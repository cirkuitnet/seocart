<?php
/**
 * Tests the statements of a currency switch: into a presentment currency against into the base currency
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Cart\Domain\CartLine;
use SEOCart\Checkout\Application\ChangeCartCurrency;
use SEOCart\Tests\Support\Checkout\CurrencySwitchTestCase;
use SEOCart\Tests\Support\Performance\ReferenceCarts;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Switching Reference Cart B, with its two promotion codes, into EUR and back into USD: every statement each switch sends, before its transaction, in it, and in the calculation of its answer after it.
 *
 * Half of Cart B's variants have a price authored in EUR, and the rest are converted. Each switch
 * starts cold, as a request does: a container of its own, and no object cache but the options
 * WordPress loads at boot. The switch into EUR may cost at most PRESENTMENT_EXTRA statements more
 * than the switch into USD, as a whole and in its calculation: the currency's terms and its
 * current rate are one read, once per request, which the calculation then reuses. Its transaction
 * sends exactly TRANSACTION_WORK statements of its own, whatever the currency.
 *
 * Planted violation: in ChangeCartCurrency::change(), have the closure read the session again
 * (`$this->sessions->find( $cartId )`) before dropping its quotes: the transaction then sends one
 * statement more than TRANSACTION_WORK.
 *
 * @group performance
 * @group international
 *
 * @since 0.1.0
 */
final class CurrencySwitchBudgetTest extends CurrencySwitchTestCase {

	/**
	 * The most statements a switch into a presentment currency may send beyond a switch into the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PRESENTMENT_EXTRA = 2;

	/**
	 * The statements a switch sends in its transaction besides the transaction's own: the compare-and-swap, the quotes dropped, and the lines read back.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const TRANSACTION_WORK = 3;

	/**
	 * The transaction's own statements: its isolation level, its start, its savepoint and its release, and its end.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CONTROL = '/^(SET TRANSACTION|START TRANSACTION|SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK|COMMIT)\b/';

	/**
	 * Tests that a switch of Cart B into EUR costs at most PRESENTMENT_EXTRA statements more than a switch of it back into USD, and that each switch's transaction sends TRANSACTION_WORK statements of its own.
	 *
	 * @since 0.1.0
	 */
	public function test_a_switch_into_a_presentment_currency_costs_at_most_two_statements_more(): void {
		$cart = $this->readyCart( self::quantities( ReferenceCarts::cartB( $this->cartBVariants() ) ), $this->cartBCodes() );

		$this->freezeQuotes( $cart );

		$eur = $this->measure( 'EUR', $cart->version );
		$usd = $this->measure( 'USD', $cart->version + 1 );

		fwrite(
			STDOUT,
			sprintf(
				"\nSwitching Cart B with its two codes, five prices authored in EUR and five converted:\n  into EUR: %1\$d statements (%2\$d before the transaction, %3\$d in it, %4\$d calculating the answer)\n  into USD: %5\$d statements (%6\$d before the transaction, %7\$d in it, %8\$d calculating the answer)\n  the calculation in EUR against USD: %9\$+d; the whole switch: %10\$+d (at most +%11\$d)\n  the switch into EUR, statement by statement:\n%12\$s\n",
				self::total( $eur ),
				count( $eur['before'] ),
				count( $eur['transaction'] ),
				count( $eur['calculation'] ),
				self::total( $usd ),
				count( $usd['before'] ),
				count( $usd['transaction'] ),
				count( $usd['calculation'] ),
				count( $eur['calculation'] ) - count( $usd['calculation'] ),
				self::total( $eur ) - self::total( $usd ),
				self::PRESENTMENT_EXTRA,
				self::listed( $eur )
			)
		);

		$this->assertSame( array( self::TRANSACTION_WORK, self::TRANSACTION_WORK ), array( self::work( $eur['transaction'] ), self::work( $usd['transaction'] ) ), "A switch's transaction:\n" . implode( "\n", $eur['transaction'] ) );
		$this->assertLessThanOrEqual( count( $usd['calculation'] ) + self::PRESENTMENT_EXTRA, count( $eur['calculation'] ), 'Calculating the answer in EUR, against in USD.' );
		$this->assertLessThanOrEqual( self::total( $usd ) + self::PRESENTMENT_EXTRA, self::total( $eur ), 'Switching into EUR, against into USD.' );
	}

	/**
	 * Switches the request's cart cold, and returns its statements by part.
	 *
	 * @since 0.1.0
	 *
	 * @param string $currency The currency to switch to.
	 * @param int    $version  The cart version the switch is based on.
	 * @return array{before: list<string>, transaction: list<string>, calculation: list<string>} The statements of each part.
	 */
	private function measure( string $currency, int $version ): array {
		// A request starts cold: a container of its own, and no object cache but the options WordPress loads at boot.
		$switcher = $this->kernelOver( $this->db, $this->tokens )->get( ChangeCartCurrency::class );
		$answer   = array();

		wp_cache_flush();
		wp_load_alloptions();

		$log = $this->captureQueries(
			function () use ( $switcher, $currency, $version, &$answer ): void {
				$answer = $switcher->change(
					array(
						'cart_version' => $version,
						'currency'     => $currency,
					),
					self::guest()
				);
			}
		);

		$this->assertSame( array( $currency, array() ), array( $answer['totals']['currency'] ?? null, $answer['unpriced_lines'] ?? null ) );

		$parts = array(
			'before'      => array(),
			'transaction' => array(),
			'calculation' => array(),
		);
		$part  = 'before';

		foreach ( $log->entries() as $entry ) {
			// The transaction begins by asking for its isolation level, and ends with its COMMIT.
			if ( str_starts_with( $entry['sql'], 'SET TRANSACTION' ) ) {
				$part = 'transaction';
			}

			$parts[ $part ][] = $entry['sql'];

			if ( 'COMMIT' === $entry['sql'] ) {
				$part = 'calculation';
			}
		}

		return $parts;
	}

	/**
	 * Counts the statements of a transaction that are not the transaction's own.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $statements The transaction's statements.
	 * @return int The count.
	 *
	 * @phpstan-param list<string> $statements
	 */
	private static function work( array $statements ): int {
		return count( (array) preg_grep( self::CONTROL, $statements, PREG_GREP_INVERT ) );
	}

	/**
	 * Returns how many statements a switch sent.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $parts The statements of each part.
	 * @return int The count.
	 */
	private static function total( array $parts ): int {
		return count( $parts, COUNT_RECURSIVE ) - count( $parts );
	}

	/**
	 * Lists a switch's statements by part, each cut to its first hundred characters, for the report.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $parts The statements of each part.
	 * @return string The list.
	 */
	private static function listed( array $parts ): string {
		$lines = array();

		foreach ( $parts as $part => $statements ) {
			foreach ( $statements as $statement ) {
				$lines[] = sprintf( '    %-12s %s', $part, substr( $statement, 0, 100 ) );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Plants Cart B's ten variants across eight products, each priced in USD and stocked, every other one also priced in EUR.
	 *
	 * @since 0.1.0
	 *
	 * @return list<int> One variant per slot, in slot order.
	 */
	private function cartBVariants(): array {
		$variants = array();
		$products = array();

		for ( $slot = 0; $slot < ReferenceCarts::CART_B_VARIANTS; $slot++ ) {
			$product    = ReferenceCarts::productOfSlot( $slot );
			$variant    = $this->sellable( 10, Inputs::money( ReferenceCarts::unitPrice( $slot ), self::CURRENCY )->minorUnits(), 'Product ' . $product, $products[ $product ] ?? 0 );
			$variants[] = $variant;

			$products[ $product ] ??= $variant;

			if ( 0 === $slot % 2 ) {
				$this->priceIn( 'EUR', array( $variant => Inputs::money( ReferenceCarts::unitPrice( $slot ), 'EUR' )->minorUnits() ) );
			}
		}

		return $variants;
	}

	/**
	 * Plants Cart B's promotions and returns their codes.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The codes, in the order they are applied.
	 */
	private function cartBCodes(): array {
		foreach ( ReferenceCarts::CART_B_CODES as $code => $columns ) {
			$this->plantPromotion( $code, $columns );
		}

		return array_keys( ReferenceCarts::CART_B_CODES );
	}

	/**
	 * Returns the units of lines, by variant.
	 *
	 * @since 0.1.0
	 *
	 * @param CartLine[] $lines The lines.
	 * @return array<int, int> Units by variant id.
	 *
	 * @phpstan-param list<CartLine> $lines
	 */
	private static function quantities( array $lines ): array {
		$quantities = array();

		foreach ( $lines as $line ) {
			$quantities[ $line->variantId ] = $line->quantity;
		}

		return $quantities;
	}
}

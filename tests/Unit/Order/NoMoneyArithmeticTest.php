<?php
/**
 * Tests that the order module adds no money up: it copies what the calculation produced
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Only the calculation produces totals, so nothing under src/Order computes an amount.
 *
 * The rule adapters keep (the money-arithmetic sniff that runs on every `Interfaces` directory),
 * applied to the order module by a scan of its tokens: no call of a method that computes an
 * amount, and no arithmetic operator in a statement that reads an amount's raw number. The two
 * lists of names are the sniff's own, read from its source, so the money API is listed in one
 * place. The payment projection is added up by the database, in the one statement that also
 * checks it; that is SQL, and the scan reads PHP.
 *
 * Planted violations, each shown red and removed:
 * - in Orders::insert(), publish `$order->totals->grandTotal->add( $order->totals->feeTotal )->minorUnits()`:
 *   an arithmetic call;
 * - in MysqlOrderRepository::insertLines(), write `$line->quantity * $line->unitPrice->minorUnits()`
 *   as the line subtotal: an operator beside an amount's raw number.
 *
 * @since 0.1.0
 */
final class NoMoneyArithmeticTest extends TestCase {

	/**
	 * The sniff whose lists name the money API.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SNIFF = 'tools/phpcs/SEOCart/Sniffs/DRY/MoneyArithmeticInInterfacesSniff.php';

	/**
	 * Receivers the sniff never reports: an adapter, or here a service, is not an amount.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const OWN = array( '$this', 'self', 'static', 'parent' );

	/**
	 * Tests that no file under src/Order computes an amount.
	 *
	 * @since 0.1.0
	 */
	public function test_the_order_module_computes_no_amount(): void {
		$methods   = self::sniffList( 'arithmeticMethods' );
		$accessors = self::sniffList( 'accessors' );
		$found     = array();

		$this->assertContains( 'add', $methods, 'The sniff\'s list was not read, so the scan would prove nothing.' );
		$this->assertContains( 'minorUnits', $accessors, 'The sniff\'s list was not read, so the scan would prove nothing.' );

		foreach ( PhpSource::files( 'src/Order' ) as $file => $source ) {
			foreach ( self::violations( $source, array_merge( $methods, array( 'sum' ) ), $accessors ) as $violation ) {
				$found[] = "{$file}:{$violation}";
			}
		}

		$this->assertSame( array(), $found, 'Only the calculation produces totals; the order module copies them.' );
	}

	/**
	 * Tests that the scan finds each shape it looks for, so a clean result means something.
	 *
	 * @since 0.1.0
	 */
	public function test_the_scan_finds_what_it_is_shown(): void {
		$methods   = self::sniffList( 'arithmeticMethods' );
		$accessors = self::sniffList( 'accessors' );

		$this->assertCount( 1, self::violations( '<?php $total = $a->grandTotal->add( $b );', $methods, $accessors ) );
		$this->assertCount( 1, self::violations( '<?php $minor = $line->quantity * $line->unitPrice->minorUnits();', $methods, $accessors ) );
		$this->assertCount( 1, self::violations( '<?php $sum = array_sum( $amounts );', $methods, $accessors ) );
		$this->assertSame( array(), self::violations( '<?php $this->add( $x ); $rows[] = $amount->minorUnits(); $next = $index + 1;', $methods, $accessors ) );
	}

	/**
	 * Lists the arithmetic a source performs on amounts.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $source    PHP source.
	 * @param string[] $methods   The names of the methods that compute an amount.
	 * @param string[] $accessors The names of the methods that expose an amount's raw number.
	 * @return list<string> One line per violation: the line number and what was found.
	 *
	 * @phpstan-param list<string> $methods
	 * @phpstan-param list<string> $accessors
	 */
	private static function violations( string $source, array $methods, array $accessors ): array {
		$tokens     = array_values( array_filter( token_get_all( $source ), static fn( $token ): bool => ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) );
		$lowerNames = array_map( 'strtolower', $methods );
		$lowerRaw   = array_map( 'strtolower', $accessors );
		$found      = array();
		$statement  = array(
			'raw'      => false,
			'operator' => false,
			'line'     => 0,
		);

		foreach ( $tokens as $index => $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			$line = is_array( $token ) ? $token[2] : $statement['line'];
			$next = $tokens[ $index + 1 ] ?? '';
			$prev = $tokens[ $index - 1 ] ?? '';

			if ( in_array( $text, array( ';', '{', '}' ), true ) ) {
				if ( $statement['raw'] && $statement['operator'] ) {
					$found[] = $statement['line'] . ': arithmetic on an amount\'s raw number';
				}

				$statement = array(
					'raw'      => false,
					'operator' => false,
					'line'     => $line,
				);

				continue;
			}

			$statement['line'] = $line;

			if ( ! is_array( $token ) || T_STRING !== $token[0] || '(' !== $next ) {
				$statement['operator'] = $statement['operator'] || self::isArithmetic( $token );

				continue;
			}

			$called   = is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true );
			$receiver = $tokens[ $index - 2 ] ?? '';
			$own      = in_array( is_array( $receiver ) ? $receiver[1] : $receiver, self::OWN, true );

			if ( $called && ! $own && in_array( strtolower( $text ), $lowerNames, true ) ) {
				$found[] = $line . ': ' . $text . '()';
			}

			if ( $called && in_array( strtolower( $text ), $lowerRaw, true ) ) {
				$statement['raw'] = true;
			}

			if ( ! $called && 'array_sum' === strtolower( $text ) ) {
				$found[] = $line . ': array_sum()';
			}
		}

		return $found;
	}

	/**
	 * Tells whether a token is an arithmetic operator.
	 *
	 * @since 0.1.0
	 *
	 * @param array|string $token The token.
	 * @return bool True for + - * / % and their compound and increment forms.
	 *
	 * @phpstan-param array{0: int, 1: string, 2: int}|string $token
	 */
	private static function isArithmetic( array|string $token ): bool {
		if ( ! is_array( $token ) ) {
			return in_array( $token, array( '+', '-', '*', '/', '%' ), true );
		}

		return in_array( $token[0], array( T_INC, T_DEC, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_POW, T_POW_EQUAL ), true );
	}

	/**
	 * Reads one of the sniff's lists of names from its source.
	 *
	 * @since 0.1.0
	 *
	 * @param string $property The list's property: `arithmeticMethods` or `accessors`.
	 * @return list<string> The names.
	 */
	private static function sniffList( string $property ): array {
		$source = (string) file_get_contents( PhpSource::root() . '/' . self::SNIFF ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A source file of the repository.

		if ( 1 !== preg_match( '/public \$' . $property . ' = array\((.*?)\);/s', $source, $list ) ) {
			return array();
		}

		preg_match_all( "/'([A-Za-z]+)'/", $list[1], $names );

		return $names[1];
	}
}

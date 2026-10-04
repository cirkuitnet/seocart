<?php
/**
 * Tests when an approval matches its intent and its order
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Domain\OrderChannel;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Domain\AmountCheck;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\PaymentIntent;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * An authorization or a capture matches when it is for the intent's frozen amount, in the order's and the intent's currency, and keeps what the order tendered within its grand total.
 *
 * The table is the specification: an EUR order of 3080 with an EUR intent of 3080, unless a row
 * says otherwise.
 *
 * Planted violation, shown red and removed: in AmountCheck::accepts(), compare the currency with
 * the intent's only: the approval in the intent's currency but not the order's matches.
 *
 * @since 0.1.0
 */
final class AmountCheckTest extends TestCase {

	/**
	 * Returns the table.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{Operation, int, string, string, int, int, bool}> The operation, the amount and currency reported, the intent's currency, what the order authorized and paid, and whether it matches.
	 */
	public static function cases(): array {
		return array(
			'the authorization asked for'                 => array( Operation::Authorize, 3080, 'EUR', 'EUR', 0, 0, true ),
			'one minor unit more'                         => array( Operation::Authorize, 3081, 'EUR', 'EUR', 0, 0, false ),
			'one minor unit less'                         => array( Operation::Authorize, 3079, 'EUR', 'EUR', 0, 0, false ),
			'in another currency'                         => array( Operation::Authorize, 3080, 'USD', 'EUR', 0, 0, false ),
			'in the intent\'s currency, not the order\'s' => array( Operation::Authorize, 3080, 'USD', 'USD', 0, 0, false ),
			'in the order\'s currency, not the intent\'s' => array( Operation::Authorize, 3080, 'EUR', 'USD', 0, 0, false ),
			'an authorization past the grand total'       => array( Operation::Authorize, 3080, 'EUR', 'EUR', 1, 0, false ),
			'the capture asked for'                       => array( Operation::Capture, 3080, 'EUR', 'EUR', 3080, 0, true ),
			'a capture past the grand total'              => array( Operation::Capture, 3080, 'EUR', 'EUR', 3080, 1, false ),
			'a refund, whose cap is its statement\'s'     => array( Operation::Refund, 1000, 'EUR', 'EUR', 3080, 3080, true ),
			'a refund in another currency'                => array( Operation::Refund, 1000, 'USD', 'EUR', 3080, 3080, false ),
		);
	}

	/**
	 * Tests each row of the table.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider cases
	 *
	 * @param Operation $operation      The operation.
	 * @param int       $minor          The amount reported.
	 * @param string    $currency       The currency reported.
	 * @param string    $intentCurrency The intent's currency.
	 * @param int       $authorized     What the order authorized before.
	 * @param int       $paid           What the order was paid before.
	 * @param bool      $matches        Whether the approval matches.
	 */
	public function test_an_approval_matches_only_its_intent_and_its_order( Operation $operation, int $minor, string $currency, string $intentCurrency, int $authorized, int $paid, bool $matches ): void {
		$eur    = static fn( int $amount ): Money => Money::of( $amount, Currency::of( 'EUR' ) );
		$usd    = static fn( int $amount ): Money => Money::of( $amount, Currency::of( 'USD' ) );
		$order  = new LockedOrder( 7, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8b', '000007', OrderChannel::Storefront, OrderStatus::PendingPayment, PaymentStatus::Unpaid, $eur( 3080 ), $eur( $authorized ), $eur( $paid ), $eur( 0 ), $eur( 3080 - $paid ), $usd( 2464 ), $usd( 0 ), $usd( 0 ), $usd( 0 ), null, 'user', null, null );
		$amount = Money::of( 3080, Currency::of( $intentCurrency ) );
		$intent = new PaymentIntent( 11, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', 7, 'stub', Mode::Test, IntentStatus::Created, $amount, $usd( 2464 ), 1, Money::zero( $amount->currency() ), Money::zero( $amount->currency() ), Money::zero( $amount->currency() ), null );
		$result = new GatewayResult( 'stub', $operation, Outcome::Approved, $intent->uuid, Money::of( $minor, Currency::of( $currency ) ), 'stub-x' );

		$this->assertSame( $matches, AmountCheck::accepts( $result, $intent, $order ) );
	}

	/**
	 * Tests that an approval of an intent whose base currency is not its order's does not match, whatever it reports.
	 *
	 * @since 0.1.0
	 */
	public function test_an_intent_in_another_base_currency_than_its_order_matches_nothing(): void {
		$eur    = static fn( int $amount ): Money => Money::of( $amount, Currency::of( 'EUR' ) );
		$order  = new LockedOrder( 7, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8b', '000007', OrderChannel::Storefront, OrderStatus::PendingPayment, PaymentStatus::Unpaid, $eur( 3080 ), $eur( 0 ), $eur( 0 ), $eur( 0 ), $eur( 3080 ), Money::of( 2464, Currency::of( 'USD' ) ), Money::zero( Currency::of( 'USD' ) ), Money::zero( Currency::of( 'USD' ) ), Money::zero( Currency::of( 'USD' ) ), null, 'user', null, null );
		$intent = new PaymentIntent( 11, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', 7, 'stub', Mode::Test, IntentStatus::Created, $eur( 3080 ), Money::of( 2900, Currency::of( 'GBP' ) ), 1, $eur( 0 ), $eur( 0 ), $eur( 0 ), null );

		$this->assertFalse( AmountCheck::accepts( new GatewayResult( 'stub', Operation::Authorize, Outcome::Approved, $intent->uuid, $eur( 3080 ), 'stub-x' ), $intent, $order ) );
	}
}

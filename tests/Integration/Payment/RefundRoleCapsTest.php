<?php
/**
 * Tests the refund caps of a role: who is held to them, in which currency, and the refusal
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Domain\NewOrder;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\RefundCapPolicy;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * An order agent is held to the caps of the `refund_caps` settings, in the base currency, by each refund's base share.
 *
 * - A refund past the cap of one order, or of a day, is refused `payment.refund_cap_exceeded`
 *   whole, naming the cap, its limit, what was used, what was asked and the currency: nothing is
 *   claimed and nothing is asked of the gateway; a store manager's same request is made.
 * - Holding a role that grants refunds and caps nothing, the manager's or one the merchant made,
 *   uncaps an agent; a role that grants nothing does not; the capability granted to the user alone
 *   is not capped.
 * - An empty cap is no cap; a cap is applied at the base currency's exponent, so 250.00 is 250 yen
 *   in a JPY store; a cap lowered below what was used refuses the next refund.
 *
 * The orders are in EUR with a USD base, at 1 USD = 0.91230 EUR.
 *
 * Planted violation, shown red and removed: in RefundService, decide the caps in the order's
 * currency rather than the base currency: a refund of 240.00 EUR, which is 263.07 USD, passes a cap
 * of 250.00.
 *
 * @since 0.2.0
 */
final class RefundRoleCapsTest extends RefundTestCase {

	/**
	 * A role the merchant made, which grants refunds.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MERCHANT_ROLE = 'shop_refunder';

	/**
	 * Removes the role the merchant made.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		remove_role( self::MERCHANT_ROLE );

		parent::tear_down();
	}

	/**
	 * Tests that a refund past the cap of one order is refused whole, before anything is claimed or asked, and that a store manager makes it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_past_the_cap_of_one_order_is_refused_and_a_manager_makes_it(): void {
		$this->capOrderAgents( '250.00', '' );

		list( $order ) = $this->placePaid( self::television( '240.00' ) );
		list( $tv )    = $this->lineUuids( $order->id );
		$share         = (int) $this->lineRow( $tv )['base_line_gross_minor'];

		$this->assertSame( 26307, $share, 'The refund is 263.07 USD.' );
		$this->assertCapExceeded( 'per_order', 25000, 0, $share, fn() => $this->refund( $order->uuid, array( $tv => 1 ) ) );
		$this->assertSame( array(), $this->claimRows( $order->id ), 'Nothing was claimed.' );
		$this->assertSame( 0, $this->refundCalls(), 'Nothing was asked of the gateway.' );

		$made = $this->refunds->refund( self::request( $order->uuid, array( $tv => 1 ), false ), $this->userWithRole( 'seocart_manager' ) );

		$this->assertSame( $share, $made->baseTotal->minorUnits(), 'A store manager makes the same refund.' );
	}

	/**
	 * Tests that a refund past the cap of a day, counting the agent's refunds of any order, is refused whole.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_past_the_cap_of_a_day_is_refused(): void {
		$this->capOrderAgents( '', '300.00' );

		list( $first )  = $this->placePaid( self::television( '120.00' ) );
		list( $second ) = $this->placePaid( self::television( '120.00' ) );
		list( $third )  = $this->placePaid( self::television( '120.00' ) );
		$one            = $this->refund( $first->uuid, array( $this->lineUuids( $first->id )[0] => 1 ) );
		$two            = $this->refund( $second->uuid, array( $this->lineUuids( $second->id )[0] => 1 ) );
		$asked          = $one->baseTotal->minorUnits() + $two->baseTotal->minorUnits();

		$this->assertCapExceeded( 'per_day', 30000, $asked, $one->baseTotal->minorUnits(), fn() => $this->refund( $third->uuid, array( $this->lineUuids( $third->id )[0] => 1 ) ) );
		$this->assertSame( array(), $this->claimRows( $third->id ), 'Nothing was claimed.' );
		$this->assertSame( 2, $this->refundCalls() );
	}

	/**
	 * Tests that a cap lowered below what an order's refunds gave back refuses the next refund.
	 *
	 * @since 0.2.0
	 */
	public function test_a_cap_lowered_below_what_was_used_refuses(): void {
		$this->capOrderAgents( '300.00', '' );

		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tv', '120.00', 2, 'untaxed' ) ), null ) );
		list( $tv )    = $this->lineUuids( $order->id );
		$first         = $this->refund( $order->uuid, array( $tv => 1 ) );

		$this->capOrderAgents( '100.00', '' );
		$this->assertCapExceeded( 'per_order', 10000, $first->baseTotal->minorUnits(), null, fn() => $this->refund( $order->uuid, array( $tv => 1 ) ) );
	}

	/**
	 * Tests who is held to the caps: an agent alone, or with a role that grants nothing; not an agent who is also a manager, or holds a role the merchant made that grants refunds, nor a user granted the capability alone.
	 *
	 * @since 0.2.0
	 */
	public function test_an_agent_is_capped_unless_another_role_or_the_user_grants_refunds(): void {
		$policy = $this->policy();
		$usd    = Currency::of( 'USD' );
		$capped = array( Money::of( 25000, $usd ), Money::of( 100000, $usd ) );

		// The first user made takes the snapshot of the roles that tear-down restores, so it must come before the added role.
		$this->userWithRole();
		add_role( self::MERCHANT_ROLE, 'Refunder', array( 'seocart_refund_orders' => true ) );

		$cases = array(
			'an order agent'                          => array( $this->agent(), $capped ),
			'an order agent who is also a subscriber' => array( $this->withRole( $this->userWithRole(), 'subscriber' ), $capped ),
			'an order agent who is also a manager'    => array( $this->withRole( $this->userWithRole(), 'seocart_manager' ), array( null, null ) ),
			'an order agent with a merchant role'     => array( $this->withRole( $this->userWithRole(), self::MERCHANT_ROLE ), array( null, null ) ),
			'a store manager'                         => array( $this->userWithRole( 'seocart_manager' ), array( null, null ) ),
			'a subscriber granted refunds alone'      => array( $this->grantedAlone(), array( null, null ) ),
		);

		foreach ( $cases as $who => list( $actor, $expected ) ) {
			$caps = $policy->for( $actor, $usd );

			$this->assertEquals( $expected, array( $caps->perOrder, $caps->perDay ), $who );
		}
	}

	/**
	 * Tests that an empty cap is no cap, and that a cap is applied at the base currency's exponent: 250.00 is 250 yen, and refuses a refund of an order with a JPY base past it.
	 *
	 * @since 0.2.0
	 */
	public function test_an_empty_cap_is_none_and_a_cap_takes_the_base_currency_exponent(): void {
		$this->capOrderAgents( '', '1000.00' );

		$caps = $this->policy()->for( $this->agent(), Currency::of( 'USD' ) );

		$this->assertEquals( array( null, Money::of( 100000, Currency::of( 'USD' ) ) ), array( $caps->perOrder, $caps->perDay ), 'An empty cap is no cap.' );

		$this->capOrderAgents( '250.00', '' );

		$yen = $this->policy()->for( $this->agent(), Currency::of( 'JPY' ) );

		$this->assertEquals( Money::of( 250, Currency::of( 'JPY' ) ), $yen->perOrder, '250.00 is 250 yen.' );

		list( $order ) = $this->placePaid( NewOrders::forTwoLines( 'USD', 'JPY' ) );
		list( $line )  = $this->lineUuids( $order->id );
		$share         = (int) $this->lineRow( $line )['base_line_gross_minor'];

		$this->assertGreaterThan( 250, $share, 'The line is worth more than 250 yen.' );

		try {
			$this->refund( $order->uuid, array( $line => (int) $this->lineRow( $line )['quantity'] ) );
			$this->fail( 'A refund past 250 yen was made.' );
		} catch ( CodedException $capped ) {
			$this->assertSame( array( PaymentError::RefundCapExceeded, 250, 'JPY' ), array( $capped->errorCode(), $capped->context()['limit_minor'] ?? null, $capped->context()['currency'] ?? null ) );
		}
	}

	/**
	 * Asserts that a refund is refused by a cap, with the five details.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $kind      `per_order` or `per_day`.
	 * @param int      $limit     The cap, in minor units of USD.
	 * @param int      $used      What was used of it.
	 * @param int|null $requested What was asked; null to leave it unchecked.
	 * @param callable $refund    The refund.
	 */
	private function assertCapExceeded( string $kind, int $limit, int $used, ?int $requested, callable $refund ): void {
		try {
			$refund();
			$this->fail( 'The refund was made past the cap ' . $kind . '.' );
		} catch ( CodedException $capped ) {
			$details = $capped->context();

			$this->assertSame( PaymentError::RefundCapExceeded, $capped->errorCode() );
			$this->assertSame( array( 'cap_kind', 'limit_minor', 'used_minor', 'requested_minor', 'currency' ), array_keys( $details ) );
			$this->assertSame( array( $kind, $limit, $used, 'USD' ), array( $details['cap_kind'], $details['limit_minor'], $details['used_minor'], $details['currency'] ) );

			if ( null !== $requested ) {
				$this->assertSame( $requested, $details['requested_minor'] );
			}
		}
	}

	/**
	 * Builds the policy over the test's connection.
	 *
	 * @since 0.2.0
	 *
	 * @return RefundCapPolicy The policy.
	 */
	private function policy(): RefundCapPolicy {
		return new RefundCapPolicy( $this->settingsOver( $this->db ) );
	}

	/**
	 * Gives a user one more role.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor  $actor The user.
	 * @param string $role  The role.
	 * @return Actor The user.
	 */
	private function withRole( Actor $actor, string $role ): Actor {
		( new \WP_User( $actor->userId() ) )->add_role( $role );

		return $actor;
	}

	/**
	 * Creates a subscriber granted the refund capability alone, with no role that grants it.
	 *
	 * @since 0.2.0
	 *
	 * @return Actor The user.
	 */
	private function grantedAlone(): Actor {
		$actor = $this->userWithRole( 'subscriber' );

		( new \WP_User( $actor->userId() ) )->add_cap( 'seocart_refund_orders' );

		return $actor;
	}

	/**
	 * Returns an order of one television, untaxed, with no shipping.
	 *
	 * @since 0.2.0
	 *
	 * @param string $price Its price in EUR.
	 * @return NewOrder The document.
	 */
	private static function television( string $price ): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tv', $price, 1, 'untaxed' ) ), null );
	}
}

<?php
/**
 * Tests a gateway's kill switch: it refuses new payments only, before anything is asked or written, and every payment the gateway holds goes on
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\GatewaySwitches;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\GatewayPlacementTestCase;
use SEOCart\Tests\Support\KernelTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test reads and removes the boot record it planted, as the database holds it.

/**
 * `second` is switched off through the boot record's kill switch: one conditional write raises the record's revision and changes nothing but the switch; the gateway is then hidden, refused at the session write and at placement (`checkout.payment_method_unavailable`, `reason: disabled`) with no call to it and no order, hold or intent written; its authorized payment is captured and refunded, its waiting payment is asked about, and the registry still gives it for the payments it holds. Switched on again, it takes a payment.
 *
 * Planted violations, each shown red and removed:
 * - in Gateways::get(), refuse every call to a switched-off gateway: the capture of its
 *   authorized payment is refused;
 * - in Modules::paymentRegister(), write the switch with update_option() instead of
 *   BootOption::mutate(): the record's revision does not move;
 * - in Gateways::configuredMode(), drop the switch: the placement through the switched-off
 *   gateway is made.
 *
 * @since 0.2.0
 */
final class KillSwitchTest extends GatewayPlacementTestCase {

	/**
	 * Removes the boot record the test planted.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => BootOption::NAME ) );
		wp_cache_flush();

		parent::tear_down();
	}

	/**
	 * Tests the switch's write, the refusals of a new payment, the payments that go on, and the switch turned back on.
	 *
	 * @since 0.2.0
	 */
	public function test_a_switched_off_gateway_takes_no_new_payment_and_its_payments_go_on(): void {
		$before = ( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn(): BootRecord => KernelTestCase::installedRecord() );

		$this->configureSecond( Mode::Test );

		$authorized = (string) $this->placeThrough( self::SECOND, 'before-authorized' )['order_uuid'];
		$waiting    = (string) $this->placeThrough( self::SECOND, 'before-waiting', StubGateway::PENDING )['order_uuid'];
		$cart       = $this->startCart( array( $this->sellable() => 1 ) );

		// A checkout that chose the gateway before it was switched off.
		$this->writeCheckout( $cart->version, self::SECOND );

		$this->assertTrue( $this->kernel->get( GatewaySwitches::class )->disable( self::SECOND ) );

		$stored = (string) $this->stored();

		$this->assertSame( $before->rev() + 1, BootRecord::fromJson( $stored )->rev(), 'One conditional write, revision-compared.' );
		$this->assertSame( array( 'gateway.second' => true ), (array) json_decode( $stored, true )['kill'] );
		$this->assertSame( $before->toJson(), BootRecord::fromJson( $stored )->withKillSwitch( 'gateway.second', false )->withRev( $before->rev() )->toJson(), 'Nothing else in the record changed.' );

		$placement = $this->freshPlacement();
		$rows      = $this->rowCounts();

		$this->assertNull( $this->gateways()->configuredMode( self::SECOND ), 'The gateway is hidden.' );

		try {
			$this->writeCheckout( $cart->version, self::SECOND );
			$this->fail( 'A switched-off gateway was chosen.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( CheckoutError::InvalidMethodKey, array( 'field' => 'payment_method_key' ) ), array( $refused->errorCode(), $refused->context() ) );
		}

		try {
			$placement->place( $this->placeInput( 'while-off' ), self::guest() );
			$this->fail( 'A payment was taken through a switched-off gateway.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::PaymentMethodUnavailable, $refused->errorCode() );
			$this->assertSame( array( 'payment_method_key' => self::SECOND ), $refused->context() );
			$this->assertSame( array( 'reason' => 'disabled' ), $refused->details() );
		}

		$this->assertNotNull( $this->second );
		$this->assertSame( array(), $this->second->calls, 'The gateway was asked nothing.' );
		$this->assertSame( $rows, $this->rowCounts(), 'No order, hold or intent was written.' );

		$this->kernel->get( PaymentService::class )->capture( $this->intentOf( $authorized )['uuid'], $this->capturer() );
		$this->kernel->get( RefundService::class )->refund( new RefundRequest( $authorized, array( new RefundLineRequest( $this->lineOf( $authorized ), 1 ) ), false, 'customer_return' ), $this->userGranted( RefundService::CAPABILITY ) );
		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $this->intentOf( $waiting )['uuid'] );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array( 'capture', 'refund', 'query' ), array_column( $this->second->calls, 'method' ), 'Its payments are captured, refunded and asked about.' );
		$this->assertSame( 'partially_refunded', $this->intentOf( $authorized )['status'], 'The line was refunded; the shipping was not asked for.' );
		$this->assertSame( $this->second, $this->gateways()->get( self::SECOND, Mode::Test ), 'The registry gives it for the payments it holds.' );

		$this->assertTrue( $this->kernel->get( GatewaySwitches::class )->enable( self::SECOND ) );
		$this->assertSame( array(), (array) json_decode( (string) $this->stored(), true )['kill'] );

		$placed = $this->freshPlacement()->place( $this->placeInput( 'switched-on' ), self::guest() );

		$this->assertSame( array( 'approved', 'test' ), array( $placed['outcome'], $this->intentOf( (string) $placed['order_uuid'] )['mode'] ), 'Switched on again, it takes a payment.' );
	}

	/**
	 * Returns the boot record's text as the database holds it.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The text, or null when there is none.
	 */
	private function stored(): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, BootOption::NAME ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Counts the orders, the intents and the holds.
	 *
	 * @since 0.2.0
	 *
	 * @return list<int> The three counts.
	 */
	private function rowCounts(): array {
		return array_map( fn( string $table ): int => (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( $table ) ), array( OrderTables::ORDERS, PaymentTables::INTENTS, InventoryTables::HOLDS ) );
	}

	/**
	 * Returns the uuid of an order's line.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @return string The line's uuid.
	 */
	private function lineOf( string $orderUuid ): string {
		return (string) $this->db->fetchValue( 'SELECT l.line_uuid FROM %i l JOIN %i o ON o.id = l.order_id WHERE o.uuid = %s', $this->table( OrderTables::LINES ), $this->table( OrderTables::ORDERS ), $orderUuid );
	}
}

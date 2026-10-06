<?php
/**
 * Tests that Safe Mode keeps every gateway off live money: new payments in test mode, live payments refused and left waiting
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Checkout\Infrastructure\Doctor\CheckoutChecks;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Application\GatewayStatuses;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Infrastructure\Doctor\GatewaysCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\GatewayPlacementTestCase;

/**
 * With `second` set to live and set up in both modes, Safe Mode makes its effective mode test: a new placement's intent is created in test, a live intent's capture is refused `payment.gateway_unavailable` / `safe_mode` with no call made and nothing written, a test intent captures, and reconciliation leaves a live intent waiting, reported as deferred.
 *
 * Planted violations, each shown red and removed:
 * - in Gateways::requireMode(), drop the Safe Mode refusal: the live intent is captured live;
 * - in Gateways::effectiveMode(), ignore Safe Mode: the new placement's intent is created live;
 * - in CheckoutChecks::waitingFinding(), give Safe Mode the critical line of a gateway that cannot
 *   be asked, as before: the checkout check calls critical the live payment the gateways check
 *   calls a warning.
 *
 * @since 0.2.0
 */
final class SafeModeGatewaysTest extends GatewayPlacementTestCase {

	/**
	 * Tests the effective mode, the placement in test, the refused live capture, the test capture and the deferred reconciliation, under Safe Mode.
	 *
	 * @since 0.2.0
	 */
	public function test_safe_mode_keeps_the_gateways_off_live_money(): void {
		$this->configureSecond( Mode::Live );

		// Before Safe Mode: one live payment authorized, one the gateway is still deciding.
		$liveOrder  = (string) $this->placeThrough( self::SECOND, 'live-authorized' )['order_uuid'];
		$waiting    = (string) $this->placeThrough( self::SECOND, 'live-deciding', StubGateway::PENDING )['order_uuid'];
		$authorized = $this->intentOf( $liveOrder );
		$deciding   = $this->intentOf( $waiting );

		$this->assertSame( array( 'live', 'authorized' ), array( $authorized['mode'], $authorized['status'] ) );
		$this->assertSame( array( 'live', 'processing' ), array( $deciding['mode'], $deciding['status'] ) );

		$this->safeMode = true;

		$placed = $this->placeThrough( self::SECOND, 'in-safe-mode' );
		$new    = $this->intentOf( (string) $placed['order_uuid'] );

		$this->assertSame( Mode::Test, $this->gateways()->effectiveMode( self::SECOND ), 'Safe Mode makes the effective mode test.' );
		$this->assertSame( array( 'live', 'test' ), array( $this->kernel->get( GatewayStatuses::class )->of( self::SECOND )->mode?->value, $this->kernel->get( GatewayStatuses::class )->of( self::SECOND )->effectiveMode?->value ), 'The status says it: set to live, effectively test.' );
		$this->assertSame( array( 'test', 'authorized' ), array( $new['mode'], $new['status'] ), 'A new payment is created in test mode.' );
		$this->assertNotNull( $this->second );
		$this->assertSame( array( 'test' ), array_column( $this->second->calls, 'mode' ), 'The gateway was asked in test mode only.' );

		$this->second->calls = array();
		$ledger              = $this->ledgerRows();

		try {
			$this->kernel->get( PaymentService::class )->capture( $authorized['uuid'], $this->capturer() );
			$this->fail( 'A live payment was captured in Safe Mode.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::GatewayUnavailable, $refused->errorCode() );
			$this->assertSame(
				array(
					'gateway_id' => self::SECOND,
					'reason'     => 'safe_mode',
				),
				$refused->context()
			);
		}

		$this->assertSame( array(), $this->second->calls, 'No live call was made.' );
		$this->assertSame( $ledger, $this->ledgerRows(), 'Nothing was written.' );
		$this->assertSame( 'authorized', $this->intentOf( $liveOrder )['status'] );

		$this->kernel->get( PaymentService::class )->capture( $new['uuid'], $this->capturer() );
		$this->assertSame( 'captured', $this->intentOf( (string) $placed['order_uuid'] )['status'], 'A test payment is captured.' );

		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $deciding['uuid'] );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( 'processing', $this->intentOf( $waiting )['status'], 'The live payment still waits.' );
		$this->assertContains(
			array(
				'intent_uuid' => $deciding['uuid'],
				'gateway_id'  => self::SECOND,
				'reason'      => PaymentError::GatewayUnavailable->value,
				'cause'       => 'safe_mode',
			),
			$this->logged( ReconcileStalePlacements::DEFERRED ),
			'Reconciliation reports the live payment deferred.'
		);
	}

	/**
	 * Tests that a live payment waiting more than a day while Safe Mode is on is a warning in doctor's checkout check as in its gateways check, each in its own words.
	 *
	 * @since 0.2.0
	 */
	public function test_a_live_payment_waiting_in_safe_mode_is_a_warning_in_both_checks(): void {
		$this->configureSecond( Mode::Live );

		$order = (string) $this->placeThrough( self::SECOND, 'live-deciding', StubGateway::PENDING )['order_uuid'];

		$this->assertSame( array( 'live', 'processing' ), array( $this->intentOf( $order )['mode'], $this->intentOf( $order )['status'] ) );
		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 25 HOUR', $this->table( PaymentTables::INTENTS ) );

		$this->safeMode = true;
		$kernel         = $this->kernelOver( $this->db, $this->tokens );

		$this->assertSame( array( 'Warning: Safe Mode is on, and 1 of second\'s live payments is open: SEOCart makes no live call, so it waits until Safe Mode ends.' ), $kernel->get( GatewaysCheck::class )->run()->findings );
		$this->assertSame( array( sprintf( 'Warning: order %s has waited for its live payment for more than 24 hours, and Safe Mode is on: SEOCart makes no live call, so its gateway second is asked about it once Safe Mode ends.', $order ) ), $kernel->get( CheckoutChecks::class )->run()->findings );
	}

	/**
	 * Counts the ledger's rows.
	 *
	 * @since 0.2.0
	 *
	 * @return int The rows.
	 */
	private function ledgerRows(): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( PaymentTables::TRANSACTIONS ) );
	}
}

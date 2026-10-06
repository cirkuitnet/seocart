<?php
/**
 * Tests that reconciliation keeps a placement whose gateway is gone waiting, run after run, and says why
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Tests\Support\Checkout\GatewayPlacementTestCase;

/**
 * A placement waits for its payment's answer when the plugin of its gateway is removed: reconciliation asks nothing, releases nothing and settles nothing on the gateway's absence alone; it reports the intent deferred, with its gateway, the error's code and the reason, each run.
 *
 * Planted violation, shown red and removed: in ReconcileStalePlacements, settle an intent whose
 * gateway is not registered as a decline: the placement ends.
 *
 * @since 0.2.0
 */
final class ReconcileGoneGatewayTest extends GatewayPlacementTestCase {

	/**
	 * Tests two runs over a placement whose gateway is gone.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_whose_gateway_is_gone_keeps_waiting(): void {
		$this->configureSecond( Mode::Test );

		$order  = (string) $this->placeThrough( self::SECOND, 'deciding', StubGateway::PENDING )['order_uuid'];
		$intent = $this->intentOf( $order )['uuid'];

		// The gateway's plugin is removed, and the intent has waited past the stale threshold.
		$this->db->execute( "UPDATE %i SET gateway_id = 'gone', updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE", $this->table( PaymentTables::INTENTS ) );

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );
		$this->freshPlacement();
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( 'processing', $this->intentOf( $order )['status'], 'The intent still waits.' );
		$this->assertSame( 'pending_payment', (string) $this->db->fetchValue( 'SELECT status FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $order ), 'The order still waits for its payment.' );
		$this->assertSame(
			array_fill(
				0,
				2,
				array(
					'intent_uuid' => $intent,
					'gateway_id'  => 'gone',
					'reason'      => PaymentError::GatewayUnavailable->value,
					'cause'       => 'not_registered',
				)
			),
			$this->logged( ReconcileStalePlacements::DEFERRED ),
			'Each run reports it deferred, and why.'
		);
	}
}

<?php
/**
 * Tests what settling a refund claim and clearing an order's unreconciled money cost in statements
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * A settlement and a clearance send a fixed number of statements; the capability check's reads of the user are left out.
 *
 * A settlement of a claim of one line, which moves the order's payment status when it records:
 *
 * - 8 reads before the gateway is asked: the claim with what it asked, the order's uuid, then
 *   the refund's own plain reads, as a refund's: the order with its totals version, the line,
 *   its tax components, the intent, and what earlier refunds returned of the components and the
 *   line;
 * - 4 statements of its one transaction, and the intent's lock;
 * - stated not made: the claim ended declined, the settlement noted, and the order's event of
 *   it: 17 with the read of the order's flag after the commit;
 * - recorded, as the gateway decided: the recording as a refund's (2 for each of its two
 *   savepoints, 7 of the money path, 7 of the document), the claim's ending read, the settlement
 *   noted and its event: 35;
 * - recorded on a person's statement: 3 more, the read of the ledger's key for the provider's
 *   refund the person names, before the gateway is asked, and the order's flag and its event: 38.
 *
 * A clearance: the 4 statements of its transaction, the order's lock by its uuid, the read of when
 * its newest unreconciled money landed, the conditional update, the order read back by its uuid,
 * and the order's event: 9.
 *
 * @since 0.2.0
 *
 * @group performance
 */
final class SettleQueryBudgetTest extends RefundTestCase {

	/**
	 * A settlement on the statement that no refund was made.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const NOT_REFUNDED = 17;

	/**
	 * A settlement the gateway decided, recording the refund it made.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const BY_GATEWAY = 35;

	/**
	 * A settlement recording a refund on a person's statement: the gateway's, the read of the ledger's key for the provider's refund named, and the order's flag with its event.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const REFUNDED = self::BY_GATEWAY + 3;

	/**
	 * A clearance.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const CLEARANCE = 9;

	/**
	 * Tests that a settlement costs its budget on each of its three paths.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settlement_costs_its_budget_on_each_path(): void {
		$provider = new RememberingGateway( new StubGateway() );
		$service  = $this->refundsOver( $this->db, $this->ids, $provider );

		$this->assertQueryCount( self::NOT_REFUNDED, $this->settlementOf( $service, $provider, 'not_refunded' ), 'a settlement on the statement that no refund was made' );
		$this->assertQueryCount( self::BY_GATEWAY, $this->settlementOf( $service, $provider, 'gateway' ), 'a settlement the gateway decided' );
		$this->assertQueryCount( self::REFUNDED, $this->settlementOf( $service, $provider, 'refunded' ), 'a settlement recording a refund on a person\'s statement' );
	}

	/**
	 * Tests that a clearance costs its budget.
	 *
	 * @since 0.2.0
	 */
	public function test_a_clearance_costs_its_budget(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		$orders                 = $this->ordersOver( $this->db, $this->ids );
		$manager                = $this->manager();

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		$log = $this->captureQueries(
			fn() => $orders->clearUnreconciledMoney(
				array(
					'order_uuid' => $order->uuid,
					'note'       => 'Reconciled.',
				),
				$manager
			)
		)->matching( self::STATEMENTS );

		$this->assertQueryCount( self::CLEARANCE, $log, 'a clearance' );
	}

	/**
	 * Opens a claim of one line of a new order and logs the statements of its settlement: on the statement that no refund was made, decided by the gateway's refund, or on the statement that it was made.
	 *
	 * @since 0.2.0
	 *
	 * @param RefundService      $service  The refund service.
	 * @param RememberingGateway $provider The gateway it asks.
	 * @param string             $path     `not_refunded`, `gateway` or `refunded`.
	 * @return \SEOCart\Tests\Support\QueryLog The settlement's statements.
	 */
	private function settlementOf( RefundService $service, RememberingGateway $provider, string $path ): \SEOCart\Tests\Support\QueryLog {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $provider, $service );
		$claimed                = Money::of( (int) $this->db->fetchValue( 'SELECT amount_minor FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid ), Currency::of( 'EUR' ) );
		$statement              = 'refunded' === $path ? new ClaimStatement( true, 'Made.', 'manual-re-' . $uuid, $claimed->minorUnits() ) : new ClaimStatement( false, 'Not made.' );
		$manager                = $this->manager();

		if ( 'gateway' === $path ) {
			$provider->made[ $uuid ][] = ( new StubGateway() )->refund( new GatewayRefund( $intent->uuid, (string) $this->intentRow( $intent->uuid )['provider_intent_id'], $claimed, $uuid, Mode::Test ) );
		}

		return $this->captureQueries( fn() => $service->settleClaim( $uuid, $statement, $manager ) )->matching( self::STATEMENTS );
	}

	/**
	 * Builds the fixture order: three tees, taxed, shipped.
	 *
	 * @since 0.2.0
	 *
	 * @return \SEOCart\Order\Domain\NewOrder The document.
	 */
	private static function order(): \SEOCart\Order\Domain\NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) );
	}
}

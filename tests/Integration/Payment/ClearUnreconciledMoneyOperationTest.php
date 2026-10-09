<?php
/**
 * Tests the clearance of an order's unreconciled money as clients call it: one declaration, on a route, a command and an ability never exposed to agents
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Application\OrderOperations;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * `order.clear_unreconciled_money` on every surface: the route, the command and the ability are compiled from its one declaration; a store manager clears an order on each; an order agent is refused; a second clearance is refused; and a card number in the note is refused on the route and on the command without being shown.
 *
 * Planted violation, shown red and removed: in Orders::clearUnreconciledMoney(), drop the check
 * of the note. The card number is kept with the order, and the route answers 200.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class ClearUnreconciledMoneyOperationTest extends RefundTestCase {

	/**
	 * The clearance on every surface.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Wires the clearance on every surface, over the test's order service.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();

		$registry->add( OrderOperations::CLEAR_UNRECONCILED_MONEY, array( OrderOperations::class, 'clearUnreconciledMoney' ) );

		$this->surfaces = new OperationSurfaces( $registry, $this->ordersOver( $this->db, $this->ids ) );
	}

	/**
	 * Discards the surfaces.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Tests the declaration: the route, the command, the capability, destructive and idempotent, and an ability never exposed to agents.
	 *
	 * @since 0.2.0
	 */
	public function test_one_declaration_serves_a_route_a_command_and_an_ability_never_exposed(): void {
		$definition = OrderOperations::clearUnreconciledMoney();

		$this->assertSame(
			array( 'POST', '/orders/{order_uuid}/money-reconciliation', 'seocart order reconcile', 'seocart/clear-unreconciled-money', false, 'seocart_override_money_state' ),
			array( $definition->httpMethod(), $definition->rest()?->route(), $definition->cli()?->command(), $definition->abilityName(), $definition->isAgentExposed(), $definition->capability() )
		);
		$this->assertTrue( $definition->annotations()->isDestructive() );
		$this->assertNotNull( wp_get_ability( 'seocart/clear-unreconciled-money' ), 'The ability is declared.' );
	}

	/**
	 * Tests that a store manager clears an order on the route, and on the command once money landed again, and that a second clearance is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_is_cleared_on_the_route_and_on_the_command(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		wp_set_current_user( $this->manager()->userId() );

		$route = $this->surfaces->rest( 'POST', '/orders/' . $order->uuid . '/money-reconciliation', array( 'note' => 'Refunded in the dashboard by mistake.' ) );
		$data  = (array) $route->get_data();

		$this->assertSame( array( 200, $order->uuid, false ), array( $route->get_status(), $data['order_uuid'] ?? null, $data['has_unreconciled_money'] ?? null ), (string) wp_json_encode( $data ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z\z/', (string) ( $data['money_reconciled_at'] ?? '' ) );

		$again = $this->surfaces->rest( 'POST', '/orders/' . $order->uuid . '/money-reconciliation', array( 'note' => 'Again.' ) );

		$this->assertSame( array( 409, OrderError::NotUnreconciled->value ), array( $again->get_status(), $again->get_data()['code'] ?? null ) );

		$this->keepUnappliedRefund( $intent, 300, 'EUR', 'external-re-2' );

		$command = $this->surfaces->cli( 'seocart order reconcile', array( $order->uuid ), array( 'note' => 'The second one too.' ) );

		$this->assertNull( $command['failure'] );
		$this->assertSame( array( $order->uuid, false ), array( $command['printed']['item']['order_uuid'] ?? null, $command['printed']['item']['has_unreconciled_money'] ?? null ) );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that an order agent is refused on the route and on the command, and that a card number in the note is refused on both without being shown; the flag stays up and nothing is kept.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_agent_and_a_card_number_are_refused_on_every_surface(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		$route                  = '/orders/' . $order->uuid . '/money-reconciliation';

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		wp_set_current_user( $this->agent()->userId() );

		$this->assertSame( 403, $this->surfaces->rest( 'POST', $route, array( 'note' => 'Done.' ) )->get_status() );
		$this->assertStringContainsString( 'requires the capability seocart_override_money_state', (string) $this->surfaces->cli( 'seocart order reconcile', array( $order->uuid ), array( 'note' => 'Done.' ) )['failure'] );

		wp_set_current_user( $this->manager()->userId() );

		$card    = 'Refunded to 4111 1111 1111 1111 by hand.';
		$refused = $this->surfaces->rest( 'POST', $route, array( 'note' => $card ) );
		$command = $this->surfaces->cli( 'seocart order reconcile', array( $order->uuid ), array( 'note' => $card ) );

		$this->assertSame( array( 422, OrderError::ReconciliationNoteRejected->value ), array( $refused->get_status(), $refused->get_data()['code'] ?? null ) );
		$this->assertStringStartsWith( OrderError::ReconciliationNoteRejected->value, (string) $command['failure'] );
		$this->assertStringNotContainsString( '4111', (string) wp_json_encode( $refused->get_data() ) . (string) wp_json_encode( $command ), 'An answer shows the number.' );
		$this->assertSame(
			array( '1', null ),
			array_values( (array) $this->db->fetchRow( 'SELECT has_unreconciled_money, money_reconciliation_note FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id ) ),
			'The flag is up, and no note was kept.'
		);
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

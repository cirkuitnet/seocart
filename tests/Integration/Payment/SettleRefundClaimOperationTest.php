<?php
/**
 * Tests the settlement of a refund claim as clients call it: one declaration, on a route, a command and an ability never exposed to agents
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * `payment.settle_refund_claim` on every surface: the route, the command and the ability are compiled from its one declaration; a store manager settles a claim on each; an order agent is refused; a second settlement is told how the claim ended; a card number in the note, or in the provider's refund, is refused on the route and on the command without being shown; and so is a provider's refund that is not printable ASCII with no space.
 *
 * Planted violation, shown red and removed: declare the settlement `agent_exposed: true`. The
 * declaration refuses it, a destructive operation never being offered to agents.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class SettleRefundClaimOperationTest extends RefundTestCase {

	/**
	 * A note with a published test card number.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CARD_NOTE = 'The customer read out 4111 1111 1111 1111 on the phone.';

	/**
	 * The settlement on every surface.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * The gateway the claims were asked of.
	 *
	 * @since 0.2.0
	 *
	 * @var RememberingGateway
	 */
	private RememberingGateway $provider;

	/**
	 * The refund service over it, which the surfaces call.
	 *
	 * @since 0.2.0
	 *
	 * @var RefundService
	 */
	private RefundService $service;

	/**
	 * Wires the settlement on every surface, over a refund service whose gateway can be made unreachable.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();

		$registry->add( PaymentOperations::SETTLE_REFUND_CLAIM, array( PaymentOperations::class, 'settleRefundClaim' ) );

		$this->provider = new RememberingGateway( new StubGateway() );
		$this->service  = $this->refundsOver( $this->db, $this->ids, $this->provider );
		$this->surfaces = new OperationSurfaces( $registry, $this->service );
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
	 * Tests the declaration: the route, the command, the capability, destructive and idempotent, no header, and an ability never exposed to agents.
	 *
	 * @since 0.2.0
	 */
	public function test_one_declaration_serves_a_route_a_command_and_an_ability_never_exposed(): void {
		$definition = PaymentOperations::settleRefundClaim();

		$this->assertSame(
			array( 'POST', '/refund-claims/{refund_uuid}/settlement', 'seocart refund settle', 'seocart/settle-refund-claim', false, 'seocart_override_money_state', array() ),
			array( $definition->httpMethod(), $definition->rest()?->route(), $definition->cli()?->command(), $definition->abilityName(), $definition->isAgentExposed(), $definition->capability(), $definition->rest()?->headers() )
		);
		$this->assertTrue( $definition->annotations()->isDestructive() );
		$this->assertTrue( $definition->annotations()->toArray()['idempotent'], 'The claim\'s state makes a retry safe.' );
		$this->assertNotNull( wp_get_ability( 'seocart/settle-refund-claim' ), 'The ability is declared.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The test reads the generated reference, a local file.
		$reference = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/reference/cli.md' );

		$this->assertStringContainsString( 'wp seocart refund settle <refund_uuid> --statement=<statement> [--provider_refund_id=<provider_refund_id>] [--amount_minor=<amount_minor>] --note=<note>', $reference );
	}

	/**
	 * Tests that a store manager settles a claim on the route, that the answer names how it ended, and that a second settlement is told how.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_is_settled_on_the_route_once(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$body          = array(
			'statement' => ClaimStatement::NOT_REFUNDED,
			'note'      => 'The provider shows no refund.',
		);

		wp_set_current_user( $this->manager()->userId() );

		$first = $this->surfaces->rest( 'POST', '/refund-claims/' . $uuid . '/settlement', $body );

		$this->assertSame(
			array(
				200,
				array(
					'refund_uuid'            => $uuid,
					'order_uuid'             => $order->uuid,
					'state'                  => 'declined',
					'decided_by'             => 'statement',
					'gateway_reading'        => 'not_found',
					'has_unreconciled_money' => false,
				),
			),
			array( $first->get_status(), $first->get_data() )
		);

		$again = $this->surfaces->rest( 'POST', '/refund-claims/' . $uuid . '/settlement', $body );

		$this->assertSame( array( 409, PaymentError::RefundClaimEnded->value, 'declined' ), array( $again->get_status(), $again->get_data()['code'] ?? null, $again->get_data()['data']['details']->state ?? null ), (string) wp_json_encode( $again->get_data() ) );
	}

	/**
	 * Tests that a store manager settles a claim on the command, naming the provider's refund and the amount it gave back, and that the settlement is theirs.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_is_settled_on_the_command(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$claimed       = (int) $this->db->fetchValue( 'SELECT amount_minor FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid );

		$this->provider->knows = false;

		wp_set_current_user( $this->manager()->userId() );

		$command = $this->surfaces->cli(
			'seocart refund settle',
			array( $uuid ),
			array(
				'statement'          => ClaimStatement::REFUNDED,
				'provider_refund_id' => 'manual-re-1',
				'amount_minor'       => (string) $claimed,
				'note'               => 'The provider\'s dashboard shows the refund.',
			)
		);
		$item    = $command['printed']['item'] ?? array();

		$this->assertNull( $command['failure'] );
		$this->assertSame( array( 'recorded', 'statement', 'cannot_say', true ), array( $item['state'] ?? null, $item['decided_by'] ?? null, $item['gateway_reading'] ?? null, $item['has_unreconciled_money'] ?? null ) );
		$this->assertSame( (string) $this->manager()->userId(), (string) $this->db->fetchValue( 'SELECT settled_by FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid ) );
	}

	/**
	 * Tests that an order agent, who may refund but not override what the plugin knows of the money, is refused on the route and on the command, with the claim left open.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_agent_is_refused_on_every_surface(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		wp_set_current_user( $this->agent()->userId() );

		$route   = $this->surfaces->rest(
			'POST',
			'/refund-claims/' . $uuid . '/settlement',
			array(
				'statement' => ClaimStatement::NOT_REFUNDED,
				'note'      => 'No refund.',
			)
		);
		$command = $this->surfaces->cli(
			'seocart refund settle',
			array( $uuid ),
			array(
				'statement' => ClaimStatement::NOT_REFUNDED,
				'note'      => 'No refund.',
			)
		);

		$this->assertSame( 403, $route->get_status(), (string) wp_json_encode( $route->get_data() ) );
		$this->assertStringContainsString( 'requires the capability seocart_override_money_state', (string) $command['failure'] );
		$this->assertSame( 'claimed', $this->db->fetchValue( 'SELECT state FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid ) );
	}

	/**
	 * Tests that a card number in the note, or in the provider's refund, is refused on the route and on the command, that neither answer shows it, and that the claim is left open with nothing kept.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_number_is_refused_on_every_surface_and_never_shown(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$cases         = array(
			'the note'            => array(
				array(
					'statement' => ClaimStatement::NOT_REFUNDED,
					'note'      => self::CARD_NOTE,
				),
				PaymentError::RefundNoteRejected->value,
			),
			'the provider refund' => array(
				array(
					'statement'          => ClaimStatement::REFUNDED,
					'provider_refund_id' => 're_4111111111111111',
					'amount_minor'       => 1234,
					'note'               => 'Made in the dashboard.',
				),
				PaymentError::RefundStatementIncomplete->value,
			),
		);

		wp_set_current_user( $this->manager()->userId() );

		foreach ( $cases as $case => list( $body, $code ) ) {
			$route   = $this->surfaces->rest( 'POST', '/refund-claims/' . $uuid . '/settlement', $body );
			$command = $this->surfaces->cli( 'seocart refund settle', array( $uuid ), array_map( 'strval', $body ) );
			$shown   = (string) wp_json_encode( $route->get_data() ) . (string) wp_json_encode( $command );

			$this->assertSame( array( 422, $code ), array( $route->get_status(), $route->get_data()['code'] ?? null ), $case . ': ' . (string) wp_json_encode( $route->get_data() ) );
			$this->assertStringStartsWith( $code, (string) $command['failure'], $case );
			$this->assertStringNotContainsString( '4111', $shown, $case . ': an answer shows the number.' );
		}

		$this->assertSame(
			array( 'claimed', null, null ),
			array_values( (array) $this->db->fetchRow( 'SELECT state, statement, settlement_note FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid ) ),
			'The claim is open, and nothing was kept.'
		);
		$this->assertSame( array(), $this->refundLedgerRows( $order->id ), 'Nothing reached the ledger.' );
	}

	/**
	 * Tests that a provider's refund that is not printable ASCII with no space, a single space or one ending in a no-break space, is refused on the route and on the command as an incomplete statement, with the claim left open and nothing written.
	 *
	 * Kept, the space would reach the ledger as no provider object at all, and the no-break space
	 * would be replaced by the ledger's ASCII column: the refund the provider made could never be
	 * found by its key, and would land later as money no claim asked for.
	 *
	 * @since 0.2.0
	 */
	public function test_a_provider_refund_not_printable_ascii_is_refused_on_every_surface(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$claimed       = (int) $this->db->fetchValue( 'SELECT amount_minor FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid );
		$refusal       = PaymentError::RefundStatementIncomplete->value;

		$this->provider->knows = false;

		wp_set_current_user( $this->manager()->userId() );

		foreach ( array(
			'a single space'            => ' ',
			'a trailing no-break space' => "re_3Q2Example\u{00A0}",
		) as $case => $providerRefundId ) {
			$body    = array(
				'statement'          => ClaimStatement::REFUNDED,
				'provider_refund_id' => $providerRefundId,
				'amount_minor'       => $claimed,
				'note'               => 'Made in the dashboard.',
			);
			$route   = $this->surfaces->rest( 'POST', '/refund-claims/' . $uuid . '/settlement', $body );
			$command = $this->surfaces->cli( 'seocart refund settle', array( $uuid ), array_map( 'strval', $body ) );

			$this->assertSame( array( 422, $refusal, ClaimStatement::INCOMPLETE ), array( $route->get_status(), $route->get_data()['code'] ?? null, $route->get_data()['data']['details']->problem ?? null ), $case . ': ' . (string) wp_json_encode( $route->get_data() ) );
			$this->assertStringStartsWith( $refusal . ': The statement cannot settle the refund\'s claim (' . ClaimStatement::INCOMPLETE . ')', (string) $command['failure'], $case );
		}

		$this->assertSame(
			array( 'claimed', null, null ),
			array_values( (array) $this->db->fetchRow( 'SELECT state, statement, settlement_note FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid ) ),
			'The claim is open, and nothing was kept.'
		);
		$this->assertSame( array(), $this->refundLedgerRows( $order->id ), 'Nothing reached the ledger.' );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'], 'The order is not flagged.' );
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

<?php
/**
 * Tests the void operation on its REST route, its command and its ability, over the payment service on real tables
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\Payment\PaymentTestCase;
use WP_REST_Response;

/**
 * `payment.void_payment` through the route and the command its one declaration is compiled into, and its ability, declared and never exposed to agents.
 *
 * The reason is one of the declared list, on every surface: one outside it, a card number among
 * them, is refused by the schema, and the refusal never shows the number. A gateway that refuses
 * to cancel is 402 `payment.operation_declined`, the payment as it was; one that answers that the
 * authorization stands is 409 `payment.not_voidable`.
 *
 * Planted violation, shown red and removed: in PaymentOperations::voidPayment(), drop the reason's
 * list of allowed values: the card number reaches the service as a reason.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class VoidOperationTest extends PaymentTestCase {

	/**
	 * A published test card number.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CARD = '4111111111111111';

	/**
	 * The void on every surface.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Wires the void on every surface, over the test's payment service.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();

		$registry->add( PaymentOperations::VOID_PAYMENT, array( PaymentOperations::class, 'voidPayment' ) );

		$this->surfaces = new OperationSurfaces( $registry, $this->payments );
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
	 * Tests the declaration, and a void on the route and then on the command, which finds it done.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_on_the_route_is_made_once(): void {
		$definition = PaymentOperations::voidPayment();

		$this->assertSame(
			array( 'POST', '/payments/{intent_uuid}/voids', 'seocart payment void', 'seocart/void-payment', false, 'seocart_void_payments', true ),
			array( $definition->httpMethod(), $definition->rest()?->route(), $definition->cli()?->command(), $definition->abilityName(), $definition->isAgentExposed(), $definition->capability(), $definition->annotations()->isDestructive() )
		);
		$this->assertNotNull( wp_get_ability( 'seocart/void-payment' ) );

		$intent = $this->authorized();

		wp_set_current_user( $this->userWithRole( 'seocart_manager' )->userId() );

		$voided = $this->voidOnRoute( $intent, 'duplicate_order' );

		$this->assertSame( 200, $voided->get_status() );
		$this->assertSame( array( 'applied', 'voided', 'voided', 3080 ), array_values( array_intersect_key( $voided->get_data(), array_flip( array( 'outcome', 'status', 'payment_status', 'amount_minor' ) ) ) ) );
		$this->assertSame( 'duplicate_order', $this->intentRow( $intent )['voided_reason'] );

		$again = $this->surfaces->cli( 'seocart payment void', array( $intent ), array( 'reason' => 'customer_request' ) );

		$this->assertStringStartsWith( 'payment.not_voidable', (string) $again['failure'] );
		$this->assertSame( 1, count( array_keys( array_column( $this->gateway->calls, 'method' ), 'void', true ) ), 'The gateway voided once.' );
	}

	/**
	 * Tests that a reason outside the declared list is refused on the route and the command, and that a card number given as the reason is never shown back.
	 *
	 * @since 0.2.0
	 */
	public function test_a_reason_outside_the_list_is_refused_without_showing_it(): void {
		$intent = $this->authorized();

		wp_set_current_user( $this->userWithRole( 'seocart_manager' )->userId() );

		foreach ( array( 'action_window_ended', self::CARD ) as $reason ) {
			$route   = $this->voidOnRoute( $intent, $reason );
			$command = $this->surfaces->cli( 'seocart payment void', array( $intent ), array( 'reason' => $reason ) );

			$this->assertSame( array( 400, 'rest_invalid_param' ), array( $route->get_status(), $route->get_data()['code'] ?? null ), $reason );
			$this->assertMatchesRegularExpression( '/^rest_[a-z_]+: /', (string) $command['failure'], $reason );
			$this->assertStringNotContainsString( self::CARD, (string) wp_json_encode( $route->get_data() ) . (string) $command['failure'], 'The refusal shows the card number.' );
		}

		$this->assertSame( array( 'authorize' ), array_column( $this->gateway->calls, 'method' ), 'Nothing reached the gateway.' );
		$this->assertSame( 'authorized', $this->intentRow( $intent )['status'] );
	}

	/**
	 * Tests that a gateway that refuses to cancel is answered 402, the payment as it was, and one that answers that the authorization stands is answered 409.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_the_gateway_refuses_or_that_finds_the_authorization_standing(): void {
		$intent = $this->authorized();

		wp_set_current_user( $this->userWithRole( 'seocart_manager' )->userId() );

		$this->gateway->voids = static fn( VoidRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Void, Outcome::Declined, $request->intentUuid, $request->amount, 'stub-void-' . $request->intentUuid, $request->providerIntentId, 'intent_not_cancelable' );

		$before   = $this->snapshot();
		$declined = $this->voidOnRoute( $intent, 'fraud' );

		$this->assertSame( array( 402, 'payment.operation_declined' ), array( $declined->get_status(), $declined->get_data()['code'] ?? null ) );
		$this->assertSame( $before, $this->snapshot(), 'The refusal changed nothing.' );

		$this->gateway->voids = static fn( VoidRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Authorize, Outcome::Approved, $request->intentUuid, $request->amount, 'stub-ch-' . $request->intentUuid, $request->providerIntentId );

		$standing = $this->voidOnRoute( $intent, 'fraud' );

		$this->assertSame( array( 409, 'payment.not_voidable', 'authorized' ), array( $standing->get_status(), $standing->get_data()['code'] ?? null, ( (array) ( $standing->get_data()['data']['details'] ?? array() ) )['status'] ?? null ) );
		$this->assertSame( $before, $this->snapshot(), 'The authorization stands, applied once.' );
	}

	/**
	 * Places the fixture order and has the stub authorize its payment.
	 *
	 * @since 0.2.0
	 *
	 * @return string The intent's uuid.
	 */
	private function authorized(): string {
		list( , $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		return $intent->uuid;
	}

	/**
	 * Sends a void on the route.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The payment.
	 * @param string $reason     The reason.
	 * @return WP_REST_Response The response.
	 */
	private function voidOnRoute( string $intentUuid, string $reason ): WP_REST_Response {
		return $this->surfaces->rest( 'POST', '/payments/' . $intentUuid . '/voids', array( 'reason' => $reason ) );
	}
}

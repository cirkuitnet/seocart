<?php
/**
 * Tests the capture operation on its REST route, its command and its ability, over the payment service on real tables
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\Payment\PaymentTestCase;
use WP_REST_Response;

/**
 * `payment.capture_payment` through the route and the command its one declaration is compiled into, and its ability, declared and never exposed to agents.
 *
 * A capture takes no idempotency key: a capture asked again after it was made is refused 409
 * `payment.not_capturable`, with what was captured as details, which a client reads as done; a
 * gateway that does not answer is 502 `payment.gateway_no_answer`, nothing recorded; one that
 * declines is 402 `payment.operation_declined`. The amount is an option, honoured where the
 * gateway declares partial captures.
 *
 * Planted violation, shown red and removed: in PaymentService::capturePayment(), let the gateway's
 * GatewayUnavailable through uncoded: the route answers the invoker's generic 500.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class CaptureOperationTest extends PaymentTestCase {

	/**
	 * The capture on every surface.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Wires the capture on every surface, over the test's payment service.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();

		$registry->add( PaymentOperations::CAPTURE_PAYMENT, array( PaymentOperations::class, 'capturePayment' ) );

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
	 * Tests the declaration: the route, the command, the capability, destructive and idempotent, no idempotency key, and an ability never exposed to agents.
	 *
	 * @since 0.2.0
	 */
	public function test_one_declaration_serves_a_route_a_command_and_an_ability_never_exposed(): void {
		$definition = PaymentOperations::capturePayment();

		$this->assertSame(
			array( 'POST', '/payments/{intent_uuid}/captures', 'seocart payment capture', 'seocart/capture-payment', false, 'seocart_capture_payments', array() ),
			array( $definition->httpMethod(), $definition->rest()?->route(), $definition->cli()?->command(), $definition->abilityName(), $definition->isAgentExposed(), $definition->capability(), $definition->rest()?->headers() )
		);
		$this->assertTrue( $definition->annotations()->isDestructive() );
		$this->assertNotNull( wp_get_ability( 'seocart/capture-payment' ), 'The ability is declared.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The test reads the generated reference, a local file.
		$reference = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/reference/cli.md' );

		$this->assertStringContainsString( 'wp seocart payment capture <intent_uuid> [--amount_minor=<amount_minor>]', $reference );
	}

	/**
	 * Tests a capture on the route, in full, and the same capture asked again, which reads as done.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_on_the_route_is_made_once(): void {
		$intent = $this->authorized();

		wp_set_current_user( $this->userWithRole()->userId() );

		$captured = $this->captureOnRoute( $intent, array() );

		$this->assertSame( 200, $captured->get_status() );
		$this->assertSame(
			array(
				'intent_uuid'    => $intent,
				'outcome'        => 'applied',
				'status'         => 'captured',
				'payment_status' => 'paid',
				'amount_minor'   => 3080,
				'currency'       => 'EUR',
			),
			$captured->get_data()
		);

		$again = $this->captureOnRoute( $intent, array() );

		$this->assertSame( array( 409, 'payment.not_capturable' ), array( $again->get_status(), $again->get_data()['code'] ?? null ) );
		$this->assertSame(
			array(
				'captured' => 3080,
				'currency' => 'EUR',
			),
			array_intersect_key( (array) ( $again->get_data()['data']['details'] ?? array() ), array_flip( array( 'captured', 'currency' ) ) ),
			'The refusal says what was captured, so the client reads it as done.'
		);
		$this->assertSame( array( 'authorize', 'capture' ), array_column( $this->gateway->calls, 'method' ), 'The gateway captured once.' );
	}

	/**
	 * Tests a capture of part on the command, where the stub declares partial captures, and one of more than was authorized, refused before the gateway is asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_part_on_the_command(): void {
		$intent = $this->authorized();

		wp_set_current_user( $this->userWithRole()->userId() );

		$past = $this->surfaces->cli( 'seocart payment capture', array( $intent ), array( 'amount_minor' => '3081' ) );

		$this->assertStringStartsWith( 'payment.capture_exceeds_authorized', (string) $past['failure'] );

		$part = $this->surfaces->cli( 'seocart payment capture', array( $intent ), array( 'amount_minor' => '1000' ) );

		$this->assertNull( $part['failure'] );
		$this->assertSame( array( 'applied', 'captured', 'partially_paid', 1000 ), array_values( array_intersect_key( (array) ( $part['printed']['item'] ?? array() ), array_flip( array( 'outcome', 'status', 'payment_status', 'amount_minor' ) ) ) ) );
		$this->assertSame( array( 'authorize', 'capture' ), array_column( $this->gateway->calls, 'method' ), 'The capture past what was authorized never reached the gateway.' );
	}

	/**
	 * Tests that a gateway that does not answer is 502 with nothing recorded, and one that declines is 402, the payment then failed.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable Only from the gateway the test scripts, which the operation codes.
	 */
	public function test_a_capture_the_gateway_does_not_answer_or_declines_is_coded(): void {
		$intent = $this->authorized();

		wp_set_current_user( $this->userWithRole()->userId() );

		$this->gateway->captures = static fn(): GatewayResult => throw new GatewayUnavailable( 'The capture never reached the gateway.' );

		$before     = $this->snapshot();
		$unanswered = $this->captureOnRoute( $intent, array() );

		$this->assertSame( array( 502, 'payment.gateway_no_answer' ), array( $unanswered->get_status(), $unanswered->get_data()['code'] ?? null ) );
		$this->assertSame( $before, $this->snapshot(), 'Nothing was recorded.' );

		$this->gateway->captures = static fn( CaptureRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Capture, Outcome::Declined, $request->intentUuid, $request->amount, 'stub-cap-' . $request->intentUuid, $request->providerIntentId, 'card_declined' );

		$declined = $this->captureOnRoute( $intent, array() );

		$this->assertSame( array( 402, 'payment.operation_declined' ), array( $declined->get_status(), $declined->get_data()['code'] ?? null ) );
		$this->assertSame( 'failed', $this->intentRow( $intent )['status'], 'The decline is recorded: the payment failed.' );
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
	 * Sends a capture on the route.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $intentUuid The payment.
	 * @param array<string, mixed> $body       The JSON body.
	 * @return WP_REST_Response The response.
	 */
	private function captureOnRoute( string $intentUuid, array $body ): WP_REST_Response {
		return $this->surfaces->rest( 'POST', '/payments/' . $intentUuid . '/captures', $body );
	}
}

<?php
/**
 * Tests that a void or a capture answered as another operation records nothing, and that a void answered with the authorization that still stands is applied as that authorization
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
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\Payment\PaymentTestCase;
use WP_REST_Response;

/**
 * A void or a capture asked on its REST route and answered as the approval of another operation is refused as the gateway's error: 502 `payment.gateway_no_answer`, and nothing is written.
 *
 * The money path applies an answer as the operation it names. A void answered as an approved
 * capture would otherwise be recorded as a capture nobody asked for: the payment captured, its
 * order paid, a capture in the ledger and its events, whoever asked the void, a person who may
 * void but not capture included, and for whatever amount the answer names. A capture answered as
 * an approved void would be applied as a void nobody gave a reason for. Refused, the payment
 * stays authorized, and the same request asked again of a gateway that answers rightly is carried
 * out. The one other operation a void may be answered with, the authorization the provider says
 * still stands, is still applied as that authorization: 409 `payment.not_voidable`, the payment
 * authorized, nothing written.
 *
 * Planted violation, shown red and removed: in CanonicalGateway::answerTo(), drop the comparison
 * of the answer's operation with the operations the call allows: the void answered as a capture
 * is then recorded as a capture and answered 409, and the capture answered as a void is answered
 * 500.
 *
 * @since 0.2.0
 */
final class MislabelledAnswerTest extends PaymentTestCase {

	/**
	 * The void and the capture on their REST routes.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Wires the void and the capture on every surface, over the test's payment service.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();

		$registry->add( PaymentOperations::VOID_PAYMENT, array( PaymentOperations::class, 'voidPayment' ) );
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
	 * Tests that a void answered as an approved capture records nothing, and that the void asked again of a gateway that answers rightly is made.
	 *
	 * @dataProvider voidsAnsweredAsCaptures
	 *
	 * @since 0.2.0
	 *
	 * @param string   $role     The role of the person who voids.
	 * @param bool     $voidOnly Whether the person is given the void capability and holds no capture capability.
	 * @param int|null $minor    The amount the answer names as captured; null for the whole amount the void releases.
	 */
	public function test_a_void_answered_as_a_capture_records_nothing( string $role, bool $voidOnly, ?int $minor ): void {
		$intent = $this->authorized();
		$person = $this->userWithRole( $role );

		if ( $voidOnly ) {
			$user = get_user_by( 'id', $person->userId() );

			$this->assertInstanceOf( \WP_User::class, $user );

			$user->add_cap( PaymentService::VOID_CAPABILITY );

			$this->assertFalse( user_can( $person->userId(), PaymentService::CAPTURE_CAPABILITY ), 'The person may not capture.' );
		}

		wp_set_current_user( $person->userId() );

		$this->gateway->voids = static fn( VoidRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Capture, Outcome::Approved, $request->intentUuid, null === $minor ? $request->amount : Money::of( $minor, $request->amount->currency() ), 'stub-cap-' . $request->intentUuid, $request->providerIntentId );

		$this->assertNothingRecorded( $intent, fn(): WP_REST_Response => $this->voidOnRoute( $intent ) );
		$this->assertSame( array( 'authorize', 'void' ), array_column( $this->gateway->calls, 'method' ), 'The gateway was asked to void, never to capture.' );

		$this->gateway->voids = null;

		$this->assertSame( array( 200, 'voided' ), self::statusOf( $this->voidOnRoute( $intent ) ) );
	}

	/**
	 * Returns the voids answered as captures: by a manager, for the whole amount and for part of it, and by a person who may void but not capture.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: bool, 2: int|null}> The role, whether the person may only void, and the amount the answer names.
	 */
	public static function voidsAnsweredAsCaptures(): array {
		return array(
			'a manager, answered as a capture of the whole amount' => array( 'seocart_manager', false, null ),
			'a manager, answered as a capture of part of it' => array( 'seocart_manager', false, 1000 ),
			'a person who may void but not capture' => array( 'seocart_reporter', true, null ),
		);
	}

	/**
	 * Tests that a capture answered as an approved void records nothing, and that the capture asked again of a gateway that answers rightly is made.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_answered_as_a_void_records_nothing(): void {
		$intent = $this->authorized();

		wp_set_current_user( $this->userWithRole( 'seocart_manager' )->userId() );

		$this->gateway->captures = static fn( CaptureRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Void, Outcome::Approved, $request->intentUuid, $request->amount, 'stub-void-' . $request->intentUuid, $request->providerIntentId );

		$this->assertNothingRecorded( $intent, fn(): WP_REST_Response => $this->captureOnRoute( $intent ) );

		$this->gateway->captures = null;

		$this->assertSame( array( 200, 'captured' ), self::statusOf( $this->captureOnRoute( $intent ) ) );
	}

	/**
	 * Tests that a void answered with the authorization that still stands is applied as that authorization: 409, the payment authorized, nothing written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_answered_with_the_standing_authorization_is_applied_as_it(): void {
		$intent = $this->authorized();

		wp_set_current_user( $this->userWithRole( 'seocart_manager' )->userId() );

		$this->gateway->voids = static fn( VoidRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Authorize, Outcome::Approved, $request->intentUuid, $request->amount, 'stub-ch-' . $request->intentUuid, $request->providerIntentId );

		$before   = $this->snapshot();
		$standing = $this->voidOnRoute( $intent );

		$this->assertSame( array( 409, 'payment.not_voidable', 'authorized' ), array( $standing->get_status(), $standing->get_data()['code'] ?? null, ( (array) ( $standing->get_data()['data']['details'] ?? array() ) )['status'] ?? null ) );
		$this->assertSame( $before, $this->snapshot(), 'The authorization stands, applied once.' );
	}

	/**
	 * Asserts that a request is answered 502 `payment.gateway_no_answer`, and that it wrote nothing: the payment and its ledger, the order and its events, and the outbox as they were.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $intent  The payment the request is about.
	 * @param \Closure $request Sends the request.
	 *
	 * @phpstan-param \Closure(): WP_REST_Response $request
	 */
	private function assertNothingRecorded( string $intent, \Closure $request ): void {
		$before   = $this->snapshot();
		$response = $request();

		$this->assertSame( $before, $this->snapshot(), 'Nothing of the answer was written.' );
		$this->assertSame( 'authorized', $this->intentRow( $intent )['status'] );
		$this->assertSame( array( 502, 'payment.gateway_no_answer' ), array( $response->get_status(), $response->get_data()['code'] ?? null ) );
	}

	/**
	 * Places the fixture order and has the stand-in authorize its payment: 3080 EUR, authorized, one ledger row.
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
	 * Sends a void on the route, for fraud.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The payment.
	 * @return WP_REST_Response The response.
	 */
	private function voidOnRoute( string $intentUuid ): WP_REST_Response {
		return $this->surfaces->rest( 'POST', '/payments/' . $intentUuid . '/voids', array( 'reason' => 'fraud' ) );
	}

	/**
	 * Sends a capture of the whole amount on the route.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The payment.
	 * @return WP_REST_Response The response.
	 */
	private function captureOnRoute( string $intentUuid ): WP_REST_Response {
		return $this->surfaces->rest( 'POST', '/payments/' . $intentUuid . '/captures', array() );
	}

	/**
	 * Reads a response's HTTP status and the payment's status it reports.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Response $response The response.
	 * @return array{0: int, 1: mixed} The HTTP status and the payment's.
	 */
	private static function statusOf( WP_REST_Response $response ): array {
		return array( $response->get_status(), $response->get_data()['status'] ?? null );
	}
}

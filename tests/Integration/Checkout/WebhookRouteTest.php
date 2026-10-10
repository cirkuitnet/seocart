<?php
/**
 * Tests the webhook route's answers: the address, the shape of a delivery, the gateway's state, and the caching headers of each
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Interfaces\Rest\WebhookRequestPolicy;
use SEOCart\Payment\Application\GatewaySwitches;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Rest\CachePolicy;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Doubles\ClosedGateTransactions;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * Every answer of the route, each with `Cache-Control: no-store, private` and the documented error shape: 404 for an address no gateway receives at, 405 for a read, 400 and 413 for a body that is empty or too large, 401 for a delivery that fails verification, and 200 for one that is settled.
 *
 * The route is registered on `rest_api_init` from the test's own wiring, as the kernel registers
 * it from its own, and dispatched with rest_do_request(); the method the request arrived with is
 * the request global, as WordPress reads it.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class WebhookRouteTest extends WebhookTestCase {

	/**
	 * The wiring the route is registered from.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $routeKernel;

	/**
	 * Registers the route from the test's wiring alone.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->routeKernel = $this->kernel;

		$this->serveRouteFrom( fn(): Container => $this->routeKernel );
	}

	/**
	 * Tests that a delivery the stand-in verifies is answered 200 `{ received: true }`, with the caching headers, and settled.
	 *
	 * @since 0.2.0
	 */
	public function test_a_verified_delivery_is_answered_received(): void {
		$placed   = $this->placed( StubGateway::THROW );
		$response = $this->post( StubWebhooks::of( $this->approvalOf( $placed ) ) );

		$this->assertSame( array( 200, array( 'received' => true ) ), array( $response->get_status(), $response->get_data() ) );
		$this->assertSame( CachePolicy::CACHE_CONTROL, $response->get_headers()['Cache-Control'] ?? null );
		$this->assertSame( 'processing', $this->orderState( $placed['order_id'] )['status'] );
	}

	/**
	 * Tests that an address no gateway receives deliveries at is 404, with the same answer for each: an id that is not registered, a case variant of the gateway or the mode, a mode the gateway does not declare, and a gateway that declares no webhooks.
	 *
	 * @since 0.2.0
	 */
	public function test_an_address_no_gateway_receives_at_is_not_found(): void {
		$delivery = StubWebhooks::event( 'evt_1', 'dispute.created', array() );

		foreach ( array( 'nope/test', 'Stub/test', 'stub/TEST', 'stub/live', self::SECOND . '/test' ) as $address ) {
			$response = $this->post( $delivery, $address );

			$this->assertSame( 404, $response->get_status(), $address );
			$this->assertErrorShape( (array) $response->get_data(), 'payment.webhook_gateway_unknown', 404 );
			$this->assertSame( CachePolicy::CACHE_CONTROL, $response->get_headers()['Cache-Control'] ?? null, $address );
		}

		$this->assertSame( 0, $this->receiptCount() );
	}

	/**
	 * Tests that a delivery of the wrong shape is refused before anything reads it: a read routed as a write, an empty body, and one larger than a provider's object, each with its code and the caching headers.
	 *
	 * Planted violation: in WebhookRequestPolicy::allows(), test the method WordPress routes the
	 * request by (`in_array( $request->get_method(), array( 'POST' ), true )`) instead of
	 * HttpMethod::isWrite(): the GET that names POST is then read as a write.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_of_the_wrong_shape_is_refused_before_it_is_read(): void {
		$approval = StubWebhooks::event( 'evt_1', 'dispute.created', array() );
		$large    = $approval->withBody( '{"pad":"' . str_repeat( 'x', WebhookRequestPolicy::MAX_BODY_BYTES ) . '"}' );
		$answers  = array(
			'payment.webhook_read_method'    => $this->post( $approval, 'stub/test', 'GET' ),
			'payment.webhook_body_empty'     => $this->post( $approval->withBody( '' ) ),
			'payment.webhook_body_too_large' => $this->post( $large ),
		);
		$statuses = array(
			'payment.webhook_read_method'    => 405,
			'payment.webhook_body_empty'     => 400,
			'payment.webhook_body_too_large' => 413,
		);

		foreach ( $answers as $code => $response ) {
			$this->assertSame( $statuses[ $code ], $response->get_status(), $code );
			$this->assertErrorShape( (array) $response->get_data(), $code, $statuses[ $code ] );
			$this->assertSame( CachePolicy::CACHE_CONTROL, $response->get_headers()['Cache-Control'] ?? null, $code );
		}

		$this->assertSame( 0, $this->receiptCount(), 'Nothing of a refused delivery is kept.' );
	}

	/**
	 * Tests that a body WordPress cannot decode is answered by WordPress before the route's checks, in the documented shape, with the caching headers.
	 *
	 * @since 0.2.0
	 */
	public function test_a_body_wordpress_cannot_decode_is_answered_in_the_documented_shape(): void {
		$response = $this->post( StubWebhooks::event( 'evt_1', 'dispute.created', array() )->withBody( '{"not json' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertErrorShape( (array) $response->get_data(), 'rest_invalid_json', 400 );
		$this->assertSame( CachePolicy::CACHE_CONTROL, $response->get_headers()['Cache-Control'] ?? null );
	}

	/**
	 * Tests that the gateway and the mode come from the address only: a body naming another mode and gateway is read at the address it was sent to.
	 *
	 * Planted violation: in WebhookRoute::receive(), read the mode with `$request->get_param( 'mode' )`:
	 * the body's `live` then wins, and the delivery is refused as to an address the stand-in has no mode for.
	 *
	 * @since 0.2.0
	 */
	public function test_the_address_is_read_from_the_url_only(): void {
		$placed = $this->placed( StubGateway::THROW );
		$event  = StubWebhooks::of( $this->approvalOf( $placed ) );
		$body   = (array) json_decode( $event->body(), true ) + array(
			'mode'       => 'live',
			'gateway_id' => self::SECOND,
		);

		$response = $this->post( $event->withBody( (string) wp_json_encode( $body ) ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'test', $this->receiptOf( $event->eventId() )['mode'] ?? null );
	}

	/**
	 * Tests that an operator's kill switch stops new payments, not the deliveries about payments the gateway already holds.
	 *
	 * @since 0.2.0
	 */
	public function test_a_switched_off_gateway_still_receives_its_deliveries(): void {
		global $wpdb;

		// The boot record holds the switch; it is planted before anything reads it.
		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn(): BootRecord => KernelTestCase::installedRecord() );

		try {
			$placed   = $this->placed( StubGateway::THROW );
			$switches = $this->kernel->get( GatewaySwitches::class );

			$this->assertTrue( $switches->disable( StubGateway::ID ) );
			$this->assertFalse( $switches->isEnabled( StubGateway::ID ), 'The gateway is switched off.' );
			$this->assertSame( 200, $this->post( StubWebhooks::of( $this->approvalOf( $placed ) ) )->get_status() );
			$this->assertSame( 'processing', $this->orderState( $placed['order_id'] )['status'] );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removes the boot record the test planted, which the boot option never deletes.
			$wpdb->delete( $wpdb->options, array( 'option_name' => BootOption::NAME ) );
			wp_cache_flush();
		}
	}

	/**
	 * Tests that a delivery that fails verification is answered 401 with a constant body that says nothing of why, and keeps nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_rejected_delivery_is_unauthorized_with_a_constant_body(): void {
		$placed  = $this->placed( StubGateway::THROW );
		$answers = array(
			$this->post( StubWebhooks::of( $this->approvalOf( $placed ) )->withBadSignature() )->get_data(),
			$this->post( StubWebhooks::of( $this->approvalOf( $placed ) )->staleBy( 400 ) )->get_data(),
			$this->post( StubWebhooks::event( 'evt_x', 'capture.approved', array() )->withBody( '{"neither":"id nor type"}' ) )->get_data(),
		);

		foreach ( $answers as $body ) {
			$this->assertErrorShape( (array) $body, 'payment.webhook_rejected', 401 );
			$this->assertSame( $answers[0]['message'], $body['message'], 'The body says nothing of why.' );
		}

		$this->assertSame( 0, $this->receiptCount() );
		$this->assertSame( 'pending_payment', $this->orderState( $placed['order_id'] )['status'] );
	}

	/**
	 * Tests that a verified delivery, while a migration the store needs is outstanding, is answered `store.unavailable` and keeps no receipt; the provider sends it again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_closed_store_refuses_a_verified_delivery_before_its_receipt(): void {
		$placed = $this->placed( StubGateway::THROW );

		$this->routeKernel = PlacementKernel::over( $this->db, $this->tokens, $this->identities, $this->wake, $this->reporter(), array( TransactionManager::class => fn(): TransactionManager => new ClosedGateTransactions( $this->db, GateState::CodeNewer ) ) );

		$response = $this->post( StubWebhooks::of( $this->approvalOf( $placed ) ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertErrorShape( (array) $response->get_data(), 'store.unavailable', 503 );
		$this->assertSame( 0, $this->receiptCount() );
		$this->assertSame( 'pending_payment', $this->orderState( $placed['order_id'] )['status'] );
	}
}

<?php
/**
 * Tests checkout.place_order over the wire: the Store API's route, the key's header, a wait's Retry-After, and the order's status read after it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Checkout\Application\KeptAnswer;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\MysqlIdempotencyKeys;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Order\Domain\Event\OrderPlaced;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Order\Interfaces\StoreApi\OrderStoreOperations;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\KernelContainer;

/**
 * `POST seocart/store/v1/checkout`, served through the production wiring: a cart's lines, its checkout, its placement with the key in the `Idempotency-Key` header, and its order's status read with the access key the placement answered.
 *
 * Planted violations, each named on its test.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class PlaceOrderOperationTest extends PlacementTestCase {

	use ServesStoreApi;

	/**
	 * Wires the kernel and boots a spy server.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bootStoreApi();
	}

	/**
	 * Restores the request globals and discards the server.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->shutStoreApi();

		parent::tear_down();
	}

	/**
	 * Tests the walk a shopper's client makes: a line, the checkout, the placement, and the order's status, and that the same request again answers what the key keeps.
	 *
	 * Planted violation: in CheckoutOperations::placeOrder(), declare the binding without its
	 * `headers`: the key is then a body field, and the walk's placement answers
	 * `checkout.idempotency_key_missing`.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_is_placed_and_read_over_the_wire(): void {
		$body = $this->readyOverTheWire();
		$sent = $this->place( $body, 'attempt-1' );

		$this->assertSame( 200, $sent['status'], (string) wp_json_encode( $sent['body'] ) );
		$this->assertSame( array( 'order_uuid', 'order_number', 'order_key', 'cart_version', 'outcome', 'status', 'payment_status' ), array_keys( $sent['body'] ) );
		$this->assertSame( array( 'approved', 'processing', 'authorized' ), array( $sent['body']['outcome'], $sent['body']['status'], $sent['body']['payment_status'] ) );
		$this->assertStringContainsString( 'no-store', $sent['headers']['Cache-Control'] ?? '' );

		$status = $this->serve(
			$this->server,
			'GET',
			'/' . RestBinding::STORE_NAMESPACE . str_replace( '{uuid}', $sent['body']['order_uuid'], OrderStoreOperations::STATUS_ROUTE ),
			array( OrderStoreOperations::KEY_HEADER => $sent['body']['order_key'] )
		);

		$this->assertSame( 200, $status['status'], (string) wp_json_encode( $status['body'] ) );
		$this->assertSame( 'processing', $status['body']['status'] ?? null );

		$again = $this->place( $body, 'attempt-1' );
		$kept  = (array) json_decode( (string) $this->secondConnection()->fetchValue( sprintf( 'SELECT response_json FROM `%s`', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) ) ), true );

		$this->assertSame( 200, $again['status'] );
		$this->assertEquals( array_diff_key( $kept, array( KeptAnswer::SEALED => true ) ), array_diff_key( $again['body'], array( KeptAnswer::ORDER_KEY => true ) ), 'The same request answers what the key keeps.' );
		$this->assertEquals( $sent['body'], $again['body'], 'What the key keeps is the placement as it stands: the first answer, its order key opened.' );
		$this->assertSame( 1, $this->committedCount( $this->secondConnection(), OrderTables::ORDERS ) );

		// A kept key that no longer opens is left out of the answer, which the operation still gives.
		$this->db->execute( "UPDATE %i SET response_json = JSON_SET( response_json, '$.order_key_sealed.box', 'AAAA' )", $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) );

		$damaged = $this->place( $body, 'attempt-1' );

		$this->assertSame( 200, $damaged['status'], (string) wp_json_encode( $damaged['body'] ) );
		$this->assertEquals( array_diff_key( $sent['body'], array( KeptAnswer::ORDER_KEY => true ) ), $damaged['body'] );
	}

	/**
	 * Tests that the key is read from its header only: a request without it is refused, and one that sends it in the body is refused as an invalid parameter.
	 *
	 * Planted violation: in RestAdapter, read a header field from the request's parameters as
	 * well: the key sent in the body is then taken, and the order placed.
	 *
	 * @since 0.1.0
	 */
	public function test_the_key_is_read_from_its_header_only(): void {
		$body = $this->readyOverTheWire();

		$missing = $this->place( $body, null );

		$this->assertSame( 400, $missing['status'] );
		$this->assertErrorShape( $missing['body'], 'checkout.idempotency_key_missing', 400 );

		$inBody = $this->place( $body + array( 'idempotency_key' => 'attempt-1' ), null );

		$this->assertSame( 400, $inBody['status'], (string) wp_json_encode( $inBody['body'] ) );
		$this->assertSame( 'rest_invalid_param', $inBody['body']['code'] ?? null );
		$this->assertSame( 0, $this->committedCount( $this->secondConnection(), OrderTables::ORDERS ) );
	}

	/**
	 * Tests that a placement still in flight under the same key is answered 409 with a Retry-After the client waits.
	 *
	 * Planted violation: in CheckoutOperations::placeOrder(), declare no `retry_after`: the
	 * answer then carries no Retry-After.
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_in_flight_is_answered_with_a_wait(): void {
		$body  = $this->readyOverTheWire();
		$token = CartToken::fromString( $this->cookies[ count( $this->cookies ) - 1 ]['value'] );
		$keys  = $this->kernel->get( MysqlIdempotencyKeys::class );

		$this->assertNotNull( $token );
		$this->db->transaction( static fn(): IdempotencyClaim => $keys->claim( IdempotencyClaim::PLACE_ORDER_SCOPE, IdempotencyClaim::keyHash( $token->hash(), 'attempt-1' ), hash( 'sha256', 'a request in flight' ), PlaceOrder::KEY_TTL_SECONDS ) );

		$wait = $this->place( $body, 'attempt-1' );

		$this->assertSame( 409, $wait['status'] );
		$this->assertErrorShape( $wait['body'], 'checkout.placement_in_progress', 409 );
		$this->assertSame( (string) CheckoutOperations::RETRY_AFTER_SECONDS, $wait['headers']['Retry-After'] ?? null );
	}

	/**
	 * Tests that an order's key leaves only in the placement's own answer: no refusal's details, no stored event and no log line written while orders are placed, declined, left waiting for the gateway and their events delivered carries it, and the logs drop one by its name.
	 *
	 * A listener of the placed order fails while the outbox is drained, so the run writes at least
	 * the line that reports it.
	 *
	 * Planted violation: in PlaceOrder::paid(), give `checkout.gateway_unavailable` the placement's
	 * record as its details: the refusal then carries the key.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_key_leaves_only_in_the_placements_answer(): void {
		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$refusals = array();
		$keys     = array();

		foreach ( array( StubGateway::APPROVE, StubGateway::DECLINE, StubGateway::THROW ) as $token ) {
			$body = $this->readyOverTheWire();

			$body['payment_data']['payment_token'] = $token;

			$sent = $this->place( $body, 'attempt-' . $token );

			$this->assertContains( $sent['status'], array( 200, 402, 503 ), $token );

			if ( 200 !== $sent['status'] ) {
				$refusals[] = $sent['body'];

				// A refused placement's key reaches the client through its retry, which opens the key its answer keeps.
				$sent = $this->place( $body, 'attempt-' . $token );
			}

			$keys[] = (string) ( $sent['body'][ KeptAnswer::ORDER_KEY ] ?? '' );
		}

		$this->assertCount( 3, array_filter( $keys ), 'Each placement keeps its answer, with its key.' );
		$this->assertCount( 2, $refusals );

		$payloads = array_column( $this->db->fetchAll( 'SELECT payload_json FROM %i', $this->table( OutboxTable::NAME ) ), 'payload_json' );
		$kept     = array_column( $this->db->fetchAll( 'SELECT response_json FROM %i', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) ), 'response_json' );

		$this->assertNotSame( array(), $payloads );
		$this->assertCount( 3, $kept );

		foreach ( $keys as $key ) {
			foreach ( $refusals as $refusal ) {
				$this->assertStringNotContainsString( $key, (string) wp_json_encode( $refusal ), 'A refusal carries an order key: ' . ( $refusal['code'] ?? '' ) );
			}

			foreach ( $payloads as $payload ) {
				$this->assertStringNotContainsString( $key, (string) $payload, 'A stored event carries an order key.' );
			}

			foreach ( $kept as $answer ) {
				$this->assertStringNotContainsString( $key, (string) $answer, 'A kept answer carries an order key readable.' );
			}
		}

		add_action(
			EventEnvelope::hookFor( OrderPlaced::eventName() ),
			static function (): void {
				throw new \RuntimeException( 'A listener that fails, so the drain writes a line.' );
			}
		);

		$kernel = KernelContainer::build( $this->db, $this->reporter() );

		$kernel->get( OutboxDrainer::class )->drain( DrainOptions::command() );

		$lines = $this->db->fetchAll( 'SELECT machine_code, message, context_json FROM %i ORDER BY id', $this->table( LogsTable::NAME ) );

		$this->assertContains( 'events.listener_failed', array_column( $lines, 'machine_code' ), 'The run wrote no line, so a clean result would prove nothing.' );

		foreach ( $lines as $line ) {
			foreach ( $keys as $key ) {
				$this->assertStringNotContainsString( $key, (string) wp_json_encode( $line ), 'A log line carries an order key: ' . $line['machine_code'] );
			}
		}

		$redacted = $kernel->get( Redactor::class )->context(
			array(
				'order_uuid' => 'kept',
				'order_key'  => $keys[0],
			)
		);

		$this->assertSame( array( 'order_uuid' => 'kept' ), $redacted, 'The logs drop the key by its name, as a secret.' );
	}

	/**
	 * Starts a new cart with a line and writes a complete checkout over the wire, and returns the placement's body for the cart as it then is.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The body.
	 */
	private function readyOverTheWire(): array {
		unset( $_COOKIE[ CartTokenTransport::COOKIE ] );

		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $this->sellable( 5, 1999 ) => 1 ) ) );

		$this->assertSame( 200, $started['status'], (string) wp_json_encode( $started['body'] ) );
		$this->presentCookie( $this->cookies[ count( $this->cookies ) - 1 ]['value'] );

		$checkout = $this->store(
			'PUT',
			CheckoutOperations::ROUTE,
			array(
				'cart_version'       => 1,
				'billing_address'    => array(
					'country'    => 'US',
					'first_name' => 'Ada',
					'last_name'  => 'Lovelace',
					'line1'      => '1 Main Street',
					'city'       => 'Austin',
					'postcode'   => '78701',
					'email'      => 'ada@example.com',
				),
				'shipping_address'   => array(
					'country'  => 'US',
					'line1'    => '1 Main Street',
					'city'     => 'Austin',
					'postcode' => '78701',
				),
				'payment_method_key' => StubGateway::ID,
			)
		);

		$this->assertSame( 200, $checkout['status'], (string) wp_json_encode( $checkout['body'] ) );

		return array(
			'cart_version'      => $checkout['body']['version'],
			'grand_total_minor' => $checkout['body']['totals']['summary']['grand_minor'],
			'currency'          => $checkout['body']['totals']['currency'],
			'payment_data'      => array( 'payment_token' => StubGateway::APPROVE ),
		);
	}

	/**
	 * Sends a placement, with the Store API's header and the key in its own header when one is given.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $body The body.
	 * @param string|null          $key  The idempotency key, or null to send none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function place( array $body, ?string $key ): array {
		$headers = array( StoreRequestPolicy::HEADER => StoreRequestPolicy::HEADER_VALUE );

		if ( null !== $key ) {
			$headers[ CheckoutOperations::IDEMPOTENCY_HEADER ] = $key;
		}

		return $this->serve( $this->server, 'POST', '/' . RestBinding::STORE_NAMESPACE . CheckoutOperations::ROUTE, $headers, $body );
	}
}

<?php
/**
 * Tests the refund operation on its REST route, its command and its ability, over the refund service on real tables
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Application\Operations\IdempotencyKey;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use WP_REST_Response;

/**
 * `payment.refund_order` through the route and the command its one declaration is compiled into, and its ability, which is declared and never exposed to agents.
 *
 * - On the route, the key travels in the `Idempotency-Key` header: a refund is made once for its
 *   key, a retry with it is answered with the same refund, and a request without it is refused
 *   `payment.refund_key_missing`.
 * - A key longer than 64 bytes, as 65 ASCII characters or 64 two-byte ones, is refused with a 400
 *   before anything is read: `payment.refund_key_missing` on the route, which reads the header
 *   unchecked by the field's schema, and on the command for the two-byte characters, which the
 *   schema counts as 64; the schema's own `rest_too_long` on the command for 65 characters.
 * - On the command, the lines are one JSON option, validated by the same schema as the route's
 *   body; the shipping is a flag; the key is a required option. JSON that does not parse is
 *   refused as a schema failure is, and the failure line never shows a card number.
 * - A request that asks for nothing, or names a line twice, is refused
 *   `payment.refund_request_invalid`, naming the problem.
 *
 * The adapters are wired over the refund service of the test, as the kernel wires them.
 *
 * Planted violations, each shown red and removed:
 *
 * - in PaymentOperations::refundOrder(), declare the key's header optional: the request without
 *   it is refused by the service alone, and the synopsis shows the option in brackets;
 * - in RefundService::refundOrder(), drop the check of a request that asks for nothing: the request
 *   is answered with the invoker's unexpected error instead of the refusal;
 * - in RefundService::refundOrder(), refuse only an empty key: a key longer than 64 bytes reaches
 *   the hash, and the route answers 500.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class RefundOperationTest extends RefundTestCase {

	/**
	 * The refund on every surface.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Wires the refund on every surface, over the test's refund service.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();

		$registry->add( PaymentOperations::REFUND_ORDER, array( PaymentOperations::class, 'refundOrder' ) );

		$this->surfaces = new OperationSurfaces( $registry, $this->refunds );
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
		$definition = PaymentOperations::refundOrder();

		$this->assertSame(
			array( 'POST', '/orders/{order_uuid}/refunds', 'seocart order refund', 'seocart/refund-order', false, 'seocart_refund_orders' ),
			array( $definition->httpMethod(), $definition->rest()?->route(), $definition->cli()?->command(), $definition->abilityName(), $definition->isAgentExposed(), $definition->capability() )
		);
		$this->assertTrue( $definition->annotations()->isDestructive() );
		$this->assertTrue( $definition->annotations()->toArray()['idempotent'] );
		$this->assertTrue( $definition->rest()?->headers()[ IdempotencyKey::FIELD ]->required ?? false, 'The key\'s header is required.' );
		$this->assertNotNull( wp_get_ability( 'seocart/refund-order' ), 'The ability is declared.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The test reads the generated reference, a local file.
		$reference = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/reference/cli.md' );

		$this->assertStringContainsString( 'wp seocart order refund <order_uuid> [--lines=<json>] [--shipping] --reason_code=<reason_code> [--note=<note>] --idempotency_key=<idempotency_key>', $reference, 'The reference shows the key as a required option and the shipping as a flag.' );
	}

	/**
	 * Tests that a refund on the route is made once for its key, that a retry with the key is answered with the same refund, and that a request without the key is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_on_the_route_is_made_once_for_its_key(): void {
		list( $order )     = $this->placePaid( self::order() );
		list( $tee, $mug ) = $this->lineUuids( $order->id );
		$body              = array(
			'lines'       => array(
				array(
					'line_uuid' => $tee,
					'quantity'  => 1,
					'restock'   => true,
				),
				array(
					'line_uuid' => $mug,
					'quantity'  => 1,
				),
			),
			'shipping'    => true,
			'reason_code' => 'damaged',
			'note'        => 'The box arrived crushed.',
		);

		wp_set_current_user( $this->agent()->userId() );

		$first = $this->refundOnRoute( $order->uuid, $body, 'attempt-1' );
		$data  = (array) $first->get_data();

		$this->assertSame( 200, $first->get_status(), (string) wp_json_encode( $data ) );
		$this->assertSame( array( 'refund_uuid', 'order_uuid', 'total_minor', 'tax_minor', 'shipping_minor', 'currency', 'base_total_minor', 'base_currency', 'reason_code', 'note', 'lines' ), array_keys( $data ) );
		$this->assertSame( (string) $this->refundRows( $order->id )[0]['uuid'], $data['refund_uuid'] );
		$this->assertSame( array( $order->uuid, 'damaged', 'The box arrived crushed.' ), array( $data['order_uuid'], $data['reason_code'], $data['note'] ) );
		$this->assertCount( 2, $data['lines'] );
		$this->assertGreaterThan( 0, $data['shipping_minor'], 'The shipping was given back.' );

		$again = $this->refundOnRoute( $order->uuid, $body, 'attempt-1' );

		$this->assertSame( array( 200, $data ), array( $again->get_status(), $again->get_data() ), 'The retry is answered with the same refund.' );
		$this->assertSame( 1, $this->refundCalls(), 'The gateway gave the money back once.' );

		$missing = $this->refundOnRoute( $order->uuid, $body, null );

		$this->assertSame( array( 400, PaymentError::RefundKeyMissing->value ), array( $missing->get_status(), $missing->get_data()['code'] ?? null ) );
	}

	/**
	 * Tests that the command takes the lines as JSON, validated by the route's schema, and the shipping as a flag, and makes the refund.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_on_the_command_takes_its_lines_as_json(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( , $mug ) = $this->lineUuids( $order->id );

		wp_set_current_user( $this->agent()->userId() );

		$command = $this->refundOnCommand(
			$order->uuid,
			(string) wp_json_encode(
				array(
					array(
						'line_uuid' => $mug,
						'quantity'  => 1,
					),
				)
			),
			array( 'shipping' => true )
		);
		$item    = $command['printed']['item'] ?? array();

		$this->assertNull( $command['failure'] );
		$this->assertSame( (string) $this->refundRows( $order->id )[0]['uuid'], $item['refund_uuid'] ?? null );
		$this->assertGreaterThan( 0, $item['shipping_minor'] ?? 0, 'The flag gave the shipping back.' );
		$this->assertSame( 'system', (string) $this->claimRows( $order->id )[0]['actor_type'], 'The command acts as the system, on the user\'s authority.' );
	}

	/**
	 * Tests that lines the command cannot parse are refused as a schema failure is, and that the failure line never shows a card number given in them.
	 *
	 * @since 0.2.0
	 */
	public function test_lines_the_command_cannot_take_are_refused_without_a_card_number(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );

		wp_set_current_user( $this->agent()->userId() );

		$unparsed = $this->refundOnCommand( $order->uuid, '[{"line_uuid": ', array() );

		$this->assertStringStartsWith( 'rest_invalid_param', (string) $unparsed['failure'] );

		// The number as a line's identifier, and as the name of a property no line has.
		foreach ( array( '[{"line_uuid":"4111111111111111","quantity":1}]', '[{"line_uuid":"' . $tee . '","quantity":1,"4111111111111111":1}]' ) as $lines ) {
			$refused = $this->refundOnCommand( $order->uuid, $lines, array() );

			$this->assertMatchesRegularExpression( '/^rest_[a-z_]+: /', (string) $refused['failure'], 'The schema refuses the lines.' );
			$this->assertStringNotContainsString( '4111111111111111', (string) $refused['failure'], 'The failure line shows the card number.' );
		}

		$this->assertSame( array(), $this->claimRows( $order->id ) );
	}

	/**
	 * Tests that a request that asks for nothing, or names a line twice, is refused, naming the problem, and claims nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_a_refund_cannot_be_made_from_is_refused(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$line          = array(
			'line_uuid' => $tee,
			'quantity'  => 1,
		);

		wp_set_current_user( $this->agent()->userId() );

		foreach ( array(
			RefundRequest::NOTHING_ASKED => array(),
			RefundRequest::LINE_REPEATED => array( $line, $line ),
		) as $problem => $lines ) {
			$refused = $this->refundOnRoute(
				$order->uuid,
				array(
					'lines'       => $lines,
					'reason_code' => 'other',
				),
				'attempt-' . $problem
			);
			$data    = (array) $refused->get_data();

			$this->assertSame( array( 422, PaymentError::RefundRequestInvalid->value ), array( $refused->get_status(), $data['code'] ?? null ), (string) wp_json_encode( $data ) );
			$this->assertStringContainsString( $problem, (string) wp_json_encode( $data ), 'The refusal names the problem.' );
		}

		$this->assertSame( array(), $this->claimRows( $order->id ) );
		$this->assertSame( 0, $this->refundCalls() );
	}

	/**
	 * Tests that a key longer than 64 bytes is refused with a 400 on the route and on the command, before any read, and that nothing is claimed or asked of the gateway.
	 *
	 * @since 0.2.0
	 */
	public function test_a_key_longer_than_its_bytes_is_refused_before_any_read(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$body          = array(
			'lines'       => array(
				array(
					'line_uuid' => $tee,
					'quantity'  => 1,
				),
			),
			'reason_code' => 'other',
		);
		$keys          = array(
			'65 ASCII characters'    => array( str_repeat( 'k', IdempotencyKey::MAX_LENGTH + 1 ), 'rest_too_long' ),
			'64 two-byte characters' => array( str_repeat( "\u{00e9}", IdempotencyKey::MAX_LENGTH ), PaymentError::RefundKeyMissing->value ),
		);

		wp_set_current_user( $this->agent()->userId() );

		foreach ( $keys as $what => list( $key, $onCommand ) ) {
			$route = null;
			$log   = $this->captureQueries(
				function () use ( $order, $body, $key, &$route ): void {
					$route = $this->refundOnRoute( $order->uuid, $body, $key );
				}
			)->matching( self::STATEMENTS );

			$this->assertInstanceOf( WP_REST_Response::class, $route );
			$this->assertSame( array( 400, PaymentError::RefundKeyMissing->value ), array( $route->get_status(), $route->get_data()['code'] ?? null ), $what . ': ' . wp_json_encode( $route->get_data() ) );
			$this->assertQueryCount( 0, $log, 'the route refusing a key of ' . $what );

			$command = null;
			$log     = $this->captureQueries(
				function () use ( $order, $body, $key, &$command ): void {
					$command = $this->surfaces->cli(
						'seocart order refund',
						array( $order->uuid ),
						array(
							'lines'           => (string) wp_json_encode( $body['lines'] ),
							'reason_code'     => $body['reason_code'],
							'idempotency_key' => $key,
						)
					);
				}
			)->matching( self::STATEMENTS );

			$this->assertIsArray( $command );
			$this->assertStringStartsWith( $onCommand . ':', (string) $command['failure'], $what );
			$this->assertQueryCount( 0, $log, 'the command refusing a key of ' . $what );
		}

		$this->assertSame( array(), $this->claimRows( $order->id ), 'Nothing was claimed.' );
		$this->assertSame( 0, $this->refundCalls(), 'Nothing was asked of the gateway.' );
	}

	/**
	 * Sends a refund to the route.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $orderUuid The order.
	 * @param array<string, mixed> $body      The JSON body.
	 * @param string|null          $key       The Idempotency-Key header; null to send none.
	 * @return WP_REST_Response The response.
	 */
	private function refundOnRoute( string $orderUuid, array $body, ?string $key ): WP_REST_Response {
		return $this->surfaces->rest( 'POST', '/orders/' . $orderUuid . '/refunds', $body, array(), null === $key ? array() : array( IdempotencyKey::HEADER => $key ) );
	}

	/**
	 * Runs the refund command with lines given as JSON, a reason and a key of its own.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $orderUuid The order.
	 * @param string               $lines     The lines, as the --lines option's text.
	 * @param array<string, mixed> $more      More options, such as the shipping flag.
	 * @return array{printed: array{item: array<string, mixed>, format: string}|null, failure: string|null} The outcome.
	 */
	private function refundOnCommand( string $orderUuid, string $lines, array $more ): array {
		return $this->surfaces->cli(
			'seocart order refund',
			array( $orderUuid ),
			array(
				'lines'           => $lines,
				'reason_code'     => 'customer_return',
				'idempotency_key' => 'command-' . md5( $lines ),
			) + $more
		);
	}

	/**
	 * Returns an order of two lines, three tees and two mugs, with shipping.
	 *
	 * @since 0.2.0
	 *
	 * @return NewOrder The document.
	 */
	private static function order(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ), RefundOrders::line( 'mug', '5.00', 2, 'standard', variantId: 502 ) ) );
	}
}

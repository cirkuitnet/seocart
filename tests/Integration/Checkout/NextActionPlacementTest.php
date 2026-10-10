<?php
/**
 * Tests what a placement answers when the shopper must act: the next action, declared once, kept sealed for a retry, and never a secret in a URL
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Checkout\Application\KeptAnswer;
use SEOCart\Checkout\Application\ResumePayment;
use SEOCart\Checkout\Application\ReturnUrls;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

/**
 * A placement whose shopper must act answers what they must do, as the placement's one declaration states it; the answer its key keeps holds it sealed, as it holds the order key, so a retry of the same request can still take the step, and no reader of the tables can; once the shopper need not act, it is cleared.
 *
 * The provider is given the address it sends the shopper back to: the site's, carrying the
 * payment's identifier and nothing else. No URL the placement produces carries the order key, the
 * cart token, the idempotency key or the provider's handle.
 *
 * Planted violations, each shown red and removed:
 * - in KeptAnswer::seal() or KeptAnswer::sealAction(), keep the next action as it is: the raw row
 *   holds the handle;
 * - in PlaceOrder::paid(), add the order key to the return address: the address carries a secret;
 * - in MysqlIdempotencyKeys::SETTLE_ANSWER, keep the sealed next action whatever the outcome: a
 *   retry after the approval still finds a box;
 * - take `client_token` out of Redactor::ANSWERED_SECRETS: the logged answer carries the handle.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class NextActionPlacementTest extends PlacementTestCase {

	/**
	 * The idempotency key the placement is sent with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY = 'attempt-acting';

	/**
	 * Tests that the placement answers the stub's next action, as its declared output schema states it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_whose_shopper_must_act_answers_what_to_do(): void {
		list( $answer, $intent ) = $this->placeActing();

		$this->assertSame( 'requires_action', $answer['outcome'] );
		$this->assertSame(
			array(
				'type'         => 'redirect',
				'url'          => ReturnUrls::for( $intent ),
				'client_token' => StubGateway::CLIENT_TOKEN_PREFIX . $intent,
			),
			$answer['next_action']
		);

		$valid = rest_validate_value_from_schema( $answer, ( new CompiledOperation( CheckoutOperations::placeOrder() ) )->outputSchema(), 'placement' );

		$this->assertTrue( true === $valid, is_wp_error( $valid ) ? $valid->get_error_message() : 'The answer is refused by its declared schema.' );
	}

	/**
	 * Tests that the key keeps the next action sealed: no handle in the row; the same request's retry gets it back, and a box opened with another key gives none.
	 *
	 * @since 0.2.0
	 */
	public function test_the_kept_answer_holds_the_next_action_sealed(): void {
		list( $answer, $intent ) = $this->placeActing();

		$kept = (string) $this->keptRow( (string) $answer['order_uuid'] );

		$this->assertStringNotContainsString( StubGateway::CLIENT_TOKEN_PREFIX . $intent, $kept, 'The row holds the handle.' );
		$this->assertEqualsCanonicalizing( array( 'nonce', 'box' ), array_keys( (array) ( json_decode( $kept, true )[ KeptAnswer::NEXT_ACTION_SEALED ] ?? array() ) ), 'The row holds a box, as an object.' );

		$this->assertEquals( $answer, $this->placement->place( $this->actingInput(), self::guest() ), 'The retry gets the placement as it stands, its next action opened.' );

		$token  = $this->tokens->presented ?? self::fail( 'No token.' );
		$opened = KeptAnswer::open( $kept, $token, 'another-key' );

		$this->assertSame( array( null, false ), array( $opened[ KeptAnswer::NEXT_ACTION ], array_key_exists( KeptAnswer::ORDER_KEY, $opened ) ), 'Another key opens neither box.' );

		// An answer kept with its next action seals it as the settlement's box does.
		$sealed = KeptAnswer::seal( array_intersect_key( $answer, array_flip( array( 'order_uuid', 'outcome', 'next_action' ) ) ), $token, self::KEY );

		$this->assertStringNotContainsString( StubGateway::CLIENT_TOKEN_PREFIX . $intent, $sealed );
		$this->assertSame( $answer['next_action'], KeptAnswer::open( $sealed, $token, self::KEY )[ KeptAnswer::NEXT_ACTION ] );
	}

	/**
	 * Tests that once the shopper need not act, the kept next action is cleared, and a retry is told the outcome without it.
	 *
	 * @since 0.2.0
	 */
	public function test_once_the_shopper_need_not_act_the_next_action_is_cleared(): void {
		list( $answer ) = $this->placeActing();

		// Left eleven minutes, by the database clock: reconciliation asks the stub, which finds the shopper confirmed.
		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE', $this->table( PaymentTables::INTENTS ) );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$replayed = $this->placement->place( $this->actingInput(), self::guest() );

		$this->assertSame( array( 'approved', null ), array( $replayed['outcome'], $replayed['next_action'] ) );
		$kept = (array) json_decode( (string) $this->keptRow( (string) $answer['order_uuid'] ), true );

		$this->assertSame( array( true, null ), array( array_key_exists( KeptAnswer::NEXT_ACTION_SEALED, $kept ), $kept[ KeptAnswer::NEXT_ACTION_SEALED ] ), 'The row keeps no box once the shopper need not act.' );
	}

	/**
	 * Tests that the provider was given exactly the return address, which carries the payment's identifier alone, and that no address the placement produced carries a secret.
	 *
	 * @since 0.2.0
	 */
	public function test_no_address_the_placement_produced_carries_a_secret(): void {
		list( $answer, $intent ) = $this->placeActing();

		$sent = $this->gateway->authorizations[0]->returnUrl ?? '';

		$this->assertSame( ReturnUrls::for( $intent ), $sent );

		wp_parse_str( (string) wp_parse_url( $sent, PHP_URL_QUERY ), $query );

		$this->assertSame( array( ReturnUrls::QUERY_ARG => $intent ), $query, 'The address carries the payment\'s identifier and nothing else.' );

		$secrets = array( (string) $answer['order_key'], (string) $this->tokens->presented?->value(), self::KEY, StubGateway::CLIENT_TOKEN_PREFIX . $intent );

		foreach ( array( $sent, (string) $answer['next_action']['url'] ) as $address ) {
			foreach ( $secrets as $secret ) {
				$this->assertStringNotContainsString( $secret, $address );
			}
		}
	}

	/**
	 * Tests that the handle reaches no log line: not from the placement, its retry, the resume or the end of the shopper's time to act, and not when a caller logs the answer itself.
	 *
	 * @since 0.2.0
	 */
	public function test_the_handle_reaches_no_log_line(): void {
		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		list( $answer, $intent ) = $this->placeActing();

		$this->placement->place( $this->actingInput(), self::guest() );
		$this->kernel->get( Reporter::class )( 'checkout.test_answer', array( 'answer' => $answer ) );
		$this->kernel->get( ResumePayment::class )->resume( array( 'intent_uuid' => $intent ), self::guest() );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$ended = $this->placement->place( $this->placeInput( 'ended', StubGateway::REQUIRES_ACTION ), self::guest() );

		$this->db->execute( 'UPDATE %i i JOIN %i o ON o.id = i.order_id SET i.updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE, i.customer_action_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $ended['order_uuid'] );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$lines = $this->db->fetchAll( 'SELECT machine_code, message, context_json FROM %i ORDER BY id', $this->table( LogsTable::NAME ) );

		$this->assertContains( 'checkout.test_answer', array_column( $lines, 'machine_code' ), 'The answer was logged, so the log is read.' );

		foreach ( $lines as $line ) {
			$this->assertStringNotContainsString( StubGateway::CLIENT_TOKEN_PREFIX, (string) $line['message'] . (string) $line['context_json'], (string) $line['machine_code'] );
		}
	}

	/**
	 * Places an order for one unit whose payment the stub asks the shopper to act on.
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: array<string, mixed>, 1: string} The answer, and the intent's uuid.
	 */
	private function placeActing(): array {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$answer = $this->placement->place( $this->actingInput(), self::guest() );
		$intent = (string) $this->db->fetchValue( 'SELECT i.uuid FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $answer['order_uuid'] );

		return array( $answer, $intent );
	}

	/**
	 * Returns the placement's input: the same every time, so a second call is a retry of the same request.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The input.
	 */
	private function actingInput(): array {
		static $input = null;

		$input ??= $this->placeInput( self::KEY, StubGateway::REQUIRES_ACTION );

		return $input;
	}

	/**
	 * Reads the answer the key of an order's placement keeps, as stored.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @return string|null The answer, as JSON.
	 */
	private function keptRow( string $orderUuid ): ?string {
		return $this->db->fetchValue( 'SELECT k.response_json FROM %i k JOIN %i o ON o.id = k.order_id WHERE o.uuid = %s', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $this->table( OrderTables::ORDERS ), $orderUuid );
	}
}

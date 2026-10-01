<?php
/**
 * Tests that the answer an idempotency key keeps holds the order key sealed, so only the retrying client reads it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\KeptAnswer;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Order\Domain\AccessKeys;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Secrets\Cipher;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

/**
 * The order key is kept sealed with the cart token and the idempotency key the client presents, which the store never keeps: a retry opens it, a reader of the tables cannot.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class KeptOrderKeyTest extends PlacementTestCase {

	/**
	 * The idempotency key of the placements: guessable on purpose, as a careless client's would be.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const KEY = 'attempt-1';

	/**
	 * Tests that the kept answer holds no part of the order key, that nothing in it verifies against the order's hash, and that the retry gets the identical answer, key included.
	 *
	 * Planted violation: in KeptAnswer::seal(), keep the order key as it is: the stored row then
	 * holds the key.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kept_answer_holds_the_key_sealed_and_the_retry_opens_it(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$input  = $this->placeInput( self::KEY );
		$first  = $this->placement->place( $input, self::guest() );
		$key    = (string) $first['order_key'];
		$stored = $this->storedAnswer( (string) $first['order_uuid'] );
		$hash   = (string) $this->secondConnection()->fetchValue( sprintf( "SELECT access_key_hash FROM `%s` WHERE uuid = '%s'", $this->table( OrderTables::ORDERS ), $first['order_uuid'] ) );
		$keys   = $this->kernel->get( AccessKeys::class );

		$this->assertSame( 'approved', $first['outcome'] );
		$this->assertTrue( $keys->verify( $key, $hash ), 'The answer\'s key is the order\'s.' );
		$this->assertArrayNotHasKey( KeptAnswer::ORDER_KEY, $stored['answer'] );
		$this->assertArrayHasKey( KeptAnswer::SEALED, $stored['answer'] );

		$last = strlen( $key ) - 8;

		for ( $at = 0; $at <= $last; $at++ ) {
			$this->assertStringNotContainsString( substr( $key, $at, 8 ), $stored['json'], 'The kept answer holds part of the order key.' );
		}

		foreach ( self::strings( $stored['answer'] ) as $value ) {
			$this->assertFalse( $keys->verify( $value, $hash ), 'A value the kept answer holds verifies against the order\'s hash: ' . $value );
		}

		$again = $this->placement->place( $input, self::guest() );

		// The settlement's rewrite leaves the stored fields in MySQL's JSON order, so the fields are compared, not their order.
		$this->assertEquals( $first, $again, 'The retry gets the identical answer, its key included.' );
	}

	/**
	 * Tests that what the tables hold cannot open the box: neither the key's row, nor the cart's token hash with the guessed idempotency key; while what the retry presents opens it.
	 *
	 * The test opens the box itself, with the plugin's cipher and the order's uuid, so a seal under
	 * a key derived from stored values is found whatever the code under test derives.
	 *
	 * Planted violations:
	 * - in KeptAnswer::key(), derive from the cart token's hash (`$token->hash()`) instead of the
	 *   token: the token hash in `carts` and the guessed key then open the box;
	 * - in KeptAnswer::key(), derive from the key's row alone, `hash( 'sha256', self::PURPOSE .
	 *   IdempotencyClaim::keyHash( $token->hash(), $idempotencyKey ), true )`: the row then opens its
	 *   own box.
	 *
	 * @since 0.1.0
	 */
	public function test_what_the_tables_hold_cannot_open_the_box(): void {
		$cart     = $this->readyCart( array( $this->sellable() => 1 ) );
		$token    = $this->tokens->presented;
		$answer   = $this->placement->place( $this->placeInput( self::KEY ), self::guest() );
		$stored   = $this->storedAnswer( (string) $answer['order_uuid'] );
		$b        = $this->secondConnection();
		$keyHash  = (string) $b->fetchValue( sprintf( 'SELECT key_hash FROM `%s` WHERE id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $stored['id'] ) );
		$cartHash = (string) $b->fetchValue( sprintf( 'SELECT token_hash FROM `%s` WHERE id = %d', $this->table( CartTables::CARTS ), $cart->id ) );

		$this->assertNotNull( $token );
		$this->assertSame( $token->hash(), $cartHash, 'The cart keeps its token\'s hash.' );
		$this->assertSame( IdempotencyClaim::keyHash( $cartHash, self::KEY ), $keyHash, 'The guessed key is confirmed from the two stored hashes.' );

		$fromStored = array(
			'the cart\'s token hash and the guessed key' => hash( 'sha256', 'seocart-order-key|' . $cartHash . '|' . self::KEY, true ),
			'the key\'s hash'                            => hash( 'sha256', 'seocart-order-key|' . $keyHash, true ),
			'the key\'s hash, as bytes'                  => (string) hex2bin( $keyHash ),
			'the two hashes'                             => hash( 'sha256', 'seocart-order-key|' . $cartHash . '|' . $keyHash, true ),
		);

		foreach ( $fromStored as $what => $candidate ) {
			$this->assertNull( $this->opened( $stored['answer'], (string) $answer['order_uuid'], $candidate ), 'The box opens with ' . $what . '.' );
		}

		$this->assertSame( $answer['order_key'], $this->opened( $stored['answer'], (string) $answer['order_uuid'], hash( 'sha256', 'seocart-order-key|' . $token->value() . '|' . self::KEY, true ) ), 'What the retry presents opens the box.' );
	}

	/**
	 * Tests that a request with another idempotency key never reaches the box: it is another row, so it places afresh, and is refused, with no key, because the cart converted into the order is no longer the request's cart.
	 *
	 * @since 0.1.0
	 */
	public function test_another_key_never_reaches_the_box(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$input = $this->placeInput( self::KEY );
		$first = $this->placement->place( $input, self::guest() );
		$token = $this->tokens->presented;

		$this->assertNotNull( $token );
		$this->assertNull( $this->keys->replay( IdempotencyClaim::PLACE_ORDER_SCOPE, IdempotencyClaim::keyHash( $token->hash(), 'attempt-2' ), hash( 'sha256', 'any request' ) ), 'Another key names no kept answer.' );

		try {
			$this->placement->place( array_replace( $input, array( 'idempotency_key' => 'attempt-2' ) ), self::guest() );
			$this->fail( 'Another key placed a second order from a converted cart.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CartError::NotFound, $refused->errorCode() );
			$this->assertStringNotContainsString( (string) $first['order_key'], (string) wp_json_encode( array( $refused->context(), $refused->details() ) ) );
		}
	}

	/**
	 * Tests that a box that does not open, because it was damaged, gives the retry its answer without the key, rather than a failure.
	 *
	 * Planted violation: in KeptAnswer::open(), keep the sealed field when its box does not open:
	 * the retry then answers with the box instead of without a key.
	 *
	 * @since 0.1.0
	 */
	public function test_a_damaged_box_answers_without_the_key(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$input  = $this->placeInput( self::KEY );
		$first  = $this->placement->place( $input, self::guest() );
		$stored = $this->storedAnswer( (string) $first['order_uuid'] );
		$box    = (string) $stored['answer'][ KeptAnswer::SEALED ]['box'];

		$this->db->execute(
			"UPDATE %i SET response_json = JSON_SET( response_json, '$.order_key_sealed.box', %s ) WHERE id = %d",
			$this->table( CheckoutTables::IDEMPOTENCY_KEYS ),
			( 'A' === $box[0] ? 'B' : 'A' ) . substr( $box, 1 ),
			$stored['id']
		);

		$again = $this->placement->place( $input, self::guest() );

		unset( $first[ KeptAnswer::ORDER_KEY ] );

		$this->assertEquals( $first, $again, 'The retry is answered as before, without the key.' );
		$this->assertArrayNotHasKey( KeptAnswer::SEALED, $again );
	}

	/**
	 * Tests that the settlement's rewrite of the kept answer leaves the sealed key as it was, and a retry after it opens the same key.
	 *
	 * @since 0.1.0
	 */
	public function test_the_settlement_leaves_the_sealed_key_untouched(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$input  = $this->placeInput( self::KEY, StubGateway::REQUIRES_ACTION );
		$first  = $this->placement->place( $input, self::guest() );
		$before = $this->storedAnswer( (string) $first['order_uuid'] );

		$this->assertSame( 'requires_action', $first['outcome'] );

		$this->db->execute( 'UPDATE %i i JOIN %i o ON o.id = i.order_id SET i.updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $first['order_uuid'] );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$after = $this->storedAnswer( (string) $first['order_uuid'] );
		$again = $this->placement->place( $input, self::guest() );

		$this->assertSame( 'approved', $after['answer']['outcome'] ?? null, 'The settlement rewrote the kept answer.' );
		$this->assertSame( $before['answer'][ KeptAnswer::SEALED ], $after['answer'][ KeptAnswer::SEALED ], 'The sealed key is as it was.' );
		$this->assertSame( array( 'approved', $first['order_key'] ), array( $again['outcome'], $again['order_key'] ?? null ) );
	}

	/**
	 * Reads the answer an order's key keeps, as connection B sees it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $orderUuid The order.
	 * @return array{id: int, json: string, answer: array<string, mixed>} The key's row, the answer as stored, and decoded.
	 */
	private function storedAnswer( string $orderUuid ): array {
		$row = $this->secondConnection()->fetchRow( sprintf( "SELECT k.id, k.response_json FROM `%s` k JOIN `%s` o ON o.id = k.order_id WHERE o.uuid = '%s'", $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $this->table( OrderTables::ORDERS ), $orderUuid ) );

		$this->assertNotNull( $row, 'The order has no key.' );

		$answer = json_decode( (string) $row['response_json'], true );

		$this->assertIsArray( $answer );

		return array(
			'id'     => (int) $row['id'],
			'json'   => (string) $row['response_json'],
			'answer' => $answer,
		);
	}

	/**
	 * Opens a kept answer's sealed key, as anyone holding a candidate key would: with the plugin's cipher and the order's uuid.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $answer    The kept answer.
	 * @param string               $orderUuid The order's uuid.
	 * @param string               $key       The candidate key: 32 bytes.
	 * @return string|null The order key, or null when the box does not open with it.
	 */
	private function opened( array $answer, string $orderUuid, string $key ): ?string {
		$cipher = Cipher::detect();
		$sealed = $answer[ KeptAnswer::SEALED ] ?? array();
		$nonce  = $cipher->decode( (string) ( $sealed['nonce'] ?? '' ) );
		$box    = $cipher->decode( (string) ( $sealed['box'] ?? '' ) );

		$this->assertNotNull( $nonce );
		$this->assertNotNull( $box );

		return $cipher->decrypt( $box, $orderUuid, $nonce, $key );
	}

	/**
	 * Returns every string an answer holds, at any depth.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $values The answer.
	 * @return list<string> The strings.
	 */
	private static function strings( array $values ): array {
		$strings = array();

		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				$strings = array_merge( $strings, self::strings( $value ) );
			} elseif ( is_string( $value ) ) {
				$strings[] = $value;
			}
		}

		return $strings;
	}
}

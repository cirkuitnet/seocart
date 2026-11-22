<?php
/**
 * Tests the idempotency key's declaration: its field, its hash and the fingerprint of a request
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Application\Operations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\IdempotencyKey;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Support\Schema\FieldSpec;

/**
 * The idempotency key is declared once: every operation that takes one binds the same field to the same header, and stores it by the same hash.
 *
 * Planted violations, each shown red and removed:
 * - in CheckoutOperations::placeOrder(), declare the key's field inline again, with `max_length: 65`:
 *   the two operations' fields differ;
 * - in IdempotencyKey::hash(), leave the salt out: the same key on two carts is one key.
 *
 * @since 0.2.0
 */
final class IdempotencyKeyTest extends TestCase {

	/**
	 * Sets up Brain Monkey, with wp_json_encode() as PHP's own.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that the hash is the SHA-256 of the salt and the key joined by a bar, that a salt keeps two scopes apart, and that a key is 1 to 64 bytes.
	 *
	 * @since 0.2.0
	 */
	public function test_a_key_is_hashed_with_its_salt(): void {
		$this->assertSame( hash( 'sha256', 'scope a|attempt-1' ), IdempotencyKey::hash( 'scope a', 'attempt-1' ) );
		$this->assertNotSame( IdempotencyKey::hash( 'scope a', 'attempt-1' ), IdempotencyKey::hash( 'scope b', 'attempt-1' ) );
		$this->assertSame( 64, strlen( IdempotencyKey::hash( 'scope a', str_repeat( 'k', IdempotencyKey::MAX_LENGTH ) ) ) );

		foreach ( array( '', str_repeat( 'k', IdempotencyKey::MAX_LENGTH + 1 ) ) as $key ) {
			try {
				IdempotencyKey::hash( 'scope a', $key );
				$this->fail( 'A key of ' . strlen( $key ) . ' bytes was hashed.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertNotSame( '', $refused->getMessage() );
			}
		}
	}

	/**
	 * Tests that a key is taken when it is 1 to 64 bytes long, counted in bytes and not in characters, and that every key taken can be hashed: 64 two-byte characters are 128 bytes, and refused.
	 *
	 * Planted violation, shown red and removed: in IdempotencyKey::accepts(), count characters with
	 * mb_strlen(): 64 two-byte characters are taken, and then refused by hash().
	 *
	 * @since 0.2.0
	 */
	public function test_a_key_is_taken_by_its_bytes(): void {
		$half    = intdiv( IdempotencyKey::MAX_LENGTH, 2 );
		$taken   = array(
			'one byte'               => 'k',
			'64 ASCII characters'    => str_repeat( 'k', IdempotencyKey::MAX_LENGTH ),
			'32 two-byte characters' => str_repeat( "\u{00e9}", $half ),
		);
		$refused = array(
			'no byte'                => '',
			'65 ASCII characters'    => str_repeat( 'k', IdempotencyKey::MAX_LENGTH + 1 ),
			'33 two-byte characters' => str_repeat( "\u{00e9}", $half + 1 ),
			'64 two-byte characters' => str_repeat( "\u{00e9}", IdempotencyKey::MAX_LENGTH ),
		);

		foreach ( $taken as $what => $key ) {
			$this->assertTrue( IdempotencyKey::accepts( $key ), $what );
			$this->assertSame( 64, strlen( IdempotencyKey::hash( 'scope a', $key ) ), $what );
		}

		foreach ( $refused as $what => $key ) {
			$this->assertFalse( IdempotencyKey::accepts( $key ), $what );
		}
	}

	/**
	 * Tests that the placement's key hash is the shared one, salted with the cart.
	 *
	 * @since 0.2.0
	 */
	public function test_the_placement_hashes_its_key_the_shared_way(): void {
		$cart = hash( 'sha256', 'a cart' );

		$this->assertSame( IdempotencyKey::hash( $cart, 'attempt-1' ), IdempotencyClaim::keyHash( $cart, 'attempt-1' ) );
		$this->assertSame( IdempotencyKey::MAX_LENGTH, IdempotencyClaim::MAX_KEY_LENGTH );
	}

	/**
	 * Tests that a signed-in person's placement key is hashed with the person as well as the cart, and a key that is the cart's alone exactly as before.
	 *
	 * Planted violations, each shown red and removed: in IdempotencyClaim::keyHash(), join the person
	 * to the cart token's hash as text (`$cartTokenHash . '|' . $person` as the salt): a visitor who
	 * chooses the key `{person}|{key}` reaches the person's key; and salt a key with no person too (the
	 * cart token's hash hashed again): the cart's own keys change.
	 *
	 * @since 0.2.0
	 */
	public function test_a_persons_placement_key_is_hashed_with_the_person(): void {
		$cart   = hash( 'sha256', 'a cart' );
		$other  = hash( 'sha256', 'another cart' );
		$person = '42|2026-11-14 09:30:00';

		$this->assertSame( '68baac90efe012951936bf23e14976598b6cf9eca89b73ee5eec86222f342463', IdempotencyClaim::keyHash( $cart, 'attempt-1' ), 'A key that is the cart\'s alone is hashed as before.' );
		$this->assertSame( IdempotencyKey::hash( $cart, 'attempt-1' ), IdempotencyClaim::keyHash( $cart, 'attempt-1', null ) );
		$this->assertNotSame( IdempotencyClaim::keyHash( $cart, 'attempt-1', $person ), IdempotencyClaim::keyHash( $cart, $person . '|attempt-1' ), 'A visitor who chooses the key "{person}|{key}" does not reach the person\'s key.' );
		$this->assertSame( 'd047febbb8b88f814b87ec3fe659d61bd6cbeedc283d0813ac9ba77d8609fb97', IdempotencyClaim::keyHash( $cart, 'attempt-1', $person ), 'A person\'s key is hashed with the cart token\'s hash hashed with the person.' );
		$this->assertNotSame( IdempotencyClaim::keyHash( $cart, 'attempt-1' ), IdempotencyClaim::keyHash( $cart, 'attempt-1', $person ), 'A person\'s key is not the cart\'s.' );
		$this->assertNotSame( IdempotencyClaim::keyHash( $cart, 'attempt-1', $person ), IdempotencyClaim::keyHash( $cart, 'attempt-1', '43|2026-11-14 09:30:00' ), 'Two people on one cart share no key.' );
		$this->assertNotSame( IdempotencyClaim::keyHash( $cart, 'attempt-1', $person ), IdempotencyClaim::keyHash( $other, 'attempt-1', $person ), 'One person on two carts shares no key.' );

		$this->expectException( \InvalidArgumentException::class );

		IdempotencyClaim::keyHash( $cart, 'attempt-1', '' );
	}

	/**
	 * Tests that a request's fingerprint is the SHA-256 of its canonical form's JSON, so one more byte is another request.
	 *
	 * @since 0.2.0
	 */
	public function test_a_fingerprint_is_the_hash_of_the_canonical_request(): void {
		$canonical = array(
			'order_uuid' => '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b',
			'shipping'   => false,
		);

		$this->assertSame( hash( 'sha256', (string) json_encode( $canonical ) ), IdempotencyKey::fingerprint( $canonical ) );
		$this->assertNotSame( IdempotencyKey::fingerprint( $canonical ), IdempotencyKey::fingerprint( array( 'shipping' => true ) + $canonical ) );
	}

	/**
	 * Tests that every operation that takes the key declares the one field, bound to the one header.
	 *
	 * @since 0.2.0
	 */
	public function test_every_operation_with_a_key_declares_the_one_field(): void {
		Functions\when( '__' )->returnArg();

		$expected = self::shape( IdempotencyKey::field() );

		foreach ( array( CheckoutOperations::placeOrder(), PaymentOperations::refundOrder() ) as $operation ) {
			$fields = array_values( array_filter( $operation->input(), static fn( FieldSpec $field ): bool => IdempotencyKey::FIELD === $field->name() ) );

			$this->assertCount( 1, $fields, $operation->id() . ' takes the key once.' );
			$this->assertSame( $expected, self::shape( $fields[0] ), $operation->id() . ' declares the shared field.' );
			$this->assertSame( IdempotencyKey::HEADER, $operation->rest()?->headers()[ IdempotencyKey::FIELD ]->name ?? null, $operation->id() . ' reads it from the shared header.' );
			$this->assertTrue( $operation->rest()?->headers()[ IdempotencyKey::FIELD ]->required ?? false, $operation->id() . ' requires the header.' );
		}
	}

	/**
	 * Returns what a field declares, its texts' closures aside.
	 *
	 * @since 0.2.0
	 *
	 * @param FieldSpec $field The field.
	 * @return array<string, mixed> Its name, type, description, example, length and privacy.
	 */
	private static function shape( FieldSpec $field ): array {
		return array(
			'name'        => $field->name(),
			'type'        => $field->type(),
			'description' => $field->description(),
			'example'     => $field->example(),
			'max_length'  => $field->maxLength(),
			'required'    => $field->isRequired(),
			'privacy'     => $field->privacy(),
		);
	}
}

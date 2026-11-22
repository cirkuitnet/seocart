<?php
/**
 * IdempotencyClaim: what claiming an idempotency key answered
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain;

use SEOCart\Application\Operations\IdempotencyKey;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * A claimed idempotency key: either this request owns it and goes on, or an earlier request placed its order and this one answers as it did.
 *
 * Owns one fact: what a key stands for. A client sends one key per attempt to place an order and
 * the same key when it retries that attempt. Keys are scoped to the cart, by hashing the cart
 * token's hash with the key (keyHash()), so no client can collide with another cart's key, and
 * neither the key nor the token is stored. A signed-in person's keys are scoped to the cart and
 * to the person, so two people presenting one cart never share a key: another person who sends
 * the key reaches a key of their own.
 *
 * @since 0.1.0
 * @since 0.2.0 A signed-in person's keys are the person's.
 */
final readonly class IdempotencyClaim {

	/**
	 * What an order placement claims its keys for: its operation's id, so no other operation shares a key with it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PLACE_ORDER_SCOPE = 'checkout.place_order';

	/**
	 * The longest key a client may send, in bytes: the idempotency key's own limit.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_KEY_LENGTH = IdempotencyKey::MAX_LENGTH;

	/**
	 * Holds the answer. Use owned() or replay().
	 *
	 * @since 0.1.0
	 *
	 * @param int         $id           The key's row.
	 * @param bool        $owned        True when this request owns the key and places the order.
	 * @param int|null    $orderId      The order an earlier request placed with the key; null when owned.
	 * @param string|null $responseJson The answer the earlier request's key keeps; null when owned.
	 */
	private function __construct(
		public int $id,
		public bool $owned,
		public ?int $orderId,
		public ?string $responseJson
	) {
	}

	/**
	 * Returns the answer of a claim this request owns: it goes on to place its order and completes the key.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id The key's row.
	 * @return self The claim.
	 */
	public static function owned( int $id ): self {
		return new self( $id, true, null, null );
	}

	/**
	 * Returns the answer of a key an earlier request placed its order with, for the same request: send its answer again.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id           The key's row.
	 * @param int    $orderId      The order it placed.
	 * @param string $responseJson The answer its key keeps.
	 * @return self The claim.
	 */
	public static function replay( int $id, int $orderId, string $responseJson ): self {
		return new self( $id, false, $orderId, $responseJson );
	}

	/**
	 * Returns the hash a key is stored and found by: the SHA-256 of the cart token's hash and the key, joined by a bar; for a person, of the cart token's hash hashed with the person, and the key.
	 *
	 * The person is hashed into the salt, never joined to it as text: a key may hold a bar, so a
	 * visitor who chose the key `{person}|{key}` would otherwise reach that person's `{key}`. The
	 * salt of a key that is the cart's alone is the cart token's hash, and a person's is another
	 * SHA-256; the two never meet.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The person.
	 *
	 * @throws \InvalidArgumentException When the cart token's hash is not a SHA-256 in lower-case
	 *                                   hexadecimal, the key is empty or longer than MAX_KEY_LENGTH,
	 *                                   or the person is empty.
	 *
	 * @param string      $cartTokenHash The hash of the token of the cart the order is placed from.
	 * @param string      $key           The key the client sent.
	 * @param string|null $person        Optional. The signed-in person who sends it: their user id and
	 *                                   registration time. Default null: the key is the cart's alone.
	 * @return string The hash, 64 lower-case hexadecimal characters.
	 */
	public static function keyHash( string $cartTokenHash, string $key, ?string $person = null ): string {
		if ( 1 !== preg_match( '/^[0-9a-f]{64}\z/', $cartTokenHash ) ) {
			throw new \InvalidArgumentException( 'A cart token hash is a SHA-256 in lower-case hexadecimal: 64 characters.' );
		}

		if ( '' === $person ) {
			throw new \InvalidArgumentException( 'A person is a user\'s id and registration time, never empty: pass null for a key that is the cart\'s alone.' );
		}

		return IdempotencyKey::hash( null === $person ? $cartTokenHash : hash( 'sha256', $cartTokenHash . '|' . $person ), $key );
	}
}

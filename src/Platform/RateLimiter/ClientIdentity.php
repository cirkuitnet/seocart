<?php
/**
 * ClientIdentity: the key a client's requests, or one cart's, are counted under
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * An HMAC over who is counted: stable for them, and not reversible to anything it was built from.
 *
 * Owns one fact: how a counted party becomes a counter key. There are two kinds, and each has its
 * named constructor, which hashes, so a raw address or a raw token can never reach a counter row or
 * a cache key:
 *
 * - ofClient(): a client, by its trusted address and its customer id. Nothing the client makes up
 *   is part of it, so a client cannot become a new one by sending new values;
 * - ofCart(): one cart, by its token. Only a token the cart module has matched to a stored cart may
 *   be counted this way (ClientIdentities::ofCart() takes it), because a made-up token is a new key
 *   every time.
 *
 * The kind is part of what is hashed, and each text part is prefixed with its length, so no client
 * and no cart, and no two different sets of parts, are ever hashed as the same text.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class ClientIdentity {

	/**
	 * The key: 64 hexadecimal characters.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Keeps the key. Use ofClient() or ofCart().
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The HMAC, in hexadecimal.
	 */
	private function __construct( string $key ) {
		$this->key = $key;
	}

	/**
	 * Builds the identity of a client.
	 *
	 * @since 0.1.0
	 *
	 * @param string $address     The client's address, as TrustedClientIp resolved it.
	 * @param int    $customer_id The WordPress user the request was authenticated as, 0 for a guest.
	 * @param string $secret      The key of the HMAC.
	 * @return self The identity.
	 */
	public static function ofClient( string $address, int $customer_id, #[\SensitiveParameter] string $secret ): self {
		return new self( hash_hmac( 'sha256', sprintf( 'client:%d:%s%d', strlen( $address ), $address, $customer_id ), $secret ) );
	}

	/**
	 * Builds the identity of a cart.
	 *
	 * @since 0.1.0
	 *
	 * @param string $cart_token The token of a cart the cart module has found.
	 * @param string $secret     The key of the HMAC.
	 * @return self The identity.
	 */
	public static function ofCart( #[\SensitiveParameter] string $cart_token, #[\SensitiveParameter] string $secret ): self {
		return new self( hash_hmac( 'sha256', sprintf( 'cart:%d:%s', strlen( $cart_token ), $cart_token ), $secret ) );
	}

	/**
	 * Returns the key the requests are counted under.
	 *
	 * @since 0.1.0
	 *
	 * @return string 64 hexadecimal characters.
	 */
	public function key(): string {
		return $this->key;
	}
}

<?php
/**
 * ClientIdentities: builds the identities the rate limiter counts under, for the current request
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter;

use SEOCart\Cart\Domain\CartToken;

defined( 'ABSPATH' ) || exit;

/**
 * Names who the rate limiter counts: the client of the current request, or one found cart.
 *
 * Owns one fact: which secret keys the identities. Each identity is an HMAC (ClientIdentity),
 * keyed with a secret the site already holds: a key derived, for this one purpose, from
 * WordPress's authentication salt (`wp_salt( 'auth' )`). Nothing is stored and nothing is queried
 * when the salts are in wp-config.php, as WordPress's installer writes them. A counter row
 * therefore never holds an address or a token, and without the salt its key cannot be tied to
 * one. Changing the salts, which also logs every user out, only starts every counter again.
 *
 * - of(): the client, by its trusted address and its customer id. Every per-client bucket counts
 *   under it. A cart token is not part of it: anyone can make one up, and a made-up token per
 *   request would make a new client of every request.
 * - ofCart(): one cart, for a per-cart bucket. Call it only with the token of a cart the cart
 *   module has found; a token a request merely carries says nothing until then.
 *
 * The key is derived on the first identity asked for, never when the service is built.
 *
 * @since 0.1.0
 */
final class ClientIdentities {

	/**
	 * What the derived key is for, mixed into its derivation so it serves nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const KEY_PURPOSE = 'seocart rate-limit client identity';

	/**
	 * Resolves the client's address.
	 *
	 * @since 0.1.0
	 *
	 * @var TrustedClientIp
	 */
	private TrustedClientIp $address;

	/**
	 * Returns the key of the HMAC.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): string
	 */
	private \Closure $secret;

	/**
	 * The key, once derived.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $key = null;

	/**
	 * Creates the service. Derives nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param TrustedClientIp $address Resolves the client's address.
	 * @param \Closure        $secret  Returns the key of the HMAC; called once, on first use.
	 *
	 * @phpstan-param \Closure(): string $secret
	 */
	public function __construct( TrustedClientIp $address, \Closure $secret ) {
		$this->address = $address;
		$this->secret  = $secret;
	}

	/**
	 * Creates the service for the request being served, keyed by the site's authentication salt.
	 *
	 * @since 0.1.0
	 *
	 * @return self The service.
	 */
	public static function forRequest(): self {
		return new self( TrustedClientIp::fromRequest(), static fn(): string => hash_hmac( 'sha256', self::KEY_PURPOSE, wp_salt( 'auth' ), true ) );
	}

	/**
	 * Returns the identity of the current request's client.
	 *
	 * @since 0.1.0
	 *
	 * @param int $customer_id The user the request was authenticated as, 0 for a guest.
	 * @return ClientIdentity The identity.
	 */
	public function of( int $customer_id ): ClientIdentity {
		return ClientIdentity::ofClient( $this->address->address(), $customer_id, $this->key() );
	}

	/**
	 * Returns the identity of a cart, for a bucket counted per cart.
	 *
	 * @since 0.1.0
	 *
	 * @param CartToken $token The token of a cart the cart module has found. Never a token a request
	 *                         merely carries: that one is not yet known to name any cart.
	 * @return ClientIdentity The identity.
	 */
	public function ofCart( CartToken $token ): ClientIdentity {
		return ClientIdentity::ofCart( $token->value(), $this->key() );
	}

	/**
	 * Returns the key of the HMAC, derived on first use.
	 *
	 * @since 0.1.0
	 *
	 * @return string The key.
	 */
	private function key(): string {
		$this->key ??= ( $this->secret )();

		return $this->key;
	}
}

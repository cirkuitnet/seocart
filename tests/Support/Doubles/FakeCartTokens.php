<?php
/**
 * FakeCartTokens: a cart-token seam a test sets and reads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Cart\Application\CartTokens;
use SEOCart\Cart\Domain\CartToken;

/**
 * The token a request carries is the one the test presented; every issued token is recorded.
 *
 * A browser keeps the token its first write was answered with, and sends it from then on;
 * keepIssued() plays that step, so a test reads as the requests a client sends.
 *
 * @since 0.1.0
 */
final class FakeCartTokens implements CartTokens {

	/**
	 * The token the next request carries, or null for none.
	 *
	 * @since 0.1.0
	 *
	 * @var CartToken|null
	 */
	public ?CartToken $presented = null;

	/**
	 * Every token issued, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<CartToken>
	 */
	public array $issued = array();

	/**
	 * The lifetime each token of $issued was issued with, in seconds, in the same order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	public array $lifetimes = array();

	/**
	 * Returns the token the test presented.
	 *
	 * @since 0.1.0
	 *
	 * @return CartToken|null The token, or null.
	 */
	public function presented(): ?CartToken {
		return $this->presented;
	}

	/**
	 * Records an issued token, and its lifetime.
	 *
	 * @since 0.1.0
	 *
	 * @param CartToken $token           The token.
	 * @param int       $lifetimeSeconds How long its cart now lives.
	 */
	public function issue( CartToken $token, int $lifetimeSeconds ): void {
		$this->issued[]    = $token;
		$this->lifetimes[] = $lifetimeSeconds;
	}

	/**
	 * Presents the token issued last, as a browser sends the cookie it was given.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no token was issued.
	 *
	 * @return CartToken The token.
	 */
	public function keepIssued(): CartToken {
		$token = $this->issued[ count( $this->issued ) - 1 ] ?? null;

		if ( null === $token ) {
			throw new \LogicException( 'No token was issued to keep.' );
		}

		$this->presented = $token;

		return $token;
	}
}

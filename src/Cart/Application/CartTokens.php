<?php
/**
 * CartTokens: the cart token a request carries, and the one its response creates
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Application;

use SEOCart\Cart\Domain\CartToken;

defined( 'ABSPATH' ) || exit;

/**
 * The port through which the cart service reads and hands out cart tokens.
 *
 * The service never sees how a token travels. It asks for the token the client sent, which finds
 * the client's cart, and when it creates a cart it issues that cart's token, which the response
 * then carries to the client. The Store API implements the port with a cookie and a header
 * (CartTokenTransport). A cart is created by the first write that adds something to it, so a
 * token is issued only by a write, never by a read.
 *
 * @since 0.1.0
 */
interface CartTokens {

	/**
	 * Returns the token the client sent with the request being served.
	 *
	 * @since 0.1.0
	 *
	 * @return CartToken|null The token, or null when the request carries none, or carries something
	 *                        that is not a token.
	 */
	public function presented(): ?CartToken;

	/**
	 * Hands the token of a cart this request created to the client, with the response.
	 *
	 * Call it once the cart is stored. The token reaches the client only with a successful response
	 * to a write.
	 *
	 * @since 0.1.0
	 *
	 * @param CartToken $token The new cart's token.
	 */
	public function issue( CartToken $token ): void;
}

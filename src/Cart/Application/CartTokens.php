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
 * the client's cart. When a write creates a cart, or extends the life of one, it issues the
 * cart's token with the cart's new lifetime, and the response carries it to the client, so the
 * client keeps the token exactly as long as the cart lives. The Store API implements the port
 * with a cookie and a header (CartTokenTransport). Only a write creates a cart or extends its
 * life, so a token is issued only by a write, never by a read.
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
	 * Hands the token of a cart this request created, or whose life it extended, to the client with the response, to keep for as long as the cart lives.
	 *
	 * Call it once the cart is written. The token reaches the client only with a successful
	 * response to a write; the last call of a request is the one sent.
	 *
	 * @since 0.1.0
	 *
	 * @param CartToken $token           The cart's token.
	 * @param int       $lifetimeSeconds How long the cart now lives, and so the token.
	 */
	public function issue( CartToken $token, int $lifetimeSeconds ): void;
}

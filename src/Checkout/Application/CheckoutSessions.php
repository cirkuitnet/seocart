<?php
/**
 * CheckoutSessions: the port to the stored checkout sessions
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutSession;
use SEOCart\Checkout\Domain\FrozenQuotes;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the one checkout session a cart may have.
 *
 * A session lives exactly as long as its cart: the cart's sweep deletes it with the cart. Its
 * details are written behind the cart's version, inside the cart's transaction, and every such
 * write invalidates its quotes; the quotes are then written back for the version they were taken
 * at, and invalidated again when the cart's currency changes.
 *
 * @since 0.1.0
 */
interface CheckoutSessions {

	/**
	 * Reads a cart's session: one query.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId The cart.
	 * @return CheckoutSession|null The session, or null when the cart has none.
	 */
	public function find( int $cartId ): ?CheckoutSession;

	/**
	 * Writes a cart's details, creating its session or replacing the details it had, and invalidates its quotes. Runs only inside the caller's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int             $cartId  The cart, whose compare-and-swap the transaction began with.
	 * @param CheckoutDetails $details The details, each one left out cleared.
	 */
	public function save( int $cartId, CheckoutDetails $details ): void;

	/**
	 * Keeps the quotes a calculation of the cart at a version consumed, unless the session holds quotes of a later version.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $cartId      The cart.
	 * @param int          $cartVersion The cart's version the quotes were taken at, 1 or more.
	 * @param FrozenQuotes $quotes      The quotes.
	 * @return bool True when the quotes were kept; false when the cart has no session, or its
	 *              quotes are of a later version.
	 */
	public function storeQuotes( int $cartId, int $cartVersion, FrozenQuotes $quotes ): bool;

	/**
	 * Drops a session's quotes, as a change of the cart's currency must. Runs only inside the caller's transaction.
	 *
	 * The shipping method chosen stays: it is the shopper's preference, not a quote.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int $cartId The cart, whose compare-and-swap the transaction began with.
	 * @return bool True when the cart has a session.
	 */
	public function invalidateQuotes( int $cartId ): bool;
}

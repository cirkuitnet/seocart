<?php
/**
 * CartRepository: the statements the cart service sends
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Domain;

use SEOCart\Support\Currency;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * The port through which the cart service, and order placement, read and change carts.
 *
 * Owns one fact: what each cart statement does. Every change of a cart begins with the
 * compare-and-swap on its version, in the caller's transaction, and every other statement of the
 * change follows it in that transaction, so the version a write answers with names exactly the
 * lines it leaves. Every read and every compare-and-swap sees a cart only until its expiry, by
 * the database clock, so an expired cart is gone to them whether or not it has been swept.
 *
 * The methods that change rows run only inside a transaction, and refuse to run outside one.
 *
 * @since 0.1.0
 */
interface CartRepository {

	/**
	 * Reads the live cart whose token has this hash, and its lines: two queries.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tokenHash The SHA-256 of the cart token, 64 hexadecimal characters.
	 * @return Cart|null The cart, or null when there is none or it has expired.
	 */
	public function findByTokenHash( string $tokenHash ): ?Cart;

	/**
	 * Reads the live cart whose token has this hash without its lines: one query, for a write, which reads the lines back once it has changed them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tokenHash The SHA-256 of the cart token, 64 hexadecimal characters.
	 * @return Cart|null The cart, its lines left empty, or null when there is none or it has expired.
	 */
	public function findRowByTokenHash( string $tokenHash ): ?Cart;

	/**
	 * Reads a cart's lines, in the order they were added.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId The cart.
	 * @return list<CartLine> The lines.
	 */
	public function lines( int $cartId ): array;

	/**
	 * Inserts an open cart at version 1, without lines.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $tokenHash  The SHA-256 of the cart's new token.
	 * @param Currency $currency   The cart's currency.
	 * @param Locale   $locale     The cart's locale.
	 * @param int      $ttlSeconds How long the cart lives without a write.
	 * @return int The cart's id.
	 */
	public function insert( string $tokenHash, Currency $currency, Locale $locale, int $ttlSeconds ): int;

	/**
	 * The compare-and-swap: moves an open, live cart from the expected version to the next, and extends its life.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId          The cart.
	 * @param int $expectedVersion The version the write was based on.
	 * @param int $ttlSeconds      How long the cart lives from now.
	 * @return bool True when the cart was open, live and at that version; false when it changed nothing.
	 */
	public function compareAndSwap( int $cartId, int $expectedVersion, int $ttlSeconds ): bool;

	/**
	 * The compare-and-swap of an order placement: as compareAndSwap(), and moves the cart to placing.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId          The cart.
	 * @param int $expectedVersion The version the placement was based on.
	 * @param int $ttlSeconds      How long the cart lives from now.
	 * @return bool True when the cart was open, live and at that version; false when it changed nothing.
	 */
	public function claimForPlacement( int $cartId, int $expectedVersion, int $ttlSeconds ): bool;

	/**
	 * Reads why a compare-and-swap changed nothing, with a locking read. It classifies; it decides nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId The cart.
	 * @return array{version: int, status: CartStatus, order_id: int|null, live: bool}|null The
	 *         cart's current version, status and order, and whether it is still live by the
	 *         database clock; null when it does not exist.
	 */
	public function diagnose( int $cartId ): ?array;

	/**
	 * Adds lines to a cart in one statement: a new identity becomes a line, a known one adds to its line's quantity, up to CartLine::MAX_QUANTITY.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $cartId The cart.
	 * @param CartLine[] $lines  The lines, at most one per identity.
	 *
	 * @phpstan-param non-empty-list<CartLine> $lines
	 */
	public function addLines( int $cartId, array $lines ): void;

	/**
	 * Sets the quantity of one of a cart's lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $cartId   The cart.
	 * @param LineIdentity $identity The line.
	 * @param int          $quantity The units, 1 to CartLine::MAX_QUANTITY.
	 * @return bool True when the cart has the line.
	 */
	public function setQuantity( int $cartId, LineIdentity $identity, int $quantity ): bool;

	/**
	 * Removes one of a cart's lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $cartId   The cart.
	 * @param LineIdentity $identity The line.
	 * @return bool True when the cart had the line.
	 */
	public function removeLine( int $cartId, LineIdentity $identity ): bool;

	/**
	 * Recounts a cart's lines and units into its row, if it holds no more than a number of lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId   The cart.
	 * @param int $maxLines The most lines a cart may hold.
	 * @return bool True when the counts were written; false when the cart holds more lines than that.
	 */
	public function project( int $cartId, int $maxLines ): bool;

	/**
	 * Records the order a placing cart produced.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId  The cart.
	 * @param int $orderId The order.
	 * @return bool True when the cart was placing.
	 */
	public function bindOrder( int $cartId, int $orderId ): bool;

	/**
	 * Moves a placing cart to the status its order's settlement decided: converted, or open again.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $cartId  The cart.
	 * @param int        $orderId The order the cart is placing.
	 * @param CartStatus $status  CartStatus::Converted or CartStatus::Open.
	 * @return bool True when the cart was placing that order.
	 */
	public function settle( int $cartId, int $orderId, CartStatus $status ): bool;
}

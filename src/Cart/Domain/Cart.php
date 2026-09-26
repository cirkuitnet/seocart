<?php
/**
 * Cart: a shopper's cart as it was read
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
 * A cart as it was read: its row and its lines, in the order they were added.
 *
 * Owns one fact: what a read of a cart returns, and how many lines a cart may hold. It is a read,
 * never a decision: every change is decided by the compare-and-swap on the cart's version, which
 * begins each write, never by the version held here. The lines are in the order of their
 * `cart_lines.id`, the order the calculation takes them in.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final readonly class Cart {

	/**
	 * The most lines a cart holds. A write that would leave more is refused whole.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_LINES = 50;

	/**
	 * The retention policy of carts: a cart lives its kind's period after its last write.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETENTION = 'carts';

	/**
	 * The cart's id.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * The version: 1 when the cart was created, one more after every accepted write.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $version;

	/**
	 * Whether the cart may still be changed.
	 *
	 * @since 0.1.0
	 *
	 * @var CartStatus
	 */
	public CartStatus $status;

	/**
	 * The order the cart last produced, or null when it has produced none.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	public ?int $orderId;

	/**
	 * The one currency the cart is in.
	 *
	 * @since 0.1.0
	 *
	 * @var Currency
	 */
	public Currency $currency;

	/**
	 * The locale the cart was started in.
	 *
	 * @since 0.1.0
	 *
	 * @var Locale
	 */
	public Locale $locale;

	/**
	 * The promotion codes applied to the cart, in the order they were applied.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $promotionCodes;

	/**
	 * The lines, in the order they were added.
	 *
	 * @since 0.1.0
	 *
	 * @var list<CartLine>
	 */
	public array $lines;

	/**
	 * Creates a cart as it was read.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $id             The cart's id.
	 * @param int        $version        The version.
	 * @param CartStatus $status         Whether the cart may still be changed.
	 * @param int|null   $orderId        The order the cart last produced, or null.
	 * @param Currency   $currency       The cart's currency.
	 * @param Locale     $locale         The cart's locale.
	 * @param string[]   $promotionCodes The promotion codes applied.
	 * @param CartLine[] $lines          The lines, in `cart_lines.id` order.
	 *
	 * @phpstan-param list<string>   $promotionCodes
	 * @phpstan-param list<CartLine> $lines
	 */
	public function __construct( int $id, int $version, CartStatus $status, ?int $orderId, Currency $currency, Locale $locale, array $promotionCodes, array $lines ) {
		$this->id             = $id;
		$this->version        = $version;
		$this->status         = $status;
		$this->orderId        = $orderId;
		$this->currency       = $currency;
		$this->locale         = $locale;
		$this->promotionCodes = $promotionCodes;
		$this->lines          = $lines;
	}

	/**
	 * Returns the same cart with other lines, as a write left it.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $version The version the write gave the cart.
	 * @param CartLine[] $lines   The lines after the write, in `cart_lines.id` order.
	 * @return self The cart after the write.
	 *
	 * @phpstan-param list<CartLine> $lines
	 */
	public function after( int $version, array $lines ): self {
		return new self( $this->id, $version, $this->status, $this->orderId, $this->currency, $this->locale, $this->promotionCodes, $lines );
	}
}

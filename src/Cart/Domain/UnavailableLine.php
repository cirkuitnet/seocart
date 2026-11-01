<?php
/**
 * UnavailableLine: a cart line that wants more units than are for sale now
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A line of a cart whose variant has fewer units for sale than the cart's lines of it hold, why, and how many are for sale.
 *
 * Owns one fact: when a line wants more than is for sale. The lines of one variant compete for
 * the same units, so their quantities are summed and every one of them is judged against the
 * variant's figure: none of them is flagged while the figure covers the sum, and all of them are
 * once it does not. A variant with no limit is never flagged; a variant the figures leave out has
 * nothing for sale.
 *
 * The flag is advisory: a snapshot of the figure when the answer was built, never stored. The line
 * stays in the cart and in its totals, and placing the order decides with its hold of the units,
 * which may still refuse a line that was not flagged, or take one that was, if the stock changed
 * since.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.2.0
 */
final readonly class UnavailableLine {

	/**
	 * No unit of the variant is for sale.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OUT_OF_STOCK = 'out_of_stock';

	/**
	 * Some units of the variant are for sale, fewer than the cart's lines of it hold.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSUFFICIENT_STOCK = 'insufficient_stock';

	/**
	 * Holds the line, the reason and the figure; of() is the one way to make one.
	 *
	 * @since 0.2.0
	 *
	 * @param string $lineIdentity The line's identity.
	 * @param int    $variantId    The variant.
	 * @param string $reason       OUT_OF_STOCK or INSUFFICIENT_STOCK.
	 * @param int    $forSale      The units of the variant for sale: 0 when it is out of stock.
	 */
	private function __construct(
		public string $lineIdentity,
		public int $variantId,
		public string $reason,
		public int $forSale
	) {
	}

	/**
	 * Returns the lines that want more units than are for sale, in the order of the lines given.
	 *
	 * @since 0.2.0
	 *
	 * @param CartLine[]           $lines   The lines to judge.
	 * @param array<int, int|null> $forSale The units of each variant for sale, by variant id: null
	 *                                      for no limit. A variant it does not name has none.
	 * @return list<UnavailableLine> The lines flagged: every line of a variant whose figure is
	 *                               below the sum of its lines' quantities.
	 *
	 * @phpstan-param list<CartLine> $lines
	 */
	public static function of( array $lines, array $forSale ): array {
		$wanted = array();

		foreach ( $lines as $line ) {
			$wanted[ $line->variantId ] = ( $wanted[ $line->variantId ] ?? 0 ) + $line->quantity;
		}

		$unavailable = array();

		foreach ( $lines as $line ) {
			// A null figure is no limit; only a variant the map leaves out has nothing for sale.
			$figure = array_key_exists( $line->variantId, $forSale ) ? $forSale[ $line->variantId ] : 0;

			if ( null !== $figure && $figure < $wanted[ $line->variantId ] ) {
				$figure        = max( 0, $figure );
				$unavailable[] = new self( $line->identity->value(), $line->variantId, 0 === $figure ? self::OUT_OF_STOCK : self::INSUFFICIENT_STOCK, $figure );
			}
		}

		return $unavailable;
	}
}

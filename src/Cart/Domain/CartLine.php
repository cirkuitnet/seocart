<?php
/**
 * CartLine: one line of a cart, a variant and how many of it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A refused line is a caller's programming error, reported to the developer, never HTML; this class may not call WordPress.

/**
 * One line of a cart: its identity, its variant and its quantity.
 *
 * Owns one fact: how many units a line may hold, and how lines of the same identity combine.
 * A line holds 1 to MAX_QUANTITY units. Two lines with the same identity are one line whose
 * quantity is their sum, filled to MAX_QUANTITY at most; lines with different identities never
 * combine. The cart's statements apply the same rule to lines already stored.
 *
 * A cart stores selections, never prices: the calculation prices each line when it runs.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final readonly class CartLine {

	/**
	 * The most units one line holds. Adding to a full line leaves it full.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_QUANTITY = 99999;

	/**
	 * The line's identity.
	 *
	 * @since 0.1.0
	 *
	 * @var LineIdentity
	 */
	public LineIdentity $identity;

	/**
	 * The variant.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $variantId;

	/**
	 * The units, 1 to MAX_QUANTITY.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $quantity;

	/**
	 * Creates a line.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the quantity is not 1 to MAX_QUANTITY, or the variant id is below 1.
	 *
	 * @param LineIdentity $identity  The line's identity.
	 * @param int          $variantId The variant, the one the identity was made from.
	 * @param int          $quantity  The units.
	 */
	public function __construct( LineIdentity $identity, int $variantId, int $quantity ) {
		if ( $variantId < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A line\'s variant id is 1 or more; %d was given.', $variantId ) );
		}

		if ( $quantity < 1 || $quantity > self::MAX_QUANTITY ) {
			throw new \InvalidArgumentException( sprintf( 'A line holds 1 to %d units; %d was given.', self::MAX_QUANTITY, $quantity ) );
		}

		$this->identity  = $identity;
		$this->variantId = $variantId;
		$this->quantity  = $quantity;
	}

	/**
	 * Creates a plain line of a variant: no add-ons, no personalization.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the variant id is below 1 or the quantity is out of range.
	 *
	 * @param int $variantId The variant.
	 * @param int $quantity  The units.
	 * @return self The line.
	 */
	public static function of( int $variantId, int $quantity ): self {
		return new self( LineIdentity::of( $variantId ), $variantId, $quantity );
	}

	/**
	 * Combines lines of the same identity into one, in the order each identity first appears.
	 *
	 * @since 0.1.0
	 *
	 * @param CartLine[] $lines The lines.
	 * @return list<CartLine> One line per identity, its quantity the sum of that identity's lines,
	 *                        at most MAX_QUANTITY.
	 *
	 * @phpstan-param list<CartLine> $lines
	 */
	public static function merge( array $lines ): array {
		$merged = array();

		foreach ( $lines as $line ) {
			$key = $line->identity->value();

			$merged[ $key ] = isset( $merged[ $key ] )
				? new self( $line->identity, $line->variantId, min( $merged[ $key ]->quantity + $line->quantity, self::MAX_QUANTITY ) )
				: $line;
		}

		return array_values( $merged );
	}
}

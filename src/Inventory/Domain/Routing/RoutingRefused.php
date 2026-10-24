<?php
/**
 * RoutingRefused: no plan the store's mode allows can give the order its units
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Routing;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message names ids and counts for the developer; the shopper's text is the error catalog's.

/**
 * The router's refusal, with the reason a shopper can act on.
 *
 * Owns one fact: the two reasons an order cannot be routed. `quantity`: one item's quantity is
 * more than can be given, with the figure that could be: the most any one location has where the
 * order must ship from one, the sum where units may come from several. `combination`: each item
 * is available somewhere, but no one location has them all, and the mode does not allow that; the
 * items that cannot travel with the rest are named, and no figure is, since every item's own
 * figure would be enough.
 *
 * @since 0.2.0
 */
final class RoutingRefused extends \RuntimeException {

	/**
	 * The reason when an item's quantity is more than can be given.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const QUANTITY = 'quantity';

	/**
	 * The reason when the items are available, but not together at one location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const COMBINATION = 'combination';

	/**
	 * QUANTITY or COMBINATION.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * The variants refused: the one whose quantity is more than can be given, or those that cannot travel with the rest, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	public readonly array $variantIds;

	/**
	 * The units asked for of the one variant, for QUANTITY; null for COMBINATION.
	 *
	 * @since 0.2.0
	 *
	 * @var int|null
	 */
	public readonly ?int $requested;

	/**
	 * The units that could be given of it, for QUANTITY; null for COMBINATION.
	 *
	 * @since 0.2.0
	 *
	 * @var int|null
	 */
	public readonly ?int $available;

	/**
	 * Records a refusal. Use the named constructors.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $reason     QUANTITY or COMBINATION.
	 * @param int[]    $variantIds The variants refused.
	 * @param int|null $requested  The units asked for, or null.
	 * @param int|null $available  The units that could be given, or null.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	private function __construct( string $reason, array $variantIds, ?int $requested, ?int $available ) {
		parent::__construct( sprintf( 'The order cannot be routed: %1$s of variant %2$s.', $reason, implode( ', ', $variantIds ) ) );

		$this->reason     = $reason;
		$this->variantIds = $variantIds;
		$this->requested  = $requested;
		$this->available  = $available;
	}

	/**
	 * Refuses an item whose quantity is more than can be given.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @param int $requested The units asked for.
	 * @param int $available The units that could be given.
	 * @return self The refusal.
	 */
	public static function quantity( int $variantId, int $requested, int $available ): self {
		return new self( self::QUANTITY, array( $variantId ), $requested, $available );
	}

	/**
	 * Refuses items that are each available, but not together at one location.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants that cannot travel with the rest, ascending.
	 * @return self The refusal.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public static function combination( array $variantIds ): self {
		return new self( self::COMBINATION, $variantIds, null, null );
	}
}

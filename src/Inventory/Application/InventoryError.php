<?php
/**
 * InventoryError: the error catalog of the inventory module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors holding, adjusting or deleting stock can end in.
 *
 * Owns one fact: how a refused stock change is reported. Each refusal happened in the database,
 * in the conditional statement's WHERE clause; the values in a message come from one read
 * afterwards that classifies the refusal and decides nothing. A caller's programming error, such
 * as a hold of zero units, is an \InvalidArgumentException or a \LogicException, never a row here.
 *
 * @since 0.1.0
 */
enum InventoryError: string implements ErrorCode {

	/**
	 * The variant has no stock item.
	 *
	 * @since 0.1.0
	 */
	case ItemMissing = 'stock.item_missing';

	/**
	 * Fewer units are available than a hold asked for, after expired holds were reclaimed.
	 *
	 * @since 0.1.0
	 */
	case Insufficient = 'stock.insufficient';

	/**
	 * An adjustment of zero units, which changes nothing.
	 *
	 * @since 0.1.0
	 */
	case ZeroDelta = 'stock.zero_delta';

	/**
	 * The item's on_hand is not the value the caller read, so the adjustment was not applied.
	 *
	 * @since 0.1.0
	 */
	case OnHandConflict = 'stock.on_hand_conflict';

	/**
	 * The adjustment would take on_hand below zero.
	 *
	 * @since 0.1.0
	 */
	case AdjustmentBelowZero = 'stock.adjustment_below_zero';

	/**
	 * The variant cannot be deleted while an order's allocation of it is open.
	 *
	 * @since 0.1.0
	 */
	case DeleteBlocked = 'stock.delete_blocked';

	/**
	 * The item's `held` is lower than its hold rows add up to, so its units cannot be given back.
	 *
	 * @since 0.1.0
	 */
	case ProjectionCorrupt = 'stock.projection_corrupt';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::ItemMissing,
				404,
				static fn(): string =>
					/* translators: %1$s: The id of a product variant. */
					__( 'Variant %1$s has no stock item.', 'seocart' ),
				array( 'variant_id' )
			),
			new ErrorDefinition(
				self::Insufficient,
				409,
				static fn(): string =>
					/* translators: %1$s: The id of a product variant. %2$s: Units asked for. %3$s: Units available. */
					__( 'Variant %1$s: %2$s asked for, but only %3$s available.', 'seocart' ),
				array( 'variant_id', 'requested', 'available' )
			),
			new ErrorDefinition(
				self::ZeroDelta,
				400,
				static fn(): string => __( 'An adjustment of zero units changes nothing. To confirm a count, adjust by the difference you found.', 'seocart' )
			),
			new ErrorDefinition(
				self::OnHandConflict,
				409,
				static fn(): string =>
					/* translators: %1$s: The id of a product variant. %2$s: The on-hand quantity the request expected. %3$s: The on-hand quantity now. */
					__( 'Variant %1$s: you expected %2$s on hand, but there are %3$s, so the adjustment was not applied. Read the stock again, then repeat the adjustment if it is still needed.', 'seocart' ),
				array( 'variant_id', 'expected', 'on_hand' )
			),
			new ErrorDefinition(
				self::AdjustmentBelowZero,
				409,
				static fn(): string =>
					/* translators: %1$s: The id of a product variant. %2$s: The on-hand quantity. %3$s: The change asked for, a negative number. */
					__( 'Variant %1$s has %2$s on hand, so a change of %3$s would take it below zero.', 'seocart' ),
				array( 'variant_id', 'on_hand', 'delta' )
			),
			new ErrorDefinition(
				self::DeleteBlocked,
				409,
				static fn(): string =>
					/* translators: %1$s: The id of a product variant. */
					__( 'Variant %1$s cannot be deleted while an order still has units of it allocated.', 'seocart' ),
				array( 'variant_id' )
			),
			new ErrorDefinition(
				self::ProjectionCorrupt,
				500,
				static fn(): string =>
					/* translators: %1$s: The id of a product variant. */
					__( 'The held quantity of variant %1$s is lower than its holds add up to, so they cannot be given back. Run `wp seocart doctor` for the figures.', 'seocart' ),
				array( 'variant_id' ),
				internal: true
			),
		);
	}
}

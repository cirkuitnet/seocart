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
 * The errors holding, adjusting, routing, transferring or deleting stock, and changing a location, can end in.
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
	 * The variant cannot be deleted while an order's allocation of it is open, or units of it are on a transfer not yet received.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Transfers.
	 */
	case DeleteBlocked = 'stock.delete_blocked';

	/**
	 * The item's `held` is lower than its hold rows add up to, so its units cannot be given back.
	 *
	 * @since 0.1.0
	 */
	case ProjectionCorrupt = 'stock.projection_corrupt';

	/**
	 * The location named does not exist.
	 *
	 * @since 0.2.0
	 */
	case LocationMissing = 'stock.location_missing';

	/**
	 * The location named is deactivated, and cannot serve what was asked of it.
	 *
	 * @since 0.2.0
	 */
	case LocationInactive = 'stock.location_inactive';

	/**
	 * No active location fulfils online orders, so nothing can be routed.
	 *
	 * @since 0.2.0
	 */
	case NoFulfillingLocation = 'stock.no_fulfilling_location';

	/**
	 * The order cannot be routed under the store's choice for orders no one location can ship whole.
	 *
	 * @since 0.2.0
	 */
	case RoutingRefused = 'stock.routing_refused';

	/**
	 * The location cannot be deleted: a stock record or an order refers to it, or it is the default.
	 *
	 * @since 0.2.0
	 */
	case LocationInUse = 'stock.location_in_use';

	/**
	 * The location is the store's default, so it cannot be deactivated or stop being the default.
	 *
	 * @since 0.2.0
	 */
	case LocationIsDefault = 'stock.location_is_default';

	/**
	 * A field of a location's record is not valid: its name, its address or its pickup hours.
	 *
	 * @since 0.2.0
	 */
	case LocationInvalid = 'stock.location_invalid';

	/**
	 * The list of variant ids a stock read was asked for is not one.
	 *
	 * @since 0.2.0
	 */
	case VariantIdsInvalid = 'stock.variant_ids_invalid';

	/**
	 * No transfer has the identifier given, or the transfer has no line of the id a receipt names.
	 *
	 * @since 0.2.0
	 */
	case TransferNotFound = 'stock.transfer_not_found';

	/**
	 * The transfer's state does not allow what was asked: it was sent, received or cancelled already, or it carries an order's units.
	 *
	 * @since 0.2.0
	 */
	case TransferState = 'stock.transfer_state';

	/**
	 * The transfer cannot be sent: its source lacks the units of a line.
	 *
	 * @since 0.2.0
	 */
	case TransferRefused = 'stock.transfer_refused';

	/**
	 * A receipt line expected other figures received and lost so far: the receipt was made already, or another came first.
	 *
	 * @since 0.2.0
	 */
	case TransferReceiptConflict = 'stock.transfer_receipt_conflict';

	/**
	 * A transfer, or a deactivation's move, names one location as both its source and its destination.
	 *
	 * @since 0.2.0
	 */
	case TransferSameLocation = 'stock.transfer_same_location';

	/**
	 * A field of a transfer is not valid in a way its declaration cannot say: a note, or the lines.
	 *
	 * @since 0.2.0
	 */
	case TransferInvalid = 'stock.transfer_invalid';

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
				array( 'variant_id', 'requested', 'available' ),
				details: array( 'location_id' )
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
					__( 'Variant %1$s cannot be deleted while an order still has units of it allocated, or units of it are on a transfer not yet received.', 'seocart' ),
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
			new ErrorDefinition(
				self::LocationMissing,
				404,
				static fn(): string =>
					/* translators: %1$s: The id of a stock location. */
					__( 'Location %1$s does not exist.', 'seocart' ),
				array( 'location_id' )
			),
			new ErrorDefinition(
				self::LocationInactive,
				409,
				static fn(): string =>
					/* translators: %1$s: The id of a stock location. */
					__( 'Location %1$s is deactivated.', 'seocart' ),
				array( 'location_id' )
			),
			new ErrorDefinition(
				self::NoFulfillingLocation,
				409,
				static fn(): string => __( 'No location ships online orders at the moment, so nothing can be sold.', 'seocart' )
			),
			new ErrorDefinition(
				self::RoutingRefused,
				409,
				static fn(): string => __( 'These items cannot be sent together in the quantities asked for.', 'seocart' ),
				details: array( 'reason', 'variant_ids', 'available' )
			),
			new ErrorDefinition(
				self::LocationInUse,
				409,
				static fn(): string =>
					/* translators: %1$s: The identifier of a stock location. */
					__( 'Location %1$s cannot be deleted while its stock history, its stock, a checkout, an order or a transfer refers to it, or while it is the default location. Deactivate it instead.', 'seocart' ),
				array( 'location_id' ),
				details: array( 'reason' )
			),
			new ErrorDefinition(
				self::LocationIsDefault,
				409,
				static fn(): string =>
					/* translators: %1$s: The identifier of a stock location. */
					__( 'Location %1$s is the default location, so it stays active and stays the default. Make another location the default first.', 'seocart' ),
				array( 'location_id' )
			),
			new ErrorDefinition(
				self::LocationInvalid,
				400,
				static fn(): string =>
					/* translators: %1$s: The name of a field of a location, such as address.country. */
					__( 'The location field %1$s is not valid. A name is not blank, a country is two capital letters such as GB, a line of text holds no line breaks, and pickup hours give each day once, opening before closing, at times written HH:MM.', 'seocart' ),
				array( 'field' )
			),
			new ErrorDefinition(
				self::VariantIdsInvalid,
				400,
				static fn(): string =>
					/* translators: %1$s: The most variant ids one request may list. */
					__( 'List from 1 to %1$s variant ids, whole numbers above 0 separated by commas, such as 12,15,20.', 'seocart' ),
				array( 'most' )
			),
			new ErrorDefinition(
				self::TransferNotFound,
				404,
				static fn(): string =>
					/* translators: %1$s: The identifier of a stock transfer. */
					__( 'Transfer %1$s does not exist, or has no line of the id given.', 'seocart' ),
				array( 'transfer_id' ),
				details: array( 'line_id' )
			),
			new ErrorDefinition(
				self::TransferState,
				409,
				static fn(): string =>
					/* translators: %1$s: The identifier of a stock transfer. %2$s: Its state, such as in_transit. */
					__( 'Transfer %1$s is %2$s, so it cannot be changed that way.', 'seocart' ),
				array( 'transfer_id', 'state' ),
				details: array( 'reason' )
			),
			new ErrorDefinition(
				self::TransferRefused,
				409,
				static fn(): string =>
					/* translators: %1$s: The id of a product variant. %2$s: Units the transfer sends. %3$s: Units the source can send. */
					__( 'The transfer cannot be sent: variant %1$s needs %2$s units at its source, which can send %3$s. Lower the line, or wait for the units.', 'seocart' ),
				array( 'variant_id', 'requested', 'available' ),
				details: array( 'location_id' )
			),
			new ErrorDefinition(
				self::TransferReceiptConflict,
				409,
				static fn(): string =>
					/* translators: %1$s: The id of a transfer line. %2$s: The units the request expected received so far. %3$s: The units it expected lost so far. %4$s: The units received so far. %5$s: The units lost so far. */
					__( 'Line %1$s: you expected %2$s received and %3$s lost so far, but it has %4$s received and %5$s lost, so the receipt was not recorded. Read the transfer again, then receive what is still on the way.', 'seocart' ),
				array( 'line_id', 'expected', 'expected_lost', 'received_quantity', 'lost_quantity' )
			),
			new ErrorDefinition(
				self::TransferSameLocation,
				400,
				static fn(): string =>
					/* translators: %1$s: The identifier of a stock location. */
					__( 'Location %1$s cannot be both where the units leave and where they arrive.', 'seocart' ),
				array( 'location_id' )
			),
			new ErrorDefinition(
				self::TransferInvalid,
				400,
				static fn(): string =>
					/* translators: %1$s: The name of a field of a transfer, such as note or lines. */
					__( 'The transfer field %1$s is not valid. A note holds no control characters; a transfer has from 1 to 500 lines; a receipt names each line once and receives or loses at least one unit of it, no more than it has on the way.', 'seocart' ),
				array( 'field' )
			),
		);
	}
}

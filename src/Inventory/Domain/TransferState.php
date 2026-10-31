<?php
/**
 * TransferState: where a transfer stands, from its draft to its receipt
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The states of a transfer, each the value `stock_transfers.state` holds.
 *
 * Owns one fact: the states and the moves between them. A draft or a requested transfer may be
 * edited, sent or cancelled; sending moves every line out of the source at once, and the transfer
 * is in transit; it is received in parts, each line's units received or written off as lost,
 * and is received once every line is. A transfer in transit is never cancelled: what left a
 * location arrives at one or is written off. Every move is one conditional update of the
 * transfer's row whose WHERE clause carries the state it leaves, so a second request for the
 * same move finds the row in another state and is refused.
 *
 * @since 0.2.0
 */
enum TransferState: string {

	/**
	 * A merchant's transfer not sent yet: it promises nothing, and may name more units than the source has.
	 *
	 * @since 0.2.0
	 */
	case Draft = 'draft';

	/**
	 * A transfer the system requested for an order, waiting for a person to send it.
	 *
	 * @since 0.2.0
	 */
	case Requested = 'requested';

	/**
	 * Sent: its units left the source, and those neither received nor lost are on the way, in no location's stock.
	 *
	 * @since 0.2.0
	 */
	case InTransit = 'in_transit';

	/**
	 * Every unit it sent was received or written off as lost.
	 *
	 * @since 0.2.0
	 */
	case Received = 'received';

	/**
	 * Cancelled before it was sent.
	 *
	 * @since 0.2.0
	 */
	case Cancelled = 'cancelled';
}

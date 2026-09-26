<?php
/**
 * CrossZonePolicy: what stays fixed when a tax-inclusive price is sold into another tax zone
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tax\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant's rule for a gross-authored amount sold where the tax rate differs from the store's own.
 *
 * Owns one fact: the two policies a store can choose between, spelled as the order snapshots
 * them. A price the merchant typed including tax was worked out at the store's reference rate;
 * sold into a zone with another rate, either the amount before tax stays the same and the price
 * the customer pays changes, or the price paid stays the same and the amount before tax changes.
 * The two agree wherever the destination's rate is the reference rate. An amount authored net
 * is not affected by either.
 *
 * @since 0.1.0
 */
enum CrossZonePolicy: string {

	/**
	 * The amount before tax is kept, so the price paid follows the destination's rate.
	 *
	 * @since 0.1.0
	 */
	case FixedNet = 'fixed_net';

	/**
	 * The price paid is kept, so the amount before tax follows the destination's rate.
	 *
	 * @since 0.1.0
	 */
	case FixedGross = 'fixed_gross';
}

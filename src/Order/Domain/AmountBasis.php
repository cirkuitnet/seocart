<?php
/**
 * AmountBasis: whether an authored amount includes tax
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The value of every `*_amount_basis` column of the order tables.
 *
 * Owns one fact: the two bases an amount can be authored in. An order records the basis of each
 * authored amount beside it, because a refund reverses an amount in the basis it was authored in.
 *
 * @since 0.1.0
 */
enum AmountBasis: string {

	/**
	 * The amount excludes tax.
	 *
	 * @since 0.1.0
	 */
	case Net = 'net';

	/**
	 * The amount includes tax.
	 *
	 * @since 0.1.0
	 */
	case Gross = 'gross';
}

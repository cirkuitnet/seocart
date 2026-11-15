<?php
/**
 * Presence: whether the checkout asks for a field, and whether it must be given
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * How the checkout treats a field: not asked for, asked for, or asked for and needed before an order is placed.
 *
 * Owns one fact: the three choices a store makes for a field. A field declares which of them it
 * allows, its default first; the store's choice among those is kept in one setting.
 *
 * Experimental until SEOCart 1.0.
 *
 * @since 0.2.0
 *
 * @api
 */
enum Presence: string {

	/**
	 * The form does not show the field, and the checkout refuses a value for it.
	 *
	 * @since 0.2.0
	 */
	case Hidden = 'hidden';

	/**
	 * The form shows the field, and an order may be placed without it.
	 *
	 * @since 0.2.0
	 */
	case Optional = 'optional';

	/**
	 * The form shows the field, and no order is placed without it.
	 *
	 * @since 0.2.0
	 */
	case Required = 'required';
}

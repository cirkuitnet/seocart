<?php
/**
 * OrderChannel: where an order came from
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The value of `orders.channel`.
 *
 * Owns one fact: the ways an order can reach the store.
 *
 * @since 0.1.0
 */
enum OrderChannel: string {

	/**
	 * Placed by a shopper at the store's checkout.
	 *
	 * @since 0.1.0
	 */
	case Storefront = 'storefront';

	/**
	 * Created by staff in the store's administration.
	 *
	 * @since 0.1.0
	 */
	case Admin = 'admin';

	/**
	 * Created through the store's API by another system.
	 *
	 * @since 0.1.0
	 */
	case Api = 'api';

	/**
	 * Brought in by an import.
	 *
	 * @since 0.1.0
	 */
	case Import = 'import';
}

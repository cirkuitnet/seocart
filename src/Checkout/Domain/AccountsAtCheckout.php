<?php
/**
 * AccountsAtCheckout: whether the store takes orders from shoppers who are not signed in
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant's answer, in the setting `checkout.accounts`, to one question: must a shopper be signed in to place an order.
 *
 * Owns one fact: the three answers. Only Required changes what the checkout accepts: a placement
 * by anyone who is not a signed-in WordPress user is refused. The others are what a storefront
 * shows: a sign-in prompt or none. An order placed by a signed-in user belongs to their account
 * under every answer.
 *
 * @since 0.2.0
 */
enum AccountsAtCheckout: string {

	/**
	 * A shopper may place an order signed in or as a guest; the storefront offers to sign in.
	 *
	 * @since 0.2.0
	 */
	case Optional = 'optional';

	/**
	 * Only a signed-in shopper may place an order; a guest's placement is refused.
	 *
	 * @since 0.2.0
	 */
	case Required = 'required';

	/**
	 * A shopper places orders as a guest; the storefront offers no sign-in. A shopper who is signed in still places orders that belong to their account.
	 *
	 * @since 0.2.0
	 */
	case GuestOnly = 'guest_only';
}

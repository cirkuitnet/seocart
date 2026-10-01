<?php
/**
 * Operation: what a gateway was asked to do with the money
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The operation a gateway result reports on: the vocabulary of `payment_transactions.operation`.
 *
 * Owns one fact: the names of the money operations. With the provider and the provider's
 * object, it is the key a result is applied once by.
 *
 * @since 0.1.0
 */
enum Operation: string {

	/**
	 * Reserve the amount on the customer's payment method.
	 *
	 * @since 0.1.0
	 */
	case Authorize = 'authorize';

	/**
	 * Take the authorized amount.
	 *
	 * @since 0.1.0
	 */
	case Capture = 'capture';

	/**
	 * Release an authorization without taking it.
	 *
	 * @since 0.1.0
	 */
	case Void = 'void';

	/**
	 * Give back captured money.
	 *
	 * @since 0.1.0
	 */
	case Refund = 'refund';
}

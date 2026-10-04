<?php
/**
 * Mode: whether a payment goes to the provider's test system or its live one
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * The provider system a payment goes to: test, where no money moves, or live.
 *
 * Owns one fact: the two modes. An intent records the mode it was created in, and every later
 * call about it uses that mode's credentials, whatever the store is set to by then.
 *
 * @since 0.2.0
 *
 * @api
 */
enum Mode: string {

	/**
	 * The provider's test system: no money moves.
	 *
	 * @since 0.2.0
	 */
	case Test = 'test';

	/**
	 * The provider's live system.
	 *
	 * @since 0.2.0
	 */
	case Live = 'live';
}

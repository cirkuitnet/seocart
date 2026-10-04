<?php
/**
 * WebhookReadingKind: what a gateway made of a webhook delivery
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * The three things a delivery can be, once a gateway has read it.
 *
 * Owns one fact: the kinds of reading. A rejected delivery is not trusted and nothing of it is
 * kept; an ignored one is genuine but nothing the plugin acts on; a result is a payment's answer
 * for the plugin to apply.
 *
 * @since 0.2.0
 *
 * @api
 */
enum WebhookReadingKind: string {

	/**
	 * The delivery failed verification: a bad signature, a timestamp outside the window, or a malformed body.
	 *
	 * @since 0.2.0
	 */
	case Rejected = 'rejected';

	/**
	 * The delivery is genuine, and reports nothing the plugin acts on, such as a dispute.
	 *
	 * @since 0.2.0
	 */
	case Ignored = 'ignored';

	/**
	 * The delivery is genuine, and reports a payment's result.
	 *
	 * @since 0.2.0
	 */
	case Result = 'result';
}

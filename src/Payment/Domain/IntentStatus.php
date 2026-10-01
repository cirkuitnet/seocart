<?php
/**
 * IntentStatus: the states of a payment intent
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Where one attempt to pay for an order stands: the vocabulary of `payment_intents.status`.
 *
 * Owns one fact: the names of the intent states. Which state may follow which is
 * IntentTransitions's; the database changes a state only through a conditional update whose
 * WHERE clause lists the states that table allows.
 *
 * @since 0.1.0
 */
enum IntentStatus: string {

	/**
	 * Created with its order; the gateway has not answered yet.
	 *
	 * @since 0.1.0
	 */
	case Created = 'created';

	/**
	 * The gateway asked the customer to act, for example to confirm with their bank.
	 *
	 * @since 0.1.0
	 */
	case RequiresAction = 'requires_action';

	/**
	 * The gateway is still deciding.
	 *
	 * @since 0.1.0
	 */
	case Processing = 'processing';

	/**
	 * The amount is authorized and not yet captured.
	 *
	 * @since 0.1.0
	 */
	case Authorized = 'authorized';

	/**
	 * The amount is captured.
	 *
	 * @since 0.1.0
	 */
	case Captured = 'captured';

	/**
	 * Part of what was captured is refunded.
	 *
	 * @since 0.1.0
	 */
	case PartiallyRefunded = 'partially_refunded';

	/**
	 * Everything captured is refunded.
	 *
	 * @since 0.1.0
	 */
	case Refunded = 'refunded';

	/**
	 * Cancelled before any money was captured.
	 *
	 * @since 0.1.0
	 */
	case Voided = 'voided';

	/**
	 * The gateway declined, or the attempt ended without money.
	 *
	 * @since 0.1.0
	 */
	case Failed = 'failed';

	/**
	 * Returns the states of an intent still waiting for the gateway's answer to its authorization.
	 *
	 * An intent in one of them may still be authorized; reconciliation asks the gateway about
	 * those that have waited too long.
	 *
	 * @since 0.1.0
	 *
	 * @return list<self> created, requires_action and processing.
	 */
	public static function awaitingResult(): array {
		return array( self::Created, self::RequiresAction, self::Processing );
	}
}

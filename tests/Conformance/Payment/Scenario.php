<?php
/**
 * Scenario: the payments a gateway's test names a payment token for
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * The kinds of payment the suite places, each made by a payment token the gateway's test names (GatewayConformanceCase::tokens()).
 *
 * Owns one fact: the scenarios a provider must be able to produce in test mode, or say it cannot.
 *
 * @since 0.2.0
 */
enum Scenario: string {

	/**
	 * The provider authorizes the payment.
	 *
	 * @since 0.2.0
	 */
	case Approve = 'approve';

	/**
	 * The provider declines the payment.
	 *
	 * @since 0.2.0
	 */
	case Decline = 'decline';

	/**
	 * The provider asks the customer to act, for example with their bank.
	 *
	 * @since 0.2.0
	 */
	case RequiresAction = 'requires_action';

	/**
	 * The provider asks the customer to act, and the customer does, before the store's wait for them ends.
	 *
	 * @since 0.2.0
	 */
	case RequiresActionCompleted = 'requires_action_completed';

	/**
	 * The payment waits, and the provider cancels it once the wait has ended.
	 *
	 * @since 0.2.0
	 */
	case Expires = 'expires';

	/**
	 * The provider takes the payment and decides later, as for a payment method that settles in the background.
	 *
	 * @since 0.2.0
	 */
	case Pending = 'pending';
}

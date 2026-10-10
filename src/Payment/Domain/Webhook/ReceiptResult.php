<?php
/**
 * ReceiptResult: what was decided about a provider's event a webhook delivered
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * The decisions a webhook receipt is settled with: the one list `webhook_receipts.result` holds.
 *
 * Owns one fact: what a delivered event can come to. Each is a decision, answered 200 and never
 * processed again. A receipt not settled yet holds none, and the event's next delivery is
 * processed again.
 *
 * @since 0.2.0
 */
enum ReceiptResult: string {

	/**
	 * The event's result was applied: money moved, or the payment's state did, as it reported.
	 *
	 * @since 0.2.0
	 */
	case Applied = 'applied';

	/**
	 * The ledger, or the payment's state, had the event's result already: nothing changed.
	 *
	 * @since 0.2.0
	 */
	case Duplicate = 'duplicate';

	/**
	 * The event reported a state the payment has left: nothing changed, and no ledger row was written.
	 *
	 * @since 0.2.0
	 */
	case Stale = 'stale';

	/**
	 * The provider moved money the ledger did not expect: its row was kept applied to nothing, and the order flagged for a person.
	 *
	 * @since 0.2.0
	 */
	case Unapplied = 'unapplied';

	/**
	 * The event is genuine and reports nothing the store acts on, such as a dispute, or a payment that is not the store's.
	 *
	 * @since 0.2.0
	 */
	case Ignored = 'ignored';
}

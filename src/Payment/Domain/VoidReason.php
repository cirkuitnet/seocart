<?php
/**
 * VoidReason: why an authorized payment is cancelled before any of it is captured
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The reasons a payment is voided for: the one list, which the void operation offers and a voided intent records.
 *
 * Owns one fact: what a payment may be voided for, and whether a person asked. A merchant gives
 * one of merchant(); the reconciliation job gives `action_window_ended` when a shopper asked to
 * act never did; a webhook gives `voided_externally` when the provider reports a void nobody asked
 * the store for. A void a person asked for leaves an accepted order to that person; one nobody
 * asked for parks it for a person to look at.
 *
 * @since 0.2.0
 */
enum VoidReason: string {

	/**
	 * The customer asked to cancel the order.
	 *
	 * @since 0.2.0
	 */
	case CustomerRequest = 'customer_request';

	/**
	 * The customer placed the same order twice.
	 *
	 * @since 0.2.0
	 */
	case DuplicateOrder = 'duplicate_order';

	/**
	 * The payment looks fraudulent.
	 *
	 * @since 0.2.0
	 */
	case Fraud = 'fraud';

	/**
	 * The store cannot ship what was ordered.
	 *
	 * @since 0.2.0
	 */
	case OutOfStock = 'out_of_stock';

	/**
	 * Any other reason a person gives.
	 *
	 * @since 0.2.0
	 */
	case Other = 'other';

	/**
	 * The shopper was asked to act, for example to confirm with their bank, and the time to do so ran out.
	 *
	 * @since 0.2.0
	 */
	case ActionWindowEnded = 'action_window_ended';

	/**
	 * The provider reports the payment voided, and nobody asked the store for it: someone cancelled it in the provider's dashboard.
	 *
	 * @since 0.2.0
	 */
	case VoidedExternally = 'voided_externally';

	/**
	 * Returns the reasons a merchant may give: every one but the end of a shopper's time to act, which only the store gives, and a void the provider reports on its own.
	 *
	 * @since 0.2.0
	 *
	 * @return list<self> The reasons, in declaration order.
	 */
	public static function merchant(): array {
		return array_values( array_filter( self::cases(), static fn( self $reason ): bool => $reason->askedByAPerson() ) );
	}

	/**
	 * Tells whether a person asked the store for the void, rather than the store on its own or the provider.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False for the end of a shopper's time to act and for a void the provider reports on its own.
	 */
	public function askedByAPerson(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return ! in_array( $this, array( self::ActionWindowEnded, self::VoidedExternally ), true );
	}
}

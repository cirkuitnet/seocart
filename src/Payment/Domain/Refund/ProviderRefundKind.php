<?php
/**
 * ProviderRefundKind: what a refund result the provider delivered on its own came to
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

defined( 'ABSPATH' ) || exit;

/**
 * The ways a provider's own word of a refund is settled: through the refund's open claim, against a claim that has ended, or with no claim of the store's at all.
 *
 * Owns one fact: the vocabulary RefundService::recordProviderRefund() answers in, which the
 * webhook's receipt writes down. The first three end an open claim as its own answer would;
 * the rest leave every claim as it stands.
 *
 * @since 0.2.0
 */
enum ProviderRefundKind: string {

	/**
	 * The refund's open claim is recorded from the result: the document, its lines and components, and the claim `recorded`.
	 *
	 * @since 0.2.0
	 */
	case Recorded = 'recorded';

	/**
	 * The refund's open claim is declined from the result, with its ledger row.
	 *
	 * @since 0.2.0
	 */
	case Declined = 'declined';

	/**
	 * The result could not be recorded as the refund its open claim asked for, such as one of another amount: the money is kept for a person, and the claim ends `unreconciled`.
	 *
	 * @since 0.2.0
	 */
	case Unreconciled = 'unreconciled';

	/**
	 * The ledger already holds the result, as the refund's own answer or an earlier delivery wrote it: nothing changes.
	 *
	 * @since 0.2.0
	 */
	case Duplicate = 'duplicate';

	/**
	 * Money the provider gave back that no open claim of the store's asked for, or that the claim's own answer did not account for: kept for a person, the order flagged and parked.
	 *
	 * @since 0.2.0
	 */
	case Unexpected = 'unexpected';

	/**
	 * The provider declines a refund the ledger holds as made, taking it back: kept for a person, the order flagged and parked, as the money the store counts as given back was not.
	 *
	 * @since 0.2.0
	 */
	case Reversed = 'reversed';

	/**
	 * A result that moves no money with no open claim to end, such as a decline of a refund nobody asked for here, or a refund the provider still has pending: nothing changes.
	 *
	 * @since 0.2.0
	 */
	case Ignored = 'ignored';
}

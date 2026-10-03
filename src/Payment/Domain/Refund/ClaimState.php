<?php
/**
 * ClaimState: where a refund's claim stands
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

defined( 'ABSPATH' ) || exit;

/**
 * The states of a refund's claim: the vocabulary of `refund_claims.state`, and the one transition table.
 *
 * Owns one fact: how a claim may end. A claim is made `claimed` before the gateway is asked, and
 * leaves that state once, when the gateway's answer is recorded: `recorded` with the refund's
 * document, `declined` with the decline, or `unreconciled` when what the gateway answered could
 * not be recorded as the refund and is left for a person. A claim the gateway cannot account for
 * stays `claimed` and waits for a person. While a claim is `claimed`, no other refund of its intent
 * is claimed or asked of the gateway. An ended claim never changes again: a refund asked again
 * after a decline is a new refund, with a new claim.
 *
 * @since 0.1.0
 */
enum ClaimState: string {

	/**
	 * The gateway is being asked, or was asked and its answer is not recorded yet: also a claim the gateway cannot account for, which waits for a person.
	 *
	 * @since 0.1.0
	 */
	case Claimed = 'claimed';

	/**
	 * The gateway gave the money back, and the refund's document records it.
	 *
	 * @since 0.1.0
	 */
	case Recorded = 'recorded';

	/**
	 * The gateway declined the refund; no money moved.
	 *
	 * @since 0.1.0
	 */
	case Declined = 'declined';

	/**
	 * What the gateway answered could not be recorded as this refund, and a person must reconcile it.
	 *
	 * Either the gateway gave money back that could not be recorded as this refund, which the ledger
	 * keeps unapplied and the claim names; or the gateway answered with another refund's result,
	 * an approval or a decline, so what it did with this one is not known, and the claim names no
	 * ledger row, for no row is this refund's. Doctor reports a claim of the second kind.
	 *
	 * @since 0.1.0
	 */
	case Unreconciled = 'unreconciled';

	/**
	 * Returns the states a claim may move to from a state.
	 *
	 * @since 0.1.0
	 *
	 * @param ClaimState $from Where the claim stands.
	 * @return list<ClaimState> The states: the three endings from `claimed`, none from an ending.
	 */
	public static function allowedFrom( ClaimState $from ): array {
		return self::Claimed === $from ? array( self::Recorded, self::Declined, self::Unreconciled ) : array();
	}
}

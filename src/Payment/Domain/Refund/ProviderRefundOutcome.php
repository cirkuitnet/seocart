<?php
/**
 * ProviderRefundOutcome: how a refund result the provider delivered on its own was settled, and with which claim and ledger row
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

defined( 'ABSPATH' ) || exit;

/**
 * What RefundService::recordProviderRefund() did with a provider's refund result: the kind, the claim it answered, the ledger row, and why.
 *
 * Owns one fact: what the webhook's receipt needs to say about a refund without reading again.
 * The reason is the word money kept for a person was kept under, or why the result was ignored.
 *
 * @since 0.2.0
 */
final readonly class ProviderRefundOutcome {

	/**
	 * Why a result is ignored: no claim of the intent names the refund the provider echoed, or it echoed none.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NO_CLAIM = 'no_claim';

	/**
	 * Why a result is ignored: the claim it names has ended, and the result moves no money.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLAIM_ENDED = 'claim_ended';

	/**
	 * Records the outcome.
	 *
	 * @since 0.2.0
	 *
	 * @param ProviderRefundKind $kind          What the result came to.
	 * @param string|null        $claimUuid     The claim it answered or met; null when no claim of the intent names it.
	 * @param int|null           $transactionId The ledger row the result wrote or met; null for none.
	 * @param string|null        $reason        Why money was kept for a person, or why the result was ignored; null otherwise.
	 */
	public function __construct(
		public ProviderRefundKind $kind,
		public ?string $claimUuid,
		public ?int $transactionId = null,
		public ?string $reason = null
	) {
	}
}

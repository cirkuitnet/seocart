<?php
/**
 * ClaimRequest: a refund claim with what it asked, from which the refund is worked out again
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A refund's claim as it was made: where it stands, the intent and the order, and what was asked of them.
 *
 * Owns one fact: what a refund claimed by an earlier request asked, read back from its claim, so
 * the refund can be worked out again after that request is gone: to settle it with a person's
 * say-so, or to record a provider's answer that arrives on its own. The note is not read back:
 * nothing a refund's uuid or money is worked out from depends on it. Read without a lock;
 * whatever it leads to is decided again under the intent's lock.
 *
 * @since 0.2.0
 */
final readonly class ClaimRequest {

	/**
	 * Records the claim.
	 *
	 * @since 0.2.0
	 *
	 * @param string              $uuid          The refund's uuid.
	 * @param ClaimState          $state         Where the claim stands.
	 * @param int|null            $transactionId The ledger row that ended it; null while it is claimed, or when no row is its own.
	 * @param string              $intentUuid    The public identifier of the intent the refund was asked of.
	 * @param int                 $orderId       The order refunded.
	 * @param bool                $shipping      Whether the refund asked for what was left of the shipping.
	 * @param string              $reasonCode    Why the refund was asked for.
	 * @param Money               $baseShare     What the refund asked for, in the base currency, at the order's frozen rate.
	 * @param RefundLineRequest[] $lines         What it asked of each line, in the order of the lines' identifiers; none for the shipping alone.
	 *
	 * @phpstan-param list<RefundLineRequest> $lines
	 */
	public function __construct(
		public string $uuid,
		public ClaimState $state,
		public ?int $transactionId,
		public string $intentUuid,
		public int $orderId,
		public bool $shipping,
		public string $reasonCode,
		public Money $baseShare,
		public array $lines
	) {
	}
}

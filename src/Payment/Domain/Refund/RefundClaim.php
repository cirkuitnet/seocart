<?php
/**
 * RefundClaim: a refund's claim, as a second request for the same refund finds it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

defined( 'ABSPATH' ) || exit;

/**
 * The claim an earlier request made for a refund: where it stands, and the ledger row that ended it.
 *
 * Owns one fact: what a request that found its refund already claimed decides from. Read without a
 * lock; whatever it leads to is decided again by a conditional statement.
 *
 * @since 0.1.0
 */
final readonly class RefundClaim {

	/**
	 * Records the claim.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $uuid          The refund's uuid.
	 * @param ClaimState $state         Where it stands.
	 * @param int|null   $transactionId The ledger row that ended it; null while it is claimed, or when no row is its own.
	 */
	public function __construct(
		public string $uuid,
		public ClaimState $state,
		public ?int $transactionId
	) {
	}
}

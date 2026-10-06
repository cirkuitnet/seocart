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
 * The claim an earlier request made for a refund: where it stands, the ledger row that ended it, and the fingerprint of the request its key was sent with.
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
	 * @since 0.2.0 The request's fingerprint.
	 *
	 * @param string      $uuid               The refund's uuid.
	 * @param ClaimState  $state              Where it stands.
	 * @param int|null    $transactionId      The ledger row that ended it; null while it is claimed, or when no row is its own.
	 * @param string|null $requestFingerprint Optional. The fingerprint of the request the claim's idempotency key was sent with,
	 *                                        as read with the key; null when the claim has no key, or it was not read. Default null.
	 */
	public function __construct(
		public string $uuid,
		public ClaimState $state,
		public ?int $transactionId,
		public ?string $requestFingerprint = null
	) {
	}
}

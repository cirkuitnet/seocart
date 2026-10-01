<?php
/**
 * IdempotencyKeys: the port to the idempotency keys an order placement claims
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Claims an idempotency key for a request, and records what the request that owns it placed.
 *
 * Both run only inside the placement's own transaction, so a claim and its completion commit
 * together with the order, or roll back with everything else: a key is never left claimed by a
 * request that failed. A second request with the same key waits for the first one's transaction
 * on the key's unique index, then answers as the first one did, or owns the key if the first one
 * rolled back.
 *
 * @since 0.1.0
 */
interface IdempotencyKeys {

	/**
	 * Claims a key for a request.
	 *
	 * The answers: a new key, or one whose expiry has passed, is this request's (owned); a key an
	 * earlier request placed its order with, for a request of the same fingerprint, answers with
	 * that request's answer (a replay). Every other key is refused.
	 *
	 * The caller's unit of work must be retried on a deadlock (RetryPolicy::deadlocks()): at READ
	 * COMMITTED, two requests that claim one expired key at once deadlock on its row, and the
	 * retried one finds the key claimed by the other.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 * @throws CodedException  `checkout.idempotency_key_reused` when an order was placed with the
	 *                         key from a request of another fingerprint;
	 *                         `checkout.placement_in_progress` when another request holds the key
	 *                         and has not placed its order.
	 *
	 * @param string $scope       What the key is claimed for, such as the order placement's operation id.
	 * @param string $keyHash     The key's hash: IdempotencyClaim::keyHash().
	 * @param string $fingerprint The SHA-256 of the request's canonical form, in lower-case hexadecimal.
	 * @param int    $ttlSeconds  How long the key lives from now.
	 * @return IdempotencyClaim The claim: owned, or a replay of the earlier answer.
	 */
	public function claim( string $scope, string $keyHash, string $fingerprint, int $ttlSeconds ): IdempotencyClaim;

	/**
	 * Records that the request that owns a key placed its order, and the answer it sends: claimed becomes placed.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, or when the key is not claimed: the request
	 *                         did not claim it in this transaction.
	 *
	 * @param int    $id           The key's row, from the claim.
	 * @param int    $orderId      The order placed.
	 * @param string $responseJson The answer, exactly as it is sent; a retry gets it byte for byte.
	 */
	public function complete( int $id, int $orderId, string $responseJson ): void;
}

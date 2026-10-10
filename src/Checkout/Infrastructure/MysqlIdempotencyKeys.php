<?php
/**
 * MysqlIdempotencyKeys: every idempotency key statement, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Infrastructure;

use SEOCart\Checkout\Application\IdempotencyKeys;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML. Coded errors go through CodedException::raise().

/**
 * The idempotency keys on MySQL: the one class that sends SQL to `idempotency_keys`.
 *
 * Owns one fact: how a key is claimed, completed and swept. Each statement is a public constant,
 * so a concurrency test sends exactly the statement this class sends.
 *
 * A claim is a plain INSERT. The unique key on (scope, key_hash) decides it: a second INSERT of
 * the same key waits for the first one's transaction, and then either goes in, when the first
 * rolled back, or fails as a duplicate, when it committed. A duplicate is then read once, by the
 * unique key, with a shared lock, which the duplicate check already holds on the row: the read
 * sees the row as last committed, whatever the transaction read before, and the row cannot change
 * or go until this transaction ends. The read only classifies; the one decision it leads to, the
 * re-claim of an expired key, is a conditional UPDATE that carries the expiry in its WHERE clause.
 * Expiry is always judged by the database clock.
 *
 * Doctor's search for stranded claims and the retention job's deletes live here too, because
 * they are key SQL; they are not part of the port placement sees.
 *
 * @since 0.1.0
 */
final class MysqlIdempotencyKeys implements IdempotencyKeys {

	/**
	 * Claims a new key, for a TTL from now.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM = "INSERT INTO %i ( scope, key_hash, request_fingerprint, state, expires_at, created_at ) VALUES ( %s, %s, %s, 'claimed', UTC_TIMESTAMP() + INTERVAL %d SECOND, UTC_TIMESTAMP(6) )";

	/**
	 * The key a claim found taken: what it holds, and whether it has expired. A locking read, by the unique key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TAKEN = 'SELECT id, request_fingerprint, state, order_id, response_json, expires_at <= UTC_TIMESTAMP() AS expired FROM %i WHERE scope = %s AND key_hash = %s FOR SHARE';

	/**
	 * A key as last committed, before any transaction: what it holds, and whether it has expired. A plain read, by the unique key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND = 'SELECT id, request_fingerprint, state, order_id, response_json, expires_at <= UTC_TIMESTAMP() AS expired FROM %i WHERE scope = %s AND key_hash = %s';

	/**
	 * Claims an expired key again, for a new request and a TTL from now: only while it is still expired.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RECLAIM_EXPIRED = "UPDATE %i SET request_fingerprint = %s, state = 'claimed', order_id = NULL, response_json = NULL, expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND, created_at = UTC_TIMESTAMP(6) WHERE id = %d AND expires_at <= UTC_TIMESTAMP()";

	/**
	 * Records the order placed with a claimed key, and the answer sent: claimed becomes placed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMPLETE = "UPDATE %i SET state = 'placed', order_id = %d, response_json = %s WHERE id = %d AND state = 'claimed'";

	/**
	 * Writes a settlement's outcome and statuses into the answer the key of its order keeps, found by the `order_id` key; an order's status left unchanged when none is given.
	 *
	 * The sealed next action follows the outcome: null unless the shopper must act; then the one
	 * given, or the one kept when none is given. Every branch is typed JSON, so a box is kept as an
	 * object, as the sealed order key is.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Writes the sealed next action.
	 *
	 * @var string
	 */
	public const SETTLE_ANSWER = "UPDATE %i SET response_json = JSON_SET( response_json, '$.outcome', %s, '$.status', COALESCE( NULLIF( %s, '' ), JSON_UNQUOTE( JSON_EXTRACT( response_json, '$.status' ) ) ), '$.payment_status', %s, "
		. "'$.next_action_sealed', CASE WHEN %s <> %s THEN NULL WHEN %s <> '' THEN CAST( NULLIF( %s, '' ) AS JSON ) ELSE JSON_EXTRACT( response_json, '$.next_action_sealed' ) END ) "
		. "WHERE order_id = %d AND state = 'placed'";

	/**
	 * A page of keys still claimed long after they were claimed, oldest first: doctor's search.
	 *
	 * It reads the whole table: no index serves the state and the age, because one would be
	 * written by every claim and completion for doctor alone, and the table holds only the keys of
	 * the retention period.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STRANDED = "SELECT id, scope, TIMESTAMPDIFF( SECOND, created_at, UTC_TIMESTAMP() ) AS age_seconds FROM %i WHERE state = 'claimed' AND created_at <= UTC_TIMESTAMP() - INTERVAL %d SECOND ORDER BY created_at, id LIMIT %d";

	/**
	 * Deletes the keys listed in `{ids}` that are still claimed and still that old: doctor's repair.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DELETE_STRANDED = "DELETE FROM %i WHERE id IN ({ids}) AND state = 'claimed' AND created_at <= UTC_TIMESTAMP() - INTERVAL %d SECOND";

	/**
	 * Deletes a page of expired keys, oldest expiry first: the retention job's statement.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DELETE_EXPIRED = 'DELETE FROM %i WHERE expires_at <= UTC_TIMESTAMP() ORDER BY expires_at LIMIT %d';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Returns DELETE_STRANDED for a number of keys.
	 *
	 * @since 0.1.0
	 *
	 * @param int $ids How many keys, 1 or more.
	 * @return string The statement, with one `%d` per key.
	 */
	public static function forIds( int $ids ): string {
		return str_replace( '{ids}', implode( ', ', array_fill( 0, max( 1, $ids ), '%d' ) ), self::DELETE_STRANDED );
	}

	/**
	 * Claims a key for a request. Runs only inside the caller's transaction, which must be retried on a deadlock.
	 *
	 * When two requests claim one expired key at once, the second's INSERT waits for the first's
	 * transaction at REPEATABLE READ, and then finds the key live. At READ COMMITTED, the level the
	 * order placement runs at, it does not wait: both read the key expired under a shared lock, and
	 * their re-claims deadlock. InnoDB rolls one back with an error the transaction manager
	 * classifies as retryable (pass RetryPolicy::deadlocks()); the retried unit of work then finds
	 * the key live and claimed by the other request, and is answered
	 * `checkout.placement_in_progress`. Either way, one request owns the key.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException|\InvalidArgumentException Outside a transaction; or when the key hash or
	 *                                                   the fingerprint is not a SHA-256 in lower-case
	 *                                                   hexadecimal, or the TTL is below one second.
	 * @throws CodedException                            `checkout.idempotency_key_reused` or
	 *                                                   `checkout.placement_in_progress`.
	 *
	 * @param string $scope       What the key is claimed for.
	 * @param string $keyHash     The key's hash.
	 * @param string $fingerprint The SHA-256 of the request's canonical form.
	 * @param int    $ttlSeconds  How long the key lives from now.
	 * @return IdempotencyClaim The claim: owned, or a replay of the earlier answer.
	 */
	public function claim( string $scope, string $keyHash, string $fingerprint, int $ttlSeconds ): IdempotencyClaim {
		$this->requireTransaction( __FUNCTION__ );
		self::requireClaimable( $keyHash, $fingerprint, $ttlSeconds );

		try {
			$this->db->execute( self::CLAIM, $this->keys(), $scope, $keyHash, $fingerprint, $ttlSeconds );

			return IdempotencyClaim::owned( $this->db->lastInsertId() );
		} catch ( DuplicateKey $taken ) {
			return $this->answerTaken( $scope, $keyHash, $fingerprint, $ttlSeconds );
		}
	}

	/**
	 * Answers a request with the answer of the earlier request that placed its order with the same key, when there was one. One plain read.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.idempotency_key_reused` or `checkout.placement_in_progress`.
	 *
	 * @param string $scope       What the key is claimed for.
	 * @param string $keyHash     The key's hash.
	 * @param string $fingerprint The SHA-256 of the request's canonical form.
	 * @return IdempotencyClaim|null A replay of the earlier answer; null when the key is new or expired.
	 */
	public function replay( string $scope, string $keyHash, string $fingerprint ): ?IdempotencyClaim {
		$row = $this->db->fetchRow( self::FIND, $this->keys(), $scope, $keyHash );

		if ( null === $row || '1' === (string) $row['expired'] ) {
			return null;
		}

		return self::answerLive( $row, $fingerprint );
	}

	/**
	 * Records the order placed with a claimed key, and its answer. Runs only inside the caller's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, or when the key is not claimed.
	 *
	 * @param int    $id           The key's row.
	 * @param int    $orderId      The order placed.
	 * @param string $responseJson The answer sent.
	 */
	public function complete( int $id, int $orderId, string $responseJson ): void {
		$this->requireTransaction( __FUNCTION__ );

		if ( 1 !== $this->db->execute( self::COMPLETE, $this->keys(), $orderId, $responseJson, $id ) ) {
			throw new \LogicException( sprintf( 'Idempotency key %d is not claimed, so order %d cannot be recorded with it: claim the key in the same transaction first.', $id, $orderId ) );
		}
	}

	/**
	 * Writes what a placement's settlement came to into the answer its key keeps. Runs only inside the caller's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int              $orderId       The order placed with the key.
	 * @param PlacementOutcome $outcome       What the settlement came to.
	 * @param OrderStatus|null $orderStatus   The order's status after it, or null when it did not change.
	 * @param PaymentStatus    $paymentStatus The order's payment status after it.
	 * @param string|null      $keptAction    Optional. The next action, sealed (KeptAnswer::sealAction()), for an outcome
	 *                                        that asks the shopper to act. Default null: the one kept.
	 */
	public function settleAnswer( int $orderId, PlacementOutcome $outcome, ?OrderStatus $orderStatus, PaymentStatus $paymentStatus, ?string $keptAction = null ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->db->execute( self::SETTLE_ANSWER, $this->keys(), $outcome->value, $orderStatus->value ?? '', $paymentStatus->value, $outcome->value, PlacementOutcome::RequiresAction->value, $keptAction ?? '', $keptAction ?? '', $orderId );
	}

	/**
	 * Lists keys still claimed longer after they were claimed than a placement can take, which only a lost connection leaves behind.
	 *
	 * @since 0.1.0
	 *
	 * @param int $olderThanSeconds How long ago a key must have been claimed.
	 * @param int $limit            The most keys to list.
	 * @return list<array{id: int, scope: string, age_seconds: int}> The keys, oldest first.
	 */
	public function stranded( int $olderThanSeconds, int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'          => (int) $row['id'],
				'scope'       => (string) $row['scope'],
				'age_seconds' => (int) $row['age_seconds'],
			),
			$this->db->fetchAll( self::STRANDED, $this->keys(), $olderThanSeconds, $limit )
		);
	}

	/**
	 * Deletes keys that are still claimed and still that old, in one statement.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $ids              The keys stranded() found.
	 * @param int   $olderThanSeconds How long ago a key must still have been claimed.
	 * @return int How many were deleted: a key completed or claimed again since is kept.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public function deleteStranded( array $ids, int $olderThanSeconds ): int {
		if ( array() === $ids ) {
			return 0;
		}

		return $this->db->execute( self::forIds( count( $ids ) ), $this->keys(), ...array_merge( $ids, array( $olderThanSeconds ) ) );
	}

	/**
	 * Deletes one page of expired keys.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most keys to delete.
	 * @return int How many were deleted: fewer than $limit when there were no more.
	 */
	public function deleteExpired( int $limit ): int {
		return $this->db->execute( self::DELETE_EXPIRED, $this->keys(), $limit );
	}

	/**
	 * Answers a claim whose key another request holds, from one locking read of the key.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.idempotency_key_reused` or `checkout.placement_in_progress`.
	 *
	 * @param string $scope       What the key is claimed for.
	 * @param string $keyHash     The key's hash.
	 * @param string $fingerprint The request's fingerprint.
	 * @param int    $ttlSeconds  How long the key lives from now, if it is claimed again.
	 * @return IdempotencyClaim A re-claim of an expired key, or a replay of the earlier answer.
	 */
	private function answerTaken( string $scope, string $keyHash, string $fingerprint, int $ttlSeconds ): IdempotencyClaim {
		$row = $this->db->fetchRow( self::TAKEN, $this->keys(), $scope, $keyHash );

		// The duplicate check holds a shared lock on the row, so it cannot go before this read; if
		// it is gone, the client is told to retry rather than told something this request cannot know.
		if ( null === $row ) {
			CodedException::raise( CheckoutError::PlacementInProgress );
		}

		$id = (int) $row['id'];

		if ( '1' === (string) $row['expired'] ) {
			if ( 1 === $this->db->execute( self::RECLAIM_EXPIRED, $this->keys(), $fingerprint, $ttlSeconds, $id ) ) {
				return IdempotencyClaim::owned( $id );
			}

			CodedException::raise( CheckoutError::PlacementInProgress );
		}

		return self::answerLive( $row, $fingerprint );
	}

	/**
	 * Answers a request whose key another request holds and has not let expire: a replay of its answer, or a refusal.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.placement_in_progress` while the other request has not placed
	 *                        its order; `checkout.idempotency_key_reused` when it placed it for a
	 *                        request of another fingerprint.
	 *
	 * @param array<string, mixed> $row         The key's row.
	 * @param string               $fingerprint The request's fingerprint.
	 * @return IdempotencyClaim The replay.
	 */
	private static function answerLive( array $row, string $fingerprint ): IdempotencyClaim {
		if ( 'placed' !== $row['state'] ) {
			CodedException::raise( CheckoutError::PlacementInProgress );
		}

		if ( ! hash_equals( (string) $row['request_fingerprint'], $fingerprint ) ) {
			CodedException::raise( CheckoutError::IdempotencyKeyReused );
		}

		return IdempotencyClaim::replay( (int) $row['id'], (int) $row['order_id'], (string) $row['response_json'] );
	}

	/**
	 * Refuses a claim's arguments unless the key hash and the fingerprint are SHA-256 digests in lower-case hexadecimal and the key lives a second or more.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When they are not.
	 *
	 * @param string $keyHash     The key's hash.
	 * @param string $fingerprint The request's fingerprint.
	 * @param int    $ttlSeconds  How long the key lives.
	 */
	private static function requireClaimable( string $keyHash, string $fingerprint, int $ttlSeconds ): void {
		$digests = array(
			'key hash'            => $keyHash,
			'request fingerprint' => $fingerprint,
		);

		foreach ( $digests as $what => $value ) {
			if ( 1 !== preg_match( '/^[0-9a-f]{64}\z/', $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'An idempotency %s is a SHA-256 in lower-case hexadecimal: 64 characters.', $what ) );
			}
		}

		if ( $ttlSeconds < 1 ) {
			throw new \InvalidArgumentException( 'An idempotency key lives at least one second.' );
		}
	}

	/**
	 * Refuses to claim or complete a key outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method The method called.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->db->depth() ) {
			throw new \LogicException( sprintf( 'MysqlIdempotencyKeys::%s() runs only inside the placement\'s transaction: a key is claimed and completed together with the order, or not at all.', $method ) );
		}
	}

	/**
	 * Returns the key table's full name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name, with the site's prefix.
	 */
	private function keys(): string {
		return $this->db->table( CheckoutTables::IDEMPOTENCY_KEYS );
	}
}

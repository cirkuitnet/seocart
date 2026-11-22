<?php
/**
 * Tests the claim of an idempotency key: its answers, the re-claim of an expired key, the completion, and the read under two hashes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\MysqlIdempotencyKeys;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;

/**
 * The answers of a claim, each from the row the claim found, and the transaction both steps need; and the read of a key kept under either of two hashes.
 *
 * Every claim and completion runs over wpdb in a transaction that commits, so the row each test
 * reads back through connection B is what a later request would find. Expiry is moved and judged
 * by the database clock only.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class IdempotencyKeysTest extends CheckoutTestCase {

	/**
	 * The answer the first request sent, with an access key and text outside ASCII, which a retry must get back byte for byte.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RESPONSE = '{"status":201,"body":{"order_uuid":"00000000-0000-4000-8000-000000000001","access_key":"k3y-été","order_number":"SC-1001"}}';

	/**
	 * The order each of two hashes' rows was placed with, by which a replay tells them apart.
	 *
	 * @since 0.2.0
	 *
	 * @var array{first: int, second: int}
	 */
	private const ORDERS = array(
		'first'  => 41,
		'second' => 42,
	);

	/**
	 * What a key keeps when its answer is someone else's, which the decider of the two-hash read refuses.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SOMEONE_ELSE = 'someone else';

	/**
	 * Tests that a new key is the claiming request's, stored claimed, in one statement.
	 *
	 * @since 0.1.0
	 */
	public function test_a_new_key_is_owned_and_stored_claimed_in_one_statement(): void {
		$hash  = self::keyHash( 'attempt-1' );
		$claim = null;
		$log   = $this->captureQueries(
			function () use ( $hash, &$claim ): void {
				$claim = $this->claimCommitted( $hash, self::digest( 'request 1' ) );
			}
		);

		$this->assertInstanceOf( IdempotencyClaim::class, $claim );
		$this->assertTrue( $claim->owned );
		$this->assertNull( $claim->responseJson );
		$this->assertQueryCount( 1, $log->forTable( $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) ), 'A new key\'s claim' );

		$row = $this->committedKey( $this->secondConnection(), $hash );

		$this->assertSame( array( $claim->id, 'claimed', self::digest( 'request 1' ), null, null ), array_values( (array) $row ) );
	}

	/**
	 * Tests that a key completed by a request answers the same request again with the first answer, byte for byte, and writes nothing.
	 *
	 * Planted violation: in MysqlIdempotencyKeys::answerTaken(), answer a placed key with owned():
	 * the retry then owns the key and would place a second order.
	 *
	 * @since 0.1.0
	 */
	public function test_a_completed_key_answers_the_same_request_with_its_first_answer(): void {
		$hash  = self::keyHash( 'attempt-1' );
		$first = $this->db->transaction(
			function () use ( $hash ): IdempotencyClaim {
				$claim = $this->keys->claim( self::SCOPE, $hash, self::digest( 'request 1' ), self::KEY_TTL );

				$this->keys->complete( $claim->id, 42, self::RESPONSE );

				return $claim;
			}
		);

		$retry = null;
		$log   = $this->captureQueries(
			function () use ( $hash, &$retry ): void {
				$retry = $this->claimCommitted( $hash, self::digest( 'request 1' ) );
			}
		);

		$this->assertInstanceOf( IdempotencyClaim::class, $retry );
		$this->assertFalse( $retry->owned, 'The retry of a placed request owns its key, so it would place a second order.' );
		$this->assertSame( array( $first->id, 42, self::RESPONSE ), array( $retry->id, $retry->orderId, $retry->responseJson ) );
		$this->assertQueryCount( 0, $log->ofType( 'UPDATE', 'DELETE' ), 'A replay writes' );

		$row = $this->committedKey( $this->secondConnection(), $hash );

		$this->assertSame( array( 'placed', 42, self::RESPONSE ), array( $row['state'] ?? null, $row['order_id'] ?? null, $row['response_json'] ?? null ) );
	}

	/**
	 * Tests that a second claim of a key never owns it: the unique key refuses the second row.
	 *
	 * Planted violation (must be shown red): drop the unique key `scope_key` from
	 * CheckoutTables::idempotencyKeys(). The second claim then inserts a second row of the key, and
	 * both claims own it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_claim_of_a_key_never_owns_it(): void {
		$hash  = self::keyHash( 'attempt-1' );
		$first = $this->claimCommitted( $hash, self::digest( 'request 1' ) );

		$this->assertTrue( $first->owned );

		try {
			$this->claimCommitted( $hash, self::digest( 'request 1' ) );
			$this->fail( 'A second claim of a key owned it: two requests would place an order with one key.' );
		} catch ( CodedException $taken ) {
			$this->assertSame( CheckoutError::PlacementInProgress, $taken->errorCode() );
		}

		$this->assertSame( 1, $this->checkoutRows( CheckoutTables::IDEMPOTENCY_KEYS ) );
	}

	/**
	 * Tests that the same key sent with another request is refused, and the key keeps the first answer.
	 *
	 * Planted violation: in MysqlIdempotencyKeys::answerTaken(), leave out the fingerprint
	 * comparison: a changed request with an old key then gets the old order's answer.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_key_with_another_request_is_refused_as_reused(): void {
		$hash = self::keyHash( 'attempt-1' );

		$this->db->transaction(
			function () use ( $hash ): void {
				$this->keys->complete( $this->keys->claim( self::SCOPE, $hash, self::digest( 'request 1' ), self::KEY_TTL )->id, 42, self::RESPONSE );
			}
		);

		try {
			$this->claimCommitted( $hash, self::digest( 'request 2' ) );
			$this->fail( 'A key placed with one request answered another.' );
		} catch ( CodedException $reused ) {
			$this->assertSame( CheckoutError::IdempotencyKeyReused, $reused->errorCode() );
		}

		$row = $this->committedKey( $this->secondConnection(), $hash );

		$this->assertSame( array( 'placed', self::digest( 'request 1' ), 42, self::RESPONSE ), array( $row['state'] ?? null, $row['request_fingerprint'] ?? null, $row['order_id'] ?? null, $row['response_json'] ?? null ) );
	}

	/**
	 * Tests that a key another request holds, live and not placed, answers "placement in progress" and changes nothing.
	 *
	 * Planted violation: in MysqlIdempotencyKeys::answerTaken(), answer a claimed key with owned():
	 * two requests then place an order with one key.
	 *
	 * @since 0.1.0
	 */
	public function test_a_live_claimed_key_is_in_progress(): void {
		$hash  = self::keyHash( 'attempt-1' );
		$first = $this->claimCommitted( $hash, self::digest( 'request 1' ) );

		foreach ( array( 'request 1', 'request 2' ) as $request ) {
			try {
				$this->claimCommitted( $hash, self::digest( $request ) );
				$this->fail( 'A key another request holds was claimed again.' );
			} catch ( CodedException $held ) {
				$this->assertSame( CheckoutError::PlacementInProgress, $held->errorCode() );
			}
		}

		$this->assertSame( array( $first->id, 'claimed', self::digest( 'request 1' ) ), array_slice( array_values( (array) $this->committedKey( $this->secondConnection(), $hash ) ), 0, 3 ) );
	}

	/**
	 * Tests that a key whose expiry has passed by the database clock is claimed again, in place, by a new request; one still live is not.
	 *
	 * Planted violation: in MysqlIdempotencyKeys::answerTaken(), skip the expiry: the new request
	 * then gets the old order's answer for a key past its retention.
	 *
	 * @since 0.1.0
	 */
	public function test_an_expired_key_is_claimed_again_by_the_database_clock(): void {
		$hash = self::keyHash( 'attempt-1' );
		$old  = $this->db->transaction(
			function () use ( $hash ): IdempotencyClaim {
				$claim = $this->keys->claim( self::SCOPE, $hash, self::digest( 'request 1' ), self::KEY_TTL );

				$this->keys->complete( $claim->id, 42, self::RESPONSE );

				return $claim;
			}
		);

		$this->keyExpiresIn( $old->id, 60 );

		$live = $this->claimCommitted( $hash, self::digest( 'request 1' ) );

		$this->assertFalse( $live->owned, 'A key with a minute left was treated as expired.' );

		$this->keyExpiresIn( $old->id, -1 );

		$again = $this->claimCommitted( $hash, self::digest( 'request 2' ) );

		$this->assertTrue( $again->owned, 'An expired key was not claimed again.' );
		$this->assertSame( $old->id, $again->id, 'The expired key was not claimed in place.' );

		$row = $this->committedKey( $this->secondConnection(), $hash );

		$this->assertSame( array( 'claimed', self::digest( 'request 2' ), null, null ), array( $row['state'] ?? null, $row['request_fingerprint'] ?? null, $row['order_id'] ?? null, $row['response_json'] ?? null ) );
		$this->assertGreaterThan( self::KEY_TTL - 60, (int) $this->db->fetchValue( 'SELECT TIMESTAMPDIFF( SECOND, UTC_TIMESTAMP(), expires_at ) FROM %i WHERE id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $old->id ), 'The re-claimed key does not live its TTL from now.' );
	}

	/**
	 * Tests that a key is claimed and completed only inside a transaction, before any statement.
	 *
	 * Planted violation: remove the requireTransaction() call from MysqlIdempotencyKeys::claim():
	 * the claim then commits on its own, and a placement that fails after it leaves its key claimed.
	 *
	 * @since 0.1.0
	 */
	public function test_a_key_is_claimed_and_completed_only_inside_a_transaction(): void {
		$steps = array(
			'claim'    => fn() => $this->keys->claim( self::SCOPE, self::keyHash( 'attempt-1' ), self::digest( 'request 1' ), self::KEY_TTL ),
			'complete' => fn() => $this->keys->complete( 1, 42, self::RESPONSE ),
		);

		foreach ( $steps as $step => $run ) {
			$log = $this->captureQueries(
				function () use ( $run, $step ): void {
					try {
						$run();
						$this->fail( $step . '() ran outside a transaction.' );
					} catch ( \LogicException $refused ) {
						$this->assertStringContainsString( 'transaction', $refused->getMessage() );
					}
				}
			);

			$this->assertQueryCount( 0, $log, $step . '() outside a transaction' );
		}

		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::IDEMPOTENCY_KEYS ) );
	}

	/**
	 * Tests that a key is completed once, only from claimed: a placed key keeps its order and answer.
	 *
	 * Planted violation: in MysqlIdempotencyKeys::COMPLETE, drop `AND state = 'claimed'`: a second
	 * completion then replaces the answer a retry gets.
	 *
	 * @since 0.1.0
	 */
	public function test_a_key_is_completed_once(): void {
		$hash  = self::keyHash( 'attempt-1' );
		$claim = $this->db->transaction(
			function () use ( $hash ): IdempotencyClaim {
				$claim = $this->keys->claim( self::SCOPE, $hash, self::digest( 'request 1' ), self::KEY_TTL );

				$this->keys->complete( $claim->id, 42, self::RESPONSE );

				return $claim;
			}
		);

		foreach ( array( $claim->id, $claim->id + 1000 ) as $id ) {
			try {
				$this->db->transaction( fn() => $this->keys->complete( $id, 43, '{"another":"answer"}' ) );
				$this->fail( 'A key that is not claimed was completed.' );
			} catch ( \LogicException $refused ) {
				$this->assertStringContainsString( 'not claimed', $refused->getMessage() );
			}
		}

		$row = $this->committedKey( $this->secondConnection(), $hash );

		$this->assertSame( array( 42, self::RESPONSE ), array( $row['order_id'] ?? null, $row['response_json'] ?? null ) );
	}

	/**
	 * Tests that one key hash in two scopes is two keys.
	 *
	 * @since 0.1.0
	 */
	public function test_scopes_keep_keys_apart(): void {
		$hash = self::keyHash( 'attempt-1' );

		$this->assertTrue( $this->claimCommitted( $hash, self::digest( 'request 1' ) )->owned );
		$this->assertTrue( $this->db->transaction( fn(): IdempotencyClaim => $this->keys->claim( 'checkout.other', $hash, self::digest( 'request 1' ), self::KEY_TTL ) )->owned );
		$this->assertSame( 2, $this->checkoutRows( CheckoutTables::IDEMPOTENCY_KEYS ) );
	}

	/**
	 * Tests that a key read under two hashes is read in one statement, and its rows judged in the order the hashes are given: a row that expired or that the decider refuses is passed over, and the first row left answers, with its answer or the key's own refusal; and that a read under one hash sends the one-hash statement, as before.
	 *
	 * Planted violations: in MysqlIdempotencyKeys::found(), give the second hash's row first: "both
	 * placed" is answered the second's; in MysqlIdempotencyKeys::replay(), ask the decider of the
	 * first hash's row alone: "both refused" is answered the second's; and a row the decider refuses
	 * ends the read (`return null` in place of `continue`): "the first refused, the second admitted"
	 * is answered nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_key_read_under_two_hashes_judges_the_first_hashs_row_first_in_one_statement(): void {
		$request = self::digest( 'the request' );
		$another = self::digest( 'another request' );
		$cases   = array(
			'neither row'                            => array( null, null, null ),
			'the second\'s row alone, placed for the request' => array( null, self::kept( $request ), 'second' ),
			'both placed'                            => array( self::kept( $request ), self::kept( $request ), 'first' ),
			'the first expired, the second live'     => array( self::kept( $request, expired: true ), self::kept( $request ), 'second' ),
			'the first refused, the second admitted' => array( self::kept( $request, refused: true ), self::kept( $request ), 'second' ),
			'both refused'                           => array( self::kept( $request, refused: true ), self::kept( $request, refused: true ), null ),
			'the first admitted for another request, the second\'s for this one' => array( self::kept( $another ), self::kept( $request ), CheckoutError::IdempotencyKeyReused->value ),
			'the first missing, the second claimed and admitted' => array( null, self::kept( $request, placed: false ), CheckoutError::PlacementInProgress->value ),
		);

		foreach ( $cases as $case => list( $first, $second, $expected ) ) {
			$hashes = array(
				'first'  => self::keyHash( 'the first, ' . md5( $case ) ),
				'second' => self::keyHash( 'the second, ' . md5( $case ) ),
			);

			foreach ( array_filter(
				array(
					'first'  => $first,
					'second' => $second,
				)
			) as $which => $row ) {
				$this->plantKept( $hashes[ $which ], $row, self::ORDERS[ $which ] );
			}

			$read = $this->readUnder( $hashes['first'], $request, $hashes['second'] );

			$this->assertSame( $expected, $read['answer'], $case );
			$this->assertCount( 1, $read['statements'], "{$case}: one statement." );
			$this->assertMatchesRegularExpression( self::shapeOf( MysqlIdempotencyKeys::FIND_EITHER ), $read['statements'][0], "{$case}: the read of both hashes." );
		}

		$hash = self::keyHash( 'one hash' );

		$this->plantKept( $hash, self::kept( $request ), self::ORDERS['first'] );

		foreach ( array(
			'no second hash'              => null,
			'a second hash, the same one' => $hash,
		) as $case => $second ) {
			$read = $this->readUnder( $hash, $request, $second );

			$this->assertSame( 'first', $read['answer'], $case );
			$this->assertCount( 1, $read['statements'], "{$case}: one statement." );
			$this->assertMatchesRegularExpression( self::shapeOf( MysqlIdempotencyKeys::FIND ), $read['statements'][0], "{$case}: the read of one hash, as before." );
		}
	}

	/**
	 * Returns how a key is planted for a case: placed or claimed, for a request, expired or not, and kept for this request or for someone the decider refuses.
	 *
	 * @since 0.2.0
	 *
	 * @param string $fingerprint The request it was claimed for.
	 * @param bool   $placed      Optional. Whether its order was placed. Default true.
	 * @param bool   $expired     Optional. Whether it has expired. Default false.
	 * @param bool   $refused     Optional. Whether the decider refuses this request from it. Default false.
	 * @return array{fingerprint: string, placed: bool, expired: bool, refused: bool} The key.
	 */
	private static function kept( string $fingerprint, bool $placed = true, bool $expired = false, bool $refused = false ): array {
		return array(
			'fingerprint' => $fingerprint,
			'placed'      => $placed,
			'expired'     => $expired,
			'refused'     => $refused,
		);
	}

	/**
	 * Claims a key as kept() describes it, in a transaction that commits, and completes it with an order when it is placed.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                                                 $hash    The key's hash.
	 * @param array{fingerprint: string, placed: bool, expired: bool, refused: bool} $row     The key.
	 * @param int                                                                    $orderId The order it was placed with.
	 */
	private function plantKept( string $hash, array $row, int $orderId ): void {
		$keeps = (string) wp_json_encode(
			array(
				'order' => $orderId,
				'for'   => $row['refused'] ? self::SOMEONE_ELSE : 'this request',
			)
		);
		$id    = $this->db->transaction(
			function () use ( $hash, $row, $orderId, $keeps ): int {
				$claim = $this->keys->claim( self::SCOPE, $hash, $row['fingerprint'], self::KEY_TTL, $keeps );

				if ( $row['placed'] ) {
					$this->keys->complete( $claim->id, $orderId, $keeps );
				}

				return $claim->id;
			}
		);

		if ( $row['expired'] ) {
			$this->keyExpiresIn( $id, -1 );
		}
	}

	/**
	 * Reads a key under one hash or two with a decider that refuses what is kept for someone else, and returns which row answered, or the refusal, with the statements the read sent.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $hash        The first hash.
	 * @param string      $fingerprint The request's fingerprint.
	 * @param string|null $elseHash    The second hash, or null for none.
	 * @return array{answer: string|null, statements: list<string>} `first` or `second` for the row whose answer was replayed, a refusal's code, or null; and the statements.
	 */
	private function readUnder( string $hash, string $fingerprint, ?string $elseHash ): array {
		$answer = null;
		$log    = $this->captureQueries(
			function () use ( $hash, $fingerprint, $elseHash, &$answer ): void {
				try {
					$replay = $this->keys->replay( self::SCOPE, $hash, $fingerprint, static fn( ?string $kept ): bool => ! str_contains( (string) $kept, self::SOMEONE_ELSE ), $elseHash );
					$answer = null === $replay ? null : (string) array_search( $replay->orderId, self::ORDERS, true );
				} catch ( CodedException $refused ) {
					$answer = $refused->errorCode()->value;
				}
			}
		);

		return array(
			'answer'     => $answer,
			'statements' => $log->sqls(),
		);
	}
}

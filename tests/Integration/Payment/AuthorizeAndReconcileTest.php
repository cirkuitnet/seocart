<?php
/**
 * Tests the calls placement and reconciliation make around the money path: authorize, the stale intents and the gateway's status
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Gateway\GatewayUnavailable;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\Outcome;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * Authorizing asks the gateway outside any transaction and applies nothing; reconciliation finds the intents still waiting by the database clock, and asks the gateway where they stand.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentService::authorize(), drop the depth check: the authorization made inside a
 *   transaction reaches the gateway;
 * - in MysqlPaymentRepository::STALE_INTENTS, compare `created_at` instead of `updated_at`: the
 *   intent that changed recently, though created long ago, is listed;
 * - in StubGateway::laterAnswer(), answer a pending intent as approved: the pending intent's query
 *   is not null;
 * - in MysqlPaymentRepository::STALE_INTENTS, ignore the cursor, `( uuid > %s OR 1 = 1 )`: every
 *   page is the first, and the intent behind the three the gateway never answers is never asked.
 *
 * @since 0.1.0
 */
final class AuthorizeAndReconcileTest extends PaymentTestCase {

	/**
	 * Tests that authorize() returns the gateway's answer for the intent's frozen amount, and writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_authorize_returns_the_answer_unapplied(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$before = $this->snapshot();
		$result = null;
		$log    = $this->captureQueries(
			function () use ( $intent, &$result ): void {
				$result = $this->authorizeWith( $intent, StubGateway::APPROVE );
			}
		);

		$this->assertNotNull( $result );
		$this->assertSame( array( Operation::Authorize, Outcome::Approved, $intent->uuid, 'stub-ch-' . $intent->uuid ), array( $result->operation, $result->outcome, $result->intentUuid, $result->providerObjectId ) );
		$this->assertTrue( $result->amount->equals( $intent->amount ) );
		$this->assertQueryCount( 0, $log->ofType( 'INSERT', 'UPDATE', 'DELETE' ), 'writes by authorize()' );
		$this->assertSame( $before, $this->snapshot(), 'The answer is the caller\'s to apply.' );
		$this->assertSame( array( 0 ), array_column( $this->gateway->calls, 'depth' ) );
		$this->assertSame( 'pending_payment', $this->orderRow( $order->id )['status'] );
	}

	/**
	 * Tests that payment data without a token reaches the gateway, which declines it, and that an unknown intent is not found.
	 *
	 * @since 0.1.0
	 */
	public function test_a_missing_token_is_declined_and_an_unknown_intent_is_not_found(): void {
		list( , $intent ) = $this->placeWithIntent();

		$declined = $this->payments->authorize( $intent->uuid, array( 'payment_token' => array( 'not', 'a', 'token' ) ) );

		$this->assertSame( array( Outcome::Declined, StubGateway::INVALID_TOKEN ), array( $declined->outcome, $declined->errorCode ) );

		try {
			$this->payments->authorize( '00000000-0000-7000-8000-000000000000', array( PaymentService::PAYMENT_TOKEN => StubGateway::APPROVE ) );
			$this->fail( 'An unknown intent was authorized.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::IntentNotFound, $refused->errorCode() );
		}
	}

	/**
	 * Tests that authorize() inside a transaction is refused before the intent is read or the gateway called.
	 *
	 * @since 0.1.0
	 */
	public function test_authorize_inside_a_transaction_is_refused_before_anything(): void {
		list( , $intent ) = $this->placeWithIntent();

		$log = $this->captureQueries(
			function () use ( $intent ): void {
				try {
					$this->db->transaction( fn() => $this->authorizeWith( $intent, StubGateway::APPROVE ) );
					$this->fail( 'The gateway was asked to authorize inside a transaction.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'never inside a transaction', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log->forTable( $this->table( PaymentTables::INTENTS ) ), 'reads of the intent before the refusal' );
		$this->assertSame( array(), $this->gateway->calls );
	}

	/**
	 * Tests that a gateway that does not answer leaves the intent as it was, for reconciliation.
	 *
	 * @since 0.1.0
	 */
	public function test_a_gateway_that_does_not_answer_changes_nothing(): void {
		list( , $intent ) = $this->placeWithIntent();

		$before = $this->snapshot();

		try {
			$this->authorizeWith( $intent, StubGateway::THROW );
			$this->fail( 'The stub answered when told to be unavailable.' );
		} catch ( GatewayUnavailable $unavailable ) {
			$this->assertNotSame( '', $unavailable->getMessage() );
		}

		$this->assertSame( $before, $this->snapshot() );
		$this->assertNull( $this->payments->queryGateway( $intent ), 'The stub never gave the intent a reference, so it knows nothing of it.' );
	}

	/**
	 * Tests that the intents still waiting for an answer are listed, in the order they were created, once they have waited past the threshold, by the database clock.
	 *
	 * @since 0.1.0
	 */
	public function test_stale_intents_are_those_still_waiting_past_the_threshold(): void {
		$created       = $this->placeWithIntent()[1];
		$fresh         = $this->placeWithIntent()[1];
		$waiting       = $this->placeWithIntent()[1];
		$pending       = $this->placeWithIntent()[1];
		$authorized    = $this->placeWithIntent()[1];
		$changedLately = $this->placeWithIntent()[1];

		$this->deliver( $this->authorizeWith( $waiting, StubGateway::REQUIRES_ACTION ) );
		$this->deliver( $this->authorizeWith( $pending, StubGateway::PENDING ) );
		$this->deliver( $this->authorizeWith( $authorized, StubGateway::APPROVE ) );

		$this->age( $created, 900, 900 );
		$this->age( $waiting, 800, 800 );
		$this->age( $pending, 700, 700 );
		$this->age( $authorized, 1000, 1000 );
		$this->age( $changedLately, 5000, 60 );

		$stale = $this->payments->staleIntents( 600, 10 );

		$this->assertSame( array( $created->uuid, $waiting->uuid, $pending->uuid ), array_map( static fn( IntentRef $intent ): string => $intent->uuid, $stale ), 'In the order they were created; not the fresh one, the authorized one, or the one changed a minute ago.' );
		$this->assertSame( array( IntentStatus::Created, IntentStatus::RequiresAction, IntentStatus::Processing ), array_map( static fn( IntentRef $intent ): IntentStatus => $intent->status, $stale ) );
		$this->assertSame( array( null, 'stub-pi-requires_action-' . $waiting->uuid, 'stub-pi-pending-' . $pending->uuid ), array_map( static fn( IntentRef $intent ): ?string => $intent->providerIntentId, $stale ) );
		$this->assertSame( array( $created->uuid, $waiting->uuid ), array_map( static fn( IntentRef $intent ): string => $intent->uuid, $this->payments->staleIntents( 600, 2 ) ), 'The limit keeps the first page.' );
		$this->assertNotContains( $fresh->uuid, array_map( static fn( IntentRef $intent ): string => $intent->uuid, $stale ) );
	}

	/**
	 * Tests that a caller paging with the cursor reaches the intent behind three the gateway never answers, where the first page alone never would.
	 *
	 * @since 0.1.0
	 */
	public function test_the_cursor_reaches_what_waits_behind_unanswered_intents(): void {
		$unanswered = array( $this->placeWithIntent()[1], $this->placeWithIntent()[1], $this->placeWithIntent()[1] );
		$answerable = $this->placeWithIntent()[1];

		$this->deliver( $this->authorizeWith( $answerable, StubGateway::REQUIRES_ACTION ) );

		foreach ( array_merge( $unanswered, array( $answerable ) ) as $intent ) {
			$this->age( $intent, 900, 900 );
		}

		$uuids = static fn( array $intents ): array => array_map( static fn( IntentRef $intent ): string => $intent->uuid, $intents );

		$this->assertSame( $uuids( array( $unanswered[0], $unanswered[1] ) ), $uuids( $this->payments->staleIntents( 600, 2 ) ), 'Without the cursor, every pass starts with the two the gateway never answers.' );

		$asked   = array();
		$applied = array();
		$after   = '';
		$pages   = 0;

		do {
			if ( ++$pages > 4 ) {
				$this->fail( 'The pages never end: the cursor does not move past the intents already read.' );
			}

			$page = $this->payments->staleIntents( 600, 2, $after );
			$full = 2 === count( $page );

			foreach ( $page as $intent ) {
				$asked[] = $intent->uuid;
				$answer  = $this->payments->queryGateway( $intent );

				if ( null !== $answer ) {
					$applied[ $intent->uuid ] = $this->deliver( $answer )->kind;
				}

				$after = $intent->uuid;
			}
		} while ( $full );

		$this->assertSame( $uuids( array_merge( $unanswered, array( $answerable ) ) ), $asked, 'Each waiting intent is asked once, in two pages and an empty one.' );
		$this->assertSame( array( $answerable->uuid => ApplicationKind::Applied ), $applied );
		$this->assertSame( 'authorized', $this->intentRow( $answerable->uuid )['status'] );
	}

	/**
	 * Tests that the gateway's later answer settles an intent that waited for the customer, keeps a pending one pending, and answers a known intent as it first did.
	 *
	 * @since 0.1.0
	 */
	public function test_query_gateway_answers_from_the_script(): void {
		$waiting  = $this->placeWithIntent()[1];
		$pending  = $this->placeWithIntent()[1];
		$approved = $this->placeWithIntent()[1];

		$this->deliver( $this->authorizeWith( $waiting, StubGateway::REQUIRES_ACTION ) );
		$this->deliver( $this->authorizeWith( $pending, StubGateway::PENDING ) );

		$first = $this->authorizeWith( $approved, StubGateway::APPROVE );

		$this->deliver( $first );

		$stale = array();

		foreach ( $this->staleRefs( $waiting, $pending, $approved ) as $uuid => $ref ) {
			$stale[ $uuid ] = $this->payments->queryGateway( $ref );
		}

		$this->assertNotNull( $stale[ $waiting->uuid ] );
		$this->assertSame( array( Operation::Authorize, Outcome::Approved, 'stub-ch-' . $waiting->uuid ), array( $stale[ $waiting->uuid ]->operation, $stale[ $waiting->uuid ]->outcome, $stale[ $waiting->uuid ]->providerObjectId ) );
		$this->assertNull( $stale[ $pending->uuid ], 'A pending intent is still pending.' );
		$this->assertEquals( $first, $stale[ $approved->uuid ], 'An intent answered before is answered the same way, so applying it again is a duplicate.' );
		$this->assertSame( ApplicationKind::Applied, $this->deliver( $stale[ $waiting->uuid ] )->kind );
		$this->assertSame( ApplicationKind::Duplicate, $this->deliver( $stale[ $approved->uuid ] )->kind );
		$this->assertSame( array( 0, 0, 0, 0, 0, 0 ), array_column( $this->gateway->calls, 'depth' ) );
	}

	/**
	 * Ages an intent, in the database's clock: when it was created and when it last changed.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentRef $intent         The intent.
	 * @param int       $createdSecondsAgo How long ago it was created.
	 * @param int       $changedSecondsAgo How long ago it last changed.
	 */
	private function age( IntentRef $intent, int $createdSecondsAgo, int $changedSecondsAgo ): void {
		$this->db->execute(
			'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL %d SECOND, updated_at = UTC_TIMESTAMP(6) - INTERVAL %d SECOND WHERE uuid = %s',
			$this->table( PaymentTables::INTENTS ),
			$createdSecondsAgo,
			$changedSecondsAgo,
			$intent->uuid
		);
	}

	/**
	 * Returns the intents as reconciliation holds them: read back with the references the gateway gave them.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentRef ...$intents The intents, as created.
	 * @return array<string, IntentRef> The intents as they stand, by uuid.
	 */
	private function staleRefs( IntentRef ...$intents ): array {
		$refs = array();

		foreach ( $intents as $intent ) {
			$row = $this->intentRow( $intent->uuid );

			$refs[ $intent->uuid ] = new IntentRef( $intent->uuid, $intent->orderId, IntentStatus::from( (string) $row['status'] ), null === $row['provider_intent_id'] ? null : (string) $row['provider_intent_id'], $intent->amount );
		}

		return $refs;
	}
}

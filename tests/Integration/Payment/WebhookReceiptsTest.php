<?php
/**
 * Tests the webhook receipts: claimed once by event and settled once, in the transaction that applies the event
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Domain\Webhook\Receipt;
use SEOCart\Payment\Domain\Webhook\ReceiptDecision;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Migrations\CreateWebhookReceipts;
use SEOCart\Payment\Infrastructure\MysqlWebhookReceipts;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * A receipt is claimed once for each event of a gateway's address, in the transaction that applies the event, found by a second delivery of it, and settled once in that transaction; a settled event is answered by one read.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class WebhookReceiptsTest extends PaymentTestCase {

	/**
	 * The hash every delivery of the test carries.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const HASH = 'a3f1c2d4e5b6a7980f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a6978';

	/**
	 * The correlation id every delivery of the test carries.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CORRELATION = '01928c3e-0000-7000-8000-00000000c0c0';

	/**
	 * The receipts over `$this->db`.
	 *
	 * @since 0.2.0
	 *
	 * @var MysqlWebhookReceipts
	 */
	private MysqlWebhookReceipts $receipts;

	/**
	 * Creates the receipts' table and the repository.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateWebhookReceipts() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$this->receipts = new MysqlWebhookReceipts( $this->db );
	}

	/**
	 * Tests that an event's receipt is claimed once, undecided, found undecided by a second claim before it is settled and settled after, and settled only once.
	 *
	 * Planted violation: in MysqlWebhookReceipts::SETTLE, drop `AND result IS NULL`: the second
	 * settlement then writes over the first, and is reported as settled here.
	 *
	 * @since 0.2.0
	 */
	public function test_an_event_is_claimed_once_and_settled_once(): void {
		$first  = $this->claim( 'evt_1' );
		$second = $this->claim( 'evt_1' );

		$this->assertNull( $first->result, 'A first delivery claims the event undecided.' );
		$this->assertSame( $first->id, $second->id, 'A second delivery finds the receipt of the first.' );
		$this->assertFalse( $second->isSettled(), 'An undecided event is processed again by its next delivery.' );

		$this->assertTrue( $this->settle( $first->id, new ReceiptDecision( ReceiptResult::Applied, 'applied', '01928c3e-0000-7000-8000-0000000000b1', 42 ) ) );
		$this->assertFalse( $this->settle( $first->id, new ReceiptDecision( ReceiptResult::Duplicate, null, '01928c3e-0000-7000-8000-0000000000b1', 42 ) ), 'A receipt is settled once: the first decision stands.' );

		$third = $this->claim( 'evt_1' );
		$found = $this->receipts->find( 'stub', Mode::Test, 'evt_1' );

		$this->assertSame( array( $first->id, ReceiptResult::Applied ), array( $third->id, $third->result ) );
		$this->assertSame( array( $first->id, ReceiptResult::Applied ), array( $found?->id, $found?->result ), 'The plain read finds the decided receipt.' );
		$this->assertNull( $this->receipts->find( 'stub', Mode::Test, 'evt_never' ) );
		$this->assertSame(
			array(
				'provider'       => 'stub',
				'mode'           => 'test',
				'event_id'       => 'evt_1',
				'event_type'     => 'authorization.approved',
				'occurred_at'    => '2026-10-09 10:00:00',
				'result'         => 'applied',
				'result_code'    => 'applied',
				'intent_uuid'    => '01928c3e-0000-7000-8000-0000000000b1',
				'transaction_id' => '42',
				'payload_hash'   => self::HASH,
				'correlation_id' => self::CORRELATION,
			),
			self::pick( $this->receiptRow( $first->id ), 'provider', 'mode', 'event_id', 'event_type', 'occurred_at', 'result', 'result_code', 'intent_uuid', 'transaction_id', 'payload_hash', 'correlation_id' )
		);
		$this->assertSame( 1, $this->receiptCount(), 'One event, one receipt.' );
	}

	/**
	 * Tests that the same event id at the address of another mode, or of another gateway, is another event, and that a decision with no word, intent or row leaves those NULL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_key_is_the_gateway_the_mode_and_the_event(): void {
		$test  = $this->claim( 'evt_shared' );
		$live  = $this->db->transaction( fn(): Receipt => $this->receipts->claim( 'stub', Mode::Live, 'evt_shared', 'authorization.approved', null, self::HASH, '' ) );
		$other = $this->db->transaction( fn(): Receipt => $this->receipts->claim( 'other_gateway', Mode::Test, 'evt_shared', 'authorization.approved', null, self::HASH, self::CORRELATION ) );

		$this->assertCount( 3, array_unique( array( $test->id, $live->id, $other->id ) ) );

		$this->settle( $live->id, ReceiptDecision::ignored( ReceiptDecision::UNKNOWN_INTENT ) );

		$this->assertSame(
			array(
				'occurred_at'    => null,
				'result'         => 'ignored',
				'result_code'    => 'unknown_intent',
				'intent_uuid'    => null,
				'transaction_id' => null,
				'correlation_id' => null,
			),
			self::pick( $this->receiptRow( $live->id ), 'occurred_at', 'result', 'result_code', 'intent_uuid', 'transaction_id', 'correlation_id' )
		);
	}

	/**
	 * Tests that a settled event delivered again is answered by one plain read each time, and that a claim meeting it costs the refused insert and one locking read.
	 *
	 * Planted violation: in MysqlWebhookReceipts::claim(), answer a refused insert as a first
	 * delivery (`return new Receipt( $this->statements->lastInsertId(), null );` in the catch),
	 * without reading the receipt: the claim then finds no settled receipt, and the event would
	 * be processed again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settled_event_is_answered_by_one_read_and_a_claim_meeting_it_by_two(): void {
		$receipt = $this->claim( 'evt_storm' );

		$this->settle( $receipt->id, new ReceiptDecision( ReceiptResult::Applied, 'applied' ) );

		$found = array();
		$log   = $this->captureQueries(
			function () use ( &$found ): void {
				for ( $delivery = 0; $delivery < 50; $delivery++ ) {
					$found[] = $this->receipts->find( 'stub', Mode::Test, 'evt_storm' )?->result;
				}
			}
		);

		$this->assertSame( array_fill( 0, 50, ReceiptResult::Applied ), $found, 'Every delivery of the settled event found it settled.' );
		$this->assertQueryCount( 50, $log->forTable( $this->table( WebhookReceiptTables::RECEIPTS ) ), 'Fifty deliveries of a settled event' );

		$claimed = null;
		$log     = $this->captureQueries(
			function () use ( &$claimed ): void {
				$claimed = $this->claim( 'evt_storm' );
			}
		);

		$this->assertSame( array( $receipt->id, ReceiptResult::Applied ), array( $claimed?->id, $claimed?->result ), 'A claim that meets the settled receipt reads its decision.' );
		$this->assertQueryCount( 2, $log->forTable( $this->table( WebhookReceiptTables::RECEIPTS ) ), 'A claim meeting a settled receipt' );
		$this->assertSame( 1, $log->matching( '/FOR UPDATE/' )->count(), 'The receipt the claim met is read under its lock.' );
		$this->assertSame( 1, $this->receiptCount() );
	}

	/**
	 * Tests that a claim and a settlement refuse outside a transaction, before any statement, while the plain read runs at any depth.
	 *
	 * @since 0.2.0
	 */
	public function test_a_receipt_is_written_only_inside_a_transaction(): void {
		$receipt = $this->claim( 'evt_depth' );
		$refused = array();
		$writes  = array(
			fn() => $this->receipts->claim( 'stub', Mode::Test, 'evt_outside', 'authorization.approved', null, self::HASH, self::CORRELATION ),
			fn() => $this->receipts->settle( $receipt->id, ReceiptDecision::ignored( 'dispute.created' ) ),
		);

		$log = $this->captureQueries(
			static function () use ( $writes, &$refused ): void {
				foreach ( $writes as $write ) {
					try {
						$write();
					} catch ( \LogicException $outside ) {
						$refused[] = $outside->getMessage();
					}
				}
			}
		);

		$this->assertCount( 2, $refused );
		$this->assertQueryCount( 0, $log->forTable( $this->table( WebhookReceiptTables::RECEIPTS ) ), 'Receipt writes outside a transaction' );
		$this->assertNull( $this->receiptRow( $receipt->id )['result'] );
		$this->assertSame( $receipt->id, $this->receipts->find( 'stub', Mode::Test, 'evt_depth' )?->id );
	}

	/**
	 * Tests that a claim whose transaction rolls back leaves no receipt, and the event's next delivery claims it afresh: the receipt commits with the money or not at all.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_rolled_back_leaves_no_receipt(): void {
		try {
			$this->db->transaction(
				function (): void {
					$this->receipts->claim( 'stub', Mode::Test, 'evt_fault', 'capture.approved', null, self::HASH, self::CORRELATION );

					throw new \RuntimeException( 'The money path failed.' );
				}
			);
			$this->fail( 'The fault was swallowed.' );
		} catch ( \RuntimeException $fault ) {
			$this->assertSame( 'The money path failed.', $fault->getMessage() );
		}

		$this->assertSame( 0, $this->receiptCount(), 'No receipt outlives its transaction.' );
		$this->assertNull( $this->claim( 'evt_fault' )->result, 'The next delivery claims the event afresh.' );
	}

	/**
	 * Tests that doctor's read counts the decisions received lately, by decision and word.
	 *
	 * @since 0.2.0
	 */
	public function test_doctor_counts_the_decisions(): void {
		$ignored = $this->claim( 'evt_dispute' );
		$also    = $this->claim( 'evt_dispute_2' );

		$this->settle( $ignored->id, ReceiptDecision::ignored( 'dispute.created' ) );
		$this->settle( $also->id, ReceiptDecision::ignored( 'dispute.created' ) );

		$this->assertSame(
			array(
				array(
					'result'      => 'ignored',
					'result_code' => 'dispute.created',
					'n'           => 2,
				),
			),
			$this->receipts->resultCounts( array( ReceiptResult::Ignored, ReceiptResult::Unapplied ), 30 * 86400, 10 )
		);
		$this->assertSame( array(), $this->receipts->resultCounts( array(), 30 * 86400, 10 ) );
	}

	/**
	 * Claims, and commits, the receipt of an event of the stub's test address.
	 *
	 * @since 0.2.0
	 *
	 * @param string $eventId The event.
	 * @return Receipt The receipt.
	 */
	private function claim( string $eventId ): Receipt {
		return $this->db->transaction( fn(): Receipt => $this->receipts->claim( 'stub', Mode::Test, $eventId, 'authorization.approved', new \DateTimeImmutable( '2026-10-09 10:00:00', new \DateTimeZone( 'UTC' ) ), self::HASH, self::CORRELATION ) );
	}

	/**
	 * Settles, and commits, a receipt.
	 *
	 * @since 0.2.0
	 *
	 * @param int             $id       The receipt.
	 * @param ReceiptDecision $decision The decision.
	 * @return bool Whether it was settled now.
	 */
	private function settle( int $id, ReceiptDecision $decision ): bool {
		return $this->db->transaction( fn(): bool => $this->receipts->settle( $id, $decision ) );
	}

	/**
	 * Reads the row of a receipt.
	 *
	 * @since 0.2.0
	 *
	 * @param int $id The receipt.
	 * @return array<string, mixed> The row.
	 */
	private function receiptRow( int $id ): array {
		return (array) $this->db->fetchRow( 'SELECT * FROM %i WHERE id = %d', $this->table( WebhookReceiptTables::RECEIPTS ), $id );
	}

	/**
	 * Counts the receipts.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	private function receiptCount(): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( WebhookReceiptTables::RECEIPTS ) );
	}
}

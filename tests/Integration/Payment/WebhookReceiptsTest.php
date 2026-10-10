<?php
/**
 * Tests the webhook receipts: recorded once by event, settled once, each outside any transaction
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
 * A receipt is recorded once for each event of a gateway's address, found by a second delivery of it, and settled once; a settled event delivered again costs two statements.
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
	 * Tests that an event's receipt is recorded once, undecided, found undecided by a second delivery before it is settled and settled after, and settled only once.
	 *
	 * Planted violation: in MysqlWebhookReceipts::SETTLE, drop `AND result IS NULL`: the second
	 * settlement then writes over the first, and is reported as settled here.
	 *
	 * @since 0.2.0
	 */
	public function test_an_event_is_recorded_once_and_settled_once(): void {
		$first  = $this->record( 'evt_1' );
		$second = $this->record( 'evt_1' );

		$this->assertNull( $first->result, 'A first delivery records the event undecided.' );
		$this->assertSame( $first->id, $second->id, 'A second delivery finds the receipt of the first.' );
		$this->assertFalse( $second->isSettled(), 'An undecided event is processed again by its next delivery.' );

		$this->assertTrue( $this->receipts->settle( $first->id, new ReceiptDecision( ReceiptResult::Applied, 'applied', '01928c3e-0000-7000-8000-0000000000b1', 42 ) ) );
		$this->assertFalse( $this->receipts->settle( $first->id, new ReceiptDecision( ReceiptResult::Duplicate, null, '01928c3e-0000-7000-8000-0000000000b1', 42 ) ), 'A receipt is settled once: the decision of the first to finish stands.' );

		$third = $this->record( 'evt_1' );

		$this->assertSame( array( $first->id, ReceiptResult::Applied ), array( $third->id, $third->result ) );
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
		$test  = $this->record( 'evt_shared' );
		$live  = $this->receipts->record( 'stub', Mode::Live, 'evt_shared', 'authorization.approved', null, self::HASH, '' );
		$other = $this->receipts->record( 'other_gateway', Mode::Test, 'evt_shared', 'authorization.approved', null, self::HASH, self::CORRELATION );

		$this->assertCount( 3, array_unique( array( $test->id, $live->id, $other->id ) ) );

		$this->receipts->settle( $live->id, ReceiptDecision::ignored( ReceiptDecision::UNKNOWN_INTENT ) );

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
	 * Tests that a settled event delivered again costs two statements, the refused insert and the read of its receipt, however often it comes.
	 *
	 * Planted violation: in MysqlWebhookReceipts::record(), answer a refused insert as a first
	 * delivery (`return new Receipt( $this->statements->lastInsertId(), null );` in the catch),
	 * without reading the receipt: the storm then finds no settled receipt, and every delivery of
	 * it would be processed again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settled_event_delivered_again_costs_two_statements_each_time(): void {
		$receipt = $this->record( 'evt_storm' );

		$this->receipts->settle( $receipt->id, new ReceiptDecision( ReceiptResult::Applied, 'applied' ) );

		$found = new \ArrayObject();
		$log   = $this->captureQueries(
			function () use ( $found ): void {
				for ( $delivery = 0; $delivery < 50; $delivery++ ) {
					$found->append( $this->record( 'evt_storm' )->result );
				}
			}
		);

		$this->assertSame( array_fill( 0, 50, ReceiptResult::Applied ), $found->getArrayCopy(), 'Every delivery of the settled event found it settled.' );
		$this->assertQueryCount( 100, $log->forTable( $this->table( WebhookReceiptTables::RECEIPTS ) ), 'Fifty deliveries of a settled event' );
		$this->assertSame( 1, $this->receiptCount() );
	}

	/**
	 * Tests that recording and settling a receipt refuse inside a transaction, before any statement.
	 *
	 * @since 0.2.0
	 */
	public function test_a_receipt_is_never_written_inside_a_transaction(): void {
		$receipt = $this->record( 'evt_depth' );
		$refused = new \ArrayObject();
		$writes  = array(
			fn() => $this->record( 'evt_inside' ),
			fn() => $this->receipts->settle( $receipt->id, ReceiptDecision::ignored( 'dispute.created' ) ),
		);

		$log = $this->captureQueries(
			function () use ( $writes, $refused ): void {
				$this->db->transaction(
					static function () use ( $writes, $refused ): void {
						foreach ( $writes as $write ) {
							try {
								$write();
							} catch ( \LogicException $outside ) {
								$refused->append( $outside->getMessage() );
							}
						}
					}
				);
			}
		);

		$this->assertCount( 2, $refused );
		$this->assertQueryCount( 0, $log->forTable( $this->table( WebhookReceiptTables::RECEIPTS ) ), 'Receipt statements inside a transaction' );
		$this->assertNull( $this->receiptRow( $receipt->id )['result'] );
	}

	/**
	 * Tests that doctor's two reads find the receipts undecided past an age, and count the decisions received lately.
	 *
	 * @since 0.2.0
	 */
	public function test_doctor_reads_the_undecided_and_counts_the_decisions(): void {
		$old     = $this->record( 'evt_old' );
		$ignored = $this->record( 'evt_dispute' );
		$also    = $this->record( 'evt_dispute_2' );

		$this->record( 'evt_young' );
		$this->receiptReceivedAgo( $old->id, 3600 );
		$this->receipts->settle( $ignored->id, ReceiptDecision::ignored( 'dispute.created' ) );
		$this->receipts->settle( $also->id, ReceiptDecision::ignored( 'dispute.created' ) );

		$unsettled = $this->receipts->unsettled( 600, 10 );

		$this->assertSame( array( 'evt_old' ), array_column( $unsettled, 'event_id' ), 'Only the receipt undecided past the age is listed.' );
		$this->assertGreaterThanOrEqual( 3600, $unsettled[0]['age_seconds'] );
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
	 * Records a delivery of an event of the stub's test address.
	 *
	 * @since 0.2.0
	 *
	 * @param string $eventId The event.
	 * @return Receipt The receipt.
	 */
	private function record( string $eventId ): Receipt {
		return $this->receipts->record( 'stub', Mode::Test, $eventId, 'authorization.approved', new \DateTimeImmutable( '2026-10-09 10:00:00', new \DateTimeZone( 'UTC' ) ), self::HASH, self::CORRELATION );
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

	/**
	 * Moves the reception of a receipt into the past, by the database clock.
	 *
	 * @since 0.2.0
	 *
	 * @param int $id      The receipt.
	 * @param int $seconds How long ago.
	 */
	private function receiptReceivedAgo( int $id, int $seconds ): void {
		$this->db->execute( 'UPDATE %i SET received_at = UTC_TIMESTAMP(6) - INTERVAL %d SECOND WHERE id = %d', $this->table( WebhookReceiptTables::RECEIPTS ), $seconds, $id );
	}
}

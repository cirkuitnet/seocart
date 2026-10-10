<?php
/**
 * Tests the retention job of the webhook receipts
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Domain\Webhook\ReceiptDecision;
use SEOCart\Payment\Infrastructure\Jobs\WebhookReceiptRetention;
use SEOCart\Payment\Infrastructure\Migrations\CreateWebhookReceipts;
use SEOCart\Payment\Infrastructure\MysqlWebhookReceipts;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\RetentionCatalog;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Logging\LogRetention;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * The receipts' retention: a receipt past its period is deleted, one inside it never, however long ago it was received; a backlog is deleted in batches.
 *
 * Expiry is set and judged by the database clock.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class WebhookReceiptRetentionTest extends PaymentTestCase {

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
	 * Tests that the job deletes exactly the receipts past their period: one received long ago that is still inside it stays, and so does one that expires in a minute.
	 *
	 * Planted violation: in MysqlWebhookReceipts::DELETE_EXPIRED, sweep by reception instead
	 * (`WHERE received_at <= UTC_TIMESTAMP() - INTERVAL 30 DAY ORDER BY received_at`): a receipt
	 * received 40 days ago that still has 20 to live is then deleted.
	 *
	 * @since 0.2.0
	 */
	public function test_the_job_deletes_the_expired_receipts_and_keeps_every_live_one(): void {
		$expired = $this->plantReceipt( 'evt_expired', -1 );
		$long    = $this->plantReceipt( 'evt_long_gone', -60 * 86400 );
		$minute  = $this->plantReceipt( 'evt_minute', 60 );
		$old     = $this->plantReceipt( 'evt_old_but_live', 20 * 86400 );

		$this->db->execute( 'UPDATE %i SET received_at = UTC_TIMESTAMP(6) - INTERVAL 40 DAY WHERE id = %d', $this->table( WebhookReceiptTables::RECEIPTS ), $old );

		( new WebhookReceiptRetention( $this->receipts ) )->handle( array() );

		$this->assertSame( array( $minute, $old ), $this->remaining(), 'The job deleted a live receipt, or kept an expired one.' );
		$this->assertNotContains( $expired, $this->remaining() );
		$this->assertNotContains( $long, $this->remaining() );
	}

	/**
	 * Tests that a backlog is deleted in batches until a batch comes back short, all in one run.
	 *
	 * @since 0.2.0
	 */
	public function test_a_backlog_is_deleted_in_batches_in_one_run(): void {
		for ( $receipt = 0; $receipt < 5; $receipt++ ) {
			$this->plantReceipt( 'evt_backlog_' . $receipt, -1 - $receipt );
		}

		$live = $this->plantReceipt( 'evt_live', 3600 );
		$log  = $this->captureQueries( fn() => ( new WebhookReceiptRetention( $this->receipts, 2 ) )->handle( array() ) );

		$this->assertSame( array( $live ), $this->remaining() );
		$this->assertQueryCount( 3, $log->ofType( 'DELETE' ), 'Five expired receipts in batches of two' );
	}

	/**
	 * Tests the job's schedule and listing, and that a receipt lives the period of its retention policy, as recording it writes.
	 *
	 * Planted violation: set MysqlWebhookReceipts::RETENTION_SECONDS one second short of thirty
	 * days: the receipts then live another period than their policy says.
	 *
	 * @since 0.2.0
	 */
	public function test_the_job_runs_hourly_and_receipts_live_their_retention_period(): void {
		$this->assertSame( array( 'webhook_receipts.prune', 3600, 1 ), array( WebhookReceiptRetention::name(), WebhookReceiptRetention::recurrence(), WebhookReceiptRetention::maxAttempts() ) );
		$this->assertContains( WebhookReceiptRetention::class, JobHandlers::PRODUCTION );
		$this->assertSame( MysqlWebhookReceipts::RETENTION_SECONDS, LogRetention::seconds( ( new RetentionCatalog() )->defaults( WebhookReceiptTables::RETENTION )['all'] ) );
		$this->assertSame( WebhookReceiptTables::RETENTION, WebhookReceiptTables::receipts()->retention() );

		$receipt = $this->receipts->record( 'stub', Mode::Test, 'evt_period', 'dispute.created', null, str_repeat( 'a', 64 ), '' );
		$lives   = (int) $this->db->fetchValue( 'SELECT TIMESTAMPDIFF( SECOND, received_at, expires_at ) FROM %i WHERE id = %d', $this->table( WebhookReceiptTables::RECEIPTS ), $receipt->id );

		$this->assertEqualsWithDelta( MysqlWebhookReceipts::RETENTION_SECONDS, $lives, 1, 'A receipt expires its retention period after it was received.' );
	}

	/**
	 * Plants a settled receipt whose expiry is a number of seconds from now, by the database clock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $eventId The event.
	 * @param int    $seconds How long it has left; below 0, how long ago it expired.
	 * @return int The receipt's row.
	 */
	private function plantReceipt( string $eventId, int $seconds ): int {
		$receipt = $this->receipts->record( 'stub', Mode::Test, $eventId, 'dispute.created', null, str_repeat( 'a', 64 ), '' );

		$this->receipts->settle( $receipt->id, ReceiptDecision::ignored( 'dispute.created' ) );
		$this->db->execute( 'UPDATE %i SET expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND WHERE id = %d', $this->table( WebhookReceiptTables::RECEIPTS ), $seconds, $receipt->id );

		return $receipt->id;
	}

	/**
	 * Lists the receipts left, by row.
	 *
	 * @since 0.2.0
	 *
	 * @return list<int> The rows, in order.
	 */
	private function remaining(): array {
		return array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT id FROM %i ORDER BY id', $this->table( WebhookReceiptTables::RECEIPTS ) ), 'id' ) );
	}
}

<?php
/**
 * Tests the query plans of the payment module's reads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundCapPolicy;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Refund\RequestKey;
use SEOCart\Payment\Domain\Webhook\ReceiptDecision;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Payment\Infrastructure\Jobs\WebhookReceiptRetention;
use SEOCart\Payment\Infrastructure\Migrations\CreateWebhookReceipts;
use SEOCart\Payment\Infrastructure\MysqlWebhookReceipts;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\ReplayingGateway;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\PaymentTestCase;
use SEOCart\Tests\Support\Payment\TestGateways;
use SEOCart\Tests\Support\QueryPlan\AllowList;
use SEOCart\Tests\Support\QueryPlan\PlanRecorder;
use SEOCart\Tests\Support\QueryPlan\QueryPlan;
use SEOCart\Tests\Support\QueryPlan\ReadInventory;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The run counts the rows of the tables it explains, directly and unrecorded.

/**
 * Every SELECT the payment module's source writes is sent, explained and judged by the query-plan rule.
 *
 * The payment module's reads run over a PlanRecorder: the locked reads of an approval and a
 * duplicate's read of the first row, a capture's plain reads, a refund's reads, its key's claim and
 * its duplicate's read of the first document, the read of a refund's claim, what a user asked in
 * the last 24 hours under the user's lock row, reconciliation's stale intents, a resume's read of
 * one intent, a claim read back with what it asked, and every line of doctor's payment check, the
 * refund claims never settled and the flagged orders' results applied to nothing among them. Each
 * plugin SELECT is explained, printed and judged as the order module's are; the reference dataset has no payments, so the tables stay under the size at
 * which the rule gates and the run records the plans. And every SELECT the module's source writes
 * must have been sent (ReadInventory), so no read goes unexplained.
 *
 * It runs only when SEOCART_QUERY_PLANS is 1, as `composer test:query-plans` sets it.
 *
 * Planted violations, each shown red and removed: leave reconciliation's stale intents out of
 * exercise(): the run names MysqlPaymentRepository's STALE_INTENTS as a read it did not send; leave
 * the read of a refund's claim out: it names MysqlRefundRepository's FIND_CLAIM; leave the open intents out:
 * it names MysqlPaymentRepository's OPEN_INTENTS; leave what a user asked in the last 24 hours out: it names
 * MysqlRefundRepository's ASKED_TODAY; leave the resume's read of one intent out: it names
 * MysqlPaymentRepository's FIND_INTENT_REF.
 *
 * @group performance
 *
 * @since 0.1.0
 */
final class PaymentQueryPlanTest extends PaymentTestCase {

	/**
	 * Skips the test unless the run is switched on.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		if ( '1' !== getenv( 'SEOCART_QUERY_PLANS' ) ) {
			$this->markTestSkipped( 'The query-plan run is `composer test:query-plans`.' );
		}

		( new CreateWebhookReceipts() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Tests that every read of the payment module is sent and keeps the query-plan rule, or is allowed with its reason.
	 *
	 * @since 0.1.0
	 */
	public function test_every_payment_read_is_sent_and_keeps_the_rule(): void {
		global $wpdb;

		$allowed  = AllowList::load( dirname( __DIR__, 3 ) . '/' . AllowList::FILE );
		$recorder = PlanRecorder::open();

		try {
			$this->exercise( new Database( $recorder, true, $this->reporter() ) );
		} finally {
			$recorder->close();
		}

		$report   = array();
		$breaking = array();

		foreach ( $recorder->statements() as $statement ) {
			$plan    = QueryPlan::explain( $wpdb, $statement, static fn( string $table ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
			$verdict = array() === $plan->breaches ? 'ok' : ( isset( $allowed[ $statement->id() ] ) ? 'allowed' : 'BREAKS THE RULE' );

			if ( 'BREAKS THE RULE' === $verdict ) {
				$breaking = array_merge( $breaking, $plan->lines( $verdict ) );
			}

			$report = array_merge( $report, $plan->lines( $verdict ) );
		}

		fwrite( STDOUT, sprintf( "\nThe plans of the payment module's %d SELECTs, and the order reads its check sends:\n%s\n", count( $recorder->statements() ), implode( "\n", $report ) ) );

		$heads  = ReadInventory::of( dirname( __DIR__, 3 ) . '/src/Payment' );
		$tables = array_map( fn( string $name ): string => $this->table( $name ), PaymentTables::moduleNames() );

		$this->assertSame( array(), ReadInventory::unsent( $heads, $recorder->allSent() ), 'A read the payment module\'s source writes was not sent; send it in exercise(), so its plan is judged.' );
		$this->assertSame( array(), ReadInventory::unknown( $heads, $recorder->allSent(), $tables ), 'A read of the payment tables came from outside src/Payment.' );
		$this->assertSame( array(), $breaking, sprintf( "These payment SELECTs break the query-plan rule:\n%s\n", implode( "\n", $breaking ) ) );
	}

	/**
	 * Runs the payment module's reads through the Database under test.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The Database over the recorder.
	 */
	private function exercise( Database $db ): void {
		$ids      = new SequentialIdGenerator( 600000 );
		$payments = $this->paymentsOver( $db, $ids, new StubGateway() );
		$orders   = $this->ordersOver( $db, $ids );
		$document = NewOrders::forTwoLines( self::CURRENCY, self::BASE );

		// Placing an order, and its intent at the rate the order was placed at.
		$inserted = $db->transaction( static fn() => $orders->insert( $document, Actor::user( 0 ) ) );
		$intent   = $db->transaction( fn(): IntentRef => $payments->createIntent( $inserted->id, StubGateway::ID, Mode::Test, $document->totals->grandTotal, $document->totals->baseGrandTotal, $inserted->conversionContextId ) );

		// An approval's locked reads; the same approval again, whose duplicate reads the first row.
		$approval = $payments->authorize( $intent->uuid, array( PaymentService::PAYMENT_TOKEN => StubGateway::APPROVE ), $inserted->uuid, $inserted->orderNumber );

		$db->transaction( static fn(): Application => $payments->applyGatewayResult( $approval, Actor::system( 'payment', 3 ) ) );
		$db->transaction( static fn(): Application => $payments->applyGatewayResult( $approval, Actor::system( 'payment', 3 ) ) );

		// A shopper's resume reads the intent as reconciliation sees it, by its uuid; a webhook naming only the provider's
		// reference to it, by that reference.
		$payments->intentRef( $intent->uuid );
		$payments->intentByProvider( StubGateway::ID, (string) $approval->providerIntentId );

		// A webhook's receipt, recorded and then met by a second delivery of the event, which reads it by its key.
		$receipts = new MysqlWebhookReceipts( $db );
		$receipt  = $receipts->record( StubGateway::ID, Mode::Test, 'evt_plan', 'capture.approved', null, str_repeat( 'a', 64 ), '' );

		$receipts->settle( $receipt->id, ReceiptDecision::ignored( 'capture.approved' ) );
		$receipts->record( StubGateway::ID, Mode::Test, 'evt_plan', 'capture.approved', null, str_repeat( 'a', 64 ), '' );

		// A capture's plain reads of the intent and of its unreconciled rows.
		$payments->capture( $intent->uuid, $this->userWithRole() );

		// A refund's reads: of the order, its intent with its unreconciled rows, and what earlier refunds returned of its lines,
		// its components and its shipping; then another refund the gateway answers with the first, whose duplicate reads the
		// first document before it is refused. The line's uuid is the test's read.
		$refunds = new RefundService(
			new MysqlRefundRepository( $db ),
			new MysqlOrderRepository( new OrderStatements( $db ), $ids ),
			$payments,
			TestGateways::of( new ReplayingGateway( new StubGateway() ) ),
			$db,
			$this->publisherOver( $db ),
			new Authorizer( new CapabilityDeclaration() ),
			FrozenClock::at( self::NOW ),
			new RefundCapPolicy( new SettingsStore( Settings::registry(), $db ) ),
			$orders
		);
		$line    = (string) $this->db->fetchValue( 'SELECT line_uuid FROM %i WHERE order_id = %d ORDER BY sort_order LIMIT 1', $this->table( OrderTables::LINES ), $inserted->id );

		// The first refund is asked with an idempotency key, which it looks up first, by the key's unique hash.
		$agent = $this->userWithRole();
		$first = $refunds->refund( new RefundRequest( $inserted->uuid, array( new RefundLineRequest( $line, 1 ) ), true, 'customer_return' ), $agent, new RequestKey( hash( 'sha256', 'a key' ), hash( 'sha256', 'a request' ) ) );

		// What a user whose refunds are capped by the day asked in the last 24 hours, under the user's lock row.
		$db->transaction(
			static function () use ( $db, $agent ): Money {
				$statements = new MysqlRefundRepository( $db );

				$statements->lockActor( $agent->userId() );

				return $statements->askedToday( $agent->userId(), Currency::of( self::BASE ) );
			}
		);

		// The read of a refund's claim that a request for a refund already claimed sends, by the claim's unique uuid; and the
		// claim read back with what it asked, which a settlement works the refund out again from.
		( new MysqlRefundRepository( $db ) )->findClaim( $first->uuid );
		( new MysqlRefundRepository( $db ) )->claimRequest( $first->uuid );

		// The read of the ledger's key a settlement sends for the provider's refund a person names, the ledger's own read of it.
		( new MysqlRefundRepository( $db ) )->holdsResult( $approval );

		try {
			$refunds->refund( new RefundRequest( $inserted->uuid, array( new RefundLineRequest( $line, 1 ) ), false, 'customer_return' ), $this->userWithRole() );
			$this->fail( 'Another refund was answered with the first one\'s document.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::Unreconciled, $refused->errorCode() );
		}

		// Reconciliation's stale intents.
		$payments->staleIntents( 600, 50 );

		// The open intents of every gateway, counted once for the gateways' status and doctor's gateways check.
		( new MysqlPaymentRepository( $db, $ids ) )->openIntents();

		// Every line of doctor's payment check, which reads the order tables through the order repository, the refund
		// claims never settled and the webhook receipts; and the results applied to nothing of the orders still flagged,
		// which the check reads only when an order is, here sent for the order refunded.
		( new PaymentLedgerCheck( new MysqlPaymentRepository( $db, $ids ), new MysqlOrderRepository( new OrderStatements( $db ), $ids ), receipts: $receipts ) )->run();
		( new MysqlPaymentRepository( $db, $ids ) )->unappliedResults( array( $inserted->id ), PaymentLedgerCheck::LIMIT );

		// When the newest result of the order applied to nothing landed, which a clearance of its flag is dated after.
		( new MysqlPaymentRepository( $db, $ids ) )->newestAt( $inserted->id );
	}

	/**
	 * Tests that the prune of the webhook receipts deletes on the expiry key, a batch at a time, never by a scan.
	 *
	 * A DELETE is not one of the reads the run sends, so its plan is read here: with a few hundred
	 * receipts, the statement must still find its rows by `expires_at`.
	 *
	 * Planted violation: in MysqlWebhookReceipts::DELETE_EXPIRED, delete by reception
	 * (`WHERE received_at <= UTC_TIMESTAMP() - INTERVAL 30 DAY ORDER BY received_at`): no key serves it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_receipts_prune_deletes_on_the_expiry_key(): void {
		global $wpdb;

		$receipts = new MysqlWebhookReceipts( $this->db );

		for ( $event = 0; $event < 300; $event++ ) {
			$receipts->record( StubGateway::ID, Mode::Test, 'evt_' . $event, 'capture.approved', null, str_repeat( 'a', 64 ), '' );
		}

		$this->db->execute( 'ANALYZE TABLE %i', $this->table( WebhookReceiptTables::RECEIPTS ) );

		list( $sql, $arguments ) = MysqlWebhookReceipts::expand( MysqlWebhookReceipts::DELETE_EXPIRED, array( WebhookReceiptRetention::BATCH ), fn( string $name ): string => $this->table( $name ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the repository's constant, expanded; this is its prepare step.
		$plan = (array) $wpdb->get_row( 'EXPLAIN ' . $wpdb->prepare( $sql, ...$arguments ), ARRAY_A );

		$this->assertSame( 'expires_at', $plan['key'] ?? null, 'The prune\'s plan: ' . (string) wp_json_encode( $plan ) );
	}
}

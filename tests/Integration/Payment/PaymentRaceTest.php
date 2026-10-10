<?php
/**
 * Tests a capture and a void of one payment racing on two connections, a capture asked twice, and a capture killed after its provider made it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;

use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\FileProviderGateway;
use SEOCart\Tests\Support\Doubles\RecordingGateway;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\RunningProbe;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The provider's memory is a temporary file the test removes.

/**
 * A capture and a void of one payment take no idempotency key: the payment's state, the key the provider is sent, derived from the payment, and the ledger's unique key make whatever runs together end in one movement of money.
 *
 * Side A runs the operation's service in this process; side B runs it in a probe, a process of its
 * own with its own connection, as `wp seocart payment capture|void` would. Both ask a provider whose
 * memory is a file they share, which serializes what it is asked about a payment as a real provider
 * does: a capture after a cancel is declined, a cancel after a capture answers the authorization it
 * keeps, and a capture asked again with the same key is answered with the capture it made. The
 * barrier is A's lock of the order, after A has locked the intent: B is started there, and A goes on
 * once the server shows B waiting for the intent's lock. Never a pause.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlPaymentRepository::LOCK_INTENT, drop `FOR UPDATE`: B never waits for A, so the races
 *   never meet at the intent and the server never shows B waiting;
 * - in PaymentService::askCapture(), skip the intent's state check: a capture asked again after it
 *   was made asks the provider again;
 * - in PaymentTables, drop the ledger's `provider_object_operation` unique key: the capture B raced
 *   is kept as a second row, and the order flagged;
 * - in CaptureRequest::idempotencyKey(), add a suffix that differs per call: the capture asked again
 *   after the crash makes a second capture at the provider.
 *
 * @group concurrency
 *
 * @since 0.2.0
 */
final class PaymentRaceTest extends PlacementTestCase {

	use CreatesUsers;
	use GrantsCapabilities;

	/**
	 * A's lock of the order, which a payment takes right after the intent's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ORDER_LOCK = '/^SELECT id, uuid, order_number, .* FOR UPDATE$/s';

	/**
	 * The process-list state of the intent's lock while it waits for the row: the read is by a unique key, a constant row the optimizer reads while it plans, so it waits in `statistics` (MySQL 8.4, measured).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WAITING_READ = 'statistics';

	/**
	 * The provider's memory, a temporary file.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $provider = '';

	/**
	 * The person who captures and voids, granted both capabilities, in this process and in the probe's.
	 *
	 * @since 0.2.0
	 *
	 * @var Actor
	 */
	private Actor $person;

	/**
	 * Creates the provider's memory.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->provider = (string) tempnam( sys_get_temp_dir(), 'seocart-provider-' );
	}

	/**
	 * Removes the provider's memory and the users the test created.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		if ( is_file( $this->provider ) ) {
			unlink( $this->provider );
		}

		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests a void (A) against a capture (B), the provider cancelling first: B's capture is declined, and its decline, for a voided payment, is stale. One new ledger row, the void; B refused with where the payment stands.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_against_a_capture_ends_voided(): void {
		list( $order, $intent ) = $this->authorizedPlacement();

		$capture = null;
		$this->raceOnTheIntent( $intent, 'capture', $capture );

		$voided = $this->services()->voidPayment(
			array(
				'intent_uuid' => $intent,
				'reason'      => 'customer_request',
			),
			$this->person
		);
		$report = $capture?->finish() ?? array();

		$this->assertSame( array( 'applied', 'voided' ), array( $voided['outcome'], $voided['status'] ) );
		$this->assertSame( array( 'payment.not_capturable', array( 'status' => 'voided' ) ), array( $report['refused'] ?? $report, $report['context'] ?? null ), (string) wp_json_encode( $report ) );
		$this->assertSame( 'authorize:approved:1,void:approved:1', $this->ledger( $order ), 'The authorization, and the void: the stale decline wrote nothing.' );
		$this->assertSame( 0, FileProviderGateway::capturesMade( $this->provider ), 'The provider captured nothing.' );
	}

	/**
	 * Tests a capture (A) against a void (B), the provider capturing first: B's void is answered with the authorization, which the ledger has. One new row, B refused with where the payment stands.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_against_a_void_ends_captured(): void {
		list( $order, $intent ) = $this->authorizedPlacement();

		$void = null;
		$this->raceOnTheIntent( $intent, 'void', $void );

		$captured = $this->services()->capturePayment( array( 'intent_uuid' => $intent ), $this->person );
		$report   = $void?->finish() ?? array();

		$this->assertSame( array( 'applied', 'captured' ), array( $captured['outcome'], $captured['status'] ) );
		$this->assertSame( array( 'payment.not_voidable', array( 'status' => 'captured' ) ), array( $report['refused'] ?? $report, $report['context'] ?? null ), (string) wp_json_encode( $report ) );
		$this->assertSame( 'authorize:approved:1,capture:approved:1', $this->ledger( $order ) );
	}

	/**
	 * Tests a capture asked again after the first was made: refused with what was captured, which reads as done, and the provider asked once.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_asked_again_after_it_was_made_reads_as_done(): void {
		list( $order, $intent ) = $this->authorizedPlacement();

		$this->services()->capturePayment( array( 'intent_uuid' => $intent ), $this->person );

		$report = $this->paymentProbe( 'capture', $intent )->finish();

		$amount = (int) $this->db->fetchValue( 'SELECT amount_minor FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intent );

		$this->assertSame( array( 'payment.not_capturable', array( 'status' => 'captured' ), array( 'captured' => $amount ) ), array( $report['refused'] ?? $report, $report['context'] ?? null, array_intersect_key( (array) ( $report['details'] ?? array() ), array( 'captured' => true ) ) ), (string) wp_json_encode( $report ) );
		$this->assertSame( 'authorize:approved:1,capture:approved:1', $this->ledger( $order ) );
		$this->assertSame( 1, FileProviderGateway::capturesAsked( $this->provider ), 'The provider was asked once.' );
	}

	/**
	 * Tests a capture asked twice at once: both pass the plain read, the provider answers both with the one capture it made, and the ledger's key makes the second a duplicate. One row, the second answered 200 duplicate.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_asked_twice_at_once_is_one_capture(): void {
		list( $order, $intent ) = $this->authorizedPlacement();

		$again = null;
		$this->raceOnTheIntent( $intent, 'capture', $again );

		$first  = $this->services()->capturePayment( array( 'intent_uuid' => $intent ), $this->person );
		$report = $again?->finish() ?? array();
		$second = json_decode( (string) ( $report['answer_json'] ?? '' ), true );

		$this->assertSame( 'applied', $first['outcome'] );
		$this->assertSame( array( 'duplicate', 'captured' ), array( $second['outcome'] ?? $report, $second['status'] ?? null ), (string) wp_json_encode( $report ) );
		$this->assertSame( 'authorize:approved:1,capture:approved:1', $this->ledger( $order ) );
		$this->assertSame( '0', (string) $this->committedOrder( $this->secondConnection(), $order )['has_unreconciled_money'], 'Nothing for a person to settle.' );
		$this->assertSame( 1, FileProviderGateway::capturesMade( $this->provider ), 'The provider made one capture.' );
	}

	/**
	 * Tests a capture killed once its provider made it, before anything was recorded: the payment stays authorized with no row, and the capture asked again sends the same key, so the provider answers the capture it made, recorded once.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_killed_after_the_provider_made_it_is_recorded_once_when_asked_again(): void {
		list( $order, $intent ) = $this->authorizedPlacement();

		$killed = $this->paymentProbe( 'capture-and-die', $intent );

		while ( ! $killed->watch( 50 ) ) {
			continue;
		}

		$this->assertSame( '', $killed->reportSoFar(), "The capture answered: it was not killed.\n" . $killed->output() );
		$this->assertSame( array( 'authorized', 'authorize:approved:1' ), array( $this->db->fetchValue( 'SELECT status FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intent ), $this->ledger( $order ) ), "The capture was recorded: the process was not killed.\n" . $killed->output() );
		$this->assertSame( 1, FileProviderGateway::capturesMade( $this->provider ), 'The provider made the capture.' );

		$captured = $this->services()->capturePayment( array( 'intent_uuid' => $intent ), $this->person );

		$this->assertSame( array( 'applied', 'captured' ), array( $captured['outcome'], $captured['status'] ) );
		$this->assertSame( 'authorize:approved:1,capture:approved:1', $this->ledger( $order ) );
		$this->assertSame( 1, FileProviderGateway::capturesMade( $this->provider ), 'Asked again with the same key, the provider made no second capture.' );
	}

	/**
	 * Places an order whose payment the stub authorizes, and grants the person who captures and voids it both capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: string, 1: string} The order's uuid and the intent's uuid.
	 */
	private function authorizedPlacement(): array {
		$this->person = $this->userGranted( PaymentService::CAPTURE_CAPABILITY, PaymentService::VOID_CAPABILITY );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$answer = $this->placement->place( $this->placeInput(), self::guest() );
		$intent = (string) $this->db->fetchValue( 'SELECT i.uuid FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $answer['order_uuid'] );

		$this->assertSame( 'approved', $answer['outcome'] );

		return array( (string) $answer['order_uuid'], $intent );
	}

	/**
	 * Returns the payment service of side A, whose gateway asks the provider the probe shares.
	 *
	 * @since 0.2.0
	 *
	 * @return PaymentService The service.
	 */
	private function services(): PaymentService {
		$kernel = PlacementKernel::over(
			$this->db,
			$this->tokens,
			$this->identities,
			$this->wake,
			$this->reporter(),
			array( PaymentGateway::class => fn(): PaymentGateway => new RecordingGateway( new FileProviderGateway( new StubGateway(), $this->provider ), $this->db ) )
		);

		return $kernel->get( PaymentService::class );
	}

	/**
	 * Starts side B at A's lock of the order: B's operation runs until it waits for the intent's lock, and A goes on.
	 *
	 * @since 0.2.0
	 *
	 * @param string            $intentUuid The payment.
	 * @param string            $mode       B's operation: `capture` or `void`.
	 * @param RunningProbe|null $probe      Receives B.
	 */
	private function raceOnTheIntent( string $intentUuid, string $mode, ?RunningProbe &$probe ): void {
		$this->beforeStatement(
			self::ORDER_LOCK,
			function () use ( $intentUuid, $mode, &$probe ): void {
				$probe = $this->paymentProbe( $mode, $intentUuid );

				$this->awaitProbeSending( $probe, $this->lockIntent( $intentUuid ), self::WAITING_READ );
			}
		);
	}

	/**
	 * Starts a capture, a void or a capture killed after its provider made it, in a probe, for the person, against the shared provider.
	 *
	 * @since 0.2.0
	 *
	 * @param string $mode       The probe's mode.
	 * @param string $intentUuid The payment.
	 * @return RunningProbe The probe.
	 */
	private function paymentProbe( string $mode, string $intentUuid ): RunningProbe {
		return $this->startPaymentProbe(
			$mode,
			array(
				'intent_uuid'   => $intentUuid,
				'user_id'       => $this->person->userId(),
				'reason'        => 'customer_request',
				'provider_file' => $this->provider,
			)
		);
	}

	/**
	 * Returns the intent's lock as the server shows it while a probe sends it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return string The statement.
	 */
	private function lockIntent( string $intentUuid ): string {
		global $wpdb;

		list( $sql, $arguments ) = MysqlPaymentRepository::expand( MysqlPaymentRepository::LOCK_INTENT, array( $intentUuid ), fn( string $name ): string => $this->table( $name ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the module's constant, expanded; this is its prepare step.
		return $wpdb->remove_placeholder_escape( (string) $wpdb->prepare( $sql, ...$arguments ) );
	}

	/**
	 * Reads an order's committed ledger rows, as connection B sees them: each as operation, result and whether it was applied, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @return string|null The rows, comma-separated.
	 */
	private function ledger( string $orderUuid ): ?string {
		return $this->secondConnection()->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT_WS( ':', t.operation, t.result, t.applied ) ORDER BY t.id ) FROM `%s` t JOIN `%s` o ON o.id = t.order_id WHERE o.uuid = '%s'", $this->table( PaymentTables::TRANSACTIONS ), $this->table( OrderTables::ORDERS ), $orderUuid ) );
	}
}

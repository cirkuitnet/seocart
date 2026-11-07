<?php
/**
 * Tests the offline payment methods' money operations: the payment received with what a person states of it, the void, the refunds, the wait, the switch and Safe Mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\GatewaySwitches;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\OfflineMethod;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Infrastructure\Cli\GatewayCommand;
use SEOCart\Payment\Infrastructure\Doctor\GatewaysCheck;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\Migrations\CreateOfflinePayments;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\AnswerLosingGateway;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\Payment\GatewayCommandRecorder;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\OfflineGateways;
use SEOCart\Tests\Support\QueryLog;
use SEOCart\Tests\Support\RunningProbe;

/**
 * An offline payment is placed as a promise to pay, received when a person records it with the day it arrived, and refunded as money returned outside a provider, each through the one money path.
 *
 * The orders are placed through the store's own placement, paid by bank transfer unless a test
 * says otherwise, with the offline payment methods inside recording doubles, so a test counts
 * what each was asked. A second side, where a test needs one, runs the capture operation's service
 * in a probe, a process of its own with its own connection, as `wp seocart payment capture` would.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentService::recordCapture(), insert the statement after the transaction that applies the
 *   capture: a second connection sees the capture before its statement is written;
 * - in PaymentService::requireEvidence(), invert the offline check: an offline payment given no day
 *   reaches the gateway, and a provider's given one is refused missing.
 *
 * @since 0.2.0
 */
final class OfflineMethodsTest extends PlacementTestCase {

	use CreatesUsers;
	use GrantsCapabilities;

	/**
	 * The order's lock, which a payment takes right after the intent's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ORDER_LOCK = '/^SELECT id, uuid, order_number, .* FOR UPDATE$/s';

	/**
	 * The process-list state of the intent's lock while it waits for the row, as the payment race test measured it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WAITING_READ = 'statistics';

	/**
	 * A published test card number, which a reference or a note must never hold.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CARD_NUMBER = '4242 4242 4242 4242';

	/**
	 * The offline payment methods inside their recording doubles.
	 *
	 * @since 0.2.0
	 *
	 * @var OfflineGateways|null
	 */
	private ?OfflineGateways $offline = null;

	/**
	 * Whether the requests built from now on are in Safe Mode, forced.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $safeMode = false;

	/**
	 * The person who records, voids and refunds, granted the capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @var Actor
	 */
	private Actor $person;

	/**
	 * Creates the table of payments received offline, and the person.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateOfflinePayments() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$this->person = $this->userGranted( PaymentService::CAPTURE_CAPABILITY, PaymentService::VOID_CAPABILITY );
	}

	/**
	 * Removes the users the test created, the methods' settings and switches, and the offline payment methods' recorders.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		global $wpdb;

		$this->deleteCreatedUsers();
		GatewayKernel::deleteOptions();
		$wpdb->delete( $wpdb->options, array( 'option_name' => BootOption::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removes the record the switch's test planted.

		$this->offline  = null;
		$this->safeMode = false;

		parent::tear_down();
	}

	/**
	 * Builds the kernel with the offline payment methods recorded, in Safe Mode while `$safeMode` is true.
	 *
	 * @since 0.2.0
	 *
	 * @param Database       $db     The connection.
	 * @param FakeCartTokens $tokens The cart token the request presents.
	 * @return Container The container.
	 */
	protected function kernelOver( Database $db, FakeCartTokens $tokens ): Container {
		$this->offline ??= new OfflineGateways( $db );

		return PlacementKernel::over(
			$db,
			$tokens,
			$this->identities,
			$this->wake,
			$this->reporter(),
			array(
				Gateways::class => fn( Container $c ): Gateways => $this->offline->registryOver( $c ),
				SafeMode::class => fn( Container $c ): SafeMode => new SafeMode( $c->get( BootOption::class ), $c->get( Clock::class ), $this->safeMode ? true : null ),
			)
		);
	}

	/**
	 * Tests a void of a promise: the payment voided, the accepted order left to the person with its units allocated, PaymentVoided published once.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_ends_the_promise_and_leaves_the_order_to_the_person(): void {
		$placed    = $this->placeOffline( 'cheque' );
		$allocated = $this->allocationsOf( $placed['variant'] );

		$this->assertNotSame( array(), $allocated, 'The promise allocated the order\'s units.' );

		$answer = $this->payments()->voidPayment(
			array(
				'intent_uuid' => $placed['intent_uuid'],
				'reason'      => 'customer_request',
			),
			$this->person
		);

		$this->assertSame( array( 'applied', 'voided', 'voided' ), array( $answer['outcome'], $answer['status'], $answer['payment_status'] ) );
		$this->assertSame( array( 'processing', 'voided' ), $this->orderStatuses( $placed['order_id'] ), 'The order is left to the person who voided.' );
		$this->assertSame( $allocated, $this->allocationsOf( $placed['variant'] ), 'Its units stay allocated.' );
		$this->assertSame( 'authorize:approved:1,void:approved:1', $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( 1, $this->committedEventRows( $this->secondConnection(), 'payment_voided' ) );
	}

	/**
	 * Tests the refunds of a payment received: a part, then the rest, each money returned outside a provider, each its own object; a stand-in's refund still is not.
	 *
	 * @since 0.2.0
	 */
	public function test_refunds_of_a_payment_received_are_money_returned_outside_a_provider(): void {
		$placed  = $this->placeOffline();
		$standIn = $this->placeStandIn();
		$refunds = $this->kernel->get( RefundService::class );
		$person  = $this->userGranted( PaymentService::CAPTURE_CAPABILITY, RefundService::CAPABILITY );

		$this->payments()->capturePayment( self::received( $placed, $this->databaseDay( 0 ), 'TRF 1' ), $person );
		$this->payments()->capturePayment( array( 'intent_uuid' => $standIn['intent_uuid'] ), $person );

		$part = $refunds->refund( new RefundRequest( $placed['order_uuid'], array( new RefundLineRequest( $this->lineOf( $placed['order_id'] ), 1 ) ), false, 'customer_return' ), $person );
		$rest = $refunds->refund( new RefundRequest( $placed['order_uuid'], array(), true, 'customer_return' ), $person );

		$refunds->refund( new RefundRequest( $standIn['order_uuid'], array( new RefundLineRequest( $this->lineOf( $standIn['order_id'] ), 1 ) ), false, 'customer_return' ), $person );

		$this->assertSame( array( '1', '1' ), $this->offlineFlags( $placed['order_id'] ), 'Each refund of the payment received is money returned outside a provider.' );
		$this->assertSame( array( '0' ), $this->offlineFlags( $standIn['order_id'] ), 'A provider\'s refund is not.' );
		$this->assertSame( 'authorize:approved:1,capture:approved:1,refund:approved:1,refund:approved:1', $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame(
			array( 'bank_transfer-refund-' . $part->uuid, 'bank_transfer-refund-' . $rest->uuid ),
			array_column( $this->db->fetchAll( "SELECT provider_object_id FROM %i WHERE order_id = %d AND operation = 'refund' ORDER BY id", $this->table( PaymentTables::TRANSACTIONS ), $placed['order_id'] ), 'provider_object_id' )
		);
		$this->assertSame( array( 'refunded', 'refunded' ), array( $this->intentStatus( $placed['intent_uuid'] ), $this->orderStatuses( $placed['order_id'] )[1] ) );
	}

	/**
	 * Tests a refund whose answer was lost: its claim waits for a person, a retry is refused unresolved, and the person settles it, refunded by statement on one payment, and not refunded on another, whose units are then refunded again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_whose_answer_was_lost_waits_for_a_person(): void {
		$this->offline   = new OfflineGateways( $this->db, static fn( PaymentGateway $method ): PaymentGateway => new AnswerLosingGateway( $method ) );
		$this->placement = $this->freshPlacement();

		$person   = $this->userGranted( PaymentService::CAPTURE_CAPABILITY, RefundService::CAPABILITY, RefundService::SETTLE_CAPABILITY );
		$refunds  = $this->kernel->get( RefundService::class );
		$recorded = $this->placeOffline();
		$declined = $this->placeOffline( 'cheque' );

		foreach ( array( $recorded, $declined ) as $placed ) {
			$this->payments()->capturePayment( self::received( $placed, $this->databaseDay( 0 ), 'TRF' ), $person );

			$request = new RefundRequest( $placed['order_uuid'], array( new RefundLineRequest( $this->lineOf( $placed['order_id'] ), 1 ) ), false, 'customer_return' );

			try {
				$refunds->refund( $request, $person );
				$this->fail( 'The refund\'s answer was not lost.' );
			} catch ( GatewayUnavailable $lost ) {
				unset( $lost );
			}

			try {
				$refunds->refund( $request, $person );
				$this->fail( 'A refund whose answer was lost was asked again.' );
			} catch ( CodedException $unresolved ) {
				$this->assertSame( PaymentError::RefundUnresolved, $unresolved->errorCode() );
			}
		}

		$claimOf = fn( array $placed ): string => (string) $this->db->fetchValue( "SELECT uuid FROM %i WHERE order_id = %d AND state = 'claimed'", $this->table( RefundClaimTables::CLAIMS ), $placed['order_id'] );
		$amount  = (int) $this->db->fetchValue( "SELECT amount_minor FROM %i WHERE order_id = %d AND state = 'claimed'", $this->table( RefundClaimTables::CLAIMS ), $recorded['order_id'] );
		$settled = $refunds->settleRefundClaim(
			array(
				'refund_uuid'        => $claimOf( $recorded ),
				'statement'          => ClaimStatement::REFUNDED,
				'provider_refund_id' => 'CHQ-000123',
				'amount_minor'       => $amount,
				'note'               => 'The refund cheque was posted.',
			),
			$person
		);

		$this->assertSame( array( 'recorded', 'statement' ), array( $settled['state'], $settled['decided_by'] ) );
		$this->assertSame( array( '1' ), $this->offlineFlags( $recorded['order_id'] ), 'The refund a person stated made is money returned outside a provider.' );

		$notMade = $refunds->settleRefundClaim(
			array(
				'refund_uuid' => $claimOf( $declined ),
				'statement'   => ClaimStatement::NOT_REFUNDED,
				'note'        => 'No refund was sent.',
			),
			$person
		);

		$this->assertSame( 'declined', $notMade['state'] );
		$this->assertSame( array(), $this->offlineFlags( $declined['order_id'] ) );

		$again = $refunds->refund( new RefundRequest( $declined['order_uuid'], array( new RefundLineRequest( $this->lineOf( $declined['order_id'] ), 1 ) ), false, 'customer_return' ), $person );

		$this->assertSame( 'cheque-refund-' . $again->uuid, $this->db->fetchValue( "SELECT provider_object_id FROM %i WHERE order_id = %d AND operation = 'refund'", $this->table( PaymentTables::TRANSACTIONS ), $declined['order_id'] ), 'The same units were refunded again.' );
		$this->assertSame( array( '1' ), $this->offlineFlags( $declined['order_id'] ) );
	}

	/**
	 * Tests the wait: a payment placed longer ago than the method's wait is reported by doctor and the status command, one within it is not, and nothing moves.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_past_the_wait_is_a_reminder_and_nothing_moves(): void {
		$operator = $this->userGranted( SettingsService::SECRETS_CAPABILITY );
		$file     = (string) wp_tempnam( 'seocart-offline-wait' );

		// The wait set as a merchant sets it: a file only its owner can read, given to the configure command.
		file_put_contents( $file, (string) wp_json_encode( array( OfflineMethod::WAIT_DAYS => 3 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test's own temporary file.
		chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- configure reads only a file its owner alone can read.

		try {
			$configured = ( new GatewayCommandRecorder( $this->kernel ) )->run(
				array( 'configure', 'bank_transfer' ),
				array(
					'mode'      => Mode::Live->value,
					'from-file' => $file,
				),
				$operator->userId()
			);
		} finally {
			wp_delete_file( $file );
		}

		$this->assertSame( GatewayCommand::EXIT_OK, $configured );

		$late   = $this->placeOffline();
		$recent = $this->placeOffline();
		$cheque = $this->placeOffline( 'cheque' );

		// The late one was placed four days ago, the cheque five: past bank transfer's three days, within the cheque's seven.
		$this->db->execute( 'UPDATE %i SET created_at = created_at - INTERVAL 4 DAY WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $late['intent_uuid'] );
		$this->db->execute( 'UPDATE %i SET created_at = created_at - INTERVAL 5 DAY WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $cheque['intent_uuid'] );

		$kernel  = $this->kernelOver( $this->db, $this->tokens );
		$doctor  = $kernel->get( GatewaysCheck::class )->run();
		$command = new GatewayCommandRecorder( $kernel );

		$this->assertFalse( $doctor->passed );
		$this->assertSame( array( 'Warning: 1 bank_transfer payment has waited for its money longer than the method allows: record the payment received with wp seocart payment capture, or void it. The method allows 3 days.' ), $doctor->findings, 'A reminder, never critical, of the late payment alone.' );
		$this->assertSame( GatewayCommand::EXIT_OK, $command->run( array( 'status' ) ) );
		$this->assertContains( 'Awaiting payment: bank_transfer, 1 past the wait of 3 days.', $command->lines );
		$this->assertSame( GatewayCommand::EXIT_OK, $command->run( array( 'status', 'cheque' ), array( 'format' => 'json' ) ) );
		$this->assertSame(
			array(
				'cheque' => array(
					'waiting'   => 0,
					'wait_days' => 7,
				),
			),
			json_decode( $command->last(), true )['awaiting_past_wait']
		);
		$this->assertSame( array( 'authorized', 'authorized', 'authorized' ), array( $this->intentStatus( $late['intent_uuid'] ), $this->intentStatus( $recent['intent_uuid'] ), $this->intentStatus( $cheque['intent_uuid'] ) ), 'Nothing ends a payment past its wait.' );

		$this->payments()->capturePayment( self::received( $late, $this->databaseDay( 0 ), 'late' ), $this->person );

		$this->assertTrue( $this->kernelOver( $this->db, $this->tokens )->get( GatewaysCheck::class )->run()->passed, 'Once the late payment is received, doctor passes again.' );
	}

	/**
	 * Tests a method switched off: a new placement is refused before anything is written, and its open promise is still received.
	 *
	 * @since 0.2.0
	 */
	public function test_a_switched_off_method_takes_no_new_promise_and_its_promise_is_still_received(): void {
		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn(): BootRecord => KernelTestCase::installedRecord() );

		// A request of its own, which reads the installation record planted.
		$this->placement = $this->freshPlacement();

		$open = $this->placeOffline();
		$cart = $this->readyCart( array( $this->sellable() => 1 ) );

		// A checkout that chose the method before it was switched off.
		$this->writeCheckout( $cart->version, 'bank_transfer' );

		$this->assertTrue( $this->kernel->get( GatewaySwitches::class )->disable( 'bank_transfer' ) );

		$this->placement = $this->freshPlacement();
		$input           = $this->placeInput( 'while-off' );
		$orders          = $this->committedCount( $this->secondConnection(), OrderTables::ORDERS );

		unset( $input['payment_data'] );

		try {
			$this->placement->place( $input, self::guest() );
			$this->fail( 'A switched-off method took a new promise.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( CheckoutError::PaymentMethodUnavailable, array( 'payment_method_key' => 'bank_transfer' ), array( 'reason' => 'disabled' ) ), array( $refused->errorCode(), $refused->context(), $refused->details() ) );
		}

		try {
			$this->writeCheckout( $cart->version, 'bank_transfer' );
			$this->fail( 'A switched-off method was chosen.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::InvalidMethodKey, $refused->errorCode() );
		}

		$this->assertSame( $orders, $this->committedCount( $this->secondConnection(), OrderTables::ORDERS ), 'No order was written.' );
		$this->assertSame( 'applied', $this->payments()->capturePayment( self::received( $open, $this->databaseDay( 0 ), 'received' ), $this->person )['outcome'], 'Its open promise is received.' );
	}

	/**
	 * Tests Safe Mode: an offline method is not offered, and its open promise waits, refused safe_mode with nothing asked.
	 *
	 * @since 0.2.0
	 */
	public function test_in_safe_mode_a_method_is_not_offered_and_its_promise_waits(): void {
		$open = $this->placeOffline();

		$this->safeMode  = true;
		$this->placement = $this->freshPlacement();

		$this->assertNull( $this->kernel->get( Gateways::class )->configuredMode( 'bank_transfer' ), 'Not offered: Safe Mode takes no live payment.' );

		$calls = $this->offline?->calls() ?? 0;

		try {
			$this->payments()->capturePayment( self::received( $open, $this->databaseDay( 0 ), 'received' ), $this->person );
			$this->fail( 'A live promise was recorded in Safe Mode.' );
		} catch ( CodedException $refused ) {
			$this->assertSame(
				array(
					PaymentError::GatewayUnavailable,
					array(
						'gateway_id' => 'bank_transfer',
						'reason'     => 'safe_mode',
					),
				),
				array( $refused->errorCode(), $refused->context() )
			);
		}

		$this->assertSame( $calls, $this->offline?->calls() ?? 0, 'Nothing was asked.' );
		$this->assertSame( 'authorized', $this->intentStatus( $open['intent_uuid'] ) );
	}

	/**
	 * Tests a payment received recorded with its day, its reference and a note: the payment captured, the order paid, and one record beside the capture's ledger row, written in the capture's transaction.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_received_is_recorded_with_what_was_stated_in_the_captures_transaction(): void {
		$placed = $this->placeOffline();
		$day    = $this->databaseDay( -1 );
		$seen   = null;

		$this->beforeStatement(
			'/^INSERT INTO \S*offline_payments/',
			function () use ( $placed, &$seen ): void {
				$b    = $this->secondConnection();
				$seen = array(
					$this->committedCount( $b, PaymentTables::TRANSACTIONS, sprintf( "order_id = %d AND operation = 'capture'", $placed['order_id'] ) ),
					$this->committedCount( $b, PaymentTables::OFFLINE_PAYMENTS ),
				);
			}
		);

		$answer = $this->payments()->capturePayment(
			array(
				'intent_uuid' => $placed['intent_uuid'],
				'received_on' => $day,
				'reference'   => 'TRF 000123',
				'note'        => 'Paid by the buyer\'s company.',
			),
			$this->person
		);

		$this->assertSame( array( 'applied', 'captured', 'paid' ), array( $answer['outcome'], $answer['status'], $answer['payment_status'] ) );
		$this->assertSame( array( 0, 0 ), $seen, 'A second connection saw the capture, or what was stated of it, before the capture\'s transaction committed.' );
		$this->assertSame( array( 'processing', 'paid' ), $this->orderStatuses( $placed['order_id'] ) );
		$this->assertSame( 'authorize:approved:1,capture:approved:1', $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( array( $this->evidenceRow( $placed, $day, 'TRF 000123', 'Paid by the buyer\'s company.' ) ), $this->evidenceRows( $placed['order_id'] ) );
	}

	/**
	 * Tests the same through the command's dialect: the day alone, the reference and the note left out.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_received_is_recorded_through_the_command(): void {
		$placed   = $this->placeOffline( 'cheque' );
		$day      = $this->databaseDay( -2 );
		$registry = new OperationRegistry();

		$registry->add( PaymentOperations::CAPTURE_PAYMENT, array( PaymentOperations::class, 'capturePayment' ) );

		$surfaces = new OperationSurfaces( $registry, $this->payments() );

		wp_set_current_user( $this->person->userId() );

		try {
			$missing  = $surfaces->cli( 'seocart payment capture', array( $placed['intent_uuid'] ), array() );
			$recorded = $surfaces->cli( 'seocart payment capture', array( $placed['intent_uuid'] ), array( 'received_on' => $day ) );
		} finally {
			OperationSurfaces::discard();
			wp_set_current_user( 0 );
		}

		$this->assertStringStartsWith( 'payment.evidence_missing', (string) $missing['failure'] );
		$this->assertNull( $recorded['failure'] );
		$this->assertSame( array( 'applied', 'captured', 'paid' ), array_values( array_intersect_key( (array) ( $recorded['printed']['item'] ?? array() ), array_flip( array( 'outcome', 'status', 'payment_status' ) ) ) ) );
		$this->assertSame( array( $this->evidenceRow( $placed, $day, null, null ) ), $this->evidenceRows( $placed['order_id'] ) );
	}

	/**
	 * Tests that the day a payment was received is judged in the time zone the site is set to: a day after the store's today is refused and the store's today is recorded, whichever side of UTC's midnight the store is.
	 *
	 * The zone is chosen from the hour it is now in UTC so that the store's day always differs from
	 * UTC's: from 10:00 UTC, UTC+14 is already on UTC's next day; before it, UTC-12 is still on
	 * UTC's previous day.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_received_is_judged_on_the_days_of_the_stores_time_zone(): void {
		$ahead    = (int) gmdate( 'G' ) >= 10;
		$zone     = $ahead ? 'Etc/GMT-14' : 'Etc/GMT+12';
		$today    = $this->databaseDay( $ahead ? 1 : -1 );
		$tomorrow = $this->databaseDay( $ahead ? 2 : 0 );
		$recorded = $this->placeOffline();
		$refused  = $this->placeOffline( 'cheque' );
		$before   = get_option( 'timezone_string', '' );
		$failure  = null;

		update_option( 'timezone_string', $zone );

		try {
			$answer = $this->payments()->capturePayment( self::received( $recorded, $today, 'store day' ), $this->person );

			try {
				$this->payments()->capturePayment( self::received( $refused, $tomorrow, 'store day' ), $this->person );
			} catch ( CodedException $refusal ) {
				$failure = array( $refusal->errorCode()->value, $refusal->context() );
			}
		} finally {
			update_option( 'timezone_string', $before );
		}

		$this->assertSame( array( 'applied', 'captured' ), array( $answer['outcome'], $answer['status'] ), $zone );
		$this->assertSame( array( 'payment.evidence_rejected', array( 'field' => 'received_on' ) ), $failure, $zone );
		$this->assertSame( array( $today ), array_column( $this->evidenceRows( $recorded['order_id'] ), 'received_on' ) );
		$this->assertSame( array(), $this->evidenceRows( $refused['order_id'] ) );
		$this->assertSame( 'authorized', $this->intentStatus( $refused['intent_uuid'] ) );
	}

	/**
	 * Tests each statement that cannot be recorded: refused before anything is written or asked of the gateway, an offline payment's and a provider's alike.
	 *
	 * @since 0.2.0
	 */
	public function test_a_statement_that_cannot_be_recorded_writes_nothing_and_asks_nothing(): void {
		$offline  = $this->placeOffline();
		$provider = $this->placeStandIn();
		$today    = $this->databaseDay( 0 );
		$cases    = array(
			'no day'                         => array( $offline, array(), array( 'payment.evidence_missing', array() ) ),
			'a reference alone'              => array( $offline, array( 'reference' => 'TRF 1' ), array( 'payment.evidence_missing', array() ) ),
			'a day that is no day'           => array( $offline, array( 'received_on' => '2025-02-29' ), array( 'payment.evidence_rejected', array( 'field' => 'received_on' ) ) ),
			'a day written otherwise'        => array( $offline, array( 'received_on' => '2025-2-1' ), array( 'payment.evidence_rejected', array( 'field' => 'received_on' ) ) ),
			'a day after tomorrow'           => array( $offline, array( 'received_on' => $this->databaseDay( 2 ) ), array( 'payment.evidence_rejected', array( 'field' => 'received_on' ) ) ),
			'a card number in the reference' => array(
				$offline,
				array(
					'received_on' => $today,
					'reference'   => 'Card ' . self::CARD_NUMBER,
				),
				array( 'payment.evidence_rejected', array( 'field' => 'reference' ) ),
			),
			'a card number in the note'      => array(
				$offline,
				array(
					'received_on' => $today,
					'note'        => 'Paid with ' . self::CARD_NUMBER,
				),
				array( 'payment.evidence_rejected', array( 'field' => 'note' ) ),
			),
			'a day for a provider'           => array( $provider, array( 'received_on' => $today ), array( 'payment.evidence_unexpected', array( 'field' => 'received_on' ) ) ),
			'a note for a provider'          => array( $provider, array( 'note' => 'Paid.' ), array( 'payment.evidence_unexpected', array( 'field' => 'note' ) ) ),
		);

		foreach ( $cases as $case => list( $placed, $stated, $expected ) ) {
			$offlineCalls  = $this->offline?->calls() ?? 0;
			$providerCalls = count( $this->gateway->calls );
			$refused       = null;
			$log           = $this->captureQueries(
				function () use ( $placed, $stated, &$refused ): void {
					try {
						$this->payments()->capturePayment( array( 'intent_uuid' => $placed['intent_uuid'] ) + $stated, $this->person );
					} catch ( CodedException $refusal ) {
						$refused = array( $refusal->errorCode()->value, $refusal->context() );
					}
				}
			);

			$this->assertSame( $expected, $refused, $case );
			$this->assertSame( 0, self::writes( $log )->count(), $case . ': ' . self::writes( $log )->describe() );
			$this->assertSame( array( $offlineCalls, $providerCalls ), array( $this->offline?->calls() ?? 0, count( $this->gateway->calls ) ), $case . ': the gateway was asked.' );
		}

		$this->assertSame( array( 'authorize:approved:1', 'authorize:approved:1' ), array( $this->ledgerOf( $offline['order_id'] ), $this->ledgerOf( $provider['order_id'] ) ) );
		$this->assertSame( array(), $this->evidenceRows( $offline['order_id'] ) );
	}

	/**
	 * Tests a provider's capture with nothing stated, which records none, and an offline capture by a caller that states nothing, which records none either.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_stating_nothing_records_nothing_beside_it(): void {
		$provider = $this->placeStandIn();
		$offline  = $this->placeOffline( 'cash_on_delivery' );

		$this->assertSame( 'applied', $this->payments()->capturePayment( array( 'intent_uuid' => $provider['intent_uuid'] ), $this->person )['outcome'] );
		$this->assertSame( 'applied', $this->payments()->capture( $offline['intent_uuid'], $this->person )->kind->value, 'An internal caller is held to nothing.' );
		$this->assertSame( 0, $this->committedCount( $this->secondConnection(), PaymentTables::OFFLINE_PAYMENTS ) );
	}

	/**
	 * Tests a payment recorded again after it was recorded, from a second connection: refused with what was captured, which reads as done; one row and one record.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_a_payment_recorded_again_from_another_connection_reads_as_done(): void {
		$placed = $this->placeOffline();
		$day    = $this->databaseDay( -1 );

		$this->payments()->capturePayment( self::received( $placed, $day, 'first' ), $this->person );

		$report = $this->captureProbe( $placed, $day, 'second' )->finish();

		$this->assertSame( array( 'payment.not_capturable', array( 'status' => 'captured' ) ), array( $report['refused'] ?? $report, $report['context'] ?? null ), (string) wp_json_encode( $report ) );
		$this->assertSame( 'authorize:approved:1,capture:approved:1', $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( array( 'first' ), array_column( $this->evidenceRows( $placed['order_id'] ), 'reference' ) );
	}

	/**
	 * Tests two people recording one payment at once, on two connections: both pass the plain read and the gateway answers both with the one capture, so the ledger's key makes the second a duplicate; one row, the first's record, and nothing for a person to settle.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_two_people_recording_one_payment_at_once_record_it_once(): void {
		$placed = $this->placeOffline();
		$day    = $this->databaseDay( -1 );
		$again  = null;

		$this->beforeStatement(
			self::ORDER_LOCK,
			function () use ( $placed, $day, &$again ): void {
				$again = $this->captureProbe( $placed, $day, 'second' );

				$this->awaitProbeSending( $again, $this->lockIntent( $placed['intent_uuid'] ), self::WAITING_READ );
			}
		);

		$first  = $this->payments()->capturePayment( self::received( $placed, $day, 'first' ), $this->person );
		$report = $again?->finish() ?? array();
		$second = json_decode( (string) ( $report['answer_json'] ?? '' ), true );

		$this->assertSame( 'applied', $first['outcome'] );
		$this->assertSame( array( 'duplicate', 'captured' ), array( $second['outcome'] ?? $report, $second['status'] ?? null ), (string) wp_json_encode( $report ) );
		$this->assertSame( 'authorize:approved:1,capture:approved:1', $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( array( 'first' ), array_column( $this->evidenceRows( $placed['order_id'] ), 'reference' ) );
		$this->assertSame( '0', (string) $this->committedOrder( $this->secondConnection(), $placed['order_uuid'] )['has_unreconciled_money'], 'Nothing for a person to settle.' );
	}

	/**
	 * Tests a capture killed after the gateway answered and before anything was recorded: the payment stays authorized with no row and no record, and the same capture asked again records the same object once.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_a_capture_killed_after_the_gateway_answered_is_recorded_once_when_asked_again(): void {
		$placed = $this->placeOffline();
		$day    = $this->databaseDay( -1 );
		$killed = $this->captureProbe( $placed, $day, 'killed', true );

		while ( ! $killed->watch( 50 ) ) {
			continue;
		}

		$this->assertSame( '', $killed->reportSoFar(), "The capture answered: it was not killed.\n" . $killed->output() );
		$this->assertSame( array( 'authorized', 'authorize:approved:1', array() ), array( $this->intentStatus( $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ), $this->evidenceRows( $placed['order_id'] ) ), "Something was recorded: the process was not killed before its transaction.\n" . $killed->output() );

		$answer = $this->payments()->capturePayment( self::received( $placed, $day, 'asked again' ), $this->person );

		$this->assertSame( array( 'applied', 'captured' ), array( $answer['outcome'], $answer['status'] ) );
		$this->assertSame( 'authorize:approved:1,capture:approved:1', $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( 'bank_transfer-received-' . $placed['intent_uuid'], $this->db->fetchValue( "SELECT provider_object_id FROM %i WHERE order_id = %d AND operation = 'capture'", $this->table( PaymentTables::TRANSACTIONS ), $placed['order_id'] ) );
		$this->assertSame( array( 'asked again' ), array_column( $this->evidenceRows( $placed['order_id'] ), 'reference' ) );
	}

	/**
	 * Places one unit of a new variant, paid through an offline payment method, through the store's placement, sending no payment data.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method Optional. The method. Default `bank_transfer`.
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, variant: int, answer: array<string, mixed>} The placement.
	 */
	private function placeOffline( string $method = 'bank_transfer' ): array {
		$variant = $this->sellable();
		$cart    = $this->readyCart( array( $variant => 1 ) );

		$this->writeCheckout( $cart->version, $method );

		$input = $this->placeInput( 'offline-' . $variant );

		unset( $input['payment_data'] );

		return $this->placed( $variant, $this->placement->place( $input, self::guest() ) );
	}

	/**
	 * Places one unit of a new variant, paid through the stand-in, which approves it.
	 *
	 * @since 0.2.0
	 *
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, variant: int, answer: array<string, mixed>} The placement.
	 */
	private function placeStandIn(): array {
		$variant = $this->sellable();

		$this->readyCart( array( $variant => 1 ) );

		return $this->placed( $variant, $this->placement->place( $this->placeInput( 'stand-in-' . $variant ), self::guest() ) );
	}

	/**
	 * Describes a placement that was approved.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $variant The variant placed.
	 * @param array<string, mixed> $answer  The placement's answer.
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, variant: int, answer: array<string, mixed>} The placement.
	 */
	private function placed( int $variant, array $answer ): array {
		$this->assertSame( 'approved', $answer['outcome'] ?? null, (string) wp_json_encode( $answer ) );

		$orderId = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), (string) $answer['order_uuid'] );

		return array(
			'order_uuid'  => (string) $answer['order_uuid'],
			'order_id'    => $orderId,
			'intent_uuid' => (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $orderId ),
			'variant'     => $variant,
			'answer'      => $answer,
		);
	}

	/**
	 * Returns the payment service of the test's kernel.
	 *
	 * @since 0.2.0
	 *
	 * @return PaymentService The service.
	 */
	private function payments(): PaymentService {
		return $this->kernel->get( PaymentService::class );
	}

	/**
	 * Builds the capture operation's input of a payment received on a day, with a reference.
	 *
	 * @since 0.2.0
	 *
	 * @param array{intent_uuid: string} $placed    The placement.
	 * @param string                     $day       The day it arrived.
	 * @param string                     $reference The reference.
	 * @return array<string, string> The input.
	 */
	private static function received( array $placed, string $day, string $reference ): array {
		return array(
			'intent_uuid' => $placed['intent_uuid'],
			'received_on' => $day,
			'reference'   => $reference,
		);
	}

	/**
	 * Starts the capture operation in a probe, for the person, stating a payment received on a day with a reference.
	 *
	 * @since 0.2.0
	 *
	 * @param array{intent_uuid: string} $placed    The placement.
	 * @param string                     $day       The day it arrived.
	 * @param string                     $reference The reference.
	 * @param bool                       $killed    Optional. Whether the probe is killed as its first transaction begins. Default false.
	 * @return RunningProbe The probe.
	 */
	private function captureProbe( array $placed, string $day, string $reference, bool $killed = false ): RunningProbe {
		return $this->startPaymentProbe(
			'capture',
			self::received( $placed, $day, $reference ) + array(
				'user_id'            => $this->person->userId(),
				'die_at_transaction' => $killed,
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
	 * Returns the day some days from the database's today, YYYY-MM-DD, so no test depends on the date it runs.
	 *
	 * @since 0.2.0
	 *
	 * @param int $days The days from today: negative for the past.
	 * @return string The day.
	 */
	private function databaseDay( int $days ): string {
		return (string) $this->db->fetchValue( 'SELECT DATE_FORMAT( UTC_DATE() + INTERVAL %d DAY, %s )', $days, '%Y-%m-%d' );
	}

	/**
	 * Returns an order's ledger, in order: each row's operation, result and applied flag.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return string The rows, joined.
	 */
	private function ledgerOf( int $orderId ): string {
		$rows = $this->db->fetchAll( 'SELECT operation, result, applied FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::TRANSACTIONS ), $orderId );

		return implode( ',', array_map( static fn( array $row ): string => $row['operation'] . ':' . $row['result'] . ':' . $row['applied'], $rows ) );
	}

	/**
	 * Returns an order's status and payment status.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return array{0: string, 1: string} The statuses.
	 */
	private function orderStatuses( int $orderId ): array {
		$row = (array) $this->db->fetchRow( 'SELECT status, payment_status FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId );

		return array( (string) $row['status'], (string) $row['payment_status'] );
	}

	/**
	 * Returns an intent's status.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return string The status.
	 */
	private function intentStatus( string $intentUuid ): string {
		return (string) $this->db->fetchValue( 'SELECT status FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intentUuid );
	}

	/**
	 * Returns the records of the payments received of an order, as the table keeps them.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<array<string, mixed>> The rows, without their id and time.
	 */
	private function evidenceRows( int $orderId ): array {
		$rows = $this->db->fetchAll( 'SELECT transaction_id, intent_id, order_id, received_on, reference, note, actor_type, actor_id FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::OFFLINE_PAYMENTS ), $orderId );

		return array_map( static fn( array $row ): array => array_map( static fn( mixed $value ): mixed => null === $value ? null : (string) $value, $row ), $rows );
	}

	/**
	 * Returns the record a payment received should have: the capture's ledger row, its intent and order and who recorded it, with what was stated.
	 *
	 * @since 0.2.0
	 *
	 * @param array{order_id: int} $placed    The placement.
	 * @param string               $day       The day it arrived.
	 * @param string|null          $reference The reference, or null.
	 * @param string|null          $note      The note, or null.
	 * @return array<string, string|null> The row.
	 */
	private function evidenceRow( array $placed, string $day, ?string $reference, ?string $note ): array {
		$capture = (array) $this->db->fetchRow( "SELECT id, intent_id, order_id, actor_type, actor_id FROM %i WHERE order_id = %d AND operation = 'capture'", $this->table( PaymentTables::TRANSACTIONS ), $placed['order_id'] );

		$this->assertSame( (string) $this->person->userId(), (string) $capture['actor_id'], 'The capture was recorded as the person\'s.' );

		return array(
			'transaction_id' => (string) $capture['id'],
			'intent_id'      => (string) $capture['intent_id'],
			'order_id'       => (string) $capture['order_id'],
			'received_on'    => $day,
			'reference'      => $reference,
			'note'           => $note,
			'actor_type'     => (string) $capture['actor_type'],
			'actor_id'       => (string) $capture['actor_id'],
		);
	}

	/**
	 * Returns the stock allocations of a variant, each with its quantity and state.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variant The variant.
	 * @return list<string> The allocations, described.
	 */
	private function allocationsOf( int $variant ): array {
		$rows = $this->db->fetchAll( 'SELECT quantity, state FROM %i WHERE variant_id = %d ORDER BY id', $this->table( InventoryTables::ALLOCATIONS ), $variant );

		return array_map( static fn( array $row ): string => $row['quantity'] . ' ' . $row['state'], $rows );
	}

	/**
	 * Returns the uuid of an order's one line.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return string The line's uuid.
	 */
	private function lineOf( int $orderId ): string {
		return (string) $this->db->fetchValue( 'SELECT line_uuid FROM %i WHERE order_id = %d', $this->table( OrderTables::LINES ), $orderId );
	}

	/**
	 * Returns whether each refund document of an order says its money went back outside any provider, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> Each document's is_offline.
	 */
	private function offlineFlags( int $orderId ): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT is_offline FROM %i WHERE order_id = %d ORDER BY id', $this->table( RefundTables::REFUNDS ), $orderId ), 'is_offline' ) );
	}

	/**
	 * Keeps the statements of a log that write: inserts, updates, deletes and replaces.
	 *
	 * @since 0.2.0
	 *
	 * @param QueryLog $log The log.
	 * @return QueryLog The writes.
	 */
	private static function writes( QueryLog $log ): QueryLog {
		return $log->ofType( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' );
	}
}

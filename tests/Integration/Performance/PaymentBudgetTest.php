<?php
/**
 * Tests what a capture, a void, the end of a shopper's time to act and the resume of a payment cost in statements
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Checkout\Application\ResumePayment;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\OfflineMethod;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\Migrations\CreateOfflinePayments;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Performance\ReferenceCarts;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\QueryLog;

/**
 * Each of the payment operations on Cart A's order sends a fixed number of statements, as measured; each is asserted exactly, so one that grows fails here.
 *
 * - A capture through its operation, by a person the request has already loaded: the intent and
 *   its unreconciled rows read, then one transaction: its control statements, the intent's and the
 *   order's locks, the ledger row, the capture, the order's payment amounts, its event and the
 *   outbox.
 * - A void through its operation, of an accepted order: the same shape, with the void.
 * - A payment received for an offline payment method, recorded through the capture operation with
 *   the day it arrived and its reference: a capture's statements, the method's settings document,
 *   read once on a cold cache, and the record of what was stated, beside the capture's ledger row.
 * - A void of an offline payment method's promise: a void's statements, and the method's settings
 *   document, read once on a cold cache.
 * - The end of a shopper's time to act, the one stale intent of a run: the page of stale intents,
 *   the void's reads, then the placement's settlement of the void, with the order's cancellation,
 *   the hold, the uses and the cart given back, the hold's release one outbox insert of its own,
 *   then the page of orders with nothing due.
 * - A resume that settles the provider's approval: the cart and the intent read, then the
 *   placement's settlement of the approval.
 *
 * Planted violations: in StockService::allocateInside(), read the hold's rows twice: a resume
 * sends one statement more than its budget; in StockReservationReleased::deliveryMode(), answer
 * AfterCommit: the end of a shopper's time to act stores no release, one statement fewer, and runs
 * the stock summary's six statements itself after its commit: five statements more.
 *
 * @group performance
 *
 * @since 0.2.0
 */
final class PaymentBudgetTest extends PlacementTestCase {

	use CreatesUsers;
	use GrantsCapabilities;

	/**
	 * The statements of a capture of Cart A's payment through its operation.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const CAPTURE = 16;

	/**
	 * The statements of a void of Cart A's accepted order through its operation.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const VOID = 16;

	/**
	 * The statements of a payment received for Cart A's order paid by bank transfer, recorded through the capture operation with what a person stated of it.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const OFFLINE_CAPTURE = 18;

	/**
	 * The statements of a void of Cart A's order paid by bank transfer, through its operation.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const OFFLINE_VOID = 17;

	/**
	 * The statements of a reconciliation run that voids Cart A's payment, its shopper's time to act ended, and settles the void.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const WINDOW_END = 38;

	/**
	 * The statements of a resume of Cart A's payment that settles the provider's approval.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const RESUME = 36;

	/**
	 * Removes the installation record a test planted.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => BootOption::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removes the record the offline test planted.

		parent::tear_down();
	}

	/**
	 * Tests the capture and the void, each of an accepted order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_and_a_void_cost_their_budget(): void {
		$person  = $this->userGranted( PaymentService::CAPTURE_CAPABILITY, PaymentService::VOID_CAPABILITY );
		$capture = $this->placeCartA( StubGateway::APPROVE );
		$void    = $this->placeCartA( StubGateway::APPROVE );
		$service = $this->kernelOver( $this->db, $this->tokens )->get( PaymentService::class );

		$captured = $this->measured( 'capture', fn() => $service->capturePayment( array( 'intent_uuid' => $capture ), $person ), $person->userId() );
		$voided   = $this->measured(
			'void',
			fn() => $service->voidPayment(
				array(
					'intent_uuid' => $void,
					'reason'      => 'customer_request',
				),
				$person
			),
			$person->userId()
		);

		$this->assertQueryCount( self::CAPTURE, $captured, 'a capture' );
		$this->assertQueryCount( self::VOID, $voided, 'a void' );
	}

	/**
	 * Tests a payment received recorded for an order paid by bank transfer, and a void of another, each of an accepted order.
	 *
	 * @since 0.2.0
	 */
	public function test_an_offline_capture_and_void_cost_their_budget(): void {
		( new CreateOfflinePayments() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		// The installation record every store that takes orders has, autoloaded: a live payment's call reads Safe Mode from it, at no cost.
		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn(): BootRecord => KernelTestCase::installedRecord( KernelTestCase::codeHead() ) );

		$person  = $this->userGranted( PaymentService::CAPTURE_CAPABILITY, PaymentService::VOID_CAPABILITY );
		$capture = $this->placeCartA( 'offline', OfflineMethod::BankTransfer->value );
		$void    = $this->placeCartA( 'offline', OfflineMethod::BankTransfer->value );
		$service = $this->kernelOver( $this->db, $this->tokens )->get( PaymentService::class );
		$day     = (string) $this->db->fetchValue( "SELECT DATE_FORMAT( UTC_DATE() - INTERVAL 1 DAY, '%Y-%m-%d' )" );

		$captured = $this->measured(
			'offline capture',
			fn() => $service->capturePayment(
				array(
					'intent_uuid' => $capture,
					'received_on' => $day,
					'reference'   => 'TRF 000123',
				),
				$person
			),
			$person->userId()
		);
		$voided   = $this->measured(
			'offline void',
			fn() => $service->voidPayment(
				array(
					'intent_uuid' => $void,
					'reason'      => 'customer_request',
				),
				$person
			),
			$person->userId()
		);

		$this->assertQueryCount( self::OFFLINE_CAPTURE, $captured, 'a payment received recorded' );
		$this->assertQueryCount( self::OFFLINE_VOID, $voided, 'a void of a promise to pay' );
	}

	/**
	 * Tests the end of a shopper's time to act, and a resume that settles the provider's approval.
	 *
	 * @since 0.2.0
	 */
	public function test_the_window_end_and_a_resume_cost_their_budget(): void {
		$ended = $this->placeCartA( StubGateway::REQUIRES_ACTION );
		$job   = $this->kernelOver( $this->db, $this->tokens )->get( ReconcileStalePlacements::class );

		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE, customer_action_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $ended );

		$window = $this->measured( 'window end', fn() => $job->handle( array() ) );

		$this->assertSame( 'voided', $this->db->fetchValue( 'SELECT status FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $ended ) );

		$resumed = $this->placeCartA( StubGateway::REQUIRES_ACTION );
		$resume  = $this->kernelOver( $this->db, $this->tokens )->get( ResumePayment::class );
		$answer  = array();
		$log     = $this->measured(
			'resume',
			function () use ( $resume, $resumed, &$answer ): void {
				$answer = $resume->resume( array( 'intent_uuid' => $resumed ), self::guest() );
			}
		);

		$this->assertSame( 'approved', $answer['outcome'] ?? null );
		$this->assertQueryCount( self::WINDOW_END, $window, 'the end of a shopper\'s time to act' );
		$this->assertQueryCount( self::RESUME, $log, 'a resume' );
	}

	/**
	 * Places Cart A, with a payment token, through the stand-in or another payment method, and returns its intent's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $paymentToken The stub's script; any word for a method that reads none.
	 * @param string $method       Optional. The payment method. Default the stand-in.
	 * @return string The intent's uuid.
	 */
	private function placeCartA( string $paymentToken, string $method = StubGateway::ID ): string {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );
		$cart    = $this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

		if ( StubGateway::ID !== $method ) {
			$this->writeCheckout( $cart->version, $method );
		}

		$answer = $this->placement->place( $this->placeInput( 'budget-' . $variant, $paymentToken ), self::guest() );

		return (string) $this->db->fetchValue( 'SELECT i.uuid FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $answer['order_uuid'] );
	}

	/**
	 * Runs work on a cold object cache, as a request starts, and returns its statements, after printing them.
	 *
	 * A request starts with the autoloaded options loaded and, on a network, the core network
	 * options, among them the network's super admins; so does the measure. A person's request has
	 * loaded them before any operation runs, while authenticating them; so is their user here.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name   What is measured, for the report.
	 * @param \Closure $work   The work.
	 * @param int      $userId Optional. The person the request is from. Default 0, for none.
	 * @return QueryLog Its statements.
	 */
	private function measured( string $name, \Closure $work, int $userId = 0 ): QueryLog {
		wp_cache_flush();
		wp_load_alloptions();
		wp_load_core_site_options();

		if ( 0 !== $userId ) {
			get_userdata( $userId );
		}

		$log = $this->captureQueries( $work );

		fwrite( STDOUT, sprintf( "\n%s: %d statements\n%s\n", $name, $log->count(), $log->describe() ) );

		return $log;
	}
}

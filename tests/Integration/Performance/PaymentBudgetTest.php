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
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;
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
 * - The end of a shopper's time to act, the one stale intent of a run: the page of stale intents,
 *   the void's reads, then the placement's settlement of the void, with the order's cancellation,
 *   the hold, the uses and the cart given back, then the page of orders with nothing due.
 * - A resume that settles the provider's approval: the cart and the intent read, then the
 *   placement's settlement of the approval.
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
	 * The statements of a reconciliation run that voids Cart A's payment, its shopper's time to act ended, and settles the void.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const WINDOW_END = 37;

	/**
	 * The statements of a resume of Cart A's payment that settles the provider's approval.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const RESUME = 35;

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
	 * Places Cart A, with a payment token, and returns its intent's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $paymentToken The stub's script.
	 * @return string The intent's uuid.
	 */
	private function placeCartA( string $paymentToken ): string {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

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

<?php
/**
 * Tests how a placement recovers: from a request killed after its commit, from a gateway that never called back, and from a stranded key
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Domain\Cart;
use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Doctor\CheckoutChecks;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Checkout\Infrastructure\MysqlIdempotencyKeys;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Inventory\Domain\Event\StockAllocated;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Domain\Event\OrderCreated;
use SEOCart\Order\Domain\Event\OrderPlaced;
use SEOCart\Order\Domain\Event\OrderStatusChanged;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Domain\Event\PaymentAuthorized;
use SEOCart\Payment\Domain\Event\PaymentIntentCreated;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\RunningProbe;
use SEOCart\Tests\Support\SecondConnection;

/**
 * A placement's work survives what interrupts it, and only the gateway's answer settles a placement left waiting.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class PlacementRecoveryTest extends PlacementTestCase {

	/**
	 * How long a killed probe may take to end, in milliseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PROBE_END_MS = 30000;

	/**
	 * Tests that a placement killed right after its second unit of work committed loses no event: each is stored, and delivered once by the next drain.
	 *
	 * The placement runs in a process of its own, which is killed with SIGKILL as its publisher
	 * wakes after the second COMMIT, before anything is delivered. The order stands; every event
	 * of the placement waits in the outbox; a drain delivers each once, and a second drain none.
	 *
	 * Planted violation: in OrderPlaced::deliveryMode(), answer DeliveryMode::AfterCommit: the
	 * event is then delivered in memory after the commit, which the kill prevents, and it is lost.
	 *
	 * @group concurrency
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_killed_after_its_commit_loses_no_event(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$token = $this->tokens->presented;

		$this->assertNotNull( $token );

		$probe = $this->startPlacement( $token, $this->placeInput(), 2 );

		$this->awaitEnd( $probe );
		$this->assertSame( '', $probe->reportSoFar(), "The placement answered: it was not killed.\n" . $probe->output() );

		$b     = $this->secondConnection();
		$order = $b->fetchRow( sprintf( 'SELECT status, payment_status FROM `%s`', $this->table( OrderTables::ORDERS ) ) );
		$rows  = $this->outboxRows( $b );

		$this->assertSame(
			array(
				'status'         => 'processing',
				'payment_status' => 'authorized',
			),
			$order,
			'The second unit of work committed.'
		);
		$this->assertSame( array( 'pending' ), array_values( array_unique( array_column( $rows, 'state' ) ) ), 'Nothing was delivered before the kill.' );

		$expected = array( OrderCreated::eventName(), OrderPlaced::eventName(), OrderStatusChanged::eventName(), PaymentAuthorized::eventName(), PaymentIntentCreated::eventName(), StockAllocated::eventName() );
		$stored   = array_values( array_unique( array_column( $rows, 'event_name' ) ) );

		sort( $expected );
		sort( $stored );

		$this->assertSame( $expected, array_values( array_intersect( $stored, $expected ) ), 'Every event of the placement is stored.' );

		$delivered = array();

		foreach ( $stored as $name ) {
			add_action(
				EventEnvelope::hookFor( $name ),
				static function () use ( $name, &$delivered ): void {
					$delivered[] = $name;
				}
			);
		}

		$drainer = $this->kernel->get( OutboxDrainer::class );

		$drainer->drain( DrainOptions::command() );
		$drainer->drain( DrainOptions::command() );

		$names = array_column( $rows, 'event_name' );

		sort( $names );
		sort( $delivered );

		$this->assertSame( $names, $delivered, 'Each stored event was delivered once.' );
	}

	/**
	 * Tests that the reconciliation settles the stale placements the gateway has answered, as it answered, and leaves alone a fresh one and one it has no answer for.
	 *
	 * Four placements wait: two the shopper confirmed or the bank declined since, one the gateway
	 * is still deciding, all three untouched for eleven minutes by the database clock; and one
	 * waiting for a minute. The run settles the first two, and nothing else; a second run writes
	 * nothing.
	 *
	 * Planted violation: in ReconcileStalePlacements::handle(), settle an intent the gateway has no
	 * answer for as declined: the placement still being decided loses its hold, and its order fails.
	 *
	 * @since 0.1.0
	 */
	public function test_the_reconciliation_settles_only_what_the_gateway_answered(): void {
		$confirmed = $this->waitingPlacement( StubGateway::REQUIRES_ACTION );
		$declined  = $this->waitingPlacement( StubGateway::REQUIRES_ACTION );
		$deciding  = $this->waitingPlacement( StubGateway::PENDING );
		$fresh     = $this->waitingPlacement( StubGateway::REQUIRES_ACTION );
		$intents   = $this->table( PaymentTables::INTENTS );
		$orders    = $this->table( OrderTables::ORDERS );

		// Since it asked the shopper to act, the bank has declined the second.
		$this->db->execute( "UPDATE %i i JOIN %i o ON o.id = i.order_id SET i.provider_intent_id = REPLACE( i.provider_intent_id, 'requires_action', 'decline' ) WHERE o.uuid = %s", $intents, $orders, $declined['answer']['order_uuid'] );
		$this->db->execute( 'UPDATE %i i JOIN %i o ON o.id = i.order_id SET i.updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE o.uuid IN ( %s, %s, %s )', $intents, $orders, $confirmed['answer']['order_uuid'], $declined['answer']['order_uuid'], $deciding['answer']['order_uuid'] );

		$job = $this->kernel->get( ReconcileStalePlacements::class );

		$this->assertNull( $job->handle( array() ) );

		$b = $this->secondConnection();

		$this->assertSettled( $b, $confirmed, 'processing', array( 5, 1, 0 ), 'converted' );
		$this->assertSettled( $b, $declined, 'failed', array( 5, 0, 0 ), 'open' );
		$this->assertSettled( $b, $deciding, 'pending_payment', array( 5, 0, 1 ), 'placing' );
		$this->assertSettled( $b, $fresh, 'pending_payment', array( 5, 0, 1 ), 'placing' );

		$log = $this->captureQueries( static fn() => $job->handle( array() ) );

		$this->assertSame( 0, $log->matching( '/^(INSERT|UPDATE|DELETE)/' )->count(), "A second run wrote:\n" . $log->describe() );
		$this->assertSame( array(), $this->reports, 'Nothing was deferred.' );
	}

	/**
	 * Tests that a placement whose authorization never reached the gateway ends once reconciled: the gateway has no record of it, which is an answer, so everything the order held is given back and the cart can be placed again.
	 *
	 * Planted violation: in StubGateway::query(), answer null for an intent it never gave a
	 * reference to, as a gateway still deciding: the placement then waits for ever, its hold, its
	 * promotion's use and its cart taken.
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_the_gateway_never_heard_of_ends_when_reconciled(): void {
		$mug       = $this->sellable();
		$promotion = $this->plantPromotion( 'SAVE10' );
		$cart      = $this->readyCart( array( $mug => 1 ), array( 'SAVE10' ) );
		$b         = $this->secondConnection();

		try {
			$this->placement->place( $this->placeInput( 'unheard', StubGateway::THROW ), self::guest() );
			$this->fail( 'The gateway was reached.' );
		} catch ( CodedException $unavailable ) {
			$this->assertSame( CheckoutError::GatewayUnavailable, $unavailable->errorCode() );
		}

		$uuid  = (string) ( $unavailable->details()['order_uuid'] ?? '' );
		$order = $this->committedOrder( $b, $uuid );

		$this->assertNotNull( $order );
		$this->assertSame( 'pending_payment', $order['status'] );
		$this->assertSame( array( 5, 0, 1 ), $this->committedStock( $b, $mug ), 'The order waits with its hold.' );

		// Made and left eleven minutes ago, by the database clock: the provider has had time to find it.
		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE, updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] );

		$this->assertNull( $this->kernel->get( ReconcileStalePlacements::class )->handle( array() ) );

		$this->assertSame( 'failed', $this->committedOrder( $b, $uuid )['status'] ?? null );
		$this->assertSame( 'failed', $b->fetchValue( sprintf( 'SELECT status FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] ) ) );
		$this->assertSame( array( 5, 0, 0 ), $this->committedStock( $b, $mug ), 'The hold is given back.' );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) );
		$this->assertSame( 'released', $b->fetchValue( sprintf( 'SELECT state FROM `%s` WHERE order_id = %d AND promotion_id = %d', $this->table( PromotionTables::USAGE ), $order['id'], $promotion ) ) );
		$this->assertSame( 'open', $this->committedCart( $b, $cart->id )['status'] ?? null );
		$this->assertSame( 'declined', json_decode( (string) $b->fetchValue( sprintf( 'SELECT response_json FROM `%s` WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $order['id'] ) ), true )['outcome'] ?? null, 'A retry of the placement is told it ended.' );
		$this->assertSame( array(), $this->reports, 'Nothing was deferred.' );
		$this->assertSame( 'approved', $this->placement->place( $this->placeInput( 'again' ), self::guest() )['outcome'], 'The cart places again, with a new key.' );
	}

	/**
	 * Tests that a placement whose payment waits past its intent's expiry, pending or for the shopper, ends once reconciled: the gateway answers the expiry, which releases everything the order held; one not yet past its expiry is left to the gateway's own answer.
	 *
	 * Each waits eleven minutes unchanged by the database clock, so reconciliation asks about all
	 * of them; two have their wait run out a minute ago.
	 *
	 * Planted violations:
	 * - in StubGateway::query(), leave out the expiry: the expired placements keep their holds,
	 *   their promotion uses and their carts;
	 * - in StubGateway::query(), expire every waiting intent, whatever the query says: the pending
	 *   placement not yet past its expiry is declined too;
	 * - in MysqlPaymentRepository::MARK_PROCESSING, leave out the window: a pending intent then
	 *   has no expiry, and waits for ever.
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_past_its_expiry_is_released_when_reconciled(): void {
		$b       = $this->secondConnection();
		$intents = $this->table( PaymentTables::INTENTS );
		$orders  = $this->table( OrderTables::ORDERS );
		$expired = array();

		foreach ( array( StubGateway::PENDING, StubGateway::REQUIRES_ACTION ) as $round => $paymentToken ) {
			$variant   = $this->sellable();
			$promotion = $this->plantPromotion( 'EXPIRY' . $round );
			$cart      = $this->readyCart( array( $variant => 1 ), array( 'EXPIRY' . $round ) );
			$answer    = $this->placement->place( $this->placeInput( 'expiring-' . $round, $paymentToken ), self::guest() );

			$expired[ $paymentToken ] = array(
				'answer'    => $answer,
				'cart'      => $cart,
				'variant'   => $variant,
				'promotion' => $promotion,
			);
		}

		$deciding  = $this->waitingPlacement( StubGateway::PENDING );
		$confirmed = $this->waitingPlacement( StubGateway::REQUIRES_ACTION );

		$this->assertSame( 4, $this->committedCount( $b, PaymentTables::INTENTS, 'customer_action_expires_at > UTC_TIMESTAMP()' ), 'Every wait, pending or for the shopper, has its window.' );

		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE', $intents );

		foreach ( $expired as $placement ) {
			$this->db->execute( 'UPDATE %i i JOIN %i o ON o.id = i.order_id SET i.customer_action_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE o.uuid = %s', $intents, $orders, $placement['answer']['order_uuid'] );
		}

		$this->assertNull( $this->kernel->get( ReconcileStalePlacements::class )->handle( array() ) );

		foreach ( $expired as $paymentToken => $placement ) {
			$order = $this->committedOrder( $b, (string) $placement['answer']['order_uuid'] );

			$this->assertNotNull( $order );
			$this->assertSame( array( 'failed', 'failed' ), array( $order['status'], $order['payment_status'] ), $paymentToken );
			$this->assertSame( StubGateway::EXPIRED, $b->fetchValue( sprintf( 'SELECT error_code FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::TRANSACTIONS ), $order['id'] ) ), $paymentToken );
			$this->assertSame( array( 5, 0, 0 ), $this->committedStock( $b, $placement['variant'] ), 'The hold is given back: ' . $paymentToken );
			$this->assertSame( array( 'released', '0' ), array( $b->fetchValue( sprintf( 'SELECT state FROM `%s` WHERE order_id = %d', $this->table( PromotionTables::USAGE ), $order['id'] ) ), $b->fetchValue( sprintf( 'SELECT used FROM `%s` WHERE id = %d', $this->table( PromotionTables::PROMOTIONS ), $placement['promotion'] ) ) ), 'The promotion\'s use is given back: ' . $paymentToken );
			$this->assertSame( 'open', $this->committedCart( $b, $placement['cart']->id )['status'] ?? null, $paymentToken );
			$this->assertSame( 'declined', json_decode( (string) $b->fetchValue( sprintf( 'SELECT response_json FROM `%s` WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $order['id'] ) ), true )['outcome'] ?? null, $paymentToken );
		}

		$this->assertSettled( $b, $deciding, 'pending_payment', array( 5, 0, 1 ), 'placing' );
		$this->assertSettled( $b, $confirmed, 'processing', array( 5, 1, 0 ), 'converted' );
		$this->assertSame( array(), $this->reports, 'Nothing was deferred.' );
	}

	/**
	 * Tests that an approval the gateway delivers after the placement ended on its "no record" answer is kept for a person: the ledger holds it unapplied, the order is flagged and doctor names it, and the stock, the promotion's use and the reopened cart stay as they were; delivered again, it is recorded once.
	 *
	 * Planted violation: in PaymentService::applyMoneyFact(), drop the keeping of an approved
	 * authorization for an intent that ended: the approval is then refused, its ledger row rolls
	 * back with it, and the shopper's money is recorded nowhere.
	 *
	 * @since 0.1.0
	 */
	public function test_a_late_approval_for_an_ended_placement_is_kept_for_a_person(): void {
		$mug       = $this->sellable();
		$promotion = $this->plantPromotion( 'SAVE10' );
		$cart      = $this->readyCart( array( $mug => 1 ), array( 'SAVE10' ) );
		$b         = $this->secondConnection();

		try {
			$this->placement->place( $this->placeInput( 'unheard', StubGateway::THROW ), self::guest() );
			$this->fail( 'The gateway was reached.' );
		} catch ( CodedException $unavailable ) {
			$this->assertSame( CheckoutError::GatewayUnavailable, $unavailable->errorCode() );
		}

		$uuid  = (string) ( $unavailable->details()['order_uuid'] ?? '' );
		$order = $this->committedOrder( $b, $uuid );

		$this->assertNotNull( $order );
		// Made and left eleven minutes ago, by the database clock: the provider has had time to find it.
		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE, updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );
		$this->assertSame( 'failed', $this->committedOrder( $b, $uuid )['status'] ?? null, 'The placement ended on the gateway\'s "no record".' );

		$ended    = $this->committedCart( $b, $cart->id );
		$intent   = $b->fetchRow( sprintf( 'SELECT uuid, amount_minor, currency FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] ) );
		$approval = ( new StubGateway() )->authorize( new PaymentRequest( (string) $intent['uuid'], Money::of( (int) $intent['amount_minor'], Currency::of( (string) $intent['currency'] ) ), StubGateway::APPROVE, Mode::Test, $uuid, '1001' ) );
		$settle   = $this->kernel->get( SettlePlacement::class );
		$late     = $settle->apply( $approval, Actor::user( 0 ) );
		$again    = $settle->apply( $approval, Actor::user( 0 ) );
		$ledger   = $this->kernel->get( PaymentLedgerCheck::class )->run();

		$this->assertSame( array( 'late_approval', 'duplicate' ), array( $late->outcome->value, $again->outcome->value ), 'The approval is kept, then known.' );
		$this->assertSame( 1, $this->committedCount( $b, PaymentTables::TRANSACTIONS, sprintf( "order_id = %d AND applied = 0 AND operation = 'authorize' AND result = 'approved'", $order['id'] ) ), 'The ledger keeps the approval unapplied, once.' );
		$this->assertSame( array( 'failed', 1 ), array( $this->committedOrder( $b, $uuid )['status'] ?? null, $this->committedOrder( $b, $uuid )['has_unreconciled_money'] ?? null ), 'The order is flagged, and left failed.' );
		$this->assertSame( 'failed', $b->fetchValue( sprintf( 'SELECT status FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] ) ) );
		$this->assertSame( array( 5, 0, 0 ), $this->committedStock( $b, $mug ), 'The units given back stay so.' );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) + $this->committedCount( $b, InventoryTables::ALLOCATIONS ) );
		$this->assertSame( 'released', $b->fetchValue( sprintf( 'SELECT state FROM `%s` WHERE order_id = %d AND promotion_id = %d', $this->table( PromotionTables::USAGE ), $order['id'], $promotion ) ) );
		$this->assertSame( $ended, $this->committedCart( $b, $cart->id ), 'The reopened cart is untouched.' );
		$this->assertSame( 'late_approval', json_decode( (string) $b->fetchValue( sprintf( 'SELECT response_json FROM `%s` WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $order['id'] ) ), true )['outcome'] ?? null );
		$this->assertFalse( $ledger->passed );
		$this->assertStringContainsString( $uuid, implode( "\n", $ledger->findings ), 'Doctor\'s payment check names the order.' );
	}

	/**
	 * Tests that doctor names a placement still waiting for its payment after a day, by its order's uuid, and that repair leaves it waiting.
	 *
	 * Planted violation: in CheckoutChecks::run(), leave the waiting placements out of the check:
	 * it then passes while an order waits.
	 *
	 * @since 0.1.0
	 */
	public function test_doctor_names_a_placement_waiting_for_a_day(): void {
		$deciding = $this->waitingPlacement( StubGateway::PENDING );
		$fresh    = $this->waitingPlacement( StubGateway::PENDING );
		$check    = $this->kernel->get( CheckoutChecks::class );

		$this->assertTrue( $check->run()->passed, 'A placement waiting for a few minutes is not reported.' );

		$this->db->execute( 'UPDATE %i i JOIN %i o ON o.id = i.order_id SET i.updated_at = UTC_TIMESTAMP(6) - INTERVAL 25 HOUR WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $deciding['answer']['order_uuid'] );

		$result = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertCount( 1, $result->findings );
		$this->assertStringContainsString( (string) $deciding['answer']['order_uuid'], $result->findings[0] );
		$this->assertStringNotContainsString( (string) $fresh['answer']['order_uuid'], implode( "\n", $result->findings ) );
		$this->assertSame( array(), $check->repair()->changes, 'Repair settles no placement.' );
		$this->assertSame( 'pending_payment', $this->committedOrder( $this->secondConnection(), (string) $deciding['answer']['order_uuid'] )['status'] ?? null );
	}

	/**
	 * Tests that a key stranded claimed refuses its retry as in progress until doctor's repair deletes it, and the retry then places.
	 *
	 * Planted violation: in CheckoutChecks::repair(), report the stranded keys without deleting
	 * them: the retry is still refused as in progress.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stranded_key_holds_its_retry_until_doctor_deletes_it(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$token = $this->tokens->presented;
		$keys  = $this->kernel->get( MysqlIdempotencyKeys::class );

		$this->assertNotNull( $token );

		$claim = $this->db->transaction( static fn(): IdempotencyClaim => $keys->claim( IdempotencyClaim::PLACE_ORDER_SCOPE, IdempotencyClaim::keyHash( $token->hash(), 'attempt-1' ), hash( 'sha256', 'a request that died' ), 3600 ) );

		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 2 HOUR WHERE id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $claim->id );

		try {
			$this->placement->place( $this->placeInput(), self::guest() );
			$this->fail( 'A retry went through a key still claimed.' );
		} catch ( CodedException $held ) {
			$this->assertSame( CheckoutError::PlacementInProgress, $held->errorCode() );
		}

		$check = $this->kernel->get( CheckoutChecks::class );

		$this->assertFalse( $check->run()->passed );
		$this->assertCount( 1, $check->repair()->changes );
		$this->assertSame( 'approved', $this->placement->place( $this->placeInput(), self::guest() )['outcome'], 'The retry places once the stranded key is gone.' );
	}

	/**
	 * Places an order from a new cart of a new product, with a payment token that leaves it waiting.
	 *
	 * @since 0.1.0
	 *
	 * @param string $paymentToken The payment token.
	 * @return array{answer: array<string, mixed>, cart: Cart, variant: int} The answer, the cart and the variant.
	 */
	private function waitingPlacement( string $paymentToken ): array {
		$variant = $this->sellable();
		$cart    = $this->readyCart( array( $variant => 1 ) );
		$answer  = $this->placement->place( $this->placeInput( 'attempt-' . $variant, $paymentToken ), self::guest() );

		$this->assertContains( $answer['outcome'], array( 'requires_action', 'processing' ) );

		return array(
			'answer'  => $answer,
			'cart'    => $cart,
			'variant' => $variant,
		);
	}

	/**
	 * Asserts where a placement stands: its order's status, its item's counters and its cart's status.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection                                              $b         Connection B.
	 * @param array{answer: array<string, mixed>, cart: Cart, variant: int} $placement The placement.
	 * @param string                                                        $status    The order's status.
	 * @param array{0: int, 1: int, 2: int}                                 $stock     on_hand, allocated and held.
	 * @param string                                                        $cart      The cart's status.
	 */
	private function assertSettled( SecondConnection $b, array $placement, string $status, array $stock, string $cart ): void {
		$order = $this->committedOrder( $b, (string) $placement['answer']['order_uuid'] );

		$this->assertSame( $status, $order['status'] ?? null );
		$this->assertSame( $stock, $this->committedStock( $b, $placement['variant'] ), 'The stock of the order ' . $status );
		$this->assertSame( $cart, $this->committedCart( $b, $placement['cart']->id )['status'] ?? null, 'The cart of the order ' . $status );
	}

	/**
	 * Returns the outbox's rows, each with its event's name and its state, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return list<array{event_name: string, state: string}> The rows.
	 */
	private function outboxRows( SecondConnection $b ): array {
		$rows = array();
		$next = fn( int $after ): ?array => $b->fetchRow( sprintf( 'SELECT id, event_name, state FROM `%s` WHERE id > %d ORDER BY id LIMIT 1', $this->table( OutboxTable::NAME ), $after ) );

		// Connection B reads one row at a time.
		for ( $row = $next( 0 ); null !== $row; $row = $next( (int) $row['id'] ) ) {
			$rows[] = array(
				'event_name' => (string) $row['event_name'],
				'state'      => (string) $row['state'],
			);
		}

		return $rows;
	}

	/**
	 * Waits for a probe to end, watching its output, never pausing; fails the test at the deadline.
	 *
	 * @since 0.1.0
	 *
	 * @param RunningProbe $probe The probe.
	 */
	private function awaitEnd( RunningProbe $probe ): void {
		$deadline = hrtime( true ) + self::PROBE_END_MS * 1000000;

		while ( ! $probe->watch( 50 ) ) {
			if ( hrtime( true ) >= $deadline ) {
				$this->fail( "The probe did not end.\n" . $probe->output() );
			}
		}
	}
}

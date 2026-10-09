<?php
/**
 * RefundTestCase: the base of the tests that refund real orders through the refund service
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use SEOCart\Application\Operations\IdempotencyKey;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\RefundCapPolicy;
use SEOCart\Payment\Application\RefundCapSettings;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRepository;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Refund\RequestKey;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\IdGenerator;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\RunningProbe;

/**
 * A PaymentTestCase with the refund service wired as the kernel wires it, over the recorded stub gateway.
 *
 * Owns one fact: how a refund test gets a paid order, asks for a refund the way a merchant does,
 * and reads back what it stored. The order is placed, authorized and captured in full through the
 * stub; the gateway's record of calls is then cleared, so a test reads only the refund's calls.
 * A second runner is the refund service over a connection of its own.
 *
 * @since 0.1.0
 */
abstract class RefundTestCase extends PaymentTestCase {

	/**
	 * The reason every refund of a test is asked with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const REASON = 'customer_return';

	/**
	 * The statements a refund sends: the plugin's tables and the transaction control, the capability check's reads of the user aside.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	protected const STATEMENTS = '/seocart_|^(START TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE SAVEPOINT)/';

	/**
	 * How long a refund probe may take to end, in milliseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PROBE_END_MS = 30000;

	/**
	 * The refund service over `$this->db`, calling `$this->gateway`.
	 *
	 * @since 0.1.0
	 *
	 * @var RefundService
	 */
	protected RefundService $refunds;

	/**
	 * The user who refunds, created once per test.
	 *
	 * @since 0.1.0
	 *
	 * @var Actor|null
	 */
	private ?Actor $agent = null;

	/**
	 * Whether the test set the refund caps, which tear_down() then removes.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $capped = false;

	/**
	 * The user who may override what the plugin knows of the money, created once per test.
	 *
	 * @since 0.2.0
	 *
	 * @var Actor|null
	 */
	private ?Actor $manager = null;

	/**
	 * How many results the test kept unapplied, so each is recorded with ids of its own.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $keptUnapplied = 0;

	/**
	 * Creates the service.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->agent         = null;
		$this->manager       = null;
		$this->keptUnapplied = 0;
		$this->capped        = false;
		$this->refunds       = $this->refundsOver( $this->db, $this->ids, $this->gateway );
	}

	/**
	 * Removes the refund caps the test set, so the next test reads the defaults.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		if ( $this->capped ) {
			foreach ( RefundCapSettings::settings() as $setting ) {
				delete_option( $setting->optionName() );
			}
		}

		parent::tear_down();
	}

	/**
	 * Sets the order agents' refund caps, committed, as the settings operation writes them: amounts of the base currency in major units, or '' for no cap.
	 *
	 * @since 0.2.0
	 *
	 * @param string $perOrder The cap of one order.
	 * @param string $perDay   The cap of any 24 hours.
	 */
	protected function capOrderAgents( string $perOrder, string $perDay ): void {
		$this->capped = true;

		$this->settingsOver( $this->db )->writeScalars(
			array(
				RefundCapSettings::ORDER_AGENT_PER_ORDER => $perOrder,
				RefundCapSettings::ORDER_AGENT_PER_DAY   => $perDay,
			)
		);
	}

	/**
	 * Builds the settings store over a connection, over every setting the plugin declares.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection.
	 * @return SettingsStore The store.
	 */
	protected function settingsOver( Database $db ): SettingsStore {
		return new SettingsStore( Settings::registry(), $db );
	}

	/**
	 * Builds the refund service over a connection, as the kernel builds it.
	 *
	 * @since 0.1.0
	 *
	 * @param Database                $db      The connection.
	 * @param IdGenerator             $ids     The ids the order and payment code mints: a second runner needs its own range.
	 * @param PaymentGateway          $gateway The gateway it calls.
	 * @param EventPublisher|null     $events  Optional. What publishes the refund's event. Default the outbox's publisher over the connection.
	 * @param RefundRepository|null   $refunds Optional. The refund statements. Default MysqlRefundRepository over the connection.
	 * @param TransactionManager|null $tx       Optional. The refund's own unit of work. Default the connection.
	 * @param Gateways|null           $gateways Optional. The registry the gateways are found in, such as a kernel's. Default one holding `$gateway` alone.
	 * @return RefundService The service.
	 */
	protected function refundsOver( Database $db, IdGenerator $ids, PaymentGateway $gateway, ?EventPublisher $events = null, ?RefundRepository $refunds = null, ?TransactionManager $tx = null, ?Gateways $gateways = null ): RefundService {
		$gateways ??= TestGateways::of( $gateway );

		return new RefundService(
			$refunds ?? new MysqlRefundRepository( $db ),
			new MysqlOrderRepository( new OrderStatements( $db ), $ids ),
			$this->paymentsWith( $db, $ids, $gateways ),
			$gateways,
			$tx ?? $db,
			$events ?? $this->publisherOver( $db ),
			new Authorizer( new CapabilityDeclaration() ),
			FrozenClock::at( self::NOW ),
			new RefundCapPolicy( $this->settingsOver( $db ) ),
			$this->ordersOver( $db, $ids )
		);
	}

	/**
	 * Opens a second runner of the refund code: the service over its own connection, over the stub gateway, closed in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @return RefundService The service.
	 */
	protected function secondRefunds(): RefundService {
		list( , $db ) = $this->secondOrders();

		return $this->refundsOver( $db, new SequentialIdGenerator( 800000 ), new StubGateway() );
	}

	/**
	 * Places an order, has the stub authorize it with a token and captures it in full, then clears the gateway's record of calls.
	 *
	 * @since 0.1.0
	 *
	 * @param NewOrder $order The document.
	 * @param string   $token Optional. The stub's script. Default StubGateway::APPROVE.
	 * @return array{0: InsertedOrder, 1: IntentRef} The order and its intent.
	 */
	protected function placePaid( NewOrder $order, string $token = StubGateway::APPROVE ): array {
		list( $inserted, $intent ) = $this->placeWithIntent( $order );

		$this->deliver( $this->authorizeWith( $intent, $token ) );
		$this->deliver( $this->gateway->capture( new CaptureRequest( $intent->uuid, (string) $this->intentRow( $intent->uuid )['provider_intent_id'], $order->totals->grandTotal, $intent->mode ) ) );

		$this->gateway->calls = array();

		return array( $inserted, $intent );
	}

	/**
	 * Refunds units of some lines, and the shipping when asked, as the order agent.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $orderUuid The order.
	 * @param array<string, int> $units     The units of each line, by line uuid.
	 * @param bool               $shipping  Optional. Whether to give back what is left of the shipping. Default false.
	 * @param RefundService|null $service   Optional. The service to ask. Default `$this->refunds`.
	 * @return Refund The refund.
	 */
	protected function refund( string $orderUuid, array $units, bool $shipping = false, ?RefundService $service = null ): Refund {
		return ( $service ?? $this->refunds )->refund( self::request( $orderUuid, $units, $shipping ), $this->agent() );
	}

	/**
	 * Refunds units of some lines as the order agent, with an idempotency key: the key's hash, scoped to the agent, and the request's fingerprint.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $orderUuid The order.
	 * @param array<string, int> $units     The units of each line, by line uuid.
	 * @param string             $key       The key the caller sends.
	 * @param string|null        $note      Optional. The note. Default null, none.
	 * @param RefundService|null $service   Optional. The service to ask. Default `$this->refunds`.
	 * @return Refund The refund.
	 */
	protected function refundWithKey( string $orderUuid, array $units, string $key, ?string $note = null, ?RefundService $service = null ): Refund {
		$request = self::request( $orderUuid, $units, false, $note );

		return ( $service ?? $this->refunds )->refund( $request, $this->agent(), $this->requestKey( $request, $key ) );
	}

	/**
	 * Builds the key a request is sent with: the key's hash, scoped to the order agent, and the request's fingerprint.
	 *
	 * @since 0.2.0
	 *
	 * @param RefundRequest $request The request.
	 * @param string        $key     The key the caller sends.
	 * @return RequestKey The key.
	 */
	protected function requestKey( RefundRequest $request, string $key ): RequestKey {
		return new RequestKey( IdempotencyKey::hash( 'refund-test|' . $this->agent()->userId(), $key ), IdempotencyKey::fingerprint( $request->canonical() ) );
	}

	/**
	 * Builds a refund request.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The note.
	 *
	 * @param string             $orderUuid The order.
	 * @param array<string, int> $units     The units of each line, by line uuid.
	 * @param bool               $shipping  Whether to give back what is left of the shipping.
	 * @param string|null        $note      Optional. The note. Default null, none.
	 * @return RefundRequest The request.
	 */
	protected static function request( string $orderUuid, array $units, bool $shipping, ?string $note = null ): RefundRequest {
		$lines = array();

		foreach ( $units as $lineUuid => $quantity ) {
			$lines[] = new RefundLineRequest( (string) $lineUuid, $quantity );
		}

		return new RefundRequest( $orderUuid, $lines, $shipping, self::REASON, $note );
	}

	/**
	 * Returns the user who refunds: an order agent, who holds the refund capability.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor The user.
	 */
	protected function agent(): Actor {
		$this->agent ??= $this->userWithRole();

		return $this->agent;
	}

	/**
	 * Counts the refunds the gateway was asked for.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	protected function refundCalls(): int {
		return count( array_filter( $this->gateway->calls, static fn( array $call ): bool => 'refund' === $call['method'] ) );
	}

	/**
	 * Counts the times the gateway was asked what became of a refund.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	protected function refundQueries(): int {
		return count( array_filter( $this->gateway->calls, static fn( array $call ): bool => 'queryRefund' === $call['method'] ) );
	}

	/**
	 * Reads an order's refund claims, oldest first: the uuid, the state, the amount and who asked, and the ledger row that ended each.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<array<string, mixed>> The claims, each with `uuid`, `state`, `amount_minor`, `actor_type`, `actor_id`, `transaction_id`, and `settled`, 1 when the claim has its settled time.
	 */
	protected function claimRows( int $orderId ): array {
		return $this->db->fetchAll(
			'SELECT uuid, state, amount_minor, actor_type, actor_id, transaction_id, settled_at IS NOT NULL AS settled FROM %i WHERE order_id = %d ORDER BY id',
			$this->table( RefundClaimTables::CLAIMS ),
			$orderId
		);
	}

	/**
	 * Makes every refund claim older, by the database clock: as if each was made that many seconds before now.
	 *
	 * @since 0.1.0
	 *
	 * @param int $seconds How old each claim is to be.
	 */
	protected function ageClaims( int $seconds ): void {
		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL %d SECOND', $this->table( RefundClaimTables::CLAIMS ), $seconds );
	}

	/**
	 * Reads an order's refund rows on the ledger, in order: the provider's refund object, the result, whether it was applied, and its error code.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<array<string, mixed>> The rows.
	 */
	protected function refundLedgerRows( int $orderId ): array {
		return $this->db->fetchAll( "SELECT id, provider_object_id, result, applied, error_code FROM %i WHERE order_id = %d AND operation = 'refund' ORDER BY id", $this->table( PaymentTables::TRANSACTIONS ), $orderId );
	}

	/**
	 * Builds doctor's payment check over the test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @return PaymentLedgerCheck The check.
	 */
	protected function ledgerCheck(): PaymentLedgerCheck {
		return new PaymentLedgerCheck( new MysqlPaymentRepository( $this->db, $this->ids ), new MysqlOrderRepository( new OrderStatements( $this->db ), $this->ids ) );
	}

	/**
	 * Starts, in a process of its own, a refund of some units of an order as the order agent, through the kernel's wiring and the stub gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $orderUuid The order.
	 * @param array<string, int> $units     The units of each line, by line uuid.
	 * @param string|null        $crashLog  Optional. The file the gateway's refund is logged to before
	 *                                      the process kills itself, once the gateway gave the money
	 *                                      back; null for a refund that runs to its end. Default null.
	 * @param Actor|null         $actor     Optional. Who refunds. Default the order agent.
	 * @param string|null        $key       Optional. The idempotency key, with which the refund is asked
	 *                                      through the refund operation's service; null for none. Default null.
	 * @return RunningProbe The running refund; its report, unless it was killed, says how it ended.
	 */
	protected function startRefundProbe( string $orderUuid, array $units, ?string $crashLog = null, ?Actor $actor = null, ?string $key = null ): RunningProbe {
		$request = array(
			'order_uuid'      => $orderUuid,
			'units'           => $units,
			'shipping'        => false,
			'user_id'         => ( $actor ?? $this->agent() )->userId(),
			'call_log'        => (string) $crashLog,
			'crash'           => null !== $crashLog,
			'idempotency_key' => (string) $key,
		);

		return ChildProcessProbe::start( __DIR__ . '/refund-probe.php', array( base64_encode( (string) wp_json_encode( $request ) ) ) );
	}

	/**
	 * Starts, in a process of its own, the settlement of a refund's claim, through the kernel's wiring and the plain stub gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param string         $refundUuid The refund whose claim is settled.
	 * @param ClaimStatement $statement  What the person states.
	 * @param Actor          $actor      Who settles it.
	 * @param string         $dieBefore  Optional. The first words of the statement the process kills itself just before; '' for none. Default ''.
	 * @param string         $callLog    Optional. The file each question to the gateway is logged to; '' for none. Default ''.
	 * @return RunningProbe The running settlement; its report, unless it was killed, says how it ended.
	 */
	protected function startSettleProbe( string $refundUuid, ClaimStatement $statement, Actor $actor, string $dieBefore = '', string $callLog = '' ): RunningProbe {
		$request = array(
			'action'             => 'settle',
			'refund_uuid'        => $refundUuid,
			'statement'          => $statement->word(),
			'note'               => $statement->note,
			'provider_refund_id' => $statement->providerRefundId,
			'amount_minor'       => $statement->amountMinor,
			'user_id'            => $actor->userId(),
			'call_log'           => $callLog,
			'crash'              => '' !== $callLog,
			'die_before'         => $dieBefore,
		);

		return ChildProcessProbe::start( __DIR__ . '/refund-probe.php', array( base64_encode( (string) wp_json_encode( $request ) ) ) );
	}

	/**
	 * Starts, in a process of its own, the clearance of an order's unreconciled money, through the kernel's wiring.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @param string $note      Why.
	 * @param Actor  $actor     Who clears it.
	 * @return RunningProbe The running clearance; its report says when it cleared the flag, or its refusal.
	 */
	protected function startReconcileProbe( string $orderUuid, string $note, Actor $actor ): RunningProbe {
		$request = array(
			'action'     => 'reconcile',
			'order_uuid' => $orderUuid,
			'note'       => $note,
			'user_id'    => $actor->userId(),
			'crash'      => false,
		);

		return ChildProcessProbe::start( __DIR__ . '/refund-probe.php', array( base64_encode( (string) wp_json_encode( $request ) ) ) );
	}

	/**
	 * Starts, in a process of its own, the recording of a refund the provider made that no claim asked for, as money a person must reconcile.
	 *
	 * @since 0.2.0
	 *
	 * @param IntentRef $intent         The intent it is of.
	 * @param int       $minor          What it gave back, in minor units.
	 * @param string    $currency       The currency.
	 * @param string    $providerObject The provider's refund object.
	 * @return RunningProbe The running recording; its report says `kept` once it committed.
	 */
	protected function startLandingProbe( IntentRef $intent, int $minor, string $currency, string $providerObject ): RunningProbe {
		$request = array(
			'action'       => 'land',
			'intent_uuid'  => $intent->uuid,
			'amount_minor' => $minor,
			'currency'     => $currency,
			'object'       => $providerObject,
			'crash'        => false,
		);

		return ChildProcessProbe::start( __DIR__ . '/refund-probe.php', array( base64_encode( (string) wp_json_encode( $request ) ) ) );
	}

	/**
	 * Asks for a refund of one unit of a line while the gateway cannot be reached, which leaves its claim open, and returns the claim's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $orderUuid The order.
	 * @param string             $lineUuid  The line.
	 * @param RememberingGateway $provider  The gateway the service asks, made unreachable for the refund.
	 * @param RefundService      $service   The refund service.
	 * @return string The open claim's uuid.
	 */
	protected function openClaim( string $orderUuid, string $lineUuid, RememberingGateway $provider, RefundService $service ): string {
		$provider->reachable = false;

		try {
			$this->refund( $orderUuid, array( $lineUuid => 1 ), false, $service );
			$this->fail( 'The refund reached the gateway.' );
		} catch ( GatewayUnavailable $unreached ) {
			unset( $unreached );
		} finally {
			$provider->reachable = true;
		}

		return (string) $this->db->fetchValue( "SELECT uuid FROM %i WHERE state = 'claimed' ORDER BY id DESC LIMIT 1", $this->table( RefundClaimTables::CLAIMS ) );
	}

	/**
	 * Returns a user who may override what the plugin knows of the money: a store manager, created once per test.
	 *
	 * @since 0.2.0
	 *
	 * @return Actor The user.
	 */
	protected function manager(): Actor {
		$this->manager ??= $this->userWithRole( 'seocart_manager' );

		return $this->manager;
	}

	/**
	 * Records a refund the provider made that no claim of the plugin's asked for, as money a person must reconcile: its ledger row applied to nothing, and the order flagged and parked.
	 *
	 * @since 0.2.0
	 *
	 * @param IntentRef     $intent         The intent it is of.
	 * @param int           $minor          What it gave back, in minor units.
	 * @param string        $currency       The currency.
	 * @param string        $providerObject The provider's refund object.
	 * @param Database|null $db             Optional. The connection it is recorded on. Default the test's.
	 */
	protected function keepUnappliedRefund( IntentRef $intent, int $minor, string $currency, string $providerObject, ?Database $db = null ): void {
		$db     ??= $this->db;
		$payments = $this->paymentsOver( $db, new SequentialIdGenerator( 700000 + 1000 * ++$this->keptUnapplied ), new StubGateway() );

		$db->transaction( static fn() => $payments->recordUnapplied( self::stubResult( $intent, Operation::Refund, Outcome::Approved, $minor, $currency, $providerObject ), self::system() ) );
	}

	/**
	 * Waits for a probe to end, watching its output, never pausing; fails the test at the deadline.
	 *
	 * @since 0.1.0
	 *
	 * @param RunningProbe $probe The probe.
	 */
	protected function awaitProbeEnd( RunningProbe $probe ): void {
		$deadline = hrtime( true ) + self::PROBE_END_MS * 1000000;

		while ( ! $probe->watch( 50 ) ) {
			if ( hrtime( true ) >= $deadline ) {
				$this->fail( "The probe did not end.\n" . $probe->output() );
			}
		}
	}

	/**
	 * Asserts that every ended claim of an order that names a ledger row names one its own answer wrote.
	 *
	 * The row is a refund row of the order; no other claim names it; a document that states it is
	 * the claim's own; and its provider object, when it has one, is named by the claim's uuid, as
	 * every gateway of these tests names a refund by the key it was asked with.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 */
	protected function assertClaimsNameOnlyTheirOwnRows( int $orderId ): void {
		$ledger    = array_column( $this->refundLedgerRows( $orderId ), null, 'id' );
		$documents = array_column( $this->refundRows( $orderId ), 'uuid', 'transaction_id' );
		$named     = array();

		foreach ( $this->claimRows( $orderId ) as $claim ) {
			if ( null === $claim['transaction_id'] ) {
				continue;
			}

			$uuid   = (string) $claim['uuid'];
			$row    = $ledger[ $claim['transaction_id'] ] ?? null;
			$object = (string) ( $row['provider_object_id'] ?? '' );

			$this->assertNotNull( $row, "Claim {$uuid} names a refund row of its order." );
			$this->assertTrue( '' === $object || str_contains( $object, $uuid ), "Claim {$uuid} names the row of refund object {$object}, another refund's." );
			$this->assertSame( $uuid, $documents[ $claim['transaction_id'] ] ?? $uuid, "Claim {$uuid} names a row another refund's document states." );
			$this->assertNotContains( $claim['transaction_id'], $named, "Claim {$uuid} names a row another claim names." );

			$named[] = $claim['transaction_id'];
		}
	}

	/**
	 * Reads an order's lines' uuids, in their order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> The uuids.
	 */
	protected function lineUuids( int $orderId ): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT line_uuid FROM %i WHERE order_id = %d ORDER BY sort_order', $this->table( OrderTables::LINES ), $orderId ), 'line_uuid' ) );
	}

	/**
	 * Reads an order line's row.
	 *
	 * @since 0.1.0
	 *
	 * @param string $lineUuid The line.
	 * @return array<string, mixed> The row.
	 */
	protected function lineRow( string $lineUuid ): array {
		return (array) $this->db->fetchRow( 'SELECT * FROM %i WHERE line_uuid = %s', $this->table( OrderTables::LINES ), $lineUuid );
	}

	/**
	 * Reads the stored tax components of an order line, or of the order's shipping, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $orderId  The order.
	 * @param string|null $lineUuid The line, or null for the shipping's.
	 * @return list<array<string, mixed>> The rows.
	 */
	protected function componentRows( int $orderId, ?string $lineUuid ): array {
		if ( null === $lineUuid ) {
			return $this->db->fetchAll(
				"SELECT c.* FROM %i c JOIN %i a ON a.id = c.order_adjustment_id WHERE c.order_id = %d AND a.scope = 'shipping' ORDER BY c.id",
				$this->table( OrderTables::TAX_COMPONENTS ),
				$this->table( OrderTables::ADJUSTMENTS ),
				$orderId
			);
		}

		return $this->db->fetchAll( 'SELECT * FROM %i WHERE order_line_id = %d ORDER BY id', $this->table( OrderTables::TAX_COMPONENTS ), (int) $this->lineRow( $lineUuid )['id'] );
	}

	/**
	 * Adds up, in SQL, what every refund returned of an order line: net, tax, gross and their base twins, and the units.
	 *
	 * @since 0.1.0
	 *
	 * @param string $lineUuid The line.
	 * @return array<string, int> The sums, by column.
	 */
	protected function returnedOfLine( string $lineUuid ): array {
		return self::ints(
			(array) $this->db->fetchRow(
				'SELECT COALESCE( SUM( quantity ), 0 ) AS quantity, COALESCE( SUM( net_minor ), 0 ) AS net_minor, COALESCE( SUM( tax_minor ), 0 ) AS tax_minor, COALESCE( SUM( gross_minor ), 0 ) AS gross_minor, '
				. 'COALESCE( SUM( base_net_minor ), 0 ) AS base_net_minor, COALESCE( SUM( base_tax_minor ), 0 ) AS base_tax_minor, COALESCE( SUM( base_gross_minor ), 0 ) AS base_gross_minor FROM %i WHERE order_line_id = %d',
				$this->table( RefundTables::LINES ),
				(int) $this->lineRow( $lineUuid )['id']
			)
		);
	}

	/**
	 * Adds up, in SQL, what every refund returned of a stored tax component, in both currencies.
	 *
	 * @since 0.1.0
	 *
	 * @param int $componentId The component.
	 * @return array<string, int> The sums, by column.
	 */
	protected function returnedOfComponent( int $componentId ): array {
		return self::ints(
			(array) $this->db->fetchRow(
				'SELECT COALESCE( SUM( net_minor ), 0 ) AS net_minor, COALESCE( SUM( tax_minor ), 0 ) AS tax_minor, COALESCE( SUM( gross_minor ), 0 ) AS gross_minor, '
				. 'COALESCE( SUM( base_net_minor ), 0 ) AS base_net_minor, COALESCE( SUM( base_tax_minor ), 0 ) AS base_tax_minor, COALESCE( SUM( base_gross_minor ), 0 ) AS base_gross_minor FROM %i WHERE order_tax_component_id = %d',
				$this->table( RefundTables::COMPONENTS ),
				$componentId
			)
		);
	}

	/**
	 * Reads an order's refund documents, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<array<string, mixed>> The rows.
	 */
	protected function refundRows( int $orderId ): array {
		return $this->db->fetchAll( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $this->table( RefundTables::REFUNDS ), $orderId );
	}

	/**
	 * Counts the rows of each refund table.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> The counts, by table.
	 */
	protected function refundTableCounts(): array {
		$counts = array();

		foreach ( RefundTables::names() as $name ) {
			$counts[ $name ] = (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( $name ) );
		}

		return $counts;
	}

	/**
	 * Returns a refund statement prepared for connection B, from its own constant, its derived row repeated once.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant of MysqlRefundRepository.
	 * @param mixed  ...$values Its values.
	 * @return string The statement, ready to send.
	 */
	protected function rawRefund( string $statement, mixed ...$values ): string {
		global $wpdb;

		$statement = str_contains( $statement, ' ) AS portion' ) ? ModuleStatements::forDerivedRows( $statement, 1 ) : $statement;

		list( $sql, $arguments ) = MysqlRefundRepository::expand( $statement, $values, fn( string $name ): string => $this->table( $name ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the module's constant, expanded; this is its prepare step.
		return (string) $wpdb->prepare( $sql, ...$arguments );
	}

	/**
	 * Picks a stored row's net, tax and gross and their base twins, as ints.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param string               $prefix Optional. What the columns' names begin with after `base_`, such as `line_`. Default ''.
	 * @return array<string, int> The six figures, by the names returnedOfLine() and returnedOfComponent() use.
	 */
	protected static function figures( array $row, string $prefix = '' ): array {
		$figures = array();

		foreach ( array( 'net_minor', 'tax_minor', 'gross_minor' ) as $column ) {
			$figures[ $column ] = (int) $row[ $prefix . $column ];
		}

		foreach ( array( 'net_minor', 'tax_minor', 'gross_minor' ) as $column ) {
			$figures[ 'base_' . $column ] = (int) $row[ 'base_' . $prefix . $column ];
		}

		return $figures;
	}

	/**
	 * Casts a row's values to ints.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row The row.
	 * @return array<string, int> The values.
	 */
	private static function ints( array $row ): array {
		return array_map( static fn( mixed $value ): int => (int) $value, $row );
	}
}

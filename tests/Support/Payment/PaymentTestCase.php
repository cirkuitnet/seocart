<?php
/**
 * PaymentTestCase: the base of the tests that apply gateway results to real intents and orders
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\Migrations\CreatePaymentTables;
use SEOCart\Payment\Infrastructure\Migrations\CreateRefundClaimTable;
use SEOCart\Payment\Infrastructure\Migrations\CreateRefundTables;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Support\Currency;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\InMemoryGrantLedger;
use SEOCart\Tests\Support\Doubles\RecordingGateway;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Order\OrderTestCase;
use SEOCart\Tests\Support\ReloadsRoles;

/**
 * An OrderTestCase with the payment module's tables, the refund tables included, and the payment service wired as the kernel wires it, over the stub gateway.
 *
 * Owns one fact: how a payment test gets an order with its intent, delivers a gateway result
 * the way the caller's unit of work does, and reads back what moved. The gateway is the stub
 * behind a RecordingGateway, so a test can ask which calls were made and at what depth. The
 * default order is the two-line fixture in EUR with a USD base: 3080 to pay, 2464 in the base.
 *
 * Connection B sends the payment module's own statements, prepared from their public constants
 * (rawPayment()), as OrderTestCase's raw() does for the order's.
 *
 * @since 0.1.0
 */
abstract class PaymentTestCase extends OrderTestCase {

	use CreatesUsers;
	use ReloadsRoles;

	/**
	 * The fixture order's currency.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const CURRENCY = 'EUR';

	/**
	 * The fixture order's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const BASE = 'USD';

	/**
	 * The fixture order's grand total, in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	protected const GRAND_TOTAL = 3080;

	/**
	 * The fixture order's grand total in the base currency, in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	protected const BASE_GRAND_TOTAL = 2464;

	/**
	 * The order uuid an authorization is asked with, which the stub does not read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	protected const ORDER_UUID = '01928c3e-0000-7000-8000-0000000000a1';

	/**
	 * The order number an authorization is asked with, which the stub does not read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	protected const ORDER_NUMBER = '1001';

	/**
	 * The payment service over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentService
	 */
	protected PaymentService $payments;

	/**
	 * The gateway the service over `$this->db` calls: the stub, recorded.
	 *
	 * @since 0.1.0
	 *
	 * @var RecordingGateway
	 */
	protected RecordingGateway $gateway;

	/**
	 * The roles option as it was before the test installed the plugin's roles; null while they are not installed.
	 *
	 * @since 0.1.0
	 *
	 * @var array{0: mixed}|null
	 */
	private ?array $roles = null;

	/**
	 * Creates the payment module's tables and the service.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreatePaymentTables() )->up( $operations );
		( new CreateRefundTables() )->up( $operations );
		( new CreateRefundClaimTable() )->up( $operations );

		$this->gateway  = new RecordingGateway( new StubGateway(), $this->db );
		$this->payments = $this->paymentsOver( $this->db, $this->ids, $this->gateway );
	}

	/**
	 * Deletes the users the test created and puts the roles back.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		$installed = null !== $this->roles;

		if ( $installed ) {
			update_option( self::rolesOption(), $this->roles[0] );

			$this->roles = null;
		}

		parent::tear_down();

		if ( $installed ) {
			self::reloadRoles();
		}
	}

	/**
	 * Returns a new user with a role, the plugin's roles installed as activation installs them; by default one who may capture payments.
	 *
	 * @since 0.1.0
	 *
	 * @param string $role Optional. The user's role. Default `seocart_order_agent`, which holds the capture capability.
	 * @return Actor The user, acting in person.
	 */
	protected function userWithRole( string $role = 'seocart_order_agent' ): Actor {
		if ( null === $this->roles ) {
			$this->roles = array( get_option( self::rolesOption() ) );

			( new CapabilityInstaller( new CapabilityDeclaration(), new InMemoryGrantLedger() ) )->install();
		}

		return Actor::user( $this->createUser( $role ) );
	}

	/**
	 * Builds the payment service over a connection, as the kernel builds it.
	 *
	 * @since 0.1.0
	 *
	 * @param Database       $db      The connection.
	 * @param IdGenerator    $ids     The ids it mints: a second runner needs its own range.
	 * @param PaymentGateway $gateway The gateway it calls.
	 * @return PaymentService The service.
	 */
	protected function paymentsOver( Database $db, IdGenerator $ids, PaymentGateway $gateway ): PaymentService {
		return $this->paymentsWith( $db, $ids, TestGateways::of( $gateway ) );
	}

	/**
	 * Builds the payment service over a connection and a gateway registry.
	 *
	 * @since 0.2.0
	 *
	 * @param Database    $db       The connection.
	 * @param IdGenerator $ids      The ids it mints.
	 * @param Gateways    $gateways The gateways it finds each intent's in.
	 * @return PaymentService The service.
	 */
	protected function paymentsWith( Database $db, IdGenerator $ids, Gateways $gateways ): PaymentService {
		return new PaymentService(
			new MysqlPaymentRepository( $db, $ids ),
			$gateways,
			$this->ordersOver( $db, $ids ),
			$db,
			$this->publisherOver( $db ),
			new Authorizer( new CapabilityDeclaration() ),
			$ids,
			FrozenClock::at( self::NOW ),
			$this->correlation,
			$this->reporter()
		);
	}

	/**
	 * Opens a second runner of the payment code: the service over its own connection, closed in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: PaymentService, 1: Database} The service, over the stub gateway, and the connection it runs on.
	 */
	protected function secondPayments(): array {
		list( , $db ) = $this->secondOrders();

		return array( $this->paymentsOver( $db, new SequentialIdGenerator( 700000 ), new StubGateway() ), $db );
	}

	/**
	 * Places an order and creates its intent for the order's grand total, in one transaction, as placement does.
	 *
	 * @since 0.1.0
	 *
	 * @param NewOrder|null $order Optional. The document. Default the two-line fixture in EUR with a USD base.
	 * @return array{0: InsertedOrder, 1: IntentRef} The order and its intent.
	 */
	protected function placeWithIntent( ?NewOrder $order = null ): array {
		$order ??= NewOrders::forTwoLines( self::CURRENCY, self::BASE );

		return $this->db->transaction(
			function () use ( $order ): array {
				$inserted = $this->orders->insert( $order, Actor::user( 0 ) );
				$intent   = $this->payments->createIntent( $inserted->id, StubGateway::ID, Mode::Test, $order->totals->grandTotal, $order->totals->baseGrandTotal, $inserted->conversionContextId );

				return array( $inserted, $intent );
			}
		);
	}

	/**
	 * Places an order in the base currency, USD, and authorizes and captures its whole amount, 3080.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: InsertedOrder, 1: IntentRef} The order and its intent.
	 */
	protected function placeCaptured(): array {
		list( $order, $intent ) = $this->placeWithIntent( NewOrders::forTwoLines( 'USD', 'USD' ) );

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );
		$this->deliver( self::stubResult( $intent, Operation::Capture, Outcome::Approved, self::GRAND_TOTAL, 'USD', 'stub-cap-' . $intent->uuid ) );

		return array( $order, $intent );
	}

	/**
	 * Builds a result of the stub gateway about an intent, as a provider would report it.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentRef $intent    The intent.
	 * @param Operation $operation The operation.
	 * @param Outcome   $outcome   The outcome.
	 * @param int       $minor     The amount, in minor units.
	 * @param string    $currency  The currency.
	 * @param string    $objectId  The provider's object for the outcome.
	 * @return GatewayResult The result.
	 */
	protected static function stubResult( IntentRef $intent, Operation $operation, Outcome $outcome, int $minor, string $currency, string $objectId ): GatewayResult {
		return new GatewayResult( StubGateway::ID, $operation, $outcome, $intent->uuid, Money::of( $minor, Currency::of( $currency ) ), $objectId );
	}

	/**
	 * Asks the stub gateway to authorize an intent, as placement does between its two units of work.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentRef $intent The intent.
	 * @param string    $token  The stub's script, such as StubGateway::APPROVE.
	 * @return GatewayResult The stub's answer, not applied.
	 */
	protected function authorizeWith( IntentRef $intent, string $token ): GatewayResult {
		return $this->payments->authorize( $intent->uuid, array( PaymentService::PAYMENT_TOKEN => $token ), self::ORDER_UUID, self::ORDER_NUMBER );
	}

	/**
	 * Applies a gateway result in a transaction of its own, as the caller's unit of work does.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayResult $result The result.
	 * @return Application What was done.
	 */
	protected function deliver( GatewayResult $result ): Application {
		return $this->db->transaction( fn(): Application => $this->payments->applyGatewayResult( $result, self::system() ) );
	}

	/**
	 * Returns the actor results are applied on the authority of: the payment process, for user 3.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor The actor.
	 */
	protected static function system(): Actor {
		return Actor::system( 'payment', 3 );
	}

	/**
	 * Reads an intent's row.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The intent's uuid.
	 * @return array<string, mixed> The row.
	 */
	protected function intentRow( string $uuid ): array {
		return (array) $this->db->fetchRow( 'SELECT * FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $uuid );
	}

	/**
	 * Reads an order's statuses, payment amounts and flag.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return array<string, mixed> The columns.
	 */
	protected function orderRow( int $orderId ): array {
		return (array) $this->db->fetchRow(
			'SELECT status, payment_status, authorized_minor, paid_minor, refunded_minor, due_minor, base_authorized_minor, base_paid_minor, base_refunded_minor, has_unreconciled_money, order_number FROM %i WHERE id = %d',
			$this->table( OrderTables::ORDERS ),
			$orderId
		);
	}

	/**
	 * Reads an order's ledger rows, oldest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<array<string, mixed>> The rows.
	 */
	protected function ledgerOf( int $orderId ): array {
		return $this->db->fetchAll( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::TRANSACTIONS ), $orderId );
	}

	/**
	 * Picks columns of a row, in the order asked.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param string               ...$keys The columns.
	 * @return array<string, mixed> The columns' values, by name, in the order asked.
	 */
	protected static function pick( array $row, string ...$keys ): array {
		$picked = array();

		foreach ( $keys as $key ) {
			$picked[ $key ] = $row[ $key ] ?? null;
		}

		return $picked;
	}

	/**
	 * Reads the payload of the newest outbox row of an event.
	 *
	 * @since 0.1.0
	 *
	 * @param string $eventName The event's name.
	 * @return array<string, mixed> The payload, or an empty array when there is no row.
	 */
	protected function latestPayload( string $eventName ): array {
		$stored = $this->db->fetchValue( 'SELECT payload_json FROM %i WHERE event_name = %s ORDER BY id DESC LIMIT 1', $this->table( OutboxTable::NAME ), $eventName );

		return null === $stored ? array() : Outbox::decode( (string) $stored )['p'];
	}

	/**
	 * Takes everything a duplicate must leave alone: every row of the payment tables, the orders and their events, and the outbox's size.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The state.
	 */
	protected function snapshot(): array {
		$state = array();

		foreach ( array( PaymentTables::INTENTS, PaymentTables::TRANSACTIONS, OrderTables::ORDERS, OrderTables::EVENTS ) as $table ) {
			$state[ $table ] = $this->db->fetchAll( 'SELECT * FROM %i ORDER BY id', $this->table( $table ) );
		}

		$state[ OutboxTable::NAME ] = (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( OutboxTable::NAME ) );

		return $state;
	}

	/**
	 * Returns a statement of the payment module prepared for connection B, from its own constant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant of MysqlPaymentRepository.
	 * @param mixed  ...$values Its values.
	 * @return string The statement, ready to send.
	 */
	protected function rawPayment( string $statement, mixed ...$values ): string {
		global $wpdb;

		list( $sql, $arguments ) = MysqlPaymentRepository::expand( $statement, $values, fn( string $name ): string => $this->table( $name ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the module's constant, expanded; this is its prepare step.
		return (string) $wpdb->prepare( $sql, ...$arguments );
	}

	/**
	 * Returns the name of the current site's roles option.
	 *
	 * @since 0.1.0
	 *
	 * @return string The option's name.
	 */
	private static function rolesOption(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'user_roles';
	}
}

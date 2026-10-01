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

use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Gateway\CaptureRequest;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Support\IdGenerator;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

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
	 * Creates the service.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->agent   = null;
		$this->refunds = $this->refundsOver( $this->db, $this->ids, $this->gateway );
	}

	/**
	 * Builds the refund service over a connection, as the kernel builds it.
	 *
	 * @since 0.1.0
	 *
	 * @param Database            $db      The connection.
	 * @param IdGenerator         $ids     The ids the order and payment code mints: a second runner needs its own range.
	 * @param PaymentGateway      $gateway The gateway it calls.
	 * @param EventPublisher|null $events  Optional. What publishes the refund's event. Default the outbox's publisher over the connection.
	 * @return RefundService The service.
	 */
	protected function refundsOver( Database $db, IdGenerator $ids, PaymentGateway $gateway, ?EventPublisher $events = null ): RefundService {
		return new RefundService(
			new MysqlRefundRepository( $db ),
			new MysqlOrderRepository( new OrderStatements( $db ), $ids ),
			$this->paymentsOver( $db, $ids, $gateway ),
			$gateway,
			$db,
			$events ?? $this->publisherOver( $db ),
			new Authorizer( new CapabilityDeclaration() ),
			FrozenClock::at( self::NOW )
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
		$this->deliver( $this->gateway->capture( new CaptureRequest( $intent->uuid, (string) $this->intentRow( $intent->uuid )['provider_intent_id'], $order->totals->grandTotal ) ) );

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
	 * Builds a refund request.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $orderUuid The order.
	 * @param array<string, int> $units     The units of each line, by line uuid.
	 * @param bool               $shipping  Whether to give back what is left of the shipping.
	 * @return RefundRequest The request.
	 */
	protected static function request( string $orderUuid, array $units, bool $shipping ): RefundRequest {
		$lines = array();

		foreach ( $units as $lineUuid => $quantity ) {
			$lines[] = new RefundLineRequest( (string) $lineUuid, $quantity );
		}

		return new RefundRequest( $orderUuid, $lines, $shipping, self::REASON );
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

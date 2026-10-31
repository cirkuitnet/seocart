<?php
/**
 * TransferTestCase: the base of the tests that move stock between locations against real tables
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Inventory;

use SEOCart\Inventory\Application\Transfers;
use SEOCart\Inventory\Domain\Transfer;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\InMemoryGrantLedger;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\ReloadsRoles;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The base plants the allocations an order's placement would make, and reads the tables back.

/**
 * A StockTestCase with the transfer service, a store manager who may use it, and the reads a transfer test checks.
 *
 * Owns one fact: how a transfer test gets the service over real tables and what it reads back.
 * The service publishes through the real publisher, as the stock service does, and queues through
 * a recording queue that keeps the queue's rules. The plugin's roles are installed as activation
 * installs them, and the roles option is put back afterwards. The order allocations a placement
 * would make are planted, with their item's `allocated`, so the stock check of doctor agrees.
 * Everything read back is read through connection B, which sees what is committed.
 *
 * @since 0.2.0
 */
abstract class TransferTestCase extends StockTestCase {

	use CreatesUsers;
	use ReloadsRoles;

	/**
	 * The last order id handed out, across every test of the run.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private static int $lastOrder = 900000;

	/**
	 * The service under test, over `$this->db`.
	 *
	 * @since 0.2.0
	 *
	 * @var Transfers
	 */
	protected Transfers $transfers;

	/**
	 * Who acts: a store manager.
	 *
	 * @since 0.2.0
	 *
	 * @var Actor
	 */
	protected Actor $manager;

	/**
	 * The roles option as it was before the test installed the plugin's roles.
	 *
	 * @since 0.2.0
	 *
	 * @var mixed
	 */
	private mixed $roles = null;

	/**
	 * The second databases this test opened.
	 *
	 * @since 0.2.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $seconds = array();

	/**
	 * Installs the plugin's roles and builds the service.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->roles = get_option( self::rolesOption() );

		( new CapabilityInstaller( new CapabilityDeclaration(), new InMemoryGrantLedger() ) )->install();

		$this->transfers = $this->transfersOver( $this->db, new SequentialIdGenerator( 600000 ) );
		$this->manager   = Actor::user( $this->createUser( 'seocart_manager' ) );
	}

	/**
	 * Closes the second databases, deletes the users and puts the roles back.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		foreach ( $this->seconds as $second ) {
			$second->close();
		}

		$this->seconds = array();

		$this->deleteCreatedUsers();

		update_option( self::rolesOption(), $this->roles );

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Opens a second runner of the transfer service, over its own connection. It is closed in tear_down().
	 *
	 * @since 0.2.0
	 *
	 * @return Transfers The service.
	 */
	protected function secondTransfers(): Transfers {
		return $this->secondRunner()[0];
	}

	/**
	 * Opens a second runner of the transfer service, and returns it with its connection, for a seam that runs in the caller's unit of work. It is closed in tear_down().
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: Transfers, 1: Database} The service, and the connection it runs on.
	 */
	protected function secondRunner(): array {
		$second          = new SecondDatabase( $this->reporter() );
		$this->seconds[] = $second;

		return array( $this->transfersOver( $second->db(), new SequentialIdGenerator( 650000 ) ), $second->db() );
	}

	/**
	 * Returns an order id no earlier test of the run used.
	 *
	 * @since 0.2.0
	 *
	 * @return int The id.
	 */
	protected static function order(): int {
		return ++self::$lastOrder;
	}

	/**
	 * Plants an order line's open allocation at an item, with the item's `allocated`, as a placement leaves it.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId   The variant, whose item at the location exists.
	 * @param int $locationId  The location.
	 * @param int $orderId     The order.
	 * @param int $orderLineId The order line.
	 * @param int $quantity    The units.
	 * @return int The allocation's id.
	 */
	protected function plantAllocation( int $variantId, int $locationId, int $orderId, int $orderLineId, int $quantity ): int {
		$this->db->execute( "INSERT INTO %i ( variant_id, location_id, order_id, order_line_id, quantity, posted_quantity, state, created_at ) VALUES ( %d, %d, %d, %d, %d, 0, 'open', UTC_TIMESTAMP(6) )", $this->table( InventoryTables::ALLOCATIONS ), $variantId, $locationId, $orderId, $orderLineId, $quantity );

		$id = $this->db->lastInsertId();

		$this->db->execute( 'UPDATE %i SET allocated = allocated + %d WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $quantity, $variantId, $locationId );

		return $id;
	}

	/**
	 * Requests an order's transfers to a location inside a unit of work at READ COMMITTED, as a placement's settlement does.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId       The order.
	 * @param int $destinationId The location.
	 * @return list<Transfer> The transfers requested.
	 */
	protected function requestFor( int $orderId, int $destinationId ): array {
		return $this->db->transaction( fn(): array => $this->transfers->requestForOrder( $orderId, $destinationId ), null, Isolation::ReadCommitted );
	}

	/**
	 * Cancels an order's stock inside a unit of work at READ COMMITTED, as the order's cancel does.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 */
	protected function detach( int $orderId ): void {
		$this->db->transaction( fn() => $this->transfers->detachOrder( $orderId ), null, Isolation::ReadCommitted );
	}

	/**
	 * Creates a draft as the store manager.
	 *
	 * @since 0.2.0
	 *
	 * @param int             $source      The source location.
	 * @param int             $destination The destination location.
	 * @param array<int, int> $units       The units of each variant, by variant id.
	 * @return array<string, mixed> The draft, as the operation answers it.
	 */
	protected function draft( int $source, int $destination, array $units ): array {
		$b     = $this->secondConnection();
		$lines = array();

		foreach ( $units as $variantId => $quantity ) {
			$lines[] = array(
				'variant_id' => $variantId,
				'quantity'   => $quantity,
			);
		}

		return $this->transfers->createTransfer(
			array(
				'source_location_uuid'      => $this->uuidOf( $b, $source ),
				'destination_location_uuid' => $this->uuidOf( $b, $destination ),
				'lines'                     => $lines,
			),
			$this->manager
		);
	}

	/**
	 * Sends a transfer as the store manager.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer.
	 * @return array<string, mixed> The transfer, as the operation answers it.
	 */
	protected function send( string $uuid ): array {
		return $this->transfers->sendTransfer( array( 'transfer_uuid' => $uuid ), $this->manager );
	}

	/**
	 * Receives lines of a transfer as the store manager.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                               $uuid  The transfer.
	 * @param list<array{0: int, 1: int, 2: int, 3: int, 4?: int}> $lines Each line's id, the units received, the units lost, the units it had received, and the units it had lost, 0 when left out.
	 * @param Transfers|null                                       $via   Optional. The service to receive through. Default null, the test's.
	 * @return array<string, mixed> The transfer, as the operation answers it.
	 */
	protected function receive( string $uuid, array $lines, ?Transfers $via = null ): array {
		return ( $via ?? $this->transfers )->receiveTransfer(
			array(
				'transfer_uuid' => $uuid,
				'lines'         => array_map(
					static fn( array $line ): array => array(
						'line_id'                    => $line[0],
						'received'                   => $line[1],
						'lost'                       => $line[2],
						'expected_received_quantity' => $line[3],
						'expected_lost_quantity'     => $line[4] ?? 0,
					),
					$lines
				),
			),
			$this->manager
		);
	}

	/**
	 * Returns the id of a transfer's line of a variant, from an answer.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $transfer  The transfer, as an operation answered it.
	 * @param int                  $variantId The variant.
	 * @return int The line's id.
	 */
	protected static function lineOf( array $transfer, int $variantId ): int {
		foreach ( (array) $transfer['lines'] as $line ) {
			if ( $variantId === $line['variant_id'] ) {
				return (int) $line['line_id'];
			}
		}

		self::fail( sprintf( 'The transfer has no line of variant %d.', $variantId ) );
	}

	/**
	 * Reads a location's public identifier as connection B sees it.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b          Connection B.
	 * @param int              $locationId The location.
	 * @return string The uuid.
	 */
	protected function uuidOf( SecondConnection $b, int $locationId ): string {
		return (string) $b->fetchValue( sprintf( 'SELECT uuid FROM `%s` WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $locationId ) );
	}

	/**
	 * Reads a transfer's row as connection B sees it.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b    Connection B.
	 * @param string           $uuid The transfer.
	 * @return array<string, string|null> The id, kind, state, order, note and who created it; empty when there is none.
	 */
	protected function committedTransfer( SecondConnection $b, string $uuid ): array {
		return (array) $b->fetchRow( sprintf( "SELECT id, kind, state, order_id, note, created_by, sent_at IS NOT NULL AS sent, received_at IS NOT NULL AS received FROM `%s` WHERE uuid = '%s'", $this->table( InventoryTables::TRANSFERS ), $uuid ) );
	}

	/**
	 * Reads a transfer's lines as connection B sees them.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b    Connection B.
	 * @param string           $uuid The transfer.
	 * @return list<string> Each line as `variant:quantity:received:lost:allocation`, ascending by variant, `-` for no allocation.
	 */
	protected function committedLines( SecondConnection $b, string $uuid ): array {
		$rows = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT( l.variant_id, ':', l.quantity, ':', l.received_quantity, ':', l.lost_quantity, ':', COALESCE( l.allocation_id, '-' ) ) ORDER BY l.variant_id, l.id SEPARATOR ',' ) FROM `%s` l JOIN `%s` t ON t.id = l.transfer_id WHERE t.uuid = '%s'", $this->table( InventoryTables::TRANSFER_LINES ), $this->table( InventoryTables::TRANSFERS ), $uuid ) );

		return '' === $rows ? array() : explode( ',', $rows );
	}

	/**
	 * Reads an order's allocations as connection B sees them.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b       Connection B.
	 * @param int              $orderId The order.
	 * @return list<string> Each as `variant@location:line:quantity:state`, ascending by variant, location and line.
	 */
	protected function committedAllocations( SecondConnection $b, int $orderId ): array {
		$rows = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT( variant_id, '@', location_id, ':', order_line_id, ':', quantity, ':', state ) ORDER BY variant_id, location_id, order_line_id SEPARATOR ',' ) FROM `%s` WHERE order_id = %d", $this->table( InventoryTables::ALLOCATIONS ), $orderId ) );

		return '' === $rows ? array() : explode( ',', $rows );
	}

	/**
	 * Reads the ledger entries a transfer wrote, as connection B sees them.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b    Connection B.
	 * @param string           $uuid The transfer.
	 * @return list<string> Each as `variant@location:delta:on_hand_after:reason`, in the order written.
	 */
	protected function transferLedger( SecondConnection $b, string $uuid ): array {
		$rows = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT( g.variant_id, '@', g.location_id, ':', g.delta, ':', g.on_hand_after, ':', g.reason ) ORDER BY g.id SEPARATOR ',' ) FROM `%s` g JOIN `%s` t ON t.id = g.transfer_id WHERE t.uuid = '%s'", $this->table( InventoryTables::LEDGER ), $this->table( InventoryTables::TRANSFERS ), $uuid ) );

		return '' === $rows ? array() : explode( ',', $rows );
	}

	/**
	 * Asserts that a call is refused with an error code, and returns the refusal.
	 *
	 * @since 0.2.0
	 *
	 * @param ErrorCode $code The code.
	 * @param callable  $call The call.
	 * @return CodedException The refusal.
	 */
	protected function assertRefused( ErrorCode $code, callable $call ): CodedException {
		try {
			$call();
		} catch ( CodedException $refused ) {
			$this->assertSame( $code, $refused->errorCode(), $refused->getMessage() );

			return $refused;
		}

		$this->fail( sprintf( 'The call was not refused with %s.', $code->value ) );
	}

	/**
	 * Returns the name of the current site's roles option.
	 *
	 * @since 0.2.0
	 *
	 * @return string For example `wptests_user_roles`.
	 */
	protected static function rolesOption(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'user_roles';
	}
}

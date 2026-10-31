<?php
/**
 * Tests a transfer's create racing the deletion of its destination, on two connections
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Inventory\Infrastructure\MysqlTransferRepository;
use SEOCart\Platform\Database\ServerKind;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Inventory\StockServices;
use SEOCart\Tests\Support\Inventory\TransferTestCase;

/**
 * A transfer is created under a shared lock of both its locations, and a location is deleted
 * under its own lock, so whichever comes first, the other waits: the deletion then finds the
 * transfer and is refused, or the create finds the location gone and is refused. No transfer
 * ever names a location that no longer exists.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class TransferLocationRaceTest extends TransferTestCase {

	/**
	 * The locations service, over the test's connection.
	 *
	 * @since 0.2.0
	 *
	 * @var Locations
	 */
	private Locations $locations;

	/**
	 * Builds the locations service.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->locations = StockServices::locations( $this->db, new SequentialIdGenerator( 340000 ), $this->transfers );
	}

	/**
	 * Tests that a deletion of the destination begun while the transfer is being created waits for the create, then finds the transfer and is refused.
	 *
	 * A creates a transfer. Once A holds both locations' shared locks and is about to write the
	 * transfer, B begins the deletion of its destination: B's lock of the location waits on A's.
	 * A commits; the deletion then finds the transfer and is refused with the reason `transfers`.
	 *
	 * Planted violation, shown red and removed: in MysqlLocationRepository::LOCK_SHARED, leave out
	 * `LOCK IN SHARE MODE`. B's lock no longer waits, so the deletion could pass between A's read
	 * of the locations and its write of the transfer.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_a_deletion_of_the_destination_waits_for_the_create_and_is_refused(): void {
		$b           = $this->secondConnection();
		$source      = $this->plantLocation();
		$destination = $this->plantLocation();
		$variant     = self::variant();
		$uuid        = $this->uuidOf( $b, $destination );
		$lock        = $this->raw( MysqlLocationRepository::LOCK_RECORD, $uuid );

		$this->stockItem( $variant, 5, $source );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlTransferRepository::INSERT_TRANSFER ),
			function () use ( $b, $lock ): void {
				$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $lock );
				$this->awaitWaiting( $b, $lock, 'statistics' );
			}
		);

		$draft = $this->draft( $source, $destination, array( $variant => 2 ) );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s lock of the destination must go through once A commits.' );

		$b->reap();
		$b->query( 'ROLLBACK' );

		$refused = $this->assertRefused( InventoryError::LocationInUse, fn(): array => $this->locations->deleteLocation( array( 'location_uuid' => $uuid ), $this->manager ) );

		$this->assertSame( array( 'reason' => 'transfers' ), $refused->details(), 'The deletion is refused for the transfer it waited for.' );
		$this->assertSame( 'draft', $this->committedTransfer( $b, (string) $draft['transfer_uuid'] )['state'] );
	}

	/**
	 * Tests that a create begun while its destination is being deleted waits for the deletion, then finds the destination gone and is refused.
	 *
	 * A deletes a location. Once A holds its lock and is about to delete its row, B begins a
	 * transfer to it: B's shared lock of the two locations waits on A's. A commits; the create is
	 * then refused `stock.location_missing`, and no transfer names the location that is gone.
	 *
	 * Planted violation, shown red and removed: in MysqlLocationRepository::LOCK_RECORD, leave out
	 * `FOR UPDATE`. B's lock no longer waits, so a create could write its transfer to a location
	 * the deletion is about to remove.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_a_create_waits_for_the_deletion_of_its_destination_and_is_refused(): void {
		$b           = $this->secondConnection();
		$source      = $this->plantLocation();
		$destination = $this->plantLocation();
		$variant     = self::variant();
		$from        = $this->uuidOf( $b, $source );
		$to          = $this->uuidOf( $b, $destination );
		$lock        = $this->raw( MysqlLocationRepository::LOCK_SHARED, array( $from, $to ) );

		$this->stockItem( $variant, 5, $source );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlLocationRepository::DELETE_LOCATION ),
			function () use ( $b, $lock ): void {
				$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $lock );
				$this->awaitWaiting(
					$b,
					$lock,
					array(
						ServerKind::MySQL->value   => 'executing',
						ServerKind::MariaDB->value => 'Sending data',
					)
				);
			}
		);

		$this->locations->deleteLocation( array( 'location_uuid' => $to ), $this->manager );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s lock of the locations must go through once A commits.' );

		$b->reap();
		$b->query( 'ROLLBACK' );

		$this->assertRefused(
			InventoryError::LocationMissing,
			fn(): array => $this->transfers->createTransfer(
				array(
					'source_location_uuid'      => $from,
					'destination_location_uuid' => $to,
					'lines'                     => array(
						array(
							'variant_id' => $variant,
							'quantity'   => 1,
						),
					),
				),
				$this->manager
			)
		);

		$this->assertSame( '0', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE destination_location_id = %d', $this->table( InventoryTables::TRANSFERS ), $destination ) ), 'No transfer names the location that is gone.' );
	}
}

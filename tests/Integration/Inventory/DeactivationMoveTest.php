<?php
/**
 * Tests a deactivation that moves its location's available stock as one transfer, worked page by page by its job
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Inventory\Application\LocationOperations;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Application\Transfers;
use SEOCart\Inventory\Domain\Event\StockTransferred;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Jobs\MoveLocationStock;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\Inventory\StockServices;
use SEOCart\Tests\Support\Inventory\TransferTestCase;

/**
 * The deactivation's move, held to what is committed: one transfer, each page sent and received at once, available units only, resumed from its lines, received at the end.
 *
 * The job runs with no time budget and pages of one variant, so each run is one page and the
 * test sees every page end.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class DeactivationMoveTest extends TransferTestCase {

	use GrantsCapabilities;

	/**
	 * The locations service, over the test's transfer service.
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

		$this->locations = StockServices::locations( $this->db, new SequentialIdGenerator( 320000 ), $this->transfers );
	}

	/**
	 * Tests that a deactivation moving its stock writes one transfer in transit and queues its job once committed, and that the job then moves the available units only, a page at a time, and receives the transfer at the end.
	 *
	 * Planted violation, shown red and removed: in Transfers::moveItem(), move the item's on_hand
	 * rather than its available units: the units allocated and held leave too, and the source's
	 * `allocated` and `held` are left with no units under them.
	 *
	 * @since 0.2.0
	 */
	public function test_a_deactivation_moves_its_available_units_as_one_transfer(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$target = $this->plantLocation();
		$spread = self::variant();
		$whole  = self::variant();
		$taken  = self::variant();

		$this->stockItem( $spread, 5, $source );
		$this->stockItem( $whole, 3, $source );
		$this->stockItem( $taken, 2, $source );
		$this->plantAllocation( $spread, $source, self::order(), 1, 2 );
		$this->plantHold( $spread, 1, 600, null, $source );
		$this->plantAllocation( $taken, $source, self::order(), 1, 2 );

		$answer = $this->deactivate( $source, $target );
		$uuid   = (string) $answer['move_transfer_uuid'];

		$this->assertFalse( $answer['active'] );
		$this->assertSame(
			array(
				'kind'  => 'deactivation',
				'state' => 'in_transit',
				'sent'  => '1',
			),
			array_intersect_key( $this->committedTransfer( $b, $uuid ), array_flip( array( 'kind', 'state', 'sent' ) ) )
		);
		$this->assertSame( array(), $this->committedLines( $b, $uuid ) );
		$this->assertCount( 1, $this->queue->queued, 'The move\'s job is queued once the deactivation commits.' );

		$job = $this->queue->queued[0];
		$id  = (int) $this->committedTransfer( $b, $uuid )['id'];

		$this->assertSame( array( Transfers::MOVE_JOB, array( 'transfer_id' => $id ), 'transfer:' . $id ), array( $job->handler, $job->payload, $job->uniqueKey ) );

		$runs = $this->runMove( $job->payload );

		$this->assertSame( 3, $runs, 'One page per variant with units available, and one that finds no more.' );
		$this->assertSame( 'received', $this->committedTransfer( $b, $uuid )['state'] );
		$this->assertSame( array( $spread . ':2:2:0:-', $whole . ':3:3:0:-' ), $this->committedLines( $b, $uuid ), 'Each line sent and received at once; nothing of the fully allocated variant.' );
		$this->assertSame( array( 3, 2, 1 ), array_values( (array) $this->committedItem( $b, $spread, $source ) ), 'The allocated and held units stay.' );
		$this->assertSame( array( 0, 0, 0 ), array_values( (array) $this->committedItem( $b, $whole, $source ) ) );
		$this->assertSame( array( 2, 2, 0 ), array_values( (array) $this->committedItem( $b, $taken, $source ) ) );
		$this->assertSame( array( 2, 0, 0 ), array_values( (array) $this->committedItem( $b, $spread, $target ) ) );
		$this->assertSame( array( 3, 0, 0 ), array_values( (array) $this->committedItem( $b, $whole, $target ) ) );
		$this->assertNull( $this->committedItem( $b, $taken, $target ), 'Nothing moved, nothing created.' );
		$this->assertSame(
			array(
				$spread . '@' . $source . ':-2:3:transfer_out',
				$spread . '@' . $target . ':2:2:transfer_in',
				$whole . '@' . $source . ':-3:0:transfer_out',
				$whole . '@' . $target . ':3:3:transfer_in',
			),
			$this->transferLedger( $b, $uuid )
		);
		$this->assertSame( 4, $this->committedEvents( $b, StockTransferred::eventName() ), 'Two pages moved units, each sent and received.' );

		$listed = $this->transfers->listTransfers( array( 'location_uuid' => $this->uuidOf( $b, $source ) ), $this->manager );

		$this->assertSame( array( $uuid ), array_column( $listed['transfers'], 'transfer_uuid' ) );
		$this->assertSame( array( 'deactivation', 'received', 2, 5, 5, 0 ), array( $listed['transfers'][0]['kind'], $listed['transfers'][0]['state'], $listed['transfers'][0]['lines'], $listed['transfers'][0]['sent'], $listed['transfers'][0]['received'], $listed['transfers'][0]['lost'] ) );

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Tests that a run killed in the middle of a page loses that page only: the page is rolled back whole, and the next run goes on from the lines committed.
	 *
	 * The first page commits; the second is stopped at its last statement, once its variant's units
	 * left the source and arrived. A unit of the first variant is then counted at the source.
	 *
	 * Planted violation, shown red and removed: in Transfers::movePage(), start from the `$after`
	 * the run gives rather than from the transfer's last line: the run after the kill starts over,
	 * and moves the first variant again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_move_killed_in_a_page_goes_on_from_its_lines(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$target = $this->plantLocation();
		$first  = self::variant();
		$second = self::variant();

		$this->stockItem( $first, 2, $source );
		$this->stockItem( $second, 4, $source );

		$uuid = (string) $this->deactivate( $source, $target )['move_transfer_uuid'];
		$job  = new MoveLocationStock( $this->transfers, 0, 1 );

		$this->assertSame( MoveLocationStock::AGAIN_AFTER_SECONDS, $job->handle( $this->queue->queued[0]->payload ), 'The first page moved its variant.' );

		// The page's last statement: the destination's ledger entry, once the units left the source and arrived.
		$killed = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::APPEND_LEDGER ),
			static function (): void {
				throw new \RuntimeException( 'The process was killed.' );
			},
			2
		);

		try {
			$job->handle( $this->queue->queued[0]->payload );
			$this->fail( 'The page was not stopped.' );
		} catch ( \RuntimeException $stopped ) {
			$this->assertSame( 'The process was killed.', $stopped->getMessage() );
		}

		$this->assertTrue( $killed->fired );
		$this->assertSame( array( $first . ':2:2:0:-' ), $this->committedLines( $b, $uuid ), 'The killed page left nothing.' );
		$this->assertSame( array( 4, 0, 0 ), array_values( (array) $this->committedItem( $b, $second, $source ) ) );
		$this->assertNull( $this->committedItem( $b, $second, $target ), 'The units that arrived went back with the page.' );

		// A unit of the first variant counted at the source after its page: the move has passed it.
		$this->service->adjust( $first, $source, 1, LedgerReason::Recount, Actor::user( 1 ) );

		$this->assertSame( 2, $this->runMove( $this->queue->queued[0]->payload ) );
		$this->assertSame( array( $first . ':2:2:0:-', $second . ':4:4:0:-' ), $this->committedLines( $b, $uuid ) );
		$this->assertCount( 4, $this->transferLedger( $b, $uuid ), 'Each variant moved once.' );
		$this->assertSame( 1, (int) $this->committedItem( $b, $first, $source )['on_hand'], 'The move goes on from its lines, past the variants it moved.' );
		$this->assertSame( 'received', $this->committedTransfer( $b, $uuid )['state'] );
	}

	/**
	 * Tests that turning the location on again between two pages ends its move: the next page receives the transfer with the line it has, and the units not yet moved stay at the location.
	 *
	 * Planted violation, shown red and removed: in Transfers::movePage(), leave out the end of a
	 * move whose source is active: the next page moves the second variant's units away too.
	 *
	 * @since 0.2.0
	 */
	public function test_turning_the_location_on_again_ends_its_move(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$target = $this->plantLocation();
		$first  = self::variant();
		$second = self::variant();

		$this->stockItem( $first, 2, $source );
		$this->stockItem( $second, 4, $source );

		$uuid = (string) $this->deactivate( $source, $target )['move_transfer_uuid'];
		$job  = new MoveLocationStock( $this->transfers, 0, 1 );

		$this->assertSame( MoveLocationStock::AGAIN_AFTER_SECONDS, $job->handle( $this->queue->queued[0]->payload ), 'The first page moved its variant.' );

		$this->locations->updateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $source ),
				'active'        => true,
			),
			$this->manager
		);

		$this->assertNull( $job->handle( $this->queue->queued[0]->payload ), 'The next page ends the move.' );
		$this->assertSame( 'received', $this->committedTransfer( $b, $uuid )['state'] );
		$this->assertSame( array( $first . ':2:2:0:-' ), $this->committedLines( $b, $uuid ), 'The move keeps the line it moved, and adds none.' );
		$this->assertSame( array( 4, 0, 0 ), array_values( (array) $this->committedItem( $b, $second, $source ) ), 'The location keeps the units not yet moved.' );
		$this->assertNull( $this->committedItem( $b, $second, $target ) );
		$this->assertSame( 2, $this->committedEvents( $b, StockTransferred::eventName() ), 'Only the first page moved units, sent and received.' );

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
		$this->assertNotSame( $uuid, $this->deactivate( $source, $target )['move_transfer_uuid'], 'Deactivated again, the location starts a move of its own.' );
	}

	/**
	 * Tests that moving the stock also needs the capability that manages inventory, refused before anything changes, while a deactivation without a move needs only the settings.
	 *
	 * Planted violation, shown red and removed: in Locations::deactivateLocation(), leave out the
	 * second authorize(): a user who may manage the settings but not inventory moves stock.
	 *
	 * @since 0.2.0
	 */
	public function test_moving_the_stock_needs_the_inventory_capability_too(): void {
		$b        = $this->secondConnection();
		$source   = $this->plantLocation();
		$target   = $this->plantLocation();
		$settings = $this->userGranted( LocationOperations::CAPABILITY );

		$refused = $this->assertRefused(
			AuthorizationError::Denied,
			fn(): array => $this->locations->deactivateLocation(
				array(
					'location_uuid' => $this->uuidOf( $b, $source ),
					'move_stock_to' => $this->uuidOf( $b, $target ),
				),
				$settings
			)
		);

		$this->assertSame( array( 'capability' => InventoryOperations::CAPABILITY ), $refused->context() );
		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT active FROM `%s` WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $source ) ), 'The refused deactivation changed nothing.' );
		$this->assertSame( '0', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s`', $this->table( InventoryTables::TRANSFERS ) ) ) );
		$this->assertSame( array(), $this->queue->queued );

		$answer = $this->locations->deactivateLocation( array( 'location_uuid' => $this->uuidOf( $b, $source ) ), $settings );

		$this->assertSame( array( false, null ), array( $answer['active'], $answer['move_transfer_uuid'] ), 'Without a move, the settings are enough.' );
	}

	/**
	 * Tests the repeat: the same target answers the same move and queues its job again, another target is refused while one is under way, a move received is answered again, and a location deactivated earlier without a move may be given one.
	 *
	 * Planted violation, shown red and removed: in Transfers::moveStockOf(), leave out queueMove()
	 * for the move found in transit: a repeat after the job was lost queues nothing, and the move
	 * stays in transit with no job.
	 *
	 * @since 0.2.0
	 */
	public function test_a_repeat_answers_the_same_move_and_queues_its_job_again(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$target = $this->plantLocation();
		$other  = $this->plantLocation();
		$v      = self::variant();

		$this->stockItem( $v, 3, $source );

		$uuid = (string) $this->deactivate( $source, $target )['move_transfer_uuid'];

		$this->assertSame( $uuid, $this->deactivate( $source, $target )['move_transfer_uuid'] );
		$this->assertCount( 1, $this->queue->queued, 'Its job waits, so queueing it again adds nothing.' );

		$this->queue->finish();

		$this->assertSame( $uuid, $this->deactivate( $source, $target )['move_transfer_uuid'] );
		$this->assertCount( 2, $this->queue->queued, 'The job that failed, or never was queued, is queued again.' );

		$refused = $this->assertRefused( InventoryError::TransferState, fn(): array => $this->deactivate( $source, $other ) );

		$this->assertSame( array( 'reason' => Transfers::ANOTHER_MOVE ), $refused->details() );
		$this->assertSame( 2, $this->runMove( $this->queue->queued[1]->payload ), 'A page for the one variant with units, and one that finds no more.' );
		$this->assertSame( $uuid, $this->deactivate( $source, $target )['move_transfer_uuid'], 'The move received is the answer to the same deactivation.' );
		$this->assertCount( 2, $this->queue->queued, 'A move received has no job to queue.' );

		$idle = $this->plantLocation();

		$this->locations->deactivateLocation( array( 'location_uuid' => $this->uuidOf( $b, $idle ) ), $this->manager );

		$this->assertNotNull( $this->deactivate( $idle, $target )['move_transfer_uuid'], 'A location deactivated without a move may be given one.' );
		$this->assertSame( array( 'reason' => Transfers::ANOTHER_MOVE ), $this->assertRefused( InventoryError::TransferState, fn(): array => $this->deactivate( $idle, $other ) )->details() );
	}

	/**
	 * Tests that the target must be another location, one that exists and is active, each refused with nothing changed.
	 *
	 * @since 0.2.0
	 */
	public function test_the_target_is_another_active_location(): void {
		$b        = $this->secondConnection();
		$source   = $this->plantLocation();
		$inactive = $this->plantLocation( 2, false );

		$this->assertRefused( InventoryError::TransferSameLocation, fn(): array => $this->deactivate( $source, $source ) );
		$this->assertRefused( InventoryError::LocationInactive, fn(): array => $this->deactivate( $source, $inactive ) );
		$this->assertRefused(
			InventoryError::LocationMissing,
			fn(): array => $this->locations->deactivateLocation(
				array(
					'location_uuid' => $this->uuidOf( $b, $source ),
					'move_stock_to' => wp_generate_uuid4(),
				),
				$this->manager
			)
		);
		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT active FROM `%s` WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $source ) ), 'Each refusal rolled the deactivation back.' );
		$this->assertSame( array(), $this->queue->queued );
	}

	/**
	 * Deactivates a location, moving its stock to another, as the store manager.
	 *
	 * @since 0.2.0
	 *
	 * @param int $location The location.
	 * @param int $target   Where its stock goes.
	 * @return array<string, mixed> The answer.
	 */
	private function deactivate( int $location, int $target ): array {
		$b = $this->secondConnection();

		return $this->locations->deactivateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $location ),
				'move_stock_to' => $this->uuidOf( $b, $target ),
			),
			$this->manager
		);
	}

	/**
	 * Runs a move's job, a page per run, until it is done.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, int|string|bool|null> $payload The job's payload.
	 * @return int How many runs it took.
	 */
	private function runMove( array $payload ): int {
		$job  = new MoveLocationStock( $this->transfers, 0, 1 );
		$runs = 0;

		do {
			++$runs;

			if ( $runs > 50 ) {
				$this->fail( 'The move never ended.' );
			}
		} while ( null !== $job->handle( $payload ) );

		return $runs;
	}
}

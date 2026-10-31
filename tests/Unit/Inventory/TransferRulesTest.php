<?php
/**
 * Tests the transfer service's rules over doubles: what it refuses before any statement, and the order of a send's and a cancel's statements
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Inventory;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Application\Transfers;
use SEOCart\Inventory\Domain\Event\StockTransferred;
use SEOCart\Inventory\Domain\LocationRecord;
use SEOCart\Inventory\Domain\LocationDetails;
use SEOCart\Inventory\Domain\TransferKind;
use SEOCart\Inventory\Domain\TransferState;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Tests\Support\Doubles\FakeLocationRepository;
use SEOCart\Tests\Support\Doubles\FakeStockRepository;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\FakeTransferRepository;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;
use SEOCart\Tests\Support\Doubles\RecordingJobQueue;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

/**
 * The transfer and locations services over in-memory doubles, at the double's one location.
 *
 * A user who holds every capability acts: the capability checks are the integration suite's.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class TransferRulesTest extends TestCase {

	/**
	 * The unit of work.
	 *
	 * @since 0.2.0
	 *
	 * @var FakeTransactionManager
	 */
	private FakeTransactionManager $tx;

	/**
	 * The stock statements.
	 *
	 * @since 0.2.0
	 *
	 * @var FakeStockRepository
	 */
	private FakeStockRepository $stock;

	/**
	 * The transfer documents.
	 *
	 * @since 0.2.0
	 *
	 * @var FakeTransferRepository
	 */
	private FakeTransferRepository $documents;

	/**
	 * What the service published.
	 *
	 * @since 0.2.0
	 *
	 * @var RecordingEventPublisher
	 */
	private RecordingEventPublisher $events;

	/**
	 * The service under test.
	 *
	 * @since 0.2.0
	 *
	 * @var Transfers
	 */
	private Transfers $transfers;

	/**
	 * Who acts.
	 *
	 * @since 0.2.0
	 *
	 * @var Actor
	 */
	private Actor $actor;

	/**
	 * Builds the services over the doubles, with a user who may do anything.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'user_can' )->justReturn( true );

		$this->tx        = new FakeTransactionManager();
		$this->stock     = new FakeStockRepository( $this->tx );
		$this->documents = new FakeTransferRepository( $this->tx );
		$this->events    = new RecordingEventPublisher( $this->tx );
		$locations       = new FakeLocationRepository();
		$this->transfers = new Transfers( $this->documents, $this->stock, $locations, new LocationDirectory( $locations ), $this->tx, $this->events, new SequentialIdGenerator( 100 ), FrozenClock::at( '2026-10-25 12:00:00' ), new CorrelationId( new SequentialIdGenerator( 9000 ) ), new Authorizer( new CapabilityDeclaration() ), new RecordingJobQueue( $this->tx ) );
		$this->actor     = Actor::user( 7 );
	}

	/**
	 * Takes the stubs down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that a creation and a receipt refuse what their declarations cannot, before any statement: one location as both ends, a note with a control character, no line, a receipt line twice or with nothing in it.
	 *
	 * Planted violation, shown red and removed: in Transfers::receipts(), drop the check of a line
	 * named twice: the receipt goes on to its statements.
	 *
	 * @since 0.2.0
	 */
	public function test_what_the_declarations_cannot_say_is_refused_before_any_statement(): void {
		$here  = FakeLocationRepository::UUID;
		$lines = array(
			array(
				'variant_id' => 4,
				'quantity'   => 1,
			),
		);

		$this->assertRefused( InventoryError::TransferSameLocation, fn(): array => $this->transfers->createTransfer( self::draft( $here, strtoupper( $here ), $lines ), $this->actor ) );
		$this->assertRefused( InventoryError::TransferInvalid, fn(): array => $this->transfers->createTransfer( self::draft( $here, '0f7e7c3a-5b1d-4c2e-9a8f-3d6b2e1c4a90', $lines ) + array( 'note' => "Ring\x07" ), $this->actor ), array( 'field' => 'note' ) );
		$this->assertRefused( InventoryError::TransferInvalid, fn(): array => $this->transfers->createTransfer( self::draft( $here, '0f7e7c3a-5b1d-4c2e-9a8f-3d6b2e1c4a90', array() ), $this->actor ), array( 'field' => 'lines' ) );

		foreach ( array( array( self::receipt( 1, 1 ), self::receipt( 1, 2 ) ), array( self::receipt( 1, 0 ) ), array() ) as $receipt ) {
			$this->assertRefused(
				InventoryError::TransferInvalid,
				fn(): array => $this->transfers->receiveTransfer(
					array(
						'transfer_uuid' => '0f7e7c3a-5b1d-4c2e-9a8f-3d6b2e1c4a90',
						'lines'         => $receipt,
					),
					$this->actor
				),
				array( 'field' => 'lines' )
			);
		}

		$this->assertSame( 0, $this->tx->attempts(), 'A refusal of the input opened a unit of work.' );
		$this->assertSame( array(), $this->stock->calls() );
	}

	/**
	 * Tests the order of a send's statements: the move to in transit first, then per line ascending by variant its units out of the source, the level read under the lock, and the ledger entry naming the transfer; a short line refuses the send.
	 *
	 * @since 0.2.0
	 */
	public function test_a_send_moves_the_transfer_first_then_each_line_with_its_entry(): void {
		$this->stock->plantItem( 4, 5 );
		$this->stock->plantItem( 9, 2 );

		$uuid = $this->plantDraft(
			array(
				9 => 1,
				4 => 3,
			)
		);
		$sent = $this->transfers->sendTransfer( array( 'transfer_uuid' => $uuid ), $this->actor );

		$this->assertSame( 'in_transit', $sent['state'] );
		$this->assertSame( array( 'transferOutAvailable:4@1:3', 'lockedLevel:4@1', 'appendLedger:4@1:-3:transfer1', 'transferOutAvailable:9@1:1', 'lockedLevel:9@1', 'appendLedger:9@1:-1:transfer1' ), $this->stock->calls() );
		$this->assertCount( 1, $this->events->published() );
		$this->assertInstanceOf( StockTransferred::class, $this->events->published()[0] );

		$short = $this->plantDraft( array( 4 => 3 ) );

		$this->assertRefused( InventoryError::TransferRefused, fn(): array => $this->transfers->sendTransfer( array( 'transfer_uuid' => $short ), $this->actor ) );
	}

	/**
	 * Tests that a draft is cancelled, and a requested transfer carrying an order's units is not, with the reason.
	 *
	 * @since 0.2.0
	 */
	public function test_a_transfer_carrying_an_orders_units_is_not_cancelled_by_a_merchant(): void {
		$draft = $this->plantDraft( array( 4 => 1 ) );

		$this->assertSame( 'cancelled', $this->transfers->cancelTransfer( array( 'transfer_uuid' => $draft ), $this->actor )['state'] );

		$requested = $this->tx->transaction(
			function (): string {
				$id = $this->documents->insert( '0f7e7c3a-5b1d-4c2e-9a8f-3d6b2e1c4a91', FakeStockRepository::LOCATION, FakeStockRepository::LOCATION, TransferKind::Order, TransferState::Requested, 31, null, null );

				$this->documents->insertLines(
					$id,
					array(
						array(
							'variant_id'        => 4,
							'quantity'          => 1,
							'received_quantity' => 0,
							'allocation_id'     => 77,
						),
					)
				);

				return '0f7e7c3a-5b1d-4c2e-9a8f-3d6b2e1c4a91';
			}
		);

		$refused = $this->assertRefused( InventoryError::TransferState, fn(): array => $this->transfers->cancelTransfer( array( 'transfer_uuid' => $requested ), $this->actor ) );

		$this->assertSame( array( 'reason' => Transfers::CARRIES_ORDER ), $refused->details() );
	}

	/**
	 * Tests that the seams an order's placement and cancel, and a location's deactivation, call run only inside the caller's unit of work.
	 *
	 * @since 0.2.0
	 */
	public function test_the_seams_run_only_in_the_callers_unit_of_work(): void {
		$record = new LocationRecord( FakeStockRepository::LOCATION, FakeLocationRepository::UUID, true, true, new LocationDetails( 'Store', null, null, true, false, null, null, 0 ), array() );

		foreach (
			array(
				fn() => $this->transfers->requestForOrder( 31, FakeStockRepository::LOCATION ),
				fn() => $this->transfers->detachOrder( 31 ),
				fn() => $this->transfers->moveStockOf( $record, '0f7e7c3a-5b1d-4c2e-9a8f-3d6b2e1c4a90', false, $this->actor ),
			) as $call
		) {
			try {
				$call();
				$this->fail( 'A seam ran outside a transaction.' );
			} catch ( \LogicException $expected ) {
				$this->assertStringContainsString( 'transaction', $expected->getMessage() );
			}
		}
	}

	/**
	 * Tests that the locations service refuses the two fields its declaration bounds, how soon a pickup is ready and a negative priority, naming each, before any statement.
	 *
	 * Planted violation, shown red and removed: in Locations::changes(), drop the check of
	 * `pickup_ready_in` against LocationDetails::PICKUP_READY_IN: a caller other than the surfaces
	 * stores a key the storefront cannot word.
	 *
	 * @since 0.2.0
	 */
	public function test_the_locations_service_refuses_a_pickup_time_or_a_priority_out_of_bounds(): void {
		$locations = new Locations( new FakeLocationRepository(), $this->stock, $this->tx, new SequentialIdGenerator( 200 ), new Authorizer( new CapabilityDeclaration() ), $this->transfers );

		foreach (
			array(
				'pickup_ready_in' => array( 'pickup_ready_in' => 'bogus' ),
				'priority'        => array( 'priority' => -5 ),
			) as $field => $input
		) {
			$this->assertRefused( InventoryError::LocationInvalid, fn(): array => $locations->createLocation( $input + array( 'name' => 'Shop' ), $this->actor ), array( 'field' => $field ) );
		}

		$this->assertSame( 0, $this->tx->attempts(), 'A refused field opened a unit of work.' );
	}

	/**
	 * Plants a draft at the double's one location, both ends.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, int> $units The units of each variant.
	 * @return string The draft's uuid.
	 */
	private function plantDraft( array $units ): string {
		static $next = 0;

		$uuid = sprintf( '0f7e7c3a-5b1d-4c2e-9a8f-%012d', ++$next );

		$this->tx->transaction(
			function () use ( $uuid, $units ): void {
				$id = $this->documents->insert( $uuid, FakeStockRepository::LOCATION, FakeStockRepository::LOCATION, TransferKind::Manual, TransferState::Draft, null, null, 7 );

				foreach ( $units as $variantId => $quantity ) {
					$this->documents->insertLines(
						$id,
						array(
							array(
								'variant_id'        => $variantId,
								'quantity'          => $quantity,
								'received_quantity' => 0,
								'allocation_id'     => null,
							),
						)
					);
				}
			}
		);

		return $uuid;
	}

	/**
	 * Returns a creation's input.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source      The source's uuid.
	 * @param string $destination The destination's uuid.
	 * @param array  $lines       The lines.
	 * @return array<string, mixed> The input.
	 */
	private static function draft( string $source, string $destination, array $lines ): array {
		return array(
			'source_location_uuid'      => $source,
			'destination_location_uuid' => $destination,
			'lines'                     => $lines,
		);
	}

	/**
	 * Returns a receipt line that expects nothing received or lost so far.
	 *
	 * @since 0.2.0
	 *
	 * @param int $lineId   The line.
	 * @param int $received The units received.
	 * @return array{line_id: int, received: int, lost: int, expected_received_quantity: int, expected_lost_quantity: int} The line.
	 */
	private static function receipt( int $lineId, int $received ): array {
		return array(
			'line_id'                    => $lineId,
			'received'                   => $received,
			'lost'                       => 0,
			'expected_received_quantity' => 0,
			'expected_lost_quantity'     => 0,
		);
	}

	/**
	 * Asserts that a call is refused with an error code, and, when given, its context.
	 *
	 * @since 0.2.0
	 *
	 * @param ErrorCode                 $code    The code.
	 * @param \Closure                  $call    The call.
	 * @param array<string, mixed>|null $context Optional. The context expected. Default null, not checked.
	 * @return CodedException The refusal.
	 */
	private function assertRefused( ErrorCode $code, \Closure $call, ?array $context = null ): CodedException {
		try {
			$call();
		} catch ( CodedException $refused ) {
			$this->assertSame( $code, $refused->errorCode(), $refused->getMessage() );

			if ( null !== $context ) {
				$this->assertSame( $context, $refused->context() );
			}

			return $refused;
		}

		$this->fail( sprintf( 'The call was not refused with %s.', $code->value ) );
	}
}

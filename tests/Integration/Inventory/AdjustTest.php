<?php
/**
 * Tests adjusting stock: the conditional update, the ledger entry and the event, and that the ledger is only ever appended
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\StockLevel;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Inventory\StockTestCase;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * An adjustment is one conditional update of on_hand, read back under the item's lock, one ledger
 * entry and one outbox event, in one transaction; a refused one writes nothing.
 *
 * Planted violations: in MysqlStockRepository::ADJUST, drop `AND on_hand + %d >= 0` (and its
 * value): the −6 adjustment lands and on_hand goes to −1. In StockService::record(), skip the
 * ledger append and use 0 as the entry id: the item's on_hand no longer adds up to its ledger,
 * and the stock check fails. In MysqlStockRepository, add a constant
 * `DELETE FROM {stock_ledger} WHERE id = %d`: the append-only test fails.
 *
 * @since 0.1.0
 */
final class AdjustTest extends StockTestCase {

	/**
	 * Tests that an adjustment updates on_hand, appends its entry and stores its event, all committed together.
	 *
	 * @since 0.1.0
	 */
	public function test_an_adjustment_updates_on_hand_appends_the_ledger_and_stores_the_event(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 0 );

		$adjustment = $this->service->adjust( $variant, $this->defaultLocation, 5, LedgerReason::Received, Actor::user( 3 ) );

		$this->assertSame( array( 5, 0, 0, 5 ), array( $adjustment->level->onHand, $adjustment->level->allocated, $adjustment->level->held, $adjustment->level->available() ) );
		$this->assertSame(
			array(
				'on_hand'   => 5,
				'allocated' => 0,
				'held'      => 0,
			),
			$this->committedItem( $b, $variant )
		);

		$ledger = $this->committedLedger( $b, $variant );

		$this->assertCount( 1, $ledger );
		$this->assertSame(
			array(
				'id'             => (string) $adjustment->ledgerEntryId,
				'delta'          => '5',
				'on_hand_after'  => '5',
				'reason'         => 'received',
				'actor_type'     => 'user',
				'actor_id'       => '3',
				'correlation_id' => $this->correlation->current(),
			),
			$ledger[0]
		);

		$stored = Outbox::decode( (string) $b->fetchValue( sprintf( "SELECT payload_json FROM `%s` WHERE event_name = 'stock_adjusted'", $this->table( OutboxTable::NAME ) ) ) );

		$this->assertSame(
			array(
				'variant_id'         => $variant,
				'location_id'        => $this->defaultLocation,
				'delta'              => 5,
				'on_hand'            => 5,
				'available'          => 5,
				'location_on_hand'   => 5,
				'location_available' => 5,
				'reason'             => 'received',
				'actor_type'         => 'user',
				'actor_id'           => 3,
				'ledger_entry_id'    => $adjustment->ledgerEntryId,
			),
			$stored['p']
		);
		$this->assertSame( $this->correlation->current(), $b->fetchValue( sprintf( "SELECT correlation_id FROM `%s` WHERE event_name = 'stock_adjusted'", $this->table( OutboxTable::NAME ) ) ), 'The ledger entry and its event share the correlation id.' );
		$this->assertTrue( $this->projectionCheck()->passed, 'on_hand adds up to the ledger.' );
	}

	/**
	 * Tests that an adjustment that would take on_hand below zero is refused and writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_an_adjustment_below_zero_is_refused_and_writes_nothing(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 5 );

		try {
			$this->service->adjust( $variant, $this->defaultLocation, -6, LedgerReason::Damaged, Actor::user( 3 ) );
			$this->fail( 'An adjustment took on_hand below zero.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::AdjustmentBelowZero, $refused->errorCode() );
			$this->assertSame(
				array(
					'variant_id' => $variant,
					'on_hand'    => 5,
					'delta'      => -6,
				),
				$refused->context()
			);
		}

		$this->assertSame( 5, $this->committedItem( $b, $variant )['on_hand'] ?? null );
		$this->assertCount( 1, $this->committedLedger( $b, $variant ), 'Only the entry that stocked the item.' );
		$this->assertSame( 1, $this->committedEvents( $b, StockAdjusted::eventName() ) );
		$this->assertSame( 0, $this->db->depth() );
	}

	/**
	 * Tests that an adjustment of zero is refused before any statement.
	 *
	 * @since 0.1.0
	 */
	public function test_a_zero_adjustment_sends_no_statement(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 5 );

		$log = $this->captureQueries(
			function () use ( $variant ): void {
				try {
					$this->service->adjust( $variant, $this->defaultLocation, 0, LedgerReason::Recount, Actor::user( 3 ) );
					$this->fail( 'A zero adjustment was accepted.' );
				} catch ( CodedException $refused ) {
					$this->assertSame( InventoryError::ZeroDelta, $refused->errorCode() );
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'A zero adjustment' );
	}

	/**
	 * Tests that an expected on_hand that is no longer true refuses the adjustment and reports what is there.
	 *
	 * @since 0.1.0
	 */
	public function test_an_expected_on_hand_that_changed_is_a_conflict(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 5 );

		try {
			$this->service->adjust( $variant, $this->defaultLocation, 2, LedgerReason::Received, Actor::user( 3 ), 4 );
			$this->fail( 'An adjustment applied although on_hand was not what the caller read.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::OnHandConflict, $refused->errorCode() );
			$this->assertSame(
				array(
					'variant_id' => $variant,
					'expected'   => 4,
					'on_hand'    => 5,
				),
				$refused->context()
			);
		}

		$adjustment = $this->service->adjust( $variant, $this->defaultLocation, 2, LedgerReason::Received, Actor::user( 3 ), 5 );

		$this->assertSame( 7, $adjustment->level->onHand, 'With the on_hand it read, the caller\'s adjustment applies.' );
		$this->assertSame( 7, $this->committedItem( $b, $variant )['on_hand'] ?? null );
	}

	/**
	 * Tests that an adjustment of a variant with no item is `stock.item_missing`.
	 *
	 * @since 0.1.0
	 */
	public function test_an_item_that_does_not_exist_is_missing(): void {
		$variant = self::variant();

		try {
			$this->service->adjust( $variant, $this->defaultLocation, 1, LedgerReason::Received, Actor::user( 3 ) );
			$this->fail( 'An item that does not exist was adjusted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::ItemMissing, $refused->errorCode() );
			$this->assertSame( array( 'variant_id' => $variant ), $refused->context() );
		}
	}

	/**
	 * Tests that the first adjustment at a second location creates the variant's item there, at zero, then counts it, with its ledger entry there; the default location's item is untouched.
	 *
	 * Planted violation: in StockService::adjust(), send the repository's adjust() before
	 * createItemAt(): the update finds no item at the second location, and the adjustment is
	 * `stock.item_missing`.
	 *
	 * @since 0.2.0
	 */
	public function test_the_first_adjustment_at_a_location_creates_its_item_and_ledger_entry(): void {
		$variant = self::variant();
		$second  = $this->plantLocation();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 2 );

		$adjustment = $this->service->adjust( $variant, $second, 3, LedgerReason::Received, Actor::user( 3 ) );

		$this->assertSame( array( $second, 3 ), array( $adjustment->level->locationId, $adjustment->level->onHand ) );
		$this->assertSame( 3, $this->committedItem( $b, $variant, $second )['on_hand'] ?? null );
		$this->assertSame( 2, $this->committedItem( $b, $variant )['on_hand'] ?? null, 'The default location\'s item is untouched.' );
		$this->assertSame( array( $this->defaultLocation . ':2:2', $second . ':3:3' ), $this->locatedLedger( $variant ) );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Tests that an adjustment's event names the variant's on_hand and available over every location, as one item's were before locations, and the item's own beside them.
	 *
	 * Planted violation: in StockService::record(), give the item's figures as the variant's: a
	 * reader of `on_hand` sees the three units at the second location where the variant has five.
	 *
	 * @since 0.2.0
	 */
	public function test_an_adjustment_names_the_variants_totals_and_the_items_own(): void {
		$variant = self::variant();
		$second  = $this->plantLocation();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 2 );
		$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );
		$this->service->adjust( $variant, $second, 3, LedgerReason::Received, Actor::user( 3 ) );

		$payload = $this->committedPayload( $b, StockAdjusted::eventName() );

		$this->assertSame(
			array( $second, 3, 5, 4, 3, 3 ),
			array( $payload['location_id'], $payload['delta'], $payload['on_hand'], $payload['available'], $payload['location_on_hand'], $payload['location_available'] ),
			'Five on hand and four available over both locations; three of each at the second.'
		);
	}

	/**
	 * Tests that reading an item's level under its lock, with the variant's totals over every location, locks no other item of the variant.
	 *
	 * A holds the default location's item under the lock the adjustment takes and has read the
	 * variant's totals, which read the second location's item too; B then locks the second
	 * location's item, as its own adjustment or claim there would, and answers at once.
	 *
	 * Planted violation: in MysqlStockRepository::LOCKED_LEVEL, end the total's subquery with
	 * `FOR UPDATE`: the second location's item is locked by A's read, and B waits for A's commit.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_the_variants_totals_lock_no_other_item(): void {
		$variant = self::variant();
		$second  = $this->plantLocation();
		$b       = $this->secondConnection();
		$lock    = $this->raw( MysqlStockRepository::LOCK_ITEM, $variant, $second );
		$waited  = null;

		$this->stockItem( $variant, 2 );
		$this->service->adjust( $variant, $second, 3, LedgerReason::Received, Actor::user( 3 ) );

		$level = $this->db->transaction(
			function () use ( $b, $variant, $lock, &$waited ): ?StockLevel {
				$level = $this->repository->lockedLevel( $variant, $this->defaultLocation );

				$b->queryAsync( $lock );

				$waited = $this->waitsOrAnswers( $b, $lock, 'updating' );

				return $level;
			},
			RetryPolicy::none(),
			Isolation::ReadCommitted
		);

		$this->assertSame( 1, $b->reap(), 'B locked the second location\'s item.' );
		$this->assertFalse( $waited, 'Reading the variant\'s totals locked another location\'s item.' );
		$this->assertSame( array( 2, 5, 5 ), array( $level?->onHand, $level?->variantOnHand, $level?->variantAvailable ) );
	}

	/**
	 * Tests that an adjustment at a location that does not exist is `stock.location_missing`, and creates nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_an_adjustment_at_a_location_that_does_not_exist_is_refused(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 2 );

		try {
			$this->service->adjust( $variant, 999999, 1, LedgerReason::Received, Actor::user( 3 ) );
			$this->fail( 'Stock was counted at a location that does not exist.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::LocationMissing, $refused->errorCode() );
			$this->assertSame( array( 'location_id' => 999999 ), $refused->context() );
		}

		$this->assertNull( $this->committedItem( $b, $variant, 999999 ) );
	}

	/**
	 * Tests that two adjustments creating the same new item leave one item and two ledger entries: the second waits for the first, and adds to it.
	 *
	 * Just before A's update of the new item, B, in its own transaction, sends the same upsert of the
	 * item; the server shows it waiting for A's insert. Once A commits, B's upsert finds the item and
	 * changes nothing, and B's whole adjustment then adds its units to A's.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_two_adjustments_creating_one_item_leave_one_item_and_two_entries(): void {
		$variant = self::variant();
		$second  = $this->plantLocation();
		$b       = $this->secondConnection();
		$create  = $this->raw( MysqlStockRepository::CREATE_ITEM_AT, $variant, $second );

		$this->stockItem( $variant, 0 );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::ADJUST ),
			function () use ( $b, $create ): void {
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $create );
				$this->awaitWaiting( $b, $create, 'update' );
			}
		);

		$this->service->adjust( $variant, $second, 1, LedgerReason::Received, Actor::user( 3 ) );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( 0, $b->reap(), 'B\'s upsert found the item A created, and changed nothing.' );

		$b->query( 'COMMIT' );

		$this->secondService()->adjust( $variant, $second, 2, LedgerReason::Received, Actor::user( 4 ) );

		$this->assertSame( 3, $this->committedItem( $b, $variant, $second )['on_hand'] ?? null );
		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $variant, $second ) ) );
		$this->assertSame( array( $second . ':1:1', $second . ':2:3' ), $this->locatedLedger( $variant ) );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Reads a variant's ledger with its locations.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return list<string> Each entry as `location:delta:on_hand_after`, by id.
	 */
	private function locatedLedger( int $variantId ): array {
		return array_map(
			static fn( array $row ): string => $row['location_id'] . ':' . $row['delta'] . ':' . $row['on_hand_after'],
			$this->db->fetchAll( 'SELECT location_id, delta, on_hand_after FROM %i WHERE variant_id = %d ORDER BY id', $this->table( InventoryTables::LEDGER ), $variantId )
		);
	}

	/**
	 * Tests that an adjustment may count fewer units than are held: on_hand records the count, and available goes negative.
	 *
	 * @since 0.1.0
	 */
	public function test_an_adjustment_may_leave_fewer_units_than_are_held(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 5 );
		$this->plantHold( $variant, 3, 600 );

		$adjustment = $this->service->adjust( $variant, $this->defaultLocation, -4, LedgerReason::Recount, Actor::system( 'cli', 3 ) );

		$this->assertSame( 1, $adjustment->level->onHand );
		$this->assertSame( -2, $adjustment->level->available() );
		$this->assertTrue( $this->projectionCheck()->passed, 'A shortfall is a fact, not a corrupt projection.' );

		try {
			$this->service->adjust( $variant, $this->defaultLocation, -2, LedgerReason::Recount, Actor::user( 3 ) );
			$this->fail( 'on_hand went below zero.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::AdjustmentBelowZero, $refused->errorCode() );
		}
	}

	/**
	 * Tests that no stock statement updates or deletes the ledger, and that the ledger is declared append-only.
	 *
	 * @since 0.1.0
	 */
	public function test_the_ledger_is_only_ever_appended(): void {
		$ledger    = '{' . InventoryTables::LEDGER . '}';
		$appends   = array();
		$rewriters = array();

		foreach ( ( new \ReflectionClass( MysqlStockRepository::class ) )->getReflectionConstants() as $constant ) {
			$sql = $constant->getValue();

			if ( ! is_string( $sql ) || ! str_contains( $sql, $ledger ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^\s*(UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $sql ) ) {
				$rewriters[] = $constant->getName();
			}

			if ( 1 === preg_match( '/^\s*INSERT INTO ' . preg_quote( $ledger, '/' ) . '/', $sql ) ) {
				$appends[] = $constant->getName();
			}
		}

		$this->assertSame( array( 'APPEND_LEDGER' ), $appends, 'The search did not find the one append, so it cannot be trusted.' );
		$this->assertSame( array(), $rewriters, 'A stock statement updates or deletes the ledger.' );
		$this->assertSame( MutationPattern::AppendOnly, InventoryTables::ledger()->mutationPattern() );
	}

	/**
	 * Tests that the repository sends SQL only through its statement constants, so the constants are every statement it can send.
	 *
	 * The append-only test reads the constants; a statement written inline in a method would pass
	 * it. So only the two helpers that expand a constant, write() and rows(), may call the
	 * connection's statement methods, and every call to them names a constant: `self::NAME`; the
	 * claim a reclaim is given, which its callers name as `self::CLAIM_*`; or, in insertRows(), the
	 * one-row insert it is given repeated by the shared ModuleStatements::forRows(), which its
	 * callers name as `self::NAME`.
	 *
	 * Planted violations:
	 * - add to MysqlStockRepository a method that deletes a ledger entry with an inline
	 *   `$this->db->execute( 'DELETE FROM %i WHERE id = %d', … )`. The append-only test still
	 *   passes; this one names the method;
	 * - in insertAllocations(), give insertRows() the statement inline in place of
	 *   `self::INSERT_ALLOCATION`: this one names insertAllocations().
	 *
	 * @since 0.1.0
	 */
	public function test_the_repository_sends_sql_only_through_its_statement_constants(): void {
		$class   = new \ReflectionClass( MysqlStockRepository::class );
		$lines   = (array) file( (string) $class->getFileName() );
		$senders = array();
		$inline  = array();

		foreach ( $class->getMethods() as $method ) {
			if ( MysqlStockRepository::class !== $method->getDeclaringClass()->getName() ) {
				continue;
			}

			$body = implode( '', array_slice( $lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1 ) );

			if ( 1 === preg_match( '/->\s*(?:execute|fetchAll|fetchRow|fetchValue)\s*\(/', $body ) ) {
				$senders[] = $method->getName();
			}

			preg_match_all( '/\$this->(?:write|rows)\(\s*([^,)]+)/', $body, $statements );

			foreach ( $statements[1] as $statement ) {
				$statement = trim( $statement );

				$given = ( 'reclaim' === $method->getName() && '$claim' === $statement ) || ( 'insertRows' === $method->getName() && 'ModuleStatements::forRows( $statement' === $statement );

				if ( 1 !== preg_match( '/^self::[A-Z][A-Z_]*$/', $statement ) && ! $given ) {
					$inline[] = $method->getName() . '(): ' . $statement;
				}
			}

			preg_match_all( '/\$this->reclaim\(\s*[^,]+,\s*[^,]+,\s*[^,]+,\s*([^,]+),/', $body, $claims );

			foreach ( $claims[1] as $claim ) {
				if ( 1 !== preg_match( '/^self::CLAIM_[A-Z_]+$/', trim( $claim ) ) ) {
					$inline[] = $method->getName() . '(): ' . trim( $claim );
				}
			}

			preg_match_all( '/\$this->insertRows\(\s*([^,]+),/', $body, $inserts );

			foreach ( $inserts[1] as $insert ) {
				if ( 1 !== preg_match( '/^self::[A-Z][A-Z_]*$/', trim( $insert ) ) ) {
					$inline[] = $method->getName() . '(): ' . trim( $insert );
				}
			}
		}

		sort( $senders );

		$this->assertSame( array( 'rows', 'write' ), $senders, 'Only write() and rows() may send a statement: the others must go through them, with a constant.' );
		$this->assertSame( array(), $inline, 'A statement is sent that is not one of the repository\'s constants.' );
	}

	/**
	 * Tests that the repository is the only code under src/ that names a stock table in a statement, with the upgrade's batch that gives the store's stock its default location, and the transfer repository, which names the transfer documents' own.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The upgrade's batch, and the transfer repository.
	 */
	public function test_the_repository_is_the_only_class_with_stock_sql(): void {
		$found = array();

		foreach ( PhpSource::files( 'src' ) as $file => $source ) {
			if ( 1 === preg_match( '/\{stock_[a-z]+\}|->table\(\s*(?:InventoryTables::|\'stock_)|seocart_stock_(?:items|ledger|holds|allocations)\b/', $source ) ) {
				$found[] = $file;
			}
		}

		sort( $found );

		$this->assertSame( array( 'src/Inventory/Infrastructure/Migrations/SeedDefaultLocation.php', 'src/Inventory/Infrastructure/MysqlStockRepository.php', 'src/Inventory/Infrastructure/MysqlTransferRepository.php' ), $found );
	}
}

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
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
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

		$adjustment = $this->service->adjust( $variant, 5, LedgerReason::Received, Actor::user( 3 ) );

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
				'variant_id'      => $variant,
				'delta'           => 5,
				'on_hand'         => 5,
				'available'       => 5,
				'reason'          => 'received',
				'actor_type'      => 'user',
				'actor_id'        => 3,
				'ledger_entry_id' => $adjustment->ledgerEntryId,
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
			$this->service->adjust( $variant, -6, LedgerReason::Damaged, Actor::user( 3 ) );
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
					$this->service->adjust( $variant, 0, LedgerReason::Recount, Actor::user( 3 ) );
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
			$this->service->adjust( $variant, 2, LedgerReason::Received, Actor::user( 3 ), 4 );
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

		$adjustment = $this->service->adjust( $variant, 2, LedgerReason::Received, Actor::user( 3 ), 5 );

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
			$this->service->adjust( $variant, 1, LedgerReason::Received, Actor::user( 3 ) );
			$this->fail( 'An item that does not exist was adjusted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::ItemMissing, $refused->errorCode() );
			$this->assertSame( array( 'variant_id' => $variant ), $refused->context() );
		}
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

		$adjustment = $this->service->adjust( $variant, -4, LedgerReason::Recount, Actor::system( 'cli', 3 ) );

		$this->assertSame( 1, $adjustment->level->onHand );
		$this->assertSame( -2, $adjustment->level->available() );
		$this->assertTrue( $this->projectionCheck()->passed, 'A shortfall is a fact, not a corrupt projection.' );

		try {
			$this->service->adjust( $variant, -2, LedgerReason::Recount, Actor::user( 3 ) );
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
	 * connection's statement methods, and every call to them names a constant: `self::NAME`, or the
	 * claim a reclaim is given, which its callers name as `self::CLAIM_*`.
	 *
	 * Planted violation: add to MysqlStockRepository a method that deletes a ledger entry with an
	 * inline `$this->db->execute( 'DELETE FROM %i WHERE id = %d', … )`. The append-only test still
	 * passes; this one names the method.
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

				if ( 1 !== preg_match( '/^self::[A-Z][A-Z_]*$/', $statement ) && ! ( 'reclaim' === $method->getName() && '$claim' === $statement ) ) {
					$inline[] = $method->getName() . '(): ' . $statement;
				}
			}

			preg_match_all( '/\$this->reclaim\(\s*[^,]+,\s*[^,]+,\s*([^,]+),/', $body, $claims );

			foreach ( $claims[1] as $claim ) {
				if ( 1 !== preg_match( '/^self::CLAIM_[A-Z_]+$/', trim( $claim ) ) ) {
					$inline[] = $method->getName() . '(): ' . trim( $claim );
				}
			}
		}

		sort( $senders );

		$this->assertSame( array( 'rows', 'write' ), $senders, 'Only write() and rows() may send a statement: the others must go through them, with a constant.' );
		$this->assertSame( array(), $inline, 'A statement is sent that is not one of the repository\'s constants.' );
	}

	/**
	 * Tests that the repository is the only code under src/ that names a stock table in a statement.
	 *
	 * @since 0.1.0
	 */
	public function test_the_repository_is_the_only_class_with_stock_sql(): void {
		$found = array();

		foreach ( PhpSource::files( 'src' ) as $file => $source ) {
			if ( 1 === preg_match( '/\{stock_[a-z]+\}|->table\(\s*(?:InventoryTables::|\'stock_)|seocart_stock_(?:items|ledger|holds|allocations)\b/', $source ) ) {
				$found[] = $file;
			}
		}

		$this->assertSame( array( 'src/Inventory/Infrastructure/MysqlStockRepository.php' ), $found );
	}
}

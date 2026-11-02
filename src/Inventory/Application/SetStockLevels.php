<?php
/**
 * SetStockLevels: sets several on-hand counts in one unit of work
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\StockLevel;
use SEOCart\Inventory\Domain\StockOutcome;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Inventory\Domain\StockTarget;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An exception here names a caller's programming error for the developer; it is never rendered.

/**
 * Sets the on-hand counts of several items, each only while it still has the count it was read at, in one unit of work.
 *
 * Owns one fact: how a grid of stock cells is applied. The cells are taken in ascending order of
 * the variant, then the location, the order every stock writer locks items in, in one transaction
 * at READ COMMITTED that a deadlock runs again whole. First one locking read of the variants'
 * policies and one of the locations keep both from being deleted until the commit: a variant
 * without a policy, or a location that does not exist, refuses the whole unit before anything is
 * written. Then one upsert creates every item that does not exist yet, at zero, and takes each
 * item's lock, whether it inserts the row or finds it. Each cell whose count differs from the one
 * it was read at is then set by one conditional update that matches only while the item still has
 * that count: a cell that matched is `applied`, one that did not is a `conflict`, and the others
 * stand. One read gives every item's count after: the unit holds each of those items' locks
 * since the upsert, so a plain read is stable for them and locks nothing else, and a hold on an
 * item the cells do not name never waits on it. One insert writes a `recount` ledger entry for each
 * applied cell, and one StockAdjusted per applied cell is stored in the outbox in the same
 * transaction. A cell already at its count is `skipped`: no statement, no entry, no event.
 *
 * The unit opens its own transaction and refuses to run inside one: inside another, its isolation
 * and its retry would be the outer transaction's.
 *
 * @since 0.2.0
 */
final class SetStockLevels {

	/**
	 * Sends the stock statements.
	 *
	 * @since 0.2.0
	 *
	 * @var StockRepository
	 */
	private StockRepository $stock;

	/**
	 * Runs the unit of work.
	 *
	 * @since 0.2.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Stores the events in the unit.
	 *
	 * @since 0.2.0
	 *
	 * @var EventPublisher
	 */
	private EventPublisher $events;

	/**
	 * Tells when the cells were applied.
	 *
	 * @since 0.2.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * The request's correlation id, which every ledger entry records.
	 *
	 * @since 0.2.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * Creates the service. Does nothing else.
	 *
	 * @since 0.2.0
	 *
	 * @param StockRepository    $stock       Sends the stock statements.
	 * @param TransactionManager $tx          Runs the unit of work.
	 * @param EventPublisher     $events      Stores the events in the unit.
	 * @param Clock              $clock       Tells the time.
	 * @param CorrelationId      $correlation The request's correlation id.
	 */
	public function __construct( StockRepository $stock, TransactionManager $tx, EventPublisher $events, Clock $clock, CorrelationId $correlation ) {
		$this->stock       = $stock;
		$this->tx          = $tx;
		$this->events      = $events;
		$this->clock       = $clock;
		$this->correlation = $correlation;
	}

	/**
	 * Applies the cells and returns what became of each.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Inside a transaction, before any statement.
	 * @phpstan-throws \LogicException|\InvalidArgumentException|CodedException An InvalidArgumentException for two cells of
	 *         one item, before any statement; `stock.item_missing` when a variant has no stock policy and
	 *         `stock.location_missing` when a location does not exist, the unit refused whole; a database error the deadlock
	 *         retry could not overcome.
	 *
	 * @param StockTarget[] $targets The cells, at most one per variant and location, in any order.
	 * @param Actor         $actor   On whose authority.
	 * @return list<StockOutcome> The outcome of each cell, in ascending order of the variant, then the location.
	 */
	public function apply( array $targets, Actor $actor ): array {
		if ( 0 !== $this->tx->depth() ) {
			throw new \LogicException( 'SetStockLevels opens its own transaction: inside another, its isolation and its deadlock retry would be the outer one\'s.' );
		}

		$sorted = self::sorted( $targets );

		if ( array() === $sorted ) {
			return array();
		}

		return $this->tx->transaction( fn(): array => $this->applySorted( $sorted, $actor ), RetryPolicy::deadlocks(), Isolation::ReadCommitted );
	}

	/**
	 * Applies sorted cells inside the unit of work.
	 *
	 * @since 0.2.0
	 *
	 * @param StockTarget[] $targets The cells, sorted.
	 * @param Actor         $actor   On whose authority.
	 * @return list<StockOutcome> The outcomes, in the cells' order.
	 *
	 * @phpstan-param list<StockTarget> $targets
	 */
	private function applySorted( array $targets, Actor $actor ): array {
		$this->requireAdjustable( $targets );

		$items = array_map( static fn( StockTarget $target ): array => array( $target->variantId, $target->locationId ), $targets );

		$this->stock->createItemsAt( $items );

		$applied = array();

		foreach ( $targets as $index => $target ) {
			if ( 0 !== $target->delta() && $this->stock->adjust( $target->variantId, $target->locationId, $target->delta(), $target->expectedOnHand ) ) {
				$applied[ $index ] = true;
			}
		}

		$levels = array();

		foreach ( $this->stock->lockedLevels( $items ) as $level ) {
			$levels[ self::key( $level->variantId, $level->locationId ) ] = $level;
		}

		$entries = $this->record( $targets, array_keys( $applied ), $levels, $actor );

		$outcomes = array();

		foreach ( $targets as $index => $target ) {
			$level      = $levels[ self::key( $target->variantId, $target->locationId ) ] ?? null;
			$outcomes[] = new StockOutcome(
				$target->variantId,
				$target->locationId,
				isset( $applied[ $index ] ) ? StockOutcome::APPLIED : ( 0 === $target->delta() ? StockOutcome::SKIPPED : StockOutcome::CONFLICT ),
				null === $level ? 0 : $level->onHand,
				$entries[ $index ] ?? null
			);
		}

		return $outcomes;
	}

	/**
	 * Refuses the unit when a variant has no stock policy or a location does not exist, and keeps both until the commit: one locking read of each.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.item_missing` or `stock.location_missing`.
	 *
	 * @param StockTarget[] $targets The cells.
	 *
	 * @phpstan-param list<StockTarget> $targets
	 */
	private function requireAdjustable( array $targets ): void {
		$variantIds  = array_values( array_unique( array_map( static fn( StockTarget $target ): int => $target->variantId, $targets ) ) );
		$locationIds = array_unique( array_map( static fn( StockTarget $target ): int => $target->locationId, $targets ) );

		sort( $locationIds );

		$missingVariants = array_diff( $variantIds, $this->stock->lockPolicies( $variantIds ) );

		if ( array() !== $missingVariants ) {
			CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => reset( $missingVariants ) ) );
		}

		$missingLocations = array_diff( $locationIds, $this->stock->lockLocations( $locationIds ) );

		if ( array() !== $missingLocations ) {
			CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => reset( $missingLocations ) ) );
		}
	}

	/**
	 * Writes a ledger entry for each applied cell, in one insert, and stores one StockAdjusted for each.
	 *
	 * @since 0.2.0
	 *
	 * @param StockTarget[]             $targets The cells.
	 * @param int[]                     $applied The indexes of the applied cells, ascending.
	 * @param array<string, StockLevel> $levels  Each item's level after, by variant and location.
	 * @param Actor                     $actor   On whose authority.
	 * @return array<int, int> The ledger entry of each applied cell, by its index.
	 *
	 * @phpstan-param list<StockTarget> $targets
	 * @phpstan-param list<int> $applied
	 */
	private function record( array $targets, array $applied, array $levels, Actor $actor ): array {
		if ( array() === $applied ) {
			return array();
		}

		$actorType = null === $actor->systemName() ? 'user' : 'system';
		$actorId   = $actor->userId() > 0 ? $actor->userId() : null;
		$rows      = array();

		foreach ( $applied as $index ) {
			$target = $targets[ $index ];
			$rows[] = array( $target->variantId, $target->locationId, $target->delta(), $this->levelOf( $levels, $target )->onHand );
		}

		$ids     = $this->stock->appendLedgerRows( $rows, LedgerReason::Recount, $actorType, $actorId, $this->correlation->current() );
		$entries = array_combine( $applied, $ids );
		$events  = array();
		$now     = $this->clock->now();

		foreach ( $entries as $index => $entryId ) {
			$target   = $targets[ $index ];
			$level    = $this->levelOf( $levels, $target );
			$events[] = new StockAdjusted(
				$target->variantId,
				$target->locationId,
				$target->delta(),
				$level->variantOnHand ?? $level->onHand,
				$level->variantAvailable ?? $level->available(),
				$level->onHand,
				$level->available(),
				LedgerReason::Recount->value,
				$actorType,
				$actorId,
				$entryId,
				$now
			);
		}

		$this->events->publish( ...$events );

		return $entries;
	}

	/**
	 * Returns the level after of an applied cell's item, which the read of the levels found since the upsert created it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the read did not find the item: the upsert's row is gone, which no writer in the order allows.
	 *
	 * @param array<string, StockLevel> $levels The levels, by variant and location.
	 * @param StockTarget               $target The cell.
	 * @return StockLevel The level.
	 */
	private function levelOf( array $levels, StockTarget $target ): StockLevel {
		return $levels[ self::key( $target->variantId, $target->locationId ) ] ?? throw new \LogicException( sprintf( 'The stock item of variant %d at location %d was set and is gone.', $target->variantId, $target->locationId ) );
	}

	/**
	 * Sorts the cells in the lock order, refusing two cells of one item.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When two cells name one variant at one location.
	 *
	 * @param StockTarget[] $targets The cells.
	 * @return list<StockTarget> The cells, by variant, then location.
	 */
	private static function sorted( array $targets ): array {
		$keyed = array();

		foreach ( $targets as $target ) {
			$key = self::key( $target->variantId, $target->locationId );

			if ( isset( $keyed[ $key ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Two stock cells name variant %d at location %d.', $target->variantId, $target->locationId ) );
			}

			$keyed[ $key ] = $target;
		}

		$sorted = array_values( $keyed );

		usort( $sorted, static fn( StockTarget $a, StockTarget $b ): int => array( $a->variantId, $a->locationId ) <=> array( $b->variantId, $b->locationId ) );

		return $sorted;
	}

	/**
	 * Returns the key of an item: its variant and its location.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The variant.
	 * @param int $locationId The location.
	 * @return string The key, `variant@location`.
	 */
	private static function key( int $variantId, int $locationId ): string {
		return $variantId . '@' . $locationId;
	}
}

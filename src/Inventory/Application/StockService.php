<?php
/**
 * StockService: holds, releases and adjusts stock, each as one unit of work with its events
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Inventory\Domain\Adjustment;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\Hold;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\ReclaimedRows;
use SEOCart\Inventory\Domain\StockLevel;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML. Coded errors go through CodedException::raise().

/**
 * The inventory module's application service: every stock change goes through it.
 *
 * Owns one fact: how stock changes are sequenced. The repository owns each statement and the
 * invariant in its WHERE clause; this class decides their order, runs them in one transaction
 * with the deadlock retry policy, raises the coded errors, and publishes the events from the
 * statements' own results, so a rolled-back attempt publishes nothing and a retried one
 * publishes once. It has no retry loop of its own and never catches a deadlock or a lock-wait
 * timeout: at the outermost level the transaction manager runs the whole unit again, and inside
 * a caller's transaction the failure goes up to the caller's policy.
 *
 * Every path takes items in ascending variant order and each item before its hold rows, and a
 * reclaim always begins with the item's lock (see StockRepository). Correctness never waits
 * for the sweep: a hold that finds too few units reclaims that item's expired holds itself and
 * claims once more, so an expired hold delays a sale by at most that one retry.
 *
 * It decides nothing about whether a product may be sold: a caller that sells checks that first.
 *
 * @since 0.1.0
 */
final class StockService {

	/**
	 * A release reason: a lowercase snake_case word of at most 32 characters.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const REASON_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

	/**
	 * The statements.
	 *
	 * @since 0.1.0
	 *
	 * @var StockRepository
	 */
	private StockRepository $stock;

	/**
	 * The unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Publishes the events.
	 *
	 * @since 0.1.0
	 *
	 * @var EventPublisher
	 */
	private EventPublisher $events;

	/**
	 * Mints hold ids and reclaim tokens.
	 *
	 * @since 0.1.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Says when an event happened.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * The request's correlation id, which a ledger entry shares with its events.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * The capability check of the operations this service performs.
	 *
	 * @since 0.1.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param StockRepository    $stock       The statements.
	 * @param TransactionManager $tx          The unit of work.
	 * @param EventPublisher     $events      Publishes the events.
	 * @param IdGenerator        $ids         Mints hold ids and reclaim tokens.
	 * @param Clock              $clock       Says when an event happened.
	 * @param CorrelationId      $correlation The request's correlation id.
	 * @param Authorizer         $authorizer  The capability check of the operations it performs.
	 */
	public function __construct( StockRepository $stock, TransactionManager $tx, EventPublisher $events, IdGenerator $ids, Clock $clock, CorrelationId $correlation, Authorizer $authorizer ) {
		$this->stock       = $stock;
		$this->tx          = $tx;
		$this->events      = $events;
		$this->ids         = $ids;
		$this->clock       = $clock;
		$this->correlation = $correlation;
		$this->authorizer  = $authorizer;
	}

	/**
	 * Holds units of one or more variants for a checkout, all or nothing.
	 *
	 * Lines of the same variant are added up, and the lines are held in ascending variant order.
	 * An untracked variant is always available: it gets no hold and is listed on the result. For
	 * each tracked line one conditional update claims the units; when it finds too few, the item's
	 * expired holds are reclaimed and the claim is sent once more, and a second refusal is
	 * `stock.insufficient`. Inside a caller's transaction a refusal undoes this hold's lines only.
	 *
	 * A hold is a stock operation, not a sale: it does not ask whether the product may be sold.
	 * A caller that sells checks that first, and holds only the lines that may be.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\InvalidArgumentException `stock.item_missing` when a variant has no stock item;
	 *         `stock.insufficient` when too few units are available; `stock.projection_corrupt` when a reclaim finds
	 *         `held` below its rows; a database error the transaction could not retry. An \InvalidArgumentException
	 *         when there is no line, a line is not a HoldLine, the TTL is below 1 second, or a cart or order id is below 1.
	 *
	 * @param HoldLine[] $lines      The lines.
	 * @param int        $ttlSeconds How long the hold lasts, 1 second or more.
	 * @param int|null   $cartId     Optional. The cart the hold is for. Default null.
	 * @param int|null   $orderId    Optional. The order the hold is for. Default null.
	 * @return Hold What was held.
	 */
	public function hold( array $lines, int $ttlSeconds, ?int $cartId = null, ?int $orderId = null ): Hold {
		$lines = self::normalise( $lines );

		if ( $ttlSeconds < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A hold lasts at least one second; %d was given.', $ttlSeconds ) );
		}

		foreach ( array( $cartId, $orderId ) as $id ) {
			if ( null !== $id && $id < 1 ) {
				throw new \InvalidArgumentException( 'A cart or order id is 1 or more, or null.' );
			}
		}

		return $this->tx->transaction(
			fn(): Hold => $this->holdInside( $lines, $ttlSeconds, $cartId, $orderId ),
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Releases what is left of a hold, before its expiry.
	 *
	 * Each variant of the hold is taken in ascending order, and its rows of the hold that are still
	 * there are given back; a row already reclaimed after its expiry is not given back twice.
	 * Runs in the caller's transaction, or in its own.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\InvalidArgumentException `stock.projection_corrupt` when an item's `held` is below its
	 *         rows; a database error the transaction could not retry. An \InvalidArgumentException when the hold id is
	 *         empty or the reason is not a lowercase snake_case word.
	 *
	 * @param string $holdGroup The hold's id.
	 * @param string $reason    Why it is released, for the event: a lowercase snake_case word of at most 32 characters.
	 * @return int The hold rows released: one per variant that still had units held.
	 */
	public function release( string $holdGroup, string $reason ): int {
		if ( '' === $holdGroup ) {
			throw new \InvalidArgumentException( 'A release needs the id of the hold.' );
		}

		self::checkReason( $reason );

		return $this->tx->transaction(
			function () use ( $holdGroup, $reason ): int {
				$released = array();

				foreach ( $this->stock->groupVariants( $holdGroup ) as $variantId ) {
					self::collect( $released, $this->giveBack( $this->stock->reclaimGroup( $variantId, $holdGroup, $this->ids->generate() ), false ) );
				}

				return $this->publishReleases( $released, $reason );
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Releases every hold of the given variants, expired or not, and leaves their items and ledgers as they are.
	 *
	 * For a product that stops being sellable but keeps its stock, such as one moved to the trash.
	 * Takes the variants in ascending order; publishes one release per hold. Runs in the caller's
	 * transaction, or in its own.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a variant id is below 1 or the reason is not a lowercase snake_case word.
	 * @throws CodedException            `stock.projection_corrupt` when an item's `held` is below its rows.
	 *
	 * @param int[]  $variantIds The variants.
	 * @param string $reason     Why, for the events: a lowercase snake_case word of at most 32 characters.
	 */
	public function releaseVariants( array $variantIds, string $reason ): void {
		$variantIds = self::variantIds( $variantIds );

		self::checkReason( $reason );

		if ( array() === $variantIds ) {
			return;
		}

		$this->tx->transaction(
			function () use ( $variantIds, $reason ): void {
				$released = array();

				foreach ( $variantIds as $variantId ) {
					self::collect( $released, $this->giveBack( $this->stock->reclaimVariant( $variantId, $this->ids->generate() ), false ) );
				}

				$this->publishReleases( $released, $reason );
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Changes a variant's on_hand and records the change in the ledger.
	 *
	 * An adjustment records a physical fact, so it may leave fewer units than are promised or
	 * held: available then goes negative and is reported, and no new hold can be taken until
	 * stock returns. It never takes on_hand itself below zero. When the caller passes the on_hand
	 * it read, the change applies only if on_hand is still that, which makes a retried request safe.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\InvalidArgumentException `stock.zero_delta` before any statement; `stock.item_missing`,
	 *         `stock.on_hand_conflict` or `stock.adjustment_below_zero` when the update is refused; a database error the
	 *         transaction could not retry. An \InvalidArgumentException when the reason is not one a merchant may give,
	 *         or the expected on_hand is negative.
	 *
	 * @param int          $variantId      The variant.
	 * @param int          $delta          The change of on_hand, not 0.
	 * @param LedgerReason $reason         Why: one of LedgerReason::merchant().
	 * @param Actor        $actor          On whose authority.
	 * @param int|null     $expectedOnHand Optional. The on_hand the caller read. Default null, no precondition.
	 * @return Adjustment The level after the change and its ledger entry.
	 */
	public function adjust( int $variantId, int $delta, LedgerReason $reason, Actor $actor, ?int $expectedOnHand = null ): Adjustment {
		if ( 0 === $delta ) {
			CodedException::raise( InventoryError::ZeroDelta );
		}

		if ( ! in_array( $reason, LedgerReason::merchant(), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'The ledger reason %s is written by the system; an adjustment gives one of LedgerReason::merchant().', $reason->value ) );
		}

		if ( null !== $expectedOnHand && $expectedOnHand < 0 ) {
			throw new \InvalidArgumentException( 'The expected on-hand quantity is 0 or more.' );
		}

		return $this->tx->transaction(
			function () use ( $variantId, $delta, $reason, $actor, $expectedOnHand ): Adjustment {
				if ( ! $this->stock->adjust( $variantId, $delta, $expectedOnHand ) ) {
					$this->refuseAdjustment( $variantId, $delta, $expectedOnHand );
				}

				list( $adjustment, $event ) = $this->record( $this->lockedLevel( $variantId ), $delta, $reason, $actor );

				$this->events->publish( $event );

				return $adjustment;
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Performs `inventory.adjust_stock`: authorizes the actor, adjusts, and returns the stock level by wire name.
	 *
	 * The operation's surfaces call this, through the operation invoker, with the input it
	 * prepared: the variant from the URL on REST, from the input on the ability and the command.
	 * It only translates: the check is the Authorizer's, and the adjustment is adjust()'s.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; the codes adjust() raises.
	 *
	 * @param array<string, mixed> $input The prepared input: variant_id, delta, reason and, optionally, expected_on_hand.
	 * @param Actor                $actor Who adjusts.
	 * @return array<string, int> The stock level after the change, keyed by wire name.
	 */
	public function adjustStock( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$adjustment = $this->adjust(
			(int) $input['variant_id'],
			(int) $input['delta'],
			LedgerReason::from( (string) $input['reason'] ),
			$actor,
			isset( $input['expected_on_hand'] ) ? (int) $input['expected_on_hand'] : null
		);

		return array(
			'variant_id'      => $adjustment->level->variantId,
			'on_hand'         => $adjustment->level->onHand,
			'allocated'       => $adjustment->level->allocated,
			'held'            => $adjustment->level->held,
			'available'       => $adjustment->level->available(),
			'ledger_entry_id' => $adjustment->ledgerEntryId,
		);
	}

	/**
	 * Reads variants' stock levels. Never cached, and never a decision: a hold decides with its own update.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a variant id is below 1.
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, StockLevel> The levels of those that have a stock item, by variant id.
	 */
	public function levels( array $variantIds ): array {
		return $this->stock->levels( self::variantIds( $variantIds ) );
	}

	/**
	 * Gives new variants their stock items, at zero: called by the product write, inside its transaction.
	 *
	 * Idempotent, so a retried unit of work may run it again. Writes no ledger entry, since an
	 * empty ledger adds up to the zero a new item holds, and publishes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException           Outside a transaction, before any statement: an item must not be committed without its variant.
	 * @throws \InvalidArgumentException When a variant id is below 1.
	 *
	 * @param int[] $variantIds The new variants.
	 */
	public function createItems( array $variantIds ): void {
		$this->requireCallersTransaction( __FUNCTION__ );

		$variantIds = self::variantIds( $variantIds );

		if ( array() !== $variantIds ) {
			$this->stock->createItems( $variantIds );
		}
	}

	/**
	 * Removes the stock items of variants being deleted: called by the product delete, inside its transaction.
	 *
	 * Per variant, ascending: the item is locked; an open allocation refuses the whole delete with
	 * `stock.delete_blocked`; every hold is given back; the remaining on_hand is written off with a
	 * final ledger entry, reason `variant_deleted`, so the item's ledger adds up to zero; then the
	 * item is deleted. Its ledger entries stay. A variant without an item is skipped. All of it
	 * happens in a savepoint of the caller's transaction, so a refusal undoes all of it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException           Outside a transaction, before any statement.
	 * @throws \InvalidArgumentException When a variant id is below 1.
	 * @throws CodedException            `stock.delete_blocked` for a variant with an open allocation; `stock.projection_corrupt`
	 *                                   when an item's `held` is below its rows.
	 *
	 * @param int[] $variantIds The variants.
	 * @param Actor $actor      On whose authority.
	 */
	public function deleteVariants( array $variantIds, Actor $actor ): void {
		$this->requireCallersTransaction( __FUNCTION__ );

		$variantIds = self::variantIds( $variantIds );

		if ( array() === $variantIds ) {
			return;
		}

		$this->tx->transaction(
			function () use ( $variantIds, $actor ): void {
				$released = array();
				$written  = array();

				foreach ( $variantIds as $variantId ) {
					if ( ! $this->stock->lockItem( $variantId ) ) {
						continue;
					}

					if ( $this->stock->hasOpenAllocation( $variantId ) ) {
						CodedException::raise( InventoryError::DeleteBlocked, array( 'variant_id' => $variantId ) );
					}

					self::collect( $released, $this->giveBack( $this->stock->reclaimVariant( $variantId, $this->ids->generate() ), true ) );

					$written[] = $this->writeOff( $variantId, $actor );

					$this->stock->deleteItem( $variantId );
				}

				$this->publishReleases( $released, LedgerReason::VariantDeleted->value );
				$this->events->publish( ...$written );
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Reclaims one item's expired holds, in a transaction of its own: the sweep's unit of work.
	 *
	 * The same statements a hold runs when it finds too few units.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `stock.projection_corrupt` when the item's `held` is below its rows.
	 *
	 * @param int $variantId The item.
	 * @return int The hold rows reclaimed; 0 when there were none or the item is gone.
	 */
	public function reclaimExpired( int $variantId ): int {
		return $this->tx->transaction(
			function () use ( $variantId ): int {
				$given  = $this->giveBack( $this->stock->reclaimExpired( $variantId, $this->ids->generate() ), false );
				$events = $this->expiredEvents( $given );

				if ( array() !== $events ) {
					$this->events->publish( ...$events );
				}

				return count( $given->rows );
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Lists a page of items that have expired holds to reclaim: the sweep's search.
	 *
	 * @since 0.1.0
	 *
	 * @param int $after Only items above this variant id: where the previous page ended, or 0.
	 * @param int $limit The most items to list, 1 or more.
	 * @return list<int> The variant ids, ascending.
	 */
	public function expiredVariants( int $after, int $limit ): array {
		return $this->stock->expiredVariants( max( 0, $after ), max( 1, $limit ) );
	}

	/**
	 * Holds the normalised lines inside the transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param HoldLine[] $lines      The lines, one per variant, ascending.
	 * @param int        $ttlSeconds How long the hold lasts.
	 * @param int|null   $cartId     The cart, or null.
	 * @param int|null   $orderId    The order, or null.
	 * @return Hold What was held.
	 *
	 * @phpstan-param list<HoldLine> $lines
	 */
	private function holdInside( array $lines, int $ttlSeconds, ?int $cartId, ?int $orderId ): Hold {
		$configuration = $this->stock->configuration( array_map( static fn( HoldLine $line ): int => $line->variantId, $lines ), $ttlSeconds );

		foreach ( $lines as $line ) {
			if ( ! isset( $configuration['track'][ $line->variantId ] ) ) {
				CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $line->variantId ) );
			}
		}

		$expiresAt = (string) $configuration['expires_at'];
		$holdGroup = $this->ids->generate();
		$held      = array();
		$untracked = array();
		$expired   = array();

		foreach ( $lines as $line ) {
			if ( $configuration['track'][ $line->variantId ] && $this->claim( $line, $expired ) ) {
				$this->stock->insertHold( $line->variantId, $line->quantity, $holdGroup, $expiresAt, $cartId, $orderId );

				$held[] = $line;

				continue;
			}

			$untracked[] = $line->variantId;
		}

		$events = $expired;

		if ( array() !== $held ) {
			$events[] = new StockReserved(
				$holdGroup,
				$cartId,
				$orderId,
				array_map( static fn( HoldLine $line ): int => $line->variantId, $held ),
				array_map( static fn( HoldLine $line ): int => $line->quantity, $held ),
				$expiresAt,
				$this->clock->now()
			);
		}

		if ( array() !== $events ) {
			$this->events->publish( ...$events );
		}

		return new Hold( $holdGroup, new \DateTimeImmutable( $expiresAt, new \DateTimeZone( 'UTC' ) ), $held, $untracked );
	}

	/**
	 * Claims one tracked line's units, reclaiming the item's expired holds and claiming once more when the first claim finds too few.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `stock.insufficient` when the second claim also finds too few units.
	 *
	 * @param HoldLine           $line    The line.
	 * @param StockHoldExpired[] $expired The events of the reclaims so far; this reclaim's are added.
	 * @return bool True when the units are claimed; false when the item turned out to be untracked.
	 *
	 * @phpstan-param list<StockHoldExpired> $expired
	 */
	private function claim( HoldLine $line, array &$expired ): bool {
		if ( $this->stock->claim( $line->variantId, $line->quantity ) ) {
			return true;
		}

		$expired = array_merge( $expired, $this->expiredEvents( $this->giveBack( $this->stock->reclaimExpired( $line->variantId, $this->ids->generate() ), true ) ) );

		if ( $this->stock->claim( $line->variantId, $line->quantity ) ) {
			return true;
		}

		// A diagnostic read under the item's lock: it classifies the refusal, it decides nothing.
		$level = $this->lockedLevel( $line->variantId );

		if ( ! $level->track ) {
			return false;
		}

		CodedException::raise(
			InventoryError::Insufficient,
			array(
				'variant_id' => $line->variantId,
				'requested'  => $line->quantity,
				'available'  => max( 0, $level->available() ),
			)
		);
	}

	/**
	 * Checks what a reclaim reports, and raises when it cannot go on.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `stock.projection_corrupt` when `held` was below the claimed rows; `stock.item_missing`
	 *                        when the item is gone and that is an error for the caller.
	 *
	 * @param ReclaimedRows $reclaimed     What the reclaim reports.
	 * @param bool          $mustBeFound Whether a missing item is an error.
	 * @return ReclaimedRows The same result.
	 */
	private function giveBack( ReclaimedRows $reclaimed, bool $mustBeFound ): ReclaimedRows {
		if ( ! $reclaimed->itemFound && $mustBeFound ) {
			CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $reclaimed->variantId ) );
		}

		if ( ! $reclaimed->projectionHeld ) {
			CodedException::raise( InventoryError::ProjectionCorrupt, array( 'variant_id' => $reclaimed->variantId ) );
		}

		return $reclaimed;
	}

	/**
	 * Builds one event per reclaimed expired row.
	 *
	 * @since 0.1.0
	 *
	 * @param ReclaimedRows $reclaimed The rows.
	 * @return list<StockHoldExpired> The events.
	 */
	private function expiredEvents( ReclaimedRows $reclaimed ): array {
		$now = $this->clock->now();

		return array_map(
			static fn( array $row ): StockHoldExpired => new StockHoldExpired( $row['hold_group'], $reclaimed->variantId, $row['quantity'], $row['expires_at'], $now ),
			$reclaimed->rows
		);
	}

	/**
	 * Adds a reclaim's rows to the units released, by hold and variant.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<int, int>> $released  Hold id => variant id => units; the rows are added.
	 * @param ReclaimedRows                  $reclaimed The rows given back.
	 */
	private static function collect( array &$released, ReclaimedRows $reclaimed ): void {
		foreach ( $reclaimed->rows as $row ) {
			$released[ $row['hold_group'] ][ $reclaimed->variantId ] = ( $released[ $row['hold_group'] ][ $reclaimed->variantId ] ?? 0 ) + $row['quantity'];
		}
	}

	/**
	 * Publishes one release per hold.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<int, int>> $released Hold id => variant id => units, variants ascending.
	 * @param string                         $reason   Why.
	 * @return int The hold rows released: one per hold and variant.
	 */
	private function publishReleases( array $released, string $reason ): int {
		$events = array();
		$rows   = 0;

		foreach ( $released as $holdGroup => $units ) {
			$events[] = new StockReservationReleased( (string) $holdGroup, $reason, array_keys( $units ), array_values( $units ), $this->clock->now() );
			$rows    += count( $units );
		}

		if ( array() !== $events ) {
			$this->events->publish( ...$events );
		}

		return $rows;
	}

	/**
	 * Writes off a deleted variant's on_hand with its final ledger entry.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $variantId The item, locked.
	 * @param Actor $actor     On whose authority.
	 * @return StockAdjusted The event of the final entry, to publish.
	 */
	private function writeOff( int $variantId, Actor $actor ): StockAdjusted {
		$delta = -$this->lockedLevel( $variantId )->onHand;

		if ( 0 !== $delta && ! $this->stock->adjust( $variantId, $delta, null ) ) {
			// The item is locked and the result is exactly zero; only a failure outside this code can get here.
			CodedException::raise( InventoryError::ProjectionCorrupt, array( 'variant_id' => $variantId ) );
		}

		return $this->record( $this->lockedLevel( $variantId ), $delta, LedgerReason::VariantDeleted, $actor )[1];
	}

	/**
	 * Appends the ledger entry of a change of on_hand, and builds its event.
	 *
	 * @since 0.1.0
	 *
	 * @param StockLevel   $after  The level after the change, read under the lock.
	 * @param int          $delta  The change.
	 * @param LedgerReason $reason Why.
	 * @param Actor        $actor  On whose authority.
	 * @return array{0: Adjustment, 1: StockAdjusted} The adjustment and its event.
	 */
	private function record( StockLevel $after, int $delta, LedgerReason $reason, Actor $actor ): array {
		$actorType = null === $actor->systemName() ? 'user' : 'system';
		$actorId   = $actor->userId() > 0 ? $actor->userId() : null;
		$entryId   = $this->stock->appendLedger( $after->variantId, $delta, $after->onHand, $reason, $actorType, $actorId, $this->correlation->current() );

		return array(
			new Adjustment( $after, $entryId ),
			new StockAdjusted( $after->variantId, $delta, $after->onHand, $after->available(), $reason->value, $actorType, $actorId, $entryId, $this->clock->now() ),
		);
	}

	/**
	 * Raises the error a refused adjustment deserves, from one read of the item under its lock.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException Always.
	 *
	 * @param int      $variantId      The item.
	 * @param int      $delta          The change asked for.
	 * @param int|null $expectedOnHand The on_hand the caller expected, or null.
	 * @return never
	 */
	private function refuseAdjustment( int $variantId, int $delta, ?int $expectedOnHand ): never {
		$level = $this->stock->lockedLevel( $variantId );

		if ( null === $level ) {
			CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $variantId ) );
		}

		if ( null !== $expectedOnHand && $expectedOnHand !== $level->onHand ) {
			CodedException::raise(
				InventoryError::OnHandConflict,
				array(
					'variant_id' => $variantId,
					'expected'   => $expectedOnHand,
					'on_hand'    => $level->onHand,
				)
			);
		}

		CodedException::raise(
			InventoryError::AdjustmentBelowZero,
			array(
				'variant_id' => $variantId,
				'on_hand'    => $level->onHand,
				'delta'      => $delta,
			)
		);
	}

	/**
	 * Reads an item under its lock, which the caller already holds.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `stock.item_missing` when it is gone, which the lock the caller holds rules out.
	 *
	 * @param int $variantId The item.
	 * @return StockLevel The level.
	 */
	private function lockedLevel( int $variantId ): StockLevel {
		$level = $this->stock->lockedLevel( $variantId );

		if ( null === $level ) {
			CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $variantId ) );
		}

		return $level;
	}

	/**
	 * Refuses a call that must run inside the caller's transaction, before any statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException At depth 0.
	 *
	 * @param string $method The method called.
	 */
	private function requireCallersTransaction( string $method ): void {
		if ( 0 === $this->tx->depth() ) {
			throw new \LogicException( sprintf( 'StockService::%s() runs inside the caller\'s transaction: a stock item must not be committed or removed apart from its variant.', $method ) );
		}
	}

	/**
	 * Adds up the lines of each variant and orders them by variant.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When there is no line, or a line is not a HoldLine.
	 *
	 * @param array<mixed> $lines The lines.
	 * @return list<HoldLine> One line per variant, ascending.
	 */
	private static function normalise( array $lines ): array {
		if ( array() === $lines ) {
			throw new \InvalidArgumentException( 'A hold needs at least one line.' );
		}

		$quantities = array();

		foreach ( $lines as $line ) {
			if ( ! $line instanceof HoldLine ) {
				throw new \InvalidArgumentException( sprintf( 'A hold line is a HoldLine; %s was given.', get_debug_type( $line ) ) );
			}

			$quantities[ $line->variantId ] = ( $quantities[ $line->variantId ] ?? 0 ) + $line->quantity;
		}

		ksort( $quantities );

		$normalised = array();

		foreach ( $quantities as $variantId => $quantity ) {
			$normalised[] = new HoldLine( $variantId, $quantity );
		}

		return $normalised;
	}

	/**
	 * Checks variant ids, and returns them once each, ascending.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When an id is not an integer of 1 or more.
	 *
	 * @param array<mixed> $variantIds The ids.
	 * @return list<int> The ids, unique and ascending.
	 */
	private static function variantIds( array $variantIds ): array {
		$unique = array();

		foreach ( $variantIds as $variantId ) {
			if ( ! is_int( $variantId ) || $variantId < 1 ) {
				throw new \InvalidArgumentException( 'A variant id is an integer of 1 or more.' );
			}

			$unique[ $variantId ] = $variantId;
		}

		ksort( $unique );

		return array_values( $unique );
	}

	/**
	 * Checks a release reason.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When it is not a lowercase snake_case word of at most 32 characters.
	 *
	 * @param string $reason The reason.
	 */
	private static function checkReason( string $reason ): void {
		if ( 1 !== preg_match( self::REASON_PATTERN, $reason ) ) {
			throw new \InvalidArgumentException( 'A release reason is a lowercase snake_case word of at most 32 characters, such as payment_declined.' );
		}
	}
}

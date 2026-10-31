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
use SEOCart\Inventory\Domain\Allocation;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\Event\StockAllocated;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\Hold;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\Location;
use SEOCart\Inventory\Domain\ReclaimedRows;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Domain\StockLevel;
use SEOCart\Inventory\Domain\StockLines;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\Isolation;
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
 * A stock item is one variant at one location. Every path takes items in ascending order of the
 * variant, then the location, and each item before its hold rows, and a reclaim always begins with
 * the item's lock (see StockRepository, and its one exception: an allocation whose hold is gone).
 * A line or an adjustment that names no location is the store's default location's, which is
 * where a store with one location keeps all of its stock. Every unit of work that writes holds or
 * items runs at READ COMMITTED, so its statements lock the rows they match and no gap between
 * them: under REPEATABLE READ a reclaim's lookups by token lock the gaps every new hold row of
 * every item and every other reclaim must write into, and a hold of one item waited for, or
 * deadlocked with, a reclaim of another. Correctness never waits for the sweep: a hold that finds
 * too few units reclaims that item's expired holds itself and claims once more, so an expired
 * hold delays a sale by at most that one retry.
 *
 * It decides nothing about whether a product may be sold: a caller that sells checks that first.
 *
 * @since 0.1.0
 */
final class StockService {

	/**
	 * A list of variant ids as a client writes it: whole numbers above 0, of at most eighteen digits, separated by commas, with spaces allowed around them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VARIANT_LIST = '/^\s*[1-9]\d{0,17}(?:\s*,\s*[1-9]\d{0,17})*\s*\z/';

	/**
	 * A release reason: a lowercase snake_case word of at most 32 characters.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const REASON_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

	/**
	 * The release reason of holds whose item was gone, given back by doctor's repair.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ITEM_MISSING = 'item_missing';

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
	 * The locations, which name the default one.
	 *
	 * @since 0.2.0
	 *
	 * @var LocationDirectory
	 */
	private LocationDirectory $locations;

	/**
	 * Plans where units claimed afresh come from.
	 *
	 * @since 0.2.0
	 *
	 * @var FulfilmentRouting
	 */
	private FulfilmentRouting $routing;

	/**
	 * Says how many units of each variant are for sale, for the stock list.
	 *
	 * @since 0.2.0
	 *
	 * @var Availability
	 */
	private Availability $availability;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The locations, the routing and the availability.
	 *
	 * @param StockRepository    $stock        The statements.
	 * @param TransactionManager $tx           The unit of work.
	 * @param EventPublisher     $events       Publishes the events.
	 * @param IdGenerator        $ids          Mints hold ids and reclaim tokens.
	 * @param Clock              $clock        Says when an event happened.
	 * @param CorrelationId      $correlation  The request's correlation id.
	 * @param Authorizer         $authorizer   The capability check of the operations it performs.
	 * @param LocationDirectory  $locations    The locations, which name the default one.
	 * @param FulfilmentRouting  $routing      Plans where units claimed afresh come from.
	 * @param Availability       $availability Says how many units of each variant are for sale.
	 */
	public function __construct( StockRepository $stock, TransactionManager $tx, EventPublisher $events, IdGenerator $ids, Clock $clock, CorrelationId $correlation, Authorizer $authorizer, LocationDirectory $locations, FulfilmentRouting $routing, Availability $availability ) {
		$this->stock        = $stock;
		$this->tx           = $tx;
		$this->events       = $events;
		$this->ids          = $ids;
		$this->clock        = $clock;
		$this->correlation  = $correlation;
		$this->authorizer   = $authorizer;
		$this->locations    = $locations;
		$this->routing      = $routing;
		$this->availability = $availability;
	}

	/**
	 * Holds units of one or more variants for a checkout, all or nothing.
	 *
	 * Lines of the same variant and location are added up; a line that names no location is held
	 * at the store's default location, read with the policies in the one read before any lock; and
	 * the lines are held in ascending order of the variant, then the location. A variant whose
	 * stock is not counted is always available: it gets no hold and is listed on the result. For
	 * each tracked line one conditional update claims the units at its location; when it finds too
	 * few, that item's expired holds are reclaimed and the claim is sent once more, and a second
	 * refusal is `stock.insufficient`, naming the location. A location the variant has no item at
	 * has no units there. Once every line is claimed, the hold's rows are written together. Inside
	 * a caller's transaction a refusal undoes this hold's lines only.
	 *
	 * A hold is a stock operation, not a sale: it does not ask whether the product may be sold.
	 * A caller that sells checks that first, and holds only the lines that may be.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Each line at a location.
	 *
	 * @throws CodedException|\InvalidArgumentException `stock.item_missing` when a variant has no stock policy;
	 *         `stock.no_fulfilling_location` when a line names no location and the store has no default one;
	 *         `stock.insufficient` when too few units are available at a line's location; `stock.projection_corrupt`
	 *         when a reclaim finds `held` below its rows; a database error the transaction could not retry. An
	 *         \InvalidArgumentException when there is no line, a line is not a HoldLine, the TTL is below 1 second, or a
	 *         cart or order id is below 1.
	 *
	 * @param HoldLine[] $lines      The lines.
	 * @param int        $ttlSeconds How long the hold lasts, 1 second or more.
	 * @param int|null   $cartId     Optional. The cart the hold is for. Default null.
	 * @param int|null   $orderId    Optional. The order the hold is for. Default null.
	 * @return Hold What was held, each line at its location.
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
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Releases what is left of a hold, before its expiry.
	 *
	 * Each item of the hold is taken in ascending order, and its rows of the hold that are still
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
	 * @return int The hold rows released: one per item that still had units held.
	 */
	public function release( string $holdGroup, string $reason ): int {
		if ( '' === $holdGroup ) {
			throw new \InvalidArgumentException( 'A release needs the id of the hold.' );
		}

		self::checkReason( $reason );

		return $this->tx->transaction(
			function () use ( $holdGroup, $reason ): int {
				$released = array();

				foreach ( $this->stock->holdRows( $holdGroup ) as $row ) {
					self::collect( $released, $this->giveBack( $this->stock->reclaimGroup( $row['variant_id'], $row['location_id'], $holdGroup, $this->ids->generate() ), false ) );
				}

				return $this->publishReleases( $released, $reason );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Releases every hold of the given variants, at every location, expired or not, and leaves their items and ledgers as they are.
	 *
	 * For a product that stops being sellable but keeps its stock, such as one moved to the trash.
	 * Takes the items in ascending order; publishes one release per hold. Runs in the caller's
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

				foreach ( $this->stock->itemLocations( $variantIds ) as $variantId => $locationIds ) {
					foreach ( $locationIds as $locationId ) {
						self::collect( $released, $this->giveBack( $this->stock->reclaimItem( $variantId, $locationId, $this->ids->generate() ), false ) );
					}
				}

				$this->publishReleases( $released, $reason );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Allocates an accepted order's units, from the hold placement took for it: inside the caller's transaction, all or nothing.
	 *
	 * The hold's own rows say where its units are: they are read first, one row per item, and the
	 * order's lines are taken by variant, in ascending order. For each tracked variant, the hold's
	 * rows of it are converted as far as the order's lines need them: the units move from `held` to
	 * `allocated` under the item's lock and the row is deleted, or, when it holds more than the order
	 * takes, keeps the rest held by the hold, so `held` and `allocated` stay equal to their rows. When the
	 * hold no longer has its rows of a variant, because one expired and another checkout reclaimed
	 * it, or a delete released it, the units missing are claimed afresh where routing puts them,
	 * whatever the store's choice for orders no one location ships whole: units are brought to the
	 * order's destination, which the context names, or the default location, so a paid order is
	 * never refused for the way the store prefers to ship. Each claim reclaims the item's expired
	 * holds once when too few are available; still too few is `stock.insufficient`, which undoes
	 * every line of this allocation and leaves the caller to decide. The units of each variant are
	 * shared out to its lines in the order given, location by location, one allocation row per
	 * line and location, the rows written together once every line's units are the order's; when a
	 * variant's units were claimed afresh, the rows so far are written right after that claim, so
	 * an order allocated a second time fails on the order line's unique key at its first variant,
	 * when the claim lands where the first allocation did. An untracked variant was never held and
	 * is not allocated. StockAllocated goes out through the outbox with the transaction.
	 *
	 * The fresh claim is the one exception to the lock order: its location may sort before one this
	 * allocation has already locked. A deadlock it causes rolls the caller's unit back whole, for its
	 * deadlock retry policy.
	 *
	 * Outside a transaction it throws \LogicException before any statement.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Per item, and the context of the claims made afresh.
	 *
	 * @throws CodedException|\InvalidArgumentException `stock.insufficient` when a variant's hold is gone and too few
	 *         units are available; `stock.item_missing` when a variant has no stock policy any more;
	 *         `stock.projection_corrupt` when a reclaim finds `held` below its rows. An \InvalidArgumentException when
	 *         the hold id is empty, the order id is below 1, or there is no line.
	 *
	 * @param string              $holdGroup The hold placement took for the order.
	 * @param int                 $orderId   The order.
	 * @param Allocation[]        $lines     The order's lines: each line's variant and units.
	 * @param RoutingContext|null $context   Optional. The order's market, pickup location and destination. Default null,
	 *                                       RoutingContext::none(): units claimed afresh are brought to the default location.
	 * @return list<Allocation> The allocations made: of tracked variants, each at its location.
	 *
	 * @phpstan-param list<Allocation> $lines
	 */
	public function allocate( string $holdGroup, int $orderId, array $lines, ?RoutingContext $context = null ): array {
		$this->requireCallersTransaction( __FUNCTION__ );

		if ( '' === $holdGroup || $orderId < 1 || array() === $lines ) {
			throw new \InvalidArgumentException( 'An allocation names the hold and the order, and allocates at least one line.' );
		}

		$byVariant = array();

		foreach ( $lines as $line ) {
			$byVariant[ $line->variantId ][] = $line;
		}

		ksort( $byVariant );

		// A savepoint of the caller's transaction, whose level and retry policy apply: a refusal undoes this allocation only.
		return $this->tx->transaction( fn(): array => $this->allocateInside( $holdGroup, $orderId, $byVariant, $context ?? RoutingContext::none() ) );
	}

	/**
	 * Changes an item's on_hand and records the change in the ledger.
	 *
	 * The variant must have a stock policy and the location must exist; an item that does not
	 * exist at the location yet is created at zero first, by the one statement that also takes its
	 * lock, so the first adjustment at a location is its first movement there. A deactivated
	 * location may still receive units. An adjustment records a physical fact, so it may leave
	 * fewer units than are promised or held: available then goes negative and is reported, and no
	 * new hold can be taken until stock returns. It never takes on_hand itself below zero. When the
	 * caller passes the on_hand it read, the change applies only if on_hand is still that, which
	 * makes a retried request safe; an item just created has 0.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws CodedException|\InvalidArgumentException `stock.zero_delta` before any statement; `stock.item_missing`
	 *         when the variant has no stock policy; `stock.location_missing` when the location does not exist;
	 *         `stock.on_hand_conflict` or `stock.adjustment_below_zero` when the update is refused; a database error the
	 *         transaction could not retry. An \InvalidArgumentException when the reason is not one a merchant may give,
	 *         or the expected on_hand is negative.
	 *
	 * @param int          $variantId      The variant.
	 * @param int          $locationId     The location.
	 * @param int          $delta          The change of on_hand, not 0.
	 * @param LedgerReason $reason         Why: one of LedgerReason::merchant().
	 * @param Actor        $actor          On whose authority.
	 * @param int|null     $expectedOnHand Optional. The on_hand the caller read. Default null, no precondition.
	 * @return Adjustment The level after the change and its ledger entry.
	 */
	public function adjust( int $variantId, int $locationId, int $delta, LedgerReason $reason, Actor $actor, ?int $expectedOnHand = null ): Adjustment {
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
			function () use ( $variantId, $locationId, $delta, $reason, $actor, $expectedOnHand ): Adjustment {
				$this->requireAdjustable( $variantId, $locationId );
				$this->stock->createItemAt( $variantId, $locationId );

				if ( ! $this->stock->adjust( $variantId, $locationId, $delta, $expectedOnHand ) ) {
					$this->refuseAdjustment( $variantId, $locationId, $delta, $expectedOnHand );
				}

				list( $adjustment, $event ) = $this->record( $this->lockedLevel( $variantId, $locationId ), $delta, $reason, $actor );

				$this->events->publish( $event );

				return $adjustment;
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Performs `inventory.adjust_stock`: authorizes the actor, adjusts the stock at the location named, or at the default location, and returns the stock level by wire name.
	 *
	 * The operation's surfaces call this, through the operation invoker, with the input it
	 * prepared: the variant from the URL on REST, from the input on the ability and the command;
	 * the location from the input on all three, and only from there. It only translates: the check
	 * is the Authorizer's, and the adjustment is adjust()'s.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; `stock.location_missing`
	 *                        when no location has the uuid; `stock.no_fulfilling_location` when none is named and the
	 *                        store has no default location; the codes adjust() raises.
	 *
	 * @param array<string, mixed> $input The prepared input: variant_id, delta, reason and, optionally, location_uuid and expected_on_hand.
	 * @param Actor                $actor Who adjusts.
	 * @return array<string, int|string> The stock level after the change, keyed by wire name.
	 */
	public function adjustStock( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$location   = $this->adjustedLocation( isset( $input['location_uuid'] ) ? (string) $input['location_uuid'] : null );
		$adjustment = $this->adjust(
			(int) $input['variant_id'],
			$location->id,
			(int) $input['delta'],
			LedgerReason::from( (string) $input['reason'] ),
			$actor,
			isset( $input['expected_on_hand'] ) ? (int) $input['expected_on_hand'] : null
		);

		return array(
			'variant_id'      => $adjustment->level->variantId,
			'location_uuid'   => $location->uuid,
			'on_hand'         => $adjustment->level->onHand,
			'allocated'       => $adjustment->level->allocated,
			'held'            => $adjustment->level->held,
			'available'       => $adjustment->level->available(),
			'ledger_entry_id' => $adjustment->ledgerEntryId,
		);
	}

	/**
	 * Reads variants' stock levels at the default location. Never cached, and never a decision: a hold decides with its own update.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a variant id is below 1.
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, StockLevel> The levels of those that have a stock policy and an item at the default location, by variant id.
	 */
	public function levels( array $variantIds ): array {
		return $this->stock->levels( self::variantIds( $variantIds ) );
	}

	/**
	 * Reads variants' stock levels at every location they have an item at. Never cached, and never a decision.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When a variant id is below 1.
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, list<StockLevel>> The levels of each variant that has a stock policy, ascending by location, by variant id.
	 */
	public function levelsAt( array $variantIds ): array {
		return $this->stock->levelsAt( self::variantIds( $variantIds ) );
	}

	/**
	 * Performs `inventory.list_stock`: each variant's policy, its units at every location a merchant manages it at, and its units for sale.
	 *
	 * A row for every active location, zeros where the variant has no item there yet, since an
	 * item at a location is created by the first units moved there; and one for each inactive
	 * location that still has an item. The figure for sale is Availability's, the one the
	 * storefront shows. Two reads whatever the number of variants, the stock list and the figure,
	 * and the list of locations when the object cache does not hold it. A variant with no stock
	 * policy is left out. Never cached, and never a decision.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; `stock.variant_ids_invalid`
	 *                        when the list is not one to LocationOperations::MOST_LISTED_VARIANTS ids above 0, separated by commas.
	 *
	 * @param array<string, mixed> $input The prepared input: variant_ids.
	 * @param Actor                $actor Who reads.
	 * @return array{variants: list<array<string, mixed>>} The variants that have stock, ascending, keyed by wire name.
	 */
	public function listStock( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$stock     = $this->stock->stockList( self::listedVariants( is_string( $input['variant_ids'] ?? null ) ? $input['variant_ids'] : '' ) );
		$forSale   = $this->availability->forSale( array_keys( $stock ), null );
		$locations = $this->locations->all();
		$variants  = array();

		foreach ( $stock as $variantId => $variant ) {
			$levels = array();

			foreach ( $variant['levels'] as $level ) {
				$levels[ $level->locationId ] = $level;
			}

			$rows = array();

			foreach ( $locations as $location ) {
				$level = $levels[ $location->id ] ?? null;

				if ( null !== $level || $location->active ) {
					$rows[] = array(
						'location_uuid' => $location->uuid,
						'on_hand'       => $level->onHand ?? 0,
						'allocated'     => $level->allocated ?? 0,
						'held'          => $level->held ?? 0,
						'available'     => $level?->available() ?? 0,
					);
				}
			}

			$variants[] = array(
				'variant_id'          => $variantId,
				'track'               => $variant['policy']->track,
				'backorder_policy'    => $variant['policy']->backorderPolicy->value,
				'low_stock_threshold' => $variant['policy']->lowStockThreshold,
				'for_sale'            => array_key_exists( $variantId, $forSale ) ? $forSale[ $variantId ] : 0,
				'locations'           => $rows,
			);
		}

		return array( 'variants' => $variants );
	}

	/**
	 * Gives new variants their stock policy, at the defaults, and their item at the default location, at zero: called by the product write, inside its transaction.
	 *
	 * Idempotent, so a retried unit of work may run it again: a policy or an item already there is
	 * left as it is. Writes no ledger entry, since an empty ledger adds up to the zero a new item
	 * holds, and publishes nothing. Items at other locations are created by the first stock moved
	 * there.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The policy, the default location, and the variants created.
	 *
	 * @throws \LogicException           Outside a transaction, before any statement: an item must not be committed without its variant.
	 * @throws \InvalidArgumentException When a variant id is below 1.
	 * @throws CodedException            `stock.no_fulfilling_location` when the store has no default location.
	 *
	 * @param int[] $variantIds The new variants.
	 * @return list<int> The variants whose stock policy row or row at the default location this call inserted, ascending: a
	 *                   variant whose two rows were both there already, whoever wrote them, is not listed.
	 */
	public function createItems( array $variantIds ): array {
		$this->requireCallersTransaction( __FUNCTION__ );

		$variantIds = self::variantIds( $variantIds );

		if ( array() === $variantIds ) {
			return array();
		}

		return $this->stock->createItems( $variantIds, $this->defaultLocation() );
	}

	/**
	 * Returns the variants among some that deleteVariants() would refuse: those with an allocation open or in transit, or units on a transfer requested or in transit, at any location, read without writing anything.
	 *
	 * For a delete that must decide whether it can go on before it writes: a product post's delete
	 * refuses in WordPress's `pre_delete_post` and writes only once the post is gone. Each variant's
	 * allocations, then its transfer lines, are read with locking reads, ascending, in the caller's
	 * transaction.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Allocations in transit, and transfers.
	 *
	 * @throws \LogicException           Outside a transaction, before any statement.
	 * @throws \InvalidArgumentException When a variant id is below 1.
	 *
	 * @param int[] $variantIds The variants.
	 * @return list<int> The variants whose delete would be refused, ascending.
	 */
	public function openAllocationVariants( array $variantIds ): array {
		$this->requireCallersTransaction( __FUNCTION__ );

		$open = array();

		foreach ( self::variantIds( $variantIds ) as $variantId ) {
			if ( $this->isPromised( $variantId ) ) {
				$open[] = $variantId;
			}
		}

		return $open;
	}

	/**
	 * Removes the stock of variants being deleted, at every location: called by the product delete, inside its transaction.
	 *
	 * Per variant, ascending: its policy is locked, then every item of it, in ascending location
	 * order; an allocation open or in transit at any location, or a line of the variant on a
	 * transfer requested or in transit, refuses the whole delete with `stock.delete_blocked`, since
	 * units on the way would otherwise arrive for a variant that is gone. The transfer check locks
	 * the lines and their transfers, which a transfer's receipt takes before the variant's policy,
	 * so the two can deadlock: the caller's deadlock retry policy runs the delete again, and it is
	 * then refused or goes on. At each location every hold is given back, the remaining on_hand is
	 * written off with a final ledger entry, reason `variant_deleted`, so the item's ledger adds up
	 * to zero, and the item is deleted; then the policy is. Its ledger entries stay. A variant with
	 * no policy and no item is skipped. All of it happens in a savepoint of the caller's
	 * transaction, so a refusal undoes all of it.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 At every location, and the policy.
	 *
	 * @throws \LogicException           Outside a transaction, before any statement.
	 * @throws \InvalidArgumentException When a variant id is below 1.
	 * @throws CodedException            `stock.delete_blocked` for a variant with an allocation open or in transit, or units on a
	 *                                   transfer not yet received; `stock.projection_corrupt` when an item's `held` is below its rows.
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
					$hasPolicy = $this->stock->lockPolicy( $variantId );
					$locations = $this->stock->lockItemsOf( $variantId );

					if ( ! $hasPolicy && array() === $locations ) {
						continue;
					}

					if ( $this->isPromised( $variantId ) ) {
						CodedException::raise( InventoryError::DeleteBlocked, array( 'variant_id' => $variantId ) );
					}

					foreach ( $locations as $locationId ) {
						self::collect( $released, $this->giveBack( $this->stock->reclaimItem( $variantId, $locationId, $this->ids->generate() ), true ) );

						$written[] = $this->writeOff( $variantId, $locationId, $actor );

						$this->stock->deleteItem( $variantId, $locationId );
					}

					$this->stock->deletePolicy( $variantId );
				}

				$this->publishReleases( $released, LedgerReason::VariantDeleted->value );

				if ( array() !== $written ) {
					$this->events->publish( ...$written );
				}
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
	 * @since 0.2.0 The location.
	 *
	 * @throws CodedException `stock.projection_corrupt` when the item's `held` is below its rows.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return int The hold rows reclaimed; 0 when there were none or the item is gone.
	 */
	public function reclaimExpired( int $variantId, int $locationId ): int {
		return $this->tx->transaction(
			function () use ( $variantId, $locationId ): int {
				$given  = $this->giveBack( $this->stock->reclaimExpired( $variantId, $locationId, $this->ids->generate() ), false );
				$events = $this->expiredEvents( $given );

				if ( array() !== $events ) {
					$this->events->publish( ...$events );
				}

				return count( $given->rows );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Gives back the hold rows of items that are gone, recreating each item first: doctor's repair of holds nothing else can reach, in a unit of work of its own.
	 *
	 * Every reclaim begins with its item's lock, so a hold row whose item is gone is given back by
	 * nothing, the sweep included. Per item, ascending: the item is recreated with nothing on hand
	 * or allocated and `held` the sum of its hold rows, which takes its lock, and those rows are
	 * then given back through the reclaim every release uses. The item ends at zero with no hold,
	 * and one StockReservationReleased per hold says so, reason `item_missing`; a settlement of such
	 * a hold claims its units afresh, as when the item is missing. The item is not left at zero
	 * with its holds, because a reclaim would then find `held` below its rows and refuse every
	 * time, the sweep's included; nor holding its rows' units, because a settlement could then
	 * turn units the location does not have into an order's allocation. An item that exists again
	 * by the time it is recreated, whoever created it, is left as it is, and so are its holds.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException            `stock.projection_corrupt` when a recreated item's `held` is below its rows, which
	 *                                   the lock the item's creation takes rules out.
	 * @throws \InvalidArgumentException When an item is not a variant id and a location id, each 1 or more.
	 *
	 * @param array $items The items, each its variant and its location.
	 * @return list<ReclaimedRows> What was given back of each item this call recreated, ascending.
	 *
	 * @phpstan-param list<array{0: int, 1: int}> $items
	 */
	public function releaseHoldsOfMissingItems( array $items ): array {
		$items = self::items( $items );

		if ( array() === $items ) {
			return array();
		}

		return $this->tx->transaction(
			function () use ( $items ): array {
				$restored = array();
				$released = array();

				foreach ( $items as list( $variantId, $locationId ) ) {
					if ( ! $this->stock->restoreItem( $variantId, $locationId ) ) {
						continue;
					}

					$reclaimed = $this->giveBack( $this->stock->reclaimItem( $variantId, $locationId, $this->ids->generate() ), true );

					self::collect( $released, $reclaimed );

					$restored[] = $reclaimed;
				}

				$this->publishReleases( $released, self::ITEM_MISSING );

				return $restored;
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Lists a page of items that have expired holds to reclaim: the sweep's search.
	 *
	 * @since 0.2.0
	 *
	 * @param int $afterVariantId  Only items after this variant and location: where the previous page ended, or 0 and 0.
	 * @param int $afterLocationId See $afterVariantId.
	 * @param int $limit           The most items to list, 1 or more.
	 * @return list<array{0: int, 1: int}> Each item's variant and location, ascending.
	 */
	public function expiredItems( int $afterVariantId, int $afterLocationId, int $limit ): array {
		return $this->stock->expiredItems( max( 0, $afterVariantId ), max( 0, $afterLocationId ), max( 1, $limit ) );
	}

	/**
	 * Lists the variant ids that have a stock policy, wherever their items are: Catalog's doctor
	 * reads this to find stock whose variant is gone, without reading Inventory's tables itself.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterVariantId Only variants with a higher id: where the previous page ended, or 0.
	 * @param int $limit          The most variants to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function itemVariantIds( int $afterVariantId, int $limit ): array {
		return $this->stock->itemVariantIds( max( 0, $afterVariantId ), max( 1, $limit ) );
	}

	/**
	 * Holds the normalised lines inside the transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param HoldLine[] $lines      The lines, one per variant and location or default.
	 * @param int        $ttlSeconds How long the hold lasts.
	 * @param int|null   $cartId     The cart, or null.
	 * @param int|null   $orderId    The order, or null.
	 * @return Hold What was held.
	 *
	 * @phpstan-param list<HoldLine> $lines
	 */
	private function holdInside( array $lines, int $ttlSeconds, ?int $cartId, ?int $orderId ): Hold {
		$configuration = $this->stock->configuration( array_values( array_unique( array_map( static fn( HoldLine $line ): int => $line->variantId, $lines ) ) ), $ttlSeconds );

		foreach ( $lines as $line ) {
			if ( ! isset( $configuration['policies'][ $line->variantId ] ) ) {
				CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $line->variantId ) );
			}
		}

		$lines     = self::located( $lines, $configuration['default_location_id'] );
		$expiresAt = (string) $configuration['expires_at'];
		$holdGroup = $this->ids->generate();
		$held      = array();
		$untracked = array();
		$expired   = array();

		foreach ( $lines as $line ) {
			if ( ! $configuration['policies'][ $line->variantId ]->track ) {
				$untracked[ $line->variantId ] = $line->variantId;

				continue;
			}

			$this->claim( $line, $expired );

			$held[] = $line;
		}

		// Every held line's units are claimed now, each under its item's lock until the commit: their rows go in together.
		$this->stock->insertHolds( $held, $holdGroup, $expiresAt, $cartId, $orderId );

		$events = $expired;

		if ( array() !== $held ) {
			list( $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities ) = StockLines::of( array_map( static fn( HoldLine $line ): array => array( $line->variantId, (int) $line->locationId, $line->quantity ), $held ) );

			$events[] = new StockReserved( $holdGroup, $cartId, $orderId, $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities, $expiresAt, $this->clock->now() );
		}

		if ( array() !== $events ) {
			$this->events->publish( ...$events );
		}

		return new Hold( $holdGroup, new \DateTimeImmutable( $expiresAt, new \DateTimeZone( 'UTC' ) ), $held, array_values( $untracked ) );
	}

	/**
	 * Claims one tracked line's units at its location, reclaiming the item's expired holds and claiming once more when the first claim finds too few.
	 *
	 * The variant's policy was found by the read before the locks, so an item missing at the
	 * location is no units there, not a missing item.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `stock.insufficient` when the second claim also finds too few units.
	 *
	 * @param HoldLine           $line    The line, at its location.
	 * @param StockHoldExpired[] $expired The events of the reclaims so far; this reclaim's are added.
	 *
	 * @phpstan-param list<StockHoldExpired> $expired
	 */
	private function claim( HoldLine $line, array &$expired ): void {
		$locationId = (int) $line->locationId;

		if ( $this->stock->claim( $line->variantId, $locationId, $line->quantity ) ) {
			return;
		}

		$expired = array_merge( $expired, $this->expiredEvents( $this->giveBack( $this->stock->reclaimExpired( $line->variantId, $locationId, $this->ids->generate() ), false ) ) );

		if ( $this->stock->claim( $line->variantId, $locationId, $line->quantity ) ) {
			return;
		}

		$this->refuseClaim( $line->variantId, $locationId, $line->quantity );
	}

	/**
	 * Allocates the order's lines inside the transaction, variant by variant.
	 *
	 * @since 0.1.0
	 *
	 * @param string                       $holdGroup The hold.
	 * @param int                          $orderId   The order.
	 * @param array<int, list<Allocation>> $byVariant The lines by variant, ascending.
	 * @param RoutingContext               $context   Where units claimed afresh are brought.
	 * @return list<Allocation> The allocations made.
	 */
	private function allocateInside( string $holdGroup, int $orderId, array $byVariant, RoutingContext $context ): array {
		// The read every hold starts with, before any lock; the expiry it also computes is not used.
		$policies  = $this->stock->configuration( array_keys( $byVariant ), 1 )['policies'];
		$held      = self::byItem( $this->stock->holdRows( $holdGroup ) );
		$allocated = array();
		$unwritten = array();
		$parts     = array();
		$expired   = array();

		foreach ( $byVariant as $variantId => $lines ) {
			if ( ! isset( $policies[ $variantId ] ) ) {
				CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $variantId ) );
			}

			if ( ! $policies[ $variantId ]->track ) {
				continue;
			}

			$quantity = array_sum( array_map( static fn( Allocation $line ): int => $line->quantity, $lines ) );
			$units    = $this->convert( $holdGroup, $variantId, $quantity, $held[ $variantId ] ?? array() );
			$short    = $quantity - array_sum( $units );

			if ( $short > 0 ) {
				foreach ( $this->claimAfresh( $variantId, $short, $context, $expired ) as $locationId => $claimed ) {
					$units[ $locationId ] = ( $units[ $locationId ] ?? 0 ) + $claimed;
				}

				ksort( $units );
			}

			$shares = self::shareOut( $lines, $units );

			array_push( $allocated, ...$shares );
			array_push( $unwritten, ...$shares );

			if ( $short > 0 ) {
				// A hold that is gone may have been converted by an earlier allocation of this very order: the rows so far are written at once, so a second allocation fails on the order line's unique key here, at its first variant, before it can find a later variant sold out.
				$this->stock->insertAllocations( $orderId, $unwritten );

				$unwritten = array();
			}

			foreach ( $units as $locationId => $atLocation ) {
				$parts[] = array( $variantId, $locationId, $atLocation );
			}
		}

		// Every other line's units are the order's now, each under its item's lock until the commit: their rows go in together.
		$this->stock->insertAllocations( $orderId, $unwritten );

		$events = $expired;

		if ( array() !== $parts ) {
			list( $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities ) = StockLines::of( $parts );

			$events[] = new StockAllocated( $orderId, $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities, $this->clock->now() );
		}

		if ( array() !== $events ) {
			$this->events->publish( ...$events );
		}

		return $allocated;
	}

	/**
	 * Converts a hold's rows of one variant into allocated units, location by location, as far as the order's units need them.
	 *
	 * A row converts as many of its units as are still needed: all of them, or, when it holds more
	 * than the order takes, the part needed, the rest staying held by the hold until it is released
	 * or expires.
	 *
	 * @since 0.2.0
	 *
	 * @param string          $holdGroup The hold.
	 * @param int             $variantId The variant.
	 * @param int             $quantity  The units the order's lines of it need.
	 * @param array<int, int> $rows      The hold's units of it, by location, ascending.
	 * @return array<int, int> The units converted, by location, ascending.
	 */
	private function convert( string $holdGroup, int $variantId, int $quantity, array $rows ): array {
		$units = array();

		foreach ( $rows as $locationId => $held ) {
			$needed = min( $held, $quantity - array_sum( $units ) );

			if ( $needed < 1 ) {
				break;
			}

			if ( $this->stock->convertHold( $holdGroup, $variantId, $locationId, $needed, $held ) ) {
				$units[ $locationId ] = $needed;
			}
		}

		return $units;
	}

	/**
	 * Claims a variant's units afresh for an order whose hold of them is gone, where routing puts them, with units brought to the order's destination.
	 *
	 * Routing reads the active locations and what each has, inside the caller's unit of work at READ
	 * COMMITTED: each read sees the rows committed when it is sent, and every claim below decides
	 * again, under its item's lock, what it saw. Each claim reclaims the item's expired holds once
	 * when it finds too few units. Units are brought only to an active location: a destination the
	 * context names that is gone or deactivated cannot receive them, and the claim is refused as
	 * when nothing fits, naming it, so the caller ends the order the way a person then sees.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Where routing puts them.
	 *
	 * @throws CodedException `stock.insufficient` when the active locations do not have the units between them, the
	 *                        destination is gone or deactivated, or a claim still finds too few after the reclaim.
	 *
	 * @param int                $variantId The variant.
	 * @param int                $quantity  The units.
	 * @param RoutingContext     $context   The order's market, pickup location and destination.
	 * @param StockHoldExpired[] $expired   The events of the reclaims so far; this reclaim's are added.
	 * @return array<int, int> The units claimed, by location, ascending.
	 *
	 * @phpstan-param list<StockHoldExpired> $expired
	 */
	private function claimAfresh( int $variantId, int $quantity, RoutingContext $context, array &$expired ): array {
		$destination = $context->destinationLocationId ?? $this->locations->default()?->id;

		try {
			$plan = $this->routing->propose( array( $variantId => $quantity ), new RoutingContext( $context->marketId, $context->pickupLocationId, $destination ), FulfilmentMode::Transfer );
		} catch ( CodedException $refused ) {
			if ( ! in_array( $refused->errorCode(), array( InventoryError::RoutingRefused, InventoryError::LocationMissing, InventoryError::LocationInactive ), true ) ) {
				throw $refused;
			}

			CodedException::raise(
				InventoryError::Insufficient,
				array(
					'variant_id' => $variantId,
					'requested'  => $quantity,
					'available'  => (int) ( $refused->details()['available'] ?? 0 ),
				),
				array( 'location_id' => $destination )
			);
		}

		$claimed = array();

		foreach ( $plan->lines as $line ) {
			$locationId = (int) $line->locationId;

			if ( ! $this->stock->claimAllocation( $variantId, $locationId, $line->quantity ) ) {
				$expired = array_merge( $expired, $this->expiredEvents( $this->giveBack( $this->stock->reclaimExpired( $variantId, $locationId, $this->ids->generate() ), false ) ) );

				if ( ! $this->stock->claimAllocation( $variantId, $locationId, $line->quantity ) ) {
					$this->refuseClaim( $variantId, $locationId, $line->quantity );
				}
			}

			$claimed[ $locationId ] = $line->quantity;
		}

		return $claimed;
	}

	/**
	 * Raises the refusal of a claim, from one read of the item under its lock: an item missing at the location has no units there.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.insufficient`, always.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $requested  The units asked for.
	 * @return never
	 */
	private function refuseClaim( int $variantId, int $locationId, int $requested ): never {
		// A diagnostic read under the item's lock: it classifies the refusal, it decides nothing.
		$level = $this->stock->lockedLevel( $variantId, $locationId );

		CodedException::raise(
			InventoryError::Insufficient,
			array(
				'variant_id' => $variantId,
				'requested'  => $requested,
				'available'  => null === $level ? 0 : max( 0, $level->available() ),
			),
			array( 'location_id' => $locationId )
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
	 * @param ReclaimedRows $reclaimed   What the reclaim reports.
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
			static fn( array $row ): StockHoldExpired => new StockHoldExpired( $row['hold_group'], $reclaimed->variantId, $reclaimed->locationId, $row['quantity'], $row['expires_at'], $now ),
			$reclaimed->rows
		);
	}

	/**
	 * Adds a reclaim's rows to the units released, by hold and item.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<int, array<int, int>>> $released  Hold id => variant id => location id => units; the rows are added.
	 * @param ReclaimedRows                              $reclaimed The rows given back.
	 */
	private static function collect( array &$released, ReclaimedRows $reclaimed ): void {
		foreach ( $reclaimed->rows as $row ) {
			$released[ $row['hold_group'] ][ $reclaimed->variantId ][ $reclaimed->locationId ] = ( $released[ $row['hold_group'] ][ $reclaimed->variantId ][ $reclaimed->locationId ] ?? 0 ) + $row['quantity'];
		}
	}

	/**
	 * Publishes one release per hold.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<int, array<int, int>>> $released Hold id => variant id => location id => units, items ascending.
	 * @param string                                     $reason   Why.
	 * @return int The hold rows released: one per hold and item.
	 */
	private function publishReleases( array $released, string $reason ): int {
		$events = array();
		$rows   = 0;

		foreach ( $released as $holdGroup => $byVariant ) {
			$items = array();

			foreach ( $byVariant as $variantId => $byLocation ) {
				foreach ( $byLocation as $locationId => $units ) {
					$items[] = array( $variantId, $locationId, $units );
				}
			}

			list( $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities ) = StockLines::of( $items );

			$events[] = new StockReservationReleased( (string) $holdGroup, $reason, $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities, $this->clock->now() );
			$rows    += count( $items );
		}

		if ( array() !== $events ) {
			$this->events->publish( ...$events );
		}

		return $rows;
	}

	/**
	 * Writes off a deleted variant's on_hand at one location with its final ledger entry there.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @param int   $variantId  The item's variant.
	 * @param int   $locationId The item's location, locked.
	 * @param Actor $actor      On whose authority.
	 * @return StockAdjusted The event of the final entry, to publish.
	 */
	private function writeOff( int $variantId, int $locationId, Actor $actor ): StockAdjusted {
		$delta = -$this->lockedLevel( $variantId, $locationId )->onHand;

		if ( 0 !== $delta && ! $this->stock->adjust( $variantId, $locationId, $delta, null ) ) {
			// The item is locked and the result is exactly zero; only a failure outside this code can get here.
			CodedException::raise( InventoryError::ProjectionCorrupt, array( 'variant_id' => $variantId ) );
		}

		return $this->record( $this->lockedLevel( $variantId, $locationId ), $delta, LedgerReason::VariantDeleted, $actor )[1];
	}

	/**
	 * Appends the ledger entry of a change of on_hand, and builds its event: the variant's totals and the item's own level.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The variant's totals beside the item's level.
	 *
	 * @param StockLevel   $after  The level after the change, read under the lock, with the variant's totals.
	 * @param int          $delta  The change.
	 * @param LedgerReason $reason Why.
	 * @param Actor        $actor  On whose authority.
	 * @return array{0: Adjustment, 1: StockAdjusted} The adjustment and its event.
	 */
	private function record( StockLevel $after, int $delta, LedgerReason $reason, Actor $actor ): array {
		$actorType = null === $actor->systemName() ? 'user' : 'system';
		$actorId   = $actor->userId() > 0 ? $actor->userId() : null;
		$entryId   = $this->stock->appendLedger( $after->variantId, $after->locationId, $delta, $after->onHand, $reason, $actorType, $actorId, $this->correlation->current() );

		return array(
			new Adjustment( $after, $entryId ),
			new StockAdjusted(
				$after->variantId,
				$after->locationId,
				$delta,
				// A level read under the item's lock carries the variant's totals; the item's own figures stand in only for one that does not.
				$after->variantOnHand ?? $after->onHand,
				$after->variantAvailable ?? $after->available(),
				$after->onHand,
				$after->available(),
				$reason->value,
				$actorType,
				$actorId,
				$entryId,
				$this->clock->now()
			),
		);
	}

	/**
	 * Refuses an adjustment of a variant without a policy, or at a location that does not exist, keeping both from being deleted until the commit.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.item_missing` or `stock.location_missing`.
	 *
	 * @param int $variantId  The variant.
	 * @param int $locationId The location.
	 */
	private function requireAdjustable( int $variantId, int $locationId ): void {
		$found = $this->stock->lockAdjustable( $variantId, $locationId );

		if ( ! $found['policy'] ) {
			CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $variantId ) );
		}

		if ( ! $found['location'] ) {
			CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $locationId ) );
		}
	}

	/**
	 * Raises the error a refused adjustment deserves, from one read of the item under its lock.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException Always.
	 *
	 * @param int      $variantId      The item's variant.
	 * @param int      $locationId     The item's location.
	 * @param int      $delta          The change asked for.
	 * @param int|null $expectedOnHand The on_hand the caller expected, or null.
	 * @return never
	 */
	private function refuseAdjustment( int $variantId, int $locationId, int $delta, ?int $expectedOnHand ): never {
		$level = $this->lockedLevel( $variantId, $locationId );

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
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return StockLevel The level.
	 */
	private function lockedLevel( int $variantId, int $locationId ): StockLevel {
		$level = $this->stock->lockedLevel( $variantId, $locationId );

		if ( null === $level ) {
			CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $variantId ) );
		}

		return $level;
	}

	/**
	 * Returns the store's default location.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.no_fulfilling_location` when the store has none, which doctor reports.
	 *
	 * @return int The location's id.
	 */
	private function defaultLocation(): int {
		return $this->locations->default()->id ?? CodedException::raise( InventoryError::NoFulfillingLocation );
	}

	/**
	 * Returns the location an adjustment is at: the one named, or the store's default.
	 *
	 * A read of the directory, which adjust() then confirms under a lock by the location's id, so a
	 * location deleted since the directory was read is still refused.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_missing` when no location has the uuid; `stock.no_fulfilling_location` when none is named and the store has no default location.
	 *
	 * @param string|null $uuid The location's uuid, or null for the default location.
	 * @return Location The location.
	 */
	private function adjustedLocation( ?string $uuid ): Location {
		if ( null === $uuid ) {
			return $this->locations->default() ?? CodedException::raise( InventoryError::NoFulfillingLocation );
		}

		return $this->locations->findByUuid( $uuid ) ?? CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $uuid ) );
	}

	/**
	 * Tells whether a variant's units are promised: an allocation of it is open or in transit, or a line of it is on a transfer requested or in transit. Two locking reads.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return bool True when its delete is refused.
	 */
	private function isPromised( int $variantId ): bool {
		return $this->stock->hasOpenAllocation( $variantId ) || $this->stock->hasTransferInFlight( $variantId );
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
	 * Adds up the lines of each variant and location, a line with no location kept apart until it is resolved.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When there is no line, or a line is not a HoldLine.
	 *
	 * @param array<mixed> $lines The lines.
	 * @return list<HoldLine> One line per variant and location, or with no location.
	 */
	private static function normalise( array $lines ): array {
		if ( array() === $lines ) {
			throw new \InvalidArgumentException( 'A hold needs at least one line.' );
		}

		foreach ( $lines as $line ) {
			if ( ! $line instanceof HoldLine ) {
				throw new \InvalidArgumentException( sprintf( 'A hold line is a HoldLine; %s was given.', get_debug_type( $line ) ) );
			}
		}

		return self::merged( $lines, null );
	}

	/**
	 * Gives every line without a location the default location, then adds up the lines of each item, ascending by variant then location.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.no_fulfilling_location` when a line has no location and the store has no default one.
	 *
	 * @param HoldLine[] $lines             The lines.
	 * @param int|null   $defaultLocationId The default location, or null when the store has none.
	 * @return list<HoldLine> One line per item, ascending.
	 *
	 * @phpstan-param list<HoldLine> $lines
	 */
	private static function located( array $lines, ?int $defaultLocationId ): array {
		foreach ( $lines as $line ) {
			if ( null === $line->locationId && null === $defaultLocationId ) {
				CodedException::raise( InventoryError::NoFulfillingLocation );
			}
		}

		return self::merged( $lines, $defaultLocationId );
	}

	/**
	 * Adds up lines of the same variant and location, ascending by variant then location.
	 *
	 * @since 0.2.0
	 *
	 * @param HoldLine[] $lines             The lines.
	 * @param int|null   $defaultLocationId The location of a line that names none, or null to keep such lines apart.
	 * @return list<HoldLine> One line per variant and location.
	 */
	private static function merged( array $lines, ?int $defaultLocationId ): array {
		$quantities = array();

		foreach ( $lines as $line ) {
			$locationId = $line->locationId ?? $defaultLocationId ?? 0;

			$quantities[ $line->variantId ][ $locationId ] = ( $quantities[ $line->variantId ][ $locationId ] ?? 0 ) + $line->quantity;
		}

		ksort( $quantities );

		$merged = array();

		foreach ( $quantities as $variantId => $byLocation ) {
			ksort( $byLocation );

			foreach ( $byLocation as $locationId => $quantity ) {
				$merged[] = new HoldLine( $variantId, $quantity, 0 === $locationId ? null : $locationId );
			}
		}

		return $merged;
	}

	/**
	 * Arranges a hold's rows by variant, then location.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{variant_id: int, location_id: int, quantity: int}> $rows The rows.
	 * @return array<int, array<int, int>> The units, by variant then location.
	 */
	private static function byItem( array $rows ): array {
		$items = array();

		foreach ( $rows as $row ) {
			$items[ $row['variant_id'] ][ $row['location_id'] ] = $row['quantity'];
		}

		return $items;
	}

	/**
	 * Shares a variant's units out to its order lines, in the order given, location by location: one allocation per line and location.
	 *
	 * @since 0.2.0
	 *
	 * @param Allocation[]    $lines The order's lines of the variant.
	 * @param array<int, int> $units The units, by location, ascending; as many as the lines ask for.
	 * @return list<Allocation> The allocations.
	 *
	 * @phpstan-param list<Allocation> $lines
	 */
	private static function shareOut( array $lines, array $units ): array {
		$shares = array();

		foreach ( $lines as $line ) {
			$needed = $line->quantity;

			foreach ( $units as $locationId => $left ) {
				$taken = min( $needed, $left );

				if ( $taken < 1 ) {
					continue;
				}

				$shares[]             = new Allocation( $line->orderLineId, $line->variantId, $taken, $locationId );
				$units[ $locationId ] = $left - $taken;
				$needed              -= $taken;

				if ( 0 === $needed ) {
					break;
				}
			}
		}

		return $shares;
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
	 * Reads the variant ids a client listed: whole numbers above 0 separated by commas, at most LocationOperations::MOST_LISTED_VARIANTS, once each.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.variant_ids_invalid` when the text is not such a list, or lists more.
	 *
	 * @param string $text The list, such as `12,15,20`.
	 * @return list<int> The ids, unique and ascending.
	 */
	private static function listedVariants( string $text ): array {
		$ids = 1 === preg_match( self::VARIANT_LIST, $text ) ? array_map( 'intval', explode( ',', (string) preg_replace( '/\s+/', '', $text ) ) ) : array();

		if ( array() === $ids || count( array_unique( $ids ) ) > LocationOperations::MOST_LISTED_VARIANTS ) {
			CodedException::raise( InventoryError::VariantIdsInvalid, array( 'most' => LocationOperations::MOST_LISTED_VARIANTS ) );
		}

		return self::variantIds( $ids );
	}

	/**
	 * Checks items, and returns them once each, ascending by variant then location.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When an item is not a variant id and a location id, each an integer of 1 or more.
	 *
	 * @param array<mixed> $items The items.
	 * @return list<array{0: int, 1: int}> The items, unique and ascending.
	 */
	private static function items( array $items ): array {
		$unique = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item[0], $item[1] ) || ! is_int( $item[0] ) || ! is_int( $item[1] ) || $item[0] < 1 || $item[1] < 1 ) {
				throw new \InvalidArgumentException( 'An item is a variant id and a location id, each an integer of 1 or more.' );
			}

			$unique[ $item[0] ][ $item[1] ] = array( $item[0], $item[1] );
		}

		ksort( $unique );

		$sorted = array();

		foreach ( $unique as $byLocation ) {
			ksort( $byLocation );

			array_push( $sorted, ...array_values( $byLocation ) );
		}

		return $sorted;
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

	/**
	 * Tells whether an order holds an allocation of stock, in any state: its lines were allocated once already.
	 *
	 * For the settlement of a placement whose units may have been allocated before while its
	 * payment still waits, as when the payment path keeps for a person a capture the provider made
	 * while the shopper was still asked to act: allocating them a second time would fail on an order
	 * line's unique key, or promise the units twice where routing now puts them elsewhere. One plain
	 * read on the allocations' order key. Every allocation of an order is made under the order's
	 * lock, so a caller that holds it reads them all.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return bool True when it holds one.
	 */
	public function hasAllocationsFor( int $orderId ): bool {
		return $this->stock->hasAllocationsFor( $orderId );
	}
}

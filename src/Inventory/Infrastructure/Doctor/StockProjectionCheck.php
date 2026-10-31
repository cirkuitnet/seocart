<?php
/**
 * StockProjectionCheck: holds every stock item's counters to the rows they project, and the locations to what stock needs of them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure\Doctor;

use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\Location;
use SEOCart\Inventory\Domain\ReclaimedRows;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Inventory\Infrastructure\MysqlTransferRepository;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\DataRegistry\RetentionCatalog;
use SEOCart\Platform\Logging\LogRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Holds each stock item's counters to the rows they project, and the store's locations to what its stock needs; it repairs only what is structural.
 *
 * Owns one fact: when doctor calls stock inconsistent. An item is one variant at one location.
 * Its `held` must equal the sum of its hold rows, expired ones included; its on_hand must equal
 * the sum of its ledger and its newest entry's on_hand_after; its `allocated` must equal the
 * unposted units of its open allocations, an allocation travelling with a transfer counting at no
 * item. A difference is critical, named with the item and both figures, and left for a person:
 * these are financial projections, and doctor never rewrites one. A hold that expired longer ago
 * than the `stock_holds` retention period is a warning that the sweep is not keeping up. A
 * committed hold row that carries a reclaim token is a warning too: a reclaim claims rows only
 * inside its own transaction, so such a row was written some other way and is stuck until a
 * person clears it. An open allocation whose item is gone, a hold whose item is gone, an open
 * allocation, an item or a ledger entry at a location that is gone, and a store with no active
 * location that ships online orders are critical. A negative available is not a finding: an adjustment may count
 * fewer units than are promised.
 *
 * Three critical findings are structural, and `--repair` fixes them: a variant with stock but no
 * policy, or with a policy but no item at the default location, gets what it lacks at the
 * defaults, at zero, through StockService::createItems(); a store with no default location gets
 * its active location with the lowest id as the default; and a hold whose item is gone, at a
 * location that exists, which nothing else can give back because every reclaim begins with the
 * item's lock, has its item recreated and its hold given back, so the item ends at zero, through
 * StockService::releaseHoldsOfMissingItems(), which says why it does both. A deactivated location
 * that still has units available is a warning: they can be sold from nowhere. The default
 * location without an address yet is reported and does not fail the check: every store starts
 * so, and the address is the merchant's to enter.
 *
 * Units on the way between locations are on the transfer lines, in no item. Six transfer lines
 * hold them to their records, and report only: a line that received and lost more than it sent,
 * or a received transfer whose lines received and lost less, is critical; so is a sent transfer
 * whose units on the way, by its lines, are not what its ledger entries moved less what was
 * lost; an allocation in transit that no line of a transfer in transit carries with the same
 * units on the way, or the reverse; a transfer naming a location that is gone; and a received
 * transfer that lost an order's units whose order is not on hold, so the shortage was never acted
 * on, or was undone by a person. A transfer requested, or in transit, for longer than the
 * store's warning period is a warning: an order waits for a person, a parcel is late, or a
 * deactivation's move lost its job. The warning period is the store's setting and the order's
 * status the order module's, each read through a function the kernel gives, so the check reads
 * no other module's tables.
 *
 * It reads through the repositories' statements, and prints ids and counts, never anything else.
 *
 * @since 0.1.0
 */
final class StockProjectionCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'stock';

	/**
	 * The most items each line of the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The stock statements.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlStockRepository
	 */
	private MysqlStockRepository $stock;

	/**
	 * The location statements.
	 *
	 * @since 0.2.0
	 *
	 * @var MysqlLocationRepository
	 */
	private MysqlLocationRepository $locations;

	/**
	 * Which locations serve which purpose.
	 *
	 * @since 0.2.0
	 *
	 * @var LocationDirectory
	 */
	private LocationDirectory $directory;

	/**
	 * Gives a variant what its stock lacks, for the repair.
	 *
	 * @since 0.2.0
	 *
	 * @var StockService
	 */
	private StockService $service;

	/**
	 * Runs the repair's units of work.
	 *
	 * @since 0.2.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * The transfer statements.
	 *
	 * @since 0.2.0
	 *
	 * @var MysqlTransferRepository
	 */
	private MysqlTransferRepository $transfers;

	/**
	 * Returns the days a transfer may stay requested or in transit before it is reported.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): int
	 */
	private \Closure $warningDays;

	/**
	 * Returns an order's status, or null when there is no such order.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(int): ?string
	 */
	private \Closure $orderStatus;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The locations, what the repair needs, and the transfers.
	 *
	 * @param MysqlStockRepository    $stock        The stock statements.
	 * @param MysqlLocationRepository $locations    The location statements.
	 * @param LocationDirectory       $directory    Which locations serve which purpose.
	 * @param StockService            $service      Gives a variant what its stock lacks.
	 * @param TransactionManager      $transactions Runs the repair's units of work.
	 * @param MysqlTransferRepository $transfers    The transfer statements.
	 * @param \Closure                $warningDays  Returns the days a transfer may stay requested or in transit (int).
	 * @param \Closure                $orderStatus  Returns an order's status from its id (int), or null for no such order.
	 *
	 * @phpstan-param \Closure(): int           $warningDays
	 * @phpstan-param \Closure(int): ?string    $orderStatus
	 */
	public function __construct( MysqlStockRepository $stock, MysqlLocationRepository $locations, LocationDirectory $directory, StockService $service, TransactionManager $transactions, MysqlTransferRepository $transfers, \Closure $warningDays, \Closure $orderStatus ) {
		$this->stock        = $stock;
		$this->locations    = $locations;
		$this->directory    = $directory;
		$this->service      = $service;
		$this->transactions = $transactions;
		$this->transfers    = $transfers;
		$this->warningDays  = $warningDays;
		$this->orderStatus  = $orderStatus;
	}

	/**
	 * Returns how long an expired hold may stay before the sweep counts as behind: the `stock_holds` retention period.
	 *
	 * @since 0.1.0
	 *
	 * @return int The seconds.
	 */
	public static function toleranceSeconds(): int {
		return LogRetention::seconds( ( new RetentionCatalog() )->defaults( InventoryTables::HOLDS_RETENTION )['expired'] );
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `stock`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Compares every item's counters with their rows, and the locations with what stock needs.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every counter agrees, no hold is stale and the locations are whole.
	 */
	public function run(): CheckResult {
		$tolerance = self::toleranceSeconds();
		$findings  = array_merge( $this->drift(), $this->holdFindings( $tolerance ), $this->locationFindings(), $this->transferFindings() );
		$notes     = $this->notes();

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, sprintf( 'Every item\'s held, on hand and allocated agree with their rows, no hold expired more than %d hours ago, and the locations are whole.', intdiv( $tolerance, 3600 ) ), $notes );
		}

		return CheckResult::fail( self::NAME, sprintf( '%d stock %s found.', count( $findings ), 1 === count( $findings ) ? 'problem' : 'problems' ), array_merge( $findings, $notes ) );
	}

	/**
	 * Gives the store a default location when it has none, then the holds whose item is gone back, then every variant that lacks it its policy and its item at the default location.
	 *
	 * Each runs in a unit of work of its own, with the deadlock retry policy, and re-reads what it
	 * fixes: a default marked meanwhile, or an item, a policy or an item at the default location
	 * created meanwhile, is left as it is, and so are that item's holds.
	 *
	 * @since 0.2.0
	 *
	 * @return RepairResult What was changed.
	 */
	public function repair(): RepairResult {
		$changes = array();

		if ( null === $this->directory->default() ) {
			$marked = $this->transactions->transaction( fn(): ?int => $this->locations->markLowestActiveDefault(), RetryPolicy::deadlocks() );

			if ( null !== $marked ) {
				$changes[] = sprintf( 'made location %d the default location.', $marked );
			}
		}

		$missing = array();

		foreach ( $this->stock->orphanHolds( self::LIMIT ) as $hold ) {
			if ( null !== $this->directory->find( $hold['location_id'] ) ) {
				$missing[] = array( $hold['variant_id'], $hold['location_id'] );
			}
		}

		// What the recreations gave back, not what the read found: an item created meanwhile keeps its holds.
		foreach ( $this->service->releaseHoldsOfMissingItems( $missing ) as $restored ) {
			$changes[] = self::restoredChange( $restored );
		}

		$variants = array_values( array_unique( array_merge( $this->stock->itemsWithoutPolicy( self::LIMIT ), $this->stock->policiesWithoutDefaultItem( self::LIMIT ) ) ) );

		sort( $variants );

		if ( array() !== $variants && null !== $this->directory->default() ) {
			// What the upserts created, not what the reads found missing: another writer may have created it meanwhile.
			$created = $this->transactions->transaction( fn(): array => $this->service->createItems( $variants ), RetryPolicy::deadlocks() );

			foreach ( $created as $variantId ) {
				$changes[] = sprintf( 'variant %d has its stock policy and its item at the default location.', $variantId );
			}
		}

		return new RepairResult( self::NAME, $changes );
	}

	/**
	 * Lists the items whose counters differ from their rows.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> One critical finding per item and counter.
	 */
	private function drift(): array {
		$findings = array();

		foreach ( $this->stock->heldDrift( self::LIMIT ) as $item ) {
			$findings[] = sprintf( 'Critical: variant %1$d at location %2$d holds %3$d, but its hold rows add up to %4$d. Its units cannot be given back until a person corrects it.', $item['variant_id'], $item['location_id'], $item['held'], $item['rows_sum'] );
		}

		foreach ( $this->stock->onHandDrift( self::LIMIT ) as $item ) {
			$findings[] = sprintf(
				'Critical: variant %1$d at location %2$d has %3$d on hand, but its ledger adds up to %4$d and its newest entry says %5$s.',
				$item['variant_id'],
				$item['location_id'],
				$item['on_hand'],
				$item['ledger_sum'],
				null === $item['newest'] ? 'nothing' : (string) $item['newest']
			);
		}

		foreach ( $this->stock->allocatedDrift( self::LIMIT ) as $item ) {
			$findings[] = sprintf( 'Critical: variant %1$d at location %2$d has %3$d allocated, but its open allocations add up to %4$d.', $item['variant_id'], $item['location_id'], $item['allocated'], $item['open_sum'] );
		}

		foreach ( $this->stock->orphanAllocations( self::LIMIT ) as $allocation ) {
			$findings[] = sprintf( 'Critical: allocation %1$d of order %2$d is open for variant %3$d at location %4$d, whose stock item is gone.', $allocation['id'], $allocation['order_id'], $allocation['variant_id'], $allocation['location_id'] );
		}

		foreach ( $this->stock->orphanHolds( self::LIMIT ) as $hold ) {
			$findings[] = sprintf(
				null === $this->directory->find( $hold['location_id'] )
					? 'Critical: hold %1$s holds %2$d %3$s of variant %4$d at location %5$d, which no longer exists, so nothing can give %6$s back.'
					: 'Critical: hold %1$s holds %2$d %3$s of variant %4$d at location %5$d, whose stock item is gone, so nothing can give %6$s back. --repair recreates the item and gives the hold back.',
				$hold['hold_group'],
				$hold['quantity'],
				1 === $hold['quantity'] ? 'unit' : 'units',
				$hold['variant_id'],
				$hold['location_id'],
				1 === $hold['quantity'] ? 'it' : 'them'
			);
		}

		return $findings;
	}

	/**
	 * Says what the repair of a hold whose item was gone changed.
	 *
	 * @since 0.2.0
	 *
	 * @param ReclaimedRows $restored What was given back of the item recreated.
	 * @return string The change.
	 */
	private static function restoredChange( ReclaimedRows $restored ): string {
		if ( array() === $restored->rows ) {
			return sprintf( 'recreated the stock item of variant %1$d at location %2$d; its hold rows marked by a reclaim that never finished are left for a person.', $restored->variantId, $restored->locationId );
		}

		$given = array_map( static fn( array $row ): string => sprintf( 'hold %1$s (%2$d %3$s)', $row['hold_group'], $row['quantity'], 1 === $row['quantity'] ? 'unit' : 'units' ), $restored->rows );

		return sprintf( 'recreated the stock item of variant %1$d at location %2$d, at zero, and gave back %3$s.', $restored->variantId, $restored->locationId, implode( ', ', $given ) );
	}

	/**
	 * Lists the items with stale or stuck hold rows.
	 *
	 * @since 0.2.0
	 *
	 * @param int $tolerance How long an expired hold may stay, in seconds.
	 * @return list<string> One warning per item.
	 */
	private function holdFindings( int $tolerance ): array {
		$findings = array();

		foreach ( $this->stock->staleHolds( $tolerance, self::LIMIT ) as $item ) {
			$findings[] = sprintf(
				'Warning: variant %1$d at location %2$d has %3$d %4$s that expired more than %5$d hours ago, the oldest %6$d seconds ago: the sweep is not keeping up. Check that background jobs run: `wp seocart jobs status`.',
				$item['variant_id'],
				$item['location_id'],
				$item['stale_rows'],
				1 === $item['stale_rows'] ? 'hold' : 'holds',
				intdiv( $tolerance, 3600 ),
				$item['oldest_seconds']
			);
		}

		foreach ( $this->stock->tokenedHolds( self::LIMIT ) as $item ) {
			$findings[] = sprintf( 'Warning: variant %1$d at location %2$d has %3$d hold %4$s marked by a reclaim that never finished; nothing will reclaim %5$s until a person clears the mark.', $item['variant_id'], $item['location_id'], $item['tokened_rows'], 1 === $item['tokened_rows'] ? 'row' : 'rows', 1 === $item['tokened_rows'] ? 'it' : 'them' );
		}

		return $findings;
	}

	/**
	 * Lists what is wrong between the stock and the locations.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> One finding per problem.
	 */
	private function locationFindings(): array {
		$findings = array();

		foreach ( $this->stock->allocationsAtLostLocations( self::LIMIT ) as $allocation ) {
			$findings[] = sprintf( 'Critical: allocation %1$d of order %2$d is open for variant %3$d at location %4$d, which no longer exists.', $allocation['id'], $allocation['order_id'], $allocation['variant_id'], $allocation['location_id'] );
		}

		foreach ( $this->stock->itemsAtLostLocations( self::LIMIT ) as $item ) {
			$findings[] = sprintf( 'Critical: variant %1$d has a stock item at location %2$d, which no longer exists.', $item[0], $item[1] );
		}

		foreach ( $this->stock->ledgerAtLostLocations( self::LIMIT ) as $locationId ) {
			$findings[] = sprintf( 'Critical: the stock ledger has entries at location %d, which no longer exists, so the history of those units names nowhere.', $locationId );
		}

		foreach ( $this->stock->itemsWithoutPolicy( self::LIMIT ) as $variantId ) {
			$findings[] = sprintf( 'Critical: variant %d has stock but no stock policy. --repair gives it one at the defaults.', $variantId );
		}

		foreach ( $this->stock->policiesWithoutDefaultItem( self::LIMIT ) as $variantId ) {
			$findings[] = sprintf( 'Critical: variant %d has no stock item at the default location. --repair gives it one at zero.', $variantId );
		}

		if ( array() === $this->directory->fulfilling( null ) ) {
			$findings[] = 'Critical: no active location ships online orders, so nothing can be sold.';
		}

		if ( null === $this->directory->default() ) {
			$findings[] = 'Critical: the store has no default location. --repair makes the active location with the lowest id the default.';
		}

		$inactive = array_values( array_filter( $this->locations->all(), static fn( Location $location ): bool => ! $location->active ) );

		foreach ( $this->stock->locationUnits( array_map( static fn( Location $location ): int => $location->id, $inactive ) ) as $locationId => $units ) {
			if ( $units > 0 ) {
				$findings[] = sprintf( 'Warning: location %1$d is deactivated but has %2$d %3$s available, which no order can be routed to. Move them, or activate it again.', $locationId, $units, 1 === $units ? 'unit' : 'units' );
			}
		}

		return $findings;
	}

	/**
	 * Lists what is wrong between the transfers and the stock they move.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> One finding per problem.
	 */
	private function transferFindings(): array {
		$findings = array();

		foreach ( $this->transfers->linesOutOfBounds( self::LIMIT ) as $line ) {
			$findings[] = sprintf( 'Critical: line %1$d of transfer %2$d sends %3$d %4$s, of which %5$d were received and %6$d lost: more than it sent, or, on a transfer received, fewer.', $line['id'], $line['transfer_id'], $line['quantity'], 1 === $line['quantity'] ? 'unit' : 'units', $line['received_quantity'], $line['lost_quantity'] );
		}

		foreach ( $this->transfers->transitDrift( self::LIMIT ) as $transfer ) {
			$findings[] = sprintf( 'Critical: transfer %1$d has %2$d units on the way by its lines, but its ledger entries moved %3$d out and in, and %4$d were lost: units left a location and are neither on the way, arrived nor lost.', $transfer['id'], $transfer['on_the_way'], $transfer['moved'], $transfer['lost'] );
		}

		foreach ( $this->transfers->uncarriedAllocations( self::LIMIT ) as $allocation ) {
			$findings[] = sprintf( 'Critical: allocation %1$d of order %2$d is in transit with %3$d units, but the lines of transfers in transit carry %4$d of them.', $allocation['id'], $allocation['order_id'], $allocation['quantity'], $allocation['on_the_way'] );
		}

		foreach ( $this->transfers->strayCarriedLines( self::LIMIT ) as $line ) {
			$findings[] = sprintf( 'Critical: line %1$d of transfer %2$d has units on the way for allocation %3$d, which is not in transit.', $line['id'], $line['transfer_id'], $line['allocation_id'] );
		}

		$days = ( $this->warningDays )();

		foreach ( $this->transfers->overdue( $days, self::LIMIT ) as $transfer ) {
			$findings[] = 'requested' === $transfer['state']
				? sprintf( 'Warning: transfer %1$d was requested more than %2$d days ago and has not been sent: an order waits for it.', $transfer['id'], $days )
				: sprintf( 'Warning: transfer %1$d (%2$s) was sent more than %3$d days ago and is still in transit. Receive what arrived; a deactivation\'s move with nothing moved has lost its job: deactivate the location again with the same target to queue it.', $transfer['id'], $transfer['kind'], $days );
		}

		$lost = $this->transfers->lostLocations( self::LIMIT );

		foreach ( array(
			'from' => $lost['from'],
			'to'   => $lost['to'],
		) as $end => $locationIds ) {
			foreach ( $locationIds as $locationId ) {
				$findings[] = sprintf( 'Critical: transfers %1$s location %2$d, which no longer exists, so their stock movements name nowhere. A person must decide where those units are.', $end, $locationId );
			}
		}

		$short = 0;

		foreach ( $this->transfers->shortOrderTransfers( self::LIMIT * 10 ) as $transfer ) {
			$status = ( $this->orderStatus )( $transfer['order_id'] );

			if ( 'on_hold' !== $status && $short++ < self::LIMIT ) {
				$findings[] = sprintf( 'Critical: transfer %1$d lost units order %2$d waited for, but the order is %3$s, not on hold: the shortage was not acted on. Put the order on hold and decide what it ships.', $transfer['id'], $transfer['order_id'], $status ?? 'gone' );
			}
		}

		return $findings;
	}

	/**
	 * Lists what doctor reports without failing the check.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The notes.
	 */
	private function notes(): array {
		$default = $this->locations->defaultWithoutAddress();

		return null === $default ? array() : array( sprintf( 'Warning: the default location, %d, has no address yet. Shipping labels and pickup confirmations print it once it is entered.', $default ) );
	}
}

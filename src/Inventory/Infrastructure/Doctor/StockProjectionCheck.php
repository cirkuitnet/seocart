<?php
/**
 * StockProjectionCheck: every stock counter agrees with the rows it adds up
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure\Doctor;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\DataRegistry\RetentionCatalog;
use SEOCart\Platform\Logging\LogRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Holds each stock item's counters to the rows they project, and reports; it never repairs.
 *
 * Owns one fact: when doctor calls stock inconsistent. An item's `held` must equal the sum of
 * its hold rows, expired ones included; its on_hand must equal the sum of its ledger and its
 * newest entry's on_hand_after; its `allocated` must equal the unposted units of its open
 * allocations. A difference is critical, named with the item and both figures, and left for a
 * person: these are financial projections, and doctor never rewrites one. A hold that expired
 * longer ago than the `stock_holds` retention period is a warning that the sweep is not keeping
 * up. A committed hold row that carries a reclaim token is a warning too: a reclaim claims rows
 * only inside its own transaction, so such a row was written some other way and is stuck until a
 * person clears it. An open allocation whose item is gone is critical. A negative available is
 * not a finding: an adjustment may count fewer units than are promised.
 *
 * It reads the inventory tables only, through the repository's statements, and prints ids and
 * counts, never anything else.
 *
 * @since 0.1.0
 */
final class StockProjectionCheck implements Check {

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
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param MysqlStockRepository $stock The stock statements.
	 */
	public function __construct( MysqlStockRepository $stock ) {
		$this->stock = $stock;
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
	 * Compares every item's counters with their rows.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every counter agrees and no hold is stale.
	 */
	public function run(): CheckResult {
		$tolerance = self::toleranceSeconds();
		$findings  = array();

		foreach ( $this->stock->heldDrift( self::LIMIT ) as $item ) {
			$findings[] = sprintf( 'Critical: variant %1$d holds %2$d, but its hold rows add up to %3$d. Its units cannot be given back until a person corrects it.', $item['variant_id'], $item['held'], $item['rows_sum'] );
		}

		foreach ( $this->stock->onHandDrift( self::LIMIT ) as $item ) {
			$findings[] = sprintf(
				'Critical: variant %1$d has %2$d on hand, but its ledger adds up to %3$d and its newest entry says %4$s.',
				$item['variant_id'],
				$item['on_hand'],
				$item['ledger_sum'],
				null === $item['newest'] ? 'nothing' : (string) $item['newest']
			);
		}

		foreach ( $this->stock->allocatedDrift( self::LIMIT ) as $item ) {
			$findings[] = sprintf( 'Critical: variant %1$d has %2$d allocated, but its open allocations add up to %3$d.', $item['variant_id'], $item['allocated'], $item['open_sum'] );
		}

		foreach ( $this->stock->orphanAllocations( self::LIMIT ) as $allocation ) {
			$findings[] = sprintf( 'Critical: allocation %1$d of order %2$d is open for variant %3$d, whose stock item is gone.', $allocation['id'], $allocation['order_id'], $allocation['variant_id'] );
		}

		foreach ( $this->stock->staleHolds( $tolerance, self::LIMIT ) as $item ) {
			$findings[] = sprintf(
				'Warning: variant %1$d has %2$d %3$s that expired more than %4$d hours ago, the oldest %5$d seconds ago: the sweep is not keeping up. Check that background jobs run: `wp seocart jobs status`.',
				$item['variant_id'],
				$item['stale_rows'],
				1 === $item['stale_rows'] ? 'hold' : 'holds',
				intdiv( $tolerance, 3600 ),
				$item['oldest_seconds']
			);
		}

		foreach ( $this->stock->tokenedHolds( self::LIMIT ) as $item ) {
			$findings[] = sprintf( 'Warning: variant %1$d has %2$d hold %3$s marked by a reclaim that never finished; nothing will reclaim %4$s until a person clears the mark.', $item['variant_id'], $item['tokened_rows'], 1 === $item['tokened_rows'] ? 'row' : 'rows', 1 === $item['tokened_rows'] ? 'it' : 'them' );
		}

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, sprintf( 'Every item\'s held, on hand and allocated agree with their rows, and no hold expired more than %d hours ago.', intdiv( $tolerance, 3600 ) ) );
		}

		return CheckResult::fail( self::NAME, sprintf( '%d stock %s found.', count( $findings ), 1 === count( $findings ) ? 'problem' : 'problems' ), $findings );
	}
}

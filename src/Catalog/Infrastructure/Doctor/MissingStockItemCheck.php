<?php
/**
 * MissingStockItemCheck: variants with no stock item
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\TransactionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Reports and repairs variants with no `stock_items` row (doctor check 6).
 *
 * Owns one fact: which of Catalog's variants Inventory has not given a stock item. It walks every
 * page of Catalog's own `variants`, a scan page at a time, so a defect past the first page is
 * still found; it lists at most LIMIT ids and says how many more there are. The repair takes each
 * defective variant's product lock, then re-reads that product's variants with a locking read and
 * re-checks the item with StockService::levels() before calling StockService::createItems() in
 * the same transaction — a variant a concurrent write deleted, or one a concurrent write already
 * gave an item, is left alone, never handed a stock item on stale information.
 *
 * @since 0.1.0
 */
final class MissingStockItemCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'missing_stock_item';

	/**
	 * The most variants the check lists, however many pages it walks to find them.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The most variant ids read from a single page while walking the table.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PAGE = 500;

	/**
	 * The variants.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Tells which variants have a stock item, and creates one.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	private StockService $stock;

	/**
	 * Runs the unit of work a repair's re-check and createItems() must be called inside.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Takes a named lock, runs work while holding it and releases it: LockService::withLock().
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, int, int, callable): mixed
	 */
	private $withLock;

	/**
	 * The variant ids the last run() found, for repair() to act on.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $found = array();

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products     The variants.
	 * @param StockService       $stock        Tells which variants have a stock item, and creates one.
	 * @param TransactionManager $transactions Runs the unit of work a repair's re-check and createItems() must be called inside.
	 * @param callable           $withLock     Takes a named lock, runs work while holding it and releases it.
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed $withLock
	 */
	public function __construct( ProductRepository $products, StockService $stock, TransactionManager $transactions, callable $withLock ) {
		$this->products     = $products;
		$this->stock        = $stock;
		$this->transactions = $transactions;
		$this->withLock     = $withLock;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.missing_stock_item`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists variants with no stock item, walking every page of Catalog's variants to the end.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every variant has an item.
	 */
	public function run(): CheckResult {
		$found = array();
		$more  = 0;
		$after = 0;

		while ( true ) {
			$page = $this->products->variantIds( $after, self::PAGE );

			if ( array() === $page ) {
				break;
			}

			$hasItem = $this->stock->levels( $page );

			foreach ( $page as $id ) {
				if ( array_key_exists( $id, $hasItem ) ) {
					continue;
				}

				if ( count( $found ) < self::LIMIT ) {
					$found[] = $id;
				} else {
					++$more;
				}
			}

			$after = $page[ count( $page ) - 1 ];

			if ( count( $page ) < self::PAGE ) {
				break;
			}
		}

		$this->found = $found;

		if ( array() === $found ) {
			return CheckResult::pass( self::NAME, 'Every variant checked has a stock item.' );
		}

		$findings = array(
			sprintf(
				'Reported: variant%1$s %2$s%3$s %4$s no stock item. --repair gives each one at zero.',
				1 === count( $found ) ? '' : 's',
				implode( ', ', $found ),
				$more > 0 ? sprintf( ' and %d more', $more ) : '',
				1 === count( $found ) ? 'has' : 'have'
			),
		);

		return CheckResult::fail( self::NAME, sprintf( '%d variant%s with no stock item.', count( $found ), 1 === count( $found ) ? '' : 's' ), $findings );
	}

	/**
	 * Creates a stock item, at zero, for each variant run() found that is still there and still without one.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was created.
	 */
	public function repair(): RepairResult {
		if ( array() === $this->found ) {
			return new RepairResult( self::NAME );
		}

		$byProduct = array();

		foreach ( $this->found as $variantId ) {
			$product = $this->products->findByVariant( $variantId );

			if ( null !== $product ) {
				$byProduct[ (int) $product->id() ][] = $variantId;
			}
		}

		$changes = array();

		foreach ( $byProduct as $productId => $variantIds ) {
			foreach ( $this->createUnderLock( $productId, $variantIds ) as $line ) {
				$changes[] = $line;
			}
		}

		return new RepairResult( self::NAME, $changes );
	}

	/**
	 * Re-checks a product's defective variants under its lock, and creates a stock item for each
	 * still there and still without one, in the same transaction as the re-check.
	 *
	 * Locks the product row first, the order the save and delete paths both use, before locking
	 * its variants: reloadUnderLock() is called for that locking read alone, its own reload
	 * unused here.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $productId  The variants' product.
	 * @param array $variantIds The variants run() found for this product.
	 * @return list<string> One line per stock item created, or one line naming the lock as busy;
	 *                       empty when every variant had already been deleted or already had an item.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	private function createUnderLock( int $productId, array $variantIds ): array {
		try {
			return ( $this->withLock )(
				SaveProduct::LOCK_PREFIX . $productId,
				SaveProduct::LOCK_TTL_SECONDS,
				0,
				function () use ( $variantIds, $productId ): array {
					return $this->transactions->transaction(
						function () use ( $variantIds, $productId ): array {
							$this->products->reloadUnderLock( $productId );

							$stillThere = $this->products->lockVariants( $productId );
							$hasItem    = $this->stock->levels( $variantIds );
							$create     = array();
							$changes    = array();

							foreach ( $variantIds as $variantId ) {
								if ( in_array( $variantId, $stillThere, true ) && ! array_key_exists( $variantId, $hasItem ) ) {
									$create[]  = $variantId;
									$changes[] = sprintf( 'created a stock item at zero for variant %d.', $variantId );
								}
							}

							if ( array() !== $create ) {
								$this->stock->createItems( $create );
							}

							return $changes;
						}
					);
				}
			);
		} catch ( LockNotAcquired $busy ) {
			return array( sprintf( 'product %1$d: its lock is busy, skipped this run (variants %2$s).', $productId, implode( ', ', $variantIds ) ) );
		}
	}
}

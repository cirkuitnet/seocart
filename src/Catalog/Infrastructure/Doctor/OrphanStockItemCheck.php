<?php
/**
 * OrphanStockItemCheck: stock items whose variant is gone
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports stock items whose variant no longer exists: the reverse of a variant with no stock
 * item (doctor check 6).
 *
 * Owns one fact: which stock items outlived their variant. It walks every page of Inventory's own
 * stock items to the end, through StockService::itemVariantIds() — Catalog's one read of that
 * seam — and checks each variant id against its own `variants` table; nothing here reads an
 * Inventory table in SQL. It lists at most LIMIT ids and says how many more there are. Never
 * repaired: deleting the item would write a tombstone to the stock ledger, and every repair must
 * leave the stock ledger unchanged.
 *
 * @since 0.1.0
 */
final class OrphanStockItemCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'orphan_stock_item';

	/**
	 * The most items the check lists, however many pages it walks to find them.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The most variant ids read from a single page while walking Inventory's items.
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
	 * Pages the variant ids that have a stock item.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	private StockService $stock;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository $products The variants.
	 * @param StockService      $stock    Pages the variant ids that have a stock item.
	 */
	public function __construct( ProductRepository $products, StockService $stock ) {
		$this->products = $products;
		$this->stock    = $stock;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.orphan_stock_item`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists stock items whose variant is gone, walking every page of Inventory's items to the end.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every item checked still has a variant.
	 */
	public function run(): CheckResult {
		$orphan = array();
		$more   = 0;
		$after  = 0;

		while ( true ) {
			$page = $this->stock->itemVariantIds( $after, self::PAGE );

			if ( array() === $page ) {
				break;
			}

			$stillThere = $this->products->variantsExisting( $page );

			foreach ( $page as $variantId ) {
				if ( in_array( $variantId, $stillThere, true ) ) {
					continue;
				}

				if ( count( $orphan ) < self::LIMIT ) {
					$orphan[] = $variantId;
				} else {
					++$more;
				}
			}

			$after = $page[ count( $page ) - 1 ];

			if ( count( $page ) < self::PAGE ) {
				break;
			}
		}

		if ( array() === $orphan ) {
			return CheckResult::pass( self::NAME, 'Every stock item checked still has a variant.' );
		}

		$findings = array(
			sprintf(
				'Reported: stock item%1$s %2$s%3$s whose variant no longer exists. Never repaired: deleting it would write a tombstone to the stock ledger.',
				1 === count( $orphan ) ? '' : 's',
				implode( ', ', $orphan ),
				$more > 0 ? sprintf( ' and %d more', $more ) : ''
			),
		);

		return CheckResult::fail( self::NAME, sprintf( '%d stock item%s with no variant.', count( $orphan ), 1 === count( $orphan ) ? '' : 's' ), $findings );
	}
}

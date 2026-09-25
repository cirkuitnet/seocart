<?php
/**
 * CatalogChecks: the catalog's contribution to `wp seocart doctor`
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\Doctor\ProductSettler;
use SEOCart\Catalog\Application\Lifecycle\Reconciler;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the catalog's ten doctor checks, plus the reverse cross-module line (a stock item
 * whose variant is gone), in a fixed order.
 *
 * Owns one fact: which checks the catalog contributes to doctor, and what each one is built from.
 * `Modules` binds this once and passes checks() into Doctor's constructor, alongside every other
 * module's; nothing here is resolved before `wp seocart doctor` runs.
 *
 * @since 0.1.0
 */
final class CatalogChecks {

	/**
	 * The products.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Reads and writes stock.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	private StockService $stock;

	/**
	 * Binds an unbound post to a new product.
	 *
	 * @since 0.1.0
	 *
	 * @var Reconciler
	 */
	private Reconciler $reconciler;

	/**
	 * Re-settles one product, safely.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductSettler
	 */
	private ProductSettler $settler;

	/**
	 * Tells the time.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Runs the unit of work check 6's repair needs createItems() called inside.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): Currency
	 */
	private $baseCurrency;

	/**
	 * Takes a named lock, runs work while holding it and releases it: LockService::withLock().
	 * Checks 3 and 6's repairs take a binding's or a variant's product lock before re-checking the
	 * defect and writing.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, int, int, callable): mixed
	 */
	private $withLock;

	/**
	 * Creates the factory. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products     The products.
	 * @param StockService       $stock        Reads and writes stock.
	 * @param Reconciler         $reconciler   Binds an unbound post to a new product.
	 * @param ProductSettler     $settler      Re-settles one product, safely.
	 * @param Clock              $clock        Tells the time.
	 * @param TransactionManager $transactions Runs the unit of work checks 3 and 6's repairs need.
	 * @param callable           $baseCurrency Returns the store's base currency.
	 * @param callable           $withLock     Takes a named lock, runs work while holding it and releases it.
	 *
	 * @phpstan-param callable(): Currency $baseCurrency
	 * @phpstan-param callable(string, int, int, callable): mixed $withLock
	 */
	public function __construct( ProductRepository $products, StockService $stock, Reconciler $reconciler, ProductSettler $settler, Clock $clock, TransactionManager $transactions, callable $baseCurrency, callable $withLock ) {
		$this->products     = $products;
		$this->stock        = $stock;
		$this->reconciler   = $reconciler;
		$this->settler      = $settler;
		$this->clock        = $clock;
		$this->transactions = $transactions;
		$this->baseCurrency = $baseCurrency;
		$this->withLock     = $withLock;
	}

	/**
	 * Returns the catalog's checks, in a fixed order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Check> The checks.
	 */
	public function checks(): array {
		return array(
			new NoBindingCheck( $this->products ),
			new MissingSourceCheck( $this->products ),
			new DanglingBindingCheck( $this->products, $this->transactions, $this->withLock ),
			new UnboundPostCheck( $this->products, $this->reconciler ),
			new IncompleteMismatchCheck( $this->products, $this->baseCurrency, $this->settler ),
			new MissingStockItemCheck( $this->products, $this->stock, $this->transactions, $this->withLock ),
			new StuckUpdatingCheck( $this->products, $this->clock, $this->settler ),
			new OrphanCommerceRowCheck( $this->products ),
			new ForeignHooksCheck(),
			new IncompleteCountCheck( $this->products ),
			new OrphanStockItemCheck( $this->products, $this->stock ),
		);
	}
}

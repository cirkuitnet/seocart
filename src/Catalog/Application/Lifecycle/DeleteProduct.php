<?php
/**
 * DeleteProduct: removes a product, its commerce rows and its stock, in one transaction
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Lifecycle;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a product and everything that must not outlive it, or nothing at all.
 *
 * Owns one fact: the order of a product's deletion. In one transaction:
 *
 * - the product's row is locked first, as a save's window locks it, so a save in progress
 *   finishes before the delete reads the product, and none starts until it has committed;
 * - every read after it is a locking read, which sees the newest committed rows even when the
 *   caller's transaction read before the lock: the product, and then, as they are deleted, its
 *   variants of every generation with their SKUs;
 * - the catalog's rows go, children first: the variants' prices, the variants, the bindings and
 *   the product;
 * - then the inventory's, for exactly the variants deleted: StockService::deleteVariants()
 *   releases their holds, writes their stock off with a final ledger entry each, and deletes
 *   their stock items. Their ledger entries stay. A variant with an open allocation refuses the
 *   whole delete with `stock.delete_blocked`;
 * - ProductDeleted, with the SKUs of the variants deleted, is stored in the outbox.
 *
 * Catalog rows before inventory rows is the order every product write takes its locks in. Any
 * failure rolls the whole of it back, so a product is either deleted with its stock or left as it
 * was; the caller decides what a refusal means. preflight() asks, without writing, whether the
 * delete would be refused, for a caller that must refuse before anything is written.
 *
 * @since 0.1.0
 */
final class DeleteProduct {

	/**
	 * Locks, loads and deletes the product's rows.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Deletes the variants' stock.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	private StockService $stock;

	/**
	 * Runs the unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Stores ProductDeleted in the unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var EventPublisher
	 */
	private EventPublisher $events;

	/**
	 * Tells when the product was deleted.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Creates the service. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products     Locks, loads and deletes the product's rows.
	 * @param StockService       $stock        Deletes the variants' stock.
	 * @param TransactionManager $transactions Runs the unit of work.
	 * @param EventPublisher     $events       Stores ProductDeleted.
	 * @param Clock              $clock        Tells the time.
	 */
	public function __construct( ProductRepository $products, StockService $stock, TransactionManager $transactions, EventPublisher $events, Clock $clock ) {
		$this->products     = $products;
		$this->stock        = $stock;
		$this->transactions = $transactions;
		$this->events       = $events;
		$this->clock        = $clock;
	}

	/**
	 * Refuses, without writing anything, a delete that delete() would refuse for the product's stock: a variant with an open allocation.
	 *
	 * Runs in the caller's transaction, which has locked the product; the product's variants are
	 * read and locked, then their allocations, the catalog's rows before the inventory's.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 * @throws CodedException  `stock.delete_blocked` when a variant has an open allocation.
	 *
	 * @param int $productId The product, locked.
	 */
	public function preflight( int $productId ): void {
		$open = $this->stock->openAllocationVariants( $this->products->lockVariants( $productId ) );

		if ( array() !== $open ) {
			CodedException::raise( InventoryError::DeleteBlocked, array( 'variant_id' => $open[0] ) );
		}
	}

	/**
	 * Deletes a product, its variants, prices and bindings, and its variants' stock, in one transaction.
	 *
	 * Runs in the caller's transaction, or in its own.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `catalog.product_not_found` when there is no such product; `stock.delete_blocked`
	 *                        when a variant has an open allocation; `store.unavailable` while the schema is being
	 *                        updated; whatever else ended the transaction, after which nothing was deleted.
	 * @phpstan-throws \Throwable
	 *
	 * @param int   $productId The product.
	 * @param Actor $actor     On whose authority: the ledger's final entries name it.
	 */
	public function delete( int $productId, Actor $actor ): void {
		$this->transactions->transaction(
			function () use ( $productId, $actor ): void {
				$product = $this->products->lock( $productId );

				if ( null === $product ) {
					CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
				}

				$skus = $this->products->delete( $product );

				$this->stock->deleteVariants( array_keys( $skus ), $actor );

				$product->markDeleted( array_values( $skus ), $this->clock->now() );

				$this->events->publish( ...$product->releaseEvents() );
			},
			RetryPolicy::deadlocks()
		);
	}
}

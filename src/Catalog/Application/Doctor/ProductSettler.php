<?php
/**
 * ProductSettler: re-settles one product's marker, safely against a save that is running or just finished
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Doctor;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The one shape doctor's checks 5 and 7 repair with: reload under the product's lock, recompute
 * Product::settle() (the one rule), write only when the outcome differs, as a compare-and-set.
 *
 * Owns one fact: how a repair may change a product's marker without a save's own window. Holding
 * the product's named lock excludes a save that is still running, but not one that ran and
 * finished between a check's read and this repair's own: settle() is recomputed from a fresh,
 * locking reload, never from what the check found, so a product a save already fixed is left
 * alone. The write itself re-states the condition — the exact state, and for check 7 the exact
 * `updated_at`, the reload just read — so even a mistaken caller cannot mark a product on stale
 * information: ProductRepository::settleIfUnchanged() is one conditional `UPDATE`.
 *
 * A marker it changes is a change of the product, so it publishes ProductSaved, with
 * `generation_state` as the field changed, in the same transaction: a product it settles to
 * `complete` becomes listable, and its listeners, the stock summary among them, hear of it after
 * the commit. A product without a source post names no post for the event, and publishes none.
 *
 * @since 0.1.0
 * @since 0.2.0 Publishes ProductSaved.
 */
final class ProductSettler {

	/**
	 * The field a settle changes, as ProductSaved names it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MARKER_FIELD = 'generation_state';

	/**
	 * Stores and reloads products.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Tells whether the default variant has a stock item.
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
	 * Takes a named lock, runs work while holding it and releases it: LockService::withLock().
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, int, int, callable): mixed
	 */
	private $withLock;

	/**
	 * Publishes the event of a settled product.
	 *
	 * @since 0.2.0
	 *
	 * @var EventPublisher
	 */
	private EventPublisher $events;

	/**
	 * Tells the time the event records.
	 *
	 * @since 0.2.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Creates the settler. Does nothing else.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The event publisher and the clock.
	 *
	 * @param ProductRepository  $products     Stores and reloads products.
	 * @param StockService       $stock        Tells whether the default variant has a stock item.
	 * @param TransactionManager $transactions Runs the unit of work.
	 * @param callable           $withLock     Takes a named lock, runs work while holding it and releases it.
	 * @param EventPublisher     $events       Publishes the event of a settled product.
	 * @param Clock              $clock        Tells the time the event records.
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed $withLock
	 */
	public function __construct( ProductRepository $products, StockService $stock, TransactionManager $transactions, callable $withLock, EventPublisher $events, Clock $clock ) {
		$this->products     = $products;
		$this->stock        = $stock;
		$this->transactions = $transactions;
		$this->withLock     = $withLock;
		$this->events       = $events;
		$this->clock        = $clock;
	}

	/**
	 * Re-settles one product, under its lock, only while it still matches what the caller read.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $productId        The product.
	 * @param string|null $requireUpdatedAt Optional. When given, the repair also requires the
	 *                                      product to still have this exact `updated_at` (check
	 *                                      7's extra guard: a save that re-marked the product in
	 *                                      the meantime wins).
	 * @return string|null One line describing the change; null when there was nothing to do —
	 *                      already settled, changed since the read, or the lock was busy (in which
	 *                      case the line naming the skip is returned instead, never null).
	 */
	public function settle( int $productId, ?string $requireUpdatedAt = null ): ?string {
		try {
			return ( $this->withLock )(
				SaveProduct::LOCK_PREFIX . $productId,
				SaveProduct::LOCK_TTL_SECONDS,
				0,
				function () use ( $productId, $requireUpdatedAt ): ?string {
					return $this->transactions->transaction(
						function () use ( $productId, $requireUpdatedAt ): ?string {
							$reloaded = $this->products->reloadUnderLock( $productId );

							if ( null === $reloaded ) {
								return null;
							}

							$product = $reloaded['product'];
							$from    = $reloaded['state'];

							if ( null !== $requireUpdatedAt && $requireUpdatedAt !== $reloaded['updatedAt'] ) {
								return null;
							}

							$variantId      = $product->defaultVariant()?->id();
							$stockItemKnown = null !== $variantId && array_key_exists( $variantId, $this->stock->levels( array( $variantId ) ) );
							$to             = $product->settle( $stockItemKnown );

							if ( $to === $from ) {
								return null;
							}

							if ( ! $this->products->settleIfUnchanged( $productId, $from, $to, $requireUpdatedAt ) ) {
								return null;
							}

							if ( null !== $product->sourcePostId() ) {
								$product->markSaved( array( self::MARKER_FIELD ), $this->clock->now() );

								$this->events->publish( ...$product->releaseEvents() );
							}

							return sprintf( 'product %1$d: settled %2$s to %3$s', $productId, $from->value, $to->value );
						},
						RetryPolicy::deadlocks()
					);
				}
			);
		} catch ( LockNotAcquired $busy ) {
			return sprintf( 'product %1$d: its lock is busy, skipped this run.', $productId );
		}
	}
}

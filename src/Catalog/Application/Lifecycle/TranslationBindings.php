<?php
/**
 * TranslationBindings: links a product's posts in other languages, unlinks them, and moves its source post
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Lifecycle;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Changes which posts present a product: one per locale, one of them its source post.
 *
 * Owns one fact: the rules of a product's bindings. A post presents one product, and a product
 * has one post per locale. The source post, the one that controls the product's existence, is
 * recorded, never inferred from a site's default language, and it is never unlinked: another
 * post is made the source first, by one conditional statement that changes nothing unless the
 * source is still the post read, and only for an actor who may edit the source post that loses
 * its place. Every change begins by locking the rows it decides on, in the
 * one order every path that touches the catalog's rows takes: the products' rows in ascending
 * order of id, then the post's binding (ProductRepository::lockWithPost()). A binding changes only
 * under its product's lock, so what the change reads under those locks is what it changes.
 *
 * A post already bound to a product that holds nothing but that post, as a reconciled post's
 * product does, can be linked to another product: that product gives way, deleted in the same
 * transaction by DeleteProduct, which records ProductDeleted with no SKU. A listener may have
 * heard of it, since a first save without a SKU records ProductSaved and leaves no variant; one
 * that never did is told of a delete of a product it does not know, which events delivered at
 * least once already allow for. A post bound to any other product is refused. Linking a post to the product it presents already
 * moves its binding to the locale given.
 *
 * SKU, price and stock belong to the product, so whichever post a change comes through, the
 * product has one of each.
 *
 * @since 0.1.0
 */
final class TranslationBindings {

	/**
	 * Locks, reads and writes the bindings.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Runs each change as one unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Stores ProductBindingPromoted.
	 *
	 * @since 0.1.0
	 *
	 * @var EventPublisher
	 */
	private EventPublisher $events;

	/**
	 * Tells when a post is bound and when the source moved.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Deletes the product a post gives way to another with.
	 *
	 * @since 0.1.0
	 *
	 * @var DeleteProduct
	 */
	private DeleteProduct $delete;

	/**
	 * Decides whether an actor may move a product's source away from its source post.
	 *
	 * @since 0.1.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

	/**
	 * Creates the service. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products     Locks, reads and writes the bindings.
	 * @param TransactionManager $transactions Runs each change.
	 * @param EventPublisher     $events       Stores ProductBindingPromoted.
	 * @param Clock              $clock        Tells the time.
	 * @param DeleteProduct      $delete       Deletes the product a post gives way to another with.
	 * @param Authorizer         $authorizer   Decides whether an actor may move a product's source away from its source post.
	 */
	public function __construct( ProductRepository $products, TransactionManager $transactions, EventPublisher $events, Clock $clock, DeleteProduct $delete, Authorizer $authorizer ) {
		$this->products     = $products;
		$this->transactions = $transactions;
		$this->events       = $events;
		$this->clock        = $clock;
		$this->delete       = $delete;
		$this->authorizer   = $authorizer;
	}

	/**
	 * Links a post to a product as its post in a locale.
	 *
	 * Runs in the caller's transaction, or in its own.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `catalog.product_not_found`; `catalog.post_bound_elsewhere` when the post presents a
	 *                        product that holds more than it; `catalog.locale_taken` when the product has another
	 *                        post in the locale.
	 * @phpstan-throws \Throwable
	 *
	 * @param int    $productId The product.
	 * @param int    $postId    The post.
	 * @param Locale $locale    The post's locale.
	 */
	public function link( int $productId, int $postId, Locale $locale ): void {
		$this->transactions->transaction(
			function () use ( $productId, $postId, $locale ): void {
				$locked  = $this->products->lockWithPost( $postId, $productId );
				$current = self::presenting( $locked, $postId );
				$binding = $current?->bindingOf( $postId );

				if ( ! isset( $locked[ $productId ] ) ) {
					CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
				}

				if ( $productId === $current?->id() ) {
					if ( null !== $binding && ! $binding->locale()->equals( $locale ) ) {
						$this->products->moveBinding( $postId, $locale );
					}

					return;
				}

				if ( null !== $current ) {
					$this->giveWay( $current, $postId );
				}

				$this->products->addBinding( $productId, new ProductPostBinding( $postId, $locale, $this->clock->now() ) );
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Unlinks a post from the product it presents: its binding goes, the product and its other posts stay.
	 *
	 * Runs in the caller's transaction, or in its own. A post that presents no product is left as it is.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `catalog.source_binding_kept` when the post is the product's source post.
	 * @phpstan-throws \Throwable
	 *
	 * @param int $postId The post.
	 */
	public function unlink( int $postId ): void {
		$this->transactions->transaction(
			function () use ( $postId ): void {
				$product = $this->products->lockByPost( $postId );

				if ( null === $product ) {
					return;
				}

				if ( $postId === $product->sourcePostId() ) {
					CodedException::raise( CatalogError::SourceBindingKept, array( 'post_id' => $postId ) );
				}

				$this->products->removeBinding( (int) $product->id(), $postId );
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Makes one of a product's posts its source post, and records ProductBindingPromoted.
	 *
	 * Runs in the caller's transaction, or in its own. Promoting the source itself changes nothing.
	 *
	 * An actor with a user must be allowed to edit the source post that loses its place, else
	 * `authorization.denied`.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `catalog.product_not_found`; `catalog.promotion_conflict` when the post does not present
	 *                        the product, or the source changed meanwhile.
	 * @phpstan-throws \Throwable
	 *
	 * @param int   $productId The product.
	 * @param int   $postId    The post to make the source.
	 * @param Actor $actor     On whose authority.
	 */
	public function promote( int $productId, int $postId, Actor $actor ): void {
		$this->transactions->transaction(
			function () use ( $productId, $postId, $actor ): void {
				$product = $this->products->lockWithPost( $postId, $productId )[ $productId ] ?? null;

				if ( null === $product ) {
					CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
				}

				$from = $product->sourcePostId();

				if ( $postId === $from ) {
					return;
				}

				$this->mayMoveSourceFrom( (int) $from, $actor );
				$this->moveSource( $productId, (int) $from, $postId, $actor );
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Hands a product's source over from a post that is going, to its oldest remaining published post, or its oldest remaining one, and unlinks the post that is going.
	 *
	 * Runs inside the caller's transaction, which has locked the product: the post-delete path,
	 * which cannot be refused for want of a promotion, so it is reconciled instead. Fails with
	 * `catalog.promotion_conflict` when the post is no longer the source, or no other post
	 * presents the product.
	 *
	 * An actor with a user must be allowed to edit the source post that loses its place, else
	 * `authorization.denied`.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 * @phpstan-throws \LogicException|CodedException
	 *
	 * @param int   $productId The product.
	 * @param int   $postId    The source post that is going.
	 * @param Actor $actor     On whose authority.
	 */
	public function handOver( int $productId, int $postId, Actor $actor ): void {
		if ( 0 === $this->transactions->depth() ) {
			throw new \LogicException( 'A hand-over of the source post runs inside the transaction that locked the product.' );
		}

		$this->mayMoveSourceFrom( $postId, $actor );
		$this->moveSource( $productId, $postId, null, $actor );
		$this->products->removeBinding( $productId, $postId );
	}

	/**
	 * Refuses to move a product's source away from its source post for an actor who may not edit that post: `edit_post` on it.
	 *
	 * The source post decides the product's existence, and the commerce data of the product may be
	 * changed through whichever post is its source; moving the source to another post is therefore
	 * an edit of the product. A process with no user behind it, such as the cron run that empties
	 * the trash, is WordPress's own, and is not asked.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `authorization.denied` when the actor's user may not edit the source post.
	 *
	 * @param int   $sourcePostId The product's source post, locked with the product.
	 * @param Actor $actor        On whose authority the source moves.
	 */
	private function mayMoveSourceFrom( int $sourcePostId, Actor $actor ): void {
		if ( $actor->userId() < 1 ) {
			return;
		}

		$this->authorizer->authorize( $actor, ProductCapabilities::map()['edit_post'], $sourcePostId );
	}

	/**
	 * Moves the source with the one conditional statement, and records the move.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $productId The product.
	 * @param int      $from      The post that must be the source now.
	 * @param int|null $to        The post to make the source, or null for the oldest remaining one.
	 * @param Actor    $actor     On whose authority.
	 */
	private function moveSource( int $productId, int $from, ?int $to, Actor $actor ): void {
		if ( null === $this->products->promoteSource( $productId, $from, $to ) ) {
			CodedException::raise(
				CatalogError::PromotionConflict,
				array(
					'product_id' => $productId,
					'post_id'    => $to ?? $from,
				)
			);
		}

		// Read again under the lock the change holds: the product as the promotion left it.
		$product = $this->products->lock( $productId );

		if ( null === $product ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}

		$product->markPromoted( $from, null === $actor->systemName() ? 'user' : 'system', $actor->userId() > 0 ? $actor->userId() : null, $this->clock->now() );

		$this->events->publish( ...$product->releaseEvents() );
	}

	/**
	 * Deletes the product a post presents, so the post can present another, when that product holds nothing but the post: through DeleteProduct, which records ProductDeleted.
	 *
	 * A product that holds nothing has no variant, so no stock and no ledger entry for the actor to
	 * be named in; the delete runs on no one's authority.
	 *
	 * @since 0.1.0
	 *
	 * @param Product $product The product the post presents now, locked.
	 * @param int     $postId  The post.
	 */
	private function giveWay( Product $product, int $postId ): void {
		if ( ! $product->holdsNothing() ) {
			CodedException::raise(
				CatalogError::PostBoundElsewhere,
				array(
					'post_id'    => $postId,
					'product_id' => (int) $product->id(),
				)
			);
		}

		$this->delete->delete( (int) $product->id(), Actor::user( 0 ) );
	}

	/**
	 * Returns, among locked products, the one a post presents.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, Product> $locked The locked products.
	 * @param int                 $postId The post.
	 * @return Product|null The product, or null when the post presents none of them.
	 */
	private static function presenting( array $locked, int $postId ): ?Product {
		foreach ( $locked as $product ) {
			if ( null !== $product->bindingOf( $postId ) ) {
				return $product;
			}
		}

		return null;
	}
}

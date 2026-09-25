<?php
/**
 * Reconciler: gives a product post the plugin did not write an unsellable product of its own
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Lifecycle;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Clock;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Binds a product post that no product is bound to, to a new `incomplete` product.
 *
 * Owns one fact: what a product post written by a path the plugin does not own becomes. Such a
 * post, from `wp post create`, a generic duplicator, an import or another plugin's
 * wp_insert_post(), gets a product of its own, `incomplete`, without a variant, and bound to the
 * post as its source binding in the post's locale: unsellable, and never a sellable default. A
 * later save through the plugin completes it. Product::reconciled() builds that product; this
 * class stores it, the product row and the binding in one transaction.
 *
 * A post another writer binds at the same moment meets this one on the binding's keys: the post
 * can be bound once, and a product has one source post. The loser's duplicate key rolls its own
 * transaction back, and it answers that the post is bound.
 *
 * It decides nothing about which posts to reconcile, and reports nothing: its caller does both.
 *
 * @since 0.1.0
 */
final class Reconciler {

	/**
	 * Stores the product.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Runs the unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Tells the locale of the post being bound.
	 *
	 * @since 0.1.0
	 *
	 * @var PostLocales
	 */
	private PostLocales $locales;

	/**
	 * Tells when the post is bound.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Mints the product's UUID.
	 *
	 * @since 0.1.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Creates the reconciler. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products     Stores the product.
	 * @param TransactionManager $transactions Runs the unit of work.
	 * @param PostLocales        $locales      Tells the locale of the post being bound.
	 * @param Clock              $clock        Tells the time.
	 * @param IdGenerator        $ids          Mints UUIDs.
	 */
	public function __construct( ProductRepository $products, TransactionManager $transactions, PostLocales $locales, Clock $clock, IdGenerator $ids ) {
		$this->products     = $products;
		$this->transactions = $transactions;
		$this->locales      = $locales;
		$this->clock        = $clock;
		$this->ids          = $ids;
	}

	/**
	 * Binds a product post to a new `incomplete` product, unless it is bound already.
	 *
	 * Runs in the caller's transaction, or in its own. Whether the post is bound, and the post's
	 * own existence, type and status, are all read inside that same transaction, never trusted from
	 * before it opened: a caller may hold a post id from a scan made moments, or minutes, earlier,
	 * and by the time this runs the post can have been deleted, changed type, or gone back to
	 * `auto-draft` (never a product to reconcile). The type and status come from a locking read of
	 * the post's own row, never from WordPress's post cache, which a concurrent write can leave
	 * holding a post already gone from the table. `trash` stays eligible: the lifecycle still
	 * binds a newly trashed product post. Reading them here, not before, is what makes every
	 * caller — the lifecycle hook and doctor's repair alike — safe against that gap.
	 *
	 * @since 0.1.0
	 *
	 * @throws \Throwable What the transaction throws: `store.unavailable` while the schema is being
	 *                    updated, a database error. A post bound by another writer at the same
	 *                    moment is not an error.
	 *
	 * @param int $postId The product post.
	 * @return bool True when the post was bound now; false when it was bound already, another
	 *              writer bound it first, or the post no longer exists, isn't a product post, or is
	 *              `auto-draft`.
	 */
	public function reconcile( int $postId ): bool {
		try {
			return $this->transactions->transaction(
				function () use ( $postId ): bool {
					$post = $this->products->lockedPostTypeAndStatus( $postId );

					if ( null === $post || ProductCapabilities::AUTO_DRAFT === $post['status'] ) {
						return false;
					}

					if ( ProductCapabilities::POST_TYPE !== $post['type'] ) {
						return false;
					}

					if ( null !== $this->products->findByPost( $postId ) ) {
						return false;
					}

					$this->products->save( Product::reconciled( $this->ids->generate(), $postId, $this->locales->localeOf( $postId ) ?? $this->locales->siteLocale(), $this->clock->now() ) );

					return true;
				},
				RetryPolicy::deadlocks()
			);
		} catch ( DuplicateKey $raced ) {
			// Another writer bound the post, or made it another product's source, after it was read.
			return false;
		}
	}
}

<?php
/**
 * PostLifecycle: what happens to a product when its post is written, trashed or deleted by any path
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Lifecycle;

use SEOCart\Catalog\Application\PostGateway;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the catalog and the stock consistent with a product post, whichever path writes, trashes or deletes it.
 *
 * Owns one fact: what each change of a product post does to its product. The kernel calls it
 * from three WordPress hooks, on every request, because a post can change anywhere: in the
 * editor, from WP-CLI, in a cron run that empties the trash, or from another plugin.
 *
 * - A post is written (`wp_after_insert_post`). An auto-draft is left alone: the first real save
 *   binds it. A write the plugin made itself, which reaches the hook through
 *   PostGateway::fireAfterInsert(), is left alone too. Any other write of a post no product is
 *   bound to gives it an `incomplete` product (Reconciler), so a post from a path the plugin does
 *   not own is never sold by default. A write to a post that is bound goes to
 *   boundPostWrittenElsewhere(), which holds the one decision about such writes.
 * - A post goes to the trash (`transition_post_status`). The holds on every variant of its
 *   product are released, with the reason TRASH_REASON; the stock, the allocations and the
 *   product stay, and the product cannot be sold while its post is in the trash. Leaving the
 *   trash re-holds nothing: whether the product can be sold follows the status the post gets back.
 * - A post is deleted (`pre_delete_post`, the one hook that can refuse). When it is the source
 *   post of a product with no other binding, the product is deleted with it (DeleteProduct); if
 *   that fails, the post's delete is refused, and the post and every row stay as they were.
 *   A post no product is bound to is deleted by WordPress alone.
 *
 * The trash and the delete decide on the product as the last save that committed left it: their
 * first statement locks the post's binding and the product's row, which a save's window holds,
 * and every read after it is a locking read.
 *
 * None of it ever throws into the path that changed the post: a failure is reported, and the
 * post is left unbound, its holds left to expire, or its delete refused. On a site where the
 * plugin is loaded but not installed there are no tables to keep consistent, and nothing is
 * done or reported.
 *
 * @since 0.1.0
 */
final class PostLifecycle {

	/**
	 * The reason the holds of a trashed product's variants are released with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TRASH_REASON = 'post_trashed';

	/**
	 * The status of a post that is in the trash.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TRASH = 'trash';

	/**
	 * The status of the post WordPress creates when the editor opens for a new one.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const AUTO_DRAFT = 'auto-draft';

	/**
	 * Loads the product a post is bound to.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Binds a post the plugin did not write.
	 *
	 * @since 0.1.0
	 *
	 * @var Reconciler
	 */
	private Reconciler $reconciler;

	/**
	 * Deletes a product with its source post.
	 *
	 * @since 0.1.0
	 *
	 * @var DeleteProduct
	 */
	private DeleteProduct $delete;

	/**
	 * Releases a trashed product's holds.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	private StockService $stock;

	/**
	 * Runs the trash and delete work in one unit each.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Tells the plugin's own writes from every other.
	 *
	 * @since 0.1.0
	 *
	 * @var PostGateway
	 */
	private PostGateway $posts;

	/**
	 * Receives a machine code and context for anything reported rather than thrown.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * Whether writes to bound posts by other paths are reported: WP_DEBUG.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $debug;

	/**
	 * Whether such a write was reported in this request.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $writeReported = false;

	/**
	 * Creates the lifecycle. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products     Loads the product a post is bound to.
	 * @param Reconciler         $reconciler   Binds a post the plugin did not write.
	 * @param DeleteProduct      $delete       Deletes a product with its source post.
	 * @param StockService       $stock        Releases a trashed product's holds.
	 * @param TransactionManager $transactions Runs the trash and delete work.
	 * @param PostGateway        $posts        Tells the plugin's own writes from every other.
	 * @param callable           $report       Receives a machine code (string) and its context (array).
	 * @param bool               $debug        Optional. Whether to report writes to bound posts by other paths, as WP_DEBUG does. Default false.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public function __construct( ProductRepository $products, Reconciler $reconciler, DeleteProduct $delete, StockService $stock, TransactionManager $transactions, PostGateway $posts, callable $report, bool $debug = false ) {
		$this->products     = $products;
		$this->reconciler   = $reconciler;
		$this->delete       = $delete;
		$this->stock        = $stock;
		$this->transactions = $transactions;
		$this->posts        = $posts;
		$this->report       = $report;
		$this->debug        = $debug;
	}

	/**
	 * Handles a product post that was written: binds it when no product is, and otherwise hands the write to boundPostWrittenElsewhere().
	 *
	 * Called on `wp_after_insert_post`, which a write by another path fires inside its own call,
	 * and a write by the plugin fires after its transaction has committed, when the post is bound.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $postId The product post.
	 * @param string $status Its status.
	 * @param bool   $update Whether the write updated an existing post.
	 * @param string $was    Optional. The status it had before the write; empty for a new post. Default empty.
	 */
	public function postWritten( int $postId, string $status, bool $update, string $was = '' ): void {
		if ( self::AUTO_DRAFT === $status || $this->posts->isFiringAfterInsert( $postId ) ) {
			return;
		}

		try {
			if ( ! $this->reconciler->reconcile( $postId ) ) {
				$this->boundPostWrittenElsewhere( $postId, $update, $status, $was );
			}
		} catch ( \Throwable $failure ) {
			$this->failed( ReportCode::ReconcileFailed, $postId, $failure );
		}
	}

	/**
	 * Handles a product post's change of status: releases the holds of every variant of its product when it goes to the trash.
	 *
	 * Called on `transition_post_status`. Only the product's source post counts: a product is
	 * sold through its source binding. The variants are read with a locking read under the
	 * product's lock, so a variant a save committed while the trash waited is released too.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $postId The product post.
	 * @param string $now    The status it has now.
	 * @param string $was    The status it had.
	 */
	public function statusChanged( int $postId, string $now, string $was ): void {
		if ( self::TRASH !== $now || self::TRASH === $was ) {
			return;
		}

		try {
			$this->transactions->transaction(
				function () use ( $postId ): void {
					$product = $this->products->lockByPost( $postId );

					if ( null === $product || $postId !== $product->sourcePostId() ) {
						return;
					}

					$variants = $this->products->lockVariants( (int) $product->id() );

					if ( array() !== $variants ) {
						$this->stock->releaseVariants( $variants, self::TRASH_REASON );
					}
				},
				RetryPolicy::deadlocks()
			);
		} catch ( \Throwable $failure ) {
			$this->failed( ReportCode::ReleaseFailed, $postId, $failure );
		}
	}

	/**
	 * Handles the deletion of a product post, before WordPress deletes it: deletes its product with it, or refuses the delete.
	 *
	 * Called on `pre_delete_post`, after every earlier callback has let the delete go on. The
	 * post's product decides:
	 *
	 * - no product: nothing to do;
	 * - the post is another binding of the product: the product stays;
	 * - the post is the source post, and other bindings remain: the product stays;
	 * - the post is the product's source post and its only binding: the product is deleted.
	 *
	 * The two middle branches keep their place for products presented by several posts, one per
	 * language; while every product has one binding, no post reaches them. The product is read
	 * under the locks of the post's binding and the product's row, the transaction's first
	 * statement, with locking reads: a delete that waited for a save decides, and reports, on what
	 * the save committed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The product post.
	 * @param int $userId The user deleting it, 0 when none is logged in, such as in a cron run.
	 * @return bool|null Null to let WordPress delete the post; false to refuse the delete, which wp_delete_post() then returns.
	 */
	public function deleting( int $postId, int $userId ): ?bool {
		try {
			$this->transactions->transaction(
				function () use ( $postId, $userId ): void {
					$product = $this->products->lockByPost( $postId );

					if ( null === $product ) {
						return;
					}

					if ( $postId !== $product->sourcePostId() ) {
						// Another binding of the product, such as a translation's post: the product
						// stays, sold through its source post. Until products have more than one
						// binding, no post reaches this branch.
						return;
					}

					if ( count( $product->bindings() ) > 1 ) {
						// The source post of a product that other posts still present: one of them
						// becomes the source, and the product stays. Until products have more than
						// one binding, no post reaches this branch.
						return;
					}

					$this->delete->delete( (int) $product->id(), Actor::user( max( 0, $userId ) ) );
				},
				RetryPolicy::deadlocks()
			);
		} catch ( \Throwable $failure ) {
			if ( self::notInstalled( $failure ) ) {
				return null;
			}

			$this->failed( ReportCode::DeleteRefused, $postId, $failure );

			return false;
		}

		return null;
	}

	/**
	 * Decides what a write by another path does to a product post that is already bound: nothing to its product; under WP_DEBUG it is reported, once per request.
	 *
	 * A write that moves the post into the trash or out of it is not such a write, whichever path
	 * makes it, the plugin's own REST delete included: it is a step of the post's lifecycle, which
	 * statusChanged() handles, and it is neither reported nor, under the other reading below, able
	 * to mark the product `incomplete`. A trashed product keeps its rows whole, so it is sold again
	 * as it was once its post is published again.
	 *
	 * Every other such write comes from Quick Edit, bulk edit, `wp post update`, a restored
	 * revision, an autosave of a draft by its author, or another plugin. Two readings of what it
	 * means exist, and this method is the whole of the choice between them:
	 *
	 * - The product is left as it is (this method). Every one of those writes is the same
	 *   wp_update_post() of the post's own fields. None of them can write a commerce row, because
	 *   only the plugin's save writes those, and the one post field a sale depends on, the status,
	 *   is read by the sellability rule on every path, so the product follows it anyway. A revision
	 *   restore then restores editorial content only, an autosave never touches a commerce row, and
	 *   a title fixed in Quick Edit leaves a live product on sale.
	 * - The product is marked `incomplete`, as any product post the plugin did not write is: one
	 *   conditional statement on the product's marker in place of the report. The product then
	 *   cannot be sold until it is saved through the plugin again, after every such write,
	 *   including a revision restore and a draft's autosave.
	 *
	 * Taking the other reading is a change to this method alone, and to the tests that pin the
	 * first: a Quick Edit leaves a complete product on sale, and an autosave or a revision restore
	 * changes no catalog row.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $postId The product post.
	 * @param bool   $update Whether the write updated an existing post.
	 * @param string $status The status the write gave the post.
	 * @param string $was    The status the post had before the write; empty for a new post.
	 */
	private function boundPostWrittenElsewhere( int $postId, bool $update, string $status, string $was ): void {
		if ( self::TRASH === $status || self::TRASH === $was ) {
			// Into the trash or out of it: a lifecycle step, not an editorial write.
			return;
		}

		if ( ! $this->debug || $this->writeReported ) {
			return;
		}

		$this->writeReported = true;

		( $this->report )(
			ReportCode::ForeignPostWrite->value,
			array(
				'post_id' => $postId,
				'update'  => $update,
			)
		);
	}

	/**
	 * Reports what ended a lifecycle step, unless the plugin is not installed on the site.
	 *
	 * @since 0.1.0
	 *
	 * @param ReportCode $code    What failed.
	 * @param int        $postId  The product post.
	 * @param \Throwable $failure What ended it.
	 */
	private function failed( ReportCode $code, int $postId, \Throwable $failure ): void {
		if ( self::notInstalled( $failure ) ) {
			return;
		}

		( $this->report )(
			$code->value,
			array(
				'post_id' => $postId,
				'error'   => $failure instanceof CodedException ? (string) $failure->errorCode()->value : get_class( $failure ),
			)
		);
	}

	/**
	 * Tells whether a failure is the refusal of a site where the plugin is loaded but not installed.
	 *
	 * Such a site has none of the plugin's tables: no product to keep consistent, and no log to
	 * report to.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $failure The failure.
	 * @return bool True for `store.unavailable` because the site is not installed.
	 */
	private static function notInstalled( \Throwable $failure ): bool {
		return $failure instanceof CodedException
			&& KernelError::StoreUnavailable === $failure->errorCode()
			&& GateState::NotInstalled->value === ( $failure->context()['reason'] ?? null );
	}
}

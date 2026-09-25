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
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\Sellability as SellabilityRule;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Platform\Localization\TranslationWatcher;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the catalog and the stock consistent with a product post, whichever path writes, trashes or deletes it.
 *
 * Owns one fact: what each change of a product post does to its product. The kernel calls it
 * from three WordPress hooks on every request, and from `deleted_post` once a delete has gone on,
 * because a post can change anywhere: in the editor, from WP-CLI, in a cron run that empties the
 * trash, or from another plugin.
 *
 * - A post is written (`wp_after_insert_post`). An auto-draft is left alone: the first real save
 *   binds it. A write the plugin made itself, which reaches the hook through
 *   PostGateway::fireAfterInsert(), is left alone too. Any other write brings the post's bindings
 *   in step with its translation group (TranslationGroups): a post no product is bound to joins
 *   the product its group presents, or gets an `incomplete` product of its own (Reconciler), so a
 *   post from a path the plugin does not own is never sold by default. What such a write means
 *   for a product it was bound to already is decided by boundPostWrittenElsewhere().
 * - A multilingual plugin changes a post's language or translation group after the post's hooks,
 *   or without writing it: once a product post has changed in the request, PostLocales tells this
 *   class (translationsChanged()), and the group is reconciled again.
 * - A post goes to the trash (`transition_post_status`). The holds on every variant of its
 *   product are released, with the reason TRASH_REASON, when it is the product's source post; the
 *   stock, the allocations and the product stay, and the product cannot be sold while its post is
 *   in the trash. A translation's post in the trash releases nothing: the product is still sold
 *   through its source post, and in the translation's locale it is not published. Nor does the
 *   source post's trash while another of the product's posts is published: the holds belong to
 *   carts in that post's language. Leaving the
 *   trash re-holds nothing: whether the product can be sold follows the status the post gets back.
 * - A post is deleted, in two steps. A post that is not its product's source post takes only its
 *   binding with it. The source post of a product other posts still present hands the source
 *   over to the oldest remaining published one, or the oldest remaining one, recording
 *   ProductBindingPromoted: such a delete may come from a path that cannot be refused. The source
 *   post of a product with no other binding takes the product with it (DeleteProduct). A post no
 *   product is bound to is deleted by WordPress alone.
 *   - The check, deleting(), on `pre_delete_post`, the one hook that can refuse: under the
 *     product's lock, and writing nothing, it decides which of those the delete is and refuses
 *     what would fail: a hand-over by a user who may not edit the source post, since it is an
 *     edit of the product, and a product whose variant has an open allocation. A refusal refuses
 *     the post's delete, and the post and every row stay as they were.
 *   - The change, deleted(), on `deleted_post`, once WordPress has deleted the post: the
 *     product is read again under its lock and the delete decided again, since the delete of
 *     another of its posts can have run in between, leaving this post the product's only
 *     binding, or its source post; the writes are made in one unit of work. A binding's removal
 *     or a product's delete needs no authorization, and DeleteProduct refuses an open allocation
 *     itself; a hand-over is made only when the check decided on it, and so authorized it. A
 *     callback that refuses the delete after the check leaves everything as it was, since
 *     `deleted_post` then never comes.
 *
 * The delete's trade-off: until WordPress has deleted the post, the plugin cannot know it will,
 * so it changes nothing before; once WordPress has, the delete cannot be refused. A change that
 * fails then, because an allocation was opened, the post moved to another product, now takes a
 * hand-over the check did not authorize, or its binding went, between the check and the change,
 * or because the database failed past its retries, is rolled back and
 * reported at error level as `catalog.delete_incomplete`: the product stays, with its source post
 * gone, and doctor reports it for a person to settle. A product deleted meanwhile leaves nothing
 * to change, and nothing is reported. The check that refused on such a failure could lose a
 * product's rows to a later callback's refusal; this never loses data, and leaves a reported
 * orphan in that rare race.
 *
 * What the check decided is kept by site and post, since a post id names a post on one site
 * only: a callback that switches to another site of a network and deletes a post with the same
 * id there, between this delete's check and its change, gets a check and a change of its own.
 *
 * The trash and the delete decide on the product as the last write that committed left it: they
 * lock the product's row before anything else, as a save's window and every change of a binding
 * do, and every read under that lock is a locking read (ProductRepository::lockByPost()).
 *
 * None of it ever throws into the path that changed the post: a failure is reported, and the
 * post is left unbound, its holds left to expire, its delete refused, or its product left. On a site where the
 * plugin is loaded but not installed there are no tables to keep consistent, and nothing is
 * done or reported.
 *
 * @since 0.1.0
 */
final class PostLifecycle implements TranslationWatcher {

	/**
	 * The reason the holds of a trashed product's variants are released with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TRASH_REASON = 'post_trashed';

	/**
	 * A delete that takes only the post's binding: the post is not its product's source post.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UNLINK = 'unlink';

	/**
	 * A delete that hands the product's source over to another of its posts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HAND_OVER = 'hand_over';

	/**
	 * A delete that takes the product with its only post.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DELETE = 'delete';

	/**
	 * The status of a post that is in the trash.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TRASH = 'trash';

	/**
	 * Loads the product a post is bound to.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Brings a post's bindings in step with its translation group.
	 *
	 * @since 0.1.0
	 *
	 * @var TranslationGroups
	 */
	private TranslationGroups $groups;

	/**
	 * Unlinks a deleted translation and hands the source over from a deleted source post.
	 *
	 * @since 0.1.0
	 *
	 * @var TranslationBindings
	 */
	private TranslationBindings $bindings;

	/**
	 * Tells of the changes a multilingual plugin makes later.
	 *
	 * @since 0.1.0
	 *
	 * @var PostLocales
	 */
	private PostLocales $locales;

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
	 * The posts whose delete this request let go on, by key(): a change the multilingual plugin makes to one of them as it goes is not reconciled.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private array $leaving = array();

	/**
	 * The deletes the check let go on in this request, by key(): which delete it is, of which product, on whose authority.
	 *
	 * An entry is taken when `deleted_post` comes for its post on its site; one whose delete a
	 * later callback refused is never taken, and goes with the request.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{delete: string, product: int, actor: Actor}>
	 */
	private array $pending = array();

	/**
	 * Receives a machine code and context for a failure a person must settle, at error level.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $alarm;

	/**
	 * Creates the lifecycle. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository   $products     Loads the product a post is bound to.
	 * @param TranslationGroups   $groups       Brings a post's bindings in step with its translation group.
	 * @param TranslationBindings $bindings     Unlinks a deleted translation, and hands a deleted source post's place over.
	 * @param DeleteProduct       $delete       Deletes a product with its source post.
	 * @param StockService        $stock        Releases a trashed product's holds.
	 * @param TransactionManager  $transactions Runs each step in one unit of work.
	 * @param PostGateway         $posts        Tells the plugin's own writes from every other.
	 * @param PostLocales         $locales      Tells of the changes a multilingual plugin makes later.
	 * @param callable            $report       Receives a machine code (string) and its context (array).
	 * @param bool                $debug        Optional. Whether to report writes to bound posts by other paths, as WP_DEBUG does. Default false.
	 * @param callable|null       $alarm        Optional. Receives, at error level, a machine code (string) and its context (array)
	 *                                          for a failure a person must settle. Default null, the reporter.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 * @phpstan-param (callable(string, array<string, mixed>): void)|null $alarm
	 */
	public function __construct( ProductRepository $products, TranslationGroups $groups, TranslationBindings $bindings, DeleteProduct $delete, StockService $stock, TransactionManager $transactions, PostGateway $posts, PostLocales $locales, callable $report, bool $debug = false, ?callable $alarm = null ) {
		$this->products     = $products;
		$this->groups       = $groups;
		$this->bindings     = $bindings;
		$this->locales      = $locales;
		$this->delete       = $delete;
		$this->stock        = $stock;
		$this->transactions = $transactions;
		$this->posts        = $posts;
		$this->report       = $report;
		$this->debug        = $debug;
		$this->alarm        = $alarm ?? $report;
	}

	/**
	 * Handles a product post that was written: brings its bindings in step with its translation group, and hands a write to a bound post to boundPostWrittenElsewhere().
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
		if ( ProductCapabilities::AUTO_DRAFT === $status || $this->posts->isFiringAfterInsert( $postId ) ) {
			return;
		}

		$this->locales->watch( $this );

		try {
			if ( $this->reconcileGroup( $postId ) ) {
				$this->boundPostWrittenElsewhere( $postId, $update, $status, $was );
			}
		} catch ( \Throwable $failure ) {
			$this->failed( ReportCode::ReconcileFailed, $postId, $failure );
		}
	}

	/**
	 * Hears that a multilingual plugin changed a product post's language or translation group, and brings the bindings in step.
	 *
	 * A change made while a transaction is open belongs to a save in progress, which binds the post
	 * itself; the change is left to it. So is a change to a post being deleted: the multilingual
	 * plugin takes it out of its group as it goes, and it must not be given a product of its own.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The product post.
	 */
	public function translationsChanged( int $postId ): void {
		if ( 0 !== $this->transactions->depth() || isset( $this->leaving[ $this->key( $postId ) ] ) ) {
			return;
		}

		try {
			$this->reconcileGroup( $postId );
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

		$this->locales->watch( $this );

		try {
			$this->transactions->transaction(
				function () use ( $postId ): void {
					$product = $this->products->lockByPost( $postId );

					if ( null === $product || $postId !== $product->sourcePostId() || $this->soldThroughAnother( $product, $postId ) ) {
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
	 * Checks the deletion of a product post, before WordPress deletes it: decides what it does to the product, refuses it when that would fail, and writes nothing.
	 *
	 * Called on `pre_delete_post`, after every earlier callback has let the delete go on. The
	 * post's product decides:
	 *
	 * - no product: nothing to do;
	 * - the post is another binding of the product, such as a translation's post: its binding will
	 *   go, and the product stay in its other locales;
	 * - the post is the source post, and other bindings remain: the source will be handed over to
	 *   the oldest remaining published post, or the oldest remaining one, and the post's binding go;
	 *   refused unless the user may edit the post;
	 * - the post is the product's source post and its only binding: the product will be deleted;
	 *   refused while a variant has an open allocation.
	 *
	 * The product is read with ProductRepository::lockByPost(): its row locked first, then its
	 * bindings and its default variant, every read a locking read, in a unit of work that writes
	 * nothing. What was decided is kept for deleted(), which the caller hooks to `deleted_post`
	 * once a check has let a delete go on.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The product post.
	 * @param int $userId The user deleting it, 0 when none is logged in, such as in a cron run.
	 * @return bool|null Null to let WordPress delete the post; false to refuse the delete, which wp_delete_post() then returns.
	 */
	public function deleting( int $postId, int $userId ): ?bool {
		$this->locales->watch( $this );

		$key = $this->key( $postId );

		unset( $this->pending[ $key ] );

		$actor = Actor::user( max( 0, $userId ) );

		try {
			$decided = $this->transactions->transaction(
				fn(): ?array => $this->check( $postId, $actor ),
				RetryPolicy::deadlocks()
			);
		} catch ( \Throwable $failure ) {
			if ( self::notInstalled( $failure ) ) {
				return null;
			}

			$this->failed( ReportCode::DeleteRefused, $postId, $failure );

			return false;
		}

		if ( is_array( $decided ) ) {
			$this->pending[ $key ] = $decided;
		}

		$this->leaving[ $key ] = true;

		return null;
	}

	/**
	 * Makes, once WordPress has deleted a product post, the change its product now needs: the binding's removal, the hand-over, or the product's delete.
	 *
	 * Called on `deleted_post`, for any post of any type; only a post whose delete the check let go
	 * on in this request, on the current site, is acted on, once. The product is read again under
	 * its lock, and the delete decided again as the product stands now: the delete of another of
	 * its posts, made between this one's check and its change, can have left this post its only
	 * binding, so the product goes with it, or its source post no longer, so its binding alone
	 * goes. Neither needs the check's authorization. A hand-over is made only when the check
	 * decided on one, which it authorized; a post that presents another product now, or now needs
	 * a hand-over the check did not decide on, is a failure. A failure cannot refuse the delete
	 * any more: it is rolled back and reported at error level as `catalog.delete_incomplete`, and
	 * the product stays for doctor to report. So is a post whose binding went meanwhile while its
	 * product stays; a product deleted meanwhile leaves nothing to change, and nothing is reported.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post WordPress deleted.
	 */
	public function deleted( int $postId ): void {
		$key     = $this->key( $postId );
		$decided = $this->pending[ $key ] ?? null;

		unset( $this->pending[ $key ] );

		if ( null === $decided ) {
			return;
		}

		try {
			$this->transactions->transaction(
				function () use ( $postId, $decided ): void {
					$product = $this->products->lockByPost( $postId );

					if ( null === $product ) {
						if ( null === $this->products->find( $decided['product'] ) ) {
							// Another request deleted the product meanwhile: nothing is left to change.
							return;
						}

						// The post's binding went meanwhile, and its product stays.
						CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => $postId ) );
					}

					$delete = self::deleteOf( $product, $postId );

					// The check authorized the hand-over it decided on, and no other.
					if ( (int) $product->id() !== $decided['product'] || ( self::HAND_OVER === $delete && self::HAND_OVER !== $decided['delete'] ) ) {
						CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => $postId ) );
					}

					match ( $delete ) {
						self::UNLINK    => $this->bindings->unlink( $postId ),
						self::HAND_OVER => $this->bindings->handOver( $decided['product'], $postId, $decided['actor'] ),
						default         => $this->delete->delete( $decided['product'], $decided['actor'] ),
					};
				},
				RetryPolicy::deadlocks()
			);
		} catch ( \Throwable $failure ) {
			if ( self::notInstalled( $failure ) ) {
				return;
			}

			( $this->alarm )(
				ReportCode::DeleteIncomplete->value,
				array(
					'post_id'    => $postId,
					'product_id' => $decided['product'],
					'error'      => $failure instanceof CodedException ? (string) $failure->errorCode()->value : get_class( $failure ),
				)
			);
		}
	}

	/**
	 * Returns the key of a post on the current site, which the deletes this request let go on are kept by.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return string The site's id and the post's, as `site:post`.
	 */
	private function key( int $postId ): string {
		return $this->posts->site() . ':' . $postId;
	}

	/**
	 * Decides what the delete of a product post does, under the product's lock, and refuses it when that would fail; writes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `authorization.denied` for a hand-over the actor may not make; `stock.delete_blocked` for a
	 *                        product whose variant has an open allocation.
	 *
	 * @param int   $postId The product post.
	 * @param Actor $actor  On whose authority it is deleted.
	 * @return array{delete: string, product: int, actor: Actor}|null What the delete does, or null when the post presents no product.
	 */
	private function check( int $postId, Actor $actor ): ?array {
		$product = $this->products->lockByPost( $postId );

		if ( null === $product ) {
			return null;
		}

		$productId = (int) $product->id();
		$delete    = self::deleteOf( $product, $postId );

		if ( self::HAND_OVER === $delete ) {
			$this->bindings->authorizeHandOver( $postId, $actor );
		}

		if ( self::DELETE === $delete ) {
			$this->delete->preflight( $productId );
		}

		return array(
			'delete'  => $delete,
			'product' => $productId,
			'actor'   => $actor,
		);
	}

	/**
	 * Tells what the delete of one of a product's posts does to the product.
	 *
	 * @since 0.1.0
	 *
	 * @param Product $product The product, locked.
	 * @param int     $postId  One of its posts.
	 * @return string UNLINK, HAND_OVER or DELETE.
	 */
	private static function deleteOf( Product $product, int $postId ): string {
		if ( $postId !== $product->sourcePostId() ) {
			return self::UNLINK;
		}

		return count( $product->bindings() ) > 1 ? self::HAND_OVER : self::DELETE;
	}

	/**
	 * Decides what a write by another path does to a product post that is already bound: nothing to its product; under WP_DEBUG it is reported, once per request.
	 *
	 * A write that changes the post's status into the trash or out of it is not such a write,
	 * whichever path makes it, the plugin's own REST delete included: it is a step of the post's
	 * lifecycle, which statusChanged() handles, and it is neither reported nor, under the other
	 * reading below, able to mark the product `incomplete`. A trashed product keeps its rows whole,
	 * so it is sold again as it was once its post is published again. A write to a post that stays
	 * in the trash is an editorial write like any other.
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
		if ( $status !== $was && ( self::TRASH === $status || self::TRASH === $was ) ) {
			// The status changed into the trash or out of it: a lifecycle step, not an editorial write.
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
	 * Tells whether a product is still sold through another of its posts: one that is published, or private.
	 *
	 * The holds on its variants then belong to carts in that post's language, which the trash of
	 * one post must not empty.
	 *
	 * @since 0.1.0
	 *
	 * @param Product $product The product.
	 * @param int     $postId  The post that stops selling it.
	 * @return bool True when another of its posts sells it.
	 */
	private function soldThroughAnother( Product $product, int $postId ): bool {
		foreach ( $product->bindings() as $binding ) {
			if ( $binding->postId() !== $postId && in_array( $this->posts->statusOf( $binding->postId() ), SellabilityRule::SELLING_STATUSES, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Brings a post's bindings in step with its translation group, in one unit of work.
	 *
	 * The reconciliation opens that unit itself, so it can start it again when a binding of the
	 * group moves between its reading of the group and its locks.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The product post.
	 * @return bool True when the post presented a product before.
	 */
	private function reconcileGroup( int $postId ): bool {
		return $this->groups->reconcile( $postId );
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

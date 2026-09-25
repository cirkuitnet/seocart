<?php
/**
 * TranslationGroupCheck: bindings that disagree with the multilingual plugin's translation group
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\Lifecycle\TranslationGroups;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Reports and repairs `product_posts` bindings that disagree with the multilingual plugin's own translation group (doctor check 11).
 *
 * Owns one fact: which bindings the multilingual setup has moved on from. Every product post the
 * lifecycle writes, or doctor's own unbound-post repair binds, is reconciled with its group at
 * that moment; this check is the safety net for the gap that leaves — a multilingual plugin's
 * request that changes a post's language or its translation group without writing the post
 * itself, so no `wp_after_insert_post` fires and the binding is left as it was. Whether a bound
 * post disagrees is TranslationGroups::disagreement()'s decision alone, the same one
 * TranslationGroups::reconcile() makes when a repair asks it to fix the post: the check and the
 * repair can never drift apart, because only one of them decides.
 *
 * TranslationGroups::keepsGroups() says whether there is anything to check at all, decided once,
 * not post by post: false — always true under SiteLocale — means the check passes with nothing
 * to look at. With a real multilingual setup, it walks every bound post, a scan page at a time,
 * so a defect past the first page is still found. Both run() and the repair drop this request's
 * cached reads of the multilingual plugin's own state first, with `wp_cache_flush_runtime()`:
 * a multilingual plugin's PHP object caches a post's language and group across calls in the same
 * process, so a second pass in the run that just repaired, or a repair that runs after another
 * process changed the group, must read it fresh, not the copy this process cached earlier.
 *
 * The repair takes the binding's product lock, the order every product-level repair does, then,
 * in the same transaction, re-reads the post's binding: only when it still names the product the
 * scan saw, and disagreement() still finds it wrong, does it run TranslationGroups::reconcile()
 * for the post — the one rule a first save in another language and this repair both go through.
 * A concurrent write that moved the binding, or deleted its post's product or the binding itself,
 * leaves the repair with nothing to do, reported, never a vanished product's or binding's post
 * sent down reconcile()'s unbound branch to be bound elsewhere: that is doctor's dangling-binding
 * and unbound-post checks' own concern. A post whose product holds commerce data is never moved
 * onto another product — reconcile() already refuses that, reporting it as a conflict for a
 * person — so it disagrees again on doctor's next run, left for the next repair or a person's
 * decision, never forced.
 *
 * @since 0.1.0
 */
final class TranslationGroupCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'translation_group';

	/**
	 * The most bindings the check lists, however many pages it walks to find them.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The most bindings read from a single page while walking the table.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PAGE = 500;

	/**
	 * The products, and the bindings.
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
	 * Runs the unit of work the repair's reconcile() must be called inside.
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
	 * The bindings the last run() found, with why each disagreed, for repair() to act on.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{post_id: int, product_id: int, locale: string, reason: string}>
	 */
	private array $found = array();

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products     The products, and the bindings.
	 * @param TranslationGroups  $groups       Brings a post's bindings in step with its translation group.
	 * @param TransactionManager $transactions Runs the unit of work the repair's reconcile() must be called inside.
	 * @param callable           $withLock     Takes a named lock, runs work while holding it and releases it.
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed $withLock
	 */
	public function __construct( ProductRepository $products, TranslationGroups $groups, TransactionManager $transactions, callable $withLock ) {
		$this->products     = $products;
		$this->groups       = $groups;
		$this->transactions = $transactions;
		$this->withLock     = $withLock;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.translation_group`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists bindings that disagree with the multilingual plugin's translation group, walking every page of bound posts to the end.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every binding agrees.
	 */
	public function run(): CheckResult {
		if ( ! $this->groups->keepsGroups() ) {
			// Decided from the port, once: a port that keeps no translation group at all, as
			// SiteLocale never does, leaves no post able to disagree with one.
			$this->found = array();

			return CheckResult::pass( self::NAME, 'The locale port keeps no translation groups, so there is nothing to check.' );
		}

		// This process may hold a multilingual plugin's own cached reads of an earlier pass, or
		// of a post this run already reconciled: drop them, so this pass judges every binding
		// against the group as the plugin now keeps it, not as it cached it.
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		$found = array();
		$more  = 0;
		$after = 0;

		while ( true ) {
			$page = $this->products->boundPostBindings( $after, self::PAGE );

			if ( array() === $page ) {
				break;
			}

			foreach ( $page as $binding ) {
				$reason = $this->groups->disagreement( $binding['post_id'] );

				if ( null === $reason ) {
					continue;
				}

				if ( count( $found ) < self::LIMIT ) {
					$found[] = $binding + array( 'reason' => $reason );
				} else {
					++$more;
				}
			}

			$after = $page[ count( $page ) - 1 ]['post_id'];

			if ( count( $page ) < self::PAGE ) {
				break;
			}
		}

		$this->found = $found;

		if ( array() === $found ) {
			return CheckResult::pass( self::NAME, 'Every bound post agrees with the multilingual plugin\'s translation group.' );
		}

		$total = count( $found ) + $more;

		$findings = array_map(
			static fn( array $row ): string => sprintf( 'Reported: post %1$d: %2$s.', $row['post_id'], $row['reason'] ),
			$found
		);

		if ( $more > 0 ) {
			$findings[] = '...and more.';
		}

		$findings[] = sprintf(
			'--repair reconciles each with its group; one it cannot move is left for a person: see the %s report.',
			ReportCode::TranslationConflict->value
		);

		return CheckResult::fail( self::NAME, sprintf( '%d binding%s out of step with the translation group.', $total, 1 === $total ? '' : 's' ), $findings );
	}

	/**
	 * Reconciles each binding run() found with its translation group, under its product's lock.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was changed, or left as a conflict.
	 */
	public function repair(): RepairResult {
		$changes = array();

		foreach ( $this->found as $row ) {
			$changes[] = $this->reconcileUnderLock( $row['post_id'], $row['product_id'] );
		}

		return new RepairResult( self::NAME, $changes );
	}

	/**
	 * Locks a binding's product, then, in the same transaction, re-reads the post's binding and
	 * runs TranslationGroups::reconcile() for it only while the binding still names that product
	 * and disagreement() still finds it wrong.
	 *
	 * A concurrent write can have moved the binding onto another product, deleted its post's
	 * product, or deleted the binding itself, between run()'s scan and the lock; any of those
	 * makes the re-read fail to match, and the repair does nothing rather than send the post down
	 * reconcile()'s branch for a post no product is bound to, which could bind it elsewhere. A
	 * post reconcile() leaves as a conflict, its product holding commerce data, re-reads as
	 * matching and wrong exactly as before, so it is reconciled again, harmlessly: reconcile() is
	 * idempotent, and the conflict is reported once more, not repaired.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId    The post.
	 * @param int $productId The product the binding named when run() found it.
	 * @return string One line naming the reconciliation, that the binding changed since the check,
	 *                or that the lock was busy.
	 */
	private function reconcileUnderLock( int $postId, int $productId ): string {
		try {
			return ( $this->withLock )(
				SaveProduct::LOCK_PREFIX . $productId,
				SaveProduct::LOCK_TTL_SECONDS,
				0,
				function () use ( $postId, $productId ): string {
					return $this->transactions->transaction(
						function () use ( $postId, $productId ): string {
							// Under the lock, before the re-check: drop this process's cached
							// reads of the multilingual plugin's own state, so the re-check below
							// sees whatever another process changed since run() scanned, not a
							// copy cached before it.
							if ( function_exists( 'wp_cache_flush_runtime' ) ) {
								wp_cache_flush_runtime();
							}

							$now = $this->products->findByPost( $postId );

							if ( null === $now || (int) $now->id() !== $productId || null === $this->groups->disagreement( $postId ) ) {
								return sprintf( 'post %d: its binding changed since the check, skipped this run.', $postId );
							}

							$this->groups->reconcile( $postId );

							return sprintf( 'reconciled post %d\'s binding with its translation group.', $postId );
						},
						RetryPolicy::deadlocks()
					);
				}
			);
		} catch ( LockNotAcquired $busy ) {
			return sprintf( 'post %d: its lock is busy, skipped this run.', $postId );
		}
	}
}

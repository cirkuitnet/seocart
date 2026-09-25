<?php
/**
 * DanglingBindingCheck: bindings whose post no longer exists
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\TransactionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Reports and repairs `product_posts` rows whose post no longer exists (doctor check 3).
 *
 * Owns one fact: which non-source bindings are dangling. The row named by its product's
 * `source_post_id` is excluded: that one is check 2's, for a person to decide, never deleted
 * here. The repair takes the binding's product lock first — the order the save and delete paths
 * both use — then, in the same transaction, re-states both conditions in the delete itself: the
 * post is still missing, and the row is still not the source binding. That order and that
 * re-statement together mean the delete cannot remove a binding a concurrent write turned into
 * the source, or one whose post came back, however briefly, between the scan and the repair.
 *
 * @since 0.1.0
 */
final class DanglingBindingCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'dangling_binding';

	/**
	 * The most bindings the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The products.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Runs the unit of work the repair's re-check and delete must be called inside.
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
	 * The bindings the last run() found, for repair() to act on.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{post_id: int, product_id: int}>
	 */
	private array $found = array();

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products     The products.
	 * @param TransactionManager $transactions Runs the unit of work the repair's re-check and delete must be called inside.
	 * @param callable           $withLock     Takes a named lock, runs work while holding it and releases it.
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed $withLock
	 */
	public function __construct( ProductRepository $products, TransactionManager $transactions, callable $withLock ) {
		$this->products     = $products;
		$this->transactions = $transactions;
		$this->withLock     = $withLock;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.dangling_binding`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists bindings whose post is gone, the source binding excluded.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when there is none.
	 */
	public function run(): CheckResult {
		$rows        = $this->products->danglingBindings( self::LIMIT + 1 );
		$more        = count( $rows ) > self::LIMIT;
		$rows        = array_slice( $rows, 0, self::LIMIT );
		$this->found = $rows;

		if ( array() === $rows ) {
			return CheckResult::pass( self::NAME, 'Every non-source binding still has its post and its product.' );
		}

		$findings = array_map(
			static fn( array $row ): string => sprintf( 'Reported: the binding of post %1$d to product %2$d, whose post or product no longer exists. --repair deletes it.', $row['post_id'], $row['product_id'] ),
			$rows
		);

		if ( $more ) {
			$findings[] = '...and more.';
		}

		return CheckResult::fail( self::NAME, sprintf( '%d dangling binding%s found.', count( $rows ), 1 === count( $rows ) ? '' : 's' ), $findings );
	}

	/**
	 * Deletes each dangling binding run() found, its product locked first, both conditions re-stated in the delete itself.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was deleted.
	 */
	public function repair(): RepairResult {
		$changes = array();

		foreach ( $this->found as $binding ) {
			$line = $this->deleteUnderLock( $binding['post_id'], $binding['product_id'] );

			if ( null !== $line ) {
				$changes[] = $line;
			}
		}

		return new RepairResult( self::NAME, $changes );
	}

	/**
	 * Locks a binding's product first, then deletes the binding in the same transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId    The binding's post.
	 * @param int $productId The binding's product.
	 * @return string|null One line naming the deletion, or that the lock was busy; null when the
	 *                      delete's re-stated conditions no longer matched.
	 */
	private function deleteUnderLock( int $postId, int $productId ): ?string {
		try {
			return ( $this->withLock )(
				SaveProduct::LOCK_PREFIX . $productId,
				SaveProduct::LOCK_TTL_SECONDS,
				0,
				function () use ( $postId, $productId ): ?string {
					return $this->transactions->transaction(
						function () use ( $postId, $productId ): ?string {
							$this->products->reloadUnderLock( $productId );

							if ( ! $this->products->deleteDanglingBinding( $postId, $productId ) ) {
								return null;
							}

							return sprintf( 'deleted the binding of post %1$d to product %2$d.', $postId, $productId );
						}
					);
				}
			);
		} catch ( LockNotAcquired $busy ) {
			return sprintf( 'the binding of post %1$d to product %2$d: its lock is busy, skipped this run.', $postId, $productId );
		}
	}
}

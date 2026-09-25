<?php
/**
 * UnboundPostCheck: product posts no product is bound to
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\Lifecycle\Reconciler;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports and repairs product posts (not `auto-draft`) with no `product_posts` row (doctor check 4).
 *
 * Owns one fact: which product posts the reconciler never reached. A post written by a path that
 * fires `wp_after_insert_post` is reconciled inline, the moment it is written; this check exists
 * for the one path that escapes it — `wp_insert_post( …, false )`, which never fires that hook.
 * The repair runs the reconciler's own create branch on each: the merged Reconciler, never a copy,
 * so a post another writer bound in the meantime is left alone (its duplicate key is swallowed).
 *
 * @since 0.1.0
 */
final class UnboundPostCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'unbound_post';

	/**
	 * The most posts the check lists.
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
	 * Binds a post to a new product.
	 *
	 * @since 0.1.0
	 *
	 * @var Reconciler
	 */
	private Reconciler $reconciler;

	/**
	 * The post ids the last run() found, for repair() to act on.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $found = array();

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository $products   The products.
	 * @param Reconciler        $reconciler Binds a post to a new product.
	 */
	public function __construct( ProductRepository $products, Reconciler $reconciler ) {
		$this->products   = $products;
		$this->reconciler = $reconciler;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.unbound_post`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists product posts with no binding.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every product post is bound.
	 */
	public function run(): CheckResult {
		$ids         = $this->products->unboundPostIds( 0, self::LIMIT + 1 );
		$more        = count( $ids ) > self::LIMIT;
		$ids         = array_slice( $ids, 0, self::LIMIT );
		$this->found = $ids;

		if ( array() === $ids ) {
			return CheckResult::pass( self::NAME, 'Every product post is bound.' );
		}

		$findings = array(
			sprintf(
				'Reported: post%1$s %2$s%3$s a product post with no product bound to it: a path that did not fire `wp_after_insert_post`. --repair binds each to a new, incomplete product.',
				1 === count( $ids ) ? ' is' : 's are',
				implode( ', ', $ids ),
				$more ? ' and more' : ''
			),
		);

		return CheckResult::fail( self::NAME, sprintf( '%d unbound product post%s found.', count( $ids ), 1 === count( $ids ) ? '' : 's' ), $findings );
	}

	/**
	 * Binds each post run() found through the reconciler's create branch.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was bound.
	 */
	public function repair(): RepairResult {
		$changes = array();

		foreach ( $this->found as $postId ) {
			if ( $this->reconciler->reconcile( $postId ) ) {
				$changes[] = sprintf( 'bound post %d to a new, incomplete product.', $postId );
			}
		}

		return new RepairResult( self::NAME, $changes );
	}
}

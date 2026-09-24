<?php
/**
 * WordPressPostGateway: product posts through WordPress's own post functions
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Application\PostGateway;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Writes product posts with wp_insert_post() and wp_update_post(), deferring `wp_after_insert_post`.
 *
 * Owns one fact: how a product post is written so that the write can sit inside the product's
 * transaction. The post is written with `$wp_error` on and `$fire_after_hooks` off, the way core's
 * REST controller writes one: everything core runs inside the call stays inside the transaction,
 * and `wp_after_insert_post`, which creates revisions, waits for fireAfterInsert(). A refusal is
 * `catalog.post_rejected`, with WordPress's own code. Only product posts are written: an insert is
 * always a product post, and an update of any other post is refused.
 *
 * Inside a transaction the post's cache entry is recorded, and the post's cache is cleaned when the
 * transaction rolls back, because a persistent object cache would otherwise keep a post that never
 * existed, or an update that never happened. Both are registered before a listener inside core's
 * call can end the window: for an update, before core is called, with the id it names; for an
 * insert, as soon as core has the new id, by a one-shot listener that runs first on the
 * `clean_post_cache` core fires right after its INSERT and before it caches the post or fires a
 * save hook. Where cache invalidation is suspended core fires no such action, and the insert is
 * registered after core returns.
 *
 * @since 0.1.0
 */
final class WordPressPostGateway implements PostGateway {

	/**
	 * The transaction a write takes part in.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Creates the gateway. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $transactions The transaction a write takes part in.
	 */
	public function __construct( TransactionManager $transactions ) {
		$this->transactions = $transactions;
	}

	/**
	 * Inserts a product post, or updates one, without firing `wp_after_insert_post`.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With CatalogError::PostNotProduct when `ID` names a post that is not a
	 *                        product post, or none; CatalogError::PostRejected when WordPress refuses the post.
	 *
	 * @param array<string, mixed> $postarr The post's fields, unslashed, as core's REST controller prepares
	 *                                      them; an `ID` updates that post. An insert is always a product post.
	 * @return int The post's id.
	 */
	public function write( array $postarr ): int {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		if ( $id > 0 ) {
			if ( ProductCapabilities::POST_TYPE !== get_post_type( $id ) ) {
				CodedException::raise( CatalogError::PostNotProduct, array( 'post_id' => $id ) );
			}

			$this->forgetOnRollback( $id );

			$result = wp_update_post( wp_slash( $postarr ), true, false );
		} else {
			unset( $postarr['ID'] );

			$postarr['post_type'] = ProductCapabilities::POST_TYPE;
			$result               = $this->insert( $postarr );
		}

		if ( $result instanceof \WP_Error ) {
			CodedException::raise( CatalogError::PostRejected, array( 'wordpress_code' => (string) $result->get_error_code() ) );
		}

		return (int) $result;
	}

	/**
	 * Inserts a post, registering its cache for a rollback as soon as core knows its id.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $postarr The post's fields, unslashed.
	 * @return int|\WP_Error The new post's id, or WordPress's refusal.
	 */
	private function insert( array $postarr ): int|\WP_Error {
		$registered = 0;
		$capture    = null;
		$capture    = function ( $postId ) use ( &$capture, &$registered ): void {
			remove_action( 'clean_post_cache', $capture, PHP_INT_MIN );

			$registered = (int) $postId;

			$this->forgetOnRollback( $registered );
		};

		add_action( 'clean_post_cache', $capture, PHP_INT_MIN );

		try {
			$result = wp_insert_post( wp_slash( $postarr ), true, false );
		} finally {
			remove_action( 'clean_post_cache', $capture, PHP_INT_MIN );
		}

		if ( ! $result instanceof \WP_Error && 0 === $registered ) {
			$this->forgetOnRollback( (int) $result );
		}

		return $result;
	}

	/**
	 * Records a post's cache entry in the open transaction, and cleans the post's cache if it rolls back. Outside a transaction it does nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 */
	private function forgetOnRollback( int $postId ): void {
		$this->transactions->touchCacheKey( (string) $postId, 'posts' );
		$this->transactions->afterRollback(
			static function () use ( $postId ): void {
				clean_post_cache( $postId );
			}
		);
	}

	/**
	 * Fires `wp_after_insert_post` for a post write() wrote.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $postId The post's id.
	 * @param bool        $update Whether write() updated an existing post.
	 * @param object|null $before The post as it was before the update (a WP_Post), or null for an insert.
	 */
	public function fireAfterInsert( int $postId, bool $update, ?object $before ): void {
		wp_after_insert_post( $postId, $update, $before instanceof \WP_Post ? $before : null );
	}

	/**
	 * Returns a post's status.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return string|null The status, or null when there is no such post.
	 */
	public function statusOf( int $postId ): ?string {
		$status = get_post_status( $postId );

		return false === $status ? null : $status;
	}

	/**
	 * Returns a post's type.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return string|null The post type, or null when there is no such post.
	 */
	public function postTypeOf( int $postId ): ?string {
		$type = get_post_type( $postId );

		return false === $type ? null : $type;
	}
}

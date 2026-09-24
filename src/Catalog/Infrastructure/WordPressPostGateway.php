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
use SEOCart\Catalog\Domain\ReportCode;
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
 * registered after core returns. An update first cleans the post's cache, because
 * wp_update_post() merges the given fields into the post it reads, and a copy cached before the
 * window would put back fields another writer has changed since.
 *
 * With WP_DEBUG on, the callbacks other plugins or the theme hooked to the hooks core fires
 * inside a product write (`save_post`, `save_post_seocart_product`, `wp_insert_post` and
 * `transition_post_status`) are reported once per request, as
 * `catalog.foreign_save_post_listener`: they run inside the product's transaction window, where
 * a slow call or a statement that ends the transaction costs the save its atomicity. Core's
 * callbacks and the plugin's own are not reported. The report goes to the log, never to a
 * client.
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
	 * Receives a machine code and context for anything reported rather than thrown, or null to report nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var (callable(string, array<string, mixed>): void)|null
	 */
	private $report;

	/**
	 * Whether other plugins' callbacks on the write's hooks are looked for: WP_DEBUG.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $debug;

	/**
	 * Whether they were reported in this request.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $reported = false;

	/**
	 * Creates the gateway. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $transactions The transaction a write takes part in.
	 * @param callable|null      $report       Optional. Receives a machine code (string) and its context (array). Default null.
	 * @param bool               $debug        Optional. Whether to look for other plugins' callbacks, as WP_DEBUG does. Default false.
	 *
	 * @phpstan-param (callable(string, array<string, mixed>): void)|null $report
	 */
	public function __construct( TransactionManager $transactions, ?callable $report = null, bool $debug = false ) {
		$this->transactions = $transactions;
		$this->report       = $report;
		$this->debug        = $debug;
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

		$this->reportForeignListeners();

		if ( $id > 0 ) {
			if ( ProductCapabilities::POST_TYPE !== get_post_type( $id ) ) {
				CodedException::raise( CatalogError::PostNotProduct, array( 'post_id' => $id ) );
			}

			$this->forgetOnRollback( $id );

			// wp_update_post() merges the fields it is given into the post it reads: the row this window sees, not a copy cached earlier.
			clean_post_cache( $id );

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
	 * Reports, once per request and under WP_DEBUG only, the callbacks of other plugins on the hooks core fires inside a product write.
	 *
	 * @since 0.1.0
	 */
	private function reportForeignListeners(): void {
		global $wp_filter;

		if ( ! $this->debug || $this->reported || null === $this->report ) {
			return;
		}

		$foreign = array();

		foreach ( array( 'save_post', 'save_post_' . ProductCapabilities::POST_TYPE, 'wp_insert_post', 'transition_post_status' ) as $hook ) {
			$registered = $wp_filter[ $hook ] ?? null;

			if ( ! $registered instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $registered->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$described = self::foreignCallback( $callback['function'] );

					if ( null !== $described ) {
						$foreign[] = sprintf( '%s @%d %s', $hook, (int) $priority, $described );
					}
				}
			}
		}

		if ( array() === $foreign ) {
			return;
		}

		$this->reported = true;

		( $this->report )( ReportCode::ForeignSaveListener->value, array( 'callbacks' => implode( '; ', $foreign ) ) );
	}

	/**
	 * Describes a callback when it belongs to neither WordPress nor this plugin.
	 *
	 * A callback belongs to WordPress when it is defined in PHP itself or under `wp-includes` or
	 * `wp-admin`, and to this plugin when it is defined in the plugin's shipped code: `src`, the
	 * bundled libraries and the main file.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $callback The callback as WordPress stores it.
	 * @return string|null Its name and where it is defined, or null when it is WordPress's, the plugin's or not a callback.
	 */
	private static function foreignCallback( mixed $callback ): ?string {
		try {
			if ( $callback instanceof \Closure || ( is_string( $callback ) && ! str_contains( $callback, '::' ) ) ) {
				$reflection = new \ReflectionFunction( $callback );
				$name       = $callback instanceof \Closure ? 'closure' : $callback;
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );
				$name       = ( is_object( $callback[0] ) ? get_class( $callback[0] ) . '->' : $callback[0] . '::' ) . $callback[1];
			} elseif ( is_string( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback );
				$name       = $callback;
			} elseif ( is_object( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
				$name       = get_class( $callback ) . '->__invoke';
			} else {
				return null;
			}
		} catch ( \ReflectionException $unknown ) {
			return null;
		}

		$file = $reflection->getFileName();

		if ( false === $file ) {
			return null;
		}

		$file    = wp_normalize_path( $file );
		$plugin  = wp_normalize_path( dirname( __DIR__, 3 ) ) . '/';
		$core    = wp_normalize_path( ABSPATH );
		$ignored = array( $core . 'wp-includes/', $core . 'wp-admin/', $plugin . 'src/', $plugin . 'vendor-scoped/', $plugin . 'seocart.php' );

		foreach ( $ignored as $prefix ) {
			if ( str_starts_with( $file, $prefix ) ) {
				return null;
			}
		}

		return sprintf( '%s in %s:%d', $name, plugin_basename( $file ), (int) $reflection->getStartLine() );
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

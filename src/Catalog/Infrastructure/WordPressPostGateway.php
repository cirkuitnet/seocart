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
 * callbacks and the plugin's own are not reported: they are told by the real path of the file
 * they are defined in, so a directory reached through a symbolic link is still recognised. The
 * report goes to the log, never to a client.
 *
 * While fireAfterInsert() fires `wp_after_insert_post` for a post, isFiringAfterInsert() says so,
 * which tells the post lifecycle that the write reaching the hook is the plugin's own. The
 * reads, contentOf() among them, are plain WordPress reads.
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
	 * How many fireAfterInsert() calls are firing the hook for each post at this moment, by post id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, int>
	 */
	private array $firing = array();

	/**
	 * More files and directories, besides WordPress's and the plugin's, whose callbacks are not another plugin's.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $alsoOwn;

	/**
	 * Creates the gateway. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $transactions The transaction a write takes part in.
	 * @param callable|null      $report       Optional. Receives a machine code (string) and its context (array). Default null.
	 * @param bool               $debug        Optional. Whether to look for other plugins' callbacks, as WP_DEBUG does. Default false.
	 * @param string[]           $alsoOwn      Optional. More files, and directories ending in a slash, whose callbacks are not
	 *                                         another plugin's, besides WordPress's and the plugin's own. Default none.
	 *
	 * @phpstan-param (callable(string, array<string, mixed>): void)|null $report
	 * @phpstan-param list<string>                                        $alsoOwn
	 */
	public function __construct( TransactionManager $transactions, ?callable $report = null, bool $debug = false, array $alsoOwn = array() ) {
		$this->transactions = $transactions;
		$this->report       = $report;
		$this->debug        = $debug;
		$this->alsoOwn      = $alsoOwn;
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
		$own     = $this->ownPaths();

		foreach ( array( 'save_post', 'save_post_' . ProductCapabilities::POST_TYPE, 'wp_insert_post', 'transition_post_status' ) as $hook ) {
			$registered = $wp_filter[ $hook ] ?? null;

			if ( ! $registered instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $registered->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$described = self::foreignCallback( $callback['function'], $own );

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
	 * Returns the files, and the directories ending in a slash, whose callbacks are WordPress's or the plugin's, each by its real path.
	 *
	 * A callback belongs to WordPress when it is defined under `wp-includes` or `wp-admin`, and to
	 * this plugin when it is defined in the plugin's shipped code: `src`, the bundled libraries and
	 * the main file.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The paths.
	 */
	private function ownPaths(): array {
		$plugin = dirname( __DIR__, 3 ) . '/';
		$paths  = array_merge( array( ABSPATH . 'wp-includes/', ABSPATH . 'wp-admin/', $plugin . 'src/', $plugin . 'vendor-scoped/', $plugin . 'seocart.php' ), $this->alsoOwn );

		return array_map( array( self::class, 'realPath' ), $paths );
	}

	/**
	 * Describes a callback when it belongs to neither WordPress nor this plugin.
	 *
	 * A callback defined in PHP itself belongs to WordPress. Otherwise the file it is defined in is
	 * compared with the own paths by real path: PHP reports the file with every symbolic link
	 * resolved, while ABSPATH, for one, may name WordPress's directory through a link. A file is
	 * the plugin's when it is an own file, or lies under an own directory: a directory, which
	 * ends in a slash, is matched by prefix, and a file only by equality, so a file whose name
	 * merely begins with an own file's, such as `seocart.php.bak.php`, is another plugin's.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed    $callback The callback as WordPress stores it.
	 * @param string[] $own      The own paths, from ownPaths().
	 * @return string|null Its name and where it is defined, or null when it is WordPress's, the plugin's or not a callback.
	 *
	 * @phpstan-param list<string> $own
	 */
	private static function foreignCallback( mixed $callback, array $own ): ?string {
		$reflected = CallbackReflection::of( $callback );

		if ( null === $reflected ) {
			return null;
		}

		$file = $reflected['reflection']->getFileName();

		if ( false === $file ) {
			return null;
		}

		$file = CallbackReflection::realPath( $file );

		foreach ( $own as $path ) {
			if ( str_ends_with( $path, '/' ) ? str_starts_with( $file, $path ) : $file === $path ) {
				return null;
			}
		}

		return sprintf( '%s in %s:%d', $reflected['name'], plugin_basename( $file ), (int) $reflected['reflection']->getStartLine() );
	}

	/**
	 * Returns a path with every symbolic link resolved, normalized; the path itself, normalized, when it cannot be resolved.
	 *
	 * A directory keeps its trailing slash, so a comparison by prefix stops at its name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path A file, or a directory ending in a slash.
	 * @return string The path.
	 */
	private static function realPath( string $path ): string {
		return CallbackReflection::realPath( $path );
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
		$this->firing[ $postId ] = ( $this->firing[ $postId ] ?? 0 ) + 1;

		try {
			wp_after_insert_post( $postId, $update, $before instanceof \WP_Post ? $before : null );
		} finally {
			if ( --$this->firing[ $postId ] < 1 ) {
				unset( $this->firing[ $postId ] );
			}
		}
	}

	/**
	 * Tells whether fireAfterInsert() is firing `wp_after_insert_post` for a post at this moment.
	 *
	 * A write another plugin makes to the same post from a listener of that hook is seen as the
	 * plugin's own while the hook runs.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return bool True while this gateway fires the hook for the post.
	 */
	public function isFiringAfterInsert( int $postId ): bool {
		return isset( $this->firing[ $postId ] );
	}

	/**
	 * Returns what a copy of a product post takes from it: its title, content and excerpt, and its featured image.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return array<string, mixed>|null The fields, unslashed, the featured image as `_thumbnail_id` in `meta_input`
	 *                                   when the post has one; null when the post is not a product post.
	 */
	public function contentOf( int $postId ): ?array {
		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post || ProductCapabilities::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$fields    = array(
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
		);
		$thumbnail = (int) get_post_thumbnail_id( $post );

		if ( $thumbnail > 0 ) {
			$fields['meta_input'] = array( '_thumbnail_id' => $thumbnail );
		}

		return $fields;
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

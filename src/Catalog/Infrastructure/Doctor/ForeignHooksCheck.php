<?php
/**
 * ForeignHooksCheck: third-party callbacks on the catalog's own hooks, and a foreign REST controller
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Infrastructure\CallbackReflection;
use SEOCart\Catalog\Interfaces\Rest\ProductPostsController;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports drift on the catalog's own hooks and post type (doctor check 9).
 *
 * Owns one fact: what else is listening where the catalog's own lifecycle depends on being the
 * only, or the deciding, listener. A third-party callback on `save_post`,
 * `save_post_seocart_product`, `wp_insert_post`, `transition_post_status`, `pre_delete_post` or
 * `rest_pre_insert_seocart_product` is named with the plugin, theme or mu-plugin directory its
 * code lives in — never a full server path — found by reflection; both the callback's file and
 * each candidate root are resolved through realpath() first, so a directory reached through a
 * symlink still compares equal to its real one, falling back to the unresolved path only when
 * realpath() finds nothing to resolve. The product post type's `rest_controller_class` not being
 * the plugin's own controller is reported the same way. WordPress core's own callbacks on these
 * hooks (`wp-admin/`, `wp-includes/`) are never drift: every one of these hooks carries several by
 * design. The report lists at most LIMIT findings and says how many more there are. Always drift,
 * never a failure this check decides on its own: a report, for a person to judge.
 *
 * @since 0.1.0
 */
final class ForeignHooksCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'foreign_hooks';

	/**
	 * The hooks the catalog's own lifecycle relies on.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const HOOKS = array(
		'save_post',
		'save_post_seocart_product',
		'wp_insert_post',
		'transition_post_status',
		'pre_delete_post',
		'rest_pre_insert_seocart_product',
	);

	/**
	 * The most drift findings the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.foreign_hooks`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists third-party callbacks on the six hooks, and a foreign REST controller.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Always passed: what it finds is drift, for a person to judge, never a failure this check decides on its own.
	 */
	public function run(): CheckResult {
		global $wp_filter;

		$findings = array();
		$more     = 0;

		$report = static function ( string $line ) use ( &$findings, &$more ): void {
			if ( count( $findings ) < self::LIMIT ) {
				$findings[] = $line;
			} else {
				++$more;
			}
		};

		foreach ( self::HOOKS as $hook ) {
			$hooked = $wp_filter[ $hook ] ?? null;

			if ( ! $hooked instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $hooked->callbacks as $priority => $group ) {
				foreach ( $group as $spec ) {
					$origin = self::origin( $spec['function'] );

					if ( null !== $origin ) {
						$report( sprintf( 'Drift: a callback on `%1$s` (priority %2$d) belongs to %3$s.', $hook, $priority, $origin ) );
					}
				}
			}
		}

		$type = get_post_type_object( ProductCapabilities::POST_TYPE );

		if ( null !== $type && ProductPostsController::class !== $type->rest_controller_class ) {
			$report( sprintf( 'Drift: the %1$s post type\'s rest_controller_class is %2$s, not the plugin\'s own controller.', ProductCapabilities::POST_TYPE, is_string( $type->rest_controller_class ) ? $type->rest_controller_class : 'the WordPress default' ) );
		}

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, 'Nothing foreign is listening on the catalog\'s own hooks, and the post type\'s REST controller is the plugin\'s own.' );
		}

		$total = count( $findings ) + $more;

		if ( $more > 0 ) {
			$findings[] = sprintf( '...and %d more.', $more );
		}

		// Drift, never a failure this check decides on its own: passed, with what it found.
		return CheckResult::pass( self::NAME, sprintf( '%d drift %s found.', $total, 1 === $total ? 'report' : 'reports' ), $findings );
	}

	/**
	 * Names where a callback's code lives, when it is not the plugin's own.
	 *
	 * Ownership is decided by the callback's own, resolved file, against the plugin's own shipped
	 * paths — never by the namespace its code happens to be declared in, which any separately
	 * distributed plugin could share, going unreported were namespace alone ever trusted.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $callback The callback, as WP_Hook stored it.
	 * @return string|null The plugin, theme or mu-plugin directory the code lives in, or the bare
	 *                      file when it names none of those; null for the plugin's own code.
	 */
	private static function origin( $callback ): ?string {
		$reflected = CallbackReflection::of( $callback );

		if ( null === $reflected ) {
			return 'a callback that could not be inspected';
		}

		$file = $reflected['reflection']->getFileName();

		if ( false === $file ) {
			return 'PHP itself';
		}

		$path = CallbackReflection::realPath( $file );

		foreach ( self::ownPaths() as $own ) {
			if ( str_ends_with( $own, '/' ) ? str_starts_with( $path, $own ) : $path === $own ) {
				return null;
			}
		}

		foreach (
			array(
				'plugin'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : null,
				'mu-plugin' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : null,
				'theme'     => function_exists( 'get_theme_root' ) ? get_theme_root() : null,
			) as $kind => $root
		) {
			if ( null === $root ) {
				continue;
			}

			$root = rtrim( CallbackReflection::realPath( $root . '/' ), '/' ) . '/';

			if ( str_starts_with( $path, $root ) ) {
				$relative = substr( $path, strlen( $root ) );
				$slug     = explode( '/', $relative )[0];

				return sprintf( 'the %1$s "%2$s"', $kind, $slug );
			}
		}

		return 'code outside a recognised plugin, theme or mu-plugin directory';
	}

	/**
	 * The plugin's own shipped paths, real, resolved: never drift, whatever hook they are on.
	 *
	 * The same "own" WordPressPostGateway's debug listing uses (its ownPaths()); this check reads
	 * that one fact from a resolved path too, rather than keeping its own second copy of it.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The paths, files and directories (ending in a slash) alike.
	 */
	private static function ownPaths(): array {
		$plugin = dirname( __DIR__, 4 ) . '/';

		return array_map(
			array( CallbackReflection::class, 'realPath' ),
			array(
				ABSPATH . 'wp-includes/',
				ABSPATH . 'wp-admin/',
				$plugin . 'src/',
				$plugin . 'vendor-scoped/',
				$plugin . 'seocart.php',
			)
		);
	}
}

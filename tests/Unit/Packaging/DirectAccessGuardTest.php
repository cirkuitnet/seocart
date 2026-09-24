<?php
/**
 * Tests that every PHP file that ships guards against direct access where Plugin Check looks for it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Packaging\DistIgnore;

/**
 * Every first-party PHP file in the release zip opens with its direct-access guard within its first 50 lines.
 *
 * Plugin Check reports `missing_direct_file_access_protection` for a file whose guard it cannot
 * find, and it looks in two places only. It walks the top-level statements of the file's syntax
 * tree, where a file that declares `namespace X;` has none but the namespace itself; then it
 * searches the first 50 lines of the file, counting every line, comments and imports included. So
 * a namespaced file passes only when the guard is within its first 50 lines, and one long enough
 * list of imports above the guard fails the release. This test catches that before CI does.
 *
 * Which files ship is .distignore's decision, asked through DistIgnore, the one implementation of
 * its dialect, so a new directory that ships is checked without a change here. vendor-scoped/ is
 * left out: it holds third-party libraries only, and the Plugin Check job excludes it the same way.
 *
 * Planted violation: in src/Support/Clock.php, add 50 comment lines above the guard. The test
 * names the file and the line its guard is on.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class DirectAccessGuardTest extends TestCase {

	/**
	 * How many lines of a file Plugin Check searches for the guard.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SEARCHED_LINES = 50;

	/**
	 * The guard a shipped file opens with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const GUARD = "defined( 'ABSPATH' ) || exit;";

	/**
	 * Files whose guard is another one, each with it. WordPress defines WP_UNINSTALL_PLUGIN only
	 * while it runs the uninstall routine, which is the one time uninstall.php may run.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const OTHER_GUARDS = array(
		'uninstall.php' => "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;",
	);

	/**
	 * Shipped directories that hold third-party code only, which the guard rule does not cover.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const THIRD_PARTY = array( 'vendor-scoped' );

	/**
	 * Tests that each shipped first-party PHP file has its guard within the lines Plugin Check searches.
	 *
	 * @since 0.1.0
	 */
	public function test_every_shipped_php_file_guards_within_the_lines_plugin_check_searches(): void {
		$root   = dirname( __DIR__, 3 );
		$files  = self::shippedPhpFiles( $root, DistIgnore::fromFile( $root . '/.distignore' ), '' );
		$failed = array();

		sort( $files );

		$this->assertContains( 'seocart.php', $files, 'The walk did not find the main plugin file, so a clean result would prove nothing.' );
		$this->assertContains( 'src/Platform/Kernel/Modules.php', $files, 'The walk did not reach src/, so a clean result would prove nothing.' );

		foreach ( $files as $file ) {
			$guard = self::OTHER_GUARDS[ $file ] ?? self::GUARD;
			$lines = file( $root . '/' . $file, FILE_IGNORE_NEW_LINES );
			$found = null;

			foreach ( false === $lines ? array() : $lines as $index => $line ) {
				if ( trim( $line ) === $guard ) {
					$found = $index + 1;
					break;
				}
			}

			if ( null === $found || $found > self::SEARCHED_LINES ) {
				$failed[] = sprintf( '%s: %s', $file, null === $found ? 'no guard' : "guard on line {$found}" );
			}
		}

		$this->assertSame(
			array(),
			$failed,
			sprintf( 'Plugin Check looks for the guard `%s` only in the first %d lines of a namespaced file. Move it up, directly below the `namespace` line if the imports are long.', self::GUARD, self::SEARCHED_LINES )
		);
	}

	/**
	 * Lists the first-party PHP files that ship beneath a directory, pruning what .distignore excludes without reading it.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $root      The repository root.
	 * @param DistIgnore $ignore    The release zip's exclusions.
	 * @param string     $directory The directory, relative to the root; an empty string for the root.
	 * @return list<string> Paths relative to the root.
	 */
	private static function shippedPhpFiles( string $root, DistIgnore $ignore, string $directory ): array {
		$files = array();
		$names = scandir( '' === $directory ? $root : $root . '/' . $directory );

		foreach ( false === $names ? array() : $names as $name ) {
			$relative = '' === $directory ? $name : $directory . '/' . $name;

			if ( '.' === $name || '..' === $name || $ignore->excludes( $relative ) || in_array( $relative, self::THIRD_PARTY, true ) ) {
				continue;
			}

			if ( is_dir( $root . '/' . $relative ) ) {
				$files = array_merge( $files, self::shippedPhpFiles( $root, $ignore, $relative ) );
			} elseif ( str_ends_with( $name, '.php' ) ) {
				$files[] = $relative;
			}
		}

		return $files;
	}
}

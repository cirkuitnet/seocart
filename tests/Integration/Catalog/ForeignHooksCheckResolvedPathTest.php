<?php
/**
 * Tests CallbackReflection::realPath(): symlink-safe, the one path resolution both consumers share
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Infrastructure\CallbackReflection;
use SEOCart\Tests\Support\DatabaseTestCase;

/**
 * CallbackReflection::realPath() is what lets a callback reached through a symlinked plugin,
 * theme or mu-plugin directory still compare equal to that directory's real one: both a
 * callback's own file and each candidate root are resolved through it before ForeignHooksCheck's
 * `origin()`, and WordPressPostGateway's own debug listing, compare them.
 *
 * Runs under the system temporary directory, never under a real WP_PLUGIN_DIR or WPMU_PLUGIN_DIR
 * — this test never writes there.
 *
 * @since 0.1.0
 */
final class ForeignHooksCheckResolvedPathTest extends DatabaseTestCase {

	/**
	 * What this test created, removed after it: symlinks and files first, then their directories,
	 * deepest first.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $links = array();

	/**
	 * The files this test created.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $files = array();

	/**
	 * The directories this test created, in the order it created them.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $dirs = array();

	/**
	 * Removes everything this test created: symlinks and files first, then their directories,
	 * deepest first.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		foreach ( $this->links as $path ) {
			unlink( $path );
		}

		foreach ( $this->files as $path ) {
			unlink( $path );
		}

		foreach ( array_reverse( $this->dirs ) as $path ) {
			rmdir( $path );
		}

		parent::tear_down();
	}

	/**
	 * Tests that a path reached through a symlinked directory resolves to the same real path as
	 * the directory itself: a callback's file under the symlink, and the directory named as a
	 * candidate root, compare equal once both go through realPath().
	 *
	 * @since 0.1.0
	 */
	public function test_a_path_through_a_symlinked_directory_resolves_the_same_as_the_real_one(): void {
		$base = sys_get_temp_dir() . '/seocart-foreign-hooks-test-' . getmypid();
		$real = $base . '/real-plugin-dir';
		$link = $base . '/linked-plugin-dir';

		mkdir( $real, 0700, true );
		$this->dirs[] = $base;
		$this->dirs[] = $real;

		file_put_contents( $real . '/callback.php', "<?php\n" );
		$this->files[] = $real . '/callback.php';

		$this->assertTrue( symlink( $real, $link ), 'The symlink fixture could not be created.' );
		$this->links[] = $link;

		$throughTheSymlink = CallbackReflection::realPath( $link . '/callback.php' );
		$theRealRoot       = CallbackReflection::realPath( $real . '/' );

		$this->assertStringStartsWith( $theRealRoot, $throughTheSymlink, 'A path reached through a symlinked directory must resolve under that directory\'s real one, so origin() still recognises it as belonging to it.' );
	}

	/**
	 * Tests that a path with nothing to resolve — a fixture path that does not exist on disk, as
	 * a test double's reflected file name can be — is returned unchanged (only slash-normalised),
	 * never dropped.
	 *
	 * @since 0.1.0
	 */
	public function test_a_path_that_does_not_exist_is_returned_unchanged(): void {
		$this->assertSame( '/no/such/path/on/this/machine.php', CallbackReflection::realPath( '/no/such/path/on/this/machine.php' ) );
	}
}

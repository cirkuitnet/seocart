<?php
/**
 * Tests that SEOCart loads into a running WordPress, cleanly
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration;

use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Tests\Support\AutoloaderWatch;
use SEOCart\Tests\Support\ErrorRecorder;
use WP_UnitTestCase;

/**
 * The integration suite's sample test: WordPress booted, and the plugin booted inside it.
 *
 * The plugin is loaded by tests/bootstrap-integration.php before the first test starts, so
 * these tests inspect the result of that load; they do not load anything themselves.
 *
 * Each test names the planted violation that must turn it red. Revert the plant afterwards.
 *
 * @since 0.1.0
 */
final class PluginLoadsTest extends WP_UnitTestCase {

	/**
	 * Tests that WordPress included the main plugin file.
	 *
	 * Planted violation: in tests/bootstrap-integration.php, comment out the `require_once` of
	 * `seocart.php` inside the `muplugins_loaded` callback.
	 *
	 * @since 0.1.0
	 */
	public function test_main_plugin_file_is_loaded(): void {
		$main_file = realpath( dirname( __DIR__, 2 ) . '/seocart.php' );

		$this->assertContains( $main_file, get_included_files(), 'The integration bootstrap did not include seocart.php.' );
		$this->assertSame( $main_file, realpath( SEOCART_PLUGIN_FILE ), 'SEOCART_PLUGIN_FILE must name the main plugin file of this checkout.' );
	}

	/**
	 * Tests that the main file hooked the kernel to `plugins_loaded`.
	 *
	 * Planted violation: in seocart.php, change the hook name in the final `add_action()` call
	 * from 'plugins_loaded' to 'init'.
	 *
	 * @since 0.1.0
	 */
	public function test_kernel_boot_is_hooked_to_plugins_loaded(): void {
		// The message is built before the assertion runs, and seocart.php may be what is missing.
		$unmet = function_exists( 'seocart_unmet_requirement' ) ? seocart_unmet_requirement() : '(unknown: seocart.php was not loaded)';

		$this->assertNotFalse(
			has_action( 'plugins_loaded', array( Kernel::class, 'boot' ) ),
			'seocart.php must register Kernel::boot() on plugins_loaded. If the version guard refused to load the plugin, the requirement it reports is: "' . $unmet . '".'
		);
	}

	/**
	 * Tests that `plugins_loaded` reached the kernel.
	 *
	 * Planted violation: in seocart.php, comment out the final `add_action()` call. The test
	 * above turns red with it, for the same reason.
	 *
	 * @since 0.1.0
	 */
	public function test_kernel_has_booted(): void {
		$this->assertTrue( Kernel::hasBooted(), 'WordPress fired plugins_loaded, but Kernel::boot() did not run.' );
	}

	/**
	 * Tests that the plugin's own autoloader loaded every plugin class, the kernel included.
	 *
	 * The release zip has no `vendor/`, so the autoloader in seocart.php is all a released site
	 * has. Composer can find the same classes, and without the watch it would load them first
	 * and hide a plugin autoloader that finds nothing. The integration bootstrap starts the
	 * watch directly after it includes seocart.php. The record is read here, so it covers the
	 * WordPress boot and the tests that ran before this one, not the tests that run after it.
	 *
	 * Planted violation: in the autoloader of seocart.php, change `'/src/'` to `'/source/'`.
	 * The kernel still boots, through Composer, so the three tests above stay green; this one
	 * must name `SEOCart\Platform\Kernel\Kernel`.
	 *
	 * @since 0.1.0
	 */
	public function test_plugin_classes_are_loaded_by_the_plugins_own_autoloader(): void {
		$this->assertTrue( AutoloaderWatch::isWatching(), 'The integration bootstrap did not start the autoloader watch, so an empty record would prove nothing.' );

		$this->assertSame(
			array(),
			AutoloaderWatch::missed(),
			'The autoloader in seocart.php did not load these plugin classes; Composer did. The release zip ships without Composer\'s autoloader, so a released site would fail on them.'
		);
	}

	/**
	 * Tests that nothing raised a notice, a warning or a deprecation while the plugin loaded.
	 *
	 * The window runs from the inclusion of seocart.php to the end of the WordPress boot, which
	 * covers the main file, `plugins_loaded` and `init`. An error raised by WordPress itself in
	 * that window is reported as well, with its file, so it cannot be mistaken for the plugin's.
	 *
	 * Planted violation: in seocart.php, directly below the ABSPATH guard, add
	 * `trigger_error( 'planted', E_USER_DEPRECATED );`. Planting the same line at the top of
	 * Kernel::boot() proves that the window reaches `plugins_loaded`.
	 *
	 * @since 0.1.0
	 */
	public function test_loading_raises_no_php_errors(): void {
		$records = ErrorRecorder::records();

		$this->assertSame(
			array(),
			$records,
			"PHP errors were raised while SEOCart loaded:\n" . ErrorRecorder::describe( $records ) . "\n"
		);
	}
}

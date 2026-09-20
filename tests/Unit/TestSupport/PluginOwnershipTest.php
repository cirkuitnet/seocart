<?php
/**
 * Tests the rules that decide what counts as SEOCart's in an idle-budget measurement
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Tests\Support\PluginOwnership;

/**
 * Proves the attribution of files, callbacks and call stacks, with no WordPress involved.
 *
 * A wrong answer here makes a budget lie in one of two ways: test-harness code counted against
 * the plugin is a false alarm, and plugin code that goes uncounted is a budget that cannot fail.
 *
 * @since 0.1.0
 */
final class PluginOwnershipTest extends TestCase {

	use TemporaryPluginDirectory;

	/**
	 * The rules under test, for the temporary plugin directory.
	 *
	 * @since 0.1.0
	 *
	 * @var PluginOwnership
	 */
	private PluginOwnership $owner;

	/**
	 * Creates a plugin directory with shipped code, test code and a vendor directory.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$closure = '<?php return static function () {};';

		$directory = $this->createPluginDirectory(
			array(
				'composer.json'                 => '{"autoload-dev":{"psr-4":{"SEOCart\\\\Tests\\\\":"tests/","SEOCart\\\\Tools\\\\":"tools/"}}}',
				'seocart.php'                   => '<?php',
				'src/hooks.php'                 => $closure,
				'tests/hooks.php'               => $closure,
				'vendor/pkg/file.php'           => '<?php',
				'vendor/composer/installed.php' => '<?php',
			)
		);

		$this->owner = PluginOwnership::fromComposerManifest( $directory );
	}

	/**
	 * Removes the plugin directory.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		$this->removePluginDirectory();

		parent::tearDown();
	}

	/**
	 * Tests that shipped files are owned and that harness files, vendor files and outside files are not.
	 *
	 * @since 0.1.0
	 */
	public function test_a_file_is_owned_when_it_is_shipped_plugin_code(): void {
		$directory = $this->pluginDirectory;

		$this->assertTrue( $this->owner->ownsFile( $directory . '/seocart.php' ) );
		$this->assertTrue( $this->owner->ownsFile( $directory . '/src/Platform/Kernel/Kernel.php' ) );
		$this->assertTrue( $this->owner->ownsFile( str_replace( '/', '\\', $directory . '/src/hooks.php' ) ), 'A path with backslashes is the same file.' );

		$this->assertFalse( $this->owner->ownsFile( $directory . '/tests/hooks.php' ), 'tests/ is development code.' );
		$this->assertFalse( $this->owner->ownsFile( $directory . '/tools/scope-vendor.php' ), 'tools/ is development code.' );
		$this->assertFalse( $this->owner->ownsFile( $directory . '/vendor/pkg/file.php' ), 'vendor/ is never shipped.' );
		$this->assertFalse( $this->owner->ownsFile( $directory . '-sibling/src/hooks.php' ), 'A directory that merely starts with the same name is another plugin.' );
		$this->assertFalse( $this->owner->ownsFile( '/wordpress/wp-includes/plugin.php' ) );
	}

	/**
	 * Tests that a file is recognized however its path is written: with `..` segments, or through a symbolic link.
	 *
	 * @since 0.1.0
	 */
	public function test_a_file_is_the_same_file_however_its_path_is_written(): void {
		$directory = $this->pluginDirectory;

		$this->assertTrue( $this->owner->ownsFile( $directory . '/vendor/composer/../../src/hooks.php' ), 'The path Composer includes an existing plugin file by.' );
		$this->assertTrue( $this->owner->ownsFile( $directory . '/vendor/composer/../../src/./Absent.php' ), 'The same for a file that does not exist, which realpath() cannot resolve.' );
		$this->assertFalse( $this->owner->ownsFile( $directory . '/src/../vendor/pkg/file.php' ), 'A vendor file does not become shipped code by being reached through src/.' );
		$this->assertFalse( $this->owner->ownsFile( $directory . '/src/../../elsewhere/src/hooks.php' ), 'A path that leaves the plugin directory.' );
		$this->assertSame( 'src/hooks.php', $this->owner->relativePath( $directory . '/vendor/composer/../../src/hooks.php' ) );

		$link = $directory . '-link';

		$this->assertTrue( symlink( $directory, $link ), 'The test needs a symbolic link to the plugin directory.' );

		try {
			$this->assertTrue( $this->owner->ownsFile( $link . '/src/hooks.php' ), 'A checkout reached through a symbolic link.' );
			$this->assertFalse( $this->owner->ownsFile( $link . '/tests/hooks.php' ) );
		} finally {
			unlink( $link );
		}
	}

	/**
	 * Tests that a relative path is not resolved against the working directory, even when that is the plugin directory.
	 *
	 * @since 0.1.0
	 */
	public function test_a_relative_path_is_never_owned(): void {
		$working_directory = (string) getcwd();

		chdir( $this->pluginDirectory );

		try {
			$this->assertFalse( $this->owner->ownsFile( 'seocart.php' ) );
			$this->assertFalse( $this->owner->ownsCaller( "require_once('wp-settings.php'), require_once('seocart.php'), get_option" ) );
		} finally {
			chdir( $working_directory );
		}
	}

	/**
	 * Tests that a directory named like a development directory is only excluded at the plugin root.
	 *
	 * @since 0.1.0
	 */
	public function test_a_development_directory_name_deeper_in_the_tree_is_still_shipped(): void {
		$this->assertTrue( $this->owner->ownsFile( $this->pluginDirectory . '/src/Catalog/tests/Fixture.php' ) );
		$this->assertTrue( $this->owner->ownsFile( $this->pluginDirectory . '/vendor-scoped/autoload.php' ) );
	}

	/**
	 * Tests the attribution of class and function names.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_symbols
	 *
	 * @param string $symbol   A class or function name.
	 * @param bool   $is_owned Whether it is shipped plugin code.
	 */
	public function test_a_symbol_is_owned_when_it_is_under_the_plugin_namespace_or_prefix( string $symbol, bool $is_owned ): void {
		$this->assertSame( $is_owned, $this->owner->ownsSymbol( $symbol ) );
	}

	/**
	 * Provides class and function names with their expected attribution.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, bool}> Test cases.
	 */
	public static function data_symbols(): array {
		return array(
			'plugin class'                   => array( 'SEOCart\\Platform\\Kernel\\Kernel', true ),
			'plugin class, leading slash'    => array( '\\SEOCart\\Platform\\Kernel\\Kernel', true ),
			'plugin class, other case'       => array( 'seocart\\platform\\kernel\\kernel', true ),
			'plugin static method'           => array( 'SEOCart\\Platform\\Kernel\\Kernel::boot', true ),
			'plugin function'                => array( 'seocart_unmet_requirement', true ),
			'test class'                     => array( 'SEOCart\\Tests\\Integration\\PluginLoadsTest', false ),
			'tool class'                     => array( 'SEOCart\\Tools\\Phpcs\\Sniff', false ),
			'namespace that only looks like' => array( 'SEOCartel\\Platform\\Kernel', false ),
			'function that only looks like'  => array( 'seocartel_boot', false ),
			'prefix in the middle'           => array( 'wp_seocart_boot', false ),
			'WordPress class'                => array( 'WP_Query', false ),
			'WordPress function'             => array( 'wp_maybe_load_widgets', false ),
		);
	}

	/**
	 * Tests that the development namespaces come from the constructor argument, not from a fixed list.
	 *
	 * @since 0.1.0
	 */
	public function test_development_code_is_whatever_the_manifest_declares(): void {
		$without_tools = new PluginOwnership( $this->pluginDirectory, array( 'SEOCart\\Tests\\' => 'tests/' ) );

		$this->assertTrue( $without_tools->ownsSymbol( 'SEOCart\\Tools\\Phpcs\\Sniff' ) );
		$this->assertTrue( $without_tools->ownsFile( $this->pluginDirectory . '/tools/scope-vendor.php' ) );
		$this->assertFalse( $without_tools->ownsSymbol( 'SEOCart\\Tests\\Unit\\X' ) );
		$this->assertFalse( $without_tools->ownsFile( $this->pluginDirectory . '/vendor/pkg/file.php' ), 'vendor/ is excluded whatever the manifest says.' );
	}

	/**
	 * Tests the attribution of every callback form WordPress stores.
	 *
	 * @since 0.1.0
	 */
	public function test_a_callback_is_owned_when_the_plugin_wrote_it(): void {
		$this->assertTrue( $this->owner->ownsCallback( 'seocart_render_requirements_notice' ), 'A prefixed function.' );
		$this->assertTrue( $this->owner->ownsCallback( array( 'SEOCart\\Platform\\Kernel\\Kernel', 'boot' ) ), 'A static method, as seocart.php registers it.' );
		$this->assertTrue( $this->owner->ownsCallback( 'SEOCart\\Platform\\Kernel\\Kernel::boot' ), 'A static method as one string.' );
		$this->assertTrue( $this->owner->ownsCallback( array( new Kernel(), 'hasBooted' ) ), 'A method of a plugin object.' );
		$this->assertTrue( $this->owner->ownsCallback( new Kernel() ), 'A plugin object used as an invokable.' );
		$this->assertTrue( $this->owner->ownsCallback( require $this->pluginDirectory . '/src/hooks.php' ), 'A closure written in a shipped file.' );

		$this->assertFalse( $this->owner->ownsCallback( require $this->pluginDirectory . '/tests/hooks.php' ), 'A closure written in a test file.' );
		$this->assertFalse( $this->owner->ownsCallback( array( $this, 'test_a_callback_is_owned_when_the_plugin_wrote_it' ) ), 'A method of a test object.' );
		$this->assertFalse( $this->owner->ownsCallback( 'wp_maybe_load_widgets' ) );
		$this->assertFalse( $this->owner->ownsCallback( array( 'WP_Widget_Factory', '_register_widgets' ) ) );
		$this->assertFalse( $this->owner->ownsCallback( \Closure::fromCallable( 'strlen' ) ), 'A closure over an internal function has no file.' );
		$this->assertFalse( $this->owner->ownsCallback( null ) );
	}

	/**
	 * Tests the attribution of call stacks in the form wpdb records them.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_callers
	 *
	 * @param string $caller   A call-stack summary. `{plugin}` stands for the plugin directory.
	 * @param bool   $is_owned Whether shipped plugin code is in the stack.
	 */
	public function test_a_call_stack_is_owned_when_any_frame_is_shipped_plugin_code( string $caller, bool $is_owned ): void {
		$caller = str_replace( '{plugin}', $this->pluginDirectory, $caller );

		$this->assertSame( $is_owned, $this->owner->ownsCaller( $caller ), $caller );
	}

	/**
	 * Provides call-stack summaries with their expected attribution.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, bool}> Test cases.
	 */
	public static function data_callers(): array {
		$boot = "require_once('wp-settings.php'), do_action('plugins_loaded'), WP_Hook->do_action, WP_Hook->apply_filters, ";

		return array(
			'a plugin static method'                    => array( $boot . 'SEOCart\\Platform\\Kernel\\Kernel::boot, get_option', true ),
			'a plugin instance method'                  => array( $boot . 'SEOCart\\Catalog\\ProductRepository->find, WP_Query->get_posts', true ),
			'a plugin function'                         => array( "require_once('{plugin}/seocart.php'), seocart_unmet_requirement, get_bloginfo, get_option", true ),
			'a plugin file at file scope'               => array( "require_once('wp-settings.php'), require_once('{plugin}/seocart.php'), get_option", true ),
			'a closure in a plugin file, PHP 8.4'       => array( $boot . '{closure:{plugin}/src/hooks.php:12}, get_option', true ),
			'a closure in a plugin method, PHP 8.4'     => array( $boot . 'SEOCart\\Platform\\Kernel\\Kernel::{closure:SEOCart\\Platform\\Kernel\\Kernel::boot():52}, get_option', true ),
			'a closure in a plugin namespace, PHP 8.3'  => array( $boot . 'SEOCart\\Catalog\\{closure}, get_option', true ),
			'WordPress only'                            => array( "require('wp-blog-header.php'), wp, WP->main, WP->query_posts, WP_Query->query, WP_Query->get_posts", false ),
			'the test that made the request'            => array( 'PHPUnit\\TextUI\\Command::main, SEOCart\\Tests\\Integration\\Performance\\IdleBudgetTest->serveFrontPage, WP_UnitTestCase_Base->go_to, WP->main, WP_Query->get_posts', false ),
			'the bootstrap closure, PHP 8.4'            => array( "do_action('muplugins_loaded'), WP_Hook->do_action, {closure:{plugin}/tests/bootstrap-integration.php:80}, get_option", false ),
			'the Composer autoloader'                   => array( "require_once('{plugin}/vendor/autoload.php'), get_option", false ),
			'a third party listening on a plugin hook'  => array( "do_action('seocart_loaded'), WP_Hook->do_action, WP_Hook->apply_filters, Acme\\Listener->onLoaded, get_option", false ),
			'a closure under a directory named like us' => array( $boot . '{closure:/srv/seocart_sites/other-plugin/plugin.php:3}, get_option', false ),
			'the prefix in the middle of a name'        => array( $boot . 'acme_seocart_bridge, get_option', false ),
			'a third-party method named like ours'      => array( $boot . 'Acme\\Bridge->seocart_sync, get_option', false ),
			'a third-party static method named so'      => array( $boot . 'Acme\\Bridge::seocart_sync, get_option', false ),
			'a closure in such a method, PHP 8.4'       => array( $boot . 'Acme\\Bridge->{closure:Acme\\Bridge::seocart_sync():5}, get_option', false ),
			'a closure in a plugin function, PHP 8.4'   => array( $boot . '{closure:seocart_unmet_requirement():75}, get_option', true ),
			'a plugin closure bound to a third party'   => array( $boot . 'Acme\\Bridge->{closure:seocart_unmet_requirement():75}, get_option', true ),
			'a plugin file that Composer included'      => array( "include('{plugin}/vendor/composer/../../src/hooks.php'), get_option", true ),
			'a vendor file reached through src'         => array( "include('{plugin}/src/../vendor/pkg/file.php'), get_option", false ),
			'an empty stack'                            => array( '', false ),
		);
	}
}

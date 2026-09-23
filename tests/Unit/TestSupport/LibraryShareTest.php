<?php
/**
 * Tests how the idle-request budgets tell the bundled library's share from the plugin's
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\BootstrapProbes;
use SEOCart\Tests\Support\LibraryShare;
use SEOCart\Tests\Support\PluginOwnership;

/**
 * Files under the library's directory are its; hooks loading the plugin added are its, unless they are the plugin's own.
 *
 * @since 0.1.0
 */
final class LibraryShareTest extends TestCase {

	/**
	 * Tests that files split by the library's directory.
	 *
	 * @since 0.1.0
	 */
	public function test_files_under_the_library_directory_are_the_librarys(): void {
		$split = LibraryShare::splitFiles(
			array(
				'seocart.php'                     => 10,
				'src/Platform/Kernel/Kernel.php'  => 20,
				LibraryShare::DIRECTORY . 'action-scheduler.php' => 30,
				LibraryShare::DIRECTORY . 'classes/ActionScheduler_Versions.php' => 40,
				'vendor-scoped/other/library.php' => 50,
			)
		);

		$this->assertSame(
			array(
				'seocart.php'                     => 10,
				'src/Platform/Kernel/Kernel.php'  => 20,
				'vendor-scoped/other/library.php' => 50,
			),
			$split['plugin']
		);
		$this->assertSame( array( LibraryShare::DIRECTORY . 'action-scheduler.php', LibraryShare::DIRECTORY . 'classes/ActionScheduler_Versions.php' ), array_keys( $split['library'] ) );
	}

	/**
	 * Tests the hook split: what loading the plugin added, less the plugin's own, is the library's; a closure in a library file is the library's.
	 *
	 * Planted violation: in splitHooks(), count every added registration as the library's
	 * (drop the `in_array( $line, $own )` check); the plugin's own registration is then counted twice.
	 *
	 * @since 0.1.0
	 */
	public function test_hooks_added_by_loading_the_plugin_that_are_not_its_own_are_the_librarys(): void {
		$own     = array(
			array(
				'hook'     => 'plugins_loaded',
				'priority' => 10,
				'callback' => 'SEOCart\\Platform\\Kernel\\Kernel::boot',
			),
			array(
				'hook'     => 'init',
				'priority' => 1,
				'callback' => 'closure at ' . LibraryShare::DIRECTORY . 'classes/abstracts/ActionScheduler.php:238',
			),
		);
		$control = array( 'init @0  wp_core_init', 'init @10  twice', 'init @10  twice' );
		$with    = array(
			'init @0  wp_core_init',
			'init @10  twice',
			'init @10  twice',
			'init @10  twice',
			'plugins_loaded @10  SEOCart\\Platform\\Kernel\\Kernel::boot',
			'init @1  closure at ' . LibraryShare::DIRECTORY . 'classes/abstracts/ActionScheduler.php:238',
			'init @1  ActionScheduler_DBStore->init',
		);

		$split = LibraryShare::splitHooks( $own, $with, $control );

		$this->assertSame( array( 'plugins_loaded @10  SEOCart\\Platform\\Kernel\\Kernel::boot' ), $split['plugin'] );
		$this->assertSame(
			array(
				'init @10  twice',
				'init @1  closure at ' . LibraryShare::DIRECTORY . 'classes/abstracts/ActionScheduler.php:238',
				'init @1  ActionScheduler_DBStore->init',
			),
			$split['library'],
			'A registration the control run had fewer times than the plugin run is an added one.'
		);
	}

	/**
	 * Tests that describeAll() writes one line per registration, with the plugin's naming of callbacks.
	 *
	 * @since 0.1.0
	 */
	public function test_every_registration_is_described_in_the_lines_the_split_compares(): void {
		$probes = new BootstrapProbes( new PluginOwnership( '/plugin', array() ) );
		$lines  = LibraryShare::describeAll(
			array(
				'init'           => array(
					0  => array( array( 'function' => 'wp_core_init' ) ),
					10 => array( array( 'function' => array( 'ActionScheduler_Versions', 'initialize_latest_version' ) ) ),
				),
				'plugins_loaded' => array(
					1 => array( array( 'function' => 'SEOCart\\Platform\\Kernel\\Kernel::boot' ) ),
				),
			),
			$probes
		);

		$this->assertSame(
			array(
				'init @0  wp_core_init',
				'init @10  ActionScheduler_Versions::initialize_latest_version',
				'plugins_loaded @1  SEOCart\\Platform\\Kernel\\Kernel::boot',
			),
			$lines
		);
	}
}

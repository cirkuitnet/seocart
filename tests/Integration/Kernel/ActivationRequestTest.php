<?php
/**
 * Tests that activation installs the site in the request WordPress activates it in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\KernelTestCase;

/**
 * WordPress activates a plugin in a request where it includes the plugin's file after
 * `plugins_loaded` has fired, so the kernel has not booted. The activation hook must install the
 * site all the same. The request is played in a child process
 * (tests/Support/activation-request-probe.php); the installation is read here, from the database.
 *
 * Planted violation: at the top of Kernel::activate(), plant
 * `if ( ! self::hasBooted() ) { return; }`: nothing is installed and the test fails.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class ActivationRequestTest extends KernelTestCase {

	/**
	 * Tests that the activation hook installs the site although the kernel never booted.
	 *
	 * @since 0.1.0
	 */
	public function test_the_activation_hook_installs_the_site_without_a_boot(): void {
		$this->assertNull( $this->storedRecord(), 'The site is not fresh.' );

		$report = ChildProcessProbe::run( dirname( __DIR__, 2 ) . '/Support/activation-request-probe.php' );

		$this->assertFalse( $report['booted_before_activation'], 'The kernel had booted before the activation hook, so the probe proves nothing.' );

		wp_cache_flush();
		self::reloadRoles();

		$this->assertNotNull( $this->storedRecord(), 'The activation request left no boot record.' );
		$this->assertSame( self::codeHead(), BootRecord::fromJson( $this->storedRecord() )->schemaHead() );
		$this->assertSame( SEOCART_VERSION, BootRecord::fromJson( $this->storedRecord() )->pluginVersion() );
		$this->assertTrue( $this->pluginTableExists( 'migrations' ) );
		$this->assertNotNull( get_role( 'seocart_manager' ), 'The activation request created no role.' );
	}
}

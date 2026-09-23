<?php
/**
 * Tests the installation on a network: per site, lazy, never a loop, and undone only by deleting a site
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Tests\Support\KernelTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests read other sites' tables as the database holds them.

/**
 * On a network, each site installs itself: network activation installs the current site only,
 * another site installs on its own next request, a new site installs as soon as core has created
 * it, and deleting a site drops its plugin tables. Runs under WP_MULTISITE=1 and skips otherwise.
 *
 * The kernel booted by the test suite hooks the network's site events to the production
 * container. For this test those callbacks are replaced by the same hooks on the test's container.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In Lifecycle::activate(), install every site: `foreach ( get_sites( array( 'fields' => 'ids' ) ) as $id )
 *   { switch_to_blog( $id ); $this->installSite(); restore_current_blog(); }`: the second site is
 *   installed eagerly and the test fails.
 * - In Modules::kernelSubscribe(), hook `wp_initialize_site` at priority 5 instead of 20: the
 *   installation runs before core has created the new site's tables and options, and fails.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class MultisiteInstallTest extends KernelTestCase {

	/**
	 * The sites this test created, to delete afterwards.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $sites = array();

	/**
	 * The network's active plugins before the test.
	 *
	 * @since 0.1.0
	 *
	 * @var mixed
	 */
	private $activeBefore = null;

	/**
	 * Skips on a single site.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs on a network only: set WP_MULTISITE=1.' );
		}

		$this->activeBefore = get_site_option( 'active_sitewide_plugins' );
	}

	/**
	 * Deletes the sites the test created and restores the network's active plugins.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		foreach ( $this->sites as $site ) {
			if ( null !== get_site( $site ) ) {
				wp_delete_site( $site );
			}
		}

		if ( is_multisite() ) {
			if ( false === $this->activeBefore ) {
				delete_site_option( 'active_sitewide_plugins' );
			} else {
				update_site_option( 'active_sitewide_plugins', $this->activeBefore );
			}
		}

		parent::tear_down();
	}

	/**
	 * Tests the network's lifecycle end to end.
	 *
	 * @since 0.1.0
	 */
	public function test_each_site_of_a_network_installs_itself(): void {
		$container = $this->container();
		$this->hookSiteEventsTo( $container );

		$second = $this->createSite( 'kernel-second' );

		$this->networkActivate();
		$container->get( Lifecycle::class )->activate();

		$main = $this->storedRecord();

		$this->assertNotNull( $main, 'Network activation did not install the current site.' );
		$this->assertTrue( $this->pluginTableExists( 'migrations' ) );
		$this->assertSame( array(), $this->pluginTablesOf( $second ), 'Network activation installed another site eagerly.' );
		$this->assertNull( $this->recordOf( $second ) );

		switch_to_blog( $second );

		try {
			$container->get( Lifecycle::class )->reconcile();

			self::reloadRoles();
			$this->assertNotNull( get_role( 'seocart_manager' ), 'The second site\'s own roles were not created.' );
		} finally {
			restore_current_blog();
		}

		$this->assertContains( $this->tableOf( $second, 'migrations' ), $this->pluginTablesOf( $second ), 'The second site did not install itself on its next request.' );
		$this->assertNotNull( $this->recordOf( $second ) );
		$this->assertNotSame( $main, $this->recordOf( $second ), 'The second site shares the first site\'s identity.' );

		$third = $this->createSite( 'kernel-third' );

		$this->assertContains( $this->tableOf( $third, 'migrations' ), $this->pluginTablesOf( $third ), 'A new site was not installed when core created it.' );
		$this->assertNotNull( $this->recordOf( $third ) );

		wp_delete_site( $third );

		$this->assertSame( array(), $this->pluginTablesOf( $third ), 'Deleting a site left its plugin tables behind.' );

		$this->assertSame( $main, $this->storedRecord(), 'Another site\'s installation changed the first site\'s record.' );
		$this->assertTrue( $this->pluginTableExists( 'migrations' ) );
	}

	/**
	 * Tests that on a second site a capability the merchant removed stays removed when that site
	 * installs again: the installer's record is the site's own settings document.
	 *
	 * Planted violation: in OptionGrantLedger::record(), plant `return;` as the first line: the
	 * removed capability comes back.
	 *
	 * @since 0.1.0
	 */
	public function test_a_merchant_removal_on_a_second_site_survives_its_next_installation(): void {
		$container  = $this->container();
		$capability = 'seocart_view_orders';

		$this->hookSiteEventsTo( $container );

		$site = $this->createSite( 'kernel-removal' );

		switch_to_blog( $site );

		try {
			$container->get( Lifecycle::class )->installSite();
			self::reloadRoles();

			$this->assertTrue( get_role( 'seocart_manager' )->has_cap( $capability ), 'The second site\'s installation did not grant the capability, so its removal would prove nothing.' );

			get_role( 'seocart_manager' )->remove_cap( $capability );
			self::reloadRoles();

			$container->get( Lifecycle::class )->installSite();
			self::reloadRoles();

			$this->assertFalse( get_role( 'seocart_manager' )->has_cap( $capability ), "The merchant removed {$capability} on the second site, and its next installation granted it again." );
		} finally {
			restore_current_blog();
			self::reloadRoles();
		}

		$this->assertNull( get_role( 'seocart_manager' ), 'Installing the second site created roles on the first.' );
	}

	/**
	 * Replaces the production kernel's site-event callbacks with the same hooks on a test container.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container the callbacks resolve from.
	 */
	private function hookSiteEventsTo( Container $container ): void {
		global $wp_filter;

		foreach ( array( 'wp_initialize_site', 'wpmu_drop_tables' ) as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];

					if ( $function instanceof \Closure && str_ends_with( (string) ( new \ReflectionFunction( $function ) )->getFileName(), 'src/Platform/Kernel/Modules.php' ) ) {
						remove_filter( $hook, $function, $priority );
					}
				}
			}
		}

		Modules::subscribe( $container );
	}

	/**
	 * Marks the plugin active on every site of the network, as network activation does.
	 *
	 * @since 0.1.0
	 */
	private function networkActivate(): void {
		$active = get_site_option( 'active_sitewide_plugins', array() );

		$active[ plugin_basename( SEOCART_PLUGIN_FILE ) ] = time();

		update_site_option( 'active_sitewide_plugins', $active );
	}

	/**
	 * Creates a site of the network.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug The site's path segment.
	 * @return int The site id.
	 */
	private function createSite( string $slug ): int {
		$site = wp_insert_site(
			array(
				'domain' => get_network()->domain,
				'path'   => '/' . $slug . '/',
				'title'  => $slug,
			)
		);

		$this->assertIsInt( $site, is_wp_error( $site ) ? $site->get_error_message() : 'The site was not created.' );

		$this->sites[] = $site;

		return $site;
	}

	/**
	 * Lists a site's plugin tables.
	 *
	 * @since 0.1.0
	 *
	 * @param int $site The site id.
	 * @return list<string> The tables.
	 */
	private function pluginTablesOf( int $site ): array {
		global $wpdb;

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
					$wpdb->esc_like( $wpdb->get_blog_prefix( $site ) . 'seocart_' ) . '%'
				)
			)
		);
	}

	/**
	 * Returns the full name of a site's plugin table.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $site The site id.
	 * @param string $name The table's unprefixed name.
	 * @return string The name.
	 */
	private function tableOf( int $site, string $name ): string {
		global $wpdb;

		return $wpdb->get_blog_prefix( $site ) . 'seocart_' . $name;
	}

	/**
	 * Returns a site's boot record as the database stores it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $site The site id.
	 * @return string|null The text, or null when the site has none.
	 */
	private function recordOf( int $site ): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->get_blog_prefix( $site ) . 'options', BootOption::NAME ) );

		return null === $value ? null : (string) $value;
	}
}

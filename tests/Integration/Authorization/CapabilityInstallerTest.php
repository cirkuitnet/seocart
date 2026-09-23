<?php
/**
 * Tests that the installer grants the declaration once per site, and never undoes a merchant's edit
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Authorization;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Tests\Support\BootstrapProbes;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\Doubles\InMemoryGrantLedger;
use SEOCart\Tests\Support\PluginOwnership;
use SEOCart\Tests\Support\QueryCounter;
use SEOCart\Tests\Support\QueryLog;
use SEOCart\Tests\Support\ReloadsRoles;
use WP_UnitTestCase;

/**
 * The installer against real roles in a real database.
 *
 * Every test starts from the roles in the database and puts them back afterwards: the test
 * transaction rolls the option back, and the in-memory WP_Roles object is reloaded from it,
 * because WordPress keeps role changes in that object as well.
 *
 * Each test that guards a rule names the planted violation that must turn it red.
 *
 * @since 0.1.0
 */
final class CapabilityInstallerTest extends WP_UnitTestCase {

	use QueryCounter;
	use ReloadsRoles;

	/**
	 * The declaration the installer grants.
	 *
	 * @since 0.1.0
	 *
	 * @var CapabilityDeclaration
	 */
	private CapabilityDeclaration $declaration;

	/**
	 * Builds the declaration and reloads the roles from the database.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->declaration = new CapabilityDeclaration();

		self::reloadRoles();
	}

	/**
	 * Rolls the test back, then reloads the roles so the next test sees what the database holds.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Tests that a fresh site gets every shipped role with exactly its bundle, and the administrator every primitive.
	 *
	 * @since 0.1.0
	 */
	public function test_a_fresh_site_gets_every_role_and_bundle(): void {
		foreach ( $this->declaration->roles() as $role ) {
			if ( $this->declaration->isShippedRole( $role ) ) {
				$this->assertNull( get_role( $role ), "The test site already has the {$role} role, so it is not a fresh site. Roles committed by an earlier run survive the test installer; drop every table of the test database and run again." );
			}
		}

		$ledger  = new InMemoryGrantLedger();
		$granted = $this->installer( $ledger )->install();

		// Read the roles back from the database, not from the object the installer changed.
		self::reloadRoles();

		foreach ( $this->declaration->roles() as $role ) {
			$installed = get_role( $role );

			$this->assertNotNull( $installed, "The {$role} role is missing." );

			foreach ( $this->declaration->bundle( $role ) as $capability ) {
				$this->assertTrue( $installed->has_cap( $capability ), "The {$role} role lacks {$capability}." );
			}

			if ( $this->declaration->isShippedRole( $role ) ) {
				$expected = array_fill_keys( $this->declaration->bundle( $role ), true );

				ksort( $expected );
				ksort( $installed->capabilities );

				$this->assertSame( $expected, $installed->capabilities, "The {$role} role holds more or less than its bundle." );
				$this->assertSame( $this->declaration->roleName( $role ), wp_roles()->role_names[ $role ], 'A role name is stored untranslated and translated only when rendered.' );
			}

			$this->assertEqualsCanonicalizing( $this->declaration->bundle( $role ), $granted[ $role ] ?? array(), "The installer does not report what it granted to {$role}." );
		}

		$this->assertSame( $granted, $ledger->granted(), 'Everything granted must be recorded.' );
	}

	/**
	 * Tests that the second run changes nothing and issues no query.
	 *
	 * Planted violation: in CapabilityInstaller::install(), replace
	 * `$recorded = $this->ledger->granted();` with `$recorded = array();`. The second run then
	 * finds every pair due again, and records them again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_run_is_a_no_op(): void {
		$ledger    = new InMemoryGrantLedger();
		$installer = $this->installer( $ledger );

		$installer->install();

		$roles_before  = get_option( wp_roles()->role_key );
		$ledger_before = $ledger->granted();
		$record_calls  = $ledger->recordCalls();
		$granted_again = null;

		$queries = $this->captureQueries(
			static function () use ( $installer, &$granted_again ): void {
				$granted_again = $installer->install();
			}
		);

		$this->assertSame( array(), $granted_again, 'The second run granted something.' );
		$this->assertQueryCount( 0, $queries, 'Queries issued by the second run' );
		$this->assertSame( $record_calls, $ledger->recordCalls(), 'The second run recorded again.' );
		$this->assertSame( $ledger_before, $ledger->granted(), 'The second run changed the record.' );

		self::reloadRoles();

		$this->assertSame( $roles_before, get_option( wp_roles()->role_key ), 'The second run changed the roles.' );
	}

	/**
	 * Tests that a capability or a role a merchant removed is not granted again.
	 *
	 * Planted violation: the same as for the no-op test. The removed capabilities come back and
	 * the deleted role is created again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_merchant_removal_survives_a_rerun(): void {
		$ledger    = new InMemoryGrantLedger();
		$installer = $this->installer( $ledger );

		$installer->install();

		get_role( 'seocart_manager' )->remove_cap( 'seocart_manage_settings' );
		get_role( 'administrator' )->remove_cap( 'seocart_refund_orders' );
		remove_role( 'seocart_reporter' );

		// The plant must really be in place: the merchant's edits reached the roles.
		$this->assertFalse( get_role( 'seocart_manager' )->has_cap( 'seocart_manage_settings' ) );
		$this->assertNull( get_role( 'seocart_reporter' ) );

		$this->assertSame( array(), $installer->install(), 'The rerun granted something.' );

		self::reloadRoles();

		$this->assertFalse( get_role( 'seocart_manager' )->has_cap( 'seocart_manage_settings' ), 'A capability the merchant removed from a shipped role came back.' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'seocart_refund_orders' ), 'A capability the merchant removed from the administrator came back.' );
		$this->assertNull( get_role( 'seocart_reporter' ), 'A shipped role the merchant deleted was created again.' );
	}

	/**
	 * Tests that a run grants exactly the pairs no earlier run settled, as after an upgrade that adds a capability.
	 *
	 * @since 0.1.0
	 */
	public function test_only_pairs_never_settled_are_granted(): void {
		$first = new InMemoryGrantLedger();

		$this->installer( $first )->install();

		// An earlier version: the store managers and administrators never had marketing.
		get_role( 'seocart_manager' )->remove_cap( 'seocart_manage_marketing' );
		get_role( 'administrator' )->remove_cap( 'seocart_manage_marketing' );

		$earlier = $first->granted();

		foreach ( array( 'seocart_manager', 'administrator' ) as $role ) {
			$earlier[ $role ] = array_values( array_diff( $earlier[ $role ], array( 'seocart_manage_marketing' ) ) );
		}

		$ledger = new InMemoryGrantLedger( $earlier );

		$this->assertSame(
			array(
				'administrator'   => array( 'seocart_manage_marketing' ),
				'seocart_manager' => array( 'seocart_manage_marketing' ),
			),
			$this->installer( $ledger )->install()
		);

		$this->assertTrue( get_role( 'seocart_manager' )->has_cap( 'seocart_manage_marketing' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'seocart_manage_marketing' ) );
		$this->assertContains( 'seocart_manage_marketing', $ledger->granted()['seocart_manager'] );
	}

	/**
	 * Tests that a capability a role explicitly denies is left denied, and settled.
	 *
	 * @since 0.1.0
	 */
	public function test_an_explicit_denial_is_respected(): void {
		get_role( 'administrator' )->add_cap( 'seocart_manage_secrets', false );

		$ledger  = new InMemoryGrantLedger();
		$granted = $this->installer( $ledger )->install();

		$this->assertFalse( get_role( 'administrator' )->capabilities['seocart_manage_secrets'], 'The installer overrode an explicit denial.' );
		$this->assertNotContains( 'seocart_manage_secrets', $granted['administrator'] );
		$this->assertContains( 'seocart_manage_secrets', $ledger->granted()['administrator'], 'The denied pair must be settled, so no later run grants it either.' );
	}

	/**
	 * Tests that a core role the site does not have is never created.
	 *
	 * @since 0.1.0
	 */
	public function test_a_missing_core_role_is_not_created(): void {
		remove_role( 'administrator' );

		$ledger = new InMemoryGrantLedger();

		$this->installer( $ledger )->install();

		$this->assertNull( get_role( 'administrator' ), 'The installer created core\'s administrator role.' );
		$this->assertArrayNotHasKey( 'administrator', $ledger->granted(), 'Nothing was granted to the administrator, so nothing may be recorded for it.' );
		$this->assertNotNull( get_role( 'seocart_manager' ) );
	}

	/**
	 * Tests that an ordinary request neither loads the installer nor writes the roles option.
	 *
	 * The request is served in a child process by tests/Support/idle-request-probe.php, against
	 * the database as the suite installed it, with no shipped role present. Registering the roles
	 * on `init`, the pattern this rule forbids, shows up there twice: as the installer's file in
	 * the list of loaded files, and as an UPDATE of the roles option.
	 *
	 * Planted violation: in Kernel::boot(), directly after `self::$booted = true;`, add
	 * `add_action( 'init', static function (): void { ( new \SEOCart\Platform\Authorization\CapabilityInstaller( new \SEOCart\Platform\Authorization\CapabilityDeclaration(), new \SEOCart\Tests\Support\Doubles\InMemoryGrantLedger() ) )->install(); } );`.
	 * Run this test alone with the plant: the child process commits the roles it writes, and the
	 * test installer does not undo that, because it builds its roles object before it drops the
	 * tables and wp_install() writes that object back. After reverting the plant, drop every
	 * table of the test database, so that the next run installs from scratch.
	 *
	 * @since 0.1.0
	 */
	public function test_an_ordinary_request_neither_loads_the_installer_nor_writes_the_roles(): void {
		$report  = self::serveOrdinaryRequestInChildProcess();
		$queries = QueryLog::fromWpdb( $report['queries'] );
		$owner   = PluginOwnership::fromComposerManifest( self::pluginDirectory() );
		$file    = $owner->relativePath( (string) ( new \ReflectionClass( CapabilityInstaller::class ) )->getFileName() );

		$this->assertTrue( $report['plugin_loaded'], 'The probe did not load SEOCart, so a clean result would prove nothing.' );
		$this->assertGreaterThan( 0, count( $queries ), 'The probe recorded no query at all, so a clean result would prove nothing.' );

		$this->assertQueryCount(
			0,
			$queries->ofType( 'INSERT', 'UPDATE', 'REPLACE', 'DELETE' )->matching( '/' . preg_quote( wp_roles()->role_key, '/' ) . '/' ),
			'Writes to the roles option on an ordinary request'
		);

		$this->assertArrayNotHasKey( $file, $report['files'], "An ordinary request loaded the installer. The plugin files it loaded:\n" . BootstrapProbes::describeFiles( $report['files'] ) );
	}

	/**
	 * Tests that the installer acts on the current site only, so each site of a network gets its own install.
	 *
	 * Runs only when the suite runs as multisite: `WP_MULTISITE=1 composer test:integration`.
	 *
	 * @since 0.1.0
	 */
	public function test_it_installs_per_site_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs only as multisite: WP_MULTISITE=1 composer test:integration.' );
		}

		$second_site = self::factory()->blog->create();

		switch_to_blog( $second_site );
		$granted = $this->installer( new InMemoryGrantLedger() )->install();
		$this->assertNotNull( get_role( 'seocart_manager' ), 'The second site did not get the shipped roles.' );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'seocart_view_orders' ) );
		restore_current_blog();

		$this->assertNotSame( array(), $granted );
		$this->assertNull( get_role( 'seocart_manager' ), 'Installing on the second site changed the first one.' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'seocart_view_orders' ), 'Installing on the second site changed the first one.' );
	}

	/**
	 * Creates an installer for the declaration.
	 *
	 * @since 0.1.0
	 *
	 * @param InMemoryGrantLedger $ledger The record of the current site.
	 * @return CapabilityInstaller The installer.
	 */
	private function installer( InMemoryGrantLedger $ledger ): CapabilityInstaller {
		return new CapabilityInstaller( $this->declaration, $ledger );
	}

	/**
	 * Serves the front page in a child PHP process, with the plugin loaded, and returns the probe's report.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The report of tests/Support/idle-request-probe.php: plugin_loaded,
	 *                              queries_run, queries, files and hooks.
	 */
	private static function serveOrdinaryRequestInChildProcess(): array {
		return ChildProcessProbe::run( self::pluginDirectory() . '/tests/Support/idle-request-probe.php', array( 'with-plugin' ) );
	}

	/**
	 * Returns the plugin directory of this checkout.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	private static function pluginDirectory(): string {
		return dirname( __DIR__, 3 );
	}
}

<?php
/**
 * Tests the capability installer with the production grant ledger, on one site and on a network
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Settings;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Authorization\OptionGrantLedger;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\QueryCounter;
use SEOCart\Tests\Support\ReloadsRoles;
use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test reads the options table itself, to see what the ledger really stored.

/**
 * The installer against real roles, with its record in the real options table.
 *
 * Every run of the installer gets a new ledger over a new store, after the object cache is
 * flushed, as a later request would: nothing can be remembered in memory between runs, so what
 * the record holds is what the table holds. The harness rolls each test back, and the roles are
 * reloaded from the database around it.
 *
 * @since 0.1.0
 */
final class OptionGrantLedgerTest extends WP_UnitTestCase {

	use QueryCounter;
	use ReloadsRoles;

	/**
	 * The option the record lives in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const OPTION = 'seocart_capability_grants';

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
	 * Tests that a site that never ran the installer has an empty record.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_never_installed_has_an_empty_record(): void {
		$this->assertSame( array(), self::ledger()->granted() );
		$this->assertNull( self::storedRecord(), 'Reading the record wrote it.' );
	}

	/**
	 * Tests that the first run records what it settled in the site's document, not autoloaded.
	 *
	 * @since 0.1.0
	 */
	public function test_the_first_run_records_every_settled_pair(): void {
		$granted = $this->installer()->install();

		$this->assertNotSame( array(), $granted );

		$record = self::storedRecord();

		$this->assertNotNull( $record, 'The record was not stored.' );
		$this->assertSame( 'off', $record['autoload'], 'The record must never be autoloaded.' );

		$document = json_decode( $record['value'], true );

		$this->assertSame( 1, $document['version'], 'The first run should write the record once.' );

		foreach ( $this->declaration->roles() as $role ) {
			$expected = $this->declaration->bundle( $role );

			sort( $expected );

			$this->assertSame( implode( ' ', $expected ), $document['values'][ $role ], "The record of {$role} is not its bundle, sorted." );
		}

		$this->assertEqualsCanonicalizing( array_keys( $granted ), array_keys( self::ledger()->granted() ) );
	}

	/**
	 * Tests that the installer run twice with the stored record is a no-op the second time: no grant, no write.
	 *
	 * Planted violation: in SettingsStore::document(), return `new SettingsDocument( 0, array() )`.
	 * The record is then never read back: the second run finds every pair due again and tries to
	 * create a record that already exists. A ledger whose granted() alone forgets the record is
	 * the merchant-removal test's: this run would still write nothing, because the installer adds
	 * no capability a role holds and record() writes no pair it holds.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_run_is_a_no_op(): void {
		$this->installer()->install();

		$roles_before  = self::rolesOption();
		$record_before = self::storedRecord();
		$granted_again = null;

		self::reloadRoles();

		$installer = $this->installer();
		$queries   = $this->captureQueries(
			static function () use ( $installer, &$granted_again ): void {
				$granted_again = $installer->install();
			}
		);

		$this->assertSame( array(), $granted_again, 'The second run granted something.' );
		$this->assertQueryCount( 0, $queries->ofType( 'INSERT', 'UPDATE', 'REPLACE', 'DELETE' ), 'Writes issued by the second run' );
		$this->assertSame( $record_before, self::storedRecord(), 'The second run changed the record.' );

		self::reloadRoles();

		$this->assertSame( $roles_before, self::rolesOption(), 'The second run changed the roles.' );
	}

	/**
	 * Tests that a capability or a role a merchant removed is not granted again by a later run.
	 *
	 * Planted violation: in OptionGrantLedger::granted(), return `array()`. The removed
	 * capabilities come back and the deleted role is created again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_merchant_removal_survives_a_rerun(): void {
		$this->installer()->install();

		get_role( 'seocart_manager' )->remove_cap( 'seocart_manage_settings' );
		get_role( 'administrator' )->remove_cap( 'seocart_refund_orders' );
		remove_role( 'seocart_reporter' );

		self::reloadRoles();

		// The plant must really be in place: the merchant's edits reached the stored roles.
		$this->assertFalse( get_role( 'seocart_manager' )->has_cap( 'seocart_manage_settings' ) );
		$this->assertNull( get_role( 'seocart_reporter' ) );

		$this->assertSame( array(), $this->installer()->install(), 'The rerun granted something.' );

		self::reloadRoles();

		$this->assertFalse( get_role( 'seocart_manager' )->has_cap( 'seocart_manage_settings' ), 'A capability the merchant removed from a shipped role came back.' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'seocart_refund_orders' ), 'A capability the merchant removed from the administrator came back.' );
		$this->assertNull( get_role( 'seocart_reporter' ), 'A shipped role the merchant deleted was created again.' );
	}

	/**
	 * Tests that recording pairs already recorded writes nothing, and new pairs are merged in.
	 *
	 * @since 0.1.0
	 */
	public function test_a_record_is_a_merge(): void {
		self::ledger()->record( array( 'seocart_reporter' => array( 'seocart_view_orders', 'read' ) ) );

		$before  = self::storedRecord();
		$ledger  = self::ledger();
		$queries = $this->captureQueries(
			static function () use ( $ledger ): void {
				$ledger->record( array( 'seocart_reporter' => array( 'read' ) ) );
			}
		);

		$this->assertQueryCount( 0, $queries->ofType( 'INSERT', 'UPDATE', 'REPLACE', 'DELETE' ), 'Recording a recorded pair' );
		$this->assertSame( $before, self::storedRecord() );

		self::ledger()->record(
			array(
				'seocart_reporter' => array( 'seocart_view_reports' ),
				'seocart_customer' => array(),
			)
		);

		$this->assertSame(
			array(
				'seocart_reporter' => array( 'read', 'seocart_view_orders', 'seocart_view_reports' ),
				'seocart_customer' => array(),
			),
			self::ledger()->granted(),
			'A role recorded with nothing settled must still count as recorded.'
		);
	}

	/**
	 * Tests that a role the capability declaration does not declare cannot be recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_an_undeclared_role_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		self::ledger()->record( array( 'editor' => array( 'seocart_view_orders' ) ) );
	}

	/**
	 * Tests that each site of a network keeps its own record.
	 *
	 * Runs only when the suite runs as multisite: `WP_MULTISITE=1 composer test:integration`.
	 *
	 * @since 0.1.0
	 */
	public function test_each_site_keeps_its_own_record(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs only as multisite: WP_MULTISITE=1 composer test:integration.' );
		}

		$second_site = self::factory()->blog->create();

		$this->installer()->install();
		get_role( 'seocart_manager' )->remove_cap( 'seocart_manage_settings' );

		switch_to_blog( $second_site );
		self::reloadRoles();

		$this->assertSame( array(), self::ledger()->granted(), 'The second site sees the first site\'s record.' );

		$granted = $this->installer()->install();

		self::reloadRoles();

		$this->assertTrue( get_role( 'seocart_manager' )->has_cap( 'seocart_manage_settings' ), 'The first site\'s removal was applied to the second site.' );
		$this->assertArrayHasKey( 'seocart_manager', $granted );
		$this->assertNotNull( self::storedRecord(), 'The second site has no record of its own.' );

		restore_current_blog();
		self::reloadRoles();

		$this->assertSame( 1, json_decode( (string) self::storedRecord()['value'], true )['version'], 'Installing on the second site wrote the first site\'s record.' );
		$this->assertSame( array(), $this->installer()->install(), 'The first site\'s rerun granted something.' );
		$this->assertFalse( get_role( 'seocart_manager' )->has_cap( 'seocart_manage_settings' ) );
	}

	/**
	 * Creates an installer with a ledger of its own, as a later request would.
	 *
	 * @since 0.1.0
	 *
	 * @return CapabilityInstaller The installer.
	 */
	private function installer(): CapabilityInstaller {
		return new CapabilityInstaller( $this->declaration, self::ledger() );
	}

	/**
	 * Creates the production ledger of the current site over a fresh store, after flushing the object cache.
	 *
	 * @since 0.1.0
	 *
	 * @return OptionGrantLedger The ledger.
	 */
	private static function ledger(): OptionGrantLedger {
		global $wpdb;

		wp_cache_flush();

		$database = new Database(
			$wpdb,
			true,
			static function ( string $code ): void {
				throw new \LogicException( 'The database wrapper reported ' . $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test failure, never rendered.
			}
		);

		return new OptionGrantLedger( new SettingsStore( Settings::registry(), $database ), $database );
	}

	/**
	 * Reads the record of the current site from its options table.
	 *
	 * @since 0.1.0
	 *
	 * @return array{value: string, autoload: string}|null The row, or null when there is none.
	 */
	private static function storedRecord(): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ), ARRAY_A );

		return is_array( $row ) ? array(
			'value'    => (string) $row['option_value'],
			'autoload' => (string) $row['autoload'],
		) : null;
	}

	/**
	 * Reads the roles option of the current site from its options table.
	 *
	 * @since 0.1.0
	 *
	 * @return string The stored value.
	 */
	private static function rolesOption(): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, wp_roles()->role_key ) );
	}
}

<?php
/**
 * KernelTestCase: the base of the kernel's integration tests, which install the plugin for real
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This base class reads and restores committed options around each test on purpose.

/**
 * A DatabaseTestCase that also puts back what an installation writes outside the plugin's tables.
 *
 * Owns one fact: how a test that really installs the plugin leaves the site as it found it. An
 * installation commits the plugin's tables, which DatabaseTestCase drops, and options, which it
 * does not: the boot record and any other `seocart_` option, and the roles option the capability
 * installer writes. So the plugin's options are removed before and after every test, the roles
 * option is restored to the text it had before the test, and the in-memory roles are rebuilt
 * from it.
 *
 * Tests get the production container with their own connection and a recording reporter
 * (container()), a record written through the real writer (plantRecord()), and the stored text
 * as the database holds it (storedRecord()).
 *
 * @since 0.1.0
 */
abstract class KernelTestCase extends DatabaseTestCase {

	use ReloadsRoles;

	/**
	 * The roles option as stored before the test, or null when it did not exist.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $rolesBefore = null;

	/**
	 * Removes any boot record and remembers the roles.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->deleteRecord();
		$this->rolesBefore = $this->storedOption( $this->rolesOption() );

		self::reloadRoles();
	}

	/**
	 * Removes the boot record and puts the roles back.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $wpdb;

		$this->deleteRecord();

		if ( null === $this->rolesBefore ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $this->rolesOption() ) );
		} else {
			$wpdb->update( $wpdb->options, array( 'option_value' => $this->rolesBefore ), array( 'option_name' => $this->rolesOption() ) );
		}

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Returns the production container with this test's connection and reporter.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, callable(Container): object> $overrides Optional. Replacements. Default none.
	 * @return Container The container.
	 */
	protected function container( array $overrides = array() ): Container {
		return KernelContainer::build( $this->db, $this->reporter(), $overrides );
	}

	/**
	 * Writes the current site's boot record through the real writer, whatever was stored before.
	 *
	 * @since 0.1.0
	 *
	 * @param BootRecord $record The record to store; its revision is the writer's to set.
	 * @return BootRecord The record as stored.
	 */
	protected function plantRecord( BootRecord $record ): BootRecord {
		return $this->changeRecord( static fn(): BootRecord => $record );
	}

	/**
	 * Changes the current site's boot record through the real writer.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(BootRecord): BootRecord $change Derives the record from the current one.
	 * @return BootRecord The record as stored.
	 */
	protected function changeRecord( callable $change ): BootRecord {
		return ( new BootOption( $this->db, $this->reporter() ) )->mutate( $change );
	}

	/**
	 * Returns a record with a complete identity and a lock mode, as every stored record has, for planting.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $head Optional. The schema head to record. Default none.
	 * @return BootRecord The record, not yet stored.
	 */
	protected static function installedRecord( ?string $head = null ): BootRecord {
		$record = BootRecord::absent()
			->withInstallUuid( '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b' )
			->withHomeUrl( home_url() )
			->withLockMode( LockMode::GetLock )
			->withInstalledAt( '2026-09-23T12:00:00Z' )
			->withPluginVersion( SEOCART_VERSION );

		return null === $head ? $record : $record->withSchemaHead( $head );
	}

	/**
	 * Returns the schema head the production code installs: the data registry's last migration.
	 *
	 * @since 0.1.0
	 *
	 * @return string The migration id.
	 */
	protected static function codeHead(): string {
		$chain = OwnedData::registry()->migrations();

		return $chain[ count( $chain ) - 1 ]->id();
	}

	/**
	 * Returns the current site's boot record as the database stores it, past every cache.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The text, or null when there is no record.
	 */
	protected function storedRecord(): ?string {
		return $this->storedOption( BootOption::NAME );
	}

	/**
	 * Returns an option of the current site as the database stores it, past every cache.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The option name.
	 * @return string|null The text, or null when there is no such option.
	 */
	protected function storedOption( string $name ): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Tells whether a plugin table exists on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The table's unprefixed name, such as `migrations`.
	 * @return bool True when it exists.
	 */
	protected function pluginTableExists( string $name ): bool {
		return in_array( $this->db->table( $name ), $this->pluginTables(), true );
	}

	/**
	 * Deletes the current site's plugin options, the boot record among them, and forgets every cached copy.
	 *
	 * @since 0.1.0
	 */
	protected function deleteRecord(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'seocart_' ) . '%' ) );
		wp_cache_flush();
	}

	/**
	 * Returns the name of the current site's roles option.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `wptests_user_roles`.
	 */
	private function rolesOption(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'user_roles';
	}
}

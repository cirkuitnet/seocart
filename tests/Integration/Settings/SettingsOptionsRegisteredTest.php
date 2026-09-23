<?php
/**
 * Tests that every option the settings store writes on a site is one the data registry lists
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Settings;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\DataRegistry\OptionDefinition;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Platform\Settings\Storage;
use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test reads the options table itself, to see what the store really wrote.

/**
 * The data registry's options coverage, for the options the settings module writes.
 *
 * The data registry's own coverage test scans the options table of the test site, where nothing
 * has activated the plugin yet, so no settings option is there to find. This test writes every
 * production setting first — each independent option and each document, with its field's
 * example — and then requires every `seocart_` option in the table to be one the production data
 * registry lists, with the autoload flag it lists. The harness rolls the writes back.
 *
 * Planted violation: in OwnedData::registry(), pass
 * `array_slice( Settings::registry()->optionDefinitions(), 1 )`. The base currency's option is
 * then in the table and missing from the registry.
 *
 * @since 0.1.0
 */
final class SettingsOptionsRegisteredTest extends WP_UnitTestCase {

	/**
	 * Tests that every option the store writes is registered, as it is stored.
	 *
	 * @since 0.1.0
	 */
	public function test_every_option_the_store_writes_is_registered(): void {
		global $wpdb;

		$settings = Settings::registry();
		$store    = new SettingsStore(
			$settings,
			new Database(
				$wpdb,
				true,
				static function ( string $code ): void {
					throw new \LogicException( 'The database wrapper reported ' . $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test failure, never rendered.
				}
			)
		);

		foreach ( $settings->options() as $held ) {
			$examples = array();

			foreach ( $held as $setting ) {
				$examples[ $setting->name() ] = $setting->field()->example();
			}

			if ( Storage::Document === $held[0]->storage() ) {
				$store->replaceDocument( $held[0]->group(), 0, $examples );
			} else {
				$store->writeScalars( $examples );
			}
		}

		$registered = array();

		foreach ( OwnedData::registry()->options() as $option ) {
			$registered[ $option->name() ] = $option;
		}

		$stored = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, autoload FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( 'seocart_' ) . '%' ), ARRAY_A );

		$this->assertCount( count( $settings->options() ), $stored, 'The store did not write every settings option, so a clean result would prove nothing.' );

		foreach ( $stored as $row ) {
			$name = (string) $row['option_name'];

			$this->assertArrayHasKey( $name, $registered, "The option {$name} is in the options table, but no module registers it." );
			$this->assertInstanceOf( OptionDefinition::class, $registered[ $name ] );
			$this->assertFalse( $registered[ $name ]->autoloads(), "The data registry says {$name} autoloads." );
			$this->assertSame( 'off', (string) $row['autoload'], "The option {$name} is stored with autoload {$row['autoload']}, not off as registered." );
		}
	}
}

<?php
/**
 * Tests the settings store against a real options table: reads, scalar writes and document writes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Settings;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Support\QueryCounter;
use SEOCart\Tests\Support\SettingsFixtures;
use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test reads the options table itself, to see what the store really wrote.

/**
 * The store over the fixture settings, one test at a time, each rolled back by the harness.
 *
 * What a row holds is read from the table, not through the store, so a store that only pretended
 * to write could not pass. Concurrent writers are the concurrency test's.
 *
 * @since 0.1.0
 */
final class SettingsStoreTest extends WP_UnitTestCase {

	use QueryCounter;

	/**
	 * The store under test.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * Every code the database wrapper reported.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $reports = array();

	/**
	 * Builds the store over the fixture settings.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		$this->reports = array();
		$this->store   = new SettingsStore(
			SettingsFixtures::registry(),
			new Database(
				$wpdb,
				true,
				function ( string $code ): void {
					$this->reports[] = $code;
				}
			)
		);
	}

	/**
	 * Checks that the database wrapper reported nothing.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		parent::tear_down();

		$this->assertSame( array(), $this->reports, 'The database wrapper reported something.' );
	}

	/**
	 * Tests that a setting never saved reads as its default, and one without a default as null.
	 *
	 * @since 0.1.0
	 */
	public function test_a_setting_never_saved_reads_as_its_default(): void {
		$this->assertSame(
			array(
				'hold_minutes'    => 15,
				'weight_unit'     => 'kg',
				'default_market'  => SettingsFixtures::DEFAULT_MARKET,
				'store_name'      => 'Shop',
				'ledger_currency' => 'USD',
				'mode'            => 'test',
				'account'         => null,
				'retries'         => 2,
			),
			$this->store->values( SettingsFixtures::registry()->all() )
		);

		$document = $this->store->document( SettingsFixtures::DOCUMENT );

		$this->assertSame( 0, $document->version(), 'A document never written is version 0.' );
		$this->assertSame( array(), $document->values() );
	}

	/**
	 * Tests that reading a group costs one query, however many options it has, and none once cached.
	 *
	 * Planted violation: in SettingsStore::values(), remove the call to `$this->prime( … )`. Each
	 * of the four options is then read with a query of its own.
	 *
	 * @since 0.1.0
	 */
	public function test_reading_a_group_costs_one_query_and_then_none(): void {
		$this->store->writeScalars(
			array(
				'hold_minutes' => 30,
				'weight_unit'  => 'lb',
			)
		);

		wp_cache_flush();

		// Every request has loaded the autoloaded options before any plugin code runs.
		wp_load_alloptions();

		$group = SettingsFixtures::registry()->group( SettingsFixtures::SCALARS );
		$first = $this->captureQueries(
			function () use ( $group ): void {
				$this->store->values( $group );
			}
		);

		$this->assertQueryCount( 1, $first, 'Reading a group of four options' );
		$this->assertQueryCount( 1, $first->matching( '/^SELECT option_name, option_value FROM \S+ WHERE option_name IN \(/' ), 'The priming query that reads the group' );

		$again = $this->captureQueries(
			function () use ( $group ): void {
				$this->store->values( $group );
			}
		);

		$this->assertQueryCount( 0, $again, 'Reading the group again' );
		$this->assertSame( 30, $this->store->value( 'hold_minutes' ), 'An integer is read back as an integer, not as the text the table holds.' );
	}

	/**
	 * Tests that each scalar is written to its own option, as text, with autoload off.
	 *
	 * @since 0.1.0
	 */
	public function test_each_scalar_is_its_own_option_with_autoload_off(): void {
		$this->store->writeScalars(
			array(
				'hold_minutes' => 45,
				'weight_unit'  => 'oz',
			)
		);

		$this->assertSame(
			array(
				'seocart_fixture_scalars_hold_minutes' => array( '45', 'off' ),
				'seocart_fixture_scalars_weight_unit'  => array( 'oz', 'off' ),
			),
			self::rows( 'seocart_fixture_scalars_%' )
		);

		wp_cache_flush();

		$this->assertSame( 45, $this->store->value( 'hold_minutes' ) );
		$this->assertSame( 'oz', $this->store->value( 'weight_unit' ) );
		$this->assertSame( 'Shop', $this->store->value( 'store_name' ), 'A setting not written keeps its default.' );
	}

	/**
	 * Tests that a write refused for one value writes none of the others.
	 *
	 * @since 0.1.0
	 */
	public function test_a_scalar_write_refused_for_one_value_writes_none(): void {
		try {
			$this->store->writeScalars(
				array(
					'weight_unit'     => 'lb',
					'ledger_currency' => 'XYZ',
				)
			);
			$this->fail( 'An unknown currency was written.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( SupportError::UnknownCurrency, $refused->errorCode() );
		}

		try {
			$this->store->writeScalars(
				array(
					'weight_unit'  => 'lb',
					'hold_minutes' => 0,
				)
			);
			$this->fail( 'A value below the minimum was written.' );
		} catch ( \InvalidArgumentException $refused ) {
			$this->assertStringContainsString( 'hold_minutes', $refused->getMessage() );
		}

		$this->assertSame( array(), self::rows( 'seocart_fixture_%' ), 'A refused write left an option behind.' );
	}

	/**
	 * Tests that a document is created at version 1, replaced whole at the next, and not autoloaded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_document_is_written_whole_at_the_next_version(): void {
		$first = $this->store->replaceDocument(
			SettingsFixtures::DOCUMENT,
			0,
			array(
				'mode'    => 'live',
				'account' => 'acct_1',
			)
		);

		$this->assertSame( 1, $first->version() );
		$this->assertSame(
			array( 'seocart_fixture_document' => array( '{"version":1,"values":{"mode":"live","account":"acct_1"}}', 'off' ) ),
			self::rows( 'seocart_fixture_document' ),
			'A document is JSON with its version and its values, stored with autoload off.'
		);

		$second = $this->store->replaceDocument( SettingsFixtures::DOCUMENT, 1, array( 'retries' => 4 ) );

		$this->assertSame( 2, $second->version() );
		$this->assertSame( array( 'retries' => 4 ), $second->values() );
		$this->assertSame(
			array(
				'mode'    => 'test',
				'account' => null,
				'retries' => 4,
			),
			$this->store->values( SettingsFixtures::registry()->group( SettingsFixtures::DOCUMENT ) ),
			'A setting left out of a replacement reads as its default.'
		);
	}

	/**
	 * Tests that a write naming a version other than the stored one loses and changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider staleVersions
	 *
	 * @param int $stale The version the writer claims to have read.
	 */
	public function test_a_stale_version_loses_and_changes_nothing( int $stale ): void {
		$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'mode' => 'live' ) );
		$this->store->replaceDocument(
			SettingsFixtures::DOCUMENT,
			1,
			array(
				'mode'    => 'live',
				'retries' => 1,
			)
		);

		$before = self::rows( 'seocart_fixture_document' );

		try {
			$this->store->replaceDocument( SettingsFixtures::DOCUMENT, $stale, array( 'mode' => 'test' ) );
			$this->fail( "A write based on version {$stale} replaced version 2." );
		} catch ( CodedException $lost ) {
			$this->assertSame( SettingsError::VersionConflict, $lost->errorCode() );
			$this->assertSame( array( 'group' => SettingsFixtures::DOCUMENT ), $lost->context() );
		}

		$this->assertSame( $before, self::rows( 'seocart_fixture_document' ), 'The losing write changed the row.' );
		$this->assertSame( 2, $this->store->document( SettingsFixtures::DOCUMENT )->version() );
	}

	/**
	 * Returns versions that are not the stored version 2.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: int}> The cases.
	 */
	public static function staleVersions(): array {
		return array(
			'a document never written' => array( 0 ),
			'the version before'       => array( 1 ),
			'a version never written'  => array( 3 ),
		);
	}

	/**
	 * Tests that a document write refused for one value writes nothing, not even the valid values.
	 *
	 * @since 0.1.0
	 */
	public function test_a_document_write_refused_for_one_value_writes_nothing(): void {
		$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'mode' => 'test' ) );

		$before = self::rows( 'seocart_fixture_document' );

		foreach ( array( array( 'retries' => 9 ), array( 'nope' => 'x' ) ) as $bad ) {
			try {
				$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 1, array( 'mode' => 'live' ) + $bad );
				$this->fail( 'A document with a refused value was written: ' . (string) wp_json_encode( $bad ) );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( (string) array_key_first( $bad ), $refused->getMessage() );
			}
		}

		$this->assertSame( $before, self::rows( 'seocart_fixture_document' ), 'Half of a refused document was saved.' );
	}

	/**
	 * Tests that a setting is written only as it is stored: a scalar by name, a document whole.
	 *
	 * @since 0.1.0
	 */
	public function test_a_setting_is_written_only_the_way_it_is_stored(): void {
		foreach (
			array(
				static fn( SettingsStore $store ) => $store->writeScalars( array( 'mode' => 'live' ) ),
				static fn( SettingsStore $store ) => $store->replaceDocument( SettingsFixtures::SCALARS, 0, array( 'weight_unit' => 'lb' ) ),
				static fn( SettingsStore $store ) => $store->writeScalars( array( 'nope' => 'x' ) ),
			) as $index => $write
		) {
			try {
				$write( $this->store );
				$this->fail( "Write {$index} was accepted." );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertNotSame( '', $refused->getMessage() );
			}
		}

		$this->assertSame( array(), self::rows( 'seocart_fixture_%' ) );
	}

	/**
	 * Tests that a read of what is stored goes past a cache that lags behind the database.
	 *
	 * The cache is made to hold an older value, as a persistent cache could after another request
	 * wrote. values() is served the cached value; valuesAsStored() and documentAsStored() read the
	 * table, and cache nothing.
	 *
	 * Planted violation: in SettingsStore::storedValues(), ignore `$as_stored`. valuesAsStored() is
	 * then served the cached value.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_of_what_is_stored_goes_past_the_cache(): void {
		$weight = SettingsFixtures::registry()->setting( 'weight_unit' );

		$this->store->writeScalars( array( 'weight_unit' => 'lb' ) );
		$this->assertSame( 'lb', $this->store->value( 'weight_unit' ) );

		wp_cache_set( $weight->optionName(), 'oz', 'options' );

		$this->assertSame( array( 'weight_unit' => 'oz' ), $this->store->values( array( $weight ) ), 'The cache was not stale, so the test proves nothing.' );
		$this->assertSame( array( 'weight_unit' => 'lb' ), $this->store->valuesAsStored( array( $weight ) ) );
		$this->assertSame( 'oz', wp_cache_get( $weight->optionName(), 'options' ), 'Reading what is stored cached it.' );
	}

	/**
	 * Tests that an independent setting is swapped only while it holds the value read.
	 *
	 * @since 0.1.0
	 */
	public function test_a_scalar_is_swapped_only_while_it_holds_the_value_read(): void {
		$this->store->writeScalars( array( 'weight_unit' => 'lb' ) );

		$this->assertFalse( $this->store->swapScalar( 'weight_unit', 'kg', 'oz' ), 'A swap from a value the option no longer holds went through.' );
		$this->assertSame( array( 'seocart_fixture_scalars_weight_unit' => array( 'lb', 'off' ) ), self::rows( 'seocart_fixture_scalars_weight_unit' ) );
		$this->assertTrue( $this->store->swapScalar( 'weight_unit', 'lb', 'oz' ) );
		$this->assertSame( 'oz', $this->store->value( 'weight_unit' ) );

		try {
			$this->store->swapScalar( 'weight_unit', 'oz', 'stone' );
			$this->fail( 'A swap to a value the setting refuses went through.' );
		} catch ( \InvalidArgumentException $refused ) {
			$this->assertSame( 'oz', $this->store->value( 'weight_unit' ) );
		}
	}

	/**
	 * Tests that a document is read under a lock only inside a transaction, where the lock would last.
	 *
	 * @since 0.1.0
	 */
	public function test_a_document_is_read_under_a_lock_only_inside_a_transaction(): void {
		foreach ( array( 'documentForShare', 'documentForUpdate' ) as $read ) {
			try {
				$this->store->$read( SettingsFixtures::DOCUMENT );
				$this->fail( "{$read}() read outside a transaction." );
			} catch ( \LogicException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * Tests that the stored text of one setting is read past a broken neighbour, and past the cache.
	 *
	 * @since 0.1.0
	 */
	public function test_a_settings_stored_text_is_read_past_its_neighbours(): void {
		$this->assertNull( $this->store->storedText( 'account' ) );
		$this->assertNull( $this->store->storedText( 'weight_unit' ) );

		$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'account' => 'acct_1' ) );
		$this->store->writeScalars( array( 'weight_unit' => 'lb' ) );

		self::storeRaw( 'seocart_fixture_scalars_hold_minutes', 'many' );

		global $wpdb;

		$wpdb->update( $wpdb->options, array( 'option_value' => '{"version":1,"values":{"account":"acct_1","retries":99}}' ), array( 'option_name' => 'seocart_fixture_document' ) );
		wp_cache_set( 'seocart_fixture_scalars_weight_unit', 'oz', 'options' );

		$this->assertSame( 'acct_1', $this->store->storedText( 'account' ), 'A broken neighbour hid the text.' );
		$this->assertFalse( $this->store->storedText( 'retries' ), 'A number was read as text.' );
		$this->assertNull( $this->store->storedText( 'mode' ) );
		$this->assertSame( 'lb', $this->store->storedText( 'weight_unit' ), 'The cache was read.' );
		$this->assertSame( 'many', $this->store->storedText( 'hold_minutes' ) );

		$wpdb->update( $wpdb->options, array( 'option_value' => 'not json' ), array( 'option_name' => 'seocart_fixture_document' ) );

		$this->assertFalse( $this->store->storedText( 'account' ) );
	}

	/**
	 * Tests that an option holding something its setting cannot hold is reported by name, never used.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider unusableOptions
	 *
	 * @param string $option The option.
	 * @param string $stored What it holds.
	 * @param string $read   The setting read.
	 */
	public function test_an_unusable_option_is_reported( string $option, string $stored, string $read ): void {
		self::storeRaw( $option, $stored );

		try {
			$this->store->value( $read );
			$this->fail( "The option {$option} holding {$stored} was used." );
		} catch ( CodedException $reported ) {
			$this->assertSame( SettingsError::StoredValueInvalid, $reported->errorCode() );
			$this->assertSame( array( 'option' => $option ), $reported->context() );
		}
	}

	/**
	 * Returns options holding what their settings cannot hold.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}> The cases.
	 */
	public static function unusableOptions(): array {
		return array(
			'text for an integer'          => array( 'seocart_fixture_scalars_hold_minutes', 'many', 'hold_minutes' ),
			'an unknown currency'          => array( 'seocart_fixture_money_ledger_currency', 'XYZ', 'ledger_currency' ),
			'a document that is not JSON'  => array( 'seocart_fixture_document', 'mode=live', 'mode' ),
			'a document without a version' => array( 'seocart_fixture_document', '{"values":{"mode":"live"}}', 'mode' ),
			'a document at version 0'      => array( 'seocart_fixture_document', '{"version":0,"values":{}}', 'mode' ),
			'a document with extra keys'   => array( 'seocart_fixture_document', '{"version":1,"values":{},"mode":"live"}', 'mode' ),
			'a document value refused'     => array( 'seocart_fixture_document', '{"version":1,"values":{"mode":"sandbox"}}', 'mode' ),
		);
	}

	/**
	 * Tests that a value a document holds for a setting no longer declared is ignored, and dropped by the next write.
	 *
	 * @since 0.1.0
	 */
	public function test_a_value_no_longer_declared_is_dropped(): void {
		self::storeRaw( 'seocart_fixture_document', '{"version":3,"values":{"mode":"live","legacy":"x"}}' );

		$document = $this->store->document( SettingsFixtures::DOCUMENT );

		$this->assertSame( 3, $document->version() );
		$this->assertSame( array( 'mode' => 'live' ), $document->values() );

		$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 3, $document->values() );

		$this->assertSame( array( 'seocart_fixture_document' => array( '{"version":4,"values":{"mode":"live"}}', 'off' ) ), self::rows( 'seocart_fixture_document' ) );
	}

	/**
	 * Tests that writing a scalar costs one statement, whether the value is new, changed or unchanged.
	 *
	 * The statement also sets autoload off, so no query is needed to check the flag.
	 *
	 * Planted violation: in SettingsStore::writeScalars(), call `wp_set_option_autoload( $option, false )`
	 * after the statement. Each write then costs a SELECT more.
	 *
	 * @since 0.1.0
	 */
	public function test_writing_a_scalar_costs_one_statement(): void {
		$writes = array(
			'new'       => 'lb',
			'changed'   => 'oz',
			'unchanged' => 'oz',
		);

		foreach ( $writes as $case => $unit ) {
			$queries = $this->captureQueries(
				function () use ( $unit ): void {
					$this->store->writeScalars( array( 'weight_unit' => $unit ) );
				}
			);

			$this->assertQueryCount( 1, $queries, "Writing a scalar whose value is {$case}" );
		}

		$this->assertSame( array( 'seocart_fixture_scalars_weight_unit' => array( 'oz', 'off' ) ), self::rows( 'seocart_fixture_scalars_%' ) );
	}

	/**
	 * Tests that a row something else created autoloaded ends with autoload off, even when the value written is the one it holds.
	 *
	 * WordPress's update_option() would leave the flag alone when the value does not change.
	 *
	 * Planted violations: in SettingsStore::WRITE_SCALAR, drop `, autoload = 'off'` from the update
	 * clause; in SettingsStore::COMPARE_AND_SWAP, remove `, autoload = 'off'`. Each row then stays
	 * autoloaded.
	 *
	 * @since 0.1.0
	 */
	public function test_an_autoloaded_row_is_switched_off_by_a_write_of_the_same_value(): void {
		self::storeRaw( 'seocart_fixture_scalars_weight_unit', 'lb', 'on' );
		self::storeRaw( 'seocart_fixture_document', '{"version":1,"values":{"mode":"live"}}', 'on' );

		$this->assertArrayHasKey( 'seocart_fixture_scalars_weight_unit', wp_load_alloptions(), 'The planted row does not autoload, so the test proves nothing.' );

		$this->store->writeScalars( array( 'weight_unit' => 'lb' ) );
		$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 1, array( 'mode' => 'live' ) );

		$this->assertSame(
			array(
				'seocart_fixture_document'            => array( '{"version":2,"values":{"mode":"live"}}', 'off' ),
				'seocart_fixture_scalars_weight_unit' => array( 'lb', 'off' ),
			),
			self::rows( 'seocart_fixture_%' )
		);
		$this->assertArrayNotHasKey( 'seocart_fixture_scalars_weight_unit', wp_load_alloptions(), 'The switched-off option is still among the autoloaded ones.' );
	}

	/**
	 * Tests that a write deletes the option lists core caches, rather than storing an edited copy of them.
	 *
	 * Editing a shared list and storing it back races with every other writer of the list: each
	 * can store a copy that lacks the other's change, so an option just written reads as absent.
	 * Deleted, the lists are rebuilt by the next reader.
	 *
	 * Planted violation: in SettingsStore::forget(), read each list, unset the option and store it
	 * back with wp_cache_set(), as the store did before. The lists are then still cached after
	 * the write.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_deletes_the_option_lists_rather_than_storing_an_edited_copy(): void {
		$writes = array(
			'seocart_fixture_scalars_hold_minutes' => fn() => $this->store->writeScalars( array( 'hold_minutes' => 30 ) ),
			'seocart_fixture_document'             => fn() => $this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'mode' => 'live' ) ),
		);

		foreach ( $writes as $option => $write ) {
			wp_cache_set(
				'notoptions',
				array(
					$option                         => true,
					'seocart_fixture_never_written' => true,
				),
				'options'
			);
			wp_load_alloptions();

			$this->assertIsArray( wp_cache_get( 'alloptions', 'options' ), 'The autoload list is not cached, so the test proves nothing.' );

			$write();

			$this->assertFalse( wp_cache_get( 'notoptions', 'options' ), "Writing {$option} left an edited absence list in the cache." );
			$this->assertFalse( wp_cache_get( 'alloptions', 'options' ), "Writing {$option} left an edited autoload list in the cache." );
			$this->assertNotFalse( get_option( $option ), "{$option} reads as absent after it was written." );
		}
	}

	/**
	 * Reads option rows from the table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $like A LIKE pattern for the option name.
	 * @return array<string, array{0: string, 1: string}> Value and autoload, keyed by option name.
	 */
	private static function rows( string $like ): array {
		global $wpdb;

		$rows = array();

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $like ), ARRAY_A ) as $row ) {
			$rows[ (string) $row['option_name'] ] = array( (string) $row['option_value'], (string) $row['autoload'] );
		}

		return $rows;
	}

	/**
	 * Stores a raw value in an option, as a hand edit or an older version of the plugin would have.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option   The option.
	 * @param string $value    The value.
	 * @param string $autoload Optional. The autoload flag. Default 'off'.
	 */
	private static function storeRaw( string $option, string $value, string $autoload = 'off' ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $option,
				'option_value' => $value,
				'autoload'     => $autoload,
			)
		);

		wp_cache_flush();
	}
}

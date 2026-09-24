<?php
/**
 * Tests the reference seed: the same rows for the same seed, and a store that is sound once seeded
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Platform\Settings\InternationalSettings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Jobs\PluginActions;
use SEOCart\Tests\Support\Seed\Dataset;
use SEOCart\Tests\Support\Seed\ReferenceSeed;
use SEOCart\Tests\Support\Seed\SeedVerifier;

/**
 * The reference seed on the `small` dataset, which every run of the suite can afford.
 *
 * The query-plan run (QueryPlanTest) writes `medium` the same way and checks it the same way.
 *
 * Planted violations, in ReferenceSeed::rows(), each shown red and removed:
 *
 * - write the price of product 7's variant in another currency, so that it has none in the base
 *   currency: the seeded store is not sound, and the problem names variant 7 as
 *   `no_base_price`. Doctor has no check of the catalog yet, so the sellability query is what
 *   finds it;
 * - write one item's on_hand one unit above the sum of its ledger: doctor's stock check names
 *   the item;
 * - draw one value with random_int() instead of the seeded RNG, or mix getmypid() into the RNG
 *   seed: the rows no longer match the digest pinned in SMALL_DIGEST, which another process
 *   and another machine must reproduce;
 * - in ReferenceSeed::notSeeded(), leave out stock_allocations: the table is neither seeded nor
 *   named;
 * - take the second locales from all three, the site's included: on a site in en_GB a product
 *   gets two posts in en_GB, which the catalog's unique index refuses;
 * - in SeedVerifier::doctor(), leave out the stock check: the list no longer equals the
 *   kernel's.
 *
 * @group performance
 *
 * @since 0.1.0
 */
final class ReferenceSeedTest extends DatabaseTestCase {

	/**
	 * The digest of the small dataset for USD and en_US at 2026-01-01 00:00:00 UTC.
	 *
	 * Pinned, so that determinism is proven across processes and machines, not only within one
	 * run. A change to what the seed writes changes it; update it in the same change.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SMALL_DIGEST = '53c35f7cb060387030703dfafdf0a5f854adabdaa1f21ef0358e26e2c55c88af';

	/**
	 * The seed of the test, once it wrote.
	 *
	 * @since 0.1.0
	 *
	 * @var ReferenceSeed|null
	 */
	private ?ReferenceSeed $seed = null;

	/**
	 * Installs the plugin's schema, as activation installs it; DatabaseTestCase drops it again.
	 *
	 * The fixture table of DatabaseTestCase is dropped first: doctor reports a table of the
	 * plugin's prefix that nothing declares.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->rowsTable() );

		SeedVerifier::installSchema( $this->db, $this->reporter() );
		PluginActions::purge();
	}

	/**
	 * Deletes the seeded posts and the runner's check-in.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->seed?->removePosts( $this->db );

		PluginActions::purge();

		parent::tear_down();
	}

	/**
	 * Tests that every table the plugin registers is seeded, or left empty with its reason, so a new table cannot be forgotten.
	 *
	 * @since 0.1.0
	 */
	public function test_every_registered_table_is_seeded_or_left_empty_with_a_reason(): void {
		$registered = OwnedData::registry()->tableNames();
		$named      = array_merge( array_diff( ReferenceSeed::TABLES, array( 'posts' ) ), array_keys( ReferenceSeed::notSeeded() ) );

		sort( $registered );
		sort( $named );

		$this->assertSame( $registered, $named, 'Every table the plugin registers must be seeded by ReferenceSeed, or named in ReferenceSeed::notSeeded() with the reason it is left empty.' );

		foreach ( ReferenceSeed::notSeeded() as $table => $reason ) {
			$this->assertNotSame( '', trim( $reason ), "The reason {$table} is left empty is blank." );
		}
	}

	/**
	 * Tests that one seed and one anchor give the same rows, and another seed other rows.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_seed_gives_the_same_rows(): void {
		$now = new \DateTimeImmutable( '2026-01-01 00:00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( self::SMALL_DIGEST, ( new ReferenceSeed( Dataset::Small, 'USD', 'en_US' ) )->digest( $now ) );
		$this->assertNotSame( self::SMALL_DIGEST, ( new ReferenceSeed( Dataset::Small, 'USD', 'en_US', ReferenceSeed::RNG_SEED + 1 ) )->digest( $now ) );
	}

	/**
	 * Tests that a site in another of the store's locales is seeded with second posts in the other two, never its own.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_in_another_locale_is_seeded_without_a_locale_twice(): void {
		global $wpdb;

		$this->seed = new ReferenceSeed( Dataset::Small, 'USD', 'en_GB' );
		$this->seed->write( $this->db );

		$locales = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT locale FROM %i WHERE post_id <> ( SELECT source_post_id FROM %i p WHERE p.id = product_id ) ORDER BY locale', $this->db->table( 'product_posts' ), $this->db->table( 'products' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads the seeded rows back.

		$this->assertSame( array( 'de_DE', 'en_US' ), $locales );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE locale = %s AND post_id <> ( SELECT source_post_id FROM %i p WHERE p.id = product_id )', $this->db->table( 'product_posts' ), 'en_GB', $this->db->table( 'products' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads the seeded rows back.
	}

	/**
	 * Tests that the doctor a seeded store is checked with runs exactly the checks of the kernel's doctor.
	 *
	 * @since 0.1.0
	 */
	public function test_the_seed_is_checked_by_every_check_of_doctor(): void {
		$names = static fn( Doctor $doctor ): array => array_map( static fn( Check $check ): string => $check->name(), $doctor->checks() );

		$this->assertSame( $names( Kernel::container()->get( Doctor::class ) ), $names( SeedVerifier::doctor( $this->db, $this->reporter() ) ), 'SeedVerifier::doctor() must build the checks the kernel gives doctor, so every check doctor runs on a store runs on a seeded one.' );
	}

	/**
	 * Tests that the small dataset is written whole, and that the seeded store is sound.
	 *
	 * @since 0.1.0
	 */
	public function test_a_seeded_store_is_sound(): void {
		$currency = (string) Kernel::container()->get( SettingsStore::class )->value( InternationalSettings::BASE_CURRENCY );
		$rows     = $this->write( $currency )['rows'];

		$this->assertSame( array( 200, 300, 300, 200, 200, 200 ), array( $rows['products'], $rows['posts'], $rows['product_posts'], $rows['variants'], $rows['variant_prices'], $rows['stock_items'] ) );
		$this->assertGreaterThan( 200, $rows['stock_ledger'] );
		$this->assertGreaterThan( 0, $rows['stock_holds'] );

		PluginActions::checkIn();

		$this->assertSame( array(), SeedVerifier::problems( $this->db, $this->reporter(), $currency, Dataset::Small->products() ) );
	}

	/**
	 * Tests that the seed refuses a store that already has products.
	 *
	 * @since 0.1.0
	 */
	public function test_the_seed_refuses_a_store_that_has_products(): void {
		$this->write( 'USD' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'already holds rows the seed would collide with' );

		( new ReferenceSeed( Dataset::Small, 'USD', 'en_US' ) )->write( $this->db );
	}

	/**
	 * Writes the small dataset, and remembers it for tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @param string $currency The base currency.
	 * @return array{rows: array<string, int>, seconds: float} What the seed reported.
	 */
	private function write( string $currency ): array {
		$this->seed = new ReferenceSeed( Dataset::Small, $currency, get_locale() );

		return $this->seed->write( $this->db );
	}
}

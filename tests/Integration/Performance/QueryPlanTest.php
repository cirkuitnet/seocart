<?php
/**
 * Tests the query plans of the plugin's reads on the medium reference dataset
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Infrastructure\MysqlProductRepository;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Platform\RateLimiter\ClientIdentity;
use SEOCart\Platform\RateLimiter\TableRateLimiter;
use SEOCart\Platform\Settings\InternationalSettings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Jobs\PluginActions;
use SEOCart\Tests\Support\QueryPlan\AllowList;
use SEOCart\Tests\Support\QueryPlan\PlanRecorder;
use SEOCart\Tests\Support\QueryPlan\QueryPlan;
use SEOCart\Tests\Support\QueryPlan\ReadInventory;
use SEOCart\Tests\Support\QueryPlan\Statement;
use SEOCart\Tests\Support\Seed\Dataset;
use SEOCart\Tests\Support\Seed\ReferenceSeed;
use SEOCart\Tests\Support\Seed\SeedVerifier;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The run picks seeded rows to read with, counts rows and drops what it seeded, directly and unrecorded.

/**
 * Every plugin SELECT the plugin's reads send keeps the query-plan rule on the reference dataset, or is on the allow-list with its reason.
 *
 * The run seeds the `medium` dataset once, in set_up_before_class(), prints how long that took
 * and fails when it took more than three minutes. The store must then be sound: doctor passes
 * and every seeded variant may be sold (SeedVerifier). Then the plugin's reads run over a
 * PlanRecorder: the catalog's lookups by post, by source post and by variant, its sellability
 * query in a locale and without one, the reads of its write path, the locking reads of a trash
 * and a delete, and the reads of a change of a product's posts, and every read of the stock
 * repository, doctor's
 * projection checks and the sweep's search for expired holds included; the reads that must run
 * in a transaction, and the write path, run in one that is rolled back. Each plugin SELECT they
 * sent is explained once per query and IN-list length, and judged by QueryPlan's rule. The run
 * prints every plan, and fails on a plan that breaks the rule unless its query is on the
 * allow-list (AllowList); on an allow-list entry that names no query of the run, or a query
 * that keeps the rule; and, for the catalog, the inventory and the rate limiter, on a SELECT
 * their source writes that the run did not send, or a SELECT of their tables their source does
 * not write (ReadInventory), so that no read goes unjudged.
 *
 * It belongs to the `performance` group but runs only when the environment variable
 * SEOCART_QUERY_PLANS is 1, as `composer test:query-plans` sets it before it runs that group:
 * seeding is too slow for every run of the suite. Give the run a test database of its own
 * (WP_PHPUNIT__TESTS_CONFIG names its configuration); the seed refuses a store that already has
 * products or stock. Everything it seeded is dropped after it.
 *
 * Planted violations, each shown red and removed:
 *
 * - in exercise(), send `SELECT variant_id FROM {stock_items} WHERE on_hand = 7` through the
 *   recorder's Database: a WHERE on an unindexed column. The rule names the query, the table
 *   and its plan (type ALL);
 * - in MysqlProductRepository::findBySourcePost(), compare `CAST( source_post_id AS CHAR )`:
 *   no index serves the lookup, and the rule names the query, seocart_products and its plan (a
 *   scan of the whole source_post_id index);
 * - in tests/query-plan-allow-list.json, empty the reason of an entry: the list is refused;
 * - add an entry for a query the run does not send: the run names the stale entry;
 * - in exercise(), leave out the sweep's search, expiredVariants(): the run names the stock
 *   repository's EXPIRED_VARIANTS statement as a read it did not send;
 * - in exercise(), leave out the rolled-back save: the run names the catalog's reads of the
 *   write path, the SKU check among them, as reads it did not send;
 * - in exercise(), leave out the rate limiter's peek(): the run names TableRateLimiter::PEEK as a
 *   read it did not send;
 * - in exercise(), send `SELECT id FROM {products} WHERE uuid = ?` through the recorder's
 *   Database: a read of a catalog table that the catalog's source does not write, named as one;
 * - in ReferenceSeed::rows(), give one item in ten a hold instead of every item: the holds are
 *   too few for the sweep's search to be judged;
 * - in QueryPlan::keepsRuleComfortably(), drop the margin (judge an entry stale as soon as its
 *   plan merely keeps the rule): an allow-listed query whose estimate sits right at MOST_ROWS
 *   is reported stale on a run where InnoDB's estimate happens to land just under it.
 *
 * @group performance
 *
 * @since 0.1.0
 */
final class QueryPlanTest extends DatabaseTestCase {

	/**
	 * The environment variable that turns the run on.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SWITCH = 'SEOCART_QUERY_PLANS';

	/**
	 * The seed budget: `medium` must be written within this many seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SEED_SECONDS = 180;

	/**
	 * A locale the sellability query is asked in: one the seed's second posts are in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SECOND_LOCALE = 'de_DE';

	/**
	 * The seed, while its rows are in the database.
	 *
	 * @since 0.1.0
	 *
	 * @var ReferenceSeed|null
	 */
	private static ?ReferenceSeed $seed = null;

	/**
	 * What writing the seed reported.
	 *
	 * @since 0.1.0
	 *
	 * @var array{rows: array<string, int>, seconds: float}
	 */
	private static array $written = array(
		'rows'    => array(),
		'seconds' => 0.0,
	);

	/**
	 * The store's base currency, read from its settings.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private static string $baseCurrency = 'USD';

	/**
	 * Installs the schema and seeds `medium`, once for the class, and prints how long seeding took.
	 *
	 * @since 0.1.0
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( ! self::switchedOn() ) {
			return;
		}

		$db = self::plainDatabase();

		SeedVerifier::installSchema( $db, self::ignore() );

		self::$baseCurrency = (string) Kernel::container()->get( SettingsStore::class )->value( InternationalSettings::BASE_CURRENCY );
		self::$seed         = new ReferenceSeed( Dataset::Medium, self::$baseCurrency, get_locale() );
		self::$written      = self::$seed->write( $db );

		$counts = array();

		foreach ( self::$written['rows'] as $table => $rows ) {
			$counts[] = $table . ' ' . $rows;
		}

		fwrite( STDOUT, sprintf( "\nSeeded the medium reference dataset in %.1f seconds: %s.\n", self::$written['seconds'], implode( ', ', $counts ) ) );
	}

	/**
	 * Drops what the class seeded.
	 *
	 * @since 0.1.0
	 */
	public static function tear_down_after_class(): void {
		global $wpdb;

		if ( null !== self::$seed ) {
			$db = self::plainDatabase();

			self::$seed->removePosts( $db );
			self::$seed = null;

			foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'seocart_' ) . '%' ) ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			}

			PluginActions::purge();
		}

		parent::tear_down_after_class();
	}

	/**
	 * Skips the test unless the run is switched on, and drops DatabaseTestCase's fixture table, which doctor would report as undeclared.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! self::switchedOn() ) {
			$this->markTestSkipped( 'The query-plan run seeds the medium reference dataset; run it with `composer test:query-plans`, on a test database of its own.' );
		}

		$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->rowsTable() );
	}

	/**
	 * Tests that the medium dataset was seeded whole, within its three minutes.
	 *
	 * @since 0.1.0
	 */
	public function test_the_medium_dataset_seeds_within_three_minutes(): void {
		$this->assertSame( 10000, self::$written['rows']['products'] );
		$this->assertSame( 15000, self::$written['rows']['posts'] );
		$this->assertSame( 15000, self::$written['rows']['product_posts'] );
		$this->assertSame( 10000, self::$written['rows']['variants'] );
		$this->assertSame( 10000, self::$written['rows']['variant_prices'] );
		$this->assertSame( 10000, self::$written['rows'][ InventoryTables::ITEMS ] );
		$this->assertGreaterThan( 10000, self::$written['rows'][ InventoryTables::LEDGER ] );
		$this->assertGreaterThanOrEqual( QueryPlan::LARGE_TABLE, self::$written['rows'][ InventoryTables::HOLDS ], 'The holds are too few for the search of the sweep to be judged.' );
		$this->assertLessThanOrEqual( self::SEED_SECONDS, self::$written['seconds'], sprintf( 'Seeding the medium dataset took %.1f seconds, more than its %d.', self::$written['seconds'], self::SEED_SECONDS ) );
	}

	/**
	 * Tests that the seeded store is sound: doctor passes, and every seeded variant may be sold.
	 *
	 * @since 0.1.0
	 */
	public function test_the_seeded_store_is_sound(): void {
		PluginActions::purge();
		PluginActions::checkIn();

		try {
			$this->assertSame( array(), SeedVerifier::problems( $this->db, $this->reporter(), self::$baseCurrency, Dataset::Medium->products() ) );
		} finally {
			PluginActions::purge();
		}
	}

	/**
	 * Tests that every plugin SELECT of the plugin's reads keeps the query-plan rule, or is allowed with its reason.
	 *
	 * @since 0.1.0
	 */
	public function test_every_plugin_select_keeps_the_query_plan_rule(): void {
		global $wpdb;

		$allowed  = AllowList::load( dirname( __DIR__, 3 ) . '/' . AllowList::FILE );
		$recorder = PlanRecorder::open();

		PluginActions::purge();
		PluginActions::checkIn();

		try {
			$this->exercise( new Database( $recorder, true, $this->reporter() ) );
		} finally {
			PluginActions::purge();
			$recorder->close();
		}

		$counts  = array();
		$counter = static function ( string $table ) use ( $wpdb, &$counts ): int {
			$counts[ $table ] ??= (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

			return $counts[ $table ];
		};

		$report   = array();
		$breaking = array();
		$stale    = $allowed;

		$sent = $recorder->statements();

		foreach ( $sent as $statement ) {
			$plan    = QueryPlan::explain( $wpdb, $statement, $counter );
			$id      = $statement->id();
			$verdict = 'ok';

			if ( array() !== $plan->breaches ) {
				$verdict = isset( $allowed[ $id ] ) ? 'allowed' : 'BREAKS THE RULE';

				if ( ! isset( $allowed[ $id ] ) ) {
					$breaking = array_merge( $breaking, $plan->lines( $verdict ) );
				}

				unset( $stale[ $id ] );
			} elseif ( isset( $allowed[ $id ] ) && ! $plan->keepsRuleComfortably() ) {
				// This run's estimate landed on the safe side of the rule, but not comfortably:
				// InnoDB's estimate for the same query varies a little between runs, so a query
				// still this close to the boundary is not yet fixed. The entry stays, unstale.
				unset( $stale[ $id ] );
			}

			$report = array_merge( $report, $plan->lines( $verdict ) );
		}

		fwrite( STDOUT, sprintf( "\nThe plans of the %d plugin SELECTs of the run, one per query and IN-list length:\n%s\n", count( $sent ), implode( "\n", $report ) ) );

		$this->assertSame( array(), $this->inventoryGaps( $recorder->allSent() ), 'The run and the reads the catalog\'s, the inventory\'s and the rate limiter\'s source write differ. Send each read in exercise(), so its plan is judged; a read of their tables belongs in their source.' );
		$this->assertSame( array(), $breaking, sprintf( "These plugin SELECTs break the query-plan rule (a full scan of, or more than %d rows examined in, a table of %d rows or more). Fix the query, or add the index it needs with a migration, or put it on %s with the reason its plan is accepted:\n%s\n", QueryPlan::MOST_ROWS, QueryPlan::LARGE_TABLE, AllowList::FILE, implode( "\n", $breaking ) ) );
		$this->assertSame( array(), array_keys( $stale ), sprintf( 'These entries of %s name no query of the run that breaks the rule; the query changed or was fixed, so remove them.', AllowList::FILE ) );
	}

	/**
	 * Runs the plugin's reads, on the seeded rows, through the Database under test.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When a read fails.
	 *
	 * @param Database $db The Database over the recorder.
	 */
	private function exercise( Database $db ): void {
		global $wpdb;

		$products = new MysqlProductRepository( $db, static fn(): Currency => Currency::of( self::$baseCurrency ) );
		$stock    = new MysqlStockRepository( $db );
		$page     = range( 4001, 4024 );
		$holds    = $this->db->table( InventoryTables::HOLDS );
		$group    = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT hold_group FROM %i ORDER BY id LIMIT 1', $holds ) );
		$expired  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT variant_id FROM %i WHERE expires_at <= UTC_TIMESTAMP() ORDER BY id LIMIT 1', $holds ) );

		foreach ( array( 1, 5000, 10000 ) as $product ) {
			$products->findByPost( ReferenceSeed::sourcePostId( $product ) );
			$products->findBySourcePost( ReferenceSeed::sourcePostId( $product ) );
			$products->findByVariant( $product );
		}

		$products->findByPost( $this->seed()->secondPostId( 5000 ) );

		// A page of 24 first: a query is explained with the values it was first sent with.
		( new Sellability( $products ) )->of( $page, false );
		( new Sellability( $products ) )->of( array( 5000 ), false );
		( new Sellability( $products ) )->of( $page, false, Locale::of( self::SECOND_LOCALE ) );

		$stock->levels( $page );
		$stock->levels( array( 5000 ) );
		$stock->configuration( $page, 900 );
		$stock->groupVariants( $group );
		$stock->expiredVariants( 0, 100 );

		// A cursor near the end of the table: doctor's own reverse check pages from 0, so this is
		// the only place a real cursor reaches MysqlStockRepository::ITEM_VARIANT_IDS.
		$stock->itemVariantIds( Dataset::Medium->products() - 1000, 20 );

		// The translation-group check's own read of every bound post: its first page, no cursor,
		// then a page part-way through the table. SeedVerifier's doctor run never reaches it,
		// since it runs over SiteLocale, which keeps too few languages for the check to walk
		// anything; this is the only place either shape of this statement is sent.
		$products->boundPostBindings( 0, 500 );
		$products->boundPostBindings( ReferenceSeed::FIRST_POST_ID + 7000, 500 );

		// The rate limiter's one read: the count of a client's current window, by its primary key.
		( new TableRateLimiter( $db ) )->peek( 'cart.write', ClientIdentity::ofClient( '192.0.2.1', 0, 'query plans' ), 60 );

		$rollBack = new \RuntimeException( 'Rolled back on purpose: the reads that lock run in a transaction that changes nothing.' );

		try {
			$db->transaction(
				function () use ( $products, $stock, $expired, $rollBack ): void {
					$stock->lockedLevel( $expired );
					$stock->hasOpenAllocation( $expired );
					$stock->reclaimExpired( $expired, '00000000-0000-4000-8000-000000000000' );

					// The catalog's write path: the mark, then a save that gives the product the SKU of
					// another, so that the repository looks up the variant holding it.
					$product = $products->findByVariant( 5000 );
					$variant = null === $product ? null : $product->defaultVariant();

					$this->assertNotNull( $product );
					$this->assertNotNull( $variant );

					$products->markUpdating( (int) $product->id() );
					$product->applyCommerce( Sku::of( 'SEED-005001' ), $variant->basePrice(), $variant->weightGrams(), Currency::of( self::$baseCurrency ) );

					try {
						$products->save( $product );
					} catch ( CodedException $taken ) {
						$this->assertSame( CatalogError::SkuTaken, $taken->errorCode() );
					}

					// A change of the product's posts: its second post locked with it, a binding refused
					// because the post presents it already, and the source handed over to the second post.
					$source = (int) $product->sourcePostId();
					$second = $this->seed()->secondPostId( 5000 );

					$this->assertArrayHasKey( (int) $product->id(), $products->lockWithPost( $second, (int) $product->id() ) );

					try {
						$products->addBinding( (int) $product->id(), new ProductPostBinding( $source, Locale::of( self::SECOND_LOCALE ), new \DateTimeImmutable() ) );
					} catch ( CodedException $bound ) {
						$this->assertSame( CatalogError::PostBoundElsewhere, $bound->errorCode() );
					}

					$this->assertSame( $second, $products->promoteSource( (int) $product->id(), $source, null ) );

					// The lifecycle's locking reads: a trash's, then a delete's, and the deletion's own.
					$locked = $products->lockByPost( (int) $product->sourcePostId() );

					$this->assertNotNull( $locked );

					$products->lockVariants( (int) $locked->id() );

					// The reconciler's own locking read of a post's type and status.
					$products->lockedPostTypeAndStatus( (int) $locked->sourcePostId() );

					$deleted = $products->lock( (int) $locked->id() );

					$this->assertNotNull( $deleted );
					$this->assertNotSame( array(), $products->delete( $deleted ) );

					throw $rollBack;
				}
			);
		} catch ( \RuntimeException $thrown ) {
			if ( $thrown !== $rollBack ) {
				throw $thrown;
			}
		}

		// Doctor, with the stock projection checks, and the sellability of every seeded variant.
		$this->assertSame( array(), SeedVerifier::problems( $db, $this->reporter(), self::$baseCurrency, Dataset::Medium->products() ) );
	}

	/**
	 * Compares the SELECTs the catalog's, the inventory's and the rate limiter's source write with the plugin SELECTs the run sent, both ways.
	 *
	 * @since 0.1.0
	 *
	 * @param Statement[] $sent Every SELECT the run sent, whatever table it names.
	 * @return list<string> One line per read the run did not send, and per read of the module's tables from outside its source.
	 *
	 * @phpstan-param list<Statement> $sent
	 */
	private function inventoryGaps( array $sent ): array {
		$gaps = array();

		$sources = array(
			'Catalog'     => 'Catalog',
			'Inventory'   => 'Inventory',
			'RateLimiter' => 'Platform/RateLimiter',
		);

		foreach ( $sources as $module => $directory ) {
			$heads  = ReadInventory::of( dirname( __DIR__, 3 ) . '/src/' . $directory );
			$tables = array();

			foreach ( OwnedData::registry()->tables() as $table ) {
				if ( $module === $table->module() ) {
					$tables[] = $this->db->table( $table->name() );
				}
			}

			foreach ( ReadInventory::unsent( $heads, $sent ) as $read ) {
				$gaps[] = "{$module}, not sent: {$read}";
			}

			foreach ( ReadInventory::unknown( $heads, $sent, $tables ) as $read ) {
				$gaps[] = "{$module}, sent from outside src/{$directory}: {$read}";
			}
		}

		return $gaps;
	}

	/**
	 * Returns the seed of the class.
	 *
	 * @since 0.1.0
	 *
	 * @return ReferenceSeed The seed.
	 */
	private function seed(): ReferenceSeed {
		$this->assertNotNull( self::$seed, 'The class seeded nothing.' );

		return self::$seed;
	}

	/**
	 * Tells whether the run is switched on.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when SEOCART_QUERY_PLANS is 1.
	 */
	private static function switchedOn(): bool {
		return '1' === getenv( self::SWITCH );
	}

	/**
	 * Returns a Database over the global wpdb, for work that is not under test.
	 *
	 * @since 0.1.0
	 *
	 * @return Database The Database.
	 */
	private static function plainDatabase(): Database {
		global $wpdb;

		return new Database( $wpdb, true, self::ignore() );
	}

	/**
	 * Returns a reporter that keeps nothing, for the work of the class's set-up and tear-down.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure The reporter.
	 */
	private static function ignore(): \Closure {
		return static function ( string $code, array $context ): void {
			unset( $code, $context );
		};
	}
}

<?php
/**
 * Tests `wp seocart catalog summarize-stock`, `project-prices` and `index-products`, and doctor's lines for the products they have yet to recompute
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Projection\CompleteProductPager;
use SEOCart\Catalog\Application\Projection\PriceProjections;
use SEOCart\Catalog\Application\Projection\PriceProjector;
use SEOCart\Catalog\Application\Projection\SearchDocumentProjector;
use SEOCart\Catalog\Application\Projection\StockSummaries;
use SEOCart\Catalog\Application\Projection\StockSummaryProjector;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Application\Search\SearchDocuments;
use SEOCart\Catalog\Infrastructure\Doctor\UnindexedCheck;
use SEOCart\Catalog\Infrastructure\Doctor\UnprojectedCheck;
use SEOCart\Catalog\Infrastructure\Doctor\UnsummarizedCheck;
use SEOCart\Catalog\Interfaces\Cli\CatalogCommand;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\Inventory\StockTables;
use SEOCart\Tests\Support\KernelContainer;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plants a catalog of products directly, as a store whose products predate their summaries holds them, and reads the summaries back.

/**
 * The command summarizes every complete product, a page at a time, prints the cursor after each
 * page, resumes from it, and writes nothing on a second run; doctor's line fails while a complete
 * product is unsummarized, with the count and the command, and passes once none is. A complete
 * product whose every variant is disabled stays unknown by the rule, so doctor does not count it.
 *
 * The catalog is planted directly, 201 complete products and an incomplete one, so the command
 * walks one full page of CatalogCommand::PAGE and a page of one.
 *
 * Planted violation, shown red and removed: in StockSummaryProjector::project(), write every
 * product's summary, whether or not it differs (drop `$summary !== $product['summary'] &&`): the
 * second run sends 201 UPDATEs. In CompleteProductPager::walk(), drop `$lease->renew();`: the page whose
 * lease ran out prints its cursor and the run goes on to write the next page. Dropping
 * `AND stock_summary <> %s` from WRITE_SUMMARY instead
 * leaves this test green: the projector writes only a summary that differs, and an UPDATE that
 * sets the value a row already holds changes no row, so no count can tell the two apart.
 *
 * @since 0.2.0
 */
final class CatalogCommandTest extends CatalogTestCase {

	/**
	 * The first id of the planted products.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const FIRST_ID = 1001;

	/**
	 * The kernel's container.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * The store's default location.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $location;

	/**
	 * Creates the stock tables beside the catalog's, and wires the kernel.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->location = StockTables::create( $this->db );
		$this->kernel   = KernelContainer::build( $this->db, $this->reporter(), array( TransactionManager::class => static fn( Container $c ): TransactionManager => $c->get( Database::class ) ) );

		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );
	}

	/**
	 * Tests that the command summarizes every complete product page by page, prints its cursor, writes nothing again, resumes from a cursor, and that doctor's line follows.
	 *
	 * @since 0.2.0
	 */
	public function test_every_complete_product_is_summarized_resumably_and_once(): void {
		$ids = $this->plantCatalog( 201 );
		$odd = $this->plantProduct( self::FIRST_ID + 500, 'incomplete', 5 );

		$check = new UnsummarizedCheck( $this->kernel->get( StockSummaries::class ) );
		$found = $check->run();

		$this->assertFalse( $found->passed );
		$this->assertSame( '201 complete products have no stock summary yet, so the in-stock filter leaves them out. Run `wp seocart catalog summarize-stock`.', $found->summary );

		list( $exit, $lines ) = $this->summarize( array() );

		$this->assertSame( CatalogCommand::EXIT_OK, $exit );
		$this->assertSame(
			array(
				sprintf( 'Summarized 200 products up to id %1$d, 200 written. To resume: --after=%1$d', $ids[199] ),
				sprintf( 'Summarized 1 products up to id %1$d, 1 written. To resume: --after=%1$d', $ids[200] ),
				'Done: 201 complete products summarized, 201 written.',
			),
			$lines
		);

		foreach ( $ids as $index => $id ) {
			$this->assertSame( 0 === $index % 2 ? 'in_stock' : 'out_of_stock', $this->summaryOf( $id ), 'Product ' . $id . ' is summarized from its units.' );
		}

		$this->assertSame( 'unknown', $this->summaryOf( $odd ), 'An incomplete product is not summarized by the command.' );
		$this->assertTrue( $check->run()->passed );
		$this->assertSame( 'Every complete product\'s stock is summarized for the listings.', $check->run()->summary );

		$again = array();
		$log   = $this->captureQueries(
			function () use ( &$again ): void {
				$again = $this->summarize( array() );
			}
		);

		$this->assertSame( 'Done: 201 complete products summarized, 0 written.', end( $again[1] ) );
		$this->assertSame( 0, $log->ofType( 'UPDATE' )->count(), 'The second run writes nothing: ' . $log->ofType( 'UPDATE' )->describe() );

		$this->db->execute( 'UPDATE %i SET stock_summary = %s WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), 'unknown', $ids[200] );

		$this->assertSame( '1 complete product has no stock summary yet, so the in-stock filter leaves it out. Run `wp seocart catalog summarize-stock`.', $check->run()->summary );

		list( $exit, $lines ) = $this->summarize( array( 'after' => (string) $ids[199] ) );

		$this->assertSame( CatalogCommand::EXIT_OK, $exit );
		$this->assertSame(
			array(
				sprintf( 'Summarized 1 products up to id %1$d, 1 written. To resume: --after=%1$d', $ids[200] ),
				'Done: 1 complete products summarized, 1 written.',
			),
			$lines,
			'A run resumed from a cursor starts after it.'
		);
		$this->assertTrue( $check->run()->passed );
	}

	/**
	 * Tests that a complete product whose only variant is disabled, unknown by the rule, is not counted by doctor, before the command or after it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_product_whose_every_variant_is_disabled_is_not_counted(): void {
		$disabled = $this->plantProduct( self::FIRST_ID, 'complete', 2, false );
		$enabled  = $this->plantProduct( self::FIRST_ID + 1, 'complete', 2 );
		$check    = new UnsummarizedCheck( $this->kernel->get( StockSummaries::class ) );

		$this->assertSame( '1 complete product has no stock summary yet, so the in-stock filter leaves it out. Run `wp seocart catalog summarize-stock`.', $check->run()->summary, 'Only the product with an enabled variant is counted.' );

		list( $exit ) = $this->summarize( array() );

		$this->assertSame( CatalogCommand::EXIT_OK, $exit );
		$this->assertSame( 'in_stock', $this->summaryOf( $enabled ) );
		$this->assertSame( 'unknown', $this->summaryOf( $disabled ), 'A product with no enabled variant stays unknown, by the rule.' );
		$this->assertTrue( $check->run()->passed, 'Doctor still counts a product the command cannot summarize: ' . $check->run()->summary );
	}

	/**
	 * Tests that `project-prices` projects every complete product page by page, prints its cursor, writes nothing again and resumes from a cursor, and that doctor's line follows.
	 *
	 * @since 0.2.0
	 */
	public function test_every_complete_product_gets_its_sort_price_resumably_and_once(): void {
		$ids = $this->plantCatalog( 201 );
		$odd = $this->plantProduct( self::FIRST_ID + 500, 'incomplete', 5 );

		foreach ( array_merge( $ids, array( $odd ) ) as $id ) {
			$this->plantPrice( $id, 1000 + $id );
		}

		$check = new UnprojectedCheck( $this->kernel->get( PriceProjections::class ), static fn(): Currency => Currency::of( CatalogTestCase::BASE_CURRENCY ) );

		$this->assertSame( '201 complete products have no sort price yet, so the price orders leave them out. Run `wp seocart catalog project-prices`.', $check->run()->summary );

		list( $exit, $lines ) = $this->runAction( 'project-prices', array() );

		$this->assertSame( CatalogCommand::EXIT_OK, $exit );
		$this->assertSame(
			array(
				sprintf( 'Projected the prices of 200 products up to id %1$d, 200 written. To resume: --after=%1$d', $ids[199] ),
				sprintf( 'Projected the prices of 1 products up to id %1$d, 1 written. To resume: --after=%1$d', $ids[200] ),
				'Done: 201 complete products projected, 201 written.',
			),
			$lines
		);
		$this->assertSame( 1000 + $ids[7], $this->sortPrice( $ids[7] ), 'A product sorts by its base price.' );
		$this->assertNull( $this->sortPrice( $odd ), 'An incomplete product is not projected by the command.' );
		$this->assertTrue( $check->run()->passed, $check->run()->summary );

		$again = array();
		$log   = $this->captureQueries(
			function () use ( &$again ): void {
				$again = $this->runAction( 'project-prices', array() );
			}
		);

		$this->assertSame( 'Done: 201 complete products projected, 0 written.', end( $again[1] ) );
		$this->assertSame( 0, $log->ofType( 'INSERT', 'UPDATE', 'DELETE' )->count(), 'The second run writes nothing: ' . $log->ofType( 'INSERT', 'UPDATE', 'DELETE' )->describe() );

		$this->db->execute( 'DELETE FROM %i WHERE product_id = %d', $this->db->table( CatalogTables::PRICE_PROJECTIONS ), $ids[200] );

		list( $exit, $lines ) = $this->runAction( 'project-prices', array( 'after' => (string) $ids[199] ) );

		$this->assertSame( CatalogCommand::EXIT_OK, $exit );
		$this->assertSame(
			array(
				sprintf( 'Projected the prices of 1 products up to id %1$d, 1 written. To resume: --after=%1$d', $ids[200] ),
				'Done: 1 complete products projected, 1 written.',
			),
			$lines,
			'A run resumed from a cursor starts after it.'
		);
	}

	/**
	 * Tests that `index-products` writes the documents of every complete product's posts, prints its cursor, writes nothing again and resumes from a cursor, and that doctor's line follows.
	 *
	 * @since 0.2.0
	 */
	public function test_every_complete_products_posts_are_indexed_resumably_and_once(): void {
		$ids = array();

		foreach ( array( 'Linen shirt', 'Cotton shirt', 'Straw hat' ) as $index => $title ) {
			$ids[] = $this->plantProduct( self::FIRST_ID + $index, 'complete', 1 );

			$this->bindPost( end( $ids ), $title );
		}

		$check = new UnindexedCheck( $this->kernel->get( SearchDocuments::class ) );

		$this->assertSame( '3 posts of complete products have no search document yet, so a search ordered by relevance leaves them out. Run `wp seocart catalog index-products`.', $check->run()->summary );

		list( $exit, $lines ) = $this->runAction( 'index-products', array() );

		$this->assertSame( CatalogCommand::EXIT_OK, $exit );
		$this->assertSame(
			array(
				sprintf( 'Indexed the words of 3 products up to id %1$d, 3 written. To resume: --after=%1$d', $ids[2] ),
				'Done: 3 complete products indexed, 3 written.',
			),
			$lines
		);
		$this->assertSame( 'Linen shirt CMD-' . $ids[0], (string) $this->db->fetchValue( 'SELECT search_document FROM %i WHERE product_id = %d', $this->db->table( CatalogTables::SEARCH_INDEX ), $ids[0] ) );
		$this->assertTrue( $check->run()->passed, $check->run()->summary );

		$again = array();
		$log   = $this->captureQueries(
			function () use ( &$again ): void {
				$again = $this->runAction( 'index-products', array() );
			}
		);

		$this->assertSame( 'Done: 3 complete products indexed, 0 written.', end( $again[1] ) );
		$this->assertSame( 0, $log->ofType( 'INSERT', 'UPDATE', 'DELETE' )->count(), 'The second run writes nothing: ' . $log->ofType( 'INSERT', 'UPDATE', 'DELETE' )->describe() );

		$this->db->execute( 'DELETE FROM %i WHERE product_id = %d', $this->db->table( CatalogTables::SEARCH_INDEX ), $ids[2] );

		list( $exit, $lines ) = $this->runAction( 'index-products', array( 'after' => (string) $ids[1] ) );

		$this->assertSame( CatalogCommand::EXIT_OK, $exit );
		$this->assertSame(
			array(
				sprintf( 'Indexed the words of 1 products up to id %1$d, 1 written. To resume: --after=%1$d', $ids[2] ),
				'Done: 1 complete products indexed, 1 written.',
			),
			$lines,
			'A run resumed from a cursor starts after it.'
		);
	}

	/**
	 * Tests that a page whose lease on the drainer's lock was lost meanwhile ends the run with exit code 1 before its cursor is printed, so no later page is written and `--after` does the page again.
	 *
	 * Table mode is forced, and the lease is expired by hand at the page's first write, as a page
	 * that ran past its lease would find it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_page_that_lost_its_lease_stops_the_run_before_its_cursor(): void {
		( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) )->createTable( PlatformTables::locks() );

		$ids = $this->plantCatalog( 201 );

		foreach ( $ids as $id ) {
			$this->plantPrice( $id, 1000 + $id );
		}

		$locks  = $this->db->table( PlatformTables::locks()->name() );
		$expire = function ( string $sql ) use ( $locks ): string {
			static $expired = false;

			if ( ! $expired && str_contains( $sql, 'INSERT INTO `' . $this->db->table( CatalogTables::PRICE_PROJECTIONS ) . '`' ) ) {
				$expired = true;
				$this->db->execute( 'UPDATE %i SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE name = %s', $locks, OutboxDrainer::LOCK_NAME );
			}

			return $sql;
		};

		$lines   = array();
		$command = new CatalogCommand(
			new CompleteProductPager( $this->kernel->get( StockSummaries::class ), array( new LockService( $this->db, LockMode::Table, $this->sleeper() ), 'withLock' ) ),
			$this->kernel->get( StockSummaryProjector::class ),
			$this->kernel->get( PriceProjector::class ),
			$this->kernel->get( SearchDocumentProjector::class ),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		add_filter( 'query', $expire );

		try {
			$exit = $command->run( array( 'project-prices' ), array() );
		} finally {
			remove_filter( 'query', $expire );
		}

		$this->assertSame( CatalogCommand::EXIT_FAILED, $exit, implode( "\n", $lines ) );
		$this->assertCount( 1, $lines, 'Only the failure is printed: ' . implode( "\n", $lines ) );
		$this->assertStringNotContainsString( '--after=', $lines[0], 'The page whose lease was lost prints no cursor.' );
		$this->assertNull( $this->sortPrice( $ids[200] ), 'No page after the one whose lease was lost is written.' );
	}

	/**
	 * Tests that the command refuses an action it does not know, and prints its usage.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unknown_action_is_refused(): void {
		$lines   = array();
		$command = new CatalogCommand(
			$this->kernel->get( CompleteProductPager::class ),
			$this->kernel->get( StockSummaryProjector::class ),
			$this->kernel->get( PriceProjector::class ),
			$this->kernel->get( SearchDocumentProjector::class ),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		$this->assertSame( CatalogCommand::EXIT_FAILED, $command->run( array( 'summarize' ), array() ) );
		$this->assertSame( array( 'Usage: wp seocart catalog summarize-stock|project-prices|index-products [--after=<id>]' ), $lines );
	}

	/**
	 * Runs `summarize-stock` and returns its exit code and the lines it printed.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $options The options.
	 * @return array{0: int, 1: list<string>} The exit code, and the lines.
	 */
	private function summarize( array $options ): array {
		$lines   = array();
		$command = new CatalogCommand(
			$this->kernel->get( CompleteProductPager::class ),
			$this->kernel->get( StockSummaryProjector::class ),
			$this->kernel->get( PriceProjector::class ),
			$this->kernel->get( SearchDocumentProjector::class ),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		return array( $command->run( array( 'summarize-stock' ), $options ), $lines );
	}

	/**
	 * Plants complete products with one enabled variant each, the even ones with two units on hand and the odd ones with none, unsummarized.
	 *
	 * @since 0.2.0
	 *
	 * @param int $count How many.
	 * @return list<int> Their ids, ascending.
	 */
	private function plantCatalog( int $count ): array {
		$ids = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$ids[] = $this->plantProduct( self::FIRST_ID + $index, 'complete', 0 === $index % 2 ? 2 : 0 );
		}

		return $ids;
	}

	/**
	 * Plants one product, its variant of the same id, enabled or not, its stock policy and its item at the default location.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $id      The product's and the variant's id.
	 * @param string $state   The generation marker.
	 * @param int    $onHand  The units on hand.
	 * @param bool   $enabled Optional. Whether the variant is enabled; the product counts it as a save would. Default true.
	 * @return int The product's id.
	 */
	private function plantProduct( int $id, string $state, int $onHand, bool $enabled = true ): int {
		$this->db->execute(
			'INSERT INTO %i ( id, uuid, source_post_id, generation_state, active_variant_generation, variant_count, enabled_variant_count, created_at, updated_at ) VALUES ( %d, %s, %d, %s, %d, 1, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )',
			$this->db->table( CatalogTables::PRODUCTS ),
			$id,
			wp_generate_uuid4(),
			900000 + $id,
			$state,
			Variant::FIRST_GENERATION,
			(int) $enabled
		);
		$this->db->execute(
			'INSERT INTO %i ( id, uuid, product_id, sku, combination_hash, generation, is_enabled, position, created_at, updated_at ) VALUES ( %d, %s, %d, %s, %s, %d, %d, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->db->table( CatalogTables::VARIANTS ),
			$id,
			wp_generate_uuid4(),
			$id,
			'CMD-' . $id,
			Variant::defaultCombinationHash(),
			Variant::FIRST_GENERATION,
			(int) $enabled
		);
		$this->db->execute( 'INSERT INTO %i ( variant_id, track, backorder_policy, updated_at ) VALUES ( %d, 1, %s, UTC_TIMESTAMP(6) )', $this->db->table( InventoryTables::POLICIES ), $id, 'no' );
		$this->db->execute( 'INSERT INTO %i ( variant_id, location_id, on_hand, allocated, held, updated_at ) VALUES ( %d, %d, %d, 0, 0, UTC_TIMESTAMP(6) )', $this->db->table( InventoryTables::ITEMS ), $id, $this->location, $onHand );

		return $id;
	}

	/**
	 * Runs an action of the command and returns its exit code and the lines it printed.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $action  The action.
	 * @param array<string, string> $options The options.
	 * @return array{0: int, 1: list<string>} The exit code, and the lines.
	 */
	private function runAction( string $action, array $options ): array {
		$lines   = array();
		$command = new CatalogCommand(
			$this->kernel->get( CompleteProductPager::class ),
			$this->kernel->get( StockSummaryProjector::class ),
			$this->kernel->get( PriceProjector::class ),
			$this->kernel->get( SearchDocumentProjector::class ),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		return array( $command->run( array( $action ), $options ), $lines );
	}

	/**
	 * Gives a planted product a published post in the site's locale, bound as its source.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $productId The product.
	 * @param string $title     The post's title.
	 */
	private function bindPost( int $productId, string $title ): void {
		$postId = $this->unboundPost();

		wp_update_post(
			array(
				'ID'         => $postId,
				'post_title' => $title,
			)
		);

		$this->db->execute(
			'INSERT INTO %i ( post_id, product_id, locale, linked_at, created_at ) VALUES ( %d, %d, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->db->table( CatalogTables::PRODUCT_POSTS ),
			$postId,
			$productId,
			self::LOCALE
		);
	}

	/**
	 * Authors a variant's base price.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The variant, whose id is its product's.
	 * @param int $priceMinor The price, in cents.
	 */
	private function plantPrice( int $variantId, int $priceMinor ): void {
		$this->db->execute(
			'INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, %s, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->db->table( CatalogTables::VARIANT_PRICES ),
			$variantId,
			CatalogTestCase::BASE_CURRENCY,
			'net',
			$priceMinor
		);
	}

	/**
	 * Returns a product's sort price in the base currency, as stored.
	 *
	 * @since 0.2.0
	 *
	 * @param int $productId The product.
	 * @return int|null The price, or null when it has no row.
	 */
	private function sortPrice( int $productId ): ?int {
		$price = $this->db->fetchValue( 'SELECT sort_price_minor FROM %i WHERE product_id = %d AND currency = %s', $this->db->table( CatalogTables::PRICE_PROJECTIONS ), $productId, CatalogTestCase::BASE_CURRENCY );

		return null === $price ? null : (int) $price;
	}

	/**
	 * Returns a product's summary as stored.
	 *
	 * @since 0.2.0
	 *
	 * @param int $productId The product.
	 * @return string The summary.
	 */
	private function summaryOf( int $productId ): string {
		return (string) $this->db->fetchValue( 'SELECT stock_summary FROM %i WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), $productId );
	}
}

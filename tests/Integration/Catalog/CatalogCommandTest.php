<?php
/**
 * Tests `wp seocart catalog summarize-stock` and doctor's line for the products it has yet to summarize
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Projection\StockSummaries;
use SEOCart\Catalog\Application\Projection\StockSummaryProjector;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Doctor\UnsummarizedCheck;
use SEOCart\Catalog\Interfaces\Cli\CatalogCommand;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\Container;
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
 * second run sends 201 UPDATEs. Dropping `AND stock_summary <> %s` from WRITE_SUMMARY instead
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
	 * Tests that the command refuses an action it does not know, and prints its usage.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unknown_action_is_refused(): void {
		$lines   = array();
		$command = new CatalogCommand(
			$this->kernel->get( StockSummaries::class ),
			$this->kernel->get( StockSummaryProjector::class ),
			array( $this->kernel->get( LockService::class ), 'withLock' ),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		$this->assertSame( CatalogCommand::EXIT_FAILED, $command->run( array( 'summarize' ), array() ) );
		$this->assertSame( array( 'Usage: wp seocart catalog summarize-stock [--after=<id>]' ), $lines );
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
			$this->kernel->get( StockSummaries::class ),
			$this->kernel->get( StockSummaryProjector::class ),
			array( $this->kernel->get( LockService::class ), 'withLock' ),
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

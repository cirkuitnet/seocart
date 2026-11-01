<?php
/**
 * Tests what one page of the repricing job sends, after a rate change, for a page of products sold by conversion
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Projection\CurrencyTerms;
use SEOCart\Catalog\Application\Projection\PriceProjections;
use SEOCart\Catalog\Application\Projection\PriceProjector;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Jobs\RepriceProjections;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Pricing\Application\ExchangeRates;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plants a page of products and their prices directly, and counts what the job sends.

/**
 * A page of RepriceProjections::PAGE products, each sold in EUR by conversion, costs the job two reads, the drainer's lock and its release, the reads of the new version's terms, and one UPDATE per converted price; a second pass finds none behind and writes nothing.
 *
 * The figures are printed for the record. The page's reads do not grow with the page: the
 * products' variants and prices are one statement, their stored rows another.
 *
 * Planted violation, shown red and removed: in MysqlPriceProjections::sources(), read each
 * product's variants on their own (one PROJECTION_SOURCE per product): the page sends 199 more
 * statements.
 *
 * @since 0.2.0
 */
final class RepriceProjectionsTest extends CatalogTestCase {

	use PricesInCurrencies;

	/**
	 * The first id of the planted products.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const FIRST_ID = 3001;

	/**
	 * Creates the rate tables, sells EUR by conversion and plants an installation record.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createRateTables();
		$this->plantBootRecord();
		$this->enableCurrency( 'EUR', true, RoundingMode::HalfUp, 5 );
	}

	/**
	 * Puts back the installation record.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests one page's statements after a rate change, and a second pass that writes nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_page_reprices_each_converted_price_with_one_write_and_a_second_pass_none(): void {
		$kernel = KernelContainer::build( $this->db, $this->reporter(), array( TransactionManager::class => static fn( Container $c ): TransactionManager => $c->get( Database::class ) ) );
		$rates  = $kernel->get( ExchangeRates::class );

		$rates->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 1 ) );

		$ids = $this->plantProducts( RepriceProjections::PAGE );

		$this->assertSame( 2 * RepriceProjections::PAGE, $kernel->get( PriceProjector::class )->project( $ids ), 'Each product gets its USD row and its converted EUR row.' );

		$version = $rates->saveVersion( array( self::rateTo( 'EUR', '0.95' ) ), Actor::user( 1 ) );
		$job     = new RepriceProjections( $kernel->get( PriceProjections::class ), $kernel->get( PriceProjector::class ), $kernel->get( CurrencyTerms::class ), array( $kernel->get( LockService::class ), 'withLock' ), 0 );
		$again   = null;
		$page    = $this->captureQueries(
			static function () use ( $job, &$again ): void {
				$again = $job->handle( array() );
			}
		);

		$this->assertSame( RepriceProjections::AGAIN_AFTER_SECONDS, $again, 'With no time left after a page, the run asks to go on.' );
		$this->assertSame( RepriceProjections::PAGE, $page->ofType( 'UPDATE' )->count(), 'One UPDATE per converted price: ' . $page->ofType( 'UPDATE' )->describe() );
		$this->assertSame( 0, $page->ofType( 'INSERT', 'DELETE' )->count() );
		$this->assertSame( RepriceProjections::PAGE, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE currency = %s AND rate_version = %d', $this->db->table( CatalogTables::PRICE_PROJECTIONS ), 'EUR', $version ) );

		$done = null;
		$last = $this->captureQueries(
			static function () use ( $job, &$done ): void {
				$done = $job->handle( array() );
			}
		);

		$this->assertNull( $done, 'The second pass finds none behind, and ends the run.' );
		$this->assertSame( 0, $last->ofType( 'INSERT', 'UPDATE', 'DELETE' )->count(), 'The second pass writes nothing.' );

		$reads = $page->count() - $page->ofType( 'UPDATE' )->count();

		$this->assertLessThanOrEqual( 8, $reads, 'A page\'s other statements do not grow with the page: ' . $page->difference( $page->ofType( 'UPDATE' ) )->describe() );

		fwrite( STDOUT, sprintf( "\nRepricing one page of %d products sold in EUR by conversion: %d statements, %d of them UPDATEs, %d others (the lock and its release, the stale products, their variants with their prices, their stored rows, the new version's terms); the second pass: %d statements, %d writes.\n", RepriceProjections::PAGE, $page->count(), $page->ofType( 'UPDATE' )->count(), $reads, $last->count(), $last->ofType( 'INSERT', 'UPDATE', 'DELETE' )->count() ) );
	}

	/**
	 * Plants complete products with one enabled variant each and a base price, as a save leaves them.
	 *
	 * @since 0.2.0
	 *
	 * @param int $count How many.
	 * @return list<int> Their ids, ascending.
	 */
	private function plantProducts( int $count ): array {
		$ids = array();

		for ( $id = self::FIRST_ID; $id < self::FIRST_ID + $count; $id++ ) {
			$this->db->execute(
				'INSERT INTO %i ( id, uuid, source_post_id, generation_state, active_variant_generation, variant_count, enabled_variant_count, created_at, updated_at ) VALUES ( %d, %s, %d, %s, %d, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )',
				$this->db->table( CatalogTables::PRODUCTS ),
				$id,
				wp_generate_uuid4(),
				900000 + $id,
				'complete',
				Variant::FIRST_GENERATION
			);
			$this->db->execute(
				'INSERT INTO %i ( id, uuid, product_id, sku, combination_hash, generation, is_enabled, position, created_at, updated_at ) VALUES ( %d, %s, %d, %s, %s, %d, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
				$this->db->table( CatalogTables::VARIANTS ),
				$id,
				wp_generate_uuid4(),
				$id,
				'RPR-' . $id,
				Variant::defaultCombinationHash(),
				Variant::FIRST_GENERATION
			);
			$this->db->execute(
				'INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, %s, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
				$this->db->table( CatalogTables::VARIANT_PRICES ),
				$id,
				CatalogTestCase::BASE_CURRENCY,
				'net',
				1000 + $id
			);

			$ids[] = $id;
		}

		return $ids;
	}
}

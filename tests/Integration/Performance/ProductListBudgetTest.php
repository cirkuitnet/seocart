<?php
/**
 * Tests the statements and the size of the Store API's product listing
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Catalog\Infrastructure\ProductTaxonomies;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Catalog\Interfaces\StoreApi\CatalogOperations;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\Catalog\CreatesTerms;
use SEOCart\Tests\Support\Catalog\PlantsListedProducts;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\QueryLog;

/**
 * A page of the listing costs the same few statements whatever its length, and a page of 24 fits
 * the size a list of 24 may have.
 *
 * The store has 100 products, each with its post, a category under a parent, a brand, a tag, a
 * featured image and a sort price in USD and in EUR, which it sells in too. Each page is served over the wire on a cold object cache, as a request on a site
 * without a persistent cache serves it, with the autoloaded options loaded first, as WordPress
 * loads them before any plugin code runs. Every statement of the request counts. The budgets:
 *
 * - a page of 24 at most BUDGET statements (G18);
 * - the page of 96, the most a request may ask for, at most GROWTH more than the page of 24;
 * - a page of 24 with every filter set (a search, a category with its descendants, a brand, a tag,
 *   the in-stock filter and a price range) at most FILTERS more than the plain page: the three reads
 *   of the term filters and the read of the store's base currency, which a price range is in;
 * - a page of 24 by price, lowest or highest first, in the base currency, at most PRICE_ORDER more
 *   than the plain page (the base currency, a setting, is read only by a request that asks
 *   anything of prices, so the plain page does not pay for it), and in another currency the store
 *   sells in at most PRESENTMENT_EXTRA more than that (the read of the currency's terms);
 * - a page of 24 of a search of three words ordered by relevance at most RELEVANCE more than the
 *   plain page;
 * - every page of 24 within G18: BUDGET plain or by price in the base currency, BUDGET +
 *   FILTERED_CEILING with filters, a price range or another currency;
 * - the body of the plain page of 24 at most MOST_BYTES (G12).
 *
 * The figures are printed for the record.
 *
 * Planted violation, shown red and removed: in ProductListing::items(), leave out the priming of
 * the featured images' posts and meta: each item then reads its image's post and meta on its own,
 * the page of 24 is over budget and the page of 96 grows by about two statements per item.
 *
 * @since 0.2.0
 *
 * @group performance
 */
final class ProductListBudgetTest extends CatalogTestCase {

	use CreatesTerms;
	use PlantsListedProducts;
	use PricesInCurrencies;
	use ServesStoreApi;

	/**
	 * The most statements a page of 24 may cost, cold.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const BUDGET = 10;

	/**
	 * How much more the page of 96 may cost than the page of 24.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const GROWTH = 2;

	/**
	 * How much more a page with every filter set may cost than the plain page.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const FILTERS = 4;

	/**
	 * How much more a page in a price order, in the base currency, may cost than the plain page: the read of the store's base currency, a setting, one statement cold.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const PRICE_ORDER = 1;

	/**
	 * How much more a page in a price order in another currency than the base one may cost than the same page in the base currency: the read of the currency's terms.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const PRESENTMENT_EXTRA = 1;

	/**
	 * How much more a page of a search of three words ordered by relevance may cost than the plain page: nothing.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const RELEVANCE = 0;

	/**
	 * How much more than BUDGET a page of 24 with filters, a price range or another currency may cost at most (G18).
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const FILTERED_CEILING = 3;

	/**
	 * The most bytes the body of a page of 24 may hold.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const MOST_BYTES = 80 * 1024;

	/**
	 * How many products the store has.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const PRODUCTS = 100;

	/**
	 * Wires the kernel, and takes the product lifecycle's hooks off.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		// The store sells in EUR too, at a rate version the installation record names, before the kernel reads the record.
		$this->createRateTables();
		$this->enableCurrency( 'EUR' );
		self::ratesOver( $this->db, static function (): void {} )->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) );
		$this->plantBootRecord( 1 );

		$this->bootStoreApi();

		KernelHooks::detach( 'wp_after_insert_post', 'transition_post_status', 'pre_delete_post', 'deleted_post' );
	}

	/**
	 * Deletes the images and terms, and restores the request globals.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deletePlantedImages();
		$this->deleteCreatedTerms();
		$this->shutStoreApi();
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests the four budgets, and prints the figures.
	 *
	 * @since 0.2.0
	 */
	public function test_a_page_costs_the_same_few_statements_whatever_its_length_and_filters(): void {
		$clothing = $this->term( ProductTaxonomies::CATEGORY, 'Clothing' );
		$shirts   = $this->term( ProductTaxonomies::CATEGORY, 'Shirts', $clothing );
		$acme     = $this->term( ProductTaxonomies::BRAND, 'Acme' );
		$summer   = $this->term( ProductTaxonomies::TAG, 'Summer' );

		for ( $product = 1; $product <= self::PRODUCTS; $product++ ) {
			$planted = $this->plantListed(
				sprintf( 'Linen shirt %d', $product ),
				sprintf( 'SHIRT-%03d', $product ),
				array(
					'excerpt' => 'A shirt woven from flax, cut loose for summer.',
					'terms'   => array(
						ProductTaxonomies::CATEGORY => array( $shirts ),
						ProductTaxonomies::BRAND    => array( $acme ),
						ProductTaxonomies::TAG      => array( $summer ),
					),
					'image'   => true,
					'price'   => 1000 + 10 * $product,
				)
			);

			$this->projectIn( $planted['product_id'], 'EUR', 900 + 10 * $product, 1 );
		}

		$plain      = $this->page( array( 'per_page' => 24 ), 24 );
		$longest    = $this->page( array( 'per_page' => CatalogOperations::MOST_PER_PAGE ), CatalogOperations::MOST_PER_PAGE );
		$filtered   = $this->page(
			array(
				'per_page'        => 24,
				'search'          => 'linen shirt',
				'category'        => 'clothing',
				'brand'           => 'acme',
				'tag'             => 'summer',
				'min_price_minor' => 100,
				'max_price_minor' => 900000,
				'in_stock'        => 'true',
			),
			24
		);
		$ascending  = $this->page(
			array(
				'per_page' => 24,
				'orderby'  => 'price_asc',
			),
			24
		);
		$descending = $this->page(
			array(
				'per_page' => 24,
				'orderby'  => 'price_desc',
			),
			24
		);
		$relevant   = $this->page(
			array(
				'per_page' => 24,
				'orderby'  => 'relevance',
				'search'   => 'linen shirt flax',
			),
			24
		);
		$euros      = $this->page(
			array(
				'per_page' => 24,
				'orderby'  => 'price_asc',
				'currency' => 'EUR',
			),
			24
		);
		fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
			STDOUT,
			sprintf(
				"\nThe product listing, cold: %d statement(s) for 24 items, %d for 96, %d for 24 with every filter and a price range, %d lowest price first, %d highest first, %d lowest first in EUR, %d for a search of three words ordered by relevance; the body of 24 items is %d bytes.\n%s\n",
				$plain['log']->count(),
				$longest['log']->count(),
				$filtered['log']->count(),
				$ascending['log']->count(),
				$descending['log']->count(),
				$euros['log']->count(),
				$relevant['log']->count(),
				$plain['bytes'],
				$plain['log']->describe() . '
' . $filtered['log']->describe()
			)
		);

		$this->assertQueryCountAtMost( self::BUDGET, $plain['log'], 'A page of 24 products (G18)' );
		$this->assertLessThanOrEqual( $plain['log']->count() + self::GROWTH, $longest['log']->count(), 'The page of 96 costs more than ' . self::GROWTH . ' statements over the page of 24: ' . $longest['log']->describe() );
		$this->assertLessThanOrEqual( $plain['log']->count() + self::FILTERS, $filtered['log']->count(), 'A page with every filter costs more than ' . self::FILTERS . ' statements over the plain page: ' . $filtered['log']->describe() );
		$this->assertLessThanOrEqual( $plain['log']->count() + self::PRICE_ORDER, $ascending['log']->count(), 'A page lowest price first costs more than the plain page: ' . $ascending['log']->describe() );
		$this->assertLessThanOrEqual( $plain['log']->count() + self::PRICE_ORDER, $descending['log']->count(), 'A page highest price first costs more than the plain page: ' . $descending['log']->describe() );
		$this->assertLessThanOrEqual( $ascending['log']->count() + self::PRESENTMENT_EXTRA, $euros['log']->count(), 'A page by price in EUR costs more than ' . self::PRESENTMENT_EXTRA . ' statement over the same page in the base currency: ' . $euros['log']->describe() );

		// Whatever the relative pins say, a page of 24 stays within G18: BUDGET plain or by price in the base currency, BUDGET + 3 with filters, a price range or another currency.
		$this->assertLessThanOrEqual( $plain['log']->count() + self::RELEVANCE, $relevant['log']->count(), 'A page of a search ordered by relevance costs more than the plain page: ' . $relevant['log']->describe() );

		foreach ( array(
			'lowest price first'               => $ascending,
			'highest price first'              => $descending,
			'of a search ordered by relevance' => $relevant,
		) as $name => $page ) {
			$this->assertQueryCountAtMost( self::BUDGET, $page['log'], 'A page of 24, ' . $name . ' (G18)' );
		}

		foreach ( array(
			'with every filter and a price range' => $filtered,
			'by price in EUR'                     => $euros,
		) as $name => $page ) {
			$this->assertQueryCountAtMost( self::BUDGET + self::FILTERED_CEILING, $page['log'], 'A page of 24 ' . $name . ' (G18)' );
		}
		$this->assertLessThanOrEqual( self::MOST_BYTES, $plain['bytes'], 'The body of a page of 24 is over the size of a list of 24 (G12).' );
	}

	/**
	 * Serves one page on a cold object cache and returns its statements and the size of its body.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, int|string> $query The query.
	 * @param int                       $items How many items the page must hold.
	 * @return array{log: QueryLog, bytes: int} The statements, and the body's bytes.
	 */
	private function page( array $query, int $items ): array {
		wp_cache_flush();
		wp_load_alloptions();

		$answer = null;
		$log    = $this->captureQueries(
			function () use ( $query, &$answer ): void {
				$answer = $this->serve( $this->server, 'GET', '/seocart/store/v1' . CatalogOperations::ROUTE, array(), array(), $query );
			}
		);

		$this->assertSame( 200, $answer['status'] ?? null, (string) wp_json_encode( $answer['body'] ?? null ) );
		$this->assertCount( $items, $answer['body']['items'] ?? array(), 'The page holds every item asked for.' );

		return array(
			'log'   => $log,
			'bytes' => strlen( $this->server->sent_body ),
		);
	}
}

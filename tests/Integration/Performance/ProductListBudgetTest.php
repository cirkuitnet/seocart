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
use SEOCart\Catalog\Interfaces\StoreApi\CatalogOperations;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\Catalog\CreatesTerms;
use SEOCart\Tests\Support\Catalog\PlantsListedProducts;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\QueryLog;

/**
 * A page of the listing costs the same few statements whatever its length, and a page of 24 fits
 * the size a list of 24 may have.
 *
 * The store has 100 products, each with its post, a category under a parent, a brand, a tag and a
 * featured image. Each page is served over the wire on a cold object cache, as a request on a site
 * without a persistent cache serves it, with the autoloaded options loaded first, as WordPress
 * loads them before any plugin code runs. Every statement of the request counts. The budgets:
 *
 * - a page of 24 at most BUDGET statements (G18);
 * - the page of 96, the most a request may ask for, at most GROWTH more than the page of 24;
 * - a page of 24 with every filter set (a search, a category with its descendants, a brand, a tag
 *   and the in-stock filter) at most FILTERS more than the plain page;
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
	private const FILTERS = 3;

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
			$this->plantListed(
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
				)
			);
		}

		$plain    = $this->page( array( 'per_page' => 24 ), 24 );
		$longest  = $this->page( array( 'per_page' => CatalogOperations::MOST_PER_PAGE ), CatalogOperations::MOST_PER_PAGE );
		$filtered = $this->page(
			array(
				'per_page' => 24,
				'search'   => 'linen shirt',
				'category' => 'clothing',
				'brand'    => 'acme',
				'tag'      => 'summer',
				'in_stock' => 'true',
			),
			24
		);

		fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
			STDOUT,
			sprintf(
				"\nThe product listing, cold: %d statement(s) for 24 items, %d for 96, %d for 24 with every filter; the body of 24 items is %d bytes.\n%s\n",
				$plain['log']->count(),
				$longest['log']->count(),
				$filtered['log']->count(),
				$plain['bytes'],
				$filtered['log']->describe()
			)
		);

		$this->assertQueryCountAtMost( self::BUDGET, $plain['log'], 'A page of 24 products (G18)' );
		$this->assertLessThanOrEqual( $plain['log']->count() + self::GROWTH, $longest['log']->count(), 'The page of 96 costs more than ' . self::GROWTH . ' statements over the page of 24: ' . $longest['log']->describe() );
		$this->assertLessThanOrEqual( $plain['log']->count() + self::FILTERS, $filtered['log']->count(), 'A page with every filter costs more than ' . self::FILTERS . ' statements over the plain page: ' . $filtered['log']->describe() );
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

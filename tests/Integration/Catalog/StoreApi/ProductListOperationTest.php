<?php
/**
 * Tests the Store API's product listing over the wire
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog\StoreApi;

use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\ProductTaxonomies;
use SEOCart\Catalog\Interfaces\StoreApi\CatalogOperations;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Platform\Rest\CachePolicy;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\Catalog\CreatesTerms;
use SEOCart\Tests\Support\Catalog\PlantsListedProducts;
use SEOCart\Tests\Support\Doubles\SeveralLocales;
use SEOCart\Tests\Support\KernelHooks;

/**
 * `GET /seocart/store/v1/products` through the production wiring: who is listed, in what order,
 * in which locale, filtered and searched how, refused when, with which headers, in which shape.
 *
 * The kernel is wired as in production, with a site that publishes in en_US and de_DE; the
 * product lifecycle's hooks are taken off, so the planted posts are bound only as planted. Every
 * planted product keeps the `hidden` visibility the column defaults to, and is listed all the same.
 *
 * Planted violations, each shown red and removed:
 *
 * - in MysqlCatalogSearch::PAGE, write the category filter as a join, `JOIN %i tc ON tc.object_id =
 *   wp.ID AND ( %d = 0 OR tc.term_taxonomy_id IN ({list}) )`: the product in two categories is
 *   listed twice;
 * - in MysqlCatalogSearch::wordValues(), leave the word unescaped: a lone `%` lists every product;
 * - in ProductListing::items(), keep a post whatever its status: the post made private after the
 *   search is listed;
 * - in ProductListing::words(), read a failed split as no words: the search in invalid UTF-8 lists
 *   the catalog.
 *
 * @since 0.2.0
 */
final class ProductListOperationTest extends CatalogTestCase {

	use CreatesTerms;
	use PlantsListedProducts;
	use ServesStoreApi;

	/**
	 * Wires the kernel over a site in two locales, and takes the product lifecycle's hooks off.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bootStoreApi( array( PostLocales::class => static fn(): PostLocales => new SeveralLocales( 'en_US', 'de_DE' ) ) );

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
	 * Tests that a page lists only published posts of complete products, newest first, whatever their visibility, and pages with has_more.
	 *
	 * @since 0.2.0
	 */
	public function test_only_published_complete_products_are_listed_newest_first(): void {
		$oldest = $this->plantListed( 'Oldest shirt', 'SHIRT-1' );

		$this->plantListed( 'Draft shirt', 'SHIRT-2', array( 'status' => 'draft' ) );
		$this->plantListed( 'Private shirt', 'SHIRT-3', array( 'status' => 'private' ) );
		$this->plantListed( 'Trashed shirt', 'SHIRT-4', array( 'status' => 'trash' ) );
		$this->plantListed( 'Incomplete shirt', 'SHIRT-5', array( 'state' => 'incomplete' ) );
		$this->plantListed( 'Updating shirt', 'SHIRT-6', array( 'state' => 'updating' ) );

		$middle = $this->plantListed( 'Middle shirt', 'SHIRT-7' );
		$newest = $this->plantListed( 'Newest shirt', 'SHIRT-8' );

		$all = $this->list();

		$this->assertSame( 200, $all['status'] );
		$this->assertSame( array( $newest['post_id'], $middle['post_id'], $oldest['post_id'] ), self::postIds( $all ), 'A draft, a private, a trashed, an incomplete and an updating product are never listed.' );
		$this->assertSame( 'hidden', $this->productRow( $newest['product_id'] )['visibility'] ?? null, 'The listed product keeps the default visibility, which the listing never reads.' );
		$this->assertFalse( $all['body']['has_more'] );

		$first = $this->list( array( 'per_page' => 2 ) );
		$last  = $this->list(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);

		$this->assertSame( array( $newest['post_id'], $middle['post_id'] ), self::postIds( $first ) );
		$this->assertTrue( $first['body']['has_more'] );
		$this->assertSame( 1, $first['body']['page'] );
		$this->assertSame( 2, $first['body']['per_page'] );
		$this->assertSame( array( $oldest['post_id'] ), self::postIds( $last ) );
		$this->assertFalse( $last['body']['has_more'] );
		$this->assertSame( 2, $last['body']['page'] );

		foreach ( array( 0, CatalogOperations::MOST_PER_PAGE + 1 ) as $refused ) {
			$this->assertSame( 400, $this->list( array( 'per_page' => $refused ) )['status'], 'A page of ' . $refused . ' products is refused.' );
		}

		$this->assertSame( 400, $this->list( array( 'page' => '9223372036854775807' ) )['status'], 'A page whose offset would leave the integers is refused.' );
	}

	/**
	 * Tests that a post made private between the search and the read of the page's posts is left out.
	 *
	 * @since 0.2.0
	 */
	public function test_a_post_made_private_after_the_search_is_left_out(): void {
		global $wpdb;

		$hidden = $this->plantListed( 'Public title', 'GONE-1' );
		$shown  = $this->plantListed( 'Other shirt', 'KEPT-1' );
		$db     = $this->db;
		$posts  = $wpdb->posts;
		$state  = array(
			'searched' => false,
			'before'   => '',
		);

		// The statement after the search is the read of the page's posts; the post is made private just before it.
		$privatise = static function ( string $sql ) use ( &$state, $db, $posts, $hidden ): string {
			if ( '' !== $state['before'] ) {
				return $sql;
			}

			if ( str_contains( $sql, 'STRAIGHT_JOIN' ) ) {
				$state['searched'] = true;
			} elseif ( $state['searched'] ) {
				$state['before'] = $sql;

				$db->execute( 'UPDATE %i SET post_status = %s, post_title = %s WHERE ID = %d', $posts, 'private', 'Secret title', $hidden['post_id'] );
			}

			return $sql;
		};

		wp_cache_flush();
		add_filter( 'query', $privatise );

		try {
			$answer = $this->list();
		} finally {
			remove_filter( 'query', $privatise );
		}

		$this->assertStringStartsWith( "SELECT {$posts}.* FROM {$posts} WHERE ID IN", $state['before'], 'The post was made private between the search and the read of the page\'s posts.' );
		$this->assertSame( array( $shown['post_id'] ), self::postIds( $answer ), 'The post made private after the search is left out.' );
		$this->assertStringNotContainsString( 'Secret title', (string) wp_json_encode( $answer['body'] ) );
	}

	/**
	 * Tests that a locale lists the products with a post in it, each by that post, and that a locale the site does not publish in is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_locale_lists_its_own_posts_only(): void {
		$both    = $this->plantListed( 'Linen shirt', 'LINEN-1' );
		$english = $this->plantListed( 'Cotton shirt', 'COTTON-1' );
		$german  = $this->plantTranslation( $both['product_id'], 'Leinenhemd', 'de_DE' );

		$this->assertSame( array( $english['post_id'], $both['post_id'] ), self::postIds( $this->list() ), 'The site\'s locale is the default.' );
		$this->assertSame( array( $english['post_id'], $both['post_id'] ), self::postIds( $this->list( array( 'locale' => 'en_US' ) ) ) );

		$de = $this->list( array( 'locale' => 'de_DE' ) );

		$this->assertSame( array( $german ), self::postIds( $de ), 'A product with no post in de_DE is absent there.' );
		$this->assertSame( $both['product_id'], $de['body']['items'][0]['product_id'] );
		$this->assertSame( 'Leinenhemd', $de['body']['items'][0]['title'] );

		foreach ( array( 'fr_FR', 'not a locale' ) as $unsupported ) {
			$refused = $this->list( array( 'locale' => $unsupported ) );

			$this->assertSame( 400, $refused['status'] );
			$this->assertErrorShape( $refused['body'], 'catalog.locale_unsupported', 400 );
		}
	}

	/**
	 * Tests the term filters: a category with its descendants, a product in two of them listed once, a brand, a tag, and an unknown slug refused.
	 *
	 * @since 0.2.0
	 */
	public function test_the_term_filters(): void {
		$clothing = $this->term( ProductTaxonomies::CATEGORY, 'Clothing' );
		$shirts   = $this->term( ProductTaxonomies::CATEGORY, 'Shirts', $clothing );
		$linen    = $this->term( ProductTaxonomies::CATEGORY, 'Linen shirts', $shirts );
		$hats     = $this->term( ProductTaxonomies::CATEGORY, 'Hats' );
		$acme     = $this->term( ProductTaxonomies::BRAND, 'Acme' );
		$summer   = $this->term( ProductTaxonomies::TAG, 'Summer' );

		$grandchild = $this->plantListed( 'Linen shirt', 'LINEN-1', array( 'terms' => array( ProductTaxonomies::CATEGORY => array( $linen ) ) ) );
		$twice      = $this->plantListed(
			'Shirt and hat',
			'COMBO-1',
			array(
				'terms' => array(
					ProductTaxonomies::CATEGORY => array( $shirts, $linen ),
					ProductTaxonomies::BRAND    => array( $acme ),
				),
			)
		);
		$hat        = $this->plantListed(
			'Sun hat',
			'HAT-1',
			array(
				'terms' => array(
					ProductTaxonomies::CATEGORY => array( $hats ),
					ProductTaxonomies::BRAND    => array( $acme ),
					ProductTaxonomies::TAG      => array( $summer ),
				),
			)
		);

		$this->assertSame( array( $twice['post_id'], $grandchild['post_id'] ), self::postIds( $this->list( array( 'category' => 'clothing' ) ) ), 'The category lists its grandchild\'s products, and a product in two of its categories once.' );
		$this->assertSame( array( $twice['post_id'], $grandchild['post_id'] ), self::postIds( $this->list( array( 'category' => 'shirts' ) ) ) );
		$this->assertSame( array( $hat['post_id'] ), self::postIds( $this->list( array( 'category' => 'hats' ) ) ) );
		$this->assertSame( array( $hat['post_id'], $twice['post_id'] ), self::postIds( $this->list( array( 'brand' => 'acme' ) ) ) );
		$this->assertSame( array( $hat['post_id'] ), self::postIds( $this->list( array( 'tag' => 'summer' ) ) ) );
		$this->assertSame(
			array( $hat['post_id'] ),
			self::postIds(
				$this->list(
					array(
						'category' => 'hats',
						'brand'    => 'acme',
						'tag'      => 'summer',
					)
				)
			),
			'The filters hold together.'
		);

		foreach ( array( 'category', 'brand', 'tag' ) as $filter ) {
			$refused = $this->list( array( $filter => 'no-such-' . $filter ) );

			$this->assertSame( 404, $refused['status'] );
			$this->assertErrorShape( $refused['body'], 'catalog.term_not_found', 404 );
		}
	}

	/**
	 * Tests that the in-stock filter leaves out the products out of stock and those not summarized yet, which are listed without it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_in_stock_filter(): void {
		$in      = $this->plantListed( 'In stock', 'IN-1' );
		$out     = $this->plantListed( 'Sold out', 'OUT-1', array( 'summary' => 'out_of_stock' ) );
		$unknown = $this->plantListed( 'Not summarized', 'UNKNOWN-1', array( 'summary' => 'unknown' ) );

		$all = $this->list();

		$this->assertSame( array( $unknown['post_id'], $out['post_id'], $in['post_id'] ), self::postIds( $all ) );
		$this->assertSame( array( 'unknown', 'out_of_stock', 'in_stock' ), array_column( $all['body']['items'], 'availability' ) );
		$this->assertSame( array( $in['post_id'] ), self::postIds( $this->list( array( 'in_stock' => 'true' ) ) ) );
	}

	/**
	 * Tests the search: a title word, an excerpt word, a SKU prefix, two words in either order, an unknown word, a lone `%`, and a fourth word refused.
	 *
	 * @since 0.2.0
	 */
	public function test_the_search(): void {
		$linen  = $this->plantListed( 'Linen shirt', 'SHIRT-LIN-01', array( 'excerpt' => 'Woven from flax.' ) );
		$cotton = $this->plantListed( 'Cotton shirt', 'SHIRT-COT-01' );
		$hat    = $this->plantListed( 'Straw hat', 'HAT-STR-01', array( 'excerpt' => 'Linen band.' ) );

		$this->assertSame( array( $hat['post_id'], $linen['post_id'] ), self::postIds( $this->list( array( 'search' => 'linen' ) ) ), 'A word in the title or the excerpt.' );
		$this->assertSame( array( $linen['post_id'] ), self::postIds( $this->list( array( 'search' => 'flax' ) ) ), 'A word in the excerpt only.' );
		$this->assertSame( array( $cotton['post_id'], $linen['post_id'] ), self::postIds( $this->list( array( 'search' => 'shirt-' ) ) ), 'The start of a SKU.' );
		$this->assertSame( array( $cotton['post_id'] ), self::postIds( $this->list( array( 'search' => 'SHIRT-COT' ) ) ), 'The start of a SKU, without regard to case.' );
		$this->assertSame( array(), self::postIds( $this->list( array( 'search' => 'LIN-01' ) ) ), 'A SKU is matched from its start only.' );
		$this->assertSame( array( $linen['post_id'] ), self::postIds( $this->list( array( 'search' => 'shirt linen' ) ) ), 'Two words, in either order.' );
		$this->assertSame( array( $linen['post_id'] ), self::postIds( $this->list( array( 'search' => '  linen   shirt ' ) ) ) );
		$this->assertSame( array(), self::postIds( $this->list( array( 'search' => 'velvet' ) ) ), 'An unknown word lists nothing.' );
		$this->assertSame( array(), self::postIds( $this->list( array( 'search' => '%' ) ) ), 'A lone % matches only a %.' );
		$this->assertSame( array(), self::postIds( $this->list( array( 'search' => '_' ) ) ), 'A lone _ matches only an _.' );

		$refused = $this->list( array( 'search' => 'one two three four' ) );

		$this->assertSame( 400, $refused['status'] );
		$this->assertErrorShape( $refused['body'], 'catalog.search_invalid', 400 );
		$this->assertSame( 400, $this->list( array( 'search' => str_repeat( 'a', 101 ) ) )['status'], 'A search longer than 100 characters is refused.' );
	}

	/**
	 * Tests that a search that is not valid UTF-8 text is refused, never read as a search of no words.
	 *
	 * @since 0.2.0
	 */
	public function test_a_search_that_is_not_valid_text_is_refused(): void {
		$this->plantListed( 'Linen shirt', 'LINEN-1' );

		// `%C3%28 nomatchword`: a two-byte character cut short, then a word no product holds.
		$refused = $this->list( array( 'search' => "\xC3\x28 nomatchword" ) );

		$this->assertSame( 400, $refused['status'], 'A search that is not valid UTF-8 was answered: ' . (string) wp_json_encode( $refused['body'], JSON_INVALID_UTF8_SUBSTITUTE ) );
		$this->assertErrorShape( $refused['body'], 'catalog.search_invalid', 400 );
	}

	/**
	 * Tests the answer's shape, its image at the medium size, and that it is never cached and sets no cookie.
	 *
	 * @since 0.2.0
	 */
	public function test_the_shape_of_an_item_and_the_headers(): void {
		$shown = $this->plantListed( 'Linen shirt', 'LINEN-1', array( 'image' => true ) );
		$plain = $this->plantListed( 'Plain shirt', 'PLAIN-1' );
		$row   = $this->db->fetchRow( 'SELECT uuid FROM %i WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), $shown['product_id'] );

		$read = $this->list();

		$this->assertSame( 200, $read['status'] );
		$this->assertSame( array( 'items', 'page', 'per_page', 'has_more' ), array_keys( $read['body'] ) );
		$this->assertSame( CatalogOperations::DEFAULT_PER_PAGE, $read['body']['per_page'] );

		$item = $read['body']['items'][1];

		$this->assertSame( array( 'post_id', 'product_id', 'product_uuid', 'variant_id', 'sku', 'title', 'permalink', 'image', 'availability' ), array_keys( $item ) );
		$this->assertSame( $shown['post_id'], $item['post_id'] );
		$this->assertSame( $shown['product_id'], $item['product_id'] );
		$this->assertSame( $row['uuid'] ?? null, $item['product_uuid'] );
		$this->assertSame( $shown['variant_id'], $item['variant_id'] );
		$this->assertSame( 'LINEN-1', $item['sku'] );
		$this->assertSame( 'Linen shirt', $item['title'] );
		$this->assertSame( get_permalink( $shown['post_id'] ), $item['permalink'] );
		$this->assertSame( 'in_stock', $item['availability'] );
		$this->assertSame( array( 'id', 'url', 'alt' ), array_keys( $item['image'] ) );
		$this->assertSame( $shown['image_id'], $item['image']['id'] );
		$this->assertStringEndsWith( '/2026/10/linen-1-300x300.jpg', $item['image']['url'], 'The image is shown at the medium size.' );
		$this->assertSame( 'The LINEN-1', $item['image']['alt'] );
		$this->assertSame( $plain['post_id'], $read['body']['items'][0]['post_id'] );
		$this->assertNull( $read['body']['items'][0]['image'], 'A post without a featured image has none.' );
		$this->assertStringNotContainsString( 'price', (string) wp_json_encode( $read['body'] ), 'A listing carries no price.' );

		$this->assertSame( CachePolicy::CACHE_CONTROL, $read['headers']['Cache-Control'] ?? null );
		$this->assertStringContainsString( 'Cookie', $read['headers']['Vary'] ?? '' );
		$this->assertArrayNotHasKey( 'Set-Cookie', $read['headers'] );
		$this->assertSame( array(), $this->cookies, 'The read sets no cookie.' );
	}

	/**
	 * Lists products over the wire.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, int|string> $query Optional. The query. Default none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function list( array $query = array() ): array {
		return $this->serve( $this->server, 'GET', '/seocart/store/v1' . CatalogOperations::ROUTE, array(), array(), $query );
	}

	/**
	 * Returns the post ids of a listing's items, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param array{status: int|null, headers: array<string, string>, body: array<string, mixed>} $answer The answer.
	 * @return list<int> The post ids.
	 */
	private static function postIds( array $answer ): array {
		\PHPUnit\Framework\Assert::assertSame( 200, $answer['status'], (string) wp_json_encode( $answer['body'] ) );

		return array_map( static fn( array $item ): int => (int) $item['post_id'], (array) $answer['body']['items'] );
	}
}

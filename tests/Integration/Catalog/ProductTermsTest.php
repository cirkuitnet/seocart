<?php
/**
 * Tests a product's categories, brands and tags through the product's endpoint
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\ProductTaxonomies;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Tests\Support\Catalog\CreatesTerms;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;
use WP_REST_Request;

/**
 * A catalog editor gives a product its terms in the same request that saves it, through the field core adds to the product resource for each product taxonomy; deleting a product leaves its terms, and deleting a term leaves its products.
 *
 * The terms are written after the product's save has committed, by core's terms step, so they
 * are not part of the save. The terms are committed, as the product is, and deleted after each
 * test.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In ProductTaxonomies::arguments(), leave the brands off the REST API (`show_in_rest` false for
 *   them): the product resource has no brand field, the brand is not assigned, and the assignment
 *   test fails.
 * - In ProductTaxonomies::register(), delete a deleted category's products:
 *   `add_action( 'delete_seocart_category', static function ( $term, $tt, $deleted, $objects ) { foreach ( $objects as $id ) { wp_delete_post( $id, true ); } }, 10, 4 );`:
 *   test_deleting_a_term_leaves_its_products fails.
 * - In ProductTaxonomies::register(), delete a deleted product's terms:
 *   `add_action( 'before_delete_post', static function ( $id ) { foreach ( wp_get_object_terms( $id, self::names() ) as $term ) { wp_delete_term( $term->term_id, $term->taxonomy ); } } );`:
 *   test_deleting_a_product_leaves_its_terms fails.
 * - In ProductTaxonomies::register(), make a term write touch the product's row:
 *   `add_action( 'set_object_terms', static function () { global $wpdb; $wpdb->query( "UPDATE {$wpdb->prefix}seocart_products SET updated_at = NOW(6)" ); } );`:
 *   test_a_change_of_terms_alone_is_not_part_of_the_product_save fails.
 *
 * @since 0.2.0
 */
final class ProductTermsTest extends ProductRestTestCase {

	use CreatesTerms;

	/**
	 * Deletes the terms the test created, and WordPress's cache of the category tree.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedTerms();

		parent::tear_down();
	}

	/**
	 * Tests that a catalog editor saves a product with two categories of a tree, a brand and a tag in one request.
	 *
	 * @since 0.2.0
	 */
	public function test_a_catalog_editor_assigns_terms_through_the_product_endpoint(): void {
		$coffee = $this->term( ProductTaxonomies::CATEGORY, 'Coffee' );
		$origin = $this->term( ProductTaxonomies::CATEGORY, 'Single origin', $coffee );
		$brand  = $this->term( ProductTaxonomies::BRAND, 'Fernwood' );
		$tag    = $this->term( ProductTaxonomies::TAG, 'Washed' );

		wp_set_current_user( $this->createUser( 'seocart_catalog_editor' ) );

		$response = $this->request(
			'POST',
			'',
			array(
				'title'                                    => 'Ethiopia Yirgacheffe',
				'status'                                   => 'publish',
				ProductCommerceSchema::PROPERTY            => array(
					'sku'         => 'SKU-TERMS',
					'price_minor' => 1800,
				),
				self::field( ProductTaxonomies::CATEGORY ) => array( $coffee, $origin ),
				self::field( ProductTaxonomies::BRAND )    => array( $brand ),
				self::field( ProductTaxonomies::TAG )      => array( $tag ),
			)
		);
		$data     = (array) $response->get_data();

		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $data ) );

		$postId   = (int) $data['id'];
		$expected = array(
			ProductTaxonomies::CATEGORY => array( $coffee, $origin ),
			ProductTaxonomies::BRAND    => array( $brand ),
			ProductTaxonomies::TAG      => array( $tag ),
		);

		foreach ( $expected as $taxonomy => $ids ) {
			$this->assertEqualsCanonicalizing( $ids, $data[ self::field( $taxonomy ) ] ?? null, "{$taxonomy}: the answer." );
			$this->assertEqualsCanonicalizing( $ids, wp_get_object_terms( $postId, $taxonomy, array( 'fields' => 'ids' ) ), "{$taxonomy}: what was stored." );
		}

		$this->assertSame( 1, (int) get_term( $origin, ProductTaxonomies::CATEGORY )->count, 'WordPress counts the published product in its category.' );
	}

	/**
	 * Tests that the terms are written after the product's save: writing them changes nothing of the product's row, and no `ProductSaved` names them.
	 *
	 * An update that gives a product new terms and nothing else is saved as an update of its post,
	 * which is what its one `ProductSaved` names, and the save moves the product's `updated_at`.
	 * The terms are written between core's `rest_insert_` and `rest_after_insert_` hooks, which both
	 * fire after the save has committed: the product's row read at each is the same.
	 *
	 * @since 0.2.0
	 */
	public function test_a_change_of_terms_alone_is_not_part_of_the_product_save(): void {
		$b      = $this->secondConnection();
		$coffee = $this->term( ProductTaxonomies::CATEGORY, 'Coffee' );
		$brand  = $this->term( ProductTaxonomies::BRAND, 'Fernwood' );
		$postId = $this->createdProduct( 'SKU-TERMS-ONLY', array() );
		$row    = sprintf( 'SELECT * FROM `%s` WHERE source_post_id = %d', $this->db->table( CatalogTables::PRODUCTS ), $postId );
		$last   = (int) $b->fetchValue( sprintf( 'SELECT MAX(id) FROM `%s`', $this->db->table( OutboxTable::NAME ) ) );
		$rows   = array();
		$terms  = array();
		$read   = static function ( string $moment ) use ( $b, $row, $postId, &$rows, &$terms ): \Closure {
			return static function () use ( $moment, $b, $row, $postId, &$rows, &$terms ): void {
				$rows[ $moment ]  = $b->fetchRow( $row );
				$terms[ $moment ] = wp_get_object_terms( $postId, ProductTaxonomies::names(), array( 'fields' => 'ids' ) );
			};
		};

		add_action( 'rest_insert_' . ProductCapabilities::POST_TYPE, $read( 'before terms' ) );
		add_action( 'rest_after_insert_' . ProductCapabilities::POST_TYPE, $read( 'after terms' ) );

		$response = $this->request(
			'POST',
			'/' . $postId,
			array(
				self::field( ProductTaxonomies::CATEGORY ) => array( $coffee ),
				self::field( ProductTaxonomies::BRAND )    => array( $brand ),
			)
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array(), $terms['before terms'] ?? null, 'The product had its terms before core\'s terms step.' );
		$this->assertEqualsCanonicalizing( array( $coffee, $brand ), $terms['after terms'] ?? null, 'Core\'s terms step did not write the terms.' );
		$this->assertNotNull( $rows['before terms'] ?? null );
		$this->assertSame( $rows['before terms'], $rows['after terms'] ?? null, 'Writing the terms changed the product row: its marker or its updated_at.' );

		$saved = array();

		foreach ( explode( "\n", (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT(payload_json ORDER BY id SEPARATOR '\\n') FROM `%s` WHERE id > %d AND event_name = 'product_saved'", $this->db->table( OutboxTable::NAME ), $last ) ) ) as $payload ) {
			$saved[] = json_decode( $payload, true )['p']['changed_fields'] ?? null;
		}

		$this->assertSame( array( array( SaveProduct::POST_FIELDS ) ), $saved, 'The update is not one save named as a change of the post alone.' );
	}

	/**
	 * Tests that deleting a product leaves its categories, brand and tags, and that WordPress counts it out of them.
	 *
	 * @since 0.2.0
	 */
	public function test_deleting_a_product_leaves_its_terms(): void {
		$coffee = $this->term( ProductTaxonomies::CATEGORY, 'Coffee' );
		$brand  = $this->term( ProductTaxonomies::BRAND, 'Fernwood' );
		$tag    = $this->term( ProductTaxonomies::TAG, 'Washed' );
		$postId = $this->createdProduct(
			'SKU-DELETED',
			array(
				self::field( ProductTaxonomies::CATEGORY ) => array( $coffee ),
				self::field( ProductTaxonomies::BRAND )    => array( $brand ),
				self::field( ProductTaxonomies::TAG )      => array( $tag ),
			)
		);

		$this->assertSame( 1, (int) get_term( $coffee, ProductTaxonomies::CATEGORY )->count, 'The product is not counted in its category, so the count below would prove nothing.' );

		$response = $this->request( 'DELETE', '/' . $postId, array( 'force' => true ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertNull( get_post( $postId ), 'The product post was not deleted.' );
		$this->assertNull( $this->products->findByPost( $postId ), 'The product was not deleted with its post.' );

		foreach ( array(
			ProductTaxonomies::CATEGORY => $coffee,
			ProductTaxonomies::BRAND    => $brand,
			ProductTaxonomies::TAG      => $tag,
		) as $taxonomy => $term ) {
			$this->assertInstanceOf( \WP_Term::class, get_term( $term, $taxonomy ), "Deleting the product deleted its {$taxonomy} term." );
			$this->assertSame( 0, (int) get_term( $term, $taxonomy )->count, "WordPress still counts the deleted product in its {$taxonomy} term." );
		}
	}

	/**
	 * Tests that deleting a category, then a brand, leaves their product, with its other terms.
	 *
	 * @since 0.2.0
	 */
	public function test_deleting_a_term_leaves_its_products(): void {
		$coffee = $this->term( ProductTaxonomies::CATEGORY, 'Coffee' );
		$origin = $this->term( ProductTaxonomies::CATEGORY, 'Single origin', $coffee );
		$brand  = $this->term( ProductTaxonomies::BRAND, 'Fernwood' );
		$postId = $this->createdProduct(
			'SKU-KEPT',
			array(
				self::field( ProductTaxonomies::CATEGORY ) => array( $coffee, $origin ),
				self::field( ProductTaxonomies::BRAND )    => array( $brand ),
			)
		);

		foreach ( array(
			ProductTaxonomies::CATEGORY => $origin,
			ProductTaxonomies::BRAND    => $brand,
		) as $taxonomy => $term ) {
			$deleted = rest_do_request( $this->termRequest( 'DELETE', $taxonomy, $term, array( 'force' => true ) ) );

			$this->assertSame( 200, $deleted->get_status(), (string) wp_json_encode( $deleted->get_data() ) );
			$this->assertNull( get_term( $term, $taxonomy ), "The {$taxonomy} term was not deleted." );
			$this->assertSame( 'publish', get_post_status( $postId ), "Deleting a {$taxonomy} term deleted or unpublished its product's post." );
			$this->assertNotNull( $this->products->findByPost( $postId ), "Deleting a {$taxonomy} term deleted its product." );
		}

		$this->assertSame( array( $coffee ), wp_get_object_terms( $postId, ProductTaxonomies::CATEGORY, array( 'fields' => 'ids' ) ), 'The product lost its other category.' );
		$this->assertSame( array(), wp_get_object_terms( $postId, ProductTaxonomies::BRAND, array( 'fields' => 'ids' ) ), 'The product kept a deleted brand.' );
	}

	/**
	 * Creates a published product through the product endpoint, as the administrator, with the terms given.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $sku   The SKU.
	 * @param array<string, mixed> $terms The term fields, by REST base.
	 * @return int The product's post id.
	 */
	private function createdProduct( string $sku, array $terms ): int {
		$response = $this->request(
			'POST',
			'',
			array(
				'title'                         => 'Product ' . $sku,
				'status'                        => 'publish',
				ProductCommerceSchema::PROPERTY => array(
					'sku'         => $sku,
					'price_minor' => 1800,
				),
			) + $terms
		);

		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (int) ( (array) $response->get_data() )['id'];
	}

	/**
	 * Builds a request to a product taxonomy's term routes.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $method   The HTTP method.
	 * @param string               $taxonomy The taxonomy.
	 * @param int                  $term     The term the request names.
	 * @param array<string, mixed> $body     The body parameters.
	 * @return WP_REST_Request The request.
	 */
	private function termRequest( string $method, string $taxonomy, int $term, array $body ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/wp/v2/' . self::field( $taxonomy ) . '/' . $term );

		$request->set_body_params( $body );

		return $request;
	}

	/**
	 * Returns the field the product resource carries a taxonomy's terms in: its REST base.
	 *
	 * @since 0.2.0
	 *
	 * @param string $taxonomy The taxonomy.
	 * @return string The field.
	 */
	private static function field( string $taxonomy ): string {
		return (string) ProductTaxonomies::arguments( $taxonomy )['rest_base'];
	}
}

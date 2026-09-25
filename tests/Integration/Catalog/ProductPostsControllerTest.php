<?php
/**
 * Tests the product's `wp/v2` endpoint: one request, one save, and core's behaviour around it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Catalog\Interfaces\Rest\ProductPostsController;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * An update or a create through `wp/v2/seocart-products` is one product save: the post and the
 * `seocart` object in one transaction, then core's own sequence on the committed post. What a
 * response carries, and what WordPress refuses before the save, follow core.
 *
 * The count of saves is the count of ProductSaved outbox rows: a save publishes exactly one,
 * inside its transaction, and nothing else publishes it.
 *
 * Planted violations, each confirmed to fail a test here:
 * - In ProductPostsController::write(), delete the update_additional_fields_for_object() call:
 *   the other plugin's field does not round-trip.
 * - In ProductPostsController::save(), run the other plugins' field callbacks inside the save:
 *   hook `update_additional_fields_for_object()` to `save_post_seocart_product` for the request:
 *   the callback runs at transaction depth 1.
 * - In ProductPostsController::save(), call `$this->save->save()` twice: two ProductSaved rows.
 * - In ProductPostsController::prepare_item_for_response(), add the `seocart` object to the
 *   response the parent returns, past core's context filter, instead of in
 *   add_additional_fields_to_object(): the view context carries `generation_state`.
 * - In ProductPostsController::install(), keep the autosave and revision controllers WordPress
 *   already built: their routes work through the instance WordPress made without services.
 * - In ProductPostsController::install(), build the controller before the post type is checked:
 *   a controller is built for a type the plugin does not serve.
 * - In ProductPostsController::commerceValues(), leave out `weight_grams`, or add a key the schema
 *   does not declare: the `seocart` object no longer matches its declaration.
 *
 * @since 0.1.0
 */
final class ProductPostsControllerTest extends ProductRestTestCase {

	/**
	 * Tests that an update with a title and a price is one save and one `save_post_seocart_product`, and answers with both.
	 *
	 * @since 0.1.0
	 */
	public function test_an_update_saves_the_post_and_the_price_in_one_save(): void {
		$b       = $this->secondConnection();
		$saved   = $this->savedProduct();
		$saves   = $this->committedSavesOfAll( $b );
		$actions = did_action( 'save_post_' . ProductCapabilities::POST_TYPE );

		$response = $this->request(
			'PUT',
			'/' . $saved->postId,
			array(
				'title'                         => 'Renamed through REST',
				ProductCommerceSchema::PROPERTY => array( 'price_minor' => 2599 ),
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $data ) );
		$this->assertSame( 1, $this->committedSavesOfAll( $b ) - $saves, 'One request is one save.' );
		$this->assertSame( 1, did_action( 'save_post_' . ProductCapabilities::POST_TYPE ) - $actions, 'One request writes the post once.' );
		$this->assertSame( array( 'Renamed through REST', 'SKU-1', '2599' ), $this->committedProduct( $b, $saved ) );
		$this->assertSame( 'Renamed through REST', $data['title']['raw'] ?? null );
		$this->assertSame( 2599, $data[ ProductCommerceSchema::PROPERTY ]['price_minor'] ?? null );
		$this->assertSame( 'sellable', $data[ ProductCommerceSchema::PROPERTY ]['sellability'] ?? null );
		$this->assertSame( 'complete', $data[ ProductCommerceSchema::PROPERTY ]['generation_state'] ?? null, 'The write answers in the edit context, as core does.' );
		$this->assertSame( array(), $this->failures );
	}

	/**
	 * Tests that a create with a title, a SKU and a price saves a whole product and answers 201 with its address.
	 *
	 * @since 0.1.0
	 */
	public function test_a_create_saves_a_whole_product(): void {
		$b        = $this->secondConnection();
		$response = $this->request(
			'POST',
			'',
			array(
				'title'                         => 'Created through REST',
				'status'                        => 'publish',
				ProductCommerceSchema::PROPERTY => array(
					'sku'         => 'SKU-REST',
					'price_minor' => 1500,
				),
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $data ) );
		$this->assertSame( rest_url( 'wp/v2/seocart-products/' . $data['id'] ), $response->get_headers()['Location'] ?? null );
		$this->assertSame( 'sellable', $data[ ProductCommerceSchema::PROPERTY ]['sellability'] ?? null );
		$this->assertSame( 'SKU-REST', $data[ ProductCommerceSchema::PROPERTY ]['sku'] ?? null );
		$this->assertSame( ProductCapabilities::POST_TYPE, get_post_type( (int) $data['id'] ) );
		$this->assertSame( 1, $this->committedSavesOfAll( $b ) );
	}

	/**
	 * Tests that another plugin's REST field round-trips, and that its update callback runs after the save committed.
	 *
	 * @since 0.1.0
	 */
	public function test_another_plugins_field_round_trips_outside_the_save(): void {
		$saved  = $this->savedProduct();
		$depths = array();

		register_rest_field(
			ProductCapabilities::POST_TYPE,
			'thirdparty',
			array(
				'get_callback'    => static fn( array $post ): string => (string) get_post_meta( (int) $post['id'], 'thirdparty', true ),
				'update_callback' => function ( $value, \WP_Post $post ) use ( &$depths ): bool {
					$depths[] = $this->db->depth();

					return false !== update_post_meta( $post->ID, 'thirdparty', (string) $value );
				},
				'schema'          => null,
			)
		);

		$response = $this->request(
			'PUT',
			'/' . $saved->postId,
			array(
				'thirdparty'                    => 'kept',
				ProductCommerceSchema::PROPERTY => array( 'price_minor' => 1234 ),
			)
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array( 0 ), $depths, 'The other plugin\'s callback ran once, with no transaction open.' );
		$this->assertSame( 'kept', $this->request( 'GET', '/' . $saved->postId )->get_data()['thirdparty'] ?? null );
	}

	/**
	 * Tests that the `rest_pre_insert_seocart_product` filter applies to what the save writes.
	 *
	 * @since 0.1.0
	 */
	public function test_the_pre_insert_filter_applies(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedProduct();

		add_filter(
			'rest_pre_insert_' . ProductCapabilities::POST_TYPE,
			static function ( \stdClass $prepared ): \stdClass {
				$prepared->post_title = ( $prepared->post_title ?? '' ) . ' (filtered)';

				return $prepared;
			}
		);

		$response = $this->request( 'PUT', '/' . $saved->postId, array( 'title' => 'Named' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Named (filtered)', $this->committedProduct( $b, $saved )[0] );
	}

	/**
	 * Tests that a SKU another product holds is a 409 in the plugin's error shape, and that the title is not saved.
	 *
	 * @since 0.1.0
	 */
	public function test_a_taken_sku_is_a_conflict_and_nothing_is_saved(): void {
		$b      = $this->secondConnection();
		$first  = $this->savedProduct( 'SKU-1', 'First' );
		$second = $this->savedProduct( 'SKU-2', 'Second' );

		$response = $this->request(
			'PUT',
			'/' . $second->postId,
			array(
				'title'                         => 'Second, renamed',
				ProductCommerceSchema::PROPERTY => array( 'sku' => 'SKU-1' ),
			)
		);
		$error    = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( CatalogError::SkuTaken->value, $error['code'] ?? null );
		$this->assertSame( 409, $error['data']['status'] ?? null );
		$this->assertSame( array( 'sku' => 'SKU-1' ), (array) ( $error['data']['details'] ?? array() ) );
		$this->assertSame( self::CORRELATION_ID, $error['data']['correlation_id'] ?? null );
		$this->assertSame( array( 'status', 'details', 'correlation_id' ), array_keys( (array) ( $error['data'] ?? array() ) ), 'The plugin\'s error shape carries exactly these.' );
		$this->assertSame( array( 'Second', 'SKU-2', '1999' ), $this->committedProduct( $b, $second ) );
		$this->assertSame( array( 'First', 'SKU-1', '1999' ), $this->committedProduct( $b, $first ) );
	}

	/**
	 * Tests that the view context omits the product's marker and the edit context sends it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_marker_is_sent_in_the_edit_context_only(): void {
		$saved = $this->savedProduct();
		$view  = $this->request( 'GET', '/' . $saved->postId, array(), array( 'context' => 'view' ) )->get_data();
		$edit  = $this->request( 'GET', '/' . $saved->postId, array(), array( 'context' => 'edit' ) )->get_data();

		$this->assertSame(
			array(
				'sku'              => 'SKU-1',
				'price_minor'      => 1999,
				'currency'         => self::BASE_CURRENCY,
				'compare_at_minor' => null,
				'weight_grams'     => null,
				'locale'           => get_locale(),
				'translation_of'   => null,
				'sellability'      => 'sellable',
			),
			$view[ ProductCommerceSchema::PROPERTY ] ?? null
		);
		$this->assertSame( 'complete', $edit[ ProductCommerceSchema::PROPERTY ][ ProductCommerceSchema::GENERATION_STATE ] ?? null );
		$this->assertSame(
			array_keys( ProductCommerceSchema::property()['properties'] ),
			array_keys( $edit[ ProductCommerceSchema::PROPERTY ] ?? array() ),
			'The edit context of a product with a price must carry every declared property of the `seocart` object, and nothing else.'
		);
	}

	/**
	 * Tests that `_fields=seocart` answers with the commerce object alone.
	 *
	 * @since 0.1.0
	 */
	public function test_fields_can_select_the_commerce_object(): void {
		$saved   = $this->savedProduct();
		$request = new \WP_REST_Request( 'GET', self::ROUTE . '/' . $saved->postId );

		$request->set_query_params( array( '_fields' => ProductCommerceSchema::PROPERTY ) );

		// As a served request is filtered: core keeps `id` until `rest_post_dispatch` drops what was not asked for.
		$data = rest_filter_response_fields( rest_do_request( $request ), rest_get_server(), $request )->get_data();

		$this->assertSame( array( ProductCommerceSchema::PROPERTY ), array_keys( $data ) );
		$this->assertSame( 'SKU-1', $data[ ProductCommerceSchema::PROPERTY ]['sku'] ?? null );
	}

	/**
	 * Tests that a product post no product is bound to reports `no_binding`, no locale among a product's posts, and nothing else.
	 *
	 * The post is written as a writer that never fires `wp_after_insert_post` writes one, the
	 * one way a product post stays unbound while the lifecycle is hooked.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unbound_post_has_no_binding(): void {
		$postId = $this->unboundPost();
		$data   = $this->request( 'GET', '/' . $postId, array(), array( 'context' => 'edit' ) )->get_data();

		$this->assertSame(
			array(
				'locale'         => null,
				'translation_of' => null,
				'sellability'    => 'no_binding',
			),
			$data[ ProductCommerceSchema::PROPERTY ] ?? null
		);
	}

	/**
	 * Tests that WordPress refuses a commerce key the schema does not declare, before anything is saved.
	 *
	 * @since 0.1.0
	 */
	public function test_an_undeclared_commerce_key_is_refused(): void {
		$b        = $this->secondConnection();
		$saved    = $this->savedProduct();
		$response = $this->request( 'PUT', '/' . $saved->postId, array( ProductCommerceSchema::PROPERTY => array( 'colour' => 'red' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null );
		$this->assertSame( 1, $this->committedSavesOfAll( $b ) );
	}

	/**
	 * Tests that sending back the commerce fields a product already has rewrites no commerce row.
	 *
	 * The rows are aged first, so a rewrite shows in the checksum whatever second the save runs in.
	 *
	 * @since 0.1.0
	 */
	public function test_unchanged_commerce_fields_rewrite_no_row(): void {
		$b      = $this->secondConnection();
		$saved  = $this->savedProduct();
		$tables = array( CatalogTables::VARIANTS, CatalogTables::VARIANT_PRICES );
		$sent   = $this->request( 'GET', '/' . $saved->postId, array(), array( 'context' => 'edit' ) )->get_data()[ ProductCommerceSchema::PROPERTY ] ?? array();

		$this->assertArrayHasKey( 'sku', $sent, 'The edit context returned no commerce fields to send back.' );
		$this->assertArrayHasKey( 'price_minor', $sent, 'The edit context returned no price to send back.' );

		$this->ageCommerceRows( $b, (int) $saved->variantId );
		$before   = array_intersect_key( $this->catalogChecksums( $b ), array_flip( $tables ) );
		$response = $this->request( 'PUT', '/' . $saved->postId, array( ProductCommerceSchema::PROPERTY => array_diff_key( $sent, array_flip( array( ProductCommerceSchema::SELLABILITY, ProductCommerceSchema::GENERATION_STATE ) ) ) ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, array_intersect_key( $this->catalogChecksums( $b ), array_flip( $tables ) ), 'An unchanged variant or price row was rewritten.' );
	}

	/**
	 * Tests that a change to one commerce field alone is still written, whatever the unchanged-row guard compares.
	 *
	 * A SKU that changes only its case, a weight set and then cleared, and a compare-at price each reach their row,
	 * and the price row's updated_at moves with its change.
	 *
	 * @since 0.1.0
	 */
	public function test_a_change_to_one_field_alone_is_written(): void {
		$b         = $this->secondConnection();
		$saved     = $this->savedProduct( 'Case-Sku' );
		$variantId = (int) $saved->variantId;
		$variant   = sprintf( 'SELECT %%s FROM `%s` WHERE id = %d', $this->db->table( CatalogTables::VARIANTS ), $variantId );
		$price     = sprintf( 'SELECT %%s FROM `%s` WHERE variant_id = %d', $this->db->table( CatalogTables::VARIANT_PRICES ), $variantId );
		$put       = fn( array $fields ): int => $this->request( 'PUT', '/' . $saved->postId, array( ProductCommerceSchema::PROPERTY => $fields ) )->get_status();

		$this->assertSame( 200, $put( array( 'sku' => 'CASE-SKU' ) ) );
		$this->assertSame( 'CASE-SKU', $b->fetchValue( sprintf( $variant, 'sku' ) ), 'A change of case alone was not written.' );

		$this->assertSame( 200, $put( array( 'weight_grams' => 250 ) ) );
		$this->assertSame( '250', $b->fetchValue( sprintf( $variant, 'weight_grams' ) ), 'A weight set alone was not written.' );

		$this->assertSame( 200, $put( array( 'weight_grams' => null ) ) );
		$this->assertNull( $b->fetchValue( sprintf( $variant, 'weight_grams' ) ), 'A weight cleared alone was not written.' );

		$this->ageCommerceRows( $b, $variantId );
		$aged = $b->fetchValue( sprintf( $price, 'updated_at' ) );

		$this->assertSame( 200, $put( array( 'compare_at_minor' => 2999 ) ) );
		$this->assertSame( '2999', $b->fetchValue( sprintf( $price, 'compare_at_minor' ) ), 'A compare-at price set alone was not written.' );
		$this->assertNotSame( $aged, $b->fetchValue( sprintf( $price, 'updated_at' ) ), 'A compare-at price changed alone left the row\'s updated_at behind.' );
	}

	/**
	 * Tests that the read-only fields a client sends back are ignored, as core ignores a read-only property.
	 *
	 * @since 0.1.0
	 */
	public function test_read_only_fields_sent_back_are_ignored(): void {
		$b        = $this->secondConnection();
		$saved    = $this->savedProduct();
		$response = $this->request(
			'PUT',
			'/' . $saved->postId,
			array(
				ProductCommerceSchema::PROPERTY => array(
					'price_minor'                      => 100,
					ProductCommerceSchema::SELLABILITY => 'sellable',
					ProductCommerceSchema::GENERATION_STATE => 'complete',
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( '100', $this->committedProduct( $b, $saved )[2] );
	}

	/**
	 * Tests that a `seocart` object carrying only its read-only fields, as the editor may send it back, changes no commerce row and no verdict.
	 *
	 * The product has no price, so it is `incomplete`; the request claims it is complete and on sale.
	 *
	 * @since 0.1.0
	 */
	public function test_read_only_fields_alone_change_nothing(): void {
		$b      = $this->secondConnection();
		$saved  = $this->save(
			null,
			array(
				'post_title'  => 'Without a price',
				'post_status' => 'publish',
			),
			array( 'sku' => 'SKU-RO' )
		);
		$tables = array( CatalogTables::VARIANTS, CatalogTables::VARIANT_PRICES );
		$this->ageCommerceRows( $b, (int) $saved->variantId );
		$before  = array_intersect_key( $this->catalogChecksums( $b ), array_flip( $tables ) );
		$claimed = array(
			ProductCommerceSchema::SELLABILITY      => SellabilityReason::Sellable->value,
			ProductCommerceSchema::GENERATION_STATE => GenerationState::Complete->value,
		);

		$response = $this->request( 'PUT', '/' . $saved->postId, array( ProductCommerceSchema::PROPERTY => $claimed ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, array_intersect_key( $this->catalogChecksums( $b ), array_flip( $tables ) ) );
		$this->assertSame( GenerationState::Incomplete->value, $this->committedMarker( $b, $saved->productId )[0] );
		$this->assertSame( SellabilityReason::Incomplete, $this->verdict( (int) $saved->variantId ) );
	}

	/**
	 * Returns amounts beyond what a price field takes, each as a client sends it in JSON.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: string}> The field and its JSON number.
	 */
	public static function amountsBeyondTheBound(): array {
		return array(
			'a price beyond 64 bits'               => array( 'price_minor', '18446744073709555712' ),
			'a price one past the signed max'      => array( 'price_minor', '9223372036854775808' ),
			'a compare-at price one past 2^53 - 1' => array( 'compare_at_minor', '9007199254740992' ),
		);
	}

	/**
	 * Tests that an amount beyond 2^53 - 1 is refused with 400 before anything is written.
	 *
	 * Planted violation: in CommerceFields::all(), remove `maximum: self::AMOUNT_MAX_MINOR` from
	 * the price: the first case is stored rounded behind a 200, and the second fails with a 500.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider amountsBeyondTheBound
	 *
	 * @param string $field  The price field.
	 * @param string $amount The amount, as a JSON number.
	 */
	public function test_an_amount_beyond_the_bound_is_refused_before_anything_is_written( string $field, string $amount ): void {
		$b      = $this->secondConnection();
		$saved  = $this->savedProduct();
		$before = $this->catalogChecksums( $b );
		$saves  = $this->committedSavesOfAll( $b );

		$response = $this->requestJson( 'PUT', '/' . $saved->postId, sprintf( '{"title":"Not saved","%s":{"%s":%s}}', ProductCommerceSchema::PROPERTY, $field, $amount ) );

		$this->assertSame( 400, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null );
		$this->assertSame( $before, $this->catalogChecksums( $b ) );
		$this->assertSame( $saves, $this->committedSavesOfAll( $b ) );
		$this->assertSame( 'Saved product', $this->committedProduct( $b, $saved )[0] );
		$this->assertSame( array(), $this->failures );
	}

	/**
	 * Tests that the largest amount a price field takes, 2^53 - 1, is stored exactly.
	 *
	 * @since 0.1.0
	 */
	public function test_the_largest_amount_is_stored_exactly(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedProduct();

		$response = $this->requestJson( 'PUT', '/' . $saved->postId, sprintf( '{"%s":{"price_minor":%d}}', ProductCommerceSchema::PROPERTY, CommerceFields::AMOUNT_MAX_MINOR ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( (string) CommerceFields::AMOUNT_MAX_MINOR, $this->committedProduct( $b, $saved )[2] );
	}

	/**
	 * Tests that a commerce value another plugin changes after WordPress validated the request is refused with 400, never taken for a server failure.
	 *
	 * Planted violation: in ProductPostsController::commerceInput(), let the InvalidArgumentException
	 * go: the save fails with a 500 and reports an unexpected failure.
	 *
	 * @since 0.1.0
	 */
	public function test_a_value_changed_after_validation_is_refused_with_400(): void {
		$b      = $this->secondConnection();
		$saved  = $this->savedProduct();
		$before = $this->catalogChecksums( $b );

		add_filter(
			'rest_request_before_callbacks',
			static function ( $response, $handler, \WP_REST_Request $request ) {
				$request->set_param( ProductCommerceSchema::PROPERTY, array( 'price_minor' => -1 ) );

				return $response;
			},
			10,
			3
		);

		$response = $this->request( 'PUT', '/' . $saved->postId, array( ProductCommerceSchema::PROPERTY => array( 'price_minor' => 1 ) ) );

		$this->assertSame( 400, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null );
		$this->assertSame( $before, $this->catalogChecksums( $b ) );
		$this->assertSame( array(), $this->failures, 'A refused value was reported as the server\'s failure.' );
	}

	/**
	 * Tests that a `rest_prepare_seocart_product` filter sees the `seocart` object and can change it, as it can any field of the response.
	 *
	 * Planted violation: in ProductPostsController, add the object after parent::prepare_item_for_response()
	 * has returned instead of in add_additional_fields_to_object(): the filter sees no `seocart`.
	 *
	 * @since 0.1.0
	 */
	public function test_the_prepare_filter_sees_and_changes_the_commerce_object(): void {
		$saved = $this->savedProduct();
		$seen  = array();

		add_filter(
			'rest_prepare_' . ProductCapabilities::POST_TYPE,
			static function ( \WP_REST_Response $response ) use ( &$seen ): \WP_REST_Response {
				$data   = (array) $response->get_data();
				$seen[] = $data[ ProductCommerceSchema::PROPERTY ]['sku'] ?? null;

				$data[ ProductCommerceSchema::PROPERTY ]['sku'] = 'FILTERED';

				$response->set_data( $data );

				return $response;
			}
		);

		$data = $this->request( 'GET', '/' . $saved->postId )->get_data();

		$this->assertSame( array( 'SKU-1' ), $seen, 'The filter did not see the object.' );
		$this->assertSame( 'FILTERED', $data[ ProductCommerceSchema::PROPERTY ]['sku'] ?? null );
	}

	/**
	 * Tests core's sequence around one create and one update: which hooks run, in which order, once each, inside or after the save, and with what.
	 *
	 * `save_post_seocart_product` runs inside the save's window, at transaction depth 1; every
	 * other step runs after the commit, at depth 0: `rest_insert_seocart_product`, another plugin's
	 * REST field update, `rest_after_insert_seocart_product` and `wp_after_insert_post`, which gets
	 * the post as it was before an update.
	 *
	 * Planted violations, each confirmed to fail this test: in ProductPostsController::write(),
	 * delete the fireAfterInsert() call; delete both do_action() calls; pass null for `$before`.
	 *
	 * @since 0.1.0
	 */
	public function test_core_sequence_runs_once_each_in_order_around_the_save(): void {
		$seen = array();

		$this->recordSequence( $seen );

		$created = $this->request(
			'POST',
			'',
			array(
				'title'                         => 'Created in sequence',
				'status'                        => 'publish',
				'thirdparty'                    => 'first',
				ProductCommerceSchema::PROPERTY => array(
					'sku'         => 'SKU-SEQUENCE',
					'price_minor' => 1999,
				),
			)
		);

		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$this->assertSame(
			array(
				array( 'save_post_seocart_product', 1, false ),
				array( 'rest_insert_seocart_product', 0, true ),
				array( 'thirdparty update', 0 ),
				array( 'rest_after_insert_seocart_product', 0, true ),
				array( 'wp_after_insert_post', 0, false, null ),
			),
			$seen,
			'The create ran core\'s sequence otherwise.'
		);

		$seen = array();

		$updated = $this->request(
			'PUT',
			'/' . (int) $created->get_data()['id'],
			array(
				'title'      => 'Renamed in sequence',
				'thirdparty' => 'second',
			)
		);

		$this->assertSame( 200, $updated->get_status(), (string) wp_json_encode( $updated->get_data() ) );
		$this->assertSame(
			array(
				array( 'save_post_seocart_product', 1, true ),
				array( 'rest_insert_seocart_product', 0, false ),
				array( 'thirdparty update', 0 ),
				array( 'rest_after_insert_seocart_product', 0, false ),
				array( 'wp_after_insert_post', 0, true, 'Created in sequence' ),
			),
			$seen,
			'The update ran core\'s sequence otherwise.'
		);
	}

	/**
	 * Tests that the kernel serves the endpoint with the controller its container builds, and that core's routes use that instance.
	 *
	 * The test's own controller is taken out, so the one the kernel installs on `rest_api_init`
	 * is the one served, as on a site.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernel_installs_its_own_controller(): void {
		remove_all_actions( 'rest_api_init', 50 );
		self::discardRestServer();

		$server     = rest_get_server();
		$controller = Kernel::container()->get( ProductPostsController::class );
		$type       = get_post_type_object( ProductCapabilities::POST_TYPE );

		$this->assertSame( $controller, $type?->get_rest_controller(), 'The post type does not serve the container\'s controller.' );

		$handlers = $server->get_routes()[ self::ROUTE . '/(?P<id>[\d]+)' ] ?? array();

		$this->assertNotSame( array(), $handlers );

		foreach ( $handlers as $handler ) {
			$this->assertSame( $controller, $handler['callback'][0] ?? null, 'A route is served by another instance.' );
		}
	}

	/**
	 * Tests that the autosave and revision routes use the installed controller, even when WordPress built their controllers earlier.
	 *
	 * WordPress keeps the two controllers on the post type once built, each holding the parent it
	 * found then. Here they are built before any server, around an instance such as WordPress makes
	 * on its own, which has no services.
	 *
	 * @since 0.1.0
	 */
	public function test_the_autosave_and_revision_routes_use_the_installed_controller(): void {
		$type = get_post_type_object( ProductCapabilities::POST_TYPE );

		$this->assertInstanceOf( \WP_Post_Type::class, $type );

		$type->rest_controller           = new ProductPostsController( ProductCapabilities::POST_TYPE );
		$type->autosave_rest_controller  = new \WP_REST_Autosaves_Controller( ProductCapabilities::POST_TYPE );
		$type->revisions_rest_controller = new \WP_REST_Revisions_Controller( ProductCapabilities::POST_TYPE );

		$this->assertSame( $type->rest_controller, $this->parentOf( $type->get_autosave_rest_controller(), \WP_REST_Autosaves_Controller::class ) );
		$this->assertSame( $type->rest_controller, $this->parentOf( $type->get_revisions_rest_controller(), \WP_REST_Revisions_Controller::class ) );

		self::discardRestServer();

		$routes = rest_get_server()->get_routes();

		foreach ( array(
			self::ROUTE . '/(?P<id>[\d]+)/autosaves'     => \WP_REST_Autosaves_Controller::class,
			self::ROUTE . '/(?P<parent>[\d]+)/revisions' => \WP_REST_Revisions_Controller::class,
		) as $route => $coreClass ) {
			$this->assertNotSame( array(), $routes[ $route ] ?? array(), "No route {$route}." );

			foreach ( $routes[ $route ] as $handler ) {
				$this->assertSame( $this->controller, $this->parentOf( $handler['callback'][0] ?? null, $coreClass ), "{$route} works through another instance of the product controller." );
			}
		}
	}

	/**
	 * Tests that nothing is built or installed for a post type that is not registered, or that another class serves.
	 *
	 * Core's constructor reads the registered post type: a controller built for a type another
	 * plugin unregistered would warn on every REST request.
	 *
	 * @since 0.1.0
	 */
	public function test_nothing_is_built_for_a_type_the_plugin_does_not_serve(): void {
		$builds = 0;
		$build  = function () use ( &$builds ): ProductPostsController {
			++$builds;

			return $this->controller;
		};
		$core   = static function ( array $args, string $name ): array {
			if ( ProductCapabilities::POST_TYPE === $name ) {
				$args['rest_controller_class'] = \WP_REST_Posts_Controller::class;
			}

			return $args;
		};

		unregister_post_type( ProductCapabilities::POST_TYPE );

		try {
			ProductPostsController::install( ProductCapabilities::POST_TYPE, $build );

			add_filter( 'register_post_type_args', $core, 10, 2 );
			ProductPostType::register();
			ProductPostsController::install( ProductCapabilities::POST_TYPE, $build );

			$served = get_post_type_object( ProductCapabilities::POST_TYPE )?->get_rest_controller();

			$this->assertInstanceOf( \WP_REST_Posts_Controller::class, $served );
			$this->assertNotInstanceOf( ProductPostsController::class, $served, 'The slot of a type another class serves was filled.' );
		} finally {
			remove_filter( 'register_post_type_args', $core, 10 );
			ProductPostType::register();
		}

		$this->assertSame( 0, $builds, 'A controller was built for a post type the plugin does not serve.' );
	}

	/**
	 * Returns the parent controller core's autosave or revision controller holds.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null  $controller The autosave or revision controller.
	 * @param class-string $coreClass  The core class that declares the private `parent_controller`.
	 * @return mixed The parent controller.
	 */
	private function parentOf( ?object $controller, string $coreClass ): mixed {
		$this->assertInstanceOf( $coreClass, $controller );

		return ( new \ReflectionProperty( $coreClass, 'parent_controller' ) )->getValue( $controller );
	}

	/**
	 * Records, from now on, each step of core's sequence for a product post: the hook, the transaction depth it ran at, and what it was told.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array<int, mixed>> $seen Receives one entry per step, in order.
	 */
	private function recordSequence( array &$seen ): void {
		$type = ProductCapabilities::POST_TYPE;

		add_action(
			'save_post_' . $type,
			function ( $postId, $post, $update ) use ( &$seen, $type ): void {
				$seen[] = array( 'save_post_' . $type, $this->db->depth(), (bool) $update );
			},
			10,
			3
		);
		add_action(
			'rest_insert_' . $type,
			function ( $post, $request, $creating ) use ( &$seen, $type ): void {
				$seen[] = array( 'rest_insert_' . $type, $this->db->depth(), (bool) $creating );
			},
			10,
			3
		);
		register_rest_field(
			$type,
			'thirdparty',
			array(
				'get_callback'    => static fn(): string => '',
				'update_callback' => function () use ( &$seen ): bool {
					$seen[] = array( 'thirdparty update', $this->db->depth() );

					return true;
				},
				'schema'          => null,
			)
		);
		add_action(
			'rest_after_insert_' . $type,
			function ( $post, $request, $creating ) use ( &$seen, $type ): void {
				$seen[] = array( 'rest_after_insert_' . $type, $this->db->depth(), (bool) $creating );
			},
			10,
			3
		);
		add_action(
			'wp_after_insert_post',
			function ( $postId, $post, $update, $before ) use ( &$seen, $type ): void {
				if ( $post instanceof \WP_Post && $type === $post->post_type ) {
					$seen[] = array( 'wp_after_insert_post', $this->db->depth(), (bool) $update, $before instanceof \WP_Post ? $before->post_title : null );
				}
			},
			10,
			4
		);
	}

	/**
	 * Moves a variant's and its prices' updated_at five seconds into the past, committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $variantId The variant.
	 */
	private function ageCommerceRows( SecondConnection $b, int $variantId ): void {
		$b->query( sprintf( 'UPDATE `%s` SET updated_at = updated_at - INTERVAL 5 SECOND WHERE id = %d', $this->db->table( CatalogTables::VARIANTS ), $variantId ) );
		$b->query( sprintf( 'UPDATE `%s` SET updated_at = updated_at - INTERVAL 5 SECOND WHERE variant_id = %d', $this->db->table( CatalogTables::VARIANT_PRICES ), $variantId ) );
	}
}

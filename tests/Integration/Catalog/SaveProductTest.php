<?php
/**
 * Tests SaveProduct against MySQL and WordPress: a save writes the post and every commerce row, or refuses
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Catalog\ProductWrites;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;

/**
 * What a save commits, what it settles, and what it refuses.
 *
 * Planted violations, each confirmed to fail a test here:
 * - In SaveProduct::finish(), drop the publish() call: the first save's window holds no event,
 *   and none is committed.
 * - In SaveProduct::finish(), ignore what leaveUpdating() answers: the conflicting save succeeds.
 * - In SaveProduct::firstBind(), let the DuplicateKey through: the raced bind is
 *   `database.duplicate_key`, not `catalog.write_conflict`.
 * - In WordPressPostGateway::reportForeignListeners(), drop `$this->reported = true`: the
 *   listener is reported twice.
 * - In WordPressPostGateway::write(), drop the clean_post_cache() before wp_update_post(): the
 *   post another writer made a draft is published again.
 *
 * @since 0.1.0
 */
final class SaveProductTest extends ProductWriteTestCase {

	/**
	 * Tests that a first save binds a whole product, and that its one event is stored in the same window, unseen by others until the commit.
	 *
	 * @since 0.1.0
	 */
	public function test_a_first_save_binds_a_whole_product_with_its_one_event(): void {
		$b      = $this->secondConnection();
		$window = array();

		$this->beforeStatement(
			'/^RELEASE SAVEPOINT sc_0$/',
			function () use ( $b, &$window ): void {
				$window = array(
					'events in the window' => (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE event_name = %s', $this->db->table( OutboxTable::NAME ), ProductSaved::eventName() ),
					'events committed'     => $this->committedCount( $b, OutboxTable::NAME ),
					'products committed'   => $this->committedCount( $b, CatalogTables::PRODUCTS ),
				);
			}
		);

		$saved = $this->savedProduct( 'SKU-1', 'A whole product' );

		$this->assertSame(
			array(
				'events in the window' => 1,
				'events committed'     => 0,
				'products committed'   => 0,
			),
			$window,
			'The event is written inside the window, and nothing is visible before the commit.'
		);
		$this->assertTrue( $saved->postCreated );
		$this->assertSame( GenerationState::Complete, $saved->generation );
		$this->assertSame( SellabilityReason::Sellable, $saved->sellability );
		$this->assertSame( array( 'complete' ), array_slice( $this->committedMarker( $b, $saved->productId ), 0, 1 ) );
		$this->assertSame( array( 'A whole product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCT_POSTS, sprintf( "post_id = %d AND product_id = %d AND locale = '%s'", $saved->postId, $saved->productId, get_locale() ) ), 'The post is bound in the site locale.' );
		$this->assertSame( 1, $this->committedStockItems( $b, (int) $saved->variantId ), 'The default variant has its stock item, at zero.' );
		$this->assertSame( 1, $this->committedSaves( $b, $saved->productId ) );
		$this->assertSame( ProductCapabilities::POST_TYPE, get_post_type( $saved->postId ) );

		$stored  = json_decode( (string) $b->fetchValue( sprintf( "SELECT payload_json FROM `%s` WHERE event_name = '%s'", $this->db->table( OutboxTable::NAME ), ProductSaved::eventName() ) ), true );
		$payload = (array) ( $stored['p'] ?? array() );

		$this->assertSame( array( SaveProduct::POST_FIELDS, 'sku', 'price_minor' ), $payload['changed_fields'] ?? null );
		$this->assertSame( array( 'SKU-1', 1999, self::BASE_CURRENCY ), array( $payload['sku'] ?? null, $payload['price_minor'] ?? null, $payload['currency'] ?? null ) );
	}

	/**
	 * Tests that an update writes the post and the price together, keeps the one stock item, and stores one more event.
	 *
	 * @since 0.1.0
	 */
	public function test_an_update_writes_the_post_and_the_commerce_rows_together(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedProduct();

		$updated = $this->save( $saved->postId, array( 'post_title' => 'Renamed' ), array( 'price_minor' => 2499 ) );

		$this->assertSame( array( $saved->productId, $saved->variantId, false, GenerationState::Complete, SellabilityReason::Sellable ), array( $updated->productId, $updated->variantId, $updated->postCreated, $updated->generation, $updated->sellability ) );
		$this->assertSame( array( 'Renamed', 'SKU-1', '2499' ), $this->committedProduct( $b, $saved ) );
		$this->assertSame( 1, $this->committedStockItems( $b, (int) $saved->variantId ), 'The stock item is created once.' );
		$this->assertSame( 2, $this->committedSaves( $b, $saved->productId ) );
		$this->assertSame( 'Renamed', get_post( $saved->postId )?->post_title );
	}

	/**
	 * Tests that a save without a price settles `incomplete`, a state and not an error, and that a later save with a price completes the product.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_without_a_price_settles_incomplete(): void {
		$b     = $this->secondConnection();
		$saved = $this->save(
			null,
			array(
				'post_title'  => 'No price yet',
				'post_status' => 'publish',
			),
			array( 'sku' => 'SKU-NP' )
		);

		$this->assertSame( GenerationState::Incomplete, $saved->generation );
		$this->assertNotSame( SellabilityReason::Sellable, $saved->sellability );
		$this->assertSame( $this->verdict( (int) $saved->variantId ), $saved->sellability );
		$this->assertSame( 'incomplete', $this->committedMarker( $b, $saved->productId )[0] );
		$this->assertSame( 1, $this->committedSaves( $b, $saved->productId ), 'An incomplete save is still a save.' );

		$priced = $this->save( $saved->postId, array(), array( 'price_minor' => 500 ) );

		$this->assertSame( array( GenerationState::Complete, SellabilityReason::Sellable ), array( $priced->generation, $priced->sellability ) );
	}

	/**
	 * Tests that a save of an unbound post that names no SKU binds it without a variant, `incomplete`, and that a later save with a SKU gives it one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_that_names_no_sku_binds_the_post_without_a_variant(): void {
		$b      = $this->secondConnection();
		$postId = $this->post();
		$saved  = $this->save( $postId );

		$this->assertSame( array( null, GenerationState::Incomplete, null ), array( $saved->variantId, $saved->generation, $saved->sellability ) );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCT_POSTS, 'post_id = ' . $postId ) );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::VARIANTS, 'product_id = ' . $saved->productId ) );

		$whole = $this->save(
			$postId,
			array(),
			array(
				'sku'         => 'SKU-LATER',
				'price_minor' => 700,
			)
		);

		$this->assertSame( array( $saved->productId, GenerationState::Complete, SellabilityReason::Sellable ), array( $whole->productId, $whole->generation, $whole->sellability ) );
		$this->assertSame( 1, $this->committedStockItems( $b, (int) $whole->variantId ) );
	}

	/**
	 * Tests that an update merges its fields into the post as it is committed when the window opens, not into a copy cached before.
	 *
	 * Another writer makes the post a draft between the mark and the relock, after the save has
	 * read the post; the save names the title only, and the post stays a draft.
	 *
	 * @since 0.1.0
	 */
	public function test_an_update_merges_into_the_post_as_committed(): void {
		global $wpdb;

		$b     = $this->secondConnection();
		$saved = $this->savedProduct();

		$this->beforeStatement(
			'/^' . preg_quote( ProductWrites::markStatement( $this->db, $saved->productId ), '/' ) . '$/',
			static function () use ( $b, $wpdb, $saved ): void {
				$b->query( sprintf( "UPDATE `%s` SET post_status = 'draft' WHERE ID = %d", $wpdb->posts, $saved->postId ) );
			},
			2
		);

		$this->save( $saved->postId, array( 'post_title' => 'Renamed only' ) );

		$this->assertSame( 'draft', $b->fetchValue( sprintf( 'SELECT post_status FROM `%s` WHERE ID = %d', $wpdb->posts, $saved->postId ) ), 'The save put back the status it had cached.' );
		$this->assertSame( 'Renamed only', $this->committedProduct( $b, $saved )[0] );
	}

	/**
	 * Tests that a price in another currency than the base one is refused, and nothing is written.
	 *
	 * @since 0.1.0
	 */
	public function test_a_price_in_another_currency_is_refused(): void {
		$b       = $this->secondConnection();
		$failure = $this->saveFailure(
			fn() => $this->save(
				null,
				array( 'post_title' => 'Priced in euros' ),
				array(
					'sku'         => 'SKU-EUR',
					'price_minor' => 100,
					'currency'    => 'EUR',
				)
			)
		);

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::CurrencyNotBase, $failure->errorCode() );
		$this->assertSame(
			array(
				'currency'      => 'EUR',
				'base_currency' => self::BASE_CURRENCY,
			),
			$failure->context()
		);
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
		$this->assertSame( '0', $b->fetchValue( sprintf( "SELECT COUNT(*) FROM `%s` WHERE post_title = 'Priced in euros'", $GLOBALS['wpdb']->posts ) ), 'No post was written.' );
	}

	/**
	 * Tests that a post WordPress refuses is `catalog.post_rejected`, with WordPress's code, and nothing is written.
	 *
	 * @since 0.1.0
	 */
	public function test_a_post_wordpress_refuses_is_post_rejected(): void {
		$b = $this->secondConnection();

		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$failure = $this->saveFailure(
			fn() => $this->save(
				null,
				array( 'post_title' => 'Refused by WordPress' ),
				array(
					'sku'         => 'SKU-REFUSED',
					'price_minor' => 100,
				)
			)
		);

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::PostRejected, $failure->errorCode() );
		$this->assertSame( array( 'wordpress_code' => 'empty_content' ), $failure->context() );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
		$this->assertSame( 0, $this->committedCount( $b, OutboxTable::NAME ) );
	}

	/**
	 * Tests that a SKU another product holds is `catalog.sku_taken` on a first save, and the new post is not kept.
	 *
	 * @since 0.1.0
	 */
	public function test_a_first_save_with_a_taken_sku_keeps_nothing(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedProduct( 'SKU-1' );

		$failure = $this->saveFailure(
			fn() => $this->save(
				null,
				array( 'post_title' => 'A second product' ),
				array(
					'sku'         => 'sku-1',
					'price_minor' => 100,
				)
			)
		);

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::SkuTaken, $failure->errorCode(), 'Never database.duplicate_key.' );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
		$this->assertSame( '0', $b->fetchValue( sprintf( "SELECT COUNT(*) FROM `%s` WHERE post_title = 'A second product'", $GLOBALS['wpdb']->posts ) ) );
		$this->assertSame( 1, $this->committedSaves( $b, $saved->productId ) );
	}

	/**
	 * Tests that a settle statement that changes no row fails the save as `catalog.write_conflict`, and the save's rows are rolled back.
	 *
	 * A listener takes the product out of `updating` inside the window, so the settle statement,
	 * which asserts the marker it replaces, finds none to replace.
	 *
	 * @since 0.1.0
	 */
	public function test_a_settle_that_finds_no_mark_is_a_write_conflict(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedProduct();

		add_action(
			'save_post_' . ProductCapabilities::POST_TYPE,
			function () use ( $saved ): void {
				$this->db->execute( "UPDATE %i SET generation_state = 'complete' WHERE id = %d", $this->db->table( CatalogTables::PRODUCTS ), $saved->productId );
			}
		);

		$failure = $this->saveFailure( fn() => $this->save( $saved->postId, array( 'post_title' => 'Conflicting' ), array( 'price_minor' => 2500 ) ) );

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::WriteConflict, $failure->errorCode() );
		$this->assertSame( array( 'post_id' => $saved->postId ), $failure->context() );
		$this->assertSame( array( 'Saved product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
		$this->assertSame( 'complete', $this->committedMarker( $b, $saved->productId )[0], 'The refused save put back the marker it found.' );
		$this->assertSame( 1, $this->committedSaves( $b, $saved->productId ) );
	}

	/**
	 * Tests that a first save racing another writer that binds the same post is `catalog.write_conflict`, and keeps nothing of its own.
	 *
	 * @since 0.1.0
	 */
	public function test_a_first_save_that_loses_the_bind_to_another_writer_is_a_write_conflict(): void {
		$b      = $this->secondConnection();
		$postId = $this->post();

		// Another writer, such as the reconciler, binds the post after the save read it as unbound.
		add_action(
			'save_post_' . ProductCapabilities::POST_TYPE,
			function ( $savedPost ) use ( $b ): void {
				$b->query( sprintf( "INSERT INTO `%s` ( uuid, source_post_id, generation_state, created_at, updated_at ) VALUES ( '00000000-0000-4000-8000-00000000abcd', %d, 'incomplete', UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )", $this->db->table( CatalogTables::PRODUCTS ), (int) $savedPost ) );
			}
		);

		$failure = $this->saveFailure(
			fn() => $this->save(
				$postId,
				array( 'post_title' => 'Raced' ),
				array(
					'sku'         => 'SKU-RACED',
					'price_minor' => 100,
				)
			)
		);

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::WriteConflict, $failure->errorCode(), 'Never database.duplicate_key: the lost bind is a conflict the client can retry.' );
		$this->assertSame( array( 'post_id' => $postId ), $failure->context() );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::VARIANTS ) );
		$this->assertSame( 'Fixture product', get_post( $postId )?->post_title, 'The post write was rolled back.' );
	}

	/**
	 * Tests that, under WP_DEBUG, another plugin's `save_post_seocart_product` callback is reported once per request, and never printed.
	 *
	 * @since 0.1.0
	 */
	public function test_another_plugins_save_listener_is_reported_once_under_debug(): void {
		$service  = ProductWrites::service( $this->db, $this->reporter(), true );
		$line     = __LINE__ + 1;
		$listener = static function (): void {};

		add_action( 'save_post_' . ProductCapabilities::POST_TYPE, $listener );

		$this->expectOutputString( '' );

		$this->save( null, array( 'post_title' => 'Watched once' ), array( 'sku' => 'SKU-W1' ), $service );
		$this->save( null, array( 'post_title' => 'Watched twice' ), array( 'sku' => 'SKU-W2' ), $service );

		$reported = array_values( array_filter( $this->reports, static fn( array $report ): bool => ReportCode::ForeignSaveListener->value === $report['code'] ) );

		$this->assertCount( 1, $reported, 'Reported once per request.' );
		$this->assertStringContainsString( sprintf( 'save_post_%s @10 closure in ', ProductCapabilities::POST_TYPE ), (string) ( $reported[0]['context']['callbacks'] ?? '' ) );
		$this->assertStringContainsString( basename( __FILE__ ) . ':' . $line, (string) ( $reported[0]['context']['callbacks'] ?? '' ), 'The report names where the callback is defined.' );

		$quiet = ProductWrites::service( $this->db, $this->reporter(), false );

		$this->reports = array();

		$this->save( null, array( 'post_title' => 'Not watched' ), array( 'sku' => 'SKU-W3' ), $quiet );

		$this->assertSame( array(), $this->reports, 'Without WP_DEBUG nothing is looked for.' );
	}
}

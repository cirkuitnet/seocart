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
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Catalog\ProductWrites;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;

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
 * - In SaveProduct::applyVariantSet(), keep the cap of a save that gives a set for options alone:
 *   options alone on a generation past the cap are refused.
 * - In SaveProduct::applyVariantSet() and SaveProduct::refuseVariantSet(), let the cap grow with
 *   the set whatever gave it: a set of more than 250 variants is taken.
 *
 * @since 0.1.0
 */
final class SaveProductTest extends ProductWriteTestCase {

	use SavesVariantSets;

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

	/**
	 * Tests that six variants of two axes are saved in one save: complete, at the first generation, each sellable, with its prices in two currencies, its label and its option values.
	 *
	 * @since 0.2.0
	 */
	public function test_six_variants_of_two_axes_are_saved_in_one_save(): void {
		$b     = $this->secondConnection();
		$saved = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COF' ) );

		$this->assertSame( array( GenerationState::Complete, SellabilityReason::Sellable, null ), array( $saved->generation, $saved->sellability, $saved->variantId ), 'A product with options has no default variant.' );
		$this->assertSame( array( 'complete', 1, 6, 6 ), $this->committedGeneration( $b, $saved->productId ) );
		$this->assertCount( 6, $saved->variantIds );

		$variants = $this->committedVariants( $b, $saved->productId );

		$this->assertSame( array_values( $saved->variantIds ), array_keys( $variants ) );
		$this->assertSame( array( 1 ), array_values( array_unique( array_column( $variants, 'generation' ) ) ), 'Every row is at the published generation.' );
		$this->assertSame( array( 1 ), array_values( array_unique( array_column( $variants, 'enabled' ) ) ) );
		$this->assertSame(
			'250 g / Whole bean',
			$variants[ $saved->variantIds[ VariantSets::hash(
				array(
					'size'  => '250g',
					'grind' => 'whole',
				)
			) ] ]['label']
		);

		foreach ( $saved->variantIds as $hash => $id ) {
			$this->assertSame( $hash, $variants[ $id ]['hash'] );
			$this->assertSame( SellabilityReason::Sellable, $this->verdict( $id ) );
		}

		$ids = implode( ', ', $saved->variantIds );

		$this->assertSame( 12, $this->committedCount( $b, CatalogTables::VARIANT_PRICES, "variant_id IN ( {$ids} )" ), 'A price in dollars and one in euros, each.' );
		$this->assertSame( 6, $this->committedCount( $b, CatalogTables::VARIANT_PRICES, "variant_id IN ( {$ids} ) AND currency = 'EUR' AND amount_basis = 'gross'" ) );
		$this->assertSame( 12, $this->committedCount( $b, CatalogTables::VARIANT_OPTION_VALUES, "variant_id IN ( {$ids} )" ), 'A value on each axis, each.' );
		$this->assertSame( array( 2, 5 ), array( $this->committedCount( $b, CatalogTables::OPTION_AXES, 'product_id = ' . $saved->productId ), (int) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` ov JOIN `%s` a ON a.id = ov.axis_id WHERE a.product_id = %d', $this->db->table( CatalogTables::OPTION_VALUES ), $this->db->table( CatalogTables::OPTION_AXES ), $saved->productId ) ) ) );
		$this->assertSame( 6, (int) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE variant_id IN ( %s )', $this->db->table( 'stock_items' ), $ids ) ), 'Every variant has its stock item.' );

		$payload = (array) ( json_decode( (string) $b->fetchValue( sprintf( "SELECT payload_json FROM `%s` WHERE event_name = '%s' AND aggregate_id = %d", $this->db->table( OutboxTable::NAME ), ProductSaved::eventName(), $saved->productId ) ), true )['p'] ?? array() );

		$this->assertSame( array( SaveProduct::POST_FIELDS, 'options', 'variants', 'prices' ), $payload['changed_fields'] ?? null );
		$this->assertSame( array( null, null, null ), array( $payload['sku'] ?? null, $payload['price_minor'] ?? null, $payload['currency'] ?? null ), 'A product with options has no single SKU or price.' );
	}

	/**
	 * Tests that a set past the cap, options past their limits and a price in a currency the store does not offer are refused before anything is written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_set_that_cannot_be_saved_is_refused_before_any_write(): void {
		$saved  = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COF' ) );
		$sizes  = array_map( static fn( int $n ): string => 's' . $n, range( 1, 16 ) );
		$grinds = array_map( static fn( int $n ): string => 'g' . $n, range( 1, 16 ) );
		$axes   = array_fill( 0, 11, VariantSets::options( array( 'a' ), array( 'b' ) )[0] );

		foreach ( $axes as $n => $axis ) {
			$axes[ $n ]['key'] = 'axis-' . $n;
		}

		$yen                             = VariantSets::variants( 'COF' );
		$yen[0]['prices'][1]['currency'] = 'JPY';

		$cases = array(
			'256 variants' => array( VariantSets::options( $sizes, $grinds ), VariantSets::variants( 'BIG', $sizes, $grinds, array(), false ), CatalogError::TooManyVariants ),
			'11 axes'      => array( $axes, array(), CatalogError::TooManyOptions ),
			'yen not sold' => array( VariantSets::options(), $yen, CatalogError::CurrencyNotOffered ),
		);

		foreach ( $cases as $name => list( $options, $variants, $code ) ) {
			$failure = null;
			$log     = $this->captureQueries(
				function () use ( $saved, $options, $variants, &$failure ): void {
					$failure = $this->saveFailure( fn() => $this->saveSet( $saved->postId, $options, $variants ) );
				}
			);

			$this->assertInstanceOf( CodedException::class, $failure, $name );
			$this->assertSame( $code, $failure->errorCode(), $name );
			$this->assertSame( array(), $log->ofType( 'INSERT', 'UPDATE', 'DELETE', 'START' )->sqls(), $name . ': refused before the mark, nothing written.' );
		}
	}

	/**
	 * Tests that options alone are accepted on a generation holding more rows than a set may name, since the save gives no variant, and that a save giving more than a set may name is still refused.
	 *
	 * @since 0.2.0
	 */
	public function test_options_alone_pass_a_generation_past_the_cap_and_a_set_past_it_does_not(): void {
		$b       = $this->secondConnection();
		$sizes   = array_map( static fn( int $n ): string => 's' . $n, range( 1, 16 ) );
		$grinds  = array_map( static fn( int $n ): string => 'g' . $n, range( 1, 16 ) );
		$options = VariantSets::options( $sizes, $grinds );
		$all     = VariantSets::variants( 'BIG', $sizes, $grinds, array(), false );
		$saved   = $this->saveSet( null, $options, array_slice( $all, 0, Product::MAX_SYNC_VARIANTS ) );

		// A set that names the last six and leaves out the first six: they stay in the generation, disabled.
		$this->saveSet( $saved->postId, $options, array_slice( $all, 6, Product::MAX_SYNC_VARIANTS ) );

		$held = array( 'complete', 1, 256, 250 );

		$this->assertSame( $held, $this->committedGeneration( $b, $saved->productId ) );

		$options[0]['label'] = 'Renamed';

		$this->save( $saved->postId, array(), array( 'options' => $options ) );

		$this->assertSame( $held, $this->committedGeneration( $b, $saved->productId ) );
		$this->assertSame( 'Renamed', $this->products->find( $saved->productId )?->options()->axis( 'size' )?->label );

		$failure = $this->saveFailure( fn() => $this->saveSet( $saved->postId, $options, array_slice( $all, 0, Product::MAX_SYNC_VARIANTS + 1 ) ) );

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::TooManyVariants, $failure->errorCode() );
		$this->assertSame(
			array(
				'count' => Product::MAX_SYNC_VARIANTS + 1,
				'limit' => Product::MAX_SYNC_VARIANTS,
			),
			$failure->context()
		);
		$this->assertSame( $held, $this->committedGeneration( $b, $saved->productId ) );
	}

	/**
	 * Tests that a value added under the same axes adds its rows at the generation the product publishes, every stored variant keeping its id.
	 *
	 * @since 0.2.0
	 */
	public function test_a_value_added_keeps_the_generation_and_the_ids(): void {
		$b     = $this->secondConnection();
		$six   = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COF' ) );
		$sizes = array( '250g', '1kg', '2kg' );
		$nine  = $this->saveSet( $six->postId, VariantSets::options( $sizes ), VariantSets::variants( 'COF', $sizes ) );

		$this->assertSame( array( 'complete', 1, 9, 9 ), $this->committedGeneration( $b, $six->productId ) );
		$this->assertSame( $six->variantIds, array_intersect_key( $nine->variantIds, $six->variantIds ), 'Every stored combination keeps its id.' );
		$this->assertCount( 9, $nine->variantIds );
	}

	/**
	 * Tests that an axis added moves the product to the next generation, the old rows answering `not_active_generation`, and the former axes restored give the old rows back their ids.
	 *
	 * @since 0.2.0
	 */
	public function test_an_axis_added_moves_the_generation_and_the_former_axes_restore_the_old_rows(): void {
		$b       = $this->secondConnection();
		$six     = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COF' ) );
		$roasted = $this->saveSet( $six->postId, VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ), VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ) );

		$this->assertSame( array( 'complete', 2, 6, 6 ), $this->committedGeneration( $b, $six->productId ) );
		$this->assertSame( array(), array_intersect( $six->variantIds, $roasted->variantIds ), 'Every combination of the new axes is a new variant.' );

		foreach ( $six->variantIds as $id ) {
			$this->assertSame( SellabilityReason::NotActiveGeneration, $this->verdict( $id ), 'The superseded variants stay, unpublished.' );
		}

		$restored = $this->saveSet( $six->postId, VariantSets::options(), VariantSets::variants( 'COF' ) );

		$this->assertSame( array( 'complete', 3, 6, 6 ), $this->committedGeneration( $b, $six->productId ) );
		$this->assertSame( $six->variantIds, $restored->variantIds, 'The former combinations find their rows, ids and SKUs again.' );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( array_values( $restored->variantIds )[0] ) );
		$this->assertSame( 12, $this->committedCount( $b, CatalogTables::VARIANTS, 'product_id = ' . $six->productId ), 'No variant was ever deleted.' );
	}

	/**
	 * Tests that a flat save keeps the basis of the stored price whatever it changes, and that the variant's prices in other currencies survive it.
	 *
	 * Planted violation: in SaveProduct::applyFlatFields(), drop `->onBasisOf( $stored )`; the save
	 * of a weight moves the price to net, and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_flat_save_keeps_the_stored_basis_and_the_other_currencies(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedOnGrossPrices( 'GROSS-1' );
		$id    = (int) $saved->variantId;

		$this->assertSame( array( 'EUR:gross:999', 'USD:gross:1234:2000' ), $this->committedPrices( $b, $id ) );

		$this->save( $saved->postId, array(), array( 'weight_grams' => 8 ) );
		$this->assertSame( array( 'EUR:gross:999', 'USD:gross:1234:2000' ), $this->committedPrices( $b, $id ), 'A weight.' );

		$this->save( $saved->postId, array(), array( 'sku' => 'GROSS-2' ) );
		$this->assertSame( array( 'EUR:gross:999', 'USD:gross:1234:2000' ), $this->committedPrices( $b, $id ), 'A SKU.' );

		$this->save( $saved->postId, array(), array( 'price_minor' => 1500 ) );
		$this->assertSame( array( 'EUR:gross:999', 'USD:gross:1500:2000' ), $this->committedPrices( $b, $id ), 'A price.' );

		$this->save( $saved->postId, array(), array( 'compare_at_minor' => 3000 ) );
		$this->assertSame( array( 'EUR:gross:999', 'USD:gross:1500:3000' ), $this->committedPrices( $b, $id ), 'A compare-at price.' );

		$this->save( $saved->postId, array(), array( 'compare_at_minor' => null ) );
		$this->assertSame( array( 'EUR:gross:999', 'USD:gross:1500' ), $this->committedPrices( $b, $id ), 'A compare-at price removed.' );

		$this->save( $saved->postId, array(), array( 'price_minor' => null ) );
		$this->assertSame( array( 'EUR:gross:999' ), $this->committedPrices( $b, $id ), 'A price given as null is removed, the others stay.' );
		$this->assertSame( 'incomplete', $this->committedGeneration( $b, $saved->productId )[0] );

		$this->save( $saved->postId, array(), array( 'price_minor' => 1600 ) );
		$this->assertSame( array( 'EUR:gross:999', 'USD:net:1600' ), $this->committedPrices( $b, $id ), 'With no stored price, net as before.' );
	}

	/**
	 * Tests that a flat save of a net price, and of a variant with no price yet, stays net.
	 *
	 * @since 0.2.0
	 */
	public function test_a_flat_save_of_a_net_price_or_of_none_stays_net(): void {
		$b    = $this->secondConnection();
		$net  = $this->savedProduct( 'NET-1' );
		$none = $this->save(
			null,
			array(
				'post_title'  => 'Not yet priced',
				'post_status' => 'publish',
			),
			array( 'sku' => 'NET-2' )
		);

		$this->save( $net->postId, array(), array( 'price_minor' => 2100 ) );
		$this->save( $none->postId, array(), array( 'price_minor' => 2200 ) );

		$this->assertSame( array( 'USD:net:2100' ), $this->committedPrices( $b, (int) $net->variantId ) );
		$this->assertSame( array( 'USD:net:2200' ), $this->committedPrices( $b, (int) $none->variantId ) );
	}

	/**
	 * Tests that the former axes restored without prices find the rows' prices: the product is `complete` and the prices are as they were.
	 *
	 * Planted violation: in Product::applyVariants(), find a combination among the generation's
	 * variants alone; the variant is taken for a new one, and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_former_axes_restored_without_prices_keep_the_stored_prices(): void {
		$b      = $this->secondConnection();
		$six    = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'RES' ) );
		$prices = array_map( fn( int $id ): array => $this->committedPrices( $b, $id ), $six->variantIds );

		$this->saveSet( $six->postId, VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ), VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ) );

		$restored = $this->saveSet( $six->postId, VariantSets::options(), VariantSets::withoutPrices( VariantSets::variants( 'RES' ) ) );

		$this->assertSame( $six->variantIds, $restored->variantIds, 'The former combinations find their rows.' );
		$this->assertSame( GenerationState::Complete, $restored->generation );
		$this->assertSame( SellabilityReason::Sellable, $restored->sellability );
		$this->assertSame( array( 'complete', 3, 6, 6 ), $this->committedGeneration( $b, $six->productId ) );
		$this->assertSame( $prices, array_map( fn( int $id ): array => $this->committedPrices( $b, $id ), $restored->variantIds ), 'The prices are as they were.' );
		$this->assertSame( array( 'EUR:gross:1100', 'USD:net:1200' ), $prices[ array_key_first( $prices ) ], 'Each on its basis.' );

		foreach ( $restored->variantIds as $id ) {
			$this->assertSame( SellabilityReason::Sellable, $this->verdict( $id ) );
		}
	}

	/**
	 * Tests that a restore that gives prices uses them, its variants' stored prices leaving with them, and the variants it gives none for keep theirs.
	 *
	 * @since 0.2.0
	 */
	public function test_a_restore_that_gives_prices_uses_them(): void {
		$b        = $this->secondConnection();
		$six      = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'RES' ) );
		$variants = VariantSets::withoutPrices( VariantSets::variants( 'RES' ) );
		$ids      = array_values( $six->variantIds );

		$this->saveSet( $six->postId, VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ), VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ) );

		$variants[0]['prices']  = array(
			array(
				'currency'     => 'USD',
				'price_minor'  => 5555,
				'amount_basis' => 'gross',
			),
		);
		$variants[1]['prices']  = array();
		$variants[1]['enabled'] = false;

		$restored = $this->saveSet( $six->postId, VariantSets::options(), $variants );

		$this->assertSame( GenerationState::Complete, $restored->generation );
		$this->assertSame( array( 'USD:gross:5555' ), $this->committedPrices( $b, $ids[0] ), 'The prices given, the others gone.' );
		$this->assertSame( array(), $this->committedPrices( $b, $ids[1] ), 'None given: none left, and it is disabled.' );
		$this->assertSame( array( 'EUR:gross:1300', 'USD:net:1400' ), $this->committedPrices( $b, $ids[2] ), 'Not given: as they were.' );
	}

	/**
	 * Tests that a disabled variant of weight, its former axes restored with `enabled`, its weight and its prices left out, stays as it was: disabled, weighed, priced, and not on sale.
	 *
	 * Planted violations, each failing here: in Product::applyVariants(), take `enabled` or the weight
	 * from the generation's variants alone; the variant goes on sale, or loses its weight.
	 *
	 * @since 0.2.0
	 */
	public function test_former_axes_restored_keep_what_the_set_leaves_out_their_disabled_state_included(): void {
		$b        = $this->secondConnection();
		$variants = VariantSets::variants( 'KEEP' );

		$variants[0]['enabled'] = false;

		$six    = $this->saveSet( null, VariantSets::options(), $variants );
		$ids    = array_values( $six->variantIds );
		$prices = array_map( fn( int $id ): array => $this->committedPrices( $b, $id ), $six->variantIds );

		$this->assertSame( array( 'complete', 1, 6, 5 ), $this->committedGeneration( $b, $six->productId ) );

		$this->saveSet( $six->postId, VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ), VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ) );

		$restored = $this->saveSet( $six->postId, VariantSets::options(), VariantSets::bare( VariantSets::variants( 'KEEP' ) ) );
		$rows     = $this->committedVariants( $b, $six->productId );

		$this->assertSame( $six->variantIds, $restored->variantIds, 'The rows keep their ids.' );
		$this->assertSame( array( 'complete', 3, 6, 5 ), $this->committedGeneration( $b, $six->productId ), 'The one disabled stays out of the count.' );
		$this->assertSame( 0, $rows[ $ids[0] ]['enabled'], 'A variant the merchant disabled stays disabled.' );
		$this->assertSame( SellabilityReason::VariantDisabled, $this->verdict( $ids[0] ), 'It is not on sale.' );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( $ids[1] ) );
		$this->assertSame( array( '250', '251', '252', '253', '254', '255' ), array_map( fn( int $id ): ?string => $this->committedWeight( $b, $id ), $ids ), 'Every weight.' );
		$this->assertSame( $prices, array_map( fn( int $id ): array => $this->committedPrices( $b, $id ), $restored->variantIds ), 'Every price.' );
		$this->assertSame( array( '250 g / Whole bean', 3 ), array( $rows[ $ids[0] ]['label'], $rows[ $ids[0] ]['generation'] ), 'Its label, in the generation restored.' );
	}

	/**
	 * Tests that what a restore gives wins over what the rows held: `enabled` either way, a weight or none, and prices.
	 *
	 * Planted violation: in Product::applyVariants(), let the row's `enabled` and weight win over the
	 * ones given; the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_restore_that_gives_values_uses_them(): void {
		$b        = $this->secondConnection();
		$variants = VariantSets::variants( 'GIVE' );

		$variants[0]['enabled'] = false;

		$six = $this->saveSet( null, VariantSets::options(), $variants );
		$ids = array_values( $six->variantIds );

		$this->saveSet( $six->postId, VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ), VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ) );

		$again = VariantSets::bare( VariantSets::variants( 'GIVE' ) );

		$again[0]['enabled']      = true;
		$again[0]['weight_grams'] = 999;
		$again[1]['enabled']      = false;
		$again[1]['weight_grams'] = null;
		$again[2]['prices']       = array(
			array(
				'currency'         => 'USD',
				'price_minor'      => 5555,
				'compare_at_minor' => 6000,
				'amount_basis'     => 'gross',
			),
		);

		$this->saveSet( $six->postId, VariantSets::options(), $again );

		$rows = $this->committedVariants( $b, $six->productId );

		$this->assertSame( array( 1, '999' ), array( $rows[ $ids[0] ]['enabled'], $this->committedWeight( $b, $ids[0] ) ), 'Enabled, and weighed as given.' );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( $ids[0] ) );
		$this->assertSame( array( 0, null ), array( $rows[ $ids[1] ]['enabled'], $this->committedWeight( $b, $ids[1] ) ), 'Disabled, and with no weight, as given.' );
		$this->assertSame( SellabilityReason::VariantDisabled, $this->verdict( $ids[1] ) );
		$this->assertSame( array( 'USD:gross:5555:6000' ), $this->committedPrices( $b, $ids[2] ), 'The prices given, whole.' );
	}

	/**
	 * Tests that a flat save that brings back a product's former default variant does to its row what the same save does to a default variant it publishes.
	 *
	 * Planted violation: in Product::giveDefaultVariant(), give the new variant rather than the row
	 * found; every case fails.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider flatFieldsOnARow
	 *
	 * @param array       $flat   The flat fields, the SKU aside.
	 * @param string[]    $prices The prices the row ends with.
	 * @param string|null $weight The weight it ends with.
	 * @param string      $marker The marker the product ends with.
	 *
	 * @phpstan-param array<string, int|null> $flat
	 * @phpstan-param list<string> $prices
	 */
	public function test_a_flat_save_that_brings_back_the_default_variant_applies_as_on_a_stored_one( array $flat, array $prices, ?string $weight, string $marker ): void {
		$b     = $this->secondConnection();
		$saved = $this->savedOnGrossPrices( 'BACK-1' );
		$twin  = $this->savedOnGrossPrices( 'TWIN-1' );
		$id    = (int) $saved->variantId;

		$this->saveSet( $saved->postId, VariantSets::options(), VariantSets::variants( 'AXES' ) );
		$this->saveSet( $saved->postId, array(), array() );

		$back = $this->save( $saved->postId, array(), array_merge( array( 'sku' => 'BACK-2' ), $flat ) );

		$this->save( $twin->postId, array(), array_merge( array( 'sku' => 'TWIN-2' ), $flat ) );

		$this->assertSame( $id, $back->variantId, 'The row is found.' );
		$this->assertSame( 'BACK-2', $this->committedVariants( $b, $saved->productId )[ $id ]['sku'] );
		$this->assertSame( array( $prices, $weight, $marker ), array( $this->committedPrices( $b, $id ), $this->committedWeight( $b, $id ), $this->committedGeneration( $b, $saved->productId )[0] ), 'The row brought back.' );
		$this->assertSame( array( $prices, $weight, $marker ), array( $this->committedPrices( $b, (int) $twin->variantId ), $this->committedWeight( $b, (int) $twin->variantId ), $this->committedGeneration( $b, $twin->productId )[0] ), 'A default variant it publishes ends the same.' );
	}

	/**
	 * Returns the flat fields a save gives a default variant, and what they leave of `USD:gross:1234:2000`, `EUR:gross:999` and a weight of 7.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: array<string, int|null>, 1: list<string>, 2: string|null, 3: string}> The cases.
	 */
	public static function flatFieldsOnARow(): array {
		return array(
			'a SKU alone'          => array( array(), array( 'EUR:gross:999', 'USD:gross:1234:2000' ), '7', 'complete' ),
			'a compare-at price'   => array( array( 'compare_at_minor' => 7777 ), array( 'EUR:gross:999', 'USD:gross:1234:7777' ), '7', 'complete' ),
			'a compare-at removed' => array( array( 'compare_at_minor' => null ), array( 'EUR:gross:999', 'USD:gross:1234' ), '7', 'complete' ),
			'a price'              => array( array( 'price_minor' => 1500 ), array( 'EUR:gross:999', 'USD:gross:1500:2000' ), '7', 'complete' ),
			'a weight'             => array( array( 'weight_grams' => 9 ), array( 'EUR:gross:999', 'USD:gross:1234:2000' ), '9', 'complete' ),
			'a weight removed'     => array( array( 'weight_grams' => null ), array( 'EUR:gross:999', 'USD:gross:1234:2000' ), null, 'complete' ),
			'a price removed'      => array( array( 'price_minor' => null ), array( 'EUR:gross:999' ), '7', 'incomplete' ),
		);
	}

	/**
	 * Tests that a flat save brings a disabled default variant back disabled, with its weight and its prices, and the product does not go on sale.
	 *
	 * Planted violation: in Product::giveDefaultVariant(), give the new variant rather than the row
	 * found; the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_flat_save_brings_a_disabled_default_variant_back_disabled(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedOnGrossPrices( 'OFF-1', false );
		$id    = (int) $saved->variantId;

		$this->saveSet( $saved->postId, VariantSets::options(), VariantSets::variants( 'AXES' ) );
		$this->saveSet( $saved->postId, array(), array() );

		$back = $this->save( $saved->postId, array(), array( 'sku' => 'OFF-2' ) );

		$this->assertSame( $id, $back->variantId );
		$this->assertSame( array( 'incomplete', 3, 1, 0 ), $this->committedGeneration( $b, $saved->productId ) );
		$this->assertSame( array( '7', array( 'EUR:gross:999', 'USD:gross:1234:2000' ) ), array( $this->committedWeight( $b, $id ), $this->committedPrices( $b, $id ) ) );
		$this->assertNotSame( SellabilityReason::Sellable, $this->verdict( $id ) );
	}

	/**
	 * Tests that a variant whose SKU another took still needs a SKU to come back with its former axes, and comes back as it was, disabled until it is enabled.
	 *
	 * Planted violation: in Product::skuOf(), let a variant the generation does not have go without
	 * a SKU; the save is taken, and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_variant_without_a_sku_needs_one_to_come_back(): void {
		$b        = $this->secondConnection();
		$six      = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'TOMB' ) );
		$variants = VariantSets::variants( 'TOMB' );
		$left     = $variants[1];
		$id       = array_values( $six->variantIds )[1];
		$dark     = VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) );
		$roasted  = VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) );

		// The variant of 250 g whole bean takes the SKU of 250 g filter, which the set leaves out.
		$variants[0]['sku'] = $variants[1]['sku'];

		unset( $variants[1] );

		$variants = array_values( $variants );

		$this->saveSet( $six->postId, VariantSets::options(), $variants );
		$this->saveSet( $six->postId, $dark, $roasted );

		$this->assertStringStartsWith( '~', $this->committedVariants( $b, $six->productId )[ $id ]['sku'] );

		$sums = $this->catalogChecksums( $b );

		foreach ( array( array( 'sku' => null ), array() ) as $given ) {
			$entry = array_merge( array( 'combination' => $left['combination'] ), $given );

			$failure = $this->saveFailure( fn() => $this->saveSet( $six->postId, VariantSets::options(), array_merge( VariantSets::bare( $variants ), array( $entry ) ) ) );

			$this->assertInstanceOf( CodedException::class, $failure, get_class( $failure ) );
			$this->assertSame( CatalogError::SkuInvalid, $failure->errorCode() );
			$this->assertSame( $sums, $this->catalogChecksums( $b ), 'Refused before anything is written.' );
		}

		$entry = array(
			'combination' => $left['combination'],
			'sku'         => 'TOMB-NEW',
		);

		$this->saveSet( $six->postId, VariantSets::options(), array_merge( VariantSets::bare( $variants ), array( $entry ) ) );

		$rows = $this->committedVariants( $b, $six->productId );

		$this->assertSame( array( 'TOMB-NEW', 0 ), array( $rows[ $id ]['sku'], $rows[ $id ]['enabled'] ), 'With a SKU of its own, as it was: disabled.' );
		$this->assertSame( SellabilityReason::VariantDisabled, $this->verdict( $id ) );

		$this->saveSet( $six->postId, $dark, $roasted );

		$entry['enabled'] = true;

		$this->saveSet( $six->postId, VariantSets::options(), array_merge( VariantSets::bare( $variants ), array( $entry ) ) );

		$this->assertSame( SellabilityReason::Sellable, $this->verdict( $id ), 'Enabled, and with a SKU, it is on sale.' );
	}

	/**
	 * Tests that a restore moves SKUs among the variants it brings back and takes them from the generation it leaves, each row keeping its id.
	 *
	 * Planted violation: in MysqlProductRepository::releaseSkus(), send no release; the save collides
	 * with its own rows, and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_restore_moves_skus_within_the_set_and_from_the_generation_it_leaves(): void {
		$b       = $this->secondConnection();
		$six     = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'MOVE' ) );
		$ids     = array_values( $six->variantIds );
		$skus    = array_column( VariantSets::variants( 'MOVE' ), 'sku' );
		$roasted = VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) );

		// A variant of the next generation takes the SKU of the row of 1 kg whole bean.
		$roasted[1]['sku'] = $skus[3];

		$dark     = $this->saveSet( $six->postId, VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ), $roasted );
		$darkIds  = array_values( $dark->variantIds );
		$restored = VariantSets::bare( VariantSets::variants( 'MOVE' ) );

		$this->assertStringStartsWith( '~', $this->committedVariants( $b, $six->productId )[ $ids[3] ]['sku'] );

		$restored[0]['sku'] = $skus[1];
		$restored[1]['sku'] = $skus[0];
		$restored[2]['sku'] = $roasted[0]['sku'];

		$this->assertSame( $six->variantIds, $this->saveSet( $six->postId, VariantSets::options(), $restored )->variantIds );

		$rows = $this->committedVariants( $b, $six->productId );

		$this->assertSame( array( $skus[1], $skus[0], $roasted[0]['sku'], $skus[3] ), array( $rows[ $ids[0] ]['sku'], $rows[ $ids[1] ]['sku'], $rows[ $ids[2] ]['sku'], $rows[ $ids[3] ]['sku'] ), 'Two swapped; one taken from the generation left; one taken back from it.' );
		$this->assertStringStartsWith( '~', $rows[ $darkIds[0] ]['sku'], 'The row that gave up its SKU.' );
		$this->assertStringStartsWith( '~', $rows[ $darkIds[1] ]['sku'], 'The row that gave it back.' );
		$this->assertSame( array( 'complete', 3, 6, 6 ), $this->committedGeneration( $b, $six->productId ) );
	}

	/**
	 * Tests that a variant left out of the set is disabled, with its stock item and its id kept.
	 *
	 * Planted violation: in MysqlProductRepository, delete the variants the set leaves out instead
	 * of disabling them; the row is gone and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_variant_left_out_is_disabled_with_its_stock_item(): void {
		$b        = $this->secondConnection();
		$six      = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COF' ) );
		$variants = VariantSets::variants( 'COF' );
		$left     = $six->variantIds[ VariantSets::hash(
			array(
				'size'  => '1kg',
				'grind' => 'espresso',
			)
		) ];

		array_pop( $variants );

		$five = $this->saveSet( $six->postId, VariantSets::options(), $variants );

		$this->assertSame( array( 'complete', 1, 6, 5 ), $this->committedGeneration( $b, $six->productId ) );
		$this->assertSame( 0, $this->committedVariants( $b, $six->productId )[ $left ]['enabled'] ?? null, 'Left out, the variant is disabled, never deleted.' );
		$this->assertSame(
			$left,
			$five->variantIds[ VariantSets::hash(
				array(
					'size'  => '1kg',
					'grind' => 'espresso',
				)
			) ] ?? null
		);
		$this->assertSame( 1, $this->committedStockItems( $b, $left ), 'Its stock item stays.' );
		$this->assertSame( SellabilityReason::VariantDisabled, $this->verdict( $left ) );
	}

	/**
	 * Tests that another product's SKU on the fourth variant rolls the update back whole, the marker restored, and names the combination.
	 *
	 * @since 0.2.0
	 */
	public function test_another_products_sku_on_the_fourth_variant_rolls_back_whole(): void {
		$b     = $this->secondConnection();
		$other = $this->savedProduct( 'TAKEN-4' );
		$six   = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COF' ) );
		$sums  = $this->catalogChecksums( $b );

		$variants              = VariantSets::variants( 'COF' );
		$variants[3]['sku']    = 'taken-4';
		$variants[0]['sku']    = 'COF-NEW-0';
		$variants[5]['prices'] = array(
			array(
				'currency'    => 'USD',
				'price_minor' => 1,
			),
		);

		$failure = $this->saveFailure( fn() => $this->saveSet( $six->postId, VariantSets::options(), $variants ) );

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::SkuTaken, $failure->errorCode() );
		$this->assertSame( array( 'sku' => 'taken-4' ), $failure->context() );
		$this->assertSame(
			array(
				'combination' => VariantSets::combination(
					array(
						'grind' => 'whole',
						'size'  => '1kg',
					)
				),
			),
			$failure->details(),
			'The combination that asked for it, so an editor marks its row.'
		);
		$this->assertSame( 'complete', $this->committedMarker( $b, $six->productId )[0], 'The marker is put back, at an instant of its own.' );
		$this->assertSame( array_diff_key( $sums, array( CatalogTables::PRODUCTS => true ) ), array_diff_key( $this->catalogChecksums( $b ), array( CatalogTables::PRODUCTS => true ) ), 'Nothing of the save outlived it.' );
		$this->assertSame( 1, $this->committedSaves( $b, $other->productId ) );
	}

	/**
	 * Tests that two of a product's variants swap their SKUs in one save.
	 *
	 * Planted violation: in MysqlProductRepository::releaseSkus(), send no release; the swap then
	 * meets its own SKUs and is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_two_skus_swapped_between_variants_are_one_save(): void {
		$b        = $this->secondConnection();
		$six      = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COF' ) );
		$variants = VariantSets::variants( 'COF' );

		list( $variants[0]['sku'], $variants[1]['sku'] ) = array( $variants[1]['sku'], $variants[0]['sku'] );

		$swapped   = $this->saveSet( $six->postId, VariantSets::options(), $variants );
		$committed = $this->committedVariants( $b, $six->productId );
		$first     = $six->variantIds[ VariantSets::hash(
			array(
				'size'  => '250g',
				'grind' => 'whole',
			)
		) ];
		$second    = $six->variantIds[ VariantSets::hash(
			array(
				'size'  => '250g',
				'grind' => 'filter',
			)
		) ];

		$this->assertSame( $six->variantIds, $swapped->variantIds );
		$this->assertSame( array( 'COF-250g-filter', 'COF-250g-whole' ), array( $committed[ $first ]['sku'], $committed[ $second ]['sku'] ) );
	}

	/**
	 * Tests that a SKU a superseded variant holds is released with a tombstone for the variant that takes it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_superseded_variants_sku_goes_to_the_new_variant_and_leaves_a_tombstone(): void {
		$b        = $this->secondConnection();
		$six      = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COF' ) );
		$old      = $six->variantIds[ VariantSets::hash(
			array(
				'size'  => '250g',
				'grind' => 'whole',
			)
		) ];
		$variants = VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) );

		$variants[0]['sku'] = 'COF-250g-whole';

		$roasted   = $this->saveSet( $six->postId, VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ), $variants );
		$committed = $this->committedVariants( $b, $six->productId );
		$uuid      = (string) $b->fetchValue( sprintf( 'SELECT uuid FROM `%s` WHERE id = %d', $this->db->table( CatalogTables::VARIANTS ), $old ) );

		$this->assertSame(
			'COF-250g-whole',
			$committed[ $roasted->variantIds[ VariantSets::hash(
				array(
					'size'  => '250g',
					'grind' => 'whole',
					'roast' => 'dark',
				)
			) ] ]['sku']
		);
		$this->assertSame( '~' . $uuid, $committed[ $old ]['sku'], 'The superseded variant keeps a tombstone, never a SKU a merchant typed.' );
	}

	/**
	 * Tests that two SKUs of one save that the table's collation finds equal, and the check before the window does not, are one SKU given twice, on a new product and on a stored one, nothing kept.
	 *
	 * Planted violation: in MysqlProductRepository::refuseSkuCollision(), throw the duplicate key
	 * again when no variant holds a SKU of the set; the new product's save is then a write
	 * conflict, and the stored one's `database.duplicate_key`.
	 *
	 * @since 0.2.0
	 */
	public function test_two_skus_equal_in_the_collation_are_one_sku_given_twice(): void {
		$b        = $this->secondConnection();
		$variants = VariantSets::variants( 'ACC' );

		$variants[0]['sku'] = 'cafe-x';
		$variants[1]['sku'] = "CAF\u{00C9}-x";

		$refusal = array(
			'sku'          => "CAF\u{00C9}-x",
			'combinations' => 'grind=filter, size=250g; grind=whole, size=250g',
		);
		$sums    = $this->catalogChecksums( $b );
		$failure = $this->saveFailure( fn() => $this->saveSet( null, VariantSets::options(), $variants ) );

		$this->assertInstanceOf( CodedException::class, $failure, get_class( $failure ) );
		$this->assertSame( array( CatalogError::SkuDuplicate, $refusal ), array( $failure->errorCode(), $failure->context() ), 'A new product.' );
		$this->assertSame( $sums, $this->catalogChecksums( $b ), 'Nothing of the new product was kept.' );

		$six     = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COLL' ) );
		$sums    = $this->catalogChecksums( $b );
		$failure = $this->saveFailure( fn() => $this->saveSet( $six->postId, VariantSets::options(), $variants ) );

		$this->assertInstanceOf( CodedException::class, $failure, get_class( $failure ) );
		$this->assertSame( array( CatalogError::SkuDuplicate, $refusal ), array( $failure->errorCode(), $failure->context() ), 'A stored product.' );
		$this->assertSame( 'complete', $this->committedMarker( $b, $six->productId )[0], 'The marker is put back.' );
		$this->assertSame( array_diff_key( $sums, array( CatalogTables::PRODUCTS => true ) ), array_diff_key( $this->catalogChecksums( $b ), array( CatalogTables::PRODUCTS => true ) ), 'Nothing of the save outlived it.' );
	}
}

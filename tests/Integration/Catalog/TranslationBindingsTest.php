<?php
/**
 * Tests the posts that present a product in each language: linking, unlinking, promoting, deleting and saving through them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Lifecycle\TranslationGroups;
use SEOCart\Catalog\Application\ProductWrite\CommerceInput;
use SEOCart\Catalog\Application\ProductWrite\ProductSave;
use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Event\ProductBindingPromoted;
use SEOCart\Catalog\Domain\Event\ProductDeleted;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;
use SEOCart\Tests\Support\Catalog\ProductWrites;
use SEOCart\Tests\Support\Doubles\SeveralLocales;
use SEOCart\Tests\Support\SecondConnection;

/**
 * A product has one post per language, one of them its source post, and one SKU, one price and one stock whichever post presents it.
 *
 * The services run over a locale port that keeps several languages in memory (SeveralLocales),
 * so the catalog's rules are tested here without a multilingual plugin; the conformance suite
 * runs the same rules against Polylang.
 *
 * - Linking a post adds a binding in its locale, and never a second SKU, price or stock item;
 *   linking it again moves it to the locale given. A post bound to a product that holds nothing
 *   is adopted, that product deleted with a ProductDeleted naming no SKU; a post bound to a
 *   product with commerce data, or a locale the product has a post in, is refused. A group whose
 *   products all hold nothing still has one owner, the product of its oldest-bound post.
 * - Unlinking removes the mapping only; the source post is never unlinked. Promoting moves the
 *   source with one conditional statement and records ProductBindingPromoted.
 * - Deleting a translation's post removes its binding only. Deleting the source post of a product
 *   other posts present promotes the oldest published one, or the oldest one when none is
 *   published, and records the promotion. Trashing a translation's post releases nothing, nor does
 *   trashing the source post while a translation's post is published.
 * - A product with no post in a locale is `not_translated` there; a translation's post is judged
 *   on its own status.
 * - A first save that names the post it translates, or whose group presents a product, joins that
 *   product; a save through a translation writes the product's one price. A locale the site does
 *   not publish in, another locale for a bound post, and another product's post are refused.
 * - Joining a product needs `edit_post` on its source post: the endpoint refuses before anything
 *   is written, and the save asks again under the product's lock, where the post it translates
 *   must still present the product. A new translation's first save names its locale, since the
 *   new post starts in the language of the post it translates.
 * - When the multilingual setup puts a post in a group or takes it out, the bindings follow: a
 *   post with commerce data of its own is left as it is and reported once, and so is a post whose
 *   product holds commerce data when its new group presents another product. The reconciliation
 *   of one post can be run at any time, outside a request that wrote it.
 * - Changing commerce fields through a post that is not its product's source post needs
 *   `edit_post` on the source post, at the endpoint and under the save's lock; so does moving the
 *   source away from its post, by a promotion or by a delete's hand-over, for any actor with a
 *   user. The cron run that empties the trash hands the source over on no one's authority.
 * - A group is owned by its product with commerce data, before any product that only holds more
 *   posts.
 *
 * Planted violations, each confirmed to fail a test here:
 * - In SaveProduct::save(), never look for the product a post translates: a translation's first
 *   save creates a second product, and the two join tests fail.
 * - In PostLifecycle::deleting(), delete the product for a translation's post too: the test of
 *   deleting a translation fails, the product deleted.
 * - In MysqlProductRepository::promoteSource(), order the candidates by `linked_at` alone: the
 *   published post is passed over for an older draft, and the test of the oldest published post
 *   fails.
 * - In TranslationBindings::giveWay(), delete the product without asking whether it holds
 *   nothing: the test of a post whose product has commerce data fails, the link not refused.
 * - In MysqlProductRepository::facts(), join the source binding whatever the locale: the
 *   untranslated product is sold in a locale it has no post in.
 * - In TranslationGroups::reportConflicts(), report nothing: the conflict test fails.
 * - In TranslationGroups::productOfGroup(), leave out the products that hold nothing: the test of
 *   a source whose product holds nothing fails, the translation given a product of its own.
 * - In ProductPostsController::mayJoin(), answer true: the endpoint test fails, the save's own
 *   refusal answering in its place.
 * - In SaveProduct::mayJoin(), drop the authorization: the save test fails, the join made.
 * - In SaveProduct::mayJoin(), drop the check that the post still presents the product: the
 *   test of an original that left the product fails.
 * - In ProductPostsController::mayChangeCommerce(), answer true: the endpoint test of a price
 *   changed through an adopted post fails, the save's own refusal answering in its place.
 * - In SaveProduct::updateInTurn(), drop the check of a commerce change through a post that is
 *   not the source: the save test of a price changed through an adopted post fails.
 * - In TranslationGroups::reconcile(), unlink a post that left its group whatever its product
 *   holds: the regrouped post is moved onto the other product.
 * - In PostLifecycle::statusChanged(), release the holds whatever the other posts' statuses:
 *   the test of a source trashed while a translation sells the product fails.
 * - In TranslationGroups::productOfGroup(), count a product with commerce data with the ones that
 *   hold more than one post: the older product without commerce data owns the group, and the
 *   owner test fails.
 * - In TranslationBindings::mayMoveSourceFrom(), ask nothing: the delete of a source post by a
 *   user who may not edit it hands the source over, and the direct promotion is made; both tests
 *   fail.
 *
 * @since 0.1.0
 */
final class TranslationBindingsTest extends ProductRestTestCase {

	/**
	 * The languages the services see.
	 *
	 * @since 0.1.0
	 *
	 * @var SeveralLocales
	 */
	private SeveralLocales $locales;

	/**
	 * Hooks the lifecycle of the services, built over several languages, and lets it hear the setup's changes.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->services->attach();
		$this->locales->watch( $this->services->lifecycle );
	}

	/**
	 * Returns the languages the services are built over.
	 *
	 * @since 0.1.0
	 *
	 * @return PostLocales The languages.
	 */
	protected function locales(): PostLocales {
		$this->locales = new SeveralLocales();

		return $this->locales;
	}

	/**
	 * Tests that a linked post presents the same product, whose SKU, price and stock stay one each.
	 *
	 * @since 0.1.0
	 */
	public function test_a_linked_post_presents_the_product_and_its_one_sku_price_and_stock(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );

		$product = $this->products->findByPost( $german );

		$this->assertInstanceOf( Product::class, $product );
		$this->assertSame( $saved->productId, $product->id() );
		$this->assertSame( $saved->postId, $product->sourcePostId(), 'Linking changed the source post.' );
		$this->assertSame( array( 'en_US', 'de_DE' ), array_map( static fn( $binding ): string => $binding->locale()->toString(), $product->bindings() ) );
		$this->assertSame(
			array( 1, 1, 1 ),
			array(
				$this->committedCount( $b, CatalogTables::VARIANTS, sprintf( 'product_id = %d', $saved->productId ) ),
				$this->committedCount( $b, CatalogTables::VARIANT_PRICES, sprintf( 'variant_id = %d', (int) $saved->variantId ) ),
				$this->committedCount( $b, InventoryTables::ITEMS, sprintf( 'variant_id = %d', (int) $saved->variantId ) ),
			),
			'A translation made a second SKU, price or stock item.'
		);
	}

	/**
	 * Tests that a product is judged in a locale on its post there, and is `not_translated` where it has none.
	 *
	 * @since 0.1.0
	 */
	public function test_a_product_is_sold_in_a_locale_through_its_post_there_only(): void {
		$saved  = $this->stocked();
		$german = $this->unboundPost( 'draft' );

		$this->link( $saved, $german, 'de_DE' );

		$this->assertSame(
			array(
				'source' => SellabilityReason::Sellable,
				'en_US'  => SellabilityReason::Sellable,
				'de_DE'  => SellabilityReason::NotPublished,
				'en_GB'  => SellabilityReason::NotTranslated,
			),
			array(
				'source' => $this->verdictIn( $saved, null ),
				'en_US'  => $this->verdictIn( $saved, 'en_US' ),
				'de_DE'  => $this->verdictIn( $saved, 'de_DE' ),
				'en_GB'  => $this->verdictIn( $saved, 'en_GB' ),
			)
		);

		wp_publish_post( $german );

		$this->assertSame( SellabilityReason::Sellable, $this->verdictIn( $saved, 'de_DE' ) );
	}

	/**
	 * Tests that linking a post again moves it to the locale given, and a locale the product has a post in is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_linking_again_moves_the_post_and_a_taken_locale_is_refused(): void {
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );
		$this->link( $saved, $german, 'en_GB' );

		$this->assertSame( 'en_GB', $this->products->findByPost( $german )?->bindingOf( $german )?->locale()->toString() );
		$this->assertSame( CatalogError::LocaleTaken, $this->refusal( fn() => $this->link( $saved, $german, 'en_US' ) ) );
		$this->assertSame( CatalogError::LocaleTaken, $this->refusal( fn() => $this->link( $saved, $this->unboundPost(), 'en_GB' ) ) );
		$this->assertSame( 'en_GB', $this->products->findByPost( $german )?->bindingOf( $german )?->locale()->toString() );
	}

	/**
	 * Tests that a post bound to a product that holds nothing but it is adopted: that product is deleted in the link's transaction, with a ProductDeleted naming no SKU.
	 *
	 * @since 0.1.0
	 */
	public function test_a_post_whose_product_holds_nothing_is_adopted(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$stray  = $this->post();
		$before = $this->products->findByPost( $stray );

		$this->assertNotNull( $before, 'The lifecycle did not reconcile the post.' );
		$this->assertTrue( $before->holdsNothing() );

		$this->link( $saved, $stray, 'de_DE' );

		$this->assertSame( $saved->productId, $this->products->findByPost( $stray )?->id() );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'id = %d', (int) $before->id() ) ), 'The empty product outlived its post.' );
		$this->assertSame( array( array( (int) $before->id(), array() ) ), $this->deletions( $b ), 'The adopted post\'s product was deleted without its event.' );
	}

	/**
	 * Tests that a translation linked to a source post whose product holds nothing joins that product: a group has one owner even while its products hold nothing, and the others are adopted, each with its ProductDeleted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_translation_of_a_source_whose_product_holds_nothing_joins_that_product(): void {
		$b       = $this->secondConnection();
		$source  = $this->post();
		$german  = $this->unboundPost();
		$british = $this->post();
		$owner   = $this->products->findByPost( $source );

		$this->assertInstanceOf( Product::class, $owner, 'The source post was not given a product.' );
		$this->assertTrue( $owner->holdsNothing(), 'The source post was not given an empty product.' );

		$this->locales->change( $german, 'de_DE', $source );
		$this->locales->change( $british, 'en_GB', $source );

		$this->assertSame(
			array( $owner->id(), $owner->id() ),
			array( $this->products->findByPost( $german )?->id(), $this->products->findByPost( $british )?->id() ),
			'A post of the group presents a product of its own.'
		);
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
		$this->assertCount( 1, $this->deletions( $b ), 'The British post\'s empty product was not deleted with its event.' );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that the endpoint refuses, before anything is written, to join a product the user may not edit: a catalog editor who may not edit other people's products.
	 *
	 * @since 0.1.0
	 */
	public function test_the_endpoint_refuses_to_join_a_product_the_user_may_not_edit(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$before = $this->catalogChecksums( $b );
		$posts  = $this->committedPosts( $b );

		wp_set_current_user( $this->editorOfTheirOwn() );

		$refused = $this->request(
			'POST',
			'',
			array(
				'title'                         => 'Hemd',
				'status'                        => 'draft',
				ProductCommerceSchema::PROPERTY => array(
					ProductCommerceSchema::TRANSLATION_OF => $saved->postId,
					ProductCommerceSchema::LOCALE         => 'de_DE',
					'price_minor'                         => 1,
				),
			)
		);

		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'rest_cannot_edit', $refused->get_data()['code'] ?? null, 'The endpoint left the refusal to the save.' );
		$this->assertSame( $before, $this->catalogChecksums( $b ), 'A catalog row changed.' );
		$this->assertSame( $posts, $this->committedPosts( $b ), 'A post was written.' );
	}

	/**
	 * Tests that the save itself refuses, under the product's lock, a join the actor may not make, and writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_the_save_refuses_under_the_lock_a_join_the_actor_may_not_make(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$posts  = $this->committedPosts( $b );
		$editor = $this->editorOfTheirOwn();

		try {
			$this->service->save( new ProductSave( null, array( 'post_title' => 'Hemd' ), CommerceInput::fromArray( array( 'price_minor' => 1 ) ), Actor::user( $editor ), $saved->postId, Locale::of( 'de_DE' ) ) );
			$this->fail( 'The save joined a product its actor may not edit.' );
		} catch ( CodedException $denied ) {
			$this->assertSame( AuthorizationError::Denied, $denied->errorCode() );
		}

		$this->assertSame( array( 'Saved product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCT_POSTS ) );
		$this->assertSame( $posts, $this->committedPosts( $b ) );
		$this->assertSame( 'complete', $this->committedMarker( $b, $saved->productId )[0] );
	}

	/**
	 * Tests that a join whose original post left the product between the save's reads and its window is a write conflict, and writes nothing.
	 *
	 * Just before the window's first statement, a second connection moves the German post, the one
	 * the save names, to another product.
	 *
	 * @since 0.1.0
	 */
	public function test_a_join_whose_original_left_the_product_meanwhile_is_a_write_conflict(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$german = $this->unboundPost();
		$other  = $this->savedProduct( 'SKU-2', 'Other product' );

		$this->link( $saved, $german, 'de_DE' );

		$posts = $this->committedPosts( $b );
		$moved = $this->beforeStatement(
			'/^' . preg_quote( ProductWrites::markStatement( $this->db, $saved->productId ), '/' ) . '$/',
			function () use ( $b, $german, $other ): void {
				$b->query( sprintf( "UPDATE `%s` SET product_id = %d, locale = 'en_GB' WHERE post_id = %d", $this->catalogTable( CatalogTables::PRODUCT_POSTS ), $other->productId, $german ) );
			},
			2
		);

		try {
			$this->saveTranslation( null, array( 'post_title' => 'Shirt (UK)' ), null, $german, 'en_GB' );
			$this->fail( 'The save joined a product the post it translates had left.' );
		} catch ( CodedException $conflict ) {
			$this->assertSame( CatalogError::WriteConflict, $conflict->errorCode() );
		}

		$this->assertTrue( $moved->fired, 'The post never moved.' );
		$this->assertSame( $posts, $this->committedPosts( $b ), 'A post was written.' );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCT_POSTS, sprintf( 'product_id = %d', $saved->productId ) ) );
	}

	/**
	 * Tests that a new translation's first save joins in the locale it names, though the new post starts in the language of the post it translates, where the product has a post already.
	 *
	 * @since 0.1.0
	 */
	public function test_a_new_translations_first_save_joins_in_the_locale_it_names(): void {
		$saved = $this->stocked();
		$draft = get_default_post_to_edit( ProductCapabilities::POST_TYPE, true );

		$this->trackPost( $draft->ID );
		$this->assertSame( 'en_US', $this->locales->localeOf( $draft->ID )->toString() );

		$without = $this->request( 'PUT', '/' . $draft->ID, $this->firstSave( $saved, null ) );

		$this->assertSame( CatalogError::LocaleTaken->value, $without->get_data()['code'] ?? null, 'Without its locale, the post joined in the language it started in.' );

		$with = $this->request( 'PUT', '/' . $draft->ID, $this->firstSave( $saved, 'de_DE' ) );

		$this->assertSame( 200, $with->get_status(), (string) wp_json_encode( $with->get_data() ) );
		$this->assertSame( 'de_DE', $with->get_data()[ ProductCommerceSchema::PROPERTY ][ ProductCommerceSchema::LOCALE ] ?? null );
		$this->assertSame( $saved->productId, $this->products->findByPost( $draft->ID )?->id() );
		$this->assertSame( array( array( $draft->ID, 'de_DE', $saved->postId ) ), $this->locales->assigned );
	}

	/**
	 * Tests that a post bound to a product with commerce data of its own is refused, and both products stay as they were.
	 *
	 * @since 0.1.0
	 */
	public function test_a_post_whose_product_has_commerce_data_is_refused(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$other  = $this->savedProduct( 'SKU-2', 'Other product' );
		$before = $this->catalogChecksums( $b );

		$this->assertSame( CatalogError::PostBoundElsewhere, $this->refusal( fn() => $this->link( $saved, $other->postId, 'de_DE' ) ) );
		$this->assertSame( $before, $this->catalogChecksums( $b ) );
	}

	/**
	 * Tests that unlinking a post removes its binding only, and the source post is never unlinked.
	 *
	 * @since 0.1.0
	 */
	public function test_unlinking_removes_the_mapping_only_and_keeps_the_source(): void {
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );
		$this->services->bindings->unlink( $german );

		$this->assertNull( $this->products->findByPost( $german ) );
		$this->assertSame( SellabilityReason::Sellable, $this->verdictIn( $saved, null ), 'Unlinking a translation took the product off sale.' );
		$this->assertSame( CatalogError::SourceBindingKept, $this->refusal( fn() => $this->services->bindings->unlink( $saved->postId ) ) );
		$this->assertSame( $saved->productId, $this->products->findByPost( $saved->postId )?->id() );
	}

	/**
	 * Tests that promoting a post moves the source to it and records ProductBindingPromoted, once, and a post that does not present the product cannot be promoted.
	 *
	 * @since 0.1.0
	 */
	public function test_promoting_moves_the_source_and_records_it(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );
		$this->services->bindings->promote( $saved->productId, $german, Actor::user( 1 ) );
		$this->services->bindings->promote( $saved->productId, $german, Actor::user( 1 ) );

		$this->assertSame( $german, $this->sourceOf( $german ) );
		$this->assertSame(
			array(
				array(
					'product_id'   => $saved->productId,
					'from_post_id' => $saved->postId,
					'from_locale'  => 'en_US',
					'to_post_id'   => $german,
					'to_locale'    => 'de_DE',
					'actor_type'   => 'user',
					'actor_id'     => 1,
				),
			),
			$this->promotions( $b )
		);
		$this->assertSame( CatalogError::PromotionConflict, $this->refusal( fn() => $this->services->bindings->promote( $saved->productId, $this->unboundPost(), Actor::user( 1 ) ) ) );
	}

	/**
	 * Tests that deleting a translation's post removes its binding only: the product, its source post, its stock and its holds stay.
	 *
	 * @since 0.1.0
	 */
	public function test_deleting_a_translation_removes_its_binding_only(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );
		$this->services->stock->hold( array( new HoldLine( (int) $saved->variantId, 1 ) ), 600 );

		$this->assertInstanceOf( \WP_Post::class, wp_delete_post( $german, true ) );

		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'id = %d', $saved->productId ) ), 'The product went with its translation.' );
		$this->assertSame( array( $saved->postId ), array_map( static fn( $binding ): int => $binding->postId(), $this->products->findByPost( $saved->postId )?->bindings() ?? array() ) );
		$this->assertSame( 1, $this->committedCount( $b, InventoryTables::HOLDS, sprintf( 'variant_id = %d', (int) $saved->variantId ) ) );
		$this->assertSame( 0, $this->committedCount( $b, OutboxTable::NAME, sprintf( "event_name = '%s'", ProductDeleted::eventName() ) ) );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that deleting the source post of a product other posts present promotes the oldest published one, over an older draft, and records the promotion.
	 *
	 * @since 0.1.0
	 */
	public function test_deleting_the_source_promotes_the_oldest_published_post(): void {
		$b       = $this->secondConnection();
		$saved   = $this->stocked();
		$british = $this->unboundPost( 'draft' );
		$german  = $this->unboundPost();

		$this->link( $saved, $british, 'en_GB' );
		$this->link( $saved, $german, 'de_DE' );

		$this->assertInstanceOf( \WP_Post::class, wp_delete_post( $saved->postId, true ) );

		$product = $this->products->findByPost( $german );

		$this->assertInstanceOf( Product::class, $product );
		$this->assertSame( $german, $product->sourcePostId() );
		$this->assertSame( array( $british, $german ), array_map( static fn( $binding ): int => $binding->postId(), $product->bindings() ) );
		$this->assertSame( array( $saved->postId, $german ), array( $this->promotions( $b )[0]['from_post_id'] ?? null, $this->promotions( $b )[0]['to_post_id'] ?? null ) );
		$this->assertSame( SellabilityReason::Sellable, $this->verdictIn( $saved, null ), 'The product is not sold through its new source post.' );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that deleting the source post when every other post is a draft promotes the oldest, and deleting the last post deletes the product.
	 *
	 * @since 0.1.0
	 */
	public function test_deleting_the_source_with_drafts_left_promotes_the_oldest_and_the_last_delete_deletes_the_product(): void {
		$b       = $this->secondConnection();
		$saved   = $this->stocked();
		$british = $this->unboundPost( 'draft' );
		$german  = $this->unboundPost( 'draft' );

		$this->link( $saved, $british, 'en_GB' );
		$this->link( $saved, $german, 'de_DE' );

		wp_delete_post( $saved->postId, true );

		$this->assertSame( $british, $this->sourceOf( $german ) );

		wp_delete_post( $british, true );

		$this->assertSame( $german, $this->sourceOf( $german ) );

		wp_delete_post( $german, true );

		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'id = %d', $saved->productId ) ) );
		$this->assertSame( 1, $this->committedCount( $b, OutboxTable::NAME, sprintf( "event_name = '%s'", ProductDeleted::eventName() ) ) );
		$this->assertCount( 2, $this->promotions( $b ) );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that trashing a translation's post releases nothing: the product is still sold through its source post.
	 *
	 * @since 0.1.0
	 */
	public function test_trashing_a_translation_releases_nothing(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );
		$this->services->stock->hold( array( new HoldLine( (int) $saved->variantId, 2 ) ), 600 );

		wp_trash_post( $german );

		$this->assertSame( 1, $this->committedCount( $b, InventoryTables::HOLDS, sprintf( 'variant_id = %d', (int) $saved->variantId ) ) );
		$this->assertSame( SellabilityReason::Sellable, $this->verdictIn( $saved, null ) );
		$this->assertSame( SellabilityReason::NotPublished, $this->verdictIn( $saved, 'de_DE' ) );
	}

	/**
	 * Tests that a first save naming the post it translates joins that post's product, in the locale given, and tells the multilingual setup.
	 *
	 * @since 0.1.0
	 */
	public function test_a_first_save_naming_the_post_it_translates_joins_its_product(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$result = $this->saveTranslation(
			null,
			array(
				'post_title'  => 'Übersetzt',
				'post_status' => 'publish',
			),
			null,
			$saved->postId,
			'de_DE'
		);

		$this->assertSame( $saved->productId, $result->productId );
		$this->assertTrue( $result->postCreated );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS ), 'The translation made a second product.' );
		$this->assertSame( 'de_DE', $this->products->findByPost( $result->postId )?->bindingOf( $result->postId )?->locale()->toString() );
		$this->assertSame( array( array( $result->postId, 'de_DE', $saved->postId ) ), $this->locales->assigned );
		$this->assertSame( SellabilityReason::Sellable, $result->sellability, 'The verdict is not the translation\'s own.' );
	}

	/**
	 * Tests that a first save of a post whose translation group presents a product joins it, in the post's own locale.
	 *
	 * @since 0.1.0
	 */
	public function test_a_first_save_of_a_post_in_a_group_joins_the_groups_product(): void {
		$saved   = $this->stocked();
		$british = $this->unboundPost();

		$this->locales->assign( $british, Locale::of( 'en_GB' ), $saved->postId );

		$result = $this->saveTranslation( $british, array( 'post_title' => 'Translated' ), null, null, null );

		$this->assertSame( $saved->productId, $result->productId );
		$this->assertSame( 'en_GB', $this->products->findByPost( $british )?->bindingOf( $british )?->locale()->toString() );
	}

	/**
	 * Tests that a save through a translation's post writes the product's one price, which every post then shows.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_through_a_translation_writes_the_one_price(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );
		$this->saveTranslation( $german, array(), array( 'price_minor' => 2500 ), null, null );

		$this->assertSame( '2500', $b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( price_minor ) FROM `%s` WHERE variant_id = %d', $this->catalogTable( CatalogTables::VARIANT_PRICES ), (int) $saved->variantId ) ) );
		$this->assertSame( 2500, $this->products->findByPost( $saved->postId )?->defaultVariant()?->basePrice()?->priceMinor() );
	}

	/**
	 * Tests that a save is refused before anything is written for a locale the site does not publish in, another locale for a bound post, or another product's post.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_with_a_locale_or_original_it_cannot_have_is_refused(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$other  = $this->savedProduct( 'SKU-2', 'Other product' );
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );

		$before = $this->catalogChecksums( $b );

		$this->assertSame( CatalogError::LocaleUnsupported, $this->refusal( fn() => $this->saveTranslation( null, array( 'post_title' => 'Traduit' ), null, $saved->postId, 'fr_FR' ) ) );
		$this->assertSame( CatalogError::LocaleFixed, $this->refusal( fn() => $this->saveTranslation( $german, array(), null, null, 'en_GB' ) ) );
		$this->assertSame( CatalogError::PostBoundElsewhere, $this->refusal( fn() => $this->saveTranslation( $german, array(), null, $other->postId, null ) ) );
		$this->assertSame( $before, $this->catalogChecksums( $b ) );

		$this->saveTranslation( $german, array( 'post_title' => 'Wieder' ), null, $saved->postId, 'de_DE' );

		$this->assertSame( 'Wieder', get_post_field( 'post_title', $german ), 'A save that repeats the post\'s locale and original was refused.' );
	}

	/**
	 * Tests that the bindings follow the multilingual setup: a post put in a group joins its product, and a post taken out is unlinked and gets a product of its own.
	 *
	 * @since 0.1.0
	 */
	public function test_the_bindings_follow_the_groups_the_multilingual_setup_changes(): void {
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->locales->change( $german, 'de_DE', $saved->postId );

		$this->assertSame( $saved->productId, $this->products->findByPost( $german )?->id() );

		$this->locales->change( $german, 'de_DE', null );

		$own = $this->products->findByPost( $german );

		$this->assertInstanceOf( Product::class, $own, 'The post left the group and presents nothing.' );
		$this->assertNotSame( $saved->productId, $own->id(), 'The post left the group but still presents its product.' );
		$this->assertTrue( $own->holdsNothing(), 'The post was not given an unsellable product of its own.' );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that a post with commerce data of its own that the setup puts in another product's group is left as it is, and reported once.
	 *
	 * @since 0.1.0
	 */
	public function test_a_grouped_post_with_its_own_commerce_data_is_left_and_reported(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$other  = $this->savedProduct( 'SKU-2', 'Other product' );
		$before = $this->catalogChecksums( $b );

		$this->locales->change( $other->postId, 'de_DE', $saved->postId );

		$this->assertSame( $before, $this->catalogChecksums( $b ) );
		$this->assertSame(
			array(
				array(
					'code'    => ReportCode::TranslationConflict->value,
					'context' => array(
						'post_id'   => $other->postId,
						'conflicts' => array( $other->postId => CatalogError::PostBoundElsewhere->value ),
					),
				),
			),
			$this->reports
		);
	}

	/**
	 * Tests that a catalog editor whose own post the multilingual plugin's screen put in another product's group cannot change that product's price through it at the endpoint.
	 *
	 * @since 0.1.0
	 */
	public function test_the_endpoint_refuses_a_price_change_through_a_post_adopted_into_a_product_the_user_may_not_edit(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked();
		$own   = $this->adoptedPostOf( $saved );

		$refused = $this->request( 'PUT', '/' . $own, array( ProductCommerceSchema::PROPERTY => array( 'price_minor' => 2 ) ) );

		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'rest_cannot_edit', $refused->get_data()['code'] ?? null, 'The endpoint left the refusal to the save.' );
		$this->assertSame( array( 'Saved product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
	}

	/**
	 * Tests that the save itself refuses, under the product's lock, a price change through a post that is not the product's source post, by an actor who may not edit the product.
	 *
	 * @since 0.1.0
	 */
	public function test_the_save_refuses_a_price_change_through_a_translation_the_actor_may_not_make(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked();
		$own   = $this->adoptedPostOf( $saved );

		try {
			$this->service->save( new ProductSave( $own, array(), CommerceInput::fromArray( array( 'price_minor' => 2 ) ), Actor::user( get_current_user_id() ) ) );
			$this->fail( 'The save changed the price of a product its actor may not edit.' );
		} catch ( CodedException $denied ) {
			$this->assertSame( AuthorizationError::Denied, $denied->errorCode() );
		}

		$this->assertSame( array( 'Saved product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
		$this->assertSame( 'complete', $this->committedMarker( $b, $saved->productId )[0] );
	}

	/**
	 * Tests that a post regrouped away from a product holding commerce data is never moved onto the product of its new group, however often it is written: its binding stays and the conflict is reported.
	 *
	 * @since 0.1.0
	 */
	public function test_a_post_regrouped_away_from_a_product_with_commerce_data_is_never_moved_onto_another(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );

		$other = $this->savedProduct( 'SKU-2', 'Other product' );

		$this->locales->change( $german, 'de_DE', $other->postId );

		$this->assertIsInt(
			wp_update_post(
				array(
					'ID'         => $german,
					'post_title' => 'Written again',
				),
				true
			)
		);

		$this->assertSame( $saved->productId, $this->products->findByPost( $german )?->id(), 'The post was moved onto the other product.' );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCT_POSTS, sprintf( 'product_id = %d', $other->productId ) ) );
		$this->assertSame( array( ReportCode::TranslationConflict->value ), array_values( array_unique( array_column( $this->reports, 'code' ) ) ) );
		$this->assertSame(
			array(
				'post_id'   => $german,
				'conflicts' => array( $german => CatalogError::PostBoundElsewhere->value ),
			),
			$this->reports[0]['context'] ?? null
		);
	}

	/**
	 * Tests that a product first saved through the endpoint with no commerce field, which recorded ProductSaved, is deleted with its ProductDeleted when its post is adopted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_product_first_saved_without_commerce_is_deleted_with_its_event_when_adopted(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked();
		$made  = $this->request(
			'POST',
			'',
			array(
				'title'  => 'Hemd',
				'status' => 'publish',
			)
		);
		$stray = (int) ( $made->get_data()['id'] ?? 0 );

		$this->assertSame( 201, $made->get_status(), (string) wp_json_encode( $made->get_data() ) );
		$this->trackPost( $stray );

		$strayProduct = (int) $this->products->findByPost( $stray )?->id();

		$this->assertSame( 1, $this->committedCount( $b, OutboxTable::NAME, sprintf( "event_name = '%s' AND aggregate_id = %d", ProductSaved::eventName(), $strayProduct ) ), 'The first save recorded no ProductSaved.' );

		$this->locales->change( $stray, 'de_DE', $saved->postId );

		$this->assertSame( $saved->productId, $this->products->findByPost( $stray )?->id() );
		$this->assertSame( array( array( $strayProduct, array() ) ), $this->deletions( $b ), 'A product listeners heard of was deleted without its event.' );
	}

	/**
	 * Tests that trashing the source post keeps the holds while a translation's post is published: they belong to carts in that language.
	 *
	 * @since 0.1.0
	 */
	public function test_trashing_the_source_keeps_the_holds_while_a_translation_sells_the_product(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		$this->link( $saved, $german, 'de_DE' );
		$this->services->stock->hold( array( new HoldLine( (int) $saved->variantId, 2 ) ), 600 );

		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $saved->postId ) );

		$this->assertSame( 1, $this->committedCount( $b, InventoryTables::HOLDS, sprintf( 'variant_id = %d', (int) $saved->variantId ) ), 'The trash released the holds of carts in German.' );
		$this->assertSame( SellabilityReason::Sellable, $this->verdictIn( $saved, 'de_DE' ) );
		$this->assertSame( SellabilityReason::NotPublished, $this->verdictIn( $saved, null ) );
	}

	/**
	 * Tests that the reconciliation of one post brings its binding in step with its group when run on its own, outside a request that wrote it, as a repair would run it.
	 *
	 * @since 0.1.0
	 */
	public function test_one_post_is_reconciled_outside_a_request_that_wrote_it(): void {
		$saved  = $this->stocked();
		$german = $this->unboundPost();

		// The setup puts the post in the group, and nothing in this request hears it.
		$this->locales->assign( $german, Locale::of( 'de_DE' ), $saved->postId );

		$this->assertNull( $this->productOf( $german ) );

		$this->db->transaction( fn(): bool => $this->services->groups->reconcile( $german ) );

		$product = $this->productOf( $german );

		$this->assertInstanceOf( Product::class, $product, 'The post is still unbound.' );
		$this->assertSame( $saved->productId, $product->id() );
		$this->assertSame( 'de_DE', $product->bindingOf( $german )?->locale()->toString() );
	}

	/**
	 * Tests that a group is owned by its product with commerce data, before an older product that holds two posts and no commerce data.
	 *
	 * @since 0.1.0
	 */
	public function test_a_group_is_owned_by_its_product_with_commerce_data_before_an_older_one_with_more_posts(): void {
		$older   = $this->post();
		$british = $this->unboundPost();

		$this->locales->change( $british, 'en_GB', $older );

		$empty = $this->productOf( $older );
		$saved = $this->stocked();

		$this->assertInstanceOf( Product::class, $empty );
		$this->assertFalse( $empty->holdsNothing(), 'The older product does not hold two posts.' );
		$this->assertFalse( $empty->hasCommerceData() );
		$this->assertLessThan( $saved->productId, (int) $empty->id(), 'The product without commerce data is not the older one.' );
		$this->assertSame(
			$saved->productId,
			TranslationGroups::productOfGroup(
				$this->products,
				array(
					$older         => Locale::of( 'en_US' ),
					$british       => Locale::of( 'en_GB' ),
					$saved->postId => Locale::of( 'de_DE' ),
				)
			),
			'The group is owned by a product without commerce data.'
		);
	}

	/**
	 * Tests that a user who may delete the admin's source post but not edit it cannot delete it while another post presents the product: the hand-over is refused, and the source and bindings stay.
	 *
	 * @since 0.1.0
	 */
	public function test_a_user_who_may_not_edit_the_source_post_cannot_hand_it_over_by_deleting_it(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked();
		$own   = $this->adoptedPostOf( $saved );

		$this->assertFalse( wp_delete_post( $saved->postId, true ), 'WordPress deleted the source post.' );

		$this->assertInstanceOf( \WP_Post::class, get_post( $saved->postId ) );
		$this->assertSame( $saved->postId, $this->sourceOf( $own ), 'The source moved to the post of a user who may not edit it.' );
		$this->assertSame( 2, $this->committedCount( $b, CatalogTables::PRODUCT_POSTS, sprintf( 'product_id = %d', $saved->productId ) ) );
		$this->assertSame( array(), $this->promotions( $b ) );
		$this->assertSame( array( ReportCode::DeleteRefused->value, 'authorization.denied' ), array( $this->reports[0]['code'] ?? null, $this->reports[0]['context']['error'] ?? null ) );
	}

	/**
	 * Tests that a promotion by an actor who may not edit the source post is denied, and the source stays.
	 *
	 * @since 0.1.0
	 */
	public function test_a_promotion_by_an_actor_who_may_not_edit_the_source_post_is_denied(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked();
		$own   = $this->adoptedPostOf( $saved );

		try {
			$this->services->bindings->promote( $saved->productId, $own, Actor::user( get_current_user_id() ) );
			$this->fail( 'The source moved to the post of an actor who may not edit it.' );
		} catch ( CodedException $denied ) {
			$this->assertSame( AuthorizationError::Denied, $denied->errorCode() );
		}

		$this->assertSame( $saved->postId, $this->sourceOf( $own ) );
		$this->assertSame( array(), $this->promotions( $b ) );
	}

	/**
	 * Tests that the cron run that empties the trash still hands the source over: it acts on no user's authority.
	 *
	 * @since 0.1.0
	 */
	public function test_the_cron_run_that_empties_the_trash_still_hands_the_source_over(): void {
		$saved = $this->stocked();
		$own   = $this->adoptedPostOf( $saved );

		wp_set_current_user( 0 );
		wp_trash_post( $saved->postId );
		update_post_meta( $saved->postId, '_wp_trash_meta_time', time() - ( DAY_IN_SECONDS * EMPTY_TRASH_DAYS ) - 60 );

		wp_scheduled_delete();

		$this->assertNull( get_post( $saved->postId ), 'The cron did not delete the post.' );
		$this->assertSame( $own, $this->sourceOf( $own ) );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Makes the current user a catalog editor who may not edit other people's products, whose own post the multilingual setup puts in a product's group, as its screen does, and which the lifecycle adopts.
	 *
	 * @since 0.1.0
	 *
	 * @param SaveResult $saved The product, another user's.
	 * @return int The editor's post, presenting the product.
	 */
	private function adoptedPostOf( SaveResult $saved ): int {
		wp_set_current_user( $this->editorOfTheirOwn() );

		$own = $this->post();

		$this->locales->change( $own, 'de_DE', $saved->postId );

		$this->assertSame( $saved->productId, $this->products->findByPost( $own )?->id(), 'The lifecycle did not adopt the post.' );

		return $own;
	}

	/**
	 * Returns the product a post presents, read afresh.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return Product|null The product, or null.
	 *
	 * @phpstan-impure
	 */
	private function productOf( int $postId ): ?Product {
		return $this->products->findByPost( $postId );
	}

	/**
	 * Returns the body of a new translation's first save, as the product editor sends it.
	 *
	 * @since 0.1.0
	 *
	 * @param SaveResult  $saved  The product it translates.
	 * @param string|null $locale The locale the editor names, or null for none.
	 * @return array<string, mixed> The body.
	 */
	private function firstSave( SaveResult $saved, ?string $locale ): array {
		$seocart = array( ProductCommerceSchema::TRANSLATION_OF => $saved->postId );

		if ( null !== $locale ) {
			$seocart[ ProductCommerceSchema::LOCALE ] = $locale;
		}

		return array(
			'title'                         => 'Hemd',
			'status'                        => 'publish',
			ProductCommerceSchema::PROPERTY => $seocart,
		);
	}

	/**
	 * Creates a catalog editor who may edit their own products but not other people's.
	 *
	 * @since 0.1.0
	 *
	 * @return int The user.
	 */
	private function editorOfTheirOwn(): int {
		$editor = $this->createUser( 'seocart_catalog_editor' );

		( new \WP_User( $editor ) )->add_cap( ProductCapabilities::map()['edit_others_posts'], false );

		return $editor;
	}

	/**
	 * Counts the product posts a second connection sees.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b The second connection.
	 * @return int The count.
	 *
	 * @phpstan-impure
	 */
	private function committedPosts( SecondConnection $b ): int {
		global $wpdb;

		return (int) $b->fetchValue( sprintf( "SELECT COUNT(*) FROM `%s` WHERE post_type = '%s'", $wpdb->posts, ProductCapabilities::POST_TYPE ) );
	}

	/**
	 * Returns the committed ProductDeleted events: the product and the SKUs each names, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b The second connection.
	 * @return list<array{0: int, 1: mixed}> The deletions.
	 *
	 * @phpstan-impure
	 */
	private function deletions( SecondConnection $b ): array {
		$deletions = array();
		$id        = 0;

		while ( true ) {
			$row = $b->fetchRow( sprintf( "SELECT id, aggregate_id, payload_json FROM `%s` WHERE event_name = '%s' AND id > %d ORDER BY id LIMIT 1", $this->db->table( OutboxTable::NAME ), ProductDeleted::eventName(), $id ) );

			if ( null === $row ) {
				return $deletions;
			}

			$id          = (int) $row['id'];
			$deletions[] = array( (int) $row['aggregate_id'], json_decode( (string) $row['payload_json'], true )['p']['skus'] ?? null );
		}
	}

	/**
	 * Saves a whole published product with units on hand.
	 *
	 * @since 0.1.0
	 *
	 * @return SaveResult The product.
	 */
	private function stocked(): SaveResult {
		$saved = $this->savedProduct();

		$this->services->stock->adjust( (int) $saved->variantId, 5, LedgerReason::Received, Actor::user( 1 ) );

		return $saved;
	}

	/**
	 * Puts a post in the product's source post's group, in a locale, as the multilingual setup would, and links it to the product.
	 *
	 * @since 0.1.0
	 *
	 * @param SaveResult $saved  The product.
	 * @param int        $postId The post.
	 * @param string     $locale The locale.
	 */
	private function link( SaveResult $saved, int $postId, string $locale ): void {
		$this->locales->assign( $postId, Locale::of( $locale ), $saved->postId );
		$this->services->bindings->link( $saved->productId, $postId, Locale::of( $locale ) );
	}

	/**
	 * Saves through the product write with the translation fields, and deletes a post it created after the test.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null                  $postId        The post, or null for a new one.
	 * @param array<string, mixed>      $editorial     The post's fields.
	 * @param array<string, mixed>|null $commerce      The commerce fields, or null.
	 * @param int|null                  $translationOf The post it translates, or null.
	 * @param string|null               $locale        Its locale, or null.
	 * @return SaveResult The result.
	 */
	private function saveTranslation( ?int $postId, array $editorial, ?array $commerce, ?int $translationOf, ?string $locale ): SaveResult {
		$result = $this->service->save( new ProductSave( $postId, $editorial, null === $commerce ? null : CommerceInput::fromArray( $commerce ), Actor::user( 1 ), $translationOf, null === $locale ? null : Locale::of( $locale ) ) );

		if ( $result->postCreated ) {
			$this->trackPost( $result->postId );
		}

		return $result;
	}

	/**
	 * Returns the source post of the product a post presents, read afresh.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return int|null The source post, or null when the post presents no product.
	 *
	 * @phpstan-impure
	 */
	private function sourceOf( int $postId ): ?int {
		return $this->products->findByPost( $postId )?->sourcePostId();
	}

	/**
	 * Returns the verdict on a product's default variant, in a locale or through its source post.
	 *
	 * @since 0.1.0
	 *
	 * @param SaveResult  $saved  The product.
	 * @param string|null $locale The locale, or null for the source post.
	 * @return SellabilityReason The verdict.
	 */
	private function verdictIn( SaveResult $saved, ?string $locale ): SellabilityReason {
		return ( new Sellability( $this->products ) )->of( array( (int) $saved->variantId ), false, null === $locale ? null : Locale::of( $locale ) )[ (int) $saved->variantId ];
	}

	/**
	 * Returns the code a refused call raised, failing the test when it raised none.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $call The call.
	 * @return CatalogError The code.
	 */
	private function refusal( callable $call ): CatalogError {
		try {
			$call();
		} catch ( CodedException $refused ) {
			$code = $refused->errorCode();

			$this->assertInstanceOf( CatalogError::class, $code );
			$this->assertSame( 0, $this->db->depth(), 'The refusal left a transaction open.' );

			return $code;
		}

		$this->fail( 'The call was not refused.' );
	}

	/**
	 * Returns the payloads of the ProductBindingPromoted events committed, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b The second connection.
	 * @return list<array<string, mixed>> The payloads.
	 *
	 * @phpstan-impure
	 */
	private function promotions( SecondConnection $b ): array {
		$payloads = array();
		$id       = 0;

		while ( true ) {
			$row = $b->fetchRow( sprintf( "SELECT id, payload_json FROM `%s` WHERE event_name = '%s' AND id > %d ORDER BY id LIMIT 1", $this->db->table( OutboxTable::NAME ), ProductBindingPromoted::eventName(), $id ) );

			if ( null === $row ) {
				return $payloads;
			}

			$id         = (int) $row['id'];
			$payloads[] = (array) ( json_decode( (string) $row['payload_json'], true )['p'] ?? array() );
		}
	}
}

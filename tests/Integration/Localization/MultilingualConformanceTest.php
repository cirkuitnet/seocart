<?php
/**
 * The multilingual lifecycle conformance suite, run against a multilingual plugin through its fixture
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Localization;

use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Event\ProductBindingPromoted;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;
use SEOCart\Tests\Support\Localization\MultilingualFixture;
use SEOCart\Tests\Support\Localization\MultilingualFixtures;

/**
 * Every lifecycle event of a multilingual plugin leaves a product with one source post, recorded, and one SKU, one price and one stock, whichever of its posts an event comes through.
 *
 * A conformance suite, written against the port, the product endpoint and a fixture, never
 * against a particular multilingual plugin: a later WPML adapter must pass the same tests. The
 * rows call only the plugin and a MultilingualFixture, which MultilingualFixtures chooses by
 * SEOCART_ML_ADAPTER; the fixture writes posts in a language, puts them in a translation group
 * and takes them out, sets the default language and deletes, as the multilingual plugin's own
 * screens would, and gives the adapter under test, over which the services are built as the
 * kernel builds them. The integration bootstrap loads the multilingual plugin when
 * SEOCART_ML_ADAPTER names it and SEOCART_ML_PLUGIN gives its main file
 * (`composer test:multilingual-conformance`); for Polylang Free that is PolylangFixture, with
 * the site's three languages, en_US the default. Without one every test is skipped. The product
 * endpoint is the plugin's own.
 *
 * The rows of the multilingual conformance table, each a test here:
 *
 * - a: a translation created in the multilingual plugin presents the same product;
 * - b: linking adopts a post, and unlinking removes the mapping only;
 * - c: the multilingual plugin's copy is reconciled, and the plugin's own copy is a new product;
 * - d: a field the multilingual plugin copies never becomes a second authority for the price;
 * - e: the source post is recorded, never inferred, and moves only by a promotion;
 * - f: deleting a translation removes its mapping; deleting the source post promotes another;
 * - g: changing the default language changes nothing;
 * - i: the product endpoint gives a post its language and its group, which Polylang's free
 *   REST API does not;
 * - k: a product with no post in a locale is `not_translated` there.
 *
 * Rows out of scope at this stage, each a skipped test saying why: h (the catalog has no
 * taxonomy yet), j (there is no import yet), l (there is no search index yet), m (notification
 * templates come later) and n (there are no orders yet).
 *
 * Planted violations, each confirmed to fail its row against Polylang:
 * - a: in TranslationGroups::reconcile(), read the post's group as the post alone, ignoring the
 *   multilingual plugin's: the translation becomes a second product ("The translation is a
 *   product of its own"), and every other row that makes a translation fails with it.
 * - b: in TranslationGroups::reconcile(), let a post that left its source post's group keep its
 *   binding: "The unlinked post still presents the product."
 * - c: in DuplicateProduct::duplicate(), name the original as the post the copy translates: the
 *   copy is refused with `catalog.locale_taken` instead of becoming a product of its own.
 * - d: in ProductPostsController::save(), keep the price in a custom field of the post too:
 *   "A commerce value was stored where the multilingual plugin copies fields."
 * - e: in TranslationBindings::unlink(), drop the refusal of the source post: "The source post
 *   was unlinked without a promotion."
 * - f: in PostLifecycle::deleting(), delete the product for a translation's post too: the
 *   product is gone after the German post's delete.
 * - g: in TranslationGroups::reconcile(), promote the group's post in the multilingual plugin's
 *   default language: "The default language chose the source" (row e fails with it).
 * - i: in ProductPostsController::save(), leave out the two translation fields: the post made
 *   through the endpoint is neither in German nor in the group (row e errors with it).
 * - k: in MysqlProductRepository::facts(), join the source binding whatever the locale: the
 *   untranslated product is sold in German (rows b and f fail with it).
 *
 * @group multilingual-conformance
 *
 * @since 0.1.0
 */
final class MultilingualConformanceTest extends ProductRestTestCase {

	/**
	 * The multilingual plugin the run was started with, or null when none is loaded.
	 *
	 * @since 0.1.0
	 *
	 * @var MultilingualFixture|null
	 */
	private static ?MultilingualFixture $plugin = null;

	/**
	 * Chooses the multilingual plugin's fixture and gives the site its languages, when a multilingual plugin was loaded.
	 *
	 * @since 0.1.0
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		self::$plugin = MultilingualFixtures::fromEnvironment();

		self::$plugin?->boot();
	}

	/**
	 * Skips the test when no multilingual plugin was loaded.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		if ( null === self::$plugin ) {
			$this->markTestSkipped( 'The conformance suite runs against a multilingual plugin: set SEOCART_ML_ADAPTER (polylang) and SEOCART_ML_PLUGIN.' );
		}
	}

	/**
	 * Returns the adapter under test, as the kernel chooses it while the multilingual plugin is active.
	 *
	 * @since 0.1.0
	 *
	 * @return PostLocales|null The adapter, or null without a multilingual plugin.
	 */
	protected function locales(): ?PostLocales {
		return self::$plugin?->adapter();
	}

	/**
	 * Row a: tests that a translation created in the multilingual plugin presents the same product, with one SKU, one price and one stock, and its own title.
	 *
	 * @since 0.1.0
	 */
	public function test_row_a_a_translation_created_in_the_multilingual_plugin_presents_the_same_product(): void {
		$b      = $this->secondConnection();
		$source = $this->created( 'Shirt' );
		$german = $this->translated( $source, 'de_DE', 'Hemd' );

		$this->assertSame( $this->productOf( $source ), $this->productOf( $german ), 'The translation is a product of its own.' );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
		$this->assertSame(
			array( 1, 1, 1 ),
			array(
				$this->committedCount( $b, CatalogTables::VARIANTS ),
				$this->committedCount( $b, CatalogTables::VARIANT_PRICES ),
				$this->committedCount( $b, InventoryTables::ITEMS ),
			)
		);

		$read = $this->commerce( $german );

		$this->assertSame( array( 'SKU-1', 1999, 'de_DE', $source ), array( $read['sku'] ?? null, $read['price_minor'] ?? null, $read['locale'] ?? null, $read['translation_of'] ?? null ) );
		$this->assertSame( 'Hemd', get_post_field( 'post_title', $german ), 'The title is the post\'s own.' );
		$this->assertSame( 'Shirt', get_post_field( 'post_title', $source ) );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Row b: tests that linking adopts a post that holds nothing yet, and unlinking removes the mapping only.
	 *
	 * @since 0.1.0
	 */
	public function test_row_b_linking_adopts_a_post_and_unlinking_removes_the_mapping_only(): void {
		$b       = $this->secondConnection();
		$source  = $this->created( 'Shirt' );
		$british = $this->inLanguage( 'en_GB', 'Shirt (UK)' );
		$stray   = $this->productOf( $british );

		$this->assertNotNull( $stray, 'A post written in the multilingual plugin was left unbound.' );
		$this->assertNotSame( $this->productOf( $source ), $stray );

		$this->plugin()->link( $source, $british );

		$this->assertSame( $this->productOf( $source ), $this->productOf( $british ) );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'id = %d', (int) $stray ) ), 'The adopted post\'s empty product was kept.' );

		$this->plugin()->unlink( $british );

		$this->assertNotSame( $this->productOf( $source ), $this->productOf( $british ), 'The unlinked post still presents the product.' );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( $this->variantOf( $source ) ), 'Unlinking a translation touched the product.' );
		$this->assertSame( SellabilityReason::NotTranslated, $this->verdictIn( $source, 'en_GB' ) );
	}

	/**
	 * Row c: tests that a copy the multilingual plugin makes of a product's post joins the product rather than becoming a second one, and the plugin's own copy is a new product in the original's language.
	 *
	 * @since 0.1.0
	 */
	public function test_row_c_the_multilingual_plugins_copy_is_reconciled_and_a_first_party_copy_is_a_new_product(): void {
		$b      = $this->secondConnection();
		$source = $this->created( 'Shirt' );
		$copy   = $this->translated( $source, 'de_DE', (string) get_post_field( 'post_title', $source ) );

		$this->assertSame( $this->productOf( $source ), $this->productOf( $copy ) );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS ), 'The copy became a second product.' );

		$duplicate = $this->services->duplicate->duplicate( (int) $this->productOf( $copy ), Actor::user( $this->admin ) );

		$this->trackPost( $duplicate->postId );

		$this->assertNotSame( $this->productOf( $source ), $duplicate->productId );
		$this->assertSame( 'draft', get_post_status( $duplicate->postId ) );
		$this->assertSame( 'en_US', $this->plugin()->languageOf( $duplicate->postId ) );
		$this->assertNotContains( $source, $this->plugin()->groupOf( $duplicate->postId ), 'The copy was put in the original\'s group.' );
	}

	/**
	 * Row d: tests that saving the price through any translation, with the multilingual plugin copying custom fields, writes the one price every post shows.
	 *
	 * @since 0.1.0
	 */
	public function test_row_d_a_synchronized_field_never_becomes_a_second_authority(): void {
		$b      = $this->secondConnection();
		$source = $this->created( 'Shirt' );
		$german = $this->translated( $source, 'de_DE', 'Hemd' );

		$this->plugin()->copyCustomFields();

		$saved = $this->request( 'PUT', '/' . $german, array( ProductCommerceSchema::PROPERTY => array( 'price_minor' => 2500 ) ) );

		$this->assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$this->assertSame( '2500', $b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( price_minor ) FROM `%s`', $this->catalogTable( CatalogTables::VARIANT_PRICES ) ) ) );
		$this->assertSame( 2500, $this->commerce( $source )['price_minor'] ?? null );
		$this->assertSame( array(), $this->commerceMeta( $source, $german ), 'A commerce value was stored where the multilingual plugin copies fields.' );
	}

	/**
	 * Row e: tests that the source post is the one recorded, never the default language's, that it is not unlinked without a promotion, and that a promotion moves it.
	 *
	 * @since 0.1.0
	 */
	public function test_row_e_the_source_is_recorded_and_moves_only_by_a_promotion(): void {
		$german  = $this->created( 'Hemd', 'de_DE' );
		$english = $this->translated( $german, 'en_US', 'Shirt' );
		$product = (int) $this->productOf( $german );

		$this->assertSame( $german, $this->sourceOf( $german ), 'The source was inferred from the default language.' );

		try {
			$this->services->bindings->unlink( $german );
			$this->fail( 'The source post was unlinked without a promotion.' );
		} catch ( CodedException $kept ) {
			$this->assertSame( CatalogError::SourceBindingKept, $kept->errorCode() );
		}

		$this->services->bindings->promote( $product, $english, Actor::user( $this->admin ) );
		$this->services->bindings->unlink( $german );

		$this->assertSame( $english, $this->sourceOf( $english ) );
		$this->assertNull( $this->productOf( $german ) );
		$this->assertCount( 1, $this->promotions() );
	}

	/**
	 * Row f: tests that deleting a translation's post removes its mapping only, deleting the source post promotes the oldest published other post, and deleting the last post deletes the product.
	 *
	 * @since 0.1.0
	 */
	public function test_row_f_deleting_a_translation_removes_its_mapping_and_the_source_is_handed_over(): void {
		$b       = $this->secondConnection();
		$source  = $this->created( 'Shirt' );
		$british = $this->translated( $source, 'en_GB', 'Shirt (UK)', 'draft' );
		$german  = $this->translated( $source, 'de_DE', 'Hemd' );
		$product = (int) $this->productOf( $source );

		$this->plugin()->delete( $german );

		$this->assertSame( $product, $this->productOf( $source ), 'Deleting a translation took the product with it.' );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
		$this->assertSame( SellabilityReason::NotTranslated, $this->verdictIn( $source, 'de_DE' ) );

		$german = $this->translated( $source, 'de_DE', 'Hemd' );

		$this->plugin()->delete( $source );

		$this->assertSame( $german, $this->sourceOf( $german ), 'The oldest published post did not become the source.' );
		$this->assertSame( $product, $this->productOf( $british ) );
		$this->assertCount( 1, $this->promotions() );

		$this->plugin()->delete( $german );
		$this->plugin()->delete( $british );

		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCT_POSTS ), 'A deleted post left a binding, or was given a product as it went.' );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Row g: tests that changing the multilingual plugin's default language changes neither the source post nor any binding, however the product is written afterwards.
	 *
	 * @since 0.1.0
	 */
	public function test_row_g_changing_the_default_language_changes_nothing(): void {
		$b      = $this->secondConnection();
		$source = $this->created( 'Shirt' );
		$german = $this->translated( $source, 'de_DE', 'Hemd' );
		$before = $this->catalogChecksums( $b );

		$this->plugin()->setDefaultLanguage( 'de_DE' );

		try {
			wp_update_post(
				array(
					'ID'         => $source,
					'post_title' => 'Shirt, edited',
				)
			);
			$this->request( 'PUT', '/' . $german, array( 'title' => 'Hemd, bearbeitet' ) );

			$this->assertSame( $source, $this->sourceOf( $german ), 'The default language chose the source.' );
			$this->assertSame( $before[ CatalogTables::PRODUCT_POSTS ] ?? null, $this->catalogChecksums( $b )[ CatalogTables::PRODUCT_POSTS ] ?? null, 'A binding changed.' );
			$this->assertSame( array(), $this->promotions() );
		} finally {
			$this->plugin()->setDefaultLanguage( 'en_US' );
		}
	}

	/**
	 * Row g: tests that a product post the multilingual plugin does not translate keeps the site's locale when the default language changes and the post is written.
	 *
	 * The plugin gives such a post no language; its binding is then left as it is, never moved to
	 * the plugin's default language.
	 *
	 * @since 0.1.0
	 */
	public function test_row_g_a_post_the_plugin_does_not_translate_keeps_its_locale(): void {
		$this->plugin()->translateProducts( false );

		try {
			$made   = $this->request(
				'POST',
				'',
				array(
					'title'                         => 'Shirt',
					'status'                        => 'publish',
					ProductCommerceSchema::PROPERTY => array(
						'sku'         => 'SKU-1',
						'price_minor' => 1999,
					),
				)
			);
			$postId = (int) ( $made->get_data()['id'] ?? 0 );

			$this->assertSame( 201, $made->get_status(), (string) wp_json_encode( $made->get_data() ) );
			$this->trackPost( $postId );
			$this->assertNull( $this->plugin()->languageOf( $postId ), 'The plugin gave an untranslated post a language.' );

			$this->plugin()->setDefaultLanguage( 'de_DE' );

			wp_update_post(
				array(
					'ID'         => $postId,
					'post_title' => 'Shirt, edited',
				)
			);

			$this->assertSame( 'en_US', $this->products->findByPost( $postId )?->bindingOf( $postId )?->locale()->toString(), 'The binding followed the default language.' );
		} finally {
			$this->plugin()->setDefaultLanguage( 'en_US' );
			$this->plugin()->translateProducts( true );
		}
	}

	/**
	 * Row h: translated taxonomies.
	 *
	 * @since 0.1.0
	 */
	public function test_row_h_translated_taxonomies(): void {
		$this->markTestSkipped( 'Out of scope: the catalog has no product taxonomy yet.' );
	}

	/**
	 * Row i: tests that the product endpoint writes a translated product with its language and its group, which a free multilingual plugin's REST API may not.
	 *
	 * @since 0.1.0
	 */
	public function test_row_i_the_endpoint_gives_a_post_its_language_and_its_group(): void {
		$b      = $this->secondConnection();
		$source = $this->created( 'Shirt' );
		$made   = $this->request(
			'POST',
			'',
			array(
				'title'                         => 'Hemd',
				'status'                        => 'publish',
				ProductCommerceSchema::PROPERTY => array(
					ProductCommerceSchema::TRANSLATION_OF => $source,
					ProductCommerceSchema::LOCALE         => 'de_DE',
				),
			)
		);
		$data   = $made->get_data();
		$german = (int) ( $data['id'] ?? 0 );

		$this->assertSame( 201, $made->get_status(), (string) wp_json_encode( $data ) );
		$this->trackPost( $german );
		$this->assertSame(
			array(
				'sku'            => 'SKU-1',
				'locale'         => 'de_DE',
				'translation_of' => $source,
				'sellability'    => 'sellable',
			),
			array_intersect_key( (array) ( $data[ ProductCommerceSchema::PROPERTY ] ?? array() ), array_flip( array( 'sku', 'locale', 'translation_of', 'sellability' ) ) )
		);
		$this->assertSame( 'de_DE', $this->plugin()->languageOf( $german ) );
		$this->assertSame(
			array(
				'de_DE' => $german,
				'en_US' => $source,
			),
			$this->plugin()->groupOf( $source )
		);
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS ) );

		$refused = $this->request(
			'POST',
			'',
			array(
				'title'                         => 'Chemise',
				ProductCommerceSchema::PROPERTY => array( ProductCommerceSchema::LOCALE => 'fr_FR' ),
			)
		);

		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( CatalogError::LocaleUnsupported->value, $refused->get_data()['code'] ?? null );
	}

	/**
	 * Row i: tests that a new translation's first save, which the editor sends for a post that starts in the language of the post it translates, joins that post's product in the locale it names.
	 *
	 * The editor creates the new post before the first save, and the multilingual plugin gives it
	 * its default language, the original's; the product editor then sends the post it translates
	 * and the locale of the language the "add translation" link asked for.
	 *
	 * @since 0.1.0
	 */
	public function test_row_i_a_new_translations_first_save_names_its_locale(): void {
		$b      = $this->secondConnection();
		$source = $this->created( 'Shirt' );
		$draft  = get_default_post_to_edit( ProductCapabilities::POST_TYPE, true );

		$this->trackPost( $draft->ID );
		$this->assertSame( 'en_US', $this->plugin()->languageOf( $draft->ID ), 'The new post did not start in the language of the post it translates.' );

		$saved = $this->request(
			'PUT',
			'/' . $draft->ID,
			array(
				'title'                         => 'Hemd',
				'status'                        => 'publish',
				ProductCommerceSchema::PROPERTY => array(
					ProductCommerceSchema::TRANSLATION_OF => $source,
					ProductCommerceSchema::LOCALE         => 'de_DE',
				),
			)
		);

		$this->assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$this->assertSame( 'de_DE', $saved->get_data()[ ProductCommerceSchema::PROPERTY ][ ProductCommerceSchema::LOCALE ] ?? null );
		$this->assertSame(
			array(
				'de_DE' => $draft->ID,
				'en_US' => $source,
			),
			$this->plugin()->groupOf( $source )
		);
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
	}

	/**
	 * Row i: tests that a catalog editor may neither join, nor change through a post of their own, a product they may not edit: the endpoint refuses a translation that names it, and a price change through a post the multilingual plugin's own screen put in its group.
	 *
	 * @since 0.1.0
	 */
	public function test_row_i_a_translator_cannot_change_a_product_they_may_not_edit(): void {
		$b      = $this->secondConnection();
		$source = $this->created( 'Shirt' );
		$editor = $this->createUser( 'seocart_catalog_editor' );

		( new \WP_User( $editor ) )->add_cap( ProductCapabilities::map()['edit_others_posts'], false );
		wp_set_current_user( $editor );

		$joined = $this->request(
			'POST',
			'',
			array(
				'title'                         => 'Hemd',
				'status'                        => 'draft',
				ProductCommerceSchema::PROPERTY => array(
					ProductCommerceSchema::TRANSLATION_OF => $source,
					ProductCommerceSchema::LOCALE         => 'de_DE',
					'price_minor'                         => 1,
				),
			)
		);

		$this->assertSame( 403, $joined->get_status(), 'A translation joined a product its author may not edit.' );

		$own = $this->inLanguage( 'de_DE', 'Hemd', 'draft' );

		$this->plugin()->link( $source, $own );

		$this->assertSame( $this->productOf( $source ), $this->productOf( $own ), 'The lifecycle did not adopt the post.' );

		$changed = $this->request( 'PUT', '/' . $own, array( ProductCommerceSchema::PROPERTY => array( 'price_minor' => 2 ) ) );

		$this->assertSame( 403, $changed->get_status(), 'A price was changed through a post its author may not edit the product of.' );
		$this->assertSame( '1999', $b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( price_minor ) FROM `%s`', $this->catalogTable( CatalogTables::VARIANT_PRICES ) ) ) );
	}

	/**
	 * Row j: import.
	 *
	 * @since 0.1.0
	 */
	public function test_row_j_import(): void {
		$this->markTestSkipped( 'Out of scope: there is no import yet.' );
	}

	/**
	 * Row k: tests that a product is `not_translated` in a locale it has no post in, and a translation's post is judged on its own status.
	 *
	 * @since 0.1.0
	 */
	public function test_row_k_an_untranslated_product_is_not_sold_in_that_locale(): void {
		$source = $this->created( 'Shirt' );

		$this->assertSame(
			array( SellabilityReason::Sellable, SellabilityReason::NotTranslated, SellabilityReason::NotTranslated ),
			array( $this->verdictIn( $source, 'en_US' ), $this->verdictIn( $source, 'de_DE' ), $this->verdictIn( $source, 'en_GB' ) )
		);

		$german = $this->translated( $source, 'de_DE', 'Hemd', 'draft' );

		$this->assertSame( SellabilityReason::NotPublished, $this->verdictIn( $source, 'de_DE' ) );
		$this->assertSame( 'not_published', $this->commerce( $german )['sellability'] ?? null, 'The endpoint judged the translation on its source post.' );
		$this->assertSame( 'sellable', $this->commerce( $source )['sellability'] ?? null );
	}

	/**
	 * Row l: the per-locale index.
	 *
	 * @since 0.1.0
	 */
	public function test_row_l_per_locale_index(): void {
		$this->markTestSkipped( 'Out of scope: there is no search or facet index yet.' );
	}

	/**
	 * Row m: the three homes for text.
	 *
	 * @since 0.1.0
	 */
	public function test_row_m_three_homes_for_text(): void {
		$this->markTestSkipped( 'Out of scope: notification templates and translatable fields come later.' );
	}

	/**
	 * Row n: the order's locale.
	 *
	 * @since 0.1.0
	 */
	public function test_row_n_order_locale(): void {
		$this->markTestSkipped( 'Out of scope: there are no orders yet.' );
	}

	/**
	 * Creates a whole published product through the endpoint, in a language, and returns its post.
	 *
	 * @since 0.1.0
	 *
	 * @param string $title  The title.
	 * @param string $locale Optional. The language. Default en_US.
	 * @return int The post.
	 */
	private function created( string $title, string $locale = 'en_US' ): int {
		$made = $this->request(
			'POST',
			'',
			array(
				'title'                         => $title,
				'status'                        => 'publish',
				ProductCommerceSchema::PROPERTY => array(
					'sku'                         => 'SKU-1',
					'price_minor'                 => 1999,
					ProductCommerceSchema::LOCALE => $locale,
				),
			)
		);

		$this->assertSame( 201, $made->get_status(), (string) wp_json_encode( $made->get_data() ) );

		$postId = (int) ( $made->get_data()['id'] ?? 0 );

		$this->trackPost( $postId );

		return $postId;
	}

	/**
	 * Writes a post in a language through the multilingual plugin, and deletes it after the test.
	 *
	 * @since 0.1.0
	 *
	 * @param string $locale The language.
	 * @param string $title  The title.
	 * @param string $status Optional. The status. Default `publish`.
	 * @return int The post.
	 */
	private function inLanguage( string $locale, string $title, string $status = 'publish' ): int {
		$postId = $this->plugin()->write( $locale, $title, $status );

		$this->trackPost( $postId );

		return $postId;
	}

	/**
	 * Writes a post in a language through the multilingual plugin and makes it a translation of another, as the plugin's editor does.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $original The post it translates.
	 * @param string $locale   The language.
	 * @param string $title    The title.
	 * @param string $status   Optional. The status. Default `publish`.
	 * @return int The post.
	 */
	private function translated( int $original, string $locale, string $title, string $status = 'publish' ): int {
		$postId = $this->inLanguage( $locale, $title, $status );

		$this->plugin()->link( $original, $postId );

		return $postId;
	}

	/**
	 * Returns the multilingual plugin's fixture; set_up() has skipped the test without one.
	 *
	 * @since 0.1.0
	 *
	 * @return MultilingualFixture The fixture.
	 */
	private function plugin(): MultilingualFixture {
		$this->assertNotNull( self::$plugin );

		return self::$plugin;
	}

	/**
	 * Returns the product a post presents.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return int|null The product, or null.
	 */
	private function productOf( int $postId ): ?int {
		return $this->products->findByPost( $postId )?->id();
	}

	/**
	 * Returns the source post of the product a post presents.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return int|null The source post, or null.
	 */
	private function sourceOf( int $postId ): ?int {
		return $this->products->findByPost( $postId )?->sourcePostId();
	}

	/**
	 * Returns the default variant of the product a post presents.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return int The variant.
	 */
	private function variantOf( int $postId ): int {
		return (int) $this->products->findByPost( $postId )?->defaultVariant()?->id();
	}

	/**
	 * Returns the verdict on the default variant of the product a post presents, in a locale.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $postId The post.
	 * @param string $locale The locale.
	 * @return SellabilityReason The verdict.
	 */
	private function verdictIn( int $postId, string $locale ): SellabilityReason {
		$variant = $this->variantOf( $postId );

		return ( new Sellability( $this->products ) )->of( array( $variant ), false, Locale::of( $locale ) )[ $variant ];
	}

	/**
	 * Reads a post's `seocart` object through the endpoint, in the edit context.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return array<string, mixed> The object.
	 */
	private function commerce( int $postId ): array {
		return (array) ( $this->request( 'GET', '/' . $postId, array(), array( 'context' => 'edit' ) )->get_data()[ ProductCommerceSchema::PROPERTY ] ?? array() );
	}

	/**
	 * Returns the custom fields of posts whose key names a commerce field or the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param int ...$postIds The posts.
	 * @return list<string> The keys.
	 */
	private function commerceMeta( int ...$postIds ): array {
		$keys = array();

		foreach ( $postIds as $postId ) {
			foreach ( array_keys( (array) get_post_meta( $postId ) ) as $key ) {
				if ( 1 === preg_match( '/seocart|sku|price|stock/i', (string) $key ) ) {
					$keys[] = (string) $key;
				}
			}
		}

		return $keys;
	}

	/**
	 * Returns the payloads of the committed ProductBindingPromoted events.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The payloads, as stored.
	 *
	 * @phpstan-impure
	 */
	private function promotions(): array {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT payload_json FROM %i WHERE event_name = %s ORDER BY id', $this->db->table( OutboxTable::NAME ), ProductBindingPromoted::eventName() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The test reads the outbox the service wrote.
	}
}

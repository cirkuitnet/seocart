<?php
/**
 * Tests the product editor's script and stylesheet: enqueued on the product editor only, and given their fields from the declarations
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Application\ProductWrite\VariantFields;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Catalog\Interfaces\Admin\ProductEditorPanel;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\Doubles\SeveralLocales;
use WP_UnitTestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The test writes a stand-in build into a temporary directory.

/**
 * The script and the stylesheet are enqueued on the product editor's screen only, from a build,
 * the script with what it is given printed before it: the fields of the two lists, each with its
 * label and the kind of control it takes, the currencies and their decimal places, where the stock
 * is read and whether the user may, and a sentence for every verdict.
 *
 * Planted violation: in ProductEditorPanel::buildOnTheProductEditor(), drop the post-type check: the
 * post editor enqueues them too, and the test of the other screens fails.
 *
 * @since 0.1.0
 */
final class ProductEditorPanelTest extends WP_UnitTestCase {

	/**
	 * A plugin directory holding a stand-in build.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $directory = '';

	/**
	 * Writes a stand-in build of the panel into a temporary plugin directory.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		// Another test may have unregistered the type the kernel registers on `init`; a screen of an unregistered type has no post type.
		if ( ! post_type_exists( ProductCapabilities::POST_TYPE ) ) {
			ProductPostType::register();
		}

		$this->directory = (string) tempnam( sys_get_temp_dir(), 'seocart-panel-' );

		unlink( $this->directory );
		mkdir( $this->directory . '/build/admin', 0700, true );
		file_put_contents( $this->directory . '/seocart.php', '<?php' );
		file_put_contents( $this->directory . '/build/admin/product-editor.asset.json', '{"dependencies":["wp-plugins","wp-editor"],"version":"stand-in"}' );
	}

	/**
	 * Removes the stand-in build, the script and the screen.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		wp_dequeue_script( ProductEditorPanel::HANDLE );
		wp_deregister_script( ProductEditorPanel::HANDLE );
		wp_dequeue_style( ProductEditorPanel::HANDLE );
		wp_deregister_style( ProductEditorPanel::HANDLE );

		foreach ( array( '/build/admin/product-editor.asset.json', '/seocart.php' ) as $file ) {
			if ( is_file( $this->directory . $file ) ) {
				unlink( $this->directory . $file );
			}
		}

		foreach ( array( '/build/admin', '/build', '' ) as $directory ) {
			if ( is_dir( $this->directory . $directory ) ) {
				rmdir( $this->directory . $directory );
			}
		}

		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- resets the screen the test set.

		parent::tear_down();
	}

	/**
	 * Tests that the product editor enqueues the panel with its settings printed before it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_product_editor_enqueues_the_panel(): void {
		set_current_screen( ProductCapabilities::POST_TYPE );

		$this->panel( $this->directory . '/seocart.php' )->enqueue();

		$this->assertTrue( wp_script_is( ProductEditorPanel::HANDLE, 'enqueued' ) );
		$this->assertSame( array( 'wp-plugins', 'wp-editor', 'wp-i18n' ), wp_scripts()->registered[ ProductEditorPanel::HANDLE ]->deps, 'The dependencies of the build, and the script of the translations.' );
		$this->assertSame( 'seocart', wp_scripts()->registered[ ProductEditorPanel::HANDLE ]->textdomain ?? null );
		$this->assertStringContainsString( 'var ' . ProductEditorPanel::SETTINGS_GLOBAL . ' = ', implode( '', (array) wp_scripts()->get_data( ProductEditorPanel::HANDLE, 'before' ) ) );
	}

	/**
	 * Tests that the product editor enqueues the stylesheet of the build, whose version is the script's, with its right-to-left copy beside it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_product_editor_enqueues_the_stylesheet(): void {
		set_current_screen( ProductCapabilities::POST_TYPE );

		$this->panel( $this->directory . '/seocart.php' )->enqueueStyles();

		$this->assertTrue( wp_style_is( ProductEditorPanel::HANDLE, 'enqueued' ) );

		$style = wp_styles()->registered[ ProductEditorPanel::HANDLE ];

		$this->assertStringEndsWith( '/build/admin/style-product-editor.css', $style->src );
		$this->assertSame( 'stand-in', $style->ver );
		$this->assertSame( 'replace', wp_styles()->get_data( ProductEditorPanel::HANDLE, 'rtl' ) );
	}

	/**
	 * Tests that another screen, the post editor included, and a checkout without a build enqueue nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_other_screens_and_a_missing_build_enqueue_nothing(): void {
		foreach ( array( 'post', 'page', 'dashboard' ) as $screen ) {
			set_current_screen( $screen );

			$this->panel( $this->directory . '/seocart.php' )->enqueue();
			$this->panel( $this->directory . '/seocart.php' )->enqueueStyles();

			$this->assertFalse( wp_script_is( ProductEditorPanel::HANDLE, 'enqueued' ), "The script was enqueued on the {$screen} screen." );
			$this->assertFalse( wp_style_is( ProductEditorPanel::HANDLE, 'enqueued' ), "The stylesheet was enqueued on the {$screen} screen." );
		}

		set_current_screen( ProductCapabilities::POST_TYPE );
		unlink( $this->directory . '/build/admin/product-editor.asset.json' );

		$this->panel( $this->directory . '/seocart.php' )->enqueue();
		$this->panel( $this->directory . '/seocart.php' )->enqueueStyles();

		$this->assertFalse( wp_script_is( ProductEditorPanel::HANDLE, 'registered' ), 'The script was enqueued without a build.' );
		$this->assertFalse( wp_style_is( ProductEditorPanel::HANDLE, 'registered' ), 'The stylesheet was enqueued without a build.' );
	}

	/**
	 * Tests that a new product's editor starts with the Product data block, as a literal the registration reads nothing for, and that the script is told its name.
	 *
	 * @since 0.2.0
	 */
	public function test_the_post_type_template_places_the_block_the_script_registers(): void {
		$arguments = ProductPostType::arguments();

		$this->assertSame( array( array( 'seocart/product-data' ) ), $arguments['template'] );
		$this->assertFalse( $arguments['template_lock'] );
		$this->assertSame( 'seocart/product-data', $this->panel( $this->directory . '/seocart.php' )->settings()['block'] );
	}

	/**
	 * Tests that the script is told where the stock is read, how many variants a request may name, and whether the user may.
	 *
	 * @since 0.2.0
	 */
	public function test_the_script_is_told_where_the_stock_is_and_whether_the_user_may_read_it(): void {
		$administrator = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );

		$administrator->add_cap( InventoryOperations::CAPABILITY );
		wp_set_current_user( $administrator->ID );

		$stock = $this->panel( $this->directory . '/seocart.php' )->settings()['stock'];

		$this->assertTrue( $stock['canManage'] );
		$this->assertSame( 'seocart/v1/locations', $stock['locationsPath'] );
		$this->assertSame( 'seocart/v1/stock-items', $stock['stockPath'] );
		$this->assertSame( 200, $stock['mostListed'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( $this->panel( $this->directory . '/seocart.php' )->settings()['stock']['canManage'], 'A user who may not manage inventory is not told to read it.' );
	}

	/**
	 * Tests that the script is given the fields of the two lists, with their kinds and bounds, the currencies, and a sentence for every verdict.
	 *
	 * @since 0.1.0
	 */
	public function test_the_script_is_given_its_fields_from_the_declarations(): void {
		$settings = $this->panel( $this->directory . '/seocart.php' )->settings();

		$this->assertSame( ProductCapabilities::POST_TYPE, $settings['postType'] );
		$this->assertSame( ProductCommerceSchema::PROPERTY, $settings['property'] );
		$this->assertSame(
			array(
				'code'     => 'JPY',
				'exponent' => 0,
			),
			$settings['currency']
		);
		$this->assertSame(
			array(
				array(
					'code'     => 'JPY',
					'exponent' => 0,
				),
				array(
					'code'     => 'EUR',
					'exponent' => 2,
				),
			),
			$settings['currencies'],
			'Every currency the store offers, with its exponent.'
		);

		$lists    = array_column( $settings['lists'], null, 'name' );
		$variants = array_column( $lists['variants']['fields'] ?? array(), null, 'name' );

		$this->assertSame( array( 'options', 'variants' ), array_keys( $lists ) );
		$this->assertSame( array( 'list', Product::MAX_SYNC_VARIANTS ), array( $lists['variants']['kind'], $lists['variants']['maxItems'] ) );
		$this->assertSame( array( 'list', Product::MAX_AXES ), array( $lists['options']['kind'], $lists['options']['maxItems'] ) );
		$this->assertSame( array( 'variant_id', 'combination', 'sku', 'enabled', 'position', 'label', 'weight_grams', 'prices', 'stock', 'sellability' ), array_keys( $variants ) );
		$this->assertSame( array( 'boolean', 'list', 'list' ), array( $variants['enabled']['kind'], $variants['prices']['kind'], $variants['stock']['kind'] ) );
		$this->assertSame( 'amount', array_column( $variants['prices']['fields'], 'kind', 'name' )['price_minor'] ?? null, 'A variant\'s price is an amount, as the single price is.' );
		$this->assertSame( 'integer', $variants['weight_grams']['kind'] );
		$this->assertSame( CommerceFields::WEIGHT_MAX_GRAMS, $variants['weight_grams']['maximum'], 'A field is given its bounds.' );
		$this->assertSame( VariantFields::ON_HAND_MAX, array_column( $variants['stock']['fields'], 'maximum', 'name' )['on_hand'] ?? null );
		$this->assertSame( array_map( static fn( SellabilityReason $reason ): string => $reason->value, SellabilityReason::cases() ), array_keys( $settings['sellability']['reasons'] ) );
		$this->assertNotContains( '', $settings['sellability']['reasons'], 'A verdict has no sentence.' );
		$this->assertNotContains( '', array_column( $lists['variants']['fields'], 'label' ), 'A field has no label.' );
		$this->assertSame(
			array(
				'field'     => ProductCommerceSchema::TRANSLATION_OF,
				'locale'    => ProductCommerceSchema::LOCALE,
				'languages' => array(
					'en'    => 'en_US',
					'en-gb' => 'en_GB',
					'de'    => 'de_DE',
				),
			),
			$settings['translation'],
			'A new translation is not given the fields and the languages its first save sends.'
		);
	}

	/**
	 * Builds the panel over a plugin file, in a store whose base currency is the yen.
	 *
	 * @since 0.1.0
	 *
	 * @param string $pluginFile The plugin file.
	 * @return ProductEditorPanel The panel.
	 */
	private function panel( string $pluginFile ): ProductEditorPanel {
		return new ProductEditorPanel( $pluginFile, static fn(): Currency => Currency::of( 'JPY' ), new SeveralLocales(), static fn(): array => array( Currency::of( 'JPY' ), Currency::of( 'EUR' ) ) );
	}
}

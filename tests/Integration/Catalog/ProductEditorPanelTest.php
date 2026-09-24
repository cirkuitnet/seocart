<?php
/**
 * Tests the product editor's panel: enqueued on the product editor only, and given its fields from the declarations
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Interfaces\Admin\ProductEditorPanel;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Support\Currency;
use WP_UnitTestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The test writes a stand-in build into a temporary directory.

/**
 * The panel is enqueued on the product editor's screen only, from a build, with what it is given
 * printed before it: the writable commerce fields, each with its label and the kind of control
 * it takes, the base currency and its decimal places, and a sentence for every verdict.
 *
 * Planted violation: in ProductEditorPanel::enqueue(), drop the post-type check: the post
 * editor enqueues the panel too, and the test of the other screens fails.
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
	 * Tests that another screen, the post editor included, and a checkout without a build enqueue nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_other_screens_and_a_missing_build_enqueue_nothing(): void {
		foreach ( array( 'post', 'page', 'dashboard' ) as $screen ) {
			set_current_screen( $screen );

			$this->panel( $this->directory . '/seocart.php' )->enqueue();

			$this->assertFalse( wp_script_is( ProductEditorPanel::HANDLE, 'enqueued' ), "The panel was enqueued on the {$screen} screen." );
		}

		set_current_screen( ProductCapabilities::POST_TYPE );
		unlink( $this->directory . '/build/admin/product-editor.asset.json' );

		$this->panel( $this->directory . '/seocart.php' )->enqueue();

		$this->assertFalse( wp_script_is( ProductEditorPanel::HANDLE, 'registered' ), 'The panel was enqueued without a build.' );
	}

	/**
	 * Tests that the panel is given the writable fields but the currency, with their kinds, the base currency, and a sentence for every verdict.
	 *
	 * @since 0.1.0
	 */
	public function test_the_panel_is_given_its_fields_from_the_declarations(): void {
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
				CommerceFields::SKU              => 'text',
				CommerceFields::PRICE_MINOR      => 'amount',
				CommerceFields::COMPARE_AT_MINOR => 'amount',
				CommerceFields::WEIGHT_GRAMS     => 'integer',
			),
			array_column( $settings['fields'], 'kind', 'name' )
		);
		$this->assertSame( array_map( static fn( SellabilityReason $reason ): string => $reason->value, SellabilityReason::cases() ), array_keys( $settings['sellability']['reasons'] ) );
		$this->assertNotContains( '', $settings['sellability']['reasons'], 'A verdict has no sentence.' );
		$this->assertNotContains( '', array_column( $settings['fields'], 'label' ), 'A field has no label.' );
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
		return new ProductEditorPanel( $pluginFile, static fn(): Currency => Currency::of( 'JPY' ) );
	}
}

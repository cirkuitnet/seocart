<?php
/**
 * Tests the product blocks rendered as a page renders them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Storefront;

use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Platform\Storefront\StorefrontAssets;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;

/**
 * The three blocks render a saved product from one state, printed once into the store
 * `seocart/product` under the post's id, with WordPress's block class on each root, the
 * storefront's stylesheet and the product's module loaded and the store's configuration printed;
 * on a post that is not a product's they show nothing and read nothing, and the editor's preview
 * shows a placeholder; a product that sells no variant says it is unavailable.
 *
 * The blocks render over a container of the test's own (RendersProductBlocks), as a request
 * builds one.
 *
 * @since 0.2.0
 */
final class ProductBlocksTest extends ProductWriteTestCase {

	use RendersProductBlocks;
	use SavesVariantSets;

	/**
	 * The blocks, in the order a product's page shows them.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const BLOCKS = array( 'seocart/product-price', 'seocart/variant-picker', 'seocart/add-to-cart' );

	/**
	 * Puts the kernel's blocks back.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_is_rest_endpoint' );
		$this->restoreProductBlocks();

		parent::tear_down();
	}

	/**
	 * Tests the three blocks of a saved product: their markup, the one state printed, what they load and the configuration printed.
	 *
	 * @since 0.2.0
	 */
	public function test_the_three_blocks_render_a_product_from_one_state(): void {
		$post = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'BLOCKS', VariantSets::SIZES, VariantSets::GRINDS, array(), false ) )->postId;

		$this->renderOver( $this->db, $this->reporter() );

		$markup = $this->renderBlocks( $post, ...self::BLOCKS );
		$state  = wp_interactivity_state( 'seocart/product' );

		$this->assertStringContainsString( '<div class="seocart-price wp-block-seocart-product-price"', $markup );
		$this->assertStringContainsString( '<div class="seocart-variant-picker wp-block-seocart-variant-picker"', $markup );
		$this->assertStringContainsString( '<form class="seocart-add-to-cart wp-block-seocart-add-to-cart"', $markup );
		$this->assertStringContainsString( 'data-wp-text="state.priceText">$12.00</span>', $markup, 'The first variant, in stock or not, priced 12.00 in US dollars.' );
		$this->assertSame( array( $post ), array_keys( $state['products'] ?? array() ), 'The state is printed once, under the post\'s id.' );
		$this->assertSame( array( 'uuid', 'name', 'axes', 'variants', 'selection', 'quantity' ), array_keys( $state['products'][ $post ] ) );
		$this->assertSame( 'Coffee', $state['products'][ $post ]['name'] );
		$this->assertCount( 6, $state['products'][ $post ]['variants'] );
		$this->assertSame(
			array(
				'minor' => 1200,
				'text'  => '$12.00',
			),
			$state['products'][ $post ]['variants'][0]['price']
		);
		$this->assertFalse( $state['products'][ $post ]['variants'][0]['inStock'], 'A variant with no unit at any location is not in stock.' );
		$this->assertTrue( wp_style_is( StorefrontAssets::STYLE, 'enqueued' ) );
		$this->assertContains( '@seocart/product', wp_script_modules()->get_queue() );
		$this->assertNotNull( wp_script_modules()->get_registered( '@seocart/store' ), 'The module the product module imports is registered for the import map.' );
		$this->assertSame( array( 'restRoot', 'nonceUrl', 'locale', 'currency', 'priceVersion', 'strings' ), array_keys( wp_interactivity_config( 'seocart/store' ) ) );
		$this->assertSame( 'USD', wp_interactivity_config( 'seocart/store' )['currency'] );
		$this->assertSame( 'en-US', wp_interactivity_config( 'seocart/store' )['locale'] );
		$this->assertStringEndsWith( 'admin-ajax.php?action=rest-nonce', wp_interactivity_config( 'seocart/store' )['nonceUrl'] );
	}

	/**
	 * Tests that the price block alone loads the stylesheet and no module: a shopper does not act on it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_price_alone_loads_no_module(): void {
		$post = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'PRICE', VariantSets::SIZES, VariantSets::GRINDS, array(), false ) )->postId;

		$this->renderOver( $this->db, $this->reporter() );
		$this->renderBlocks( $post, 'seocart/product-price' );

		$this->assertTrue( wp_style_is( StorefrontAssets::STYLE, 'enqueued' ) );
		$this->assertSame( array(), wp_script_modules()->get_queue() );
		$this->assertSame( array(), wp_interactivity_config( 'seocart/store' ) );
	}

	/**
	 * Tests that a block on a post that is not a product's shows nothing, reads no product and loads nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_block_on_another_post_shows_nothing_and_reads_nothing(): void {
		$post = self::factory()->post->create();

		$this->renderOver( $this->db, $this->reporter() );

		$log = $this->captureQueries( fn() => $this->assertSame( '', $this->renderBlocks( $post, ...self::BLOCKS ) ) );

		$this->assertSame( 0, $log->matching( '/seocart_/' )->count(), 'A block read the plugin\'s tables for a post that is not a product\'s: ' . $log->describe() );
		$this->assertFalse( wp_style_is( StorefrontAssets::STYLE, 'registered' ) );
	}

	/**
	 * Tests that the editor's preview, which WordPress renders over the REST API, shows a placeholder where there is no product.
	 *
	 * @since 0.2.0
	 */
	public function test_the_editor_preview_shows_a_placeholder_where_there_is_no_product(): void {
		$post = self::factory()->post->create();

		add_filter( 'wp_is_rest_endpoint', '__return_true' );
		$this->renderOver( $this->db, $this->reporter() );

		$this->assertSame( '<p class="seocart-placeholder wp-block-seocart-product-price">Product price: shown on the page of a product.</p>', $this->renderBlocks( $post, 'seocart/product-price' ) );
	}

	/**
	 * Tests that a product whose generation is not complete sells no variant: no price, no picker, and the sentence that it is unavailable.
	 *
	 * @since 0.2.0
	 */
	public function test_a_product_that_sells_no_variant_says_it_is_unavailable(): void {
		$product = $this->boundProduct( 'BLOCKS-INCOMPLETE', null );

		$this->products->save( $product );
		$this->products->leaveUpdating( (int) $product->id(), GenerationState::Incomplete );
		$this->renderOver( $this->db, $this->reporter() );

		$this->assertSame( '<p class="seocart-notice wp-block-seocart-add-to-cart">This product is unavailable right now.</p>', $this->renderBlocks( (int) $product->sourcePostId(), ...self::BLOCKS ) );
	}
}

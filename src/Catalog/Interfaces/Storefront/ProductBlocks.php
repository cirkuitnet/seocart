<?php
/**
 * ProductBlocks: what the product blocks of a page share as they render
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Storefront;

use SEOCart\Catalog\Application\Query\ProductPage;
use SEOCart\Catalog\Application\Query\ProductPageView;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Localization\RequestLanguage;
use SEOCart\Platform\Storefront\StoreConfig;
use SEOCart\Platform\Storefront\StorefrontAssets;
use SEOCart\Platform\Storefront\StorefrontStrings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a product block: finds the product it shows, prints that product's state once a request, loads what the block needs, and hands the state to the block's component.
 *
 * Owns one fact: how a product block becomes markup. A block placed in a product's content, or in
 * the template a product's page is rendered with, has the post in its context; on any other post,
 * or none, it shows nothing on a page and a one-line placeholder in the editor's preview, which
 * WordPress renders over the REST API, and nothing is read for it. The product blocks of one page
 * share one read of the product (ProductPage) and one state, printed into the store
 * `seocart/product` under the post's id the first time one of them renders: the variants the
 * sellability rule lets a visitor buy, each price as minor units and the server's text, and
 * whether each is in stock, never a count of units. A variant the rule refuses is left out, so
 * its combination of values reads as unavailable. The state's first paint is answered on the
 * server by closures the store's getters stand for in the browser.
 *
 * Every block loads the storefront's stylesheet; a block a shopper acts on also loads the store
 * module `@seocart/product`, with the store `@seocart/store` it imports, and the store's
 * configuration. Nothing in what is printed depends on the visitor.
 *
 * @since 0.2.0
 *
 * @phpstan-import-type State from ProductState
 */
final class ProductBlocks {

	/**
	 * The store the product blocks bind to.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STORE = 'seocart/product';

	/**
	 * Reads a product as a page shows it, once a request.
	 *
	 * @since 0.2.0
	 *
	 * @var ProductPage
	 */
	private ProductPage $page;

	/**
	 * Loads the storefront's modules and stylesheet.
	 *
	 * @since 0.2.0
	 *
	 * @var StorefrontAssets
	 */
	private StorefrontAssets $assets;

	/**
	 * Prints the store module's configuration.
	 *
	 * @since 0.2.0
	 *
	 * @var StoreConfig
	 */
	private StoreConfig $config;

	/**
	 * The language the page's words and prices are written in.
	 *
	 * @since 0.2.0
	 *
	 * @var RequestLanguage
	 */
	private RequestLanguage $language;

	/**
	 * The states printed in this request, by post id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, State>
	 */
	private array $products = array();

	/**
	 * The storefront's words, once read in this request.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>|null
	 */
	private ?array $strings = null;

	/**
	 * Creates the renderer's helper. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ProductPage      $page     Reads a product as a page shows it.
	 * @param StorefrontAssets $assets   Loads the storefront's modules and stylesheet.
	 * @param StoreConfig      $config   Prints the store module's configuration.
	 * @param RequestLanguage  $language The language the page's words and prices are written in.
	 */
	public function __construct( ProductPage $page, StorefrontAssets $assets, StoreConfig $config, RequestLanguage $language ) {
		$this->page     = $page;
		$this->assets   = $assets;
		$this->config   = $config;
		$this->language = $language;
	}

	/**
	 * Returns the product a block shows.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Block $block The block, with the post in its context.
	 * @return ProductPageView|null The product as the page shows it, or null when the post in the context is not a product's, or there is none.
	 */
	public function view( \WP_Block $block ): ?ProductPageView {
		if ( ProductCapabilities::POST_TYPE !== ( $block->context['postType'] ?? null ) ) {
			return null;
		}

		return $this->page->of( (int) ( $block->context['postId'] ?? 0 ) );
	}

	/**
	 * Renders a product block.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Block $block       The block.
	 * @param bool      $interactive Whether a shopper acts on the block, which then needs the store modules.
	 * @param callable  $component   Returns the block's markup from the product's post, its state and the storefront's words.
	 * @return string The block's markup, with WordPress's block classes on its root.
	 *
	 * @phpstan-param callable(int, State, array<string, string>): string $component
	 */
	public function render( \WP_Block $block, bool $interactive, callable $component ): string {
		$view = $this->view( $block );

		if ( null === $view ) {
			return wp_is_rest_endpoint() ? self::wrapped( BlockPlaceholder::markup( StorefrontStrings::format( $this->strings()['placeholder'], array( 'block' => (string) $block->block_type->title ) ) ) ) : '';
		}

		$strings = $this->strings();
		$product = $this->product( $view );

		$this->assets->style();

		if ( $interactive && array() !== $product['variants'] ) {
			$this->config->print();
			$this->assets->module( '@seocart/product' );
		}

		return self::wrapped( $component( $view->postId, $product, $strings ) );
	}

	/**
	 * Returns the markup of the images of a product's option values, by attachment id: decorative, since each value's label is written beside its image.
	 *
	 * @since 0.2.0
	 *
	 * @param array $product The product's state.
	 * @return array<int, string> The images, escaped; an attachment that is gone has none.
	 *
	 * @phpstan-param State $product
	 */
	public static function images( array $product ): array {
		$ids = array();

		foreach ( $product['axes'] as $axis ) {
			foreach ( $axis['values'] as $value ) {
				if ( null !== $value['image'] ) {
					$ids[] = $value['image'];
				}
			}
		}

		if ( array() === $ids ) {
			return array();
		}

		_prime_post_caches( $ids, false, true );

		$images = array();

		foreach ( $ids as $id ) {
			$images[ $id ] = wp_get_attachment_image(
				$id,
				'thumbnail',
				false,
				array(
					'alt'   => '',
					'class' => 'seocart-pill__image',
				)
			);
		}

		return array_filter( $images );
	}

	/**
	 * Returns a product's state, printing it into the store the first time a request asks for it.
	 *
	 * @since 0.2.0
	 *
	 * @param ProductPageView $view The product.
	 * @return array The state.
	 *
	 * @phpstan-return State
	 */
	private function product( ProductPageView $view ): array {
		if ( ! isset( $this->products[ $view->postId ] ) ) {
			$first = array() === $this->products;

			$this->products[ $view->postId ] = ProductState::of( $view, wp_strip_all_tags( (string) get_post_field( 'post_title', $view->postId, 'raw' ) ), $this->language->locale()->toString() );

			wp_interactivity_state( self::STORE, array( 'products' => array( $view->postId => $this->products[ $view->postId ] ) ) + ( $first ? $this->firstPaint() : array() ) );
		}

		return $this->products[ $view->postId ];
	}

	/**
	 * Returns the closures that answer, while WordPress writes the page, what the store's getters answer in the browser.
	 *
	 * WordPress evaluates the blocks' directives on the server with these and sends none of them:
	 * the page is written for the variant selected first, and the getters of `@seocart/product`
	 * take over for every change.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, \Closure> The closures, by the name of the getter each stands for.
	 */
	private function firstPaint(): array {
		$product  = fn(): ?array => $this->products[ (int) ( wp_interactivity_get_context( self::STORE )['postId'] ?? 0 ) ] ?? null;
		$selected = static fn( ?array $state ): ?array => null === $state ? null : ProductState::selected( $state );

		return array(
			'priceText'      => static fn(): string => $selected( $product() )['price']['text'] ?? '',
			'compareAtText'  => static fn(): string => $selected( $product() )['compareAt']['text'] ?? '',
			'axisValueLabel' => static fn(): string => null === $product() ? '' : ProductState::valueLabel( $product(), (string) ( wp_interactivity_get_context( self::STORE )['axis'] ?? '' ) ),
			'buttonLabel'    => fn(): string => $this->strings()[ ProductState::buttonLabel( $selected( $product() ) ) ],
			'cannotAdd'      => static fn(): bool => ! ProductState::canAdd( $selected( $product() ) ),
			'quantity'       => static fn(): int => $product()['quantity'] ?? 1,
		);
	}

	/**
	 * Returns the storefront's words, read once a request.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The words, by name.
	 */
	private function strings(): array {
		$this->strings ??= StorefrontStrings::all();

		return $this->strings;
	}

	/**
	 * Puts the classes WordPress gives the block being rendered, its own and the editor's additional ones, on the root of a component's markup.
	 *
	 * @since 0.2.0
	 *
	 * @param string $markup The component's markup.
	 * @return string The markup, its root carrying the block's classes too.
	 */
	private static function wrapped( string $markup ): string {
		$wrapper = new \WP_HTML_Tag_Processor( '<div ' . get_block_wrapper_attributes() . '>' );
		$root    = new \WP_HTML_Tag_Processor( $markup );

		if ( '' === $markup || ! $wrapper->next_tag() || ! $root->next_tag() ) {
			return $markup;
		}

		foreach ( preg_split( '/\s+/', (string) $wrapper->get_attribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			$root->add_class( $class );
		}

		return $root->get_updated_html();
	}
}

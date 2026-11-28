<?php
/**
 * Tests the markup the product components write from a product's state
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Storefront;

use SEOCart\Catalog\Interfaces\Storefront\AddToCartForm;
use SEOCart\Catalog\Interfaces\Storefront\BlockPlaceholder;
use SEOCart\Catalog\Interfaces\Storefront\ProductPrice;
use SEOCart\Catalog\Interfaces\Storefront\VariantPicker;
use SEOCart\Platform\Storefront\StorefrontStrings;
use WP_UnitTestCase;

/**
 * The price, the picker and the form write, for a product with two axes, one axis or none, a
 * variant that is sold out, one with no price in the page's currency, and a product that sells
 * no variant, the markup their docblocks show; and every class they print is in the published
 * list, docs/styling.md.
 *
 * The states are written by hand, in the shape ProductState writes; ProductBlocksTest renders
 * them from saved products.
 *
 * Planted violations, each shown red and removed: in ProductPrice::markup(), write a variant
 * without a price as `$0.00` (the unpriced variant's test); print a class missing from
 * docs/styling.md, such as `seocart-price__planted` on the amount (the published classes' test).
 *
 * @since 0.2.0
 */
final class ComponentsTest extends WP_UnitTestCase {

	/**
	 * The post the states belong to.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const POST = 41;

	/**
	 * Tests the price of the selected variant, its compare-at price struck through, and the compare-at line hidden for a variant without one.
	 *
	 * @since 0.2.0
	 */
	public function test_the_price_shows_the_selected_variant_and_its_reduction(): void {
		$product = self::coffee();
		$markup  = ProductPrice::markup( self::POST, $product, StorefrontStrings::all() );

		$this->assertStringContainsString( '<div class="seocart-price" data-wp-interactive="seocart/product" data-wp-context=\'{"postId":41}\'>', $markup );
		$this->assertStringContainsString( '<span class="seocart-price__amount seocart-money" data-wp-text="state.priceText">$18.00</span>', $markup );
		$this->assertStringContainsString( '<s class="seocart-price__compare-at" data-wp-bind--hidden="!state.compareAtText"><span class="seocart-visually-hidden">Original price </span><span class="seocart-money" data-wp-text="state.compareAtText">$24.00</span></s>', $markup );

		$product['selection'] = array(
			'size'  => '1kg',
			'grind' => 'whole',
		);

		$this->assertStringContainsString( '<s class="seocart-price__compare-at" data-wp-bind--hidden="!state.compareAtText" hidden>', ProductPrice::markup( self::POST, $product, StorefrontStrings::all() ), 'A variant without a compare-at price hides the line.' );
		$this->assertStringNotContainsString( 'seocart-price__compare-at', ProductPrice::markup( self::POST, self::flat(), StorefrontStrings::all() ), 'A product none of whose variants has a compare-at price has no such line.' );
	}

	/**
	 * Tests that a variant with no price in the page's currency shows no figure and cannot be added.
	 *
	 * @since 0.2.0
	 */
	public function test_a_variant_without_a_price_shows_no_figure_and_cannot_be_added(): void {
		$product              = self::coffee();
		$product['selection'] = array(
			'size'  => '1kg',
			'grind' => 'filter',
		);

		$this->assertStringContainsString( '<span class="seocart-price__amount seocart-money" data-wp-text="state.priceText"></span>', ProductPrice::markup( self::POST, $product, StorefrontStrings::all() ), 'A variant without a price shows no figure, never zero.' );
		$this->assertStringContainsString( 'aria-disabled="true" data-wp-bind--aria-disabled="state.cannotAdd" data-wp-bind--aria-busy="state.adding" data-wp-text="state.buttonLabel">Unavailable</button>', AddToCartForm::markup( self::POST, $product, StorefrontStrings::all() ) );
	}

	/**
	 * Tests the picker of two axes: a fieldset each, in order, its legend naming the selected value, every value a radio button with the default checked.
	 *
	 * @since 0.2.0
	 */
	public function test_the_picker_writes_a_fieldset_per_axis_with_the_default_checked(): void {
		$markup = VariantPicker::markup( self::POST, self::coffee(), array() );

		$this->assertSame( 2, substr_count( $markup, '<fieldset class="seocart-options"' ) );
		$this->assertSame( 5, substr_count( $markup, 'type="radio"' ) );
		$this->assertSame( 2, substr_count( $markup, ' checked ' ) );
		$this->assertStringContainsString( '<legend class="seocart-options__legend">Size <span class="seocart-options__current" data-wp-text="state.axisValueLabel">250 g</span></legend>', $markup );
		$this->assertStringContainsString( '<legend class="seocart-options__legend">Grind <span class="seocart-options__current" data-wp-text="state.axisValueLabel">Whole bean</span></legend>', $markup );
		$this->assertStringContainsString( '<input class="seocart-pill__input" type="radio" name="seocart-41-size" value="250g" checked data-wp-on--change="actions.select">', $markup );
		$this->assertStringContainsString( '<label class="seocart-pill" data-wp-context=\'{"value":"1kg"}\' data-wp-class--is-unavailable="state.valueUnavailable"><input class="seocart-pill__input" type="radio" name="seocart-41-size" value="1kg" data-wp-on--change="actions.select"><span class="seocart-pill__swatch" aria-hidden="true" style="background-color:#7a4b2a"></span><span class="seocart-pill__label">1 kg</span>', $markup );
		$this->assertLessThan( strpos( $markup, 'Grind' ), strpos( $markup, 'Size' ), 'The axes are in the product\'s order.' );
	}

	/**
	 * Tests a product of one axis, and a product without options, which has no picker.
	 *
	 * @since 0.2.0
	 */
	public function test_one_axis_has_one_fieldset_and_none_has_no_picker(): void {
		$this->assertSame( 1, substr_count( VariantPicker::markup( self::POST, self::sizes(), array() ), '<fieldset' ) );
		$this->assertSame( '', VariantPicker::markup( self::POST, self::flat(), array() ) );
		$this->assertStringContainsString( '>Add to cart</button>', AddToCartForm::markup( self::POST, self::flat(), StorefrontStrings::all() ) );
	}

	/**
	 * Tests the form: the labelled quantity of at least one between two labelled buttons, the button, the two regions and the sentence for a browser without a script.
	 *
	 * @since 0.2.0
	 */
	public function test_the_form_has_its_quantity_its_button_and_its_two_regions(): void {
		$markup = AddToCartForm::markup( self::POST, self::coffee(), StorefrontStrings::all() );

		$this->assertStringStartsWith( '<form class="seocart-add-to-cart" data-wp-interactive="seocart/product" data-wp-context=\'{"postId":41}\' data-wp-on--submit="actions.add"', $markup );
		$this->assertStringNotContainsString( 'action=', $markup, 'The form posts nowhere.' );
		$this->assertStringContainsString( '<label class="seocart-add-to-cart__label" for="seocart-quantity-41">Quantity</label>', $markup );
		$this->assertStringContainsString( 'id="seocart-quantity-41" type="number" inputmode="numeric" min="1" step="1" value="1"', $markup );
		$this->assertStringContainsString( 'aria-label="Decrease quantity"', $markup );
		$this->assertStringContainsString( 'aria-label="Increase quantity"', $markup );
		$this->assertStringContainsString( '<button class="seocart-button seocart-button--block seocart-button--large" type="submit" aria-disabled="false"', $markup );
		$this->assertStringContainsString( '<p class="seocart-add-to-cart__status" role="status" data-wp-text="state.statusText"></p>', $markup );
		$this->assertStringContainsString( '<p class="seocart-notice seocart-notice--error" role="alert" data-wp-text="state.alertText"></p>', $markup );
		$this->assertStringContainsString( '<noscript><p class="seocart-notice">Adding to the cart needs JavaScript', $markup );
	}

	/**
	 * Tests that a sold-out variant selected first prints the button "Sold out", marked disabled.
	 *
	 * @since 0.2.0
	 */
	public function test_a_sold_out_variant_prints_the_button_sold_out(): void {
		$product = self::sizes();

		$product['variants'][0]['inStock'] = false;

		$this->assertStringContainsString( 'aria-disabled="true" data-wp-bind--aria-disabled="state.cannotAdd" data-wp-bind--aria-busy="state.adding" data-wp-text="state.buttonLabel">Sold out</button>', AddToCartForm::markup( self::POST, $product, StorefrontStrings::all() ) );
	}

	/**
	 * Tests that a product that sells no variant says so, and shows no price, no picker and no form.
	 *
	 * @since 0.2.0
	 */
	public function test_a_product_that_sells_no_variant_is_unavailable(): void {
		$product = self::coffee();

		$product['variants']  = array();
		$product['selection'] = array();

		$this->assertSame( '', ProductPrice::markup( self::POST, $product, StorefrontStrings::all() ) );
		$this->assertSame( '', VariantPicker::markup( self::POST, $product, array() ) );
		$this->assertSame( '<p class="seocart-notice">This product is unavailable right now.</p>', AddToCartForm::markup( self::POST, $product, StorefrontStrings::all() ) );
	}

	/**
	 * Tests that every class a component prints, in a class attribute or a class directive, is in the published list.
	 *
	 * @since 0.2.0
	 */
	public function test_every_class_a_component_prints_is_published(): void {
		$strings = StorefrontStrings::all();
		$markup  = '';

		foreach ( array( self::coffee(), self::sizes(), self::flat() ) as $product ) {
			$markup .= ProductPrice::markup( self::POST, $product, $strings ) . VariantPicker::markup( self::POST, $product, array( 9 => '<img class="seocart-pill__image" alt="">' ) ) . AddToCartForm::markup( self::POST, $product, $strings );
		}

		$markup .= BlockPlaceholder::markup( 'Product price: shown on the page of a product.' );

		preg_match_all( '/class="([^"]*)"|data-wp-class--([a-z0-9_-]+)=/', $markup, $matches );

		$printed = array_values( array_unique( array_filter( preg_split( '/\s+/', implode( ' ', $matches[1] ) . ' ' . implode( ' ', $matches[2] ) ) ) ) );
		$printed = array_values( array_filter( $printed, static fn( string $name ): bool => str_starts_with( $name, 'seocart-' ) || str_starts_with( $name, 'is-' ) ) );

		$this->assertContains( 'is-unavailable', $printed, 'The search did not find the class directive, so a clean result would prove nothing.' );
		$this->assertContains( 'seocart-pill__image', $printed, 'The search did not find the image of a value, so a clean result would prove nothing.' );
		$this->assertSame( array(), array_values( array_diff( $printed, self::published() ) ), 'These classes are printed by a component and missing from docs/styling.md.' );
	}

	/**
	 * Returns the classes docs/styling.md lists.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The classes.
	 */
	private static function published(): array {
		$page    = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/styling.md' );
		$section = (string) strstr( $page, "\n## Classes" );

		preg_match_all( '/^\|\s*`([^`]+)`/m', $section, $matches );

		return $matches[1];
	}

	/**
	 * Returns a coffee of two sizes and three grinds, two of its combinations missing: the 250 g with a compare-at price, the 1 kg filter without a price.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The state.
	 */
	private static function coffee(): array {
		return array(
			'uuid'      => '01937a6e-0000-7000-8000-000000000041',
			'name'      => 'Ethiopia Yirgacheffe',
			'axes'      => array(
				self::axis(
					'size',
					'Size',
					array(
						'250g' => array( '250 g', null, null ),
						'1kg'  => array( '1 kg', '#7a4b2a', null ),
					)
				),
				self::axis(
					'grind',
					'Grind',
					array(
						'whole'    => array( 'Whole bean', null, null ),
						'filter'   => array( 'Filter', null, null ),
						'espresso' => array( 'Espresso', null, null ),
					)
				),
			),
			'variants'  => array(
				self::variant(
					1,
					array(
						'size'  => '250g',
						'grind' => 'whole',
					),
					'250 g / Whole bean',
					1800,
					2400
				),
				self::variant(
					2,
					array(
						'size'  => '250g',
						'grind' => 'filter',
					),
					'250 g / Filter',
					1800,
					null
				),
				self::variant(
					3,
					array(
						'size'  => '1kg',
						'grind' => 'whole',
					),
					'1 kg / Whole bean',
					5800,
					null
				),
				self::variant(
					4,
					array(
						'size'  => '1kg',
						'grind' => 'filter',
					),
					'1 kg / Filter',
					null,
					null
				),
			),
			'selection' => array(
				'size'  => '250g',
				'grind' => 'whole',
			),
			'quantity'  => 1,
		);
	}

	/**
	 * Returns a product of one axis, sizes.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The state.
	 */
	private static function sizes(): array {
		return array(
			'uuid'      => '01937a6e-0000-7000-8000-000000000042',
			'name'      => 'Kenya AA',
			'axes'      => array(
				self::axis(
					'size',
					'Size',
					array(
						'250g' => array( '250 g', null, null ),
						'1kg'  => array( '1 kg', null, 9 ),
					)
				),
			),
			'variants'  => array(
				self::variant( 5, array( 'size' => '250g' ), '250 g', 1600, null ),
				self::variant( 6, array( 'size' => '1kg' ), '1 kg', 5200, null ),
			),
			'selection' => array( 'size' => '250g' ),
			'quantity'  => 1,
		);
	}

	/**
	 * Returns a product without options: one variant, which selects nothing.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The state.
	 */
	private static function flat(): array {
		return array(
			'uuid'      => '01937a6e-0000-7000-8000-000000000043',
			'name'      => 'Paper filters',
			'axes'      => array(),
			'variants'  => array( self::variant( 7, array(), null, 650, null ) ),
			'selection' => array(),
			'quantity'  => 1,
		);
	}

	/**
	 * Returns an axis as the state holds it.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                               $key    The axis's key.
	 * @param string                                               $label  Its label.
	 * @param array<string, array{0: string, 1: ?string, 2: ?int}> $values Each value's label, swatch and image, by key.
	 * @return array<string, mixed> The axis.
	 */
	private static function axis( string $key, string $label, array $values ): array {
		$axis = array(
			'key'    => $key,
			'label'  => $label,
			'values' => array(),
		);

		foreach ( $values as $value => list( $name, $swatch, $image ) ) {
			$axis['values'][] = array(
				'key'    => (string) $value,
				'label'  => $name,
				'swatch' => $swatch,
				'image'  => $image,
			);
		}

		return $axis;
	}

	/**
	 * Returns a variant as the state holds it, in US dollars, in stock.
	 *
	 * @since 0.2.0
	 *
	 * @param int                   $id        Its id.
	 * @param array<string, string> $values    Its value on each axis.
	 * @param string|null           $label     Its label.
	 * @param int|null              $price     Its price in cents, or null for none.
	 * @param int|null              $compareAt Its compare-at price in cents, or null for none.
	 * @return array<string, mixed> The variant.
	 */
	private static function variant( int $id, array $values, ?string $label, ?int $price, ?int $compareAt ): array {
		return array(
			'id'        => $id,
			'values'    => $values,
			'label'     => $label,
			'price'     => null === $price ? null : array(
				'minor' => $price,
				'text'  => '$' . number_format( $price / 100, 2 ),
			),
			'compareAt' => null === $compareAt ? null : array(
				'minor' => $compareAt,
				'text'  => '$' . number_format( $compareAt / 100, 2 ),
			),
			'inStock'   => true,
		);
	}
}

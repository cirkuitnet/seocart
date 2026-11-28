<?php
/**
 * Tests what a page reads of a product: the view ProductPage answers
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Query\ProductPage;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\OptionAxis;
use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;

/**
 * The view of a saved product with two axes: its axes and values in order, the variants it sells in
 * order with their values, labels and prices in the page's currency, a disabled variant left out, a
 * variant with no price there listed without one, and in stock read from one ask of the units for
 * sale; the view kept for the request; no view for a post without a product; and no variant for a
 * product whose generation is not complete, which the sellability rule judges.
 *
 * The units for sale come from a closure the test gives, as the kernel gives the availability's:
 * the test counts the asks and chooses each figure.
 *
 * @since 0.2.0
 */
final class ProductPageTest extends ProductWriteTestCase {

	use SavesVariantSets;

	/**
	 * The variant ids each ask for the units for sale named, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<list<int>>
	 */
	private array $asked = array();

	/**
	 * The units for sale the closure answers, by variant id: null for no limit; a variant not listed has none.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, int|null>
	 */
	private array $figures = array();

	/**
	 * Tests the view of a product with two axes, a disabled variant, a variant without a price in the page's currency and a compare-at price, on a page in euros.
	 *
	 * @since 0.2.0
	 */
	public function test_the_view_holds_the_axes_and_the_enabled_variants_with_prices_and_stock(): void {
		$variants                                     = VariantSets::variants( 'PAGE' );
		$variants[0]['prices'][1]['compare_at_minor'] = 2500;
		$variants[2]['enabled']                       = false;
		$variants[3]['prices']                        = array( $variants[3]['prices'][0] );

		$saved   = $this->saveSet( null, VariantSets::options(), $variants );
		$product = $this->products->findByPost( $saved->postId );
		$ids     = array_map( static fn( $variant ): int => (int) $variant->id(), $product->variants() );

		$this->figures = array(
			$ids[0] => null,
			$ids[1] => 0,
			$ids[3] => 3,
			$ids[4] => 1,
		);

		$view = $this->page( 'EUR' )->of( $saved->postId );

		$this->assertNotNull( $view );
		$this->assertSame( $saved->postId, $view->postId );
		$this->assertSame( $product->uuid(), $view->uuid );
		$this->assertSame( 'EUR', $view->currency->code() );
		$this->assertSame( 7, $view->priceVersion );
		$this->assertSame(
			array(
				'size'  => array( '250g', '1kg' ),
				'grind' => array( 'whole', 'filter', 'espresso' ),
			),
			array_combine(
				array_map( static fn( OptionAxis $axis ): string => $axis->key, $view->axes ),
				array_map( static fn( OptionAxis $axis ): array => array_map( static fn( OptionValue $value ): string => $value->key, $axis->values ), $view->axes )
			)
		);
		$this->assertSame( array( array( $ids[0], $ids[1], $ids[3], $ids[4], $ids[5] ) ), $this->asked, 'The units for sale are asked once, for the enabled variants.' );
		$this->assertSame( array( $ids[0], $ids[1], $ids[3], $ids[4], $ids[5] ), array_column( $view->variants, 'id' ), 'The enabled variants, in their order.' );
		$this->assertSame( array( true, false, true, true, false ), array_column( $view->variants, 'inStock' ), 'No limit and a figure above zero are in stock; zero and no figure are not.' );
		$this->assertSame(
			array(
				'grind' => 'whole',
				'size'  => '250g',
			),
			$view->variants[0]['values'],
			'The value on each axis, by axis key.'
		);
		$this->assertSame( '250 g / Whole bean', $view->variants[0]['label'] );
		$this->assertTrue( Money::of( 1100, Currency::of( 'EUR' ) )->equals( $view->variants[0]['price'] ), 'The price is the one entered in the currency of the page.' );
		$this->assertTrue( Money::of( 2500, Currency::of( 'EUR' ) )->equals( $view->variants[0]['compareAt'] ) );
		$this->assertNull( $view->variants[1]['compareAt'] );
		$this->assertNull( $view->variants[2]['price'], 'A variant with no price in the page\'s currency is listed without one, never at zero.' );
		$this->assertNull( $view->variants[2]['compareAt'] );
		$this->assertSame( $ids[0], $view->defaultVariant()['id'] ?? null, 'The first variant in stock with a price is shown first.' );
	}

	/**
	 * Tests that the first variant is shown first when none is in stock with a price.
	 *
	 * @since 0.2.0
	 */
	public function test_the_first_variant_is_shown_first_when_none_can_be_added(): void {
		$saved = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'NONE' ) );
		$view  = $this->page()->of( $saved->postId );

		$this->assertNotNull( $view );
		$this->assertSame( $view->variants[0]['id'], $view->defaultVariant()['id'] ?? null );
	}

	/**
	 * Tests that a view is read once a request: the blocks of one product cost one read.
	 *
	 * @since 0.2.0
	 */
	public function test_a_view_is_read_once_a_request(): void {
		$saved = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'ONCE' ) );
		$page  = $this->page();
		$first = $page->of( $saved->postId );

		$again = $this->captureQueries( static fn() => $page->of( $saved->postId ) );

		$this->assertSame( 0, $again->count(), 'The second ask of the view sent a statement: ' . $again->describe() );
		$this->assertSame( $first, $page->of( $saved->postId ) );
		$this->assertCount( 1, $this->asked );
	}

	/**
	 * Tests that a post no product is bound to has no view, and that it asks for no units.
	 *
	 * @since 0.2.0
	 */
	public function test_a_post_without_a_product_has_no_view(): void {
		$this->assertNull( $this->page()->of( $this->unboundPost() ) );
		$this->assertSame( array(), $this->asked );
	}

	/**
	 * Tests that a product whose generation is not complete sells nothing on its page, and asks for no units.
	 *
	 * @since 0.2.0
	 */
	public function test_a_product_whose_generation_is_not_complete_has_no_variant(): void {
		$product = $this->boundProduct( 'PAGE-INCOMPLETE', null );

		$this->products->save( $product );
		$this->products->leaveUpdating( (int) $product->id(), GenerationState::Incomplete );

		$view = $this->page()->of( (int) $product->sourcePostId() );

		$this->assertNotNull( $view );
		$this->assertSame( array(), $view->variants );
		$this->assertNull( $view->defaultVariant() );
		$this->assertSame( array(), $this->asked );
	}

	/**
	 * Returns the query, over the test's repository, the test's units for sale, a page currency and the exchange-rate version 7.
	 *
	 * @since 0.2.0
	 *
	 * @param string $currency Optional. The page's currency. Default the base currency, US dollars.
	 * @return ProductPage The query.
	 */
	private function page( string $currency = 'USD' ): ProductPage {
		return new ProductPage(
			$this->products,
			new Sellability( $this->products ),
			function ( array $variantIds ): array {
				$this->asked[] = $variantIds;

				return array_intersect_key( $this->figures, array_flip( $variantIds ) );
			},
			static fn(): Currency => Currency::of( $currency ),
			static fn(): int => 7
		);
	}
}

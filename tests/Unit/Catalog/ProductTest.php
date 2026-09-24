<?php
/**
 * Tests Product: how a product begins, the one rule that settles its marker, and the events it records
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Event\ProductDeleted;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

/**
 * A first binding starts `updating` with one variant at the first generation; a reconciled post
 * starts `incomplete` without one; settle() says `complete` only for a product that is bound,
 * has its variant, a base price and a stock item; a price in another currency is refused.
 *
 * Planted violation for settle(): drop `&& $stockItemKnown` from Product::settle(). The truth
 * table's row without a stock item then settles `complete`, and
 * test_settle_is_complete_only_when_everything_a_sale_needs_exists fails.
 *
 * @since 0.1.0
 */
final class ProductTest extends TestCase {

	/**
	 * The instant the tests bind and save at.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const AT = '2026-09-25 10:00:00';

	/**
	 * Tests that a first binding builds one variant at the first generation, published, and starts `updating`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_first_binding_starts_updating_with_its_default_variant_published(): void {
		$product = $this->firstBinding();
		$variant = $product->defaultVariant();

		$this->assertNull( $product->id() );
		$this->assertSame( GenerationState::Updating, $product->generation() );
		$this->assertSame( 42, $product->sourcePostId() );
		$this->assertCount( 1, $product->bindings() );
		$this->assertSame( 42, $product->bindings()[0]->postId() );
		$this->assertSame( 'de_DE', $product->bindings()[0]->locale()->toString() );
		$this->assertNotNull( $variant );
		$this->assertSame( Variant::FIRST_GENERATION, $variant->generation() );
		$this->assertSame( Variant::FIRST_GENERATION, $product->activeGeneration() );
		$this->assertSame( hash( 'sha256', '' ), $variant->combinationHash() );
		$this->assertTrue( $variant->isEnabled() );
		$this->assertNull( $variant->basePrice() );
		$this->assertSame( 1, $product->variantCount() );
		$this->assertSame( 1, $product->enabledVariantCount() );
	}

	/**
	 * Tests that a product reconciled from a post it did not write is `incomplete`, bound, without a variant and publishing nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_reconciled_product_is_incomplete_without_a_variant(): void {
		$product = Product::reconciled( SequentialIdGenerator::nth( 1 ), 7, Locale::of( 'en_US' ), self::instant() );

		$this->assertSame( GenerationState::Incomplete, $product->generation() );
		$this->assertSame( 7, $product->sourcePostId() );
		$this->assertNull( $product->defaultVariant() );
		$this->assertSame( Product::NO_GENERATION, $product->activeGeneration() );
		$this->assertSame( 0, $product->variantCount() );
		$this->assertSame( GenerationState::Incomplete, $product->settle( true ), 'Without a variant a product cannot be whole.' );
	}

	/**
	 * Tests settle() against every combination of what a sale needs.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_settle
	 *
	 * @param bool            $bound    Whether the source post is among the bindings.
	 * @param bool            $priced   Whether the variant has a base price.
	 * @param bool            $stocked  Whether the stock item exists.
	 * @param GenerationState $expected The marker settle() decides.
	 */
	public function test_settle_is_complete_only_when_everything_a_sale_needs_exists( bool $bound, bool $priced, bool $stocked, GenerationState $expected ): void {
		$usd     = Currency::of( 'USD' );
		$variant = Variant::byDefault( SequentialIdGenerator::nth( 2 ), Sku::of( 'A-1' ) );
		$binding = new ProductPostBinding( $bound ? 42 : 43, Locale::of( 'en_US' ), self::instant() );
		$product = Product::stored( 5, SequentialIdGenerator::nth( 1 ), 42, array( $binding ), GenerationState::Updating, 1, $variant->withId( 9 ) );

		$product->applyCommerce( Sku::of( 'A-1' ), $priced ? VariantPrice::net( $usd, 1000 ) : null, null, $usd );

		$this->assertSame( $expected, $product->settle( $stocked ) );
		$this->assertSame( $expected, $product->generation(), 'settle() records the marker it decides.' );
	}

	/**
	 * Provides every combination of binding, price and stock item.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{bool, bool, bool, GenerationState}> Test cases.
	 */
	public static function data_settle(): array {
		return array(
			'bound, priced, stocked'  => array( true, true, true, GenerationState::Complete ),
			'no stock item'           => array( true, true, false, GenerationState::Incomplete ),
			'no base price'           => array( true, false, true, GenerationState::Incomplete ),
			'not bound to its source' => array( false, true, true, GenerationState::Incomplete ),
			'nothing'                 => array( false, false, false, GenerationState::Incomplete ),
		);
	}

	/**
	 * Tests that commerce data lands on the default variant, and a price in another currency is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_commerce_data_lands_on_the_default_variant_in_the_base_currency_only(): void {
		$usd     = Currency::of( 'USD' );
		$product = $this->firstBinding();

		$product->applyCommerce( Sku::of( 'B-2' ), VariantPrice::net( $usd, 2500, 3000 ), 125, $usd );

		$variant = $product->defaultVariant();

		$this->assertNotNull( $variant );

		$price = $variant->basePrice();

		$this->assertNotNull( $price );
		$this->assertSame( 'B-2', $variant->sku()->toString() );
		$this->assertSame( 2500, $price->priceMinor() );
		$this->assertSame( 3000, $price->compareAtMinor() );
		$this->assertSame( VariantPrice::NET, $price->amountBasis() );
		$this->assertSame( 125, $variant->weightGrams() );

		try {
			$product->applyCommerce( Sku::of( 'B-2' ), VariantPrice::net( Currency::of( 'EUR' ), 2300 ), 125, $usd );
			$this->fail( 'A price in another currency was accepted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CatalogError::CurrencyNotBase, $refused->errorCode() );
			$this->assertSame(
				array(
					'currency'      => 'EUR',
					'base_currency' => 'USD',
				),
				$refused->context()
			);
		}

		$this->assertSame( 2500, $product->defaultVariant()?->basePrice()?->priceMinor(), 'The refused price changed nothing.' );
	}

	/**
	 * Tests that identify() gives the product and its variant their ids, once.
	 *
	 * @since 0.1.0
	 */
	public function test_identify_records_the_stored_ids_once(): void {
		$product = $this->firstBinding();

		$product->identify( 11, 12 );
		$product->identify( 11, 12 );

		$this->assertSame( 11, $product->id() );
		$this->assertSame( 12, $product->defaultVariant()?->id() );

		$this->expectException( \LogicException::class );
		$product->identify( 13, 12 );
	}

	/**
	 * Tests that saving and deleting are recorded as events, released once.
	 *
	 * @since 0.1.0
	 */
	public function test_saving_and_deleting_are_recorded_as_events(): void {
		$usd     = Currency::of( 'USD' );
		$product = $this->firstBinding();

		$product->applyCommerce( Sku::of( 'C-3' ), VariantPrice::net( $usd, 999 ), null, $usd );
		$product->identify( 21, 22 );
		$product->markSaved( array( 'sku', 'price_minor' ), self::instant() );
		$product->markDeleted( self::instant() );

		$events = $product->releaseEvents();

		$this->assertSame( array(), $product->releaseEvents(), 'Events are released once.' );
		$this->assertCount( 2, $events );
		$this->assertInstanceOf( ProductSaved::class, $events[0] );
		$this->assertInstanceOf( ProductDeleted::class, $events[1] );
		$this->assertSame(
			array(
				'product_id'     => 21,
				'post_id'        => 42,
				'changed_fields' => array( 'sku', 'price_minor' ),
				'sku'            => 'C-3',
				'price_minor'    => 999,
				'currency'       => 'USD',
			),
			$events[0]->toPayload()
		);
		$this->assertSame(
			array(
				'product_id' => 21,
				'post_id'    => 42,
				'skus'       => array( 'C-3' ),
			),
			$events[1]->toPayload()
		);
	}

	/**
	 * Tests that no event can be recorded for a product that is not stored.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unstored_product_records_no_event(): void {
		$this->expectException( \LogicException::class );

		$this->firstBinding()->markSaved( array(), self::instant() );
	}

	/**
	 * Returns a product bound for the first time to post 42, in German.
	 *
	 * @since 0.1.0
	 *
	 * @return Product The product.
	 */
	private function firstBinding(): Product {
		return Product::firstBinding(
			SequentialIdGenerator::nth( 1 ),
			42,
			Locale::of( 'de_DE' ),
			Variant::byDefault( SequentialIdGenerator::nth( 2 ), Sku::of( 'A-1' ) ),
			self::instant()
		);
	}

	/**
	 * Returns the instant the tests bind and save at.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The instant, UTC.
	 */
	private static function instant(): \DateTimeImmutable {
		return new \DateTimeImmutable( self::AT, new \DateTimeZone( 'UTC' ) );
	}
}

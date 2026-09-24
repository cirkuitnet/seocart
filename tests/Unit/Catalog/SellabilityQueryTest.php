<?php
/**
 * Tests Query\Sellability: every verdict comes from the one fetch, judged by the one rule
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\FixedFactsRepository;

/**
 * The query asks the repository once for every variant's facts, answers in the order asked with
 * each id once, and answers `unknown_variant` for an id with no facts; with no id it asks nothing.
 *
 * @since 0.1.0
 */
final class SellabilityQueryTest extends TestCase {

	/**
	 * Tests that the verdicts come from one fetch, in the order asked, each id once.
	 *
	 * @since 0.1.0
	 */
	public function test_the_verdicts_come_from_one_fetch(): void {
		$repository = new FixedFactsRepository(
			new SellabilityFacts( 7, 3, GenerationState::Complete, 1, 1, true, 42, 42, 'publish', true ),
			new SellabilityFacts( 8, 3, GenerationState::Updating, 1, 1, true, 42, 42, 'publish', true ),
			new SellabilityFacts( 9, 4, GenerationState::Complete, 1, 1, true, 43, 43, 'private', true )
		);

		$verdicts = ( new Sellability( $repository ) )->of( array( 9, 7, 8, 404, 7 ), false );

		$this->assertSame( array( array( 9, 7, 8, 404 ) ), $repository->asked, 'One fetch, each id once.' );
		$this->assertSame(
			array(
				9   => SellabilityReason::PrivateProduct,
				7   => SellabilityReason::Sellable,
				8   => SellabilityReason::Updating,
				404 => SellabilityReason::UnknownVariant,
			),
			$verdicts
		);
		$this->assertSame( SellabilityReason::Sellable, ( new Sellability( $repository ) )->of( array( 9 ), true )[9], 'A reader of private products may buy a private one.' );
	}

	/**
	 * Tests that no id asks nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_no_id_asks_nothing(): void {
		$repository = new FixedFactsRepository();

		$this->assertSame( array(), ( new Sellability( $repository ) )->of( array(), false ) );
		$this->assertSame( array(), $repository->asked );
	}

	/**
	 * Tests that a post with no variant to judge gets the rule's verdict without a fetch.
	 *
	 * @since 0.1.0
	 */
	public function test_a_post_with_no_variant_to_judge_asks_nothing(): void {
		$repository = new FixedFactsRepository();
		$query      = new Sellability( $repository );

		$this->assertSame( SellabilityReason::NoBinding, $query->ofProduct( null, false ) );
		$this->assertSame( SellabilityReason::Incomplete, $query->ofProduct( self::product( GenerationState::Incomplete, null ), false ) );
		$this->assertSame( SellabilityReason::Updating, $query->ofProduct( self::product( GenerationState::Updating, null ), false ) );
		$this->assertSame( array(), $repository->asked );
	}

	/**
	 * Tests that a product's default variant is judged from the one fetch.
	 *
	 * @since 0.1.0
	 */
	public function test_a_products_default_variant_is_judged_from_the_fetch(): void {
		$repository = new FixedFactsRepository( new SellabilityFacts( 70, 7, GenerationState::Complete, 1, 1, true, 42, 42, 'publish', true ) );

		$this->assertSame( SellabilityReason::Sellable, ( new Sellability( $repository ) )->ofProduct( self::product( GenerationState::Complete, 70 ), false ) );
		$this->assertSame( array( array( 70 ) ), $repository->asked );
	}

	/**
	 * Builds a stored product bound to post 42.
	 *
	 * @since 0.1.0
	 *
	 * @param GenerationState $generation The marker.
	 * @param int|null        $variantId  The default variant's id, or null for none.
	 * @return Product The product.
	 */
	private static function product( GenerationState $generation, ?int $variantId ): Product {
		return Product::stored(
			7,
			'00000000-0000-4000-8000-000000000007',
			42,
			array( new ProductPostBinding( 42, Locale::of( 'en_US' ), new \DateTimeImmutable( '2026-09-01 00:00:00' ) ) ),
			$generation,
			null === $variantId ? 0 : 1,
			null === $variantId ? null : Variant::stored( $variantId, '00000000-0000-4000-8000-000000000070', Sku::of( 'SKU-1' ), Variant::defaultCombinationHash(), 1, true, null, null )
		);
	}
}

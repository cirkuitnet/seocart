<?php
/**
 * Tests Sellability: every verdict, from planted facts, in the order the rule checks them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Sellability;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Catalog\Domain\SellabilityReason;

/**
 * The rule returns the first verdict a variant fails, in a fixed order, and `sellable` only when
 * it fails none.
 *
 * The order is proven by starting from facts that fail every rule and repairing one fact at a
 * time: the verdicts must come out as unknown variant, incomplete, updating, no binding, no base
 * price, not the active generation, disabled, not published, private, sellable, and every case of
 * SellabilityReason must appear.
 *
 * Planted violation: remove the `GenerationState::Updating` comparison from
 * Sellability::verdict(). The updating product is then judged on its other facts and comes out
 * `sellable`, and both tests fail.
 *
 * @since 0.1.0
 */
final class SellabilityTest extends TestCase {

	/**
	 * Tests that repairing one fact at a time walks through every verdict, in the rule's order.
	 *
	 * @since 0.1.0
	 */
	public function test_every_verdict_comes_out_in_the_rules_order(): void {
		$facts = array(
			'generation'        => GenerationState::Incomplete,
			'activeGeneration'  => 1,
			'variantGeneration' => 2,
			'variantEnabled'    => false,
			'sourcePostId'      => null,
			'boundPostId'       => null,
			'postStatus'        => null,
			'hasBasePrice'      => false,
		);

		$repairs = array(
			array(),
			array( 'generation' => GenerationState::Updating ),
			array( 'generation' => GenerationState::Complete ),
			array(
				'sourcePostId' => 42,
				'boundPostId'  => 42,
				'postStatus'   => 'draft',
			),
			array( 'hasBasePrice' => true ),
			array( 'variantGeneration' => 1 ),
			array( 'variantEnabled' => true ),
			array( 'postStatus' => 'private' ),
			array( 'postStatus' => 'publish' ),
		);

		$verdicts = array( Sellability::verdict( null, false )->value );

		foreach ( $repairs as $repair ) {
			$facts      = array_merge( $facts, $repair );
			$verdicts[] = Sellability::verdict( self::facts( $facts ), false )->value;
		}

		$this->assertSame(
			array( 'unknown_variant', 'incomplete', 'updating', 'no_binding', 'no_base_price', 'not_active_generation', 'variant_disabled', 'not_published', 'private', 'sellable' ),
			$verdicts
		);
		$this->assertEqualsCanonicalizing( array_map( static fn( SellabilityReason $reason ): string => $reason->value, SellabilityReason::cases() ), $verdicts, 'Every verdict is reachable.' );
	}

	/**
	 * Tests single facts against a sellable variant: each one alone decides the verdict.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_single_faults
	 *
	 * @param array<string, mixed> $fault          The fact that is wrong.
	 * @param bool                 $canReadPrivate Whether the reader may read private products.
	 * @param string               $expected       The verdict.
	 */
	public function test_one_wrong_fact_decides_the_verdict( array $fault, bool $canReadPrivate, string $expected ): void {
		$facts = array_merge(
			array(
				'generation'        => GenerationState::Complete,
				'activeGeneration'  => 1,
				'variantGeneration' => 1,
				'variantEnabled'    => true,
				'sourcePostId'      => 42,
				'boundPostId'       => 42,
				'postStatus'        => 'publish',
				'hasBasePrice'      => true,
			),
			$fault
		);

		$this->assertSame( $expected, Sellability::verdict( self::facts( $facts ), $canReadPrivate )->value );
	}

	/**
	 * Provides single wrong facts with the verdict each decides.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, mixed>, bool, string}> Test cases.
	 */
	public static function data_single_faults(): array {
		return array(
			'nothing wrong'                       => array( array(), false, 'sellable' ),
			'incomplete'                          => array( array( 'generation' => GenerationState::Incomplete ), false, 'incomplete' ),
			'updating'                            => array( array( 'generation' => GenerationState::Updating ), true, 'updating' ),
			'updating, even as a draft'           => array(
				array(
					'generation' => GenerationState::Updating,
					'postStatus' => 'draft',
				),
				true,
				'updating',
			),
			'no source post'                      => array( array( 'sourcePostId' => null ), false, 'no_binding' ),
			'no binding row'                      => array( array( 'boundPostId' => null ), false, 'no_binding' ),
			'the source post is not a product'    => array( array( 'postStatus' => null ), false, 'no_binding' ),
			'no base price'                       => array( array( 'hasBasePrice' => false ), false, 'no_base_price' ),
			'an older generation'                 => array( array( 'variantGeneration' => 2 ), false, 'not_active_generation' ),
			'no generation published'             => array( array( 'activeGeneration' => 0 ), false, 'not_active_generation' ),
			'disabled'                            => array( array( 'variantEnabled' => false ), false, 'variant_disabled' ),
			'a draft'                             => array( array( 'postStatus' => 'draft' ), false, 'not_published' ),
			'pending'                             => array( array( 'postStatus' => 'pending' ), false, 'not_published' ),
			'scheduled'                           => array( array( 'postStatus' => 'future' ), false, 'not_published' ),
			'in the trash'                        => array( array( 'postStatus' => 'trash' ), false, 'not_published' ),
			'an auto-draft'                       => array( array( 'postStatus' => 'auto-draft' ), false, 'not_published' ),
			'private, to a reader without access' => array( array( 'postStatus' => 'private' ), false, 'private' ),
			'private, to a reader with access'    => array( array( 'postStatus' => 'private' ), true, 'sellable' ),
		);
	}

	/**
	 * Builds facts for variant 7 of product 3.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $facts The facts, by name.
	 * @return SellabilityFacts The facts.
	 */
	private static function facts( array $facts ): SellabilityFacts {
		return new SellabilityFacts( 7, 3, $facts['generation'], $facts['activeGeneration'], $facts['variantGeneration'], $facts['variantEnabled'], $facts['sourcePostId'], $facts['boundPostId'], $facts['postStatus'], $facts['hasBasePrice'] );
	}

	/**
	 * Tests the verdict on a post with no variant to judge: never sellable, and in the rule's order.
	 *
	 * @since 0.1.0
	 */
	public function test_a_post_without_a_variant_is_never_sellable(): void {
		$this->assertSame( SellabilityReason::NoBinding, Sellability::withoutVariant( null ), 'A post no product is bound to has no binding.' );
		$this->assertSame( SellabilityReason::Incomplete, Sellability::withoutVariant( GenerationState::Incomplete ) );
		$this->assertSame( SellabilityReason::Updating, Sellability::withoutVariant( GenerationState::Updating ), 'A product being saved is reported as such.' );
		$this->assertSame( SellabilityReason::Incomplete, Sellability::withoutVariant( GenerationState::Complete ), 'A product without a variant is not whole, whatever its marker says.' );
	}
}

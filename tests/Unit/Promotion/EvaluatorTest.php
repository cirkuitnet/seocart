<?php
/**
 * Tests the promotion module's evaluator: one intent per promotion, in the order promotions apply
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Promotion;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\Intent\DiscountLines;
use SEOCart\Pricing\Domain\Intent\DiscountOrder;
use SEOCart\Pricing\Domain\Intent\FreeShipping;
use SEOCart\Pricing\Domain\Intent\PromotionIntent;
use SEOCart\Pricing\Domain\PhaseAView;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Promotion\Domain\Evaluator;
use SEOCart\Support\Percentage;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Each kind of promotion becomes its intent, named by the promotion; promotions apply lowest priority first, then in the order of their codes; the evaluator holds nothing and reaches nothing but the calculation's domain.
 *
 * Planted violations, each shown red and removed:
 *
 * - in Evaluator::evaluate(), sort by priority the other way round: the free-shipping promotion
 *   no longer comes last, and the order test fails;
 * - give Evaluator a constructor that takes a TaxQuoter: the structural test names the parameter
 *   and the import, and PromotionsNeverReachAProviderTest names the quoter.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class EvaluatorTest extends TestCase {

	/**
	 * Tests that each promotion becomes its intent, with its source, lowest priority first and in code order within a priority.
	 *
	 * @since 0.1.0
	 */
	public function test_each_promotion_becomes_its_intent_in_the_order_promotions_apply(): void {
		$ten     = Percentage::fromString( '10' );
		$five    = Inputs::amount( '5.00', AmountBasis::Gross );
		$view    = new PhaseAView(
			array(
				new PromotionFacts( 3, '00000000-0000-7000-8000-000000000003', 'SHIP', PromotionEffect::freeShipping(), 30 ),
				new PromotionFacts( 1, '00000000-0000-7000-8000-000000000001', 'TEN', PromotionEffect::percent( $ten ), 10 ),
				new PromotionFacts( 2, '00000000-0000-7000-8000-000000000002', 'FIVE', PromotionEffect::fixed( $five ), 10 ),
			),
			array()
		);
		$intents = ( new Evaluator() )->evaluate( $view );

		$this->assertSame(
			array(
				'discount_lines:promotion:00000000-0000-7000-8000-000000000001',
				'discount_order:promotion:00000000-0000-7000-8000-000000000002',
				'free_shipping:promotion:00000000-0000-7000-8000-000000000003',
			),
			array_map( static fn( PromotionIntent $intent ): string => $intent->type() . ':' . $intent->source()->toString(), $intents ),
			'Priority 10 before 30, and within priority 10 the order the codes were applied in.'
		);
		$this->assertInstanceOf( DiscountLines::class, $intents[0] );
		$this->assertTrue( $ten->equals( $intents[0]->percentage ) );
		$this->assertInstanceOf( DiscountOrder::class, $intents[1] );
		$this->assertSame( $five, $intents[1]->amount );
		$this->assertInstanceOf( FreeShipping::class, $intents[2] );
	}

	/**
	 * Tests that no promotion asks for nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_no_promotion_asks_for_nothing(): void {
		$this->assertSame( array(), ( new Evaluator() )->evaluate( new PhaseAView( array(), array() ) ) );
	}

	/**
	 * Tests that the evaluator takes nothing when it is made, keeps no state, and names nothing outside the calculation's domain.
	 *
	 * @since 0.1.0
	 */
	public function test_the_evaluator_holds_nothing_and_names_only_the_calculations_domain(): void {
		$class = new \ReflectionClass( Evaluator::class );

		$this->assertNull( $class->getConstructor(), 'The evaluator is made with nothing: it holds no service.' );
		$this->assertSame( array(), $class->getProperties(), 'The evaluator keeps no state between calls.' );

		$outside = array();

		foreach ( PhpSource::classNames( (string) file_get_contents( PhpSource::root() . '/src/Promotion/Domain/Evaluator.php' ) ) as $name ) {
			if ( str_contains( $name['name'], '\\' ) && ! str_starts_with( $name['name'], 'SEOCart\\Pricing\\Domain\\' ) ) {
				$outside[] = $name['name'] . ' (line ' . $name['line'] . ')';
			}
		}

		$this->assertSame( array(), $outside, 'The evaluator names only the calculation\'s own domain: the port, the view, the facts and the intents.' );
	}
}

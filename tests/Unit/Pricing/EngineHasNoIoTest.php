<?php
/**
 * Tests that the engine performs no I/O: it holds nothing, and a port it must not call is never called from inside it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Application\CalculationRequest;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Application\ShippingQuoteRequest;
use SEOCart\Pricing\Domain\Engine\BaseEquivalentStep;
use SEOCart\Pricing\Domain\Engine\DiscountStep;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\Engine\FeeStep;
use SEOCart\Pricing\Domain\Engine\IntentEvaluation;
use SEOCart\Pricing\Domain\Engine\LineResolution;
use SEOCart\Pricing\Domain\Engine\Rounder;
use SEOCart\Pricing\Domain\Engine\ShippingStep;
use SEOCart\Pricing\Domain\Engine\TaxStep;
use SEOCart\Pricing\Domain\Engine\TotalsAssembly;
use SEOCart\Pricing\Domain\Intent\DiscountLines;
use SEOCart\Pricing\Domain\Intent\FreeShipping;
use SEOCart\Pricing\Domain\PhaseAView;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Percentage;
use SEOCart\Tests\Support\Catalog\FixedFactsRepository;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\PoisonedQuoters;
use SEOCart\Tests\Support\Pricing\Calculators;
use SEOCart\Tests\Support\Pricing\CartB;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Proves the engine's purity two ways: at run time, with ports that fail when the engine calls them, and in its structure.
 *
 * Planted violations, each shown red and removed:
 *
 * - in TaxStep::apply(), call a tax quoter reached from inside the step: the poisoned quoter
 *   throws, and the reference cart cannot be calculated;
 * - give Engine a constructor that takes a Clock: the structural test names the parameter.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class EngineHasNoIoTest extends TestCase {

	/**
	 * The engine and its steps: none may hold a service or any state between calls.
	 *
	 * @since 0.1.0
	 *
	 * @var list<class-string>
	 */
	private const STATELESS = array(
		Engine::class,
		LineResolution::class,
		IntentEvaluation::class,
		DiscountStep::class,
		ShippingStep::class,
		FeeStep::class,
		TaxStep::class,
		BaseEquivalentStep::class,
		TotalsAssembly::class,
		Rounder::class,
	);

	/**
	 * Tests that the calculator prices the reference cart through ports that refuse to be called from the engine.
	 *
	 * @since 0.1.0
	 */
	public function test_the_reference_cart_is_priced_with_ports_the_engine_must_not_call(): void {
		$prices = new FixedFactsRepository();
		$lines  = array();

		foreach ( CartB::LINES as $line ) {
			$prices->prices[] = Calculators::price( $line[1], $line[3] );
			$lines[]          = new LineRequest( $line[0], $line[1], $line[2] );
		}

		$quoters     = new PoisonedQuoters(
			array( Inputs::shippingRate( 'express', '14.95' ), Inputs::shippingRate( 'ground', '6.95' ) ),
			Inputs::taxQuote( array( 'standard' => array( Inputs::rate( '8.25' ) ) ) ),
			array( new DiscountLines( Source::promotion( 'code' ), Percentage::fromString( '15' ) ), new FreeShipping( Source::promotion( 'ship' ) ) )
		);
		$calculation = Calculators::over( $prices, $quoters, new FakeTransactionManager() )->calculate( new CalculationRequest( Currency::of( 'USD' ), $lines, new Address( 'US' ) ) );

		$this->assertCount( 10, $calculation->totals->lines );
		$this->assertCount( 12, $calculation->totals->adjustments );
		$this->assertSame( array( 1, 1, 1 ), array( $quoters->shippingCalls, $quoters->taxCalls, $quoters->evaluations ), 'Each port once per calculation.' );
		$this->assertSame( array( 10 ), $quoters->shippedLineCounts, 'Shipping is quoted for the lines the first phase resolved.' );
		$this->assertSame( array( array( 'standard' ) ), $quoters->taxClassesAsked, 'Tax is quoted for every class the lines and the rates name, each once.' );
		$this->assertCount( 1, $prices->askedPrices, 'One read for all ten lines.' );
	}

	/**
	 * Tests the poison itself: a quoter called from inside the engine throws.
	 *
	 * @since 0.1.0
	 */
	public function test_a_quoter_called_from_inside_the_engine_throws(): void {
		$quoters  = new PoisonedQuoters( array(), Inputs::taxQuote( array() ) );
		$sneaking = new class( $quoters ) implements PromotionEvaluator {

			/**
			 * Holds the quoters.
			 *
			 * @since 0.1.0
			 *
			 * @param PoisonedQuoters $quoters The quoters.
			 */
			public function __construct( private PoisonedQuoters $quoters ) {
			}

			/**
			 * Reaches for a quote from inside the engine.
			 *
			 * @since 0.1.0
			 *
			 * @param PhaseAView $view The lines, which it asks a quote for.
			 * @return list<\SEOCart\Pricing\Domain\Intent\PromotionIntent> Never returns.
			 */
			public function evaluate( PhaseAView $view ): array {
				$this->quoters->quoteShipping( new ShippingQuoteRequest( $view->lines, null, Currency::of( 'USD' ), ConversionContext::identity( Currency::of( 'USD' ) ), new \DateTimeImmutable( Inputs::AT ) ) );

				return array();
			}
		};

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'inside the engine' );

		( new Engine() )->phaseA( Inputs::input( array( Inputs::line( 'a', '1.00' ) ) ), $sneaking );
	}

	/**
	 * Tests that the engine and its steps take nothing when they are made, and that no engine class keeps static state.
	 *
	 * @since 0.1.0
	 */
	public function test_the_engine_and_its_steps_hold_nothing(): void {
		$holding = array();

		foreach ( self::STATELESS as $class ) {
			$constructor = ( new \ReflectionClass( $class ) )->getConstructor();

			if ( null !== $constructor && 0 !== $constructor->getNumberOfParameters() ) {
				$holding[] = $class . ' takes ' . implode( ', ', array_map( static fn( \ReflectionParameter $parameter ): string => '$' . $parameter->getName(), $constructor->getParameters() ) );
			}
		}

		foreach ( array_keys( PhpSource::files( 'src/Pricing/Domain/Engine' ) ) as $file ) {
			$class = 'SEOCart\\Pricing\\Domain\\Engine\\' . basename( $file, '.php' );

			foreach ( ( new \ReflectionClass( $class ) )->getProperties( \ReflectionProperty::IS_STATIC ) as $property ) {
				$holding[] = $class . '::$' . $property->getName() . ' is static';
			}
		}

		$this->assertSame( array(), $holding, 'The engine is a function of its input: it holds no service and no state.' );
	}
}

<?php
/**
 * Tests the first phase of the calculation: line amounts, and the bound on promotions that add lines
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\Engine\ResolvedLine;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\Intent\DiscountLines;
use SEOCart\Pricing\Domain\NoPromotions;
use SEOCart\Pricing\Domain\PriceSource;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\ArithmeticOverflowException;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\MoneyAssertions;
use SEOCart\Tests\Support\Pricing\AddingEvaluator;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Proves that a line's amount is its unit price times its quantity, exactly, and that promotions are asked at most twice.
 *
 * Planted violation, shown red and removed: in IntentEvaluation::evaluate(), ask again while the
 * intents hold an add-a-line intent, up to three times. The evaluator is then asked three times,
 * a second gift line appears, and the re-entry test fails.
 *
 * @since 0.1.0
 */
final class PhaseATest extends TestCase {

	use MoneyAssertions;

	/**
	 * Tests that a line's amount is exact, whatever the quantity.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_amount_is_the_unit_price_times_the_quantity(): void {
		$result = ( new Engine() )->phaseA( Inputs::input( array( Inputs::line( 'a', '19.99', 3 ), Inputs::line( 'b', '0.01', 1000 ) ) ), new NoPromotions() );

		$this->assertSame( array( 5997, 1000 ), array_map( static fn( ResolvedLine $line ): int => $line->lineAmount->amount->minorUnits(), $result->lines ) );
		$this->assertSame( array(), $result->intents );
	}

	/**
	 * Tests that a line whose amount does not fit an integer throws rather than turning into a float.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_amount_too_large_for_an_integer_throws(): void {
		$line = new InputLine( 'a', 1, 3, new AuthoredAmount( Money::of( PHP_INT_MAX, Currency::of( 'USD' ) ), AmountBasis::Net ), PriceSource::Explicit, 'standard' );

		$this->expectException( ArithmeticOverflowException::class );

		( new Engine() )->phaseA( Inputs::input( array( $line ) ), new NoPromotions() );
	}

	/**
	 * Tests the re-entry bound: two evaluations, one added line, the second pass's added line dropped and traced.
	 *
	 * @since 0.1.0
	 */
	public function test_promotions_are_asked_twice_at_most_and_an_added_line_adds_nothing(): void {
		$evaluator = new AddingEvaluator();
		$result    = ( new Engine() )->phaseA( Inputs::input( array( Inputs::line( 'a', '10.00' ) ) ), $evaluator );

		$this->assertSame( 2, $evaluator->calls, 'Promotions are evaluated at most twice.' );
		$this->assertSame( array( 1, 2 ), $evaluator->linesSeen, 'The second evaluation sees the added line.' );
		$this->assertCount( 2, $result->lines, 'The second evaluation added no line.' );

		$added = $result->lines[1];

		$this->assertSame( 'auto:promotion:gift:1', $added->line->key );
		$this->assertSame( 900, $added->line->variantId );
		$this->assertTrue( $added->line->autoAdded );
		$this->assertSame( PriceSource::AutoAdded, $added->line->priceSource );
		$this->assertMoneyEquals( Inputs::money( '1.00' ), $added->lineAmount->amount, 'The added line is priced from its intent: 2 × 0.50.' );

		$this->assertCount( 1, $result->intents );
		$this->assertInstanceOf( DiscountLines::class, $result->intents[0], 'The second evaluation\'s intents apply, less its add-a-line.' );

		$skipped = array_values( array_filter( $result->trace->entries, static fn( TraceEntry $entry ): bool => TraceEntry::SKIPPED === $entry->kind ) );

		$this->assertCount( 1, $skipped );
		$this->assertSame( 'a3.intents', $skipped[0]->step );
		$this->assertSame(
			array(
				'reason' => 're-entry',
				'type'   => 'add_line',
				'source' => 'promotion:gift',
			),
			$skipped[0]->data
		);
	}

	/**
	 * Tests that a promotion adding a line in another currency is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_an_added_line_in_another_currency_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Engine() )->phaseA( Inputs::input( array( Inputs::line( 'a', '10.00', currency: 'EUR' ) ), currency: 'EUR' ), new AddingEvaluator() );
	}
}

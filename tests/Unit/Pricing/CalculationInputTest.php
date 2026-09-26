<?php
/**
 * Tests CalculationInput and its parts: what a calculation refuses to start from
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\AdjustmentBase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\FeeDefinition;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\PriceSource;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Taxability;
use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Percentage;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Proves that an input holds one currency and unique line keys, and that its trace form holds no one's address.
 *
 * A mismatch inside an input is a caller's mistake, so it is an exception when the input is
 * made, not an error a customer sees.
 *
 * @since 0.1.0
 */
final class CalculationInputTest extends TestCase {

	/**
	 * Tests that a line priced in another currency is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_in_another_currency_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'EUR' );

		Inputs::input( array( Inputs::line( 'a', '9.99' ), Inputs::line( 'b', '9.99', currency: 'EUR' ) ) );
	}

	/**
	 * Tests that two lines with one key are refused.
	 *
	 * @since 0.1.0
	 */
	public function test_two_lines_with_one_key_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Inputs::input( array( Inputs::line( 'a', '9.99' ), Inputs::line( 'a', '1.00' ) ) );
	}

	/**
	 * Tests that a conversion context that does not end in the calculation's currency is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_context_to_another_currency_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Inputs::input( array( Inputs::line( 'a', '9.99' ) ), context: ConversionContext::identity( Currency::of( 'EUR' ) ) );
	}

	/**
	 * Tests that a promotion's and a fee's fixed amounts must be in the calculation's currency.
	 *
	 * @since 0.1.0
	 */
	public function test_a_fixed_promotion_or_fee_in_another_currency_is_refused(): void {
		$promotion = new PromotionFacts( 1, 'p1', 'SAVE', PromotionEffect::fixed( Inputs::amount( '5.00', currency: 'GBP' ) ), 10 );
		$fee       = new FeeDefinition( 'handling', Inputs::amount( '1.00', currency: 'GBP' ), AdjustmentBase::SubtotalAfterDiscounts, Taxability::notTaxable() );

		foreach ( array( array( array( $promotion ), array() ), array( array(), array( $fee ) ) ) as $case ) {
			try {
				Inputs::input( array( Inputs::line( 'a', '9.99' ) ), promotions: $case[0], fees: $case[1] );
				$this->fail( 'An amount in GBP was accepted in a USD calculation.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( 'GBP', $refused->getMessage() );
			}
		}
	}

	/**
	 * Tests that a line of no units, of no variant, or at a negative price is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_bad_lines
	 *
	 * @param int    $variantId The variant.
	 * @param int    $quantity  The quantity.
	 * @param string $unit      The unit price.
	 */
	public function test_a_line_that_sells_nothing_or_pays_out_is_refused( int $variantId, int $quantity, string $unit ): void {
		$this->expectException( \InvalidArgumentException::class );

		new InputLine( 'a', $variantId, $quantity, Inputs::amount( $unit ), PriceSource::Explicit, 'standard' );
	}

	/**
	 * Provides lines a calculation cannot take.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{int, int, string}> Test cases.
	 */
	public static function data_bad_lines(): array {
		return array(
			'quantity zero'    => array( 1, 0, '9.99' ),
			'no variant'       => array( 0, 1, '9.99' ),
			'a negative price' => array( 1, 1, '-0.01' ),
		);
	}

	/**
	 * Tests that negative promotion effects and fees are refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_promotion_or_fee_that_adds_money_the_wrong_way_is_refused(): void {
		$attempts = array(
			static fn() => PromotionEffect::fixed( Inputs::amount( '-1.00' ) ),
			static fn() => PromotionEffect::percent( Percentage::fromString( '-5' ) ),
			static fn() => new FeeDefinition( 'surcharge', Inputs::amount( '-1.00' ), AdjustmentBase::SubtotalAfterDiscounts, Taxability::notTaxable() ),
			static fn() => new FeeDefinition( 'Bad Key', Inputs::amount( '1.00' ), AdjustmentBase::SubtotalAfterDiscounts, Taxability::notTaxable() ),
		);

		foreach ( $attempts as $index => $attempt ) {
			try {
				$attempt();
				$this->fail( "Attempt {$index} was accepted." );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertNotSame( '', $refused->getMessage() );
			}
		}
	}

	/**
	 * Tests that the input's trace form is scalars only and names the destination by its country alone.
	 *
	 * @since 0.1.0
	 */
	public function test_the_trace_form_holds_no_address(): void {
		$address = new Address( 'GB', 'Ada', 'Lovelace', '', '1 Test Street', '', 'London', '', 'N1 1AA', '000 0000', 'ada@example.com', 'GB000000000' );
		$input   = Inputs::input( array( Inputs::line( 'a', '9.99', basis: AmountBasis::Gross ) ), destination: $address );
		$array   = $input->toArray();

		$this->assertSame( 'GB', $array['destination_country'] );

		foreach ( $array as $value ) {
			$this->assertStringNotContainsString( 'Lovelace', (string) $value );
			$this->assertStringNotContainsString( 'N1 1AA', (string) $value );
			$this->assertStringNotContainsString( 'example.com', (string) $value );
		}

		$this->assertSame( '2026-01-15T10:00:00.000000Z', $array['calculated_at'] );
		$this->assertSame( ConversionContext::identity( Currency::of( 'USD' ) )->fingerprint(), $array['conversion_context'] );
	}
}

<?php
/**
 * Tests the terms a currency is offered on, and what a merchant may save as a rate
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Application\ManualRate;
use SEOCart\Pricing\Application\PresentmentCurrency;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Support\Decimal;
use SEOCart\Tests\Support\Pricing\Calculators;

/**
 * Proves the base currency's fixed terms, and what each value object refuses.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class CurrencyTermsTest extends TestCase {

	/**
	 * Tests that the base currency is offered at its identity rate, by its default rule, with nothing to convert.
	 *
	 * @since 0.1.0
	 */
	public function test_the_base_currency_is_offered_on_fixed_terms(): void {
		$usd   = Currency::of( 'USD' );
		$terms = PresentmentCurrency::base( $usd );

		$this->assertEquals( ConversionContext::identity( $usd ), $terms->context );
		$this->assertEquals( CurrencyRoundingRule::defaultFor( $usd ), $terms->roundingRule );
		$this->assertFalse( $terms->conversionFallbackAllowed );
		$this->assertSame( array( 'USD', 'USD' ), array( $terms->currency()->code(), $terms->baseCurrency()->code() ) );
	}

	/**
	 * Tests that a currency's terms name it and its base currency, and that a rounding rule of another currency is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_the_terms_hold_one_currency(): void {
		$euro = Calculators::presentment( 'EUR', '0.91230' );

		$this->assertSame( array( 'EUR', 'USD', 5 ), array( $euro->currency()->code(), $euro->baseCurrency()->code(), $euro->context->rateScale() ) );

		$this->expectException( \InvalidArgumentException::class );

		new PresentmentCurrency( CurrencyRoundingRule::defaultFor( Currency::of( 'GBP' ) ), true, $euro->context );
	}

	/**
	 * Tests that a rate keeps the scale it was written at, up to twelve places.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rate_keeps_its_scale(): void {
		$rate = new ManualRate( Currency::of( 'USD' ), Currency::of( 'JPY' ), Decimal::of( '149.123456789012' ) );

		$this->assertSame( array( '149.123456789012', 12 ), array( $rate->rate->toString(), $rate->rate->scale() ) );
		$this->assertSame( '0.91230', ( new ManualRate( Currency::of( 'USD' ), Currency::of( 'EUR' ), Decimal::of( '0.91230' ) ) )->rate->toString() );
	}

	/**
	 * Returns rates a merchant may not save.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: string}> The quote currency and the rate.
	 */
	public static function data_refused_rates(): array {
		return array(
			'to the base currency itself'     => array( 'USD', '1' ),
			'zero'                            => array( 'EUR', '0.00000' ),
			'below zero'                      => array( 'EUR', '-0.91230' ),
			'more than twelve decimal places' => array( 'EUR', '0.9123000000001' ),
		);
	}

	/**
	 * Tests that each refused rate is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_refused_rates
	 *
	 * @param string $quote The quote currency.
	 * @param string $rate  The rate.
	 */
	public function test_a_rate_that_cannot_be_quoted_is_refused( string $quote, string $rate ): void {
		$this->expectException( \InvalidArgumentException::class );

		new ManualRate( Currency::of( 'USD' ), Currency::of( $quote ), Decimal::of( $rate ) );
	}
}

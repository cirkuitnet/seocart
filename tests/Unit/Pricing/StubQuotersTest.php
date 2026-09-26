<?php
/**
 * Tests the two providers the plugin ships with: a flat shipping rate and a fixed tax rate
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Application\ShippingQuoteRequest;
use SEOCart\Pricing\Application\TaxQuoteRequest;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Infrastructure\Quotes\FixedRateTaxQuoter;
use SEOCart\Pricing\Infrastructure\Quotes\FlatRateShippingQuoter;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Percentage;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Proves what the stand-in providers quote: their constants, converted where the cart's currency is not the base one.
 *
 * @since 0.1.0
 */
final class StubQuotersTest extends TestCase {

	/**
	 * Tests the flat rate in the base currency, and converted at a frozen rate.
	 *
	 * @since 0.1.0
	 */
	public function test_the_flat_rate_is_quoted_in_the_carts_currency(): void {
		$quoter = new FlatRateShippingQuoter( new SequentialIdGenerator(), Decimal::of( FlatRateShippingQuoter::RATE ), AmountBasis::Net, FlatRateShippingQuoter::TAX_CLASS );
		$usd    = Currency::of( 'USD' );
		$eur    = Currency::of( 'EUR' );
		$at     = new \DateTimeImmutable( Inputs::AT );
		$frozen = new ConversionContext( $usd, $eur, ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( '0.91230' ), 5, 'manual', 3, $at );

		$home   = $quoter->quote( new ShippingQuoteRequest( array(), null, $usd, ConversionContext::identity( $usd ), $at ) );
		$abroad = $quoter->quote( new ShippingQuoteRequest( array(), null, $eur, $frozen, $at ) );

		$this->assertCount( 1, $home );
		$this->assertSame( array( 'flat', 'USD 500', 'net', 'standard', 'stub:flat:v1' ), array( $home[0]->methodKey, $home[0]->rate->amount->currency()->code() . ' ' . $home[0]->rate->amount->minorUnits(), $home[0]->rate->basis->value, $home[0]->taxClass, $home[0]->providerFingerprint ) );
		$this->assertSame( '2026-01-15T10:15:00+00:00', $home[0]->expiresAt->format( DATE_ATOM ), 'A quote holds for fifteen minutes from when the calculation was asked for.' );
		$this->assertSame( SequentialIdGenerator::nth( 1 ), $home[0]->quoteId );
		$this->assertSame( SequentialIdGenerator::nth( 2 ), $abroad[0]->quoteId, 'Each quote has an id of its own.' );
		$this->assertSame( 'EUR 456', $abroad[0]->rate->amount->currency()->code() . ' ' . $abroad[0]->rate->amount->minorUnits(), '5.00 USD × 0.91230 = 4.5615, rounded once.' );
	}

	/**
	 * Tests that the fixed rate is quoted for every class asked for, at the destination and at home alike.
	 *
	 * @since 0.1.0
	 */
	public function test_the_fixed_rate_is_quoted_for_every_class(): void {
		$quote = ( new FixedRateTaxQuoter( Percentage::fromString( FixedRateTaxQuoter::RATE ), FixedRateTaxQuoter::JURISDICTION ) )->quote( new TaxQuoteRequest( array( 'standard', 'class_7' ), null, new \DateTimeImmutable( Inputs::AT ) ) );

		$this->assertTrue( $quote->covers( 'standard', 'class_7' ) );
		$this->assertFalse( $quote->covers( 'reduced' ) );
		$this->assertSame( $quote->destinationRates, $quote->referenceRates );
		$this->assertSame( 20000000, $quote->destinationRatesFor( 'class_7' )[0]->rate->micropercent() );
		$this->assertSame( 'stub', $quote->destinationRatesFor( 'standard' )[0]->jurisdictionCode );
		$this->assertSame( 'stub:fixed:v1', $quote->providerFingerprint );
	}
}

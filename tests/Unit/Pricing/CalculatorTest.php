<?php
/**
 * Tests the calculator: when it refuses to run, how a provider's failure surfaces, and what it reports beside the totals
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
use SEOCart\Pricing\Application\UnpricedLine;
use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Pricing\Domain\Quote\TaxQuote;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Catalog\FixedFactsRepository;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\PoisonedQuoters;
use SEOCart\Tests\Support\Pricing\Calculators;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Proves the calculator's guards and what reaches its caller.
 *
 * Planted violations, each shown red and removed:
 *
 * - in Calculator::calculate(), drop the transaction depth check: inside a transaction the prices
 *   are read and the quoters called, and the first test fails on the recorded calls;
 * - in Calculator::quotes(), catch the shipping quoter's failure and go on with no rate: the
 *   customer is told there is no shipping method instead of that the provider did not answer,
 *   and the second test fails.
 *
 * @since 0.1.0
 */
final class CalculatorTest extends TestCase {

	/**
	 * Tests that a calculation inside a transaction is refused before anything is read or quoted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_calculation_inside_a_transaction_is_refused_before_any_port_is_called(): void {
		$prices       = self::prices();
		$quoters      = self::quoters();
		$transactions = new FakeTransactionManager();
		$calculator   = Calculators::over( $prices, $quoters, $transactions );

		try {
			$transactions->transaction( static fn() => $calculator->calculate( self::request() ) );
			$this->fail( 'A calculation ran inside a transaction.' );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( 'transaction', $refused->getMessage() );
		}

		$this->assertSame( array(), $prices->askedPrices );
		$this->assertSame( array( 0, 0, 0 ), array( $quoters->shippingCalls, $quoters->taxCalls, $quoters->evaluations ) );
	}

	/**
	 * Tests that a provider's failure reaches the caller as it was thrown, and no totals come back.
	 *
	 * @since 0.1.0
	 */
	public function test_a_provider_failure_reaches_the_caller_unchanged(): void {
		$failure    = CodedException::because( PricingError::QuoteUnavailable, array( 'provider' => 'carrier:v1' ) );
		$quoters    = new PoisonedQuoters( array(), self::taxQuote(), array(), $failure );
		$calculator = Calculators::over( self::prices(), $quoters, new FakeTransactionManager() );

		try {
			$calculator->calculate( self::request() );
			$this->fail( 'A calculation returned although a provider could not quote.' );
		} catch ( CodedException $thrown ) {
			$this->assertSame( $failure, $thrown );
		}

		$this->assertSame( 0, $quoters->taxCalls, 'Nothing is quoted after a failure.' );
	}

	/**
	 * Tests that a tax quote without a class the cart needs is a provider failure, not a zero tax.
	 *
	 * @since 0.1.0
	 */
	public function test_a_tax_quote_without_a_needed_class_is_a_provider_failure(): void {
		$quoters    = new PoisonedQuoters( array( Inputs::shippingRate( 'flat', '5.00' ) ), Inputs::taxQuote( array( 'reduced' => array() ) ) );
		$calculator = Calculators::over( self::prices(), $quoters, new FakeTransactionManager() );

		try {
			$calculator->calculate( self::request() );
			$this->fail( 'A cart was priced without the rates of its tax class.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PricingError::QuoteUnavailable, $refused->errorCode() );
			$this->assertSame( array( 'provider' => 'test:tax:v1' ), $refused->context() );
		}
	}

	/**
	 * Tests that a cart in another currency than the base one is refused before any price is read.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cart_in_another_currency_is_refused_before_any_read(): void {
		$prices     = self::prices();
		$calculator = Calculators::over( $prices, self::quoters(), new FakeTransactionManager() );

		try {
			$calculator->calculate( new CalculationRequest( Currency::of( 'EUR' ), array( new LineRequest( 'a', 11, 1 ) ) ) );
			$this->fail( 'A cart in EUR was priced in a USD store.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PricingError::CurrencyNotEnabled, $refused->errorCode() );
			$this->assertSame( array( 'currency' => 'EUR' ), $refused->context() );
		}

		$this->assertSame( array(), $prices->askedPrices );
	}

	/**
	 * Tests that lines without a price are reported beside the totals and left out of them, and that codes are traced.
	 *
	 * @since 0.1.0
	 */
	public function test_lines_without_a_price_are_reported_not_priced(): void {
		$prices           = self::prices();
		$prices->prices[] = Calculators::price( 12, '8.00', 'EUR' );
		$request          = new CalculationRequest(
			Currency::of( 'USD' ),
			array( new LineRequest( 'priced', 11, 2 ), new LineRequest( 'euro-only', 12, 1 ), new LineRequest( 'unknown', 99, 1 ) ),
			new Address( 'US' ),
			array( 'SAVE10' )
		);
		$calculation      = Calculators::over( $prices, self::quoters(), new FakeTransactionManager() )->calculate( $request );

		$this->assertSame( array( 'priced' ), array_map( static fn( $line ): string => $line->line->key, $calculation->totals->lines ) );
		$this->assertEquals(
			array( new UnpricedLine( 'euro-only', 12, UnpricedLine::UNKNOWN_VARIANT ), new UnpricedLine( 'unknown', 99, UnpricedLine::UNKNOWN_VARIANT ) ),
			$calculation->unpricedLines,
			'A USD cart reads USD prices only, so a price in a third currency is as good as none.'
		);
		$this->assertSame( 3000, $calculation->totals->summary->grand->minorUnits(), '2 × 10.00 and a flat 5.00, both taxed at 20 %.' );

		$rejected = array_values( array_filter( $calculation->totals->trace->entries, static fn( TraceEntry $entry ): bool => 'rejected_code' === ( $entry->data['record'] ?? null ) ) );

		$this->assertSame( array( 'SAVE10' ), array_map( static fn( TraceEntry $entry ): string => $entry->data['code'], $rejected ) );
	}

	/**
	 * Returns a request for two units of variant 11, shipped to the United States.
	 *
	 * @since 0.1.0
	 *
	 * @return CalculationRequest The request.
	 */
	private static function request(): CalculationRequest {
		return new CalculationRequest( Currency::of( 'USD' ), array( new LineRequest( 'a', 11, 2 ) ), new Address( 'US' ) );
	}

	/**
	 * Returns prices: variant 11 at 10.00 USD.
	 *
	 * @since 0.1.0
	 *
	 * @return FixedFactsRepository The prices.
	 */
	private static function prices(): FixedFactsRepository {
		$prices         = new FixedFactsRepository();
		$prices->prices = array( Calculators::price( 11, '10.00' ) );

		return $prices;
	}

	/**
	 * Returns quoters answering a flat 5.00 and 20 % on `standard`.
	 *
	 * @since 0.1.0
	 *
	 * @return PoisonedQuoters The quoters.
	 */
	private static function quoters(): PoisonedQuoters {
		return new PoisonedQuoters( array( Inputs::shippingRate( 'flat', '5.00' ) ), self::taxQuote() );
	}

	/**
	 * Returns a quote of 20 % on `standard`.
	 *
	 * @since 0.1.0
	 *
	 * @return TaxQuote The quote.
	 */
	private static function taxQuote(): TaxQuote {
		return Inputs::taxQuote( array( 'standard' => array( Inputs::rate( '20' ) ) ) );
	}
}

<?php
/**
 * Calculator: prices a cart or an order, from its lines to its totals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\CustomerTaxFacts;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\Engine\PhaseAResult;
use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\Quote\Quotes;
use SEOCart\Pricing\Domain\RejectedCode;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;

defined( 'ABSPATH' ) || exit;

/**
 * The one entry to the calculation: it gathers the input, runs the engine's two phases, and takes the quotes between them.
 *
 * Owns one fact: the order in which a calculation reaches outside the engine. First the prices,
 * in one query; then the engine's first phase; then the shipping and tax quotes, once each, for
 * exactly the lines that phase resolved; then the second phase. Nothing else is read, and
 * nothing is computed here: every figure comes from the engine.
 *
 * A calculation never runs inside a transaction. Its quotes may be taken from a provider over
 * the network, and a transaction must not wait on the network: so it is refused outright, before
 * anything is read, rather than merely advised against. A caller that needs fresh totals inside
 * a unit of work calculates first and opens the transaction after.
 *
 * Prices are offered in the base currency, and in every currency the merchant enabled that has
 * a rate in the current exchange-rate version; a cart in any other currency is refused. A cart's
 * currency brings its terms: its rounding rule, whether base prices may be converted into it, and
 * the rate every converted price and every base figure of the calculation is at. Finding them
 * costs nothing for the base currency and one query the first time in a request for another.
 * The store's base currency, its cross-zone policy and its tax rounding mode are the merchant's
 * settings, read once per calculation, through the readers the calculator is given: they are one
 * group of settings, so reading them costs one query the first time in a request and none after.
 * No promotion is resolved from a code here, so every code entered is traced as unknown.
 *
 * @since 0.1.0
 */
final class Calculator {

	/**
	 * Creates the calculator. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param PriceResolver         $prices          Finds the lines' prices.
	 * @param ShippingRateQuoter    $shipping        Quotes the shipping rates.
	 * @param TaxQuoter             $tax             Quotes the tax rates.
	 * @param PromotionEvaluator    $promotions      Turns the promotions into intents.
	 * @param TransactionManager    $transactions    Tells whether a transaction is open.
	 * @param Clock                 $clock           Tells when the calculation is asked for.
	 * @param \Closure              $baseCurrency    Returns the store's base currency.
	 * @param \Closure              $crossZonePolicy Returns what stays fixed when a gross price is sold into another tax zone.
	 * @param \Closure              $taxRoundingMode Returns where tax is rounded.
	 * @param PresentmentCurrencies $currencies      Finds the terms the cart's currency is offered on.
	 *
	 * @phpstan-param \Closure(): Currency        $baseCurrency
	 * @phpstan-param \Closure(): CrossZonePolicy $crossZonePolicy
	 * @phpstan-param \Closure(): TaxRoundingMode $taxRoundingMode
	 */
	public function __construct(
		private PriceResolver $prices,
		private ShippingRateQuoter $shipping,
		private TaxQuoter $tax,
		private PromotionEvaluator $promotions,
		private TransactionManager $transactions,
		private Clock $clock,
		private \Closure $baseCurrency,
		private \Closure $crossZonePolicy,
		private \Closure $taxRoundingMode,
		private PresentmentCurrencies $currencies
	) {
	}

	/**
	 * Calculates the totals of a request.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open.
	 * @throws CodedException  With PricingError::CurrencyNotEnabled when prices are not offered in the
	 *                         cart's currency; PricingError::QuoteUnavailable when a provider cannot
	 *                         quote; PricingError::NoShippingRate when the destination has no rate.
	 *
	 * @param CalculationRequest $request The lines, the currency, the destination, the codes and the shipping chosen.
	 * @return Calculation The totals, and the lines that could not be priced.
	 */
	public function calculate( CalculationRequest $request ): Calculation {
		$this->refuseInsideTransaction();

		$base     = ( $this->baseCurrency )();
		$currency = $this->currencies->find( $base, $request->currency );

		if ( null === $currency ) {
			CodedException::raise( PricingError::CurrencyNotEnabled, array( 'currency' => $request->currency->code() ) );
		}

		$prices = $this->prices->resolve( $request->lines, $currency );
		$input  = new CalculationInput(
			$request->currency,
			$base,
			$currency->context,
			$currency->roundingRule,
			$prices->lines,
			$request->destination,
			CustomerTaxFacts::notExempt(),
			array(),
			array_map( static fn( string $code ): RejectedCode => new RejectedCode( $code, RejectedCode::UNKNOWN ), $request->promotionCodes ),
			$request->shippingMethodKey,
			array(),
			( $this->crossZonePolicy )(),
			( $this->taxRoundingMode )(),
			$this->clock->now()
		);

		$engine = new Engine();
		$phaseA = $engine->phaseA( $input, $this->promotions );

		return new Calculation( $engine->phaseB( $phaseA, $this->quotes( $phaseA ) ), $prices->unpriced );
	}

	/**
	 * Refuses to go on inside a transaction, before anything is read or quoted.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open.
	 */
	private function refuseInsideTransaction(): void {
		if ( $this->transactions->depth() > 0 ) {
			throw new \LogicException( 'A calculation takes quotes from providers, so it never runs inside a transaction: calculate first, then open the transaction.' );
		}
	}

	/**
	 * Takes the shipping and tax quotes for the first phase's lines: the calculation's only reach outside.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With PricingError::QuoteUnavailable when a provider cannot quote, or the
	 *                        tax quote leaves out a class the calculation needs.
	 *
	 * @param PhaseAResult $phaseA The first phase's result.
	 * @return Quotes The quotes.
	 */
	private function quotes( PhaseAResult $phaseA ): Quotes {
		$input   = $phaseA->input;
		$rates   = $this->shipping->quote( new ShippingQuoteRequest( $phaseA->lines, $input->destination, $input->currency, $input->conversionContext, $input->calculatedAt ) );
		$classes = array();

		foreach ( $phaseA->lines as $resolved ) {
			$classes[] = $resolved->line->taxClass;
		}

		foreach ( $rates as $rate ) {
			$classes[] = $rate->taxClass;
		}

		foreach ( $input->fees as $fee ) {
			if ( null !== $fee->taxability->taxClass ) {
				$classes[] = $fee->taxability->taxClass;
			}
		}

		$classes = array_values( array_unique( $classes ) );
		$tax     = $this->tax->quote( new TaxQuoteRequest( $classes, $input->destination, $input->calculatedAt ) );

		// A quote that leaves out a class cannot price it; charging it no tax instead would be the silent zero.
		if ( ! $tax->covers( ...$classes ) ) {
			CodedException::raise( PricingError::QuoteUnavailable, array( 'provider' => $tax->providerFingerprint ) );
		}

		return new Quotes( $rates, $tax, $phaseA->packagesFingerprint() );
	}
}

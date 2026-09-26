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
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;
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
 * Prices are offered in the base currency only; a cart in another currency is refused. Tax is
 * worked out per line, and a gross price keeps its net amount across tax zones. No promotion is
 * resolved from a code here, so every code entered is traced as unknown.
 *
 * @since 0.1.0
 */
final class Calculator {

	/**
	 * What stays fixed when a gross price is sold into another tax zone.
	 *
	 * @since 0.1.0
	 *
	 * @var CrossZonePolicy
	 */
	private const CROSS_ZONE_POLICY = CrossZonePolicy::FixedNet;

	/**
	 * Where tax is rounded.
	 *
	 * @since 0.1.0
	 *
	 * @var TaxRoundingMode
	 */
	private const TAX_ROUNDING_MODE = TaxRoundingMode::PerLine;

	/**
	 * Creates the calculator. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param PriceResolver      $prices       Finds the lines' prices.
	 * @param ShippingRateQuoter $shipping     Quotes the shipping rates.
	 * @param TaxQuoter          $tax          Quotes the tax rates.
	 * @param PromotionEvaluator $promotions   Turns the promotions into intents.
	 * @param TransactionManager $transactions Tells whether a transaction is open.
	 * @param Clock              $clock        Tells when the calculation is asked for.
	 * @param \Closure           $baseCurrency Returns the store's base currency.
	 *
	 * @phpstan-param \Closure(): Currency $baseCurrency
	 */
	public function __construct(
		private PriceResolver $prices,
		private ShippingRateQuoter $shipping,
		private TaxQuoter $tax,
		private PromotionEvaluator $promotions,
		private TransactionManager $transactions,
		private Clock $clock,
		private \Closure $baseCurrency
	) {
	}

	/**
	 * Calculates the totals of a request.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open.
	 * @throws CodedException  With PricingError::CurrencyNotEnabled when the cart is not in the base
	 *                         currency; PricingError::QuoteUnavailable when a provider cannot quote;
	 *                         PricingError::NoShippingRate when the destination has no rate.
	 *
	 * @param CalculationRequest $request The lines, the currency, the destination, the codes and the shipping chosen.
	 * @return Calculation The totals, and the lines that could not be priced.
	 */
	public function calculate( CalculationRequest $request ): Calculation {
		$this->refuseInsideTransaction();

		$base = ( $this->baseCurrency )();

		if ( ! $request->currency->equals( $base ) ) {
			CodedException::raise( PricingError::CurrencyNotEnabled, array( 'currency' => $request->currency->code() ) );
		}

		$prices = $this->prices->resolve( $request->lines, $request->currency, $base );
		$input  = new CalculationInput(
			$request->currency,
			$base,
			ConversionContext::identity( $base ),
			CurrencyRoundingRule::defaultFor( $request->currency ),
			$prices->lines,
			$request->destination,
			CustomerTaxFacts::notExempt(),
			array(),
			array_map( static fn( string $code ): RejectedCode => new RejectedCode( $code, RejectedCode::UNKNOWN ), $request->promotionCodes ),
			$request->shippingMethodKey,
			array(),
			self::CROSS_ZONE_POLICY,
			self::TAX_ROUNDING_MODE,
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

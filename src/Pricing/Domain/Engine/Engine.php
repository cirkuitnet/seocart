<?php
/**
 * Engine: the pure two-phase calculation of a cart's totals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\Quote\Quotes;
use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Domain\Totals\TraceEntry;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages name values built by code, for the developer; they are never rendered.

/**
 * Calculates totals in two phases, as a pure function of its input and its quotes.
 *
 * Owns one fact: the order of the calculation's steps. The first phase resolves the lines and
 * evaluates the promotions; then the caller obtains the shipping and tax quotes for exactly those
 * lines, which is the calculation's only input from outside; the second phase applies, in this
 * order, the discounts, the shipping, the fees and the tax, and converts every figure to the base
 * currency.
 *
 * The engine has no constructor and holds nothing: no clock, no repository, no provider. Its
 * steps are the same. The one callable it is handed is the promotion evaluator, which is domain
 * code that reads only what it is shown. So the same input and the same quotes always give the
 * same totals, trace included.
 *
 * @since 0.1.0
 */
final class Engine {

	/**
	 * Runs the first phase: the lines' amounts, then the promotions' intents.
	 *
	 * @since 0.1.0
	 *
	 * @param CalculationInput   $input     The input.
	 * @param PromotionEvaluator $evaluator The promotions' evaluator.
	 * @return PhaseAResult The lines and intents the quotes are to be taken for.
	 */
	public function phaseA( CalculationInput $input, PromotionEvaluator $evaluator ): PhaseAResult {
		$trace = new TraceBuilder( $input->roundingRule->roundingMode() );

		$trace->input( $input );

		return ( new IntentEvaluation() )->evaluate( $input, ( new LineResolution() )->resolve( $input->lines ), $evaluator, $trace );
	}

	/**
	 * Runs the second phase on the first phase's result and the quotes taken for it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the quotes were taken for other lines, or a rate is in another currency.
	 *
	 * @param PhaseAResult $phaseA The first phase's result.
	 * @param Quotes       $quotes The quotes taken for its lines.
	 * @return Totals The totals.
	 */
	public function phaseB( PhaseAResult $phaseA, Quotes $quotes ): Totals {
		$this->assertQuotesFit( $phaseA, $quotes );

		$trace = new TraceBuilder( $phaseA->input->roundingRule->roundingMode(), $phaseA->trace );

		$this->traceQuotes( $trace, $quotes );

		$state = new PhaseBState( $phaseA, $quotes, $trace );

		( new DiscountStep() )->apply( $state );
		( new ShippingStep() )->apply( $state );
		( new FeeStep() )->apply( $state );
		( new TaxStep() )->apply( $state );
		( new BaseEquivalentStep() )->apply( $state );

		return ( new TotalsAssembly() )->assemble( $state );
	}

	/**
	 * Refuses quotes taken for other lines than the first phase's, or rates in another currency.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When they do not fit.
	 *
	 * @param PhaseAResult $phaseA The first phase's result.
	 * @param Quotes       $quotes The quotes.
	 */
	private function assertQuotesFit( PhaseAResult $phaseA, Quotes $quotes ): void {
		if ( $quotes->packagesFingerprint !== $phaseA->packagesFingerprint() ) {
			throw new \LogicException( 'The quotes were taken for other lines than the ones the first phase resolved; quote the first phase\'s lines.' );
		}

		foreach ( $quotes->shippingRates as $rate ) {
			if ( ! $rate->rate->currency()->equals( $phaseA->input->currency ) ) {
				throw new \LogicException( sprintf( 'The shipping rate "%s" is quoted in %s, not in %s.', $rate->methodKey, $rate->rate->currency()->code(), $phaseA->input->currency->code() ) );
			}
		}
	}

	/**
	 * Records the quotes: each shipping rate, the tax quote, and the rates of each tax class.
	 *
	 * @since 0.1.0
	 *
	 * @param TraceBuilder $trace  The trace.
	 * @param Quotes       $quotes The quotes.
	 */
	private function traceQuotes( TraceBuilder $trace, Quotes $quotes ): void {
		$trace->enter( 'quotes' );

		foreach ( $quotes->shippingRates as $rate ) {
			$trace->record( TraceEntry::QUOTE, array( 'record' => 'shipping_rate' ) + $rate->toArray() );
		}

		$trace->record( TraceEntry::QUOTE, array( 'record' => 'tax' ) + $quotes->taxQuote->toArray() );

		$sides = array(
			'destination' => $quotes->taxQuote->destinationRates,
			'reference'   => $quotes->taxQuote->referenceRates,
		);

		foreach ( $sides as $side => $classes ) {
			foreach ( $classes as $class => $rates ) {
				$trace->record(
					TraceEntry::QUOTE,
					array(
						'record'             => 'tax_rates',
						'side'               => $side,
						'tax_class'          => (string) $class,
						'rate_refs'          => array_map( static fn( TaxRateComponent $rate ): string => $rate->rateRef, $rates ),
						'rates_micropercent' => array_map( static fn( TaxRateComponent $rate ): int => $rate->rate->micropercent(), $rates ),
						'compound'           => array_map( static fn( TaxRateComponent $rate ): bool => $rate->isCompound, $rates ),
						'jurisdictions'      => array_map( static fn( TaxRateComponent $rate ): string => $rate->jurisdictionCode, $rates ),
					)
				);
			}
		}
	}
}

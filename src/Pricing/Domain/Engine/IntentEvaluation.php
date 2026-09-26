<?php
/**
 * IntentEvaluation: asks the promotions what they want, and honours an added line only once
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\Intent\AddLine;
use SEOCart\Pricing\Domain\Intent\PromotionIntent;
use SEOCart\Pricing\Domain\PhaseAView;
use SEOCart\Pricing\Domain\PriceSource;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\Totals\TraceEntry;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message names a value built by code, for the developer; it is never rendered.

/**
 * The last step of the first phase: promotion evaluation, with its re-entry bound.
 *
 * Owns one fact: that promotions are evaluated at most twice per calculation. If the first
 * evaluation asks to add lines, they are added and evaluation runs once more over every line,
 * so a promotion can see the line it added. Any line the second evaluation asks to add is
 * dropped and traced: an added line never adds a line. The bound is two plain calls, not a
 * loop, so no evaluator can make it a third.
 *
 * @since 0.1.0
 */
final class IntentEvaluation {

	/**
	 * Evaluates the promotions and finishes the first phase.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a promotion adds a line priced in another currency.
	 *
	 * @param CalculationInput   $input     The input.
	 * @param array              $lines     The input's lines, resolved.
	 * @param PromotionEvaluator $evaluator The promotions' evaluator.
	 * @param TraceBuilder       $trace     The trace, filed under the step `a3.intents`.
	 * @return PhaseAResult The first phase's result.
	 *
	 * @phpstan-param list<ResolvedLine> $lines
	 */
	public function evaluate( CalculationInput $input, array $lines, PromotionEvaluator $evaluator, TraceBuilder $trace ): PhaseAResult {
		$trace->enter( 'a3.intents' );

		$intents = $this->ask( $evaluator, $input, $lines, 1, $trace );
		$added   = array_values( array_filter( $intents, static fn( PromotionIntent $intent ): bool => $intent instanceof AddLine ) );

		if ( array() !== $added ) {
			$lines   = array_merge( $lines, ( new LineResolution() )->resolve( $this->linesFor( $input, $added ) ) );
			$intents = $this->ask( $evaluator, $input, $lines, 2, $trace );

			foreach ( $intents as $intent ) {
				if ( $intent instanceof AddLine ) {
					$trace->record(
						TraceEntry::SKIPPED,
						array(
							'reason' => 're-entry',
							'type'   => $intent->type(),
							'source' => $intent->source()->toString(),
						)
					);
				}
			}
		}

		$applicable = array_values( array_filter( $intents, static fn( PromotionIntent $intent ): bool => ! $intent instanceof AddLine ) );

		return new PhaseAResult( $input, $lines, $applicable, $trace->freeze() );
	}

	/**
	 * Asks the evaluator for its intents over the lines, and traces them.
	 *
	 * @since 0.1.0
	 *
	 * @param PromotionEvaluator $evaluator The evaluator.
	 * @param CalculationInput   $input     The input.
	 * @param array              $lines     The lines.
	 * @param int                $pass      1 or 2.
	 * @param TraceBuilder       $trace     The trace.
	 * @return list<PromotionIntent> The intents, in order.
	 *
	 * @phpstan-param list<ResolvedLine> $lines
	 */
	private function ask( PromotionEvaluator $evaluator, CalculationInput $input, array $lines, int $pass, TraceBuilder $trace ): array {
		$intents = $evaluator->evaluate( new PhaseAView( $input->promotions, $lines ) );

		foreach ( $intents as $intent ) {
			$trace->record(
				TraceEntry::INTENT,
				array(
					'pass'   => $pass,
					'type'   => $intent->type(),
					'source' => $intent->source()->toString(),
				)
			);
		}

		return $intents;
	}

	/**
	 * Turns add-a-line intents into lines, keyed `auto:<source>:<n>` in the order they were asked for.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a line is priced in another currency than the calculation's.
	 *
	 * @param CalculationInput $input The input.
	 * @param AddLine[]        $added The intents.
	 * @return list<InputLine> The lines.
	 *
	 * @phpstan-param list<AddLine> $added
	 */
	private function linesFor( CalculationInput $input, array $added ): array {
		$lines = array();

		foreach ( $added as $index => $intent ) {
			if ( ! $intent->unitPrice->currency()->equals( $input->currency ) ) {
				throw new \InvalidArgumentException( sprintf( 'A promotion added a line priced in %s to a calculation in %s.', $intent->unitPrice->currency()->code(), $input->currency->code() ) );
			}

			$lines[] = new InputLine( 'auto:' . $intent->source()->toString() . ':' . ( $index + 1 ), $intent->variantId, $intent->quantity, $intent->unitPrice, PriceSource::AutoAdded, $intent->taxClass, true );
		}

		return $lines;
	}
}

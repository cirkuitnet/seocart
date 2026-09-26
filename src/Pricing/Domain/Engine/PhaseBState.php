<?php
/**
 * PhaseBState: the working figures of the second phase, from its first step to its totals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\AdjustmentBase;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\Quote\Quotes;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Pricing\Domain\Taxability;
use SEOCart\Pricing\Domain\Totals\Adjustment;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Pricing\Domain\Totals\AdjustmentType;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * What the second phase's steps have worked out so far, which each step reads and adds to.
 *
 * Owns one fact: the second phase's working figures, in one place, for one run. It is created by
 * the engine for a single run of the phase and never leaves it, so the steps may fill it in
 * turn: the discounts fill the lines' amounts after discounts and add adjustments; shipping and
 * fees add adjustments; tax fills the taxed figures; the base step fills their base twins.
 *
 * A figure of a line is filed under the line's key; a figure of an adjustment under its position.
 * Where lines and adjustments are pooled together, each is named by a reference: `line:<key>`
 * or `adjustment:<position>`.
 *
 * @since 0.1.0
 */
final class PhaseBState {

	/**
	 * Each line's amount after the discounts applied so far, by line key.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, AuthoredAmount>
	 */
	public array $afterDiscounts = array();

	/**
	 * The adjustments made so far, in the order they were made.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Adjustment>
	 */
	public array $adjustments = array();

	/**
	 * Each line taxed on its amount after discounts, by line key.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, ScopeTax>
	 */
	public array $lineTaxes = array();

	/**
	 * Each line's gross divided by its quantity, for display, by line key.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, Money>
	 */
	public array $unitGross = array();

	/**
	 * Each adjustment taxed, by position.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, ScopeTax>
	 */
	public array $adjustmentTaxes = array();

	/**
	 * Each line's and adjustment's taxed figures in the base currency, by reference.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, TaxedMoney>
	 */
	public array $bases = array();

	/**
	 * Each line's authored amount after discounts and each adjustment's authored amount in the base currency, by reference.
	 *
	 * Each is the base twin of the taxed figure the amount equals, or a share of the pool of those
	 * that equal none: what an order stores as a line's and an adjustment's base amount, and what
	 * the summary's base subtotal, discount, shipping and fee totals add up.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, Money>
	 */
	public array $authoredBases = array();

	/**
	 * Each line's and adjustment's components in the base currency, in component order, by reference.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, list<TaxedMoney>>
	 */
	public array $componentBases = array();

	/**
	 * The lines, by key.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, InputLine>
	 */
	private array $lines = array();

	/**
	 * Starts the phase's figures: every line at its full amount, and nothing else yet.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseAResult $phaseA The first phase's result.
	 * @param Quotes       $quotes The quotes taken for its lines.
	 * @param TraceBuilder $trace  The trace of this phase.
	 */
	public function __construct(
		public readonly PhaseAResult $phaseA,
		public readonly Quotes $quotes,
		public readonly TraceBuilder $trace
	) {
		foreach ( $phaseA->lines as $resolved ) {
			$this->afterDiscounts[ $resolved->line->key ] = $resolved->lineAmount;
			$this->lines[ $resolved->line->key ]          = $resolved->line;
		}
	}

	/**
	 * Returns the calculation's input.
	 *
	 * @since 0.1.0
	 *
	 * @return CalculationInput The input.
	 */
	public function input(): CalculationInput {
		return $this->phaseA->input;
	}

	/**
	 * Returns a line.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The line's key.
	 * @return InputLine The line.
	 */
	public function line( string $key ): InputLine {
		return $this->lines[ $key ];
	}

	/**
	 * Makes an adjustment, gives it the next position, and traces it.
	 *
	 * @since 0.1.0
	 *
	 * @param AdjustmentScope     $scope           What it changes.
	 * @param AdjustmentType      $type            What kind of change it is.
	 * @param Source              $source          What caused it.
	 * @param string              $labelKey        The key of its label.
	 * @param string|null         $lineKey         The line, for a line-scoped adjustment.
	 * @param AuthoredAmount      $authoredAmount  The signed amount.
	 * @param AdjustmentBase|null $calculationBase What a fee was calculated on.
	 * @param Taxability          $taxability      Whether it is taxed.
	 * @return Adjustment The adjustment.
	 */
	public function add( AdjustmentScope $scope, AdjustmentType $type, Source $source, string $labelKey, ?string $lineKey, AuthoredAmount $authoredAmount, ?AdjustmentBase $calculationBase, Taxability $taxability ): Adjustment {
		$adjustment = new Adjustment( count( $this->adjustments ) + 1, $scope, $type, $source, $labelKey, $lineKey, $authoredAmount, $calculationBase, $taxability );

		$this->adjustments[] = $adjustment;
		$this->trace->record( TraceEntry::ADJUSTMENT, $adjustment->toArray() );

		return $adjustment;
	}

	/**
	 * Returns the adjustments of one line, in the order they were made.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The line's key.
	 * @return list<Adjustment> Its line-scoped adjustments.
	 */
	public function adjustmentsOf( string $key ): array {
		return array_values( array_filter( $this->adjustments, static fn( Adjustment $adjustment ): bool => $key === $adjustment->lineKey ) );
	}

	/**
	 * Returns the reference of a line where lines and adjustments are pooled.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The line's key.
	 * @return string `line:<key>`.
	 */
	public static function lineRef( string $key ): string {
		return 'line:' . $key;
	}

	/**
	 * Returns the reference of an adjustment where lines and adjustments are pooled.
	 *
	 * @since 0.1.0
	 *
	 * @param int $position The adjustment's position.
	 * @return string `adjustment:<position>`.
	 */
	public static function adjustmentRef( int $position ): string {
		return 'adjustment:' . $position;
	}
}

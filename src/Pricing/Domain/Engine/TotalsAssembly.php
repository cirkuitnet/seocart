<?php
/**
 * TotalsAssembly: turns the second phase's figures into the totals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\Totals\TaxComponent;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Domain\Totals\TotalsAdjustment;
use SEOCart\Pricing\Domain\Totals\TotalsLine;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the immutable totals from what the steps worked out: lines, adjustments and their components.
 *
 * Owns one fact: which working figure becomes which field of the totals. It rounds nothing and
 * converts nothing: a line's discount is its amount after discounts less its subtotal, its base
 * discount is the sum of its discounts' base amounts, and its base subtotal is its base amount
 * after discounts less that base discount, so a line's base figures add up as its cart figures
 * do. Totals then adds up the summary.
 *
 * @since 0.1.0
 */
final class TotalsAssembly {

	/**
	 * Builds the totals.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state The phase's figures, every step done.
	 * @return Totals The totals.
	 */
	public function assemble( PhaseBState $state ): Totals {
		$input = $state->input();
		$lines = array();

		foreach ( $state->phaseA->lines as $resolved ) {
			$key          = $resolved->line->key;
			$ref          = PhaseBState::lineRef( $key );
			$baseDiscount = Money::zero( $input->baseCurrency );

			foreach ( $state->adjustmentsOf( $key ) as $adjustment ) {
				$baseDiscount = $baseDiscount->add( $state->authoredBases[ PhaseBState::adjustmentRef( $adjustment->position ) ] );
			}

			$lines[] = new TotalsLine(
				$resolved->line,
				$resolved->lineAmount,
				$state->afterDiscounts[ $key ]->amount->subtract( $resolved->lineAmount->amount ),
				$state->lineTaxes[ $key ]->amount,
				$state->bases[ $ref ],
				$state->authoredBases[ $ref ]->subtract( $baseDiscount ),
				$baseDiscount,
				$state->unitGross[ $key ],
				$this->components( $state, $ref, $key, null, $resolved->lineAmount->basis, $state->lineTaxes[ $key ]->components )
			);
		}

		$adjustments = array();

		foreach ( $state->adjustments as $adjustment ) {
			$ref   = PhaseBState::adjustmentRef( $adjustment->position );
			$taxed = $state->adjustmentTaxes[ $adjustment->position ];

			$adjustments[] = new TotalsAdjustment(
				$adjustment,
				$taxed->amount,
				$state->bases[ $ref ],
				$state->authoredBases[ $ref ],
				$this->components( $state, $ref, null, $adjustment->position, $adjustment->authoredAmount->basis, $taxed->components )
			);
		}

		return new Totals( $input->currency, $input->baseCurrency, $input->conversionContext, $input->crossZonePolicy, $input->taxRoundingMode, $input->calculatedAt, $lines, $adjustments, $state->trace->freeze() );
	}

	/**
	 * Builds an owner's tax components, keyed `<owner reference>:<n>` from 1.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState      $state    The phase's figures.
	 * @param string           $ref      The owner's reference.
	 * @param string|null      $lineKey  The owner's line key, for a line.
	 * @param int|null         $position The owner's position, for an adjustment.
	 * @param AmountBasis      $basis    The basis the owner was authored in.
	 * @param ComponentShare[] $shares   The owner's components in the cart's currency.
	 * @return list<TaxComponent> The components.
	 *
	 * @phpstan-param list<ComponentShare> $shares
	 */
	private function components( PhaseBState $state, string $ref, ?string $lineKey, ?int $position, AmountBasis $basis, array $shares ): array {
		$components = array();

		foreach ( $shares as $index => $share ) {
			$components[] = new TaxComponent( $ref . ':' . ( $index + 1 ), $lineKey, $position, $share->rate, $basis, $share->amount, $state->componentBases[ $ref ][ $index ], $share->residualMinor );
		}

		return $components;
	}
}

<?php
/**
 * FeeStep: charges each fee on its declared base
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\AdjustmentBase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\FeeDefinition;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Pricing\Domain\Totals\AdjustmentType;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\Money;
use SEOCart\Support\Percentage;

defined( 'ABSPATH' ) || exit;

/**
 * The fee step of the second phase: each fee becomes an order-scoped adjustment.
 *
 * Owns one fact: how a fee is charged. A percentage fee is a share of its declared base, rounded
 * once: the lines after every discount, or the selected shipping rate after its discount. A fixed
 * fee is charged as authored, and its base is recorded only. Every fee is worked out on figures
 * that do not include any fee, so no fee ever grows another. A fee that comes to nothing makes
 * no adjustment.
 *
 * Lines authored net and lines authored gross have no common sum, since one includes tax and the
 * other does not. So a percentage of lines authored in both bases is charged once per basis: the
 * percentage of the net lines as a net fee, and of the gross lines as a gross fee, each rounded
 * once, two adjustments with the fee's source. Each is then taxed as its basis says, exactly as
 * the lines it was worked out on.
 *
 * @since 0.1.0
 */
final class FeeStep {

	/**
	 * Charges the fees.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state The phase's figures.
	 */
	public function apply( PhaseBState $state ): void {
		$state->trace->enter( 'b5.fees' );

		$rounder = new Rounder();

		foreach ( $state->input()->fees as $fee ) {
			foreach ( $this->charges( $state, $rounder, $fee ) as $amount ) {
				if ( $amount->amount->isZero() ) {
					$state->trace->record(
						TraceEntry::SKIPPED,
						array(
							'reason' => 'nothing_to_charge',
							'source' => $fee->source->toString(),
						)
					);

					continue;
				}

				$state->add( AdjustmentScope::Order, AdjustmentType::Fee, $fee->source, $fee->key, null, $amount, $fee->base, $fee->taxability );
			}
		}
	}

	/**
	 * Works out what a fee charges: a fixed fee as authored, a percentage fee on its base, once per basis the base is authored in.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState   $state   The phase's figures.
	 * @param Rounder       $rounder The rounder.
	 * @param FeeDefinition $fee     The fee.
	 * @return list<AuthoredAmount> The charges: one, or one per basis for a percentage of lines authored in both.
	 */
	private function charges( PhaseBState $state, Rounder $rounder, FeeDefinition $fee ): array {
		if ( ! $fee->amount instanceof Percentage ) {
			return array( $fee->amount );
		}

		$bases   = AdjustmentBase::SubtotalAfterDiscounts === $fee->base ? $this->linesAfterDiscounts( $state ) : array( $this->shippingAfterDiscount( $state ) );
		$charges = array();

		foreach ( $bases as $base ) {
			$subject   = $fee->source->toString() . ( count( $bases ) > 1 ? ':' . $base->basis->value : '' );
			$charges[] = $base->withAmount( $rounder->money( $state->trace, $subject, $base->amount->multiply( $fee->amount ), $base->currency() ) );
		}

		return $charges;
	}

	/**
	 * Adds up the lines after every discount, one sum per basis.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state The phase's figures.
	 * @return list<AuthoredAmount> One sum per basis the lines are authored in, in the order the bases first appear; zero and net when there is no line.
	 */
	private function linesAfterDiscounts( PhaseBState $state ): array {
		$sums = array();

		foreach ( $state->phaseA->lines as $resolved ) {
			$line  = $state->afterDiscounts[ $resolved->line->key ];
			$basis = $line->basis->value;

			$sums[ $basis ] = isset( $sums[ $basis ] ) ? $line->withAmount( $sums[ $basis ]->amount->add( $line->amount ) ) : $line;
		}

		return array() === $sums ? array( new AuthoredAmount( Money::zero( $state->input()->currency ), AmountBasis::Net ) ) : array_values( $sums );
	}

	/**
	 * Adds up the shipping adjustments: the selected rate and its discount.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state The phase's figures.
	 * @return AuthoredAmount The shipping after its discount, in the rate's basis; zero and net when there is no shipping.
	 */
	private function shippingAfterDiscount( PhaseBState $state ): AuthoredAmount {
		$sum = new AuthoredAmount( Money::zero( $state->input()->currency ), AmountBasis::Net );

		foreach ( $state->adjustments as $adjustment ) {
			if ( AdjustmentScope::Shipping === $adjustment->scope ) {
				$sum = $adjustment->authoredAmount->withAmount( $sum->amount->add( $adjustment->authoredAmount->amount ) );
			}
		}

		return $sum;
	}
}

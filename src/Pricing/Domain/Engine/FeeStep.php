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
			$amount = $fee->amount instanceof Percentage ? $this->percentOf( $state, $rounder, $fee, $fee->amount ) : $fee->amount;

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

	/**
	 * Works out a percentage fee on its base, rounded once, in the base's basis.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState   $state      The phase's figures.
	 * @param Rounder       $rounder    The rounder.
	 * @param FeeDefinition $fee        The fee.
	 * @param Percentage    $percentage Its percentage.
	 * @return AuthoredAmount The fee.
	 */
	private function percentOf( PhaseBState $state, Rounder $rounder, FeeDefinition $fee, Percentage $percentage ): AuthoredAmount {
		$base = AdjustmentBase::SubtotalAfterDiscounts === $fee->base ? $this->linesAfterDiscounts( $state ) : $this->shippingAfterDiscount( $state );

		return $base->withAmount( $rounder->money( $state->trace, $fee->source->toString(), $base->amount->multiply( $percentage ), $base->currency() ) );
	}

	/**
	 * Adds up the lines after every discount.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the lines are authored in both bases, which have no common sum.
	 *
	 * @param PhaseBState $state The phase's figures.
	 * @return AuthoredAmount The sum, in the lines' basis; zero and net when there is no line.
	 */
	private function linesAfterDiscounts( PhaseBState $state ): AuthoredAmount {
		$sum = new AuthoredAmount( Money::zero( $state->input()->currency ), AmountBasis::Net );

		foreach ( $state->phaseA->lines as $index => $resolved ) {
			$line = $state->afterDiscounts[ $resolved->line->key ];

			if ( 0 !== $index && $line->basis !== $sum->basis ) {
				throw new \LogicException( 'A percentage fee on the lines needs them all authored in one basis.' );
			}

			$sum = $line->withAmount( $sum->amount->add( $line->amount ) );
		}

		return $sum;
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

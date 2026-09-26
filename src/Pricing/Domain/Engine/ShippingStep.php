<?php
/**
 * ShippingStep: selects a quoted shipping rate and applies free shipping
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Pricing\Domain\Intent\FreeShipping;
use SEOCart\Pricing\Domain\Quote\ShippingRateQuote;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Pricing\Domain\Taxability;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Pricing\Domain\Totals\AdjustmentType;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The shipping step of the second phase: one quoted rate becomes a shipping adjustment, and free shipping a second one.
 *
 * Owns one fact: how shipping reaches the totals. The rate is the one the customer chose, or,
 * when none was chosen or the chosen one was not quoted, the cheapest, the first quoted on a tie;
 * the choice is traced. A quoted rate of zero is a rate like any other. Free shipping does not
 * change the rate: it adds a second adjustment that takes the rate off, with the promotion as its
 * source and the rate's tax class, so both stay in the audit trail and the tax on shipping nets
 * to zero. Only one can take anything off: a shipping discount never exceeds the rate.
 *
 * A cart with no destination yet, or with no lines, is charged no shipping. A cart with a
 * destination and no quoted rate cannot be priced at all: that is an error, never a free delivery.
 *
 * @since 0.1.0
 */
final class ShippingStep {

	/**
	 * Applies the shipping.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With PricingError::NoShippingRate when the destination has no quoted rate.
	 *
	 * @param PhaseBState $state The phase's figures.
	 */
	public function apply( PhaseBState $state ): void {
		$state->trace->enter( 'b3.shipping' );

		$input = $state->input();

		if ( null === $input->destination || array() === $state->phaseA->lines ) {
			$state->trace->record( TraceEntry::SKIPPED, array( 'reason' => null === $input->destination ? 'no_destination' : 'no_lines' ) );

			return;
		}

		if ( array() === $state->quotes->shippingRates ) {
			CodedException::raise( PricingError::NoShippingRate );
		}

		$rate     = $this->select( $state, $input->shippingMethodKey );
		$taxable  = Taxability::taxable( $rate->taxClass );
		$shipping = $state->add( AdjustmentScope::Shipping, AdjustmentType::Shipping, Source::shipping( $rate->methodKey ), $rate->labelKey, null, $rate->rate, null, $taxable );
		$left     = $shipping->authoredAmount->amount;

		foreach ( $state->phaseA->intents as $intent ) {
			if ( ! $intent instanceof FreeShipping ) {
				continue;
			}

			if ( $left->isZero() ) {
				$state->trace->record(
					TraceEntry::SKIPPED,
					array(
						'reason' => 'nothing_to_discount',
						'source' => $intent->source()->toString(),
					)
				);

				continue;
			}

			$state->add( AdjustmentScope::Shipping, AdjustmentType::Discount, $intent->source(), 'free_shipping', null, $rate->rate->withAmount( $left->negate() ), null, $taxable );

			$left = Money::zero( $left->currency() );
		}
	}

	/**
	 * Selects the rate: the one asked for when it was quoted, otherwise the cheapest, the first quoted on a tie.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state     The phase's figures.
	 * @param string|null $requested The method the customer chose, or null.
	 * @return ShippingRateQuote The selected rate.
	 */
	private function select( PhaseBState $state, ?string $requested ): ShippingRateQuote {
		$rates = $state->quotes->shippingRates;

		foreach ( $rates as $rate ) {
			if ( $requested === $rate->methodKey ) {
				return $this->selected( $state, $requested, $rate, 'requested' );
			}
		}

		$cheapest = $rates[0];

		foreach ( $rates as $rate ) {
			if ( $rate->rate->amount->compare( $cheapest->rate->amount ) < 0 ) {
				$cheapest = $rate;
			}
		}

		return $this->selected( $state, $requested, $cheapest, null === $requested ? 'cheapest' : 'requested_not_quoted' );
	}

	/**
	 * Traces the selection and returns the selected rate.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState       $state     The phase's figures.
	 * @param string|null       $requested The method the customer chose, or null.
	 * @param ShippingRateQuote $selected  The rate selected.
	 * @param string            $reason    Why: `requested`, `cheapest` or `requested_not_quoted`.
	 * @return ShippingRateQuote The selected rate.
	 */
	private function selected( PhaseBState $state, ?string $requested, ShippingRateQuote $selected, string $reason ): ShippingRateQuote {
		$state->trace->record(
			TraceEntry::SELECTION,
			array(
				'requested' => $requested,
				'selected'  => $selected->methodKey,
				'reason'    => $reason,
			)
		);

		return $selected;
	}
}

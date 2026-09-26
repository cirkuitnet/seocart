<?php
/**
 * TaxStep: taxes every line and every taxable adjustment, and shares each tax out to its rates
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Pricing\Domain\Totals\Adjustment;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\EffectiveRate;
use SEOCart\Tax\Domain\TaxRoundingMode;

defined( 'ABSPATH' ) || exit;

/**
 * The tax step of the second phase.
 *
 * Owns one fact: how an authored amount becomes net, tax and gross, and how that tax is shared
 * out to the rates that make it up. Tax is always worked out on the discounted amount: each line
 * after its discounts, each adjustment on its own. An adjustment that is not taxable carries no
 * tax. Every figure is a TaxedMoney, so `net + tax = gross` holds in minor units everywhere.
 *
 * The rule for an amount `a` of a tax class, with the destination's rate `r_dest` and the store's
 * reference rate `r_ref` for that class:
 *
 * - Authored net: the tax is `a × r_dest`, rounded once. An exempt customer pays `a`.
 * - Authored gross, the price paid kept fixed: the tax is taken out of `a` at `r_dest`, rounded
 *   once. An exempt customer pays `a ÷ (1 + r_dest)`: never more than a taxed customer's net.
 * - Authored gross, the net amount kept fixed: the gross at the destination is
 *   `a × (1 + r_dest) ÷ (1 + r_ref)`, rounded once, and the tax is then taken out of it at
 *   `r_dest`. The net amount `a ÷ (1 + r_ref)` is kept exact on the way. Rounding it first would
 *   change the price at home: 9.99 at 20 % would become a net 8.33 and a gross 10.00, which no
 *   merchant means. Kept exact, the gross at home is the price as authored, so both policies
 *   agree wherever the destination's rate is the reference rate. An exempt customer pays that
 *   exact net amount, rounded once.
 *
 * Rates of a class that are not compound add up to one effective rate. The tax of an amount is
 * shared out to its rates by largest remainder, in proportion to the rates, so the components'
 * taxes add up to the amount's tax exactly; each component's net is the amount the rate was
 * charged on.
 *
 * Rounded per line, each line is its own amount. Rounded per subtotal, the lines of one tax class
 * and one basis are taxed as one amount, rounded once, and the result is shared out to them by
 * largest remainder in proportion to their amounts, so the lines add back up to the group; each
 * line's tax is then shared out to its rates as above. A line-scoped discount is inside its
 * line: its figures are its share of what the discounts changed in the line, the line after
 * discounts less the line before them worked out the same way, so the two add up exactly.
 *
 * Every such share keeps the figure its amount kept as authored (keptBasis()): that figure and
 * the tax are split, and the third figure is derived. So a gross price, or a gross discount, is
 * never moved by a minor unit when it is shared out; and where the gross is a converted figure,
 * the net is split instead, so no share can carry more tax than gross.
 *
 * @since 0.1.0
 */
final class TaxStep {

	/**
	 * Taxes the lines and the adjustments.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state The phase's figures.
	 */
	public function apply( PhaseBState $state ): void {
		$state->trace->enter( 'b6.tax' );

		$rounder = new Rounder();

		$state->lineTaxes = $this->taxLines( $state, $rounder, $state->afterDiscounts, '' );

		$this->shareLineDiscounts( $state, $rounder );

		foreach ( $state->adjustments as $adjustment ) {
			if ( AdjustmentScope::Line !== $adjustment->scope ) {
				$state->adjustmentTaxes[ $adjustment->position ] = $this->taxAdjustment( $state, $rounder, $adjustment );
			}
		}

		foreach ( $state->phaseA->lines as $resolved ) {
			$key   = $resolved->line->key;
			$gross = $state->lineTaxes[ $key ]->amount->gross();

			$state->unitGross[ $key ] = $rounder->quotient( $state->trace, 'line:' . $key . ':unit_gross', $gross->toDecimal(), Decimal::ofUnscaled( $resolved->line->quantity, 0 ), $gross->currency(), true );
		}
	}

	/**
	 * Taxes the lines at the given amounts, under the calculation's tax rounding mode.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state   The phase's figures.
	 * @param Rounder     $rounder The rounder.
	 * @param array       $amounts Each line's amount, by line key.
	 * @param string      $suffix  What the trace subjects end with: empty, or `:before_discounts`.
	 * @return array<string, ScopeTax> Each line taxed, by line key, in line order.
	 *
	 * @phpstan-param array<string, AuthoredAmount> $amounts
	 */
	private function taxLines( PhaseBState $state, Rounder $rounder, array $amounts, string $suffix ): array {
		$taxes = array();

		if ( TaxRoundingMode::PerLine === $state->input()->taxRoundingMode ) {
			foreach ( $state->phaseA->lines as $resolved ) {
				$key           = $resolved->line->key;
				$taxes[ $key ] = $this->taxScope( $state, $rounder, 'line:' . $key . $suffix, $amounts[ $key ], $resolved->line->taxClass );
			}

			return $taxes;
		}

		$groups = array();

		foreach ( $state->phaseA->lines as $resolved ) {
			$groups[ $resolved->line->taxClass . ':' . $amounts[ $resolved->line->key ]->basis->value ][] = $resolved->line->key;
		}

		foreach ( $groups as $group => $keys ) {
			$taxes += $this->taxGroup( $state, $rounder, 'group:' . $group . $suffix, $keys, $amounts, $suffix );
		}

		$ordered = array();

		foreach ( $state->phaseA->lines as $resolved ) {
			$ordered[ $resolved->line->key ] = $taxes[ $resolved->line->key ];
		}

		return $ordered;
	}

	/**
	 * Taxes a group of lines of one class and one basis as one amount, and shares the result out to them.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state   The phase's figures.
	 * @param Rounder     $rounder The rounder.
	 * @param string      $subject The group's trace subject.
	 * @param array       $keys    The lines' keys, in line order.
	 * @param array       $amounts Each line's amount, by line key.
	 * @param string      $suffix  What the lines' trace subjects end with.
	 * @return array<string, ScopeTax> Each line of the group taxed, by line key.
	 *
	 * @phpstan-param list<string>                  $keys
	 * @phpstan-param array<string, AuthoredAmount> $amounts
	 */
	private function taxGroup( PhaseBState $state, Rounder $rounder, string $subject, array $keys, array $amounts, string $suffix ): array {
		$taxClass = $state->line( $keys[0] )->taxClass;
		$sum      = Money::zero( $state->input()->currency );
		$ratios   = array();

		foreach ( $keys as $key ) {
			$sum = $sum->add( $amounts[ $key ]->amount );

			$ratios[ PhaseBState::lineRef( $key ) ] = $amounts[ $key ]->amount->minorUnits();
		}

		$group  = $this->taxedAmount( $state, $rounder, $subject, $amounts[ $keys[0] ]->withAmount( $sum ), $taxClass );
		$shares = $sum->isZero() ? array() : $rounder->splitTaxed( $state->trace, $subject . ':lines', $group, $ratios, $this->keptBasis( $state, $amounts[ $keys[0] ]->basis, $taxClass ) );
		$taxes  = array();

		foreach ( $keys as $key ) {
			$share         = $shares[ PhaseBState::lineRef( $key ) ] ?? TaxedMoney::zero( $sum->currency() );
			$taxes[ $key ] = new ScopeTax( $share, $this->components( $state, $rounder, 'line:' . $key . $suffix, $share, $taxClass ) );
		}

		return $taxes;
	}

	/**
	 * Gives each line-scoped discount its share of what the discounts changed in its line.
	 *
	 * The lines are taxed once more at their amounts before discounts, the same way; a line's
	 * discounts share the difference by largest remainder, in proportion to their amounts.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state   The phase's figures.
	 * @param Rounder     $rounder The rounder.
	 */
	private function shareLineDiscounts( PhaseBState $state, Rounder $rounder ): void {
		$discounted = array_filter( $state->adjustments, static fn( Adjustment $adjustment ): bool => AdjustmentScope::Line === $adjustment->scope );

		if ( array() === $discounted ) {
			return;
		}

		$subtotals = array();

		foreach ( $state->phaseA->lines as $resolved ) {
			$subtotals[ $resolved->line->key ] = $resolved->lineAmount;
		}

		$before = $this->taxLines( $state, $rounder, $subtotals, ':before_discounts' );

		foreach ( $state->phaseA->lines as $resolved ) {
			$key         = $resolved->line->key;
			$adjustments = $state->adjustmentsOf( $key );

			if ( array() === $adjustments ) {
				continue;
			}

			$ratios = array();

			foreach ( $adjustments as $adjustment ) {
				$ratios[ $adjustment->position ] = $adjustment->authoredAmount->amount->negate()->minorUnits();
			}

			$change = $state->lineTaxes[ $key ]->amount->subtract( $before[ $key ]->amount );
			$shares = $rounder->splitTaxed( $state->trace, 'line:' . $key . ':discounts', $change, $ratios, $this->keptBasis( $state, $resolved->lineAmount->basis, $resolved->line->taxClass ) );

			foreach ( $adjustments as $adjustment ) {
				$state->adjustmentTaxes[ $adjustment->position ] = new ScopeTax( $shares[ $adjustment->position ], array() );
			}
		}
	}

	/**
	 * Taxes an adjustment outside a line: on its own when it is taxable, not at all otherwise.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state      The phase's figures.
	 * @param Rounder     $rounder    The rounder.
	 * @param Adjustment  $adjustment The adjustment.
	 * @return ScopeTax The adjustment taxed.
	 */
	private function taxAdjustment( PhaseBState $state, Rounder $rounder, Adjustment $adjustment ): ScopeTax {
		$amount   = $adjustment->authoredAmount->amount;
		$taxClass = $adjustment->taxability->taxClass;

		if ( null === $taxClass ) {
			return new ScopeTax( new TaxedMoney( $amount, Money::zero( $amount->currency() ), $amount ), array() );
		}

		return $this->taxScope( $state, $rounder, 'adjustment:' . $adjustment->position, $adjustment->authoredAmount, $taxClass );
	}

	/**
	 * Taxes one amount and shares its tax out to its rates.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState    $state   The phase's figures.
	 * @param Rounder        $rounder The rounder.
	 * @param string         $subject The trace subject.
	 * @param AuthoredAmount $amount  The amount.
	 * @param string         $taxClass Its tax class.
	 * @return ScopeTax The amount taxed, with its components.
	 */
	private function taxScope( PhaseBState $state, Rounder $rounder, string $subject, AuthoredAmount $amount, string $taxClass ): ScopeTax {
		$taxed = $this->taxedAmount( $state, $rounder, $subject, $amount, $taxClass );

		return new ScopeTax( $taxed, $this->components( $state, $rounder, $subject, $taxed, $taxClass ) );
	}

	/**
	 * Works out net, tax and gross of one amount, under the basis, the cross-zone policy and the customer's exemption.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState    $state   The phase's figures.
	 * @param Rounder        $rounder The rounder.
	 * @param string         $subject The trace subject.
	 * @param AuthoredAmount $amount  The amount.
	 * @param string         $taxClass Its tax class.
	 * @return TaxedMoney The amount's net, tax and gross.
	 */
	private function taxedAmount( PhaseBState $state, Rounder $rounder, string $subject, AuthoredAmount $amount, string $taxClass ): TaxedMoney {
		$input       = $state->input();
		$quote       = $state->quotes->taxQuote;
		$money       = $amount->amount;
		$zero        = Money::zero( $money->currency() );
		$exempt      = $input->customerTax->exempt;
		$destination = self::effectiveRate( $quote->destinationRatesFor( $taxClass ) );

		if ( AmountBasis::Net === $amount->basis ) {
			return $exempt ? new TaxedMoney( $money, $zero, $money ) : $rounder->taxOnNet( $state->trace, $subject, $money, $destination );
		}

		if ( CrossZonePolicy::FixedGross === $input->crossZonePolicy ) {
			if ( $exempt ) {
				$net = $rounder->quotient( $state->trace, $subject . ':exempt_net', $money->toDecimal(), $destination->multiplier(), $money->currency() );

				return new TaxedMoney( $net, $zero, $net );
			}

			return $rounder->taxInGross( $state->trace, $subject, $money, $destination );
		}

		$reference = self::effectiveRate( $quote->referenceRatesFor( $taxClass ) );

		if ( $exempt ) {
			$net = $rounder->quotient( $state->trace, $subject . ':exempt_net', $money->toDecimal(), $reference->multiplier(), $money->currency() );

			return new TaxedMoney( $net, $zero, $net );
		}

		$gross = $rounder->quotient( $state->trace, $subject . ':gross_at_destination', $money->toDecimal()->multiply( $destination->multiplier() ), $reference->multiplier(), $money->currency() );

		return $rounder->taxInGross( $state->trace, $subject, $gross, $destination );
	}

	/**
	 * Shares an amount's tax out to the destination's rates of its class, in proportion to the rates.
	 *
	 * An exempt customer's amount and an amount of a class no rate taxes have no component.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state   The phase's figures.
	 * @param Rounder     $rounder The rounder.
	 * @param string      $subject The amount's trace subject.
	 * @param TaxedMoney  $taxed   The amount taxed.
	 * @param string      $taxClass Its tax class.
	 * @return list<ComponentShare> One share per rate, in the quote's order.
	 */
	private function components( PhaseBState $state, Rounder $rounder, string $subject, TaxedMoney $taxed, string $taxClass ): array {
		$rates = $state->quotes->taxQuote->destinationRatesFor( $taxClass );

		if ( $state->input()->customerTax->exempt || array() === $rates ) {
			return array();
		}

		$ratios = array_map( static fn( TaxRateComponent $rate ): Decimal => $rate->rate->toFactor(), $rates );
		$shares = array();

		if ( array() === array_filter( $ratios, static fn( Decimal $ratio ): bool => ! $ratio->isZero() ) ) {
			// Every rate is zero, so the tax is zero: each rate's share of it is zero, and nothing is split.
			foreach ( $rates as $rate ) {
				$shares[] = new ComponentShare( $rate, $taxed, 0 );
			}

			return $shares;
		}

		$allocation = $rounder->split( $state->trace, $subject . ':components', $taxed->tax(), $ratios );

		foreach ( $rates as $index => $rate ) {
			$tax      = $allocation->shares[ $index ];
			$shares[] = new ComponentShare( $rate, new TaxedMoney( $taxed->net(), $tax, $taxed->net()->add( $tax ) ), $allocation->residuals[ $index ] );
		}

		return $shares;
	}

	/**
	 * Returns the figure an amount of a class keeps exactly as it was authored, which a split of it must keep too.
	 *
	 * A net amount keeps its net. A gross amount keeps its gross only where it is taxed at the rate
	 * it was priced at: under the fixed-gross policy, and under the fixed-net policy where the
	 * destination's rate is the store's own. Elsewhere its gross is not the price authored: under
	 * fixed-net sold at another rate it is the gross converted to the destination, and for an
	 * exempt customer it is the net the customer pays. There is no quoted gross to keep, so the
	 * amount is split as a net amount is. That matters: a converted gross and its tax are each
	 * rounded, so splitting the gross could leave a share with more tax than gross, which is a
	 * negative net. The net and the tax each have the sign of the whole, so a gross derived from
	 * their shares does too.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state    The phase's figures.
	 * @param AmountBasis $authored The basis the amount was authored in.
	 * @param string      $taxClass The amount's tax class.
	 * @return AmountBasis Net, or gross where the gross is the price authored.
	 */
	private function keptBasis( PhaseBState $state, AmountBasis $authored, string $taxClass ): AmountBasis {
		$input = $state->input();

		if ( AmountBasis::Net === $authored || $input->customerTax->exempt ) {
			return AmountBasis::Net;
		}

		if ( CrossZonePolicy::FixedGross === $input->crossZonePolicy ) {
			return AmountBasis::Gross;
		}

		$quote = $state->quotes->taxQuote;
		$home  = self::effectiveRate( $quote->destinationRatesFor( $taxClass ) )->multiplier()->equals( self::effectiveRate( $quote->referenceRatesFor( $taxClass ) )->multiplier() );

		return $home ? AmountBasis::Gross : AmountBasis::Net;
	}

	/**
	 * Composes the rates of a class into one.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a rate is compound: rates are composed by adding them.
	 *
	 * @param TaxRateComponent[] $rates The rates.
	 * @return EffectiveRate Their sum.
	 *
	 * @phpstan-param list<TaxRateComponent> $rates
	 */
	private static function effectiveRate( array $rates ): EffectiveRate {
		foreach ( $rates as $rate ) {
			if ( $rate->isCompound ) {
				throw new \LogicException( 'A compound tax rate cannot be applied: the rates of a class are composed by adding them.' );
			}
		}

		return EffectiveRate::additive( ...array_map( static fn( TaxRateComponent $rate ) => $rate->rate, $rates ) );
	}
}

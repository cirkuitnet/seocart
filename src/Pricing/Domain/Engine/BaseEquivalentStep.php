<?php
/**
 * BaseEquivalentStep: gives every figure its twin in the base currency, so that the twins add up
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * The last step of the second phase: the base-currency figures, converted in pools, never row by row.
 *
 * Owns one fact: how a figure in the cart's currency gets its base-currency twin. Converting
 * each row on its own would round each row on its own, and the rows would not add up to the
 * converted total. So the figures that add up to a total are pooled first: the positive nets,
 * the negative nets, the positive taxes and the negative taxes, each converted once at the
 * frozen rate, and each pool is shared back to its members by largest remainder in proportion
 * to their amounts. A member's base gross is its base net plus its base tax, and the summary's
 * base figures are sums of members, so every base figure adds up exactly.
 *
 * Two families are pooled on their own, because each adds up to something of its own: the lines
 * and the adjustments outside a line, whose sum is the grand total; and the line-scoped
 * discounts, which are inside their lines.
 *
 * An authored amount is not converted on its own: it takes the base twin of the taxed figure it
 * equals in the cart's currency. A net-authored amount is its taxed net, so its base twin is its
 * base net; a gross-authored amount that is its taxed gross takes its base gross. These twins are
 * the base amounts an order stores beside each line and adjustment, and the summary's base
 * subtotal, discount, shipping and fee totals are their sums. A line's twin is its amount after
 * discounts; its base discount is the sum of its discounts' twins, and its base subtotal is its
 * twin less its base discount. So the base figures add up across the summary and across each
 * line: where every amount is net-authored, the base subtotal, discounts, shipping and fees add
 * up to the base net, exactly as they do in the cart's currency.
 *
 * One exception keeps a conversion of its own: an authored amount that equals none of its taxed
 * figures. That is a gross price under the policy that keeps the net amount fixed, sold where
 * the rate differs from the store's own, whose gross at the destination is not the price
 * authored; and a gross price of which an exempt customer pays only the net. Such amounts are
 * pooled together, positive and negative apart, converted once and shared back.
 *
 * A component's base tax is its share of its owner's base tax, in proportion to the components'
 * taxes; its base net is its owner's base net, as its net is its owner's net. With the identity
 * context of a base-currency cart, every pool converts to itself and shares back exactly, so the
 * base figures are the cart's figures, through the same code.
 *
 * @since 0.1.0
 */
final class BaseEquivalentStep {

	/**
	 * Works out the base-currency twins.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state The phase's figures.
	 */
	public function apply( PhaseBState $state ): void {
		$state->trace->enter( 'b8.base' );

		$rounder = new Rounder();
		$totals  = array();
		$inside  = array();

		foreach ( $state->phaseA->lines as $resolved ) {
			$totals[ PhaseBState::lineRef( $resolved->line->key ) ] = $state->lineTaxes[ $resolved->line->key ]->amount;
		}

		foreach ( $state->adjustments as $adjustment ) {
			$ref   = PhaseBState::adjustmentRef( $adjustment->position );
			$taxed = $state->adjustmentTaxes[ $adjustment->position ]->amount;

			if ( AdjustmentScope::Line === $adjustment->scope ) {
				$inside[ $ref ] = $taxed;
			} else {
				$totals[ $ref ] = $taxed;
			}
		}

		$state->bases         = $this->taxedToBase( $state, $rounder, 'totals', $totals ) + $this->taxedToBase( $state, $rounder, 'line_discounts', $inside );
		$state->authoredBases = $this->authoredToBase( $state, $rounder );

		foreach ( $state->phaseA->lines as $resolved ) {
			$this->componentsToBase( $state, $rounder, PhaseBState::lineRef( $resolved->line->key ), $state->lineTaxes[ $resolved->line->key ]->components );
		}

		foreach ( $state->adjustments as $adjustment ) {
			$this->componentsToBase( $state, $rounder, PhaseBState::adjustmentRef( $adjustment->position ), $state->adjustmentTaxes[ $adjustment->position ]->components );
		}
	}

	/**
	 * Gives every authored amount its base twin: the base figure of the taxed figure it equals, or else a share of its own pool.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state   The phase's figures, with the taxed figures' base twins.
	 * @param Rounder     $rounder The rounder.
	 * @return array<string, Money> The twins, by reference: each line's amount after discounts, and each adjustment's amount.
	 */
	private function authoredToBase( PhaseBState $state, Rounder $rounder ): array {
		$twins = array();
		$apart = array();

		foreach ( $state->phaseA->lines as $resolved ) {
			$ref    = PhaseBState::lineRef( $resolved->line->key );
			$amount = $state->afterDiscounts[ $resolved->line->key ];
			$twin   = self::twinOf( $amount, $state->lineTaxes[ $resolved->line->key ]->amount, $state->bases[ $ref ] );

			if ( null === $twin ) {
				$apart[ $ref ] = $amount->amount;
			} else {
				$twins[ $ref ] = $twin;
			}
		}

		foreach ( $state->adjustments as $adjustment ) {
			$ref  = PhaseBState::adjustmentRef( $adjustment->position );
			$twin = self::twinOf( $adjustment->authoredAmount, $state->adjustmentTaxes[ $adjustment->position ]->amount, $state->bases[ $ref ] );

			if ( null === $twin ) {
				$apart[ $ref ] = $adjustment->authoredAmount->amount;
			} else {
				$twins[ $ref ] = $twin;
			}
		}

		return $twins + $this->pooled( $state, $rounder, 'authored_apart', $apart );
	}

	/**
	 * Returns the base twin of an authored amount when it equals one of its taxed figures.
	 *
	 * @since 0.1.0
	 *
	 * @param AuthoredAmount $authored The authored amount.
	 * @param TaxedMoney     $taxed    Its taxed figures, in the cart's currency.
	 * @param TaxedMoney     $base     Their base twins.
	 * @return Money|null The base net of a net amount, or the base gross of a gross amount that is its taxed gross; otherwise null.
	 */
	private static function twinOf( AuthoredAmount $authored, TaxedMoney $taxed, TaxedMoney $base ): ?Money {
		if ( AmountBasis::Net === $authored->basis ) {
			return $authored->amount->equals( $taxed->net() ) ? $base->net() : null;
		}

		return $authored->amount->equals( $taxed->gross() ) ? $base->gross() : null;
	}

	/**
	 * Converts a family of taxed figures: nets and taxes pooled apart, each member's gross their sum.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state   The phase's figures.
	 * @param Rounder     $rounder The rounder.
	 * @param string      $family  The family's name, for the trace.
	 * @param array       $members The taxed figures, by reference.
	 * @return array<string, TaxedMoney> Their base twins, by reference.
	 *
	 * @phpstan-param array<string, TaxedMoney> $members
	 */
	private function taxedToBase( PhaseBState $state, Rounder $rounder, string $family, array $members ): array {
		$nets  = $this->pooled( $state, $rounder, $family . ':net', array_map( static fn( TaxedMoney $taxed ): Money => $taxed->net(), $members ) );
		$taxes = $this->pooled( $state, $rounder, $family . ':tax', array_map( static fn( TaxedMoney $taxed ): Money => $taxed->tax(), $members ) );
		$bases = array();

		foreach ( $nets as $ref => $net ) {
			$bases[ $ref ] = new TaxedMoney( $net, $taxes[ $ref ], $net->add( $taxes[ $ref ] ) );
		}

		return $bases;
	}

	/**
	 * Converts amounts in two pools, the positive and the negative, each once, and shares each back to its members.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state   The phase's figures.
	 * @param Rounder     $rounder The rounder.
	 * @param string      $subject The pools' name, for the trace.
	 * @param array       $members The amounts, by reference.
	 * @return array<string, Money> Their base twins, by reference.
	 *
	 * @phpstan-param array<string, Money> $members
	 */
	private function pooled( PhaseBState $state, Rounder $rounder, string $subject, array $members ): array {
		$context = $state->input()->conversionContext;
		$bases   = array_map( static fn(): Money => Money::zero( $context->baseCurrency() ), $members );

		foreach ( array( 'positive', 'negative' ) as $name ) {
			$pool   = Money::zero( $context->quoteCurrency() );
			$ratios = array();

			foreach ( $members as $ref => $amount ) {
				$inPool         = 'positive' === $name ? $amount->minorUnits() > 0 : $amount->isNegative();
				$ratios[ $ref ] = $inPool ? self::magnitude( $amount ) : 0;
				$pool           = $inPool ? $pool->add( $amount ) : $pool;
			}

			if ( $pool->isZero() ) {
				continue;
			}

			$converted = $rounder->toBase( $state->trace, $subject . ':' . $name, $pool, $context );

			foreach ( $rounder->split( $state->trace, $subject . ':' . $name, $converted, $ratios )->shares as $ref => $share ) {
				$bases[ $ref ] = $bases[ $ref ]->add( $share );
			}
		}

		return $bases;
	}

	/**
	 * Gives an owner's components their base twins: shares of the owner's base tax, on the owner's base net.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state      The phase's figures.
	 * @param Rounder     $rounder    The rounder.
	 * @param string      $ref        The owner's reference.
	 * @param array       $components The owner's components.
	 *
	 * @phpstan-param list<ComponentShare> $components
	 */
	private function componentsToBase( PhaseBState $state, Rounder $rounder, string $ref, array $components ): void {
		if ( array() === $components ) {
			return;
		}

		$owner  = $state->bases[ $ref ];
		$ratios = array_map( static fn( ComponentShare $component ): int => self::magnitude( $component->amount->tax() ), $components );
		$taxes  = array() === array_filter( $ratios ) ? array_map( static fn(): Money => Money::zero( $owner->currency() ), $ratios ) : $rounder->split( $state->trace, $ref . ':components', $owner->tax(), $ratios )->shares;

		$state->componentBases[ $ref ] = array_map( static fn( Money $tax ): TaxedMoney => new TaxedMoney( $owner->net(), $tax, $owner->net()->add( $tax ) ), array_values( $taxes ) );
	}

	/**
	 * Returns the size of an amount, whatever its sign, as a ratio of a split.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $amount The amount.
	 * @return int Its minor units without the sign.
	 */
	private static function magnitude( Money $amount ): int {
		return ( $amount->isNegative() ? $amount->negate() : $amount )->minorUnits();
	}
}

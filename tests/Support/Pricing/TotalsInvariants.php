<?php
/**
 * TotalsInvariants: the arithmetic every set of totals must satisfy, whatever its scenario
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Pricing\Domain\Totals\TaxComponent;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

/**
 * Checks that the figures of a calculation add up, in the cart's currency and in the base currency.
 *
 * Owns one fact: the invariants of a result, stated once for every scenario test:
 *
 * - `net + tax = gross` for every line, adjustment, component and summary figure;
 * - an amount's components add up to its tax;
 * - the lines and the adjustments outside a line add up to the summary's net, tax and grand;
 * - a line's subtotal and its discounts add up to its authored amount after discounts, and its
 *   discount is the sum of its discounts;
 * - no line, adjustment outside a discount, or summary figure goes below zero;
 * - the base-currency figures add up as the cart's do (baseProblems());
 * - no line, adjustment or component has a figure against its scope's sign (signProblems()).
 *
 * @since 0.1.0
 */
final class TotalsInvariants {

	/**
	 * Lists every invariant the totals break.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return list<string> What is broken; none when every invariant holds.
	 */
	public static function problems( Totals $totals ): array {
		$problems = array();
		$sum      = array(
			'cart' => TaxedMoney::zero( $totals->currency ),
			'base' => TaxedMoney::zero( $totals->baseCurrency ),
		);

		foreach ( $totals->lines as $line ) {
			$what     = 'line ' . $line->line->key;
			$problems = array_merge( $problems, self::adds( $what, $line->amount ), self::adds( $what . ' (base)', $line->base ), self::componentsAdd( $what, $line->amount, $line->base, $line->components ) );
			$discount = Money::zero( $totals->currency );

			foreach ( $totals->adjustments as $adjustment ) {
				if ( $line->line->key === $adjustment->adjustment->lineKey ) {
					$discount = $discount->add( $adjustment->adjustment->authoredAmount->amount );
				}
			}

			if ( ! $discount->equals( $line->lineDiscount ) ) {
				$problems[] = "{$what}: its discount is not the sum of its discounts";
			}

			if ( $line->amount->gross()->isNegative() || $line->amount->net()->isNegative() ) {
				$problems[] = "{$what}: below zero";
			}

			$sum['cart'] = $sum['cart']->add( $line->amount );
			$sum['base'] = $sum['base']->add( $line->base );
		}

		foreach ( $totals->adjustments as $adjustment ) {
			$what     = 'adjustment ' . $adjustment->adjustment->position . ' (' . $adjustment->source()->toString() . ')';
			$problems = array_merge( $problems, self::adds( $what, $adjustment->amount ), self::adds( $what . ' (base)', $adjustment->base ), self::componentsAdd( $what, $adjustment->amount, $adjustment->base, $adjustment->components ) );

			if ( AdjustmentScope::Line !== $adjustment->adjustment->scope ) {
				$sum['cart'] = $sum['cart']->add( $adjustment->amount );
				$sum['base'] = $sum['base']->add( $adjustment->base );
			}
		}

		$summary = $totals->summary;

		foreach ( array(
			'cart' => new TaxedMoney( $summary->net, $summary->tax, $summary->grand ),
			'base' => new TaxedMoney( $summary->baseNet, $summary->baseTax, $summary->baseGrand ),
		) as $side => $figures ) {
			if ( ! $figures->equals( $sum[ $side ] ) ) {
				$problems[] = "summary ({$side}): the lines and the adjustments outside a line do not add up to it";
			}
		}

		if ( $summary->grand->isNegative() || $summary->baseGrand->isNegative() ) {
			$problems[] = 'summary: the grand total is below zero';
		}

		return array_merge( $problems, self::baseProblems( $totals ), self::signProblems( $totals ) );
	}

	/**
	 * Lists every share with a figure against the sign of its scope.
	 *
	 * A line's scope is its authored amount after discounts; an adjustment's is its authored
	 * amount. Where the scope is zero or more, the net, the tax and the gross of the share and of
	 * each of its components are zero or more; where it is below zero, they are zero or less. A
	 * split that rounds one figure apart from another could break this: a share with more tax
	 * than gross has a negative net.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return list<string> The figures of the wrong sign; none when every figure has its scope's.
	 */
	public static function signProblems( Totals $totals ): array {
		$problems = array();

		foreach ( $totals->lines as $line ) {
			$problems = array_merge( $problems, self::againstSign( 'line ' . $line->line->key, $line->lineSubtotal->amount->add( $line->lineDiscount ), $line->amount, $line->components ) );
		}

		foreach ( $totals->adjustments as $adjustment ) {
			$problems = array_merge( $problems, self::againstSign( 'adjustment ' . $adjustment->adjustment->position, $adjustment->adjustment->authoredAmount->amount, $adjustment->amount, $adjustment->components ) );
		}

		return $problems;
	}

	/**
	 * Lists the figures of a share and its components that are against the sign of its scope.
	 *
	 * @since 0.1.0
	 *
	 * @param string         $what       What the share is.
	 * @param Money          $scope      The authored amount it was worked out from.
	 * @param TaxedMoney     $share      Its figures.
	 * @param TaxComponent[] $components Its components.
	 * @return list<string> The figures of the wrong sign.
	 *
	 * @phpstan-param list<TaxComponent> $components
	 */
	private static function againstSign( string $what, Money $scope, TaxedMoney $share, array $components ): array {
		$problems = array();
		$figures  = array( $what => $share );

		foreach ( $components as $component ) {
			$figures[ $component->componentKey ] = $component->amount;
		}

		foreach ( $figures as $name => $taxed ) {
			foreach ( array(
				'net'   => $taxed->net(),
				'tax'   => $taxed->tax(),
				'gross' => $taxed->gross(),
			) as $figure => $money ) {
				if ( $scope->isNegative() ? $money->minorUnits() > 0 : $money->isNegative() ) {
					$problems[] = sprintf( '%s: its %s is %s, against the sign of its scope of %s', $name, $figure, $money->toDecimal()->toString(), $scope->toDecimal()->toString() );
				}
			}
		}

		return $problems;
	}

	/**
	 * Lists every way the base-currency figures fail to add up across the summary and each line.
	 *
	 * - The base net and the base tax add up to the base grand total.
	 * - A line whose amount after discounts is its net (a net price) or its gross (a gross price)
	 *   has a base subtotal and a base discount that add up to that base figure.
	 * - Where every line and adjustment is authored net, the base subtotal, discounts, shipping
	 *   and fees add up to the base net, as they add up to the net in the cart's currency.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return list<string> What does not add up; none when everything does.
	 */
	public static function baseProblems( Totals $totals ): array {
		$problems = array();
		$summary  = $totals->summary;
		$allNet   = true;

		if ( ! $summary->baseNet->add( $summary->baseTax )->equals( $summary->baseGrand ) ) {
			$problems[] = 'summary (base): net plus tax is not the grand total';
		}

		foreach ( $totals->lines as $line ) {
			$after  = $line->lineSubtotal->amount->add( $line->lineDiscount );
			$net    = AmountBasis::Net === $line->lineSubtotal->basis;
			$twin   = $net ? $line->base->net() : $line->base->gross();
			$allNet = $allNet && $net;

			if ( $after->equals( $net ? $line->amount->net() : $line->amount->gross() ) && ! $line->baseSubtotal->add( $line->baseDiscount )->equals( $twin ) ) {
				$problems[] = 'line ' . $line->line->key . ' (base): its subtotal and discount do not add up to its ' . ( $net ? 'net' : 'gross' );
			}
		}

		foreach ( $totals->adjustments as $adjustment ) {
			$allNet = $allNet && AmountBasis::Net === $adjustment->adjustment->authoredAmount->basis;
		}

		$header = $summary->baseSubtotal->add( $summary->baseDiscountTotal )->add( $summary->baseShippingTotal )->add( $summary->baseFeeTotal );

		if ( $allNet && ! $header->equals( $summary->baseNet ) ) {
			$problems[] = sprintf( 'summary (base): subtotal, discounts, shipping and fees come to %s, but the net is %s', $header->toDecimal()->toString(), $summary->baseNet->toDecimal()->toString() );
		}

		return $problems;
	}

	/**
	 * Checks `net + tax = gross`.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $what  What the figures are.
	 * @param TaxedMoney $taxed The figures.
	 * @return list<string> The problem, if any.
	 */
	private static function adds( string $what, TaxedMoney $taxed ): array {
		return $taxed->net()->add( $taxed->tax() )->equals( $taxed->gross() ) ? array() : array( "{$what}: net plus tax is not gross" );
	}

	/**
	 * Checks that an amount's components add up to its tax, in both currencies.
	 *
	 * @since 0.1.0
	 *
	 * @param string         $what       What the amount is.
	 * @param TaxedMoney     $amount     The amount.
	 * @param TaxedMoney     $base       Its base twin.
	 * @param TaxComponent[] $components Its components.
	 * @return list<string> The problems.
	 *
	 * @phpstan-param list<TaxComponent> $components
	 */
	private static function componentsAdd( string $what, TaxedMoney $amount, TaxedMoney $base, array $components ): array {
		if ( array() === $components ) {
			return array();
		}

		$problems = array();
		$tax      = Money::zero( $amount->currency() );
		$baseTax  = Money::zero( $base->currency() );

		foreach ( $components as $component ) {
			$problems = array_merge( $problems, self::adds( $component->componentKey, $component->amount ), self::adds( $component->componentKey . ' (base)', $component->base ) );
			$tax      = $tax->add( $component->amount->tax() );
			$baseTax  = $baseTax->add( $component->base->tax() );

			if ( ! $component->amount->net()->equals( $amount->net() ) || ! $component->base->net()->equals( $base->net() ) ) {
				$problems[] = "{$component->componentKey}: its net is not its owner's";
			}

			if ( abs( $component->residualMinor ) > 1 ) {
				$problems[] = "{$component->componentKey}: a residual of more than one minor unit";
			}
		}

		if ( ! $tax->equals( $amount->tax() ) || ! $baseTax->equals( $base->tax() ) ) {
			$problems[] = "{$what}: its components do not add up to its tax";
		}

		return $problems;
	}
}

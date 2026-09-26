<?php
/**
 * DiscountStep: applies the promotions' discounts to the lines
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\Intent\DiscountLines;
use SEOCart\Pricing\Domain\Intent\DiscountOrder;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Pricing\Domain\Taxability;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Pricing\Domain\Totals\AdjustmentType;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The first step of the second phase: every discount becomes line-scoped adjustments.
 *
 * Owns one fact: how discounts reach the lines. Intents apply in the order given, each on what the
 * lines cost after the discounts before it.
 *
 * - A percentage off is worked out on each line and rounded once per line.
 * - An amount off the order is shared across the lines by largest remainder, in proportion to
 *   what they cost, and each line's share is an adjustment of its own with the promotion's
 *   source. So tax, worked out per line afterwards, sees each line's discounted amount, and a
 *   refund of one line gives back that line's share.
 *
 * Nothing goes below zero: a line's discount is capped at what the line costs, and an amount off
 * the order at what the lines cost together. A discount that comes to nothing makes no adjustment.
 *
 * @since 0.1.0
 */
final class DiscountStep {

	/**
	 * Applies the discounts.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state The phase's figures.
	 */
	public function apply( PhaseBState $state ): void {
		$state->trace->enter( 'b1.discounts' );

		$rounder = new Rounder();

		foreach ( $state->phaseA->intents as $intent ) {
			if ( $intent instanceof DiscountLines ) {
				$this->discountLines( $state, $rounder, $intent );
			} elseif ( $intent instanceof DiscountOrder ) {
				$this->discountOrder( $state, $rounder, $intent );
			}
		}
	}

	/**
	 * Takes a percentage off every line.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState   $state   The phase's figures.
	 * @param Rounder       $rounder The rounder.
	 * @param DiscountLines $intent  The intent.
	 */
	private function discountLines( PhaseBState $state, Rounder $rounder, DiscountLines $intent ): void {
		foreach ( $state->phaseA->lines as $resolved ) {
			$key      = $resolved->line->key;
			$current  = $state->afterDiscounts[ $key ]->amount;
			$discount = $rounder->money( $state->trace, 'line:' . $key . ':' . $intent->source()->toString(), $current->multiply( $intent->percentage ), $current->currency() );

			$this->discountLine( $state, $key, $intent->source(), self::atMost( $discount, $current ) );
		}
	}

	/**
	 * Takes an amount off the order, shared across the lines by what they cost now.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a line to share it with is authored in another basis than the amount.
	 *
	 * @param PhaseBState   $state   The phase's figures.
	 * @param Rounder       $rounder The rounder.
	 * @param DiscountOrder $intent  The intent.
	 */
	private function discountOrder( PhaseBState $state, Rounder $rounder, DiscountOrder $intent ): void {
		$total  = Money::zero( $state->input()->currency );
		$ratios = array();

		foreach ( $state->phaseA->lines as $resolved ) {
			$current = $state->afterDiscounts[ $resolved->line->key ];

			if ( ! $current->amount->isZero() && $current->basis !== $intent->amount->basis ) {
				throw new \LogicException( 'An amount off the order is shared only across lines authored in its own basis.' );
			}

			$total = $total->add( $current->amount );
			$ratios[ PhaseBState::lineRef( $resolved->line->key ) ] = $current->amount->minorUnits();
		}

		$amount = self::atMost( $intent->amount->amount, $total );

		if ( $amount->isZero() ) {
			$state->trace->record(
				TraceEntry::SKIPPED,
				array(
					'reason' => 'nothing_to_discount',
					'source' => $intent->source()->toString(),
				)
			);

			return;
		}

		$shares = $rounder->split( $state->trace, 'order:' . $intent->source()->toString(), $amount, $ratios )->shares;

		foreach ( $state->phaseA->lines as $resolved ) {
			$this->discountLine( $state, $resolved->line->key, $intent->source(), $shares[ PhaseBState::lineRef( $resolved->line->key ) ] );
		}
	}

	/**
	 * Takes a discount off one line: one negative line-scoped adjustment, in the line's basis and tax class.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseBState $state    The phase's figures.
	 * @param string      $key      The line's key.
	 * @param Source      $source   What the discount comes from.
	 * @param Money       $discount The discount, zero or more, at most what the line costs now.
	 */
	private function discountLine( PhaseBState $state, string $key, Source $source, Money $discount ): void {
		if ( $discount->isZero() ) {
			return;
		}

		$current = $state->afterDiscounts[ $key ];

		$state->add( AdjustmentScope::Line, AdjustmentType::Discount, $source, AdjustmentType::Discount->value, $key, $current->withAmount( $discount->negate() ), null, Taxability::taxable( $state->line( $key )->taxClass ) );

		$state->afterDiscounts[ $key ] = $current->withAmount( $current->amount->subtract( $discount ) );
	}

	/**
	 * Returns the smaller of two amounts.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $amount The amount.
	 * @param Money $cap    The most it may be.
	 * @return Money The amount, or the cap when the amount is larger.
	 */
	private static function atMost( Money $amount, Money $cap ): Money {
		return $amount->compare( $cap ) > 0 ? $cap : $amount;
	}
}

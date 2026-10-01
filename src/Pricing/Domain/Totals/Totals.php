<?php
/**
 * Totals: the immutable result of a calculation, which an order snapshots
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\InstantFormat;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;

defined( 'ABSPATH' ) || exit;

/**
 * Every figure of one calculation: its lines, its adjustments, their tax components, the summary and the trace.
 *
 * Owns one fact: the totals of a cart or an order, and it is the only place their summary is
 * added up. Anything that shows or stores a total reads it from here and computes nothing: the
 * Store API maps toArray(), and an order copies the lines, adjustments, components, summary and
 * trace into its rows as they are.
 *
 * The grand total is the lines, which already include their discounts, plus the adjustments
 * outside a line: shipping, its discount, and fees. It is never negative: discounts are capped
 * where they are made. In this version nothing is tendered, so the amount due is the grand total.
 * Every summary figure is a net amount, so the summary adds up whatever basis each amount was
 * authored in; the authored amounts stay on the lines and adjustments, and a gross figure for
 * display is read from their taxed amounts.
 *
 * @since 0.1.0
 */
final readonly class Totals {

	/**
	 * The summary figures, added up from the lines and the adjustments.
	 *
	 * @since 0.1.0
	 *
	 * @var TotalsSummary
	 */
	public TotalsSummary $summary;

	/**
	 * Holds the result and adds up its summary.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency           $currency          The currency of the calculation.
	 * @param Currency           $baseCurrency      The store's base currency.
	 * @param ConversionContext  $conversionContext The frozen rate the base figures were converted at.
	 * @param CrossZonePolicy    $crossZonePolicy   The cross-zone policy the lines were taxed under.
	 * @param TaxRoundingMode    $taxRoundingMode   Where the tax was rounded.
	 * @param \DateTimeImmutable $calculatedAt      When the calculation was asked for.
	 * @param array              $lines             The lines, in cart order.
	 * @param array              $adjustments       The adjustments, in the order they were made.
	 * @param CalculationTrace   $trace             How the figures were reached.
	 *
	 * @phpstan-param list<TotalsLine>       $lines
	 * @phpstan-param list<TotalsAdjustment> $adjustments
	 */
	public function __construct(
		public Currency $currency,
		public Currency $baseCurrency,
		public ConversionContext $conversionContext,
		public CrossZonePolicy $crossZonePolicy,
		public TaxRoundingMode $taxRoundingMode,
		public \DateTimeImmutable $calculatedAt,
		public array $lines,
		public array $adjustments,
		public CalculationTrace $trace
	) {
		$this->summary = $this->summarise();
	}

	/**
	 * Returns every tax component: each line's, in line order, then each adjustment's.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TaxComponent> The components.
	 */
	public function components(): array {
		$components = array();

		foreach ( $this->lines as $line ) {
			$components = array_merge( $components, $line->components );
		}

		foreach ( $this->adjustments as $adjustment ) {
			$components = array_merge( $components, $adjustment->components );
		}

		return $components;
	}

	/**
	 * Returns what the customer owes: the grand total, since nothing is tendered yet.
	 *
	 * @since 0.1.0
	 *
	 * @return Money The amount due.
	 */
	public function amountDue(): Money {
		return $this->summary->grand;
	}

	/**
	 * Returns the totals as an array of scalars and lists, the form the Store API answers with.
	 *
	 * The trace is not in it: an order stores it on its own, from the trace's toArray().
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The totals.
	 */
	public function toArray(): array {
		return array(
			'currency'           => $this->currency->code(),
			'base_currency'      => $this->baseCurrency->code(),
			'conversion_context' => $this->conversionContext->fingerprint(),
			'rate_version'       => $this->conversionContext->sourceVersion(),
			'cross_zone_policy'  => $this->crossZonePolicy->value,
			'tax_rounding_mode'  => $this->taxRoundingMode->value,
			'calculated_at'      => InstantFormat::of( $this->calculatedAt ),
			'lines'              => array_map( static fn( TotalsLine $line ): array => $line->toArray(), $this->lines ),
			'adjustments'        => array_map( static fn( TotalsAdjustment $adjustment ): array => $adjustment->toArray(), $this->adjustments ),
			'summary'            => $this->summary->toArray(),
			'amount_due_minor'   => $this->amountDue()->minorUnits(),
		);
	}

	/**
	 * Returns a taxed amount's three figures in minor units, under a prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $taxed  The taxed amount.
	 * @param string     $prefix Optional. What each key starts with, such as `base_`. Default empty.
	 * @return array<string, int> `{prefix}net_minor`, `{prefix}tax_minor` and `{prefix}gross_minor`.
	 */
	public static function figures( TaxedMoney $taxed, string $prefix = '' ): array {
		return array(
			$prefix . 'net_minor'   => $taxed->net()->minorUnits(),
			$prefix . 'tax_minor'   => $taxed->tax()->minorUnits(),
			$prefix . 'gross_minor' => $taxed->gross()->minorUnits(),
		);
	}

	/**
	 * Adds up the summary figures, in both currencies.
	 *
	 * Every figure is a sum of taxed net amounts, never of authored ones: an amount authored net
	 * and one authored gross have no common sum, and a store with gross prices and a net shipping
	 * rate is an ordinary one. The discount, shipping and fee figures are the nets of the
	 * adjustments of each type, discounts below zero. The subtotal is the lines' net before their
	 * discounts: the lines' net less the nets of the discounts inside them, which a line's figures
	 * already include. So the subtotal, discounts, shipping and fees add up to the net, and the net
	 * and the tax to the grand total. The base figures are the same sums of the same members' base
	 * nets, so they add up the same way.
	 *
	 * @since 0.1.0
	 *
	 * @return TotalsSummary The summary.
	 */
	private function summarise(): TotalsSummary {
		$lines     = TaxedMoney::zero( $this->currency );
		$baseLines = TaxedMoney::zero( $this->baseCurrency );
		$inLines   = array( Money::zero( $this->currency ), Money::zero( $this->baseCurrency ) );
		$byType    = array();

		foreach ( AdjustmentType::cases() as $type ) {
			$byType[ $type->value ] = array( Money::zero( $this->currency ), Money::zero( $this->baseCurrency ) );
		}

		foreach ( $this->lines as $line ) {
			$lines     = $lines->add( $line->amount );
			$baseLines = $baseLines->add( $line->base );
		}

		$taxed     = $lines;
		$baseTaxed = $baseLines;

		foreach ( $this->adjustments as $adjustment ) {
			$type = $adjustment->adjustment->type->value;

			$byType[ $type ] = array(
				$byType[ $type ][0]->add( $adjustment->amount->net() ),
				$byType[ $type ][1]->add( $adjustment->base->net() ),
			);

			if ( AdjustmentScope::Line === $adjustment->adjustment->scope ) {
				$inLines = array( $inLines[0]->add( $adjustment->amount->net() ), $inLines[1]->add( $adjustment->base->net() ) );
			} else {
				$taxed     = $taxed->add( $adjustment->amount );
				$baseTaxed = $baseTaxed->add( $adjustment->base );
			}
		}

		return new TotalsSummary(
			$lines->net()->subtract( $inLines[0] ),
			AmountBasis::Net->value,
			$byType[ AdjustmentType::Discount->value ][0],
			$byType[ AdjustmentType::Shipping->value ][0],
			$byType[ AdjustmentType::Fee->value ][0],
			$taxed->net(),
			$taxed->tax(),
			$taxed->gross(),
			$baseLines->net()->subtract( $inLines[1] ),
			$byType[ AdjustmentType::Discount->value ][1],
			$byType[ AdjustmentType::Shipping->value ][1],
			$byType[ AdjustmentType::Fee->value ][1],
			$baseTaxed->net(),
			$baseTaxed->tax(),
			$baseTaxed->gross()
		);
	}
}

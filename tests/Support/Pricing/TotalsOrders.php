<?php
/**
 * TotalsOrders: the order document a test places from a calculation's totals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Order\Domain\AmountBasis;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Domain\NewOrderAdjustment;
use SEOCart\Order\Domain\NewOrderLine;
use SEOCart\Order\Domain\NewTaxComponent;
use SEOCart\Order\Domain\TotalsSnapshot;
use SEOCart\Pricing\Domain\Totals\TaxComponent;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Domain\Totals\TotalsAdjustment;
use SEOCart\Pricing\Domain\Totals\TotalsLine;
use SEOCart\Tests\Support\Order\NewOrders;

/**
 * Maps a calculation's totals onto an order document, figure for figure, for tests that place what was calculated.
 *
 * Owns one fact: how a test turns totals into an order before placement exists to do it. Every
 * amount is the calculation's own, copied; nothing is added up or converted. What the totals do
 * not hold is the fixture order's: its contact, its addresses, a product, SKU and title named after
 * each line's variant, prices entered net and no stock hold.
 *
 * @since 0.1.0
 */
final class TotalsOrders {

	/**
	 * Builds the document.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return NewOrder The document, at the totals' conversion context.
	 */
	public static function document( Totals $totals ): NewOrder {
		return NewOrders::document(
			$totals->conversionContext,
			array_map( array( self::class, 'line' ), $totals->lines ),
			array_map( array( self::class, 'adjustment' ), $totals->adjustments ),
			self::snapshot( $totals ),
			null,
			null
		);
	}

	/**
	 * Copies a line.
	 *
	 * @since 0.1.0
	 *
	 * @param TotalsLine $line The line.
	 * @return NewOrderLine The order line.
	 */
	private static function line( TotalsLine $line ): NewOrderLine {
		$input = $line->line;

		return new NewOrderLine(
			key: $input->key,
			variantId: $input->variantId,
			productId: $input->variantId,
			sku: 'SKU-' . $input->variantId,
			title: 'Variant ' . $input->variantId,
			variantLabel: '',
			quantity: $input->quantity,
			unitAmountBasis: AmountBasis::from( $input->unitPrice->basis->value ),
			unitPrice: $input->unitPrice->amount,
			unitPriceGross: $line->unitGrossForDisplay,
			unitCompareAt: null,
			lineSubtotal: $line->lineSubtotal->amount,
			lineDiscount: $line->lineDiscount,
			amount: $line->amount,
			lineTotal: $line->amount->gross(),
			baseLineDiscount: $line->baseDiscount,
			baseAmount: $line->base,
			taxClass: $input->taxClass,
			isTaxable: array() !== $line->components,
			priceSource: $input->priceSource->value,
			taxComponents: array_map( array( self::class, 'component' ), $line->components )
		);
	}

	/**
	 * Copies an adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @param TotalsAdjustment $adjustment The adjustment.
	 * @return NewOrderAdjustment The order adjustment.
	 */
	private static function adjustment( TotalsAdjustment $adjustment ): NewOrderAdjustment {
		$made = $adjustment->adjustment;

		return new NewOrderAdjustment(
			scope: $made->scope->value,
			type: $made->type->value,
			source: $made->source->toString(),
			label: $made->labelKey,
			authoredAmountBasis: AmountBasis::from( $made->authoredAmount->basis->value ),
			amount: $made->authoredAmount->amount,
			taxed: $adjustment->amount,
			baseAmount: $adjustment->baseAuthoredAmount,
			baseTaxed: $adjustment->base,
			calculationBase: $made->calculationBase?->value,
			taxClass: $made->taxability->taxClass,
			isTaxable: $made->taxability->isTaxable(),
			lineKey: $made->lineKey,
			taxComponents: array_map( array( self::class, 'component' ), $adjustment->components )
		);
	}

	/**
	 * Copies a tax component.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxComponent $component The component.
	 * @return NewTaxComponent The order's component.
	 */
	private static function component( TaxComponent $component ): NewTaxComponent {
		return new NewTaxComponent(
			$component->rate->jurisdictionCode,
			null,
			$component->rate->name,
			$component->rate->rate->micropercent(),
			$component->rate->isCompound,
			$component->rate->priority,
			AmountBasis::from( $component->authoredBasis->value ),
			$component->amount,
			$component->base,
			$component->residualMinor
		);
	}

	/**
	 * Copies the summary, the policy and mode the totals were taxed under, the rate version and the trace.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return TotalsSnapshot The snapshot.
	 */
	private static function snapshot( Totals $totals ): TotalsSnapshot {
		$summary = $totals->summary;
		$version = $totals->conversionContext->sourceVersion();

		return new TotalsSnapshot(
			subtotal: $summary->subtotal,
			discountTotal: $summary->discountTotal,
			shippingTotal: $summary->shippingTotal,
			feeTotal: $summary->feeTotal,
			taxTotal: $summary->tax,
			grandTotal: $summary->grand,
			amountDue: $totals->amountDue(),
			baseSubtotal: $summary->baseSubtotal,
			baseDiscountTotal: $summary->baseDiscountTotal,
			baseShippingTotal: $summary->baseShippingTotal,
			baseFeeTotal: $summary->baseFeeTotal,
			baseTaxTotal: $summary->baseTax,
			baseGrandTotal: $summary->baseGrand,
			taxRoundingMode: $totals->taxRoundingMode->value,
			priceEntryMode: 'net',
			crossZonePolicy: $totals->crossZonePolicy->value,
			taxDisplayMode: null,
			rateVersion: 0 === $version ? null : $version,
			trace: array( 'entries' => $totals->trace->toArray() )
		);
	}
}

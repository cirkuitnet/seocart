<?php
/**
 * OrderDocument: the order a placement writes, mapped from the calculation's totals without arithmetic
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Order\Domain\AmountBasis;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Domain\NewOrderAdjustment;
use SEOCart\Order\Domain\NewOrderLine;
use SEOCart\Order\Domain\NewTaxComponent;
use SEOCart\Order\Domain\OrderChannel;
use SEOCart\Order\Domain\TotalsSnapshot;
use SEOCart\Pricing\Domain\Totals\TaxComponent;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Domain\Totals\TotalsAdjustment;
use SEOCart\Pricing\Domain\Totals\TotalsLine;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * Builds the order a placement writes from the totals it was priced with, the shopper's checkout and the catalog's facts of what it sells.
 *
 * Owns one fact: where each part of an order comes from. Every amount is the calculation's own,
 * copied: only the calculation produces totals, and a scan holds this class to no arithmetic. The
 * words are the snapshot the order keeps: each line's product, SKU and title are the facts the
 * sale was judged on, read in the cart's locale, and an adjustment's label is the key the
 * calculation gave it. The order is the storefront's, in the cart's locale, at the totals' frozen
 * rate, for the billing address's e-mail.
 *
 * How the store's prices were entered is recorded from the lines: the basis they share, or
 * `mixed` when they differ.
 *
 * @since 0.1.0
 */
final class OrderDocument {

	/**
	 * What the order records when its lines' prices were entered in different bases.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MIXED_ENTRY = 'mixed';

	/**
	 * Builds the document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the checkout has no billing address, or a line's variant has no facts.
	 *
	 * @param Totals                       $totals     The totals the cart was priced with.
	 * @param Locale                       $locale     The cart's locale.
	 * @param CheckoutDetails              $details    The shopper's checkout: its addresses.
	 * @param array<int, SellabilityFacts> $sold       The facts the sale of each line's variant was judged on, by variant id.
	 * @param string|null                  $holdGroup  The stock hold taken for the order, or null when nothing was held.
	 * @param int|null                     $customerId The customer the order belongs to, or null for a guest.
	 * @return NewOrder The document.
	 */
	public static function of( Totals $totals, Locale $locale, CheckoutDetails $details, array $sold, ?string $holdGroup, ?int $customerId ): NewOrder {
		$billing = $details->billingAddress ?? throw new \InvalidArgumentException( 'An order is placed with a billing address.' );

		return new NewOrder(
			channel: OrderChannel::Storefront,
			email: $billing->email(),
			customerId: $customerId,
			locale: $locale,
			marketId: null,
			conversionContext: $totals->conversionContext,
			totals: self::snapshot( $totals ),
			lines: array_map( static fn( TotalsLine $line ): NewOrderLine => self::line( $line, $sold[ $line->line->variantId ] ?? throw new \InvalidArgumentException( sprintf( 'The sale of variant %d was not judged.', $line->line->variantId ) ) ), $totals->lines ),
			adjustments: array_map( array( self::class, 'adjustment' ), $totals->adjustments ),
			billingAddress: $billing,
			shippingAddress: $details->shippingAddress,
			holdGroup: $holdGroup
		);
	}

	/**
	 * Copies a line, with the words of the facts its sale was judged on.
	 *
	 * @since 0.1.0
	 *
	 * @param TotalsLine       $line  The line.
	 * @param SellabilityFacts $facts The facts of its variant.
	 * @return NewOrderLine The order line.
	 */
	private static function line( TotalsLine $line, SellabilityFacts $facts ): NewOrderLine {
		$input = $line->line;

		return new NewOrderLine(
			key: $input->key,
			variantId: $input->variantId,
			productId: $facts->productId,
			sku: $facts->sku,
			title: $facts->title,
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
	 * Copies the summary, the policy and the mode the totals were taxed under, the rate version and the trace.
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
			priceEntryMode: self::priceEntryMode( $totals ),
			crossZonePolicy: $totals->crossZonePolicy->value,
			taxDisplayMode: null,
			rateVersion: 0 === $version ? null : $version,
			trace: array( 'entries' => $totals->trace->toArray() )
		);
	}

	/**
	 * Returns how the prices of the order's lines were entered: the basis they share, or MIXED_ENTRY.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return string `net`, `gross` or `mixed`.
	 */
	private static function priceEntryMode( Totals $totals ): string {
		$bases = array_unique( array_map( static fn( TotalsLine $line ): string => $line->line->unitPrice->basis->value, $totals->lines ) );

		return 1 === count( $bases ) ? (string) reset( $bases ) : self::MIXED_ENTRY;
	}
}

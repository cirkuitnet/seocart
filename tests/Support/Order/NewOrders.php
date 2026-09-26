<?php
/**
 * NewOrders: builds the documents the order tests place
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Order;

use SEOCart\Order\Domain\AmountBasis;
use SEOCart\Order\Domain\LineOption;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Domain\NewOrderAdjustment;
use SEOCart\Order\Domain\NewOrderLine;
use SEOCart\Order\Domain\NewTaxComponent;
use SEOCart\Order\Domain\OrderChannel;
use SEOCart\Order\Domain\TotalsSnapshot;
use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Locale;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

/**
 * The order document of a two-line checkout, as the calculation would have produced it.
 *
 * Owns one fact: the fixture order every order test places. Line 1 is two units of a variant
 * with a size option, discounted by a line-scoped promotion; line 2 is one unit of another
 * variant; shipping is a taxed adjustment. Each line and the shipping carry one tax component.
 * The figures are the calculation's, written out: a test compares what the order stored with
 * them. In another currency than the base, the rate is 1 base unit = 1.25 order units, and every
 * base figure is the order figure divided by 1.25, exactly.
 *
 * @since 0.1.0
 */
final class NewOrders {

	/**
	 * The hold group the fixture order was placed with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HOLD_GROUP = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8b';

	/**
	 * The trace the fixture calculation reports.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>
	 */
	public const TRACE = array(
		'steps' => array( 'a1.resolve_prices', 'b1.discounts', 'b3.shipping', 'b6.tax' ),
		'lines' => 2,
	);

	/**
	 * Builds the two-line order.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $currency   Optional. The order's currency. Default USD.
	 * @param string      $base       Optional. The store's base currency. Default USD.
	 * @param int|null    $customerId Optional. The customer, or null for a guest. Default null.
	 * @param string|null $holdGroup  Optional. The stock hold. Default HOLD_GROUP.
	 * @return NewOrder The document.
	 */
	public static function forTwoLines( string $currency = 'USD', string $base = 'USD', ?int $customerId = null, ?string $holdGroup = self::HOLD_GROUP ): NewOrder {
		$context = self::context( Currency::of( $currency ), Currency::of( $base ) );

		return self::document( $context, self::lines( $context ), self::adjustments( $context ), self::totals( $context ), $customerId, $holdGroup );
	}

	/**
	 * Builds an order of many lines: the two-line order with more units of line 2's kind, each with an option and a tax component.
	 *
	 * The totals are the two-line order's: the order copies them and checks none of them, so only
	 * the number of rows changes.
	 *
	 * @since 0.1.0
	 *
	 * @param int $count How many lines, 2 or more.
	 * @return NewOrder The document.
	 */
	public static function forLines( int $count ): NewOrder {
		$context = self::context( Currency::of( 'USD' ), Currency::of( 'USD' ) );
		$lines   = self::lines( $context );

		for ( $index = 3; $index <= $count; ++$index ) {
			$lines[] = new NewOrderLine(
				key: 'line-' . $index,
				variantId: 500 + $index,
				productId: 400 + $index,
				sku: 'MUG-' . $index,
				title: 'Mug ' . $index,
				variantLabel: 'Blue',
				quantity: 1,
				unitAmountBasis: AmountBasis::Net,
				unitPrice: self::money( $context, 500 ),
				unitPriceGross: self::money( $context, 550 ),
				unitCompareAt: null,
				lineSubtotal: self::money( $context, 500 ),
				lineDiscount: self::money( $context, 0 ),
				amount: self::taxed( $context, 500, 50 ),
				lineTotal: self::money( $context, 550 ),
				baseLineDiscount: self::base( $context, 0 ),
				baseAmount: self::baseTaxed( $context, 500, 50 ),
				taxClass: null,
				isTaxable: true,
				priceSource: 'explicit',
				options: array( new LineOption( 'colour', 'Colour', 'blue', 'Blue' ) ),
				taxComponents: array( self::component( $context, 'GB', 500, 50 ) )
			);
		}

		return self::document( $context, $lines, self::adjustments( $context ), self::totals( $context ) );
	}

	/**
	 * Builds a document from its parts.
	 *
	 * @since 0.1.0
	 *
	 * @param ConversionContext    $context     The rate.
	 * @param NewOrderLine[]       $lines       The lines.
	 * @param NewOrderAdjustment[] $adjustments The adjustments.
	 * @param TotalsSnapshot       $totals      The totals.
	 * @param int|null             $customerId  Optional. The customer. Default null.
	 * @param string|null          $holdGroup   Optional. The stock hold. Default HOLD_GROUP.
	 * @return NewOrder The document.
	 */
	public static function document( ConversionContext $context, array $lines, array $adjustments, TotalsSnapshot $totals, ?int $customerId = null, ?string $holdGroup = self::HOLD_GROUP ): NewOrder {
		return new NewOrder(
			channel: OrderChannel::Storefront,
			email: 'jane.doe@example.com',
			customerId: $customerId,
			locale: Locale::of( 'en_GB' ),
			marketId: null,
			conversionContext: $context,
			totals: $totals,
			lines: $lines,
			adjustments: $adjustments,
			billingAddress: new Address( 'GB', first_name: 'Jane', last_name: 'Doe', line1: '1 High Street', city: 'London', postcode: 'SW1A 1AA', email: 'jane.doe@example.com' ),
			shippingAddress: new Address( 'GB', first_name: 'Jane', last_name: 'Doe', line1: '2 Low Road', city: 'Leeds', postcode: 'LS1 1AA' ),
			holdGroup: $holdGroup,
			clientIp: '192.0.2.10',
			userAgent: 'Mozilla/5.0 (fixture)'
		);
	}

	/**
	 * Returns the rate between two currencies: identity for one currency, 1.25 otherwise.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $currency The order's currency.
	 * @param Currency $base     The base currency.
	 * @return ConversionContext The context.
	 */
	public static function context( Currency $currency, Currency $base ): ConversionContext {
		if ( $currency->equals( $base ) ) {
			return ConversionContext::identity( $base );
		}

		return new ConversionContext( $base, $currency, ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( '1.250000' ), 6, 'manual', 3, new \DateTimeImmutable( '2026-09-20 08:00:00', new \DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Returns the two lines.
	 *
	 * @since 0.1.0
	 *
	 * @param ConversionContext $context The rate.
	 * @return list<NewOrderLine> The lines.
	 */
	public static function lines( ConversionContext $context ): array {
		return array(
			new NewOrderLine(
				key: 'line-1',
				variantId: 501,
				productId: 401,
				sku: 'TEE-M',
				title: 'Tee',
				variantLabel: 'Medium',
				quantity: 2,
				unitAmountBasis: AmountBasis::Net,
				unitPrice: self::money( $context, 1000 ),
				unitPriceGross: self::money( $context, 1100 ),
				unitCompareAt: self::money( $context, 1200 ),
				lineSubtotal: self::money( $context, 2000 ),
				lineDiscount: self::money( $context, 200 ),
				amount: self::taxed( $context, 1800, 180 ),
				lineTotal: self::money( $context, 1980 ),
				baseLineDiscount: self::base( $context, 200 ),
				baseAmount: self::baseTaxed( $context, 1800, 180 ),
				taxClass: null,
				isTaxable: true,
				priceSource: 'explicit',
				options: array( new LineOption( 'size', 'Size', 'm', 'Medium' ) ),
				taxComponents: array( self::component( $context, 'GB', 1800, 180 ) )
			),
			new NewOrderLine(
				key: 'line-2',
				variantId: 502,
				productId: 402,
				sku: 'MUG-1',
				title: 'Mug',
				variantLabel: '',
				quantity: 1,
				unitAmountBasis: AmountBasis::Net,
				unitPrice: self::money( $context, 500 ),
				unitPriceGross: self::money( $context, 550 ),
				unitCompareAt: null,
				lineSubtotal: self::money( $context, 500 ),
				lineDiscount: self::money( $context, 0 ),
				amount: self::taxed( $context, 500, 50 ),
				lineTotal: self::money( $context, 550 ),
				baseLineDiscount: self::base( $context, 0 ),
				baseAmount: self::baseTaxed( $context, 500, 50 ),
				taxClass: 'reduced',
				isTaxable: true,
				priceSource: 'explicit',
				taxComponents: array( self::component( $context, 'GB', 500, 50 ) )
			),
		);
	}

	/**
	 * Returns the two adjustments: line 1's discount and the shipping.
	 *
	 * @since 0.1.0
	 *
	 * @param ConversionContext $context The rate.
	 * @return list<NewOrderAdjustment> The adjustments.
	 */
	public static function adjustments( ConversionContext $context ): array {
		return array(
			new NewOrderAdjustment(
				scope: 'line',
				type: 'discount',
				source: 'promotion:01928c3e-0000-7000-8000-00000000abcd',
				label: '10% off tees',
				authoredAmountBasis: AmountBasis::Net,
				amount: self::money( $context, -200 ),
				taxed: self::taxed( $context, -200, 0 ),
				baseAmount: self::base( $context, -200 ),
				baseTaxed: self::baseTaxed( $context, -200, 0 ),
				calculationBase: 'line_amount',
				taxClass: null,
				isTaxable: false,
				lineKey: 'line-1'
			),
			new NewOrderAdjustment(
				scope: 'shipping',
				type: 'shipping',
				source: 'shipping:flat_rate',
				label: 'Standard delivery',
				authoredAmountBasis: AmountBasis::Net,
				amount: self::money( $context, 500 ),
				taxed: self::taxed( $context, 500, 50 ),
				baseAmount: self::base( $context, 500 ),
				baseTaxed: self::baseTaxed( $context, 500, 50 ),
				calculationBase: null,
				taxClass: null,
				isTaxable: true,
				taxComponents: array( self::component( $context, 'GB', 500, 50 ) )
			),
		);
	}

	/**
	 * Returns the totals: subtotal 2500, discount 200, shipping 500, tax 280, grand 3080.
	 *
	 * @since 0.1.0
	 *
	 * @param ConversionContext $context The rate.
	 * @return TotalsSnapshot The totals.
	 */
	public static function totals( ConversionContext $context ): TotalsSnapshot {
		return new TotalsSnapshot(
			subtotal: self::money( $context, 2500 ),
			discountTotal: self::money( $context, 200 ),
			shippingTotal: self::money( $context, 500 ),
			feeTotal: self::money( $context, 0 ),
			taxTotal: self::money( $context, 280 ),
			grandTotal: self::money( $context, 3080 ),
			amountDue: self::money( $context, 3080 ),
			baseSubtotal: self::base( $context, 2500 ),
			baseDiscountTotal: self::base( $context, 200 ),
			baseShippingTotal: self::base( $context, 500 ),
			baseFeeTotal: self::base( $context, 0 ),
			baseTaxTotal: self::base( $context, 280 ),
			baseGrandTotal: self::base( $context, 3080 ),
			taxRoundingMode: 'per_line',
			priceEntryMode: 'net',
			crossZonePolicy: 'fixed_net',
			taxDisplayMode: 'incl',
			rateVersion: $context->baseCurrency()->equals( $context->quoteCurrency() ) ? null : 3,
			trace: self::TRACE
		);
	}

	/**
	 * Returns one tax component at 10%.
	 *
	 * @since 0.1.0
	 *
	 * @param ConversionContext $context      The rate.
	 * @param string            $jurisdiction The jurisdiction.
	 * @param int               $net          The taxed amount, in order minor units.
	 * @param int               $tax          The tax, in order minor units.
	 * @return NewTaxComponent The component.
	 */
	public static function component( ConversionContext $context, string $jurisdiction, int $net, int $tax ): NewTaxComponent {
		return new NewTaxComponent( $jurisdiction, null, 'VAT', 10000000, false, 0, AmountBasis::Net, self::taxed( $context, $net, $tax ), self::baseTaxed( $context, $net, $tax ), 0 );
	}

	/**
	 * Returns an amount in the order's currency.
	 *
	 * @since 0.1.0
	 *
	 * @param ConversionContext $context The rate.
	 * @param int               $minor   The amount.
	 * @return Money The amount.
	 */
	public static function money( ConversionContext $context, int $minor ): Money {
		return Money::of( $minor, $context->quoteCurrency() );
	}

	/**
	 * Returns the base twin of an order amount: the amount divided by the fixture rate.
	 *
	 * @since 0.1.0
	 *
	 * @param ConversionContext $context The rate.
	 * @param int               $minor   The amount, in order minor units.
	 * @return Money The amount in the base currency.
	 */
	public static function base( ConversionContext $context, int $minor ): Money {
		$same = $context->baseCurrency()->equals( $context->quoteCurrency() );

		return Money::of( $same ? $minor : intdiv( $minor * 4, 5 ), $context->baseCurrency() );
	}

	/**
	 * Returns net, tax and gross in the order's currency.
	 *
	 * @since 0.1.0
	 *
	 * @param ConversionContext $context The rate.
	 * @param int               $net     The net amount.
	 * @param int               $tax     The tax.
	 * @return TaxedMoney The amount.
	 */
	public static function taxed( ConversionContext $context, int $net, int $tax ): TaxedMoney {
		return new TaxedMoney( self::money( $context, $net ), self::money( $context, $tax ), self::money( $context, $net + $tax ) );
	}

	/**
	 * Returns the base twin of net, tax and gross.
	 *
	 * @since 0.1.0
	 *
	 * @param ConversionContext $context The rate.
	 * @param int               $net     The net amount, in order minor units.
	 * @param int               $tax     The tax, in order minor units.
	 * @return TaxedMoney The amount in the base currency.
	 */
	public static function baseTaxed( ConversionContext $context, int $net, int $tax ): TaxedMoney {
		$baseNet = self::base( $context, $net );
		$baseTax = self::base( $context, $tax );

		return new TaxedMoney( $baseNet, $baseTax, $baseNet->add( $baseTax ) );
	}
}

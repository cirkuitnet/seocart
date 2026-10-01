<?php
/**
 * RefundOrders: order documents a refund test places, priced by the calculation engine
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use SEOCart\Order\Domain\NewOrder;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Percentage;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\TotalsOrders;

/**
 * Prices a cart in EUR, a presentment currency of a USD store, with the calculation's own engine, and maps its totals onto an order.
 *
 * Owns one fact: the shapes of order a refund is tested on, each figure the engine's: lines taxed
 * by one rate, by two that add up, by one that compounds on another, or by none; a customer exempt
 * from tax; shipping, taxed or not, and free shipping, which takes it off again. The rate is
 * 1 USD = 0.91230 EUR, version 1, so every base figure is a conversion the order stores and a
 * refund only shares out.
 *
 * Tax classes: `standard` 20 %; `additive` 5 % and 7 %, both charged on the net; `compound` 5 %
 * and then 9.975 % charged on the net and the 5 %; `untaxed` no rate at all.
 *
 * @since 0.1.0
 */
final class RefundOrders {

	/**
	 * The method key of the shipping rate.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SHIPPING = 'flat';

	/**
	 * Builds an order of some lines, in EUR at the version-1 rate, shipped to Germany.
	 *
	 * @since 0.1.0
	 *
	 * @param InputLine[] $lines         The lines, priced in EUR.
	 * @param string|null $shippingClass Optional. The tax class of a 4.99 net shipping rate; null for no shipping. Default 'standard'.
	 * @param bool        $freeShipping  Optional. Whether a promotion makes the shipping free. Default false.
	 * @param bool        $exempt        Optional. Whether the customer is exempt from tax. Default false.
	 * @param bool        $tenPercentOff Optional. Whether a promotion takes ten percent off the lines. Default false.
	 * @return NewOrder The document.
	 *
	 * @phpstan-param list<InputLine> $lines
	 */
	public static function priced( array $lines, ?string $shippingClass = 'standard', bool $freeShipping = false, bool $exempt = false, bool $tenPercentOff = false ): NewOrder {
		$promotions = array();

		if ( $tenPercentOff ) {
			$promotions[] = new PromotionFacts( 1, '01928c3e-0000-7000-8000-0000000000a1', 'TENOFF', PromotionEffect::percent( Percentage::fromString( '10' ) ), 1 );
		}

		if ( $freeShipping ) {
			$promotions[] = new PromotionFacts( 2, '01928c3e-0000-7000-8000-0000000000a2', 'FREESHIP', PromotionEffect::freeShipping(), 2 );
		}

		// With no shipping, no destination: the engine charges no shipping for a cart that has none yet.
		$input  = Inputs::input( $lines, 'EUR', null === $shippingClass ? null : new Address( 'DE' ), $promotions, array(), null === $shippingClass ? null : self::SHIPPING, CrossZonePolicy::FixedGross, exempt: $exempt, context: self::context() );
		$rates  = null === $shippingClass ? array() : array( Inputs::shippingRate( self::SHIPPING, '4.99', AmountBasis::Net, $shippingClass, 'EUR' ) );
		$totals = Inputs::calculate( $input, $rates, Inputs::taxQuote( self::rates() ) );

		return TotalsOrders::document( $totals );
	}

	/**
	 * Builds a line priced in EUR.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $key       The line's key, which becomes its variant's name.
	 * @param string      $unit      The unit price in major units.
	 * @param int         $quantity  How many.
	 * @param string      $taxClass  The tax class.
	 * @param AmountBasis $basis     Optional. The price's basis. Default gross.
	 * @param int         $variantId Optional. The variant. Default 501.
	 * @return InputLine The line.
	 */
	public static function line( string $key, string $unit, int $quantity, string $taxClass, AmountBasis $basis = AmountBasis::Gross, int $variantId = 501 ): InputLine {
		return Inputs::line( $key, $unit, $quantity, $basis, $taxClass, 'EUR', $variantId );
	}

	/**
	 * Returns the rate of the order: 1 USD = 0.91230 EUR, the manual rate of version 1.
	 *
	 * @since 0.1.0
	 *
	 * @return ConversionContext The context.
	 */
	public static function context(): ConversionContext {
		return new ConversionContext( Currency::of( 'USD' ), Currency::of( 'EUR' ), ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( '0.91230' ), 5, 'manual', 1, new \DateTimeImmutable( '2026-09-20 08:00:00', new \DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Returns the rates of each tax class.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, list<TaxRateComponent>> The rates, by class.
	 */
	private static function rates(): array {
		return array(
			'standard' => array( Inputs::rate( '20', 'DE' ) ),
			'additive' => array( Inputs::rate( '5', 'CA' ), Inputs::rate( '7', 'CA-BC', 2 ) ),
			'compound' => array( Inputs::rate( '5', 'CA' ), new TaxRateComponent( 'CA-QC:9.975', 'CA-QC tax', Percentage::fromString( '9.975' ), true, 2, 'CA-QC' ) ),
			'untaxed'  => array(),
		);
	}
}

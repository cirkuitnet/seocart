<?php
/**
 * Inputs: builds calculation inputs and quotes for the pricing tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\CustomerTaxFacts;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\FeeDefinition;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\PriceSource;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Quote\Quotes;
use SEOCart\Pricing\Domain\Quote\ShippingRateQuote;
use SEOCart\Pricing\Domain\Quote\TaxQuote;
use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\Percentage;
use SEOCart\Support\RoundingMode;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;

/**
 * Builds the values a calculation takes, from decimal strings, with the defaults most tests share.
 *
 * Owns one fact: how a pricing test writes an amount: a decimal string in major units, read
 * exactly, never a float. The default instant is a fixed one, so no test depends on today.
 *
 * @since 0.1.0
 */
final class Inputs {

	/**
	 * The instant every calculation of a test is asked at, unless it says otherwise.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const AT = '2026-01-15T10:00:00+00:00';

	/**
	 * Reads an amount exactly.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the amount has more places than the currency.
	 *
	 * @param string $amount   The amount in major units, such as '9.99'.
	 * @param string $currency Optional. The ISO code. Default 'USD'.
	 * @return Money The amount.
	 */
	public static function money( string $amount, string $currency = 'USD' ): Money {
		$code    = Currency::of( $currency );
		$decimal = Decimal::of( $amount );
		$minor   = $decimal->rescale( $code->exponent(), RoundingMode::TowardZero );

		if ( ! $minor->equals( $decimal ) ) {
			throw new \InvalidArgumentException( "{$amount} has more places than {$currency} has." );
		}

		return Money::of( $minor->toUnscaledInt(), $code );
	}

	/**
	 * Reads an authored amount.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $amount   The amount in major units.
	 * @param AmountBasis $basis    Optional. The basis. Default net.
	 * @param string      $currency Optional. The ISO code. Default 'USD'.
	 * @return AuthoredAmount The amount.
	 */
	public static function amount( string $amount, AmountBasis $basis = AmountBasis::Net, string $currency = 'USD' ): AuthoredAmount {
		return new AuthoredAmount( self::money( $amount, $currency ), $basis );
	}

	/**
	 * Builds a line.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $key       The line's key.
	 * @param string      $unit      The unit price in major units.
	 * @param int         $quantity  Optional. How many. Default 1.
	 * @param AmountBasis $basis     Optional. The price's basis. Default net.
	 * @param string      $taxClass  Optional. The tax class. Default 'standard'.
	 * @param string      $currency  Optional. The ISO code. Default 'USD'.
	 * @param int         $variantId Optional. The variant. Default 1.
	 * @return InputLine The line.
	 */
	public static function line( string $key, string $unit, int $quantity = 1, AmountBasis $basis = AmountBasis::Net, string $taxClass = 'standard', string $currency = 'USD', int $variantId = 1 ): InputLine {
		return new InputLine( $key, $variantId, $quantity, self::amount( $unit, $basis, $currency ), PriceSource::Explicit, $taxClass );
	}

	/**
	 * Builds an input, with a destination in the United States, and every other fact at its most common value.
	 *
	 * @since 0.1.0
	 *
	 * @param array                  $lines             The lines.
	 * @param string                 $currency          Optional. The calculation's currency. Default 'USD'.
	 * @param Address|null           $destination       Optional. The destination; null for none. Default the United States.
	 * @param array                  $promotions        Optional. The promotions. Default none.
	 * @param array                  $fees              Optional. The fees. Default none.
	 * @param string|null            $shippingMethodKey Optional. The chosen shipping method. Default none.
	 * @param CrossZonePolicy        $policy            Optional. Default fixed net.
	 * @param TaxRoundingMode        $mode              Optional. Default per line.
	 * @param bool                   $exempt            Optional. Whether the customer is exempt. Default false.
	 * @param ConversionContext|null $context           Optional. The conversion context; null for the identity. Default null.
	 * @param string|null            $at                Optional. When the calculation is asked for. Default AT.
	 * @return CalculationInput The input.
	 *
	 * @phpstan-param list<InputLine>      $lines
	 * @phpstan-param list<PromotionFacts> $promotions
	 * @phpstan-param list<FeeDefinition>  $fees
	 */
	public static function input(
		array $lines,
		string $currency = 'USD',
		?Address $destination = new Address( 'US' ),
		array $promotions = array(),
		array $fees = array(),
		?string $shippingMethodKey = null,
		CrossZonePolicy $policy = CrossZonePolicy::FixedNet,
		TaxRoundingMode $mode = TaxRoundingMode::PerLine,
		bool $exempt = false,
		?ConversionContext $context = null,
		?string $at = null
	): CalculationInput {
		$code    = Currency::of( $currency );
		$context = $context ?? ConversionContext::identity( $code );

		return new CalculationInput(
			$code,
			$context->baseCurrency(),
			$context,
			CurrencyRoundingRule::defaultFor( $code ),
			$lines,
			$destination,
			new CustomerTaxFacts( $exempt ),
			$promotions,
			array(),
			$shippingMethodKey,
			$fees,
			$policy,
			$mode,
			new \DateTimeImmutable( $at ?? self::AT )
		);
	}

	/**
	 * Builds a rate of a tax quote.
	 *
	 * @since 0.1.0
	 *
	 * @param string $percent      The rate, in percent.
	 * @param string $jurisdiction Optional. The jurisdiction. Default 'US-TX'.
	 * @param int    $priority     Optional. The priority. Default 1.
	 * @return TaxRateComponent The rate.
	 */
	public static function rate( string $percent, string $jurisdiction = 'US-TX', int $priority = 1 ): TaxRateComponent {
		return new TaxRateComponent( $jurisdiction . ':' . $percent, $jurisdiction . ' tax', Percentage::fromString( $percent ), false, $priority, $jurisdiction );
	}

	/**
	 * Builds a tax quote.
	 *
	 * @since 0.1.0
	 *
	 * @param array      $destination The destination's rates, by class.
	 * @param array|null $reference   Optional. The reference rates, by class; null for the destination's. Default null.
	 * @return TaxQuote The quote.
	 *
	 * @phpstan-param array<string, list<TaxRateComponent>>      $destination
	 * @phpstan-param array<string, list<TaxRateComponent>>|null $reference
	 */
	public static function taxQuote( array $destination, ?array $reference = null ): TaxQuote {
		return new TaxQuote( 'tax-quote-1', 'US-TX', 'test:tax:v1', $destination, $reference ?? $destination, new \DateTimeImmutable( '2026-01-15T10:15:00+00:00' ) );
	}

	/**
	 * Builds a shipping rate quote.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $method   The method's key.
	 * @param string      $amount   The rate in major units.
	 * @param AmountBasis $basis    Optional. The rate's basis. Default net.
	 * @param string      $taxClass Optional. The rate's tax class. Default 'standard'.
	 * @param string      $currency Optional. The ISO code. Default 'USD'.
	 * @return ShippingRateQuote The quote.
	 */
	public static function shippingRate( string $method, string $amount, AmountBasis $basis = AmountBasis::Net, string $taxClass = 'standard', string $currency = 'USD' ): ShippingRateQuote {
		return new ShippingRateQuote( 'ship-quote-' . $method, $method, $method, self::amount( $amount, $basis, $currency ), $taxClass, new \DateTimeImmutable( '2026-01-15T10:15:00+00:00' ), 'test:shipping:v1' );
	}

	/**
	 * Runs both phases of the engine on an input, with quotes taken for its first phase.
	 *
	 * @since 0.1.0
	 *
	 * @param CalculationInput        $input     The input.
	 * @param ShippingRateQuote[]     $rates     Optional. The shipping rates. Default none.
	 * @param TaxQuote|null           $taxQuote  Optional. The tax quote. Default 8.25 % on `standard`, nothing on `exempt`.
	 * @param PromotionEvaluator|null $evaluator Optional. The promotions' evaluator. Default FactsEvaluator.
	 * @return Totals The totals.
	 *
	 * @phpstan-param list<ShippingRateQuote> $rates
	 */
	public static function calculate( CalculationInput $input, array $rates = array(), ?TaxQuote $taxQuote = null, ?PromotionEvaluator $evaluator = null ): Totals {
		$engine = new Engine();
		$phaseA = $engine->phaseA( $input, $evaluator ?? new FactsEvaluator() );
		$tax    = $taxQuote ?? self::taxQuote(
			array(
				'standard' => array( self::rate( '8.25' ) ),
				'exempt'   => array(),
			)
		);

		return $engine->phaseB( $phaseA, new Quotes( $rates, $tax, $phaseA->packagesFingerprint() ) );
	}
}

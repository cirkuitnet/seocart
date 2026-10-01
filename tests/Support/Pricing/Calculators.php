<?php
/**
 * Calculators: builds a calculator over test doubles, in a USD store
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Pricing\Application\Calculator;
use SEOCart\Pricing\Application\PresentmentCurrencies;
use SEOCart\Pricing\Application\PresentmentCurrency;
use SEOCart\Pricing\Application\PriceResolver;
use SEOCart\Pricing\Application\PromotionCodes;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Support\Decimal;
use SEOCart\Support\RoundingMode;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;
use SEOCart\Tests\Support\Catalog\FixedFactsRepository;
use SEOCart\Tests\Support\Doubles\FixedPresentmentCurrencies;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\PoisonedQuoters;

/**
 * Wires a calculator the way the kernel does, over a price double, poisoned ports and a transaction double.
 *
 * Owns one fact: the calculator a unit test drives. The store's base currency is USD, its tax
 * settings are the defaults unless a test names others, it offers prices in no other currency
 * unless a test gives the terms of one (presentment()), and the clock is frozen at Inputs::AT.
 *
 * @since 0.1.0
 */
final class Calculators {

	/**
	 * Builds a calculator.
	 *
	 * @since 0.1.0
	 *
	 * @param FixedFactsRepository       $prices       The prices it reads.
	 * @param PoisonedQuoters            $quoters      Its shipping, tax and promotion ports.
	 * @param TransactionManager         $transactions What tells it whether a transaction is open.
	 * @param CrossZonePolicy            $policy       Optional. The store's cross-zone policy. Default fixed net.
	 * @param TaxRoundingMode            $mode         Optional. The store's tax rounding mode. Default per line.
	 * @param PresentmentCurrencies|null $currencies   Optional. The currencies it offers. Default null, the base currency alone.
	 * @param PromotionCodes|null        $codes        Optional. What resolves the codes entered. Default none: every code is unknown.
	 * @param PromotionEvaluator|null    $evaluator    Optional. The promotions' evaluator. Default the quoters' own.
	 * @return Calculator The calculator.
	 */
	public static function over(
		FixedFactsRepository $prices,
		PoisonedQuoters $quoters,
		TransactionManager $transactions,
		CrossZonePolicy $policy = CrossZonePolicy::FixedNet,
		TaxRoundingMode $mode = TaxRoundingMode::PerLine,
		?PresentmentCurrencies $currencies = null,
		?PromotionCodes $codes = null,
		?PromotionEvaluator $evaluator = null
	): Calculator {
		return new Calculator(
			new PriceResolver( $prices ),
			$quoters->shipping(),
			$quoters->tax(),
			$evaluator ?? $quoters->evaluator(),
			$transactions,
			FrozenClock::at( Inputs::AT ),
			static fn(): Currency => Currency::of( 'USD' ),
			static fn(): CrossZonePolicy => $policy,
			static fn(): TaxRoundingMode => $mode,
			$currencies ?? new FixedPresentmentCurrencies(),
			$codes
		);
	}

	/**
	 * Returns the terms of a currency offered besides USD, at a manual rate from USD.
	 *
	 * @since 0.1.0
	 *
	 * @param string $currency      The ISO code.
	 * @param string $rate          The rate, written at the scale it is quoted at, such as `0.91230`.
	 * @param bool   $fallback      Optional. Whether base prices may be converted into it. Default true.
	 * @param int    $cashStepMinor Optional. Its cash rounding step, in minor units. Default 0, none.
	 * @param int    $version       Optional. The version of the rate set. Default 1.
	 * @return PresentmentCurrency The terms.
	 */
	public static function presentment( string $currency, string $rate, bool $fallback = true, int $cashStepMinor = 0, int $version = 1 ): PresentmentCurrency {
		$quote = Currency::of( $currency );
		$rate  = Decimal::of( $rate );

		return new PresentmentCurrency(
			new CurrencyRoundingRule( $quote, RoundingMode::HalfUp, $cashStepMinor ),
			$fallback,
			new ConversionContext( Currency::of( 'USD' ), $quote, ConversionContext::DIRECTION_BASE_TO_QUOTE, $rate, $rate->scale(), 'manual', $version, new \DateTimeImmutable( '2026-01-01T00:00:00+00:00' ) )
		);
	}

	/**
	 * Returns a price row as the repository reads it.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $variantId  The variant.
	 * @param string   $amount     The price in major units.
	 * @param string   $currency   Optional. The ISO code. Default 'USD'.
	 * @param int|null $taxClassId Optional. The tax class, or null. Default null.
	 * @param string   $basis      Optional. `net` or `gross`. Default `net`.
	 * @return array{variantId: int, price: VariantPrice, taxClassId: int|null} The row.
	 */
	public static function price( int $variantId, string $amount, string $currency = 'USD', ?int $taxClassId = null, string $basis = VariantPrice::NET ): array {
		return array(
			'variantId'  => $variantId,
			'price'      => VariantPrice::stored( Currency::of( $currency ), Inputs::money( $amount, $currency )->minorUnits(), null, $basis ),
			'taxClassId' => $taxClassId,
		);
	}
}

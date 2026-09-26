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
use SEOCart\Pricing\Application\PriceResolver;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\Catalog\FixedFactsRepository;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\PoisonedQuoters;

/**
 * Wires a calculator the way the kernel does, over a price double, poisoned ports and a transaction double.
 *
 * Owns one fact: the calculator a unit test drives. The store's base currency is USD and the
 * clock is frozen at Inputs::AT.
 *
 * @since 0.1.0
 */
final class Calculators {

	/**
	 * Builds a calculator.
	 *
	 * @since 0.1.0
	 *
	 * @param FixedFactsRepository $prices       The prices it reads.
	 * @param PoisonedQuoters      $quoters      Its shipping, tax and promotion ports.
	 * @param TransactionManager   $transactions What tells it whether a transaction is open.
	 * @return Calculator The calculator.
	 */
	public static function over( FixedFactsRepository $prices, PoisonedQuoters $quoters, TransactionManager $transactions ): Calculator {
		return new Calculator(
			new PriceResolver( $prices ),
			$quoters->shipping(),
			$quoters->tax(),
			$quoters->evaluator(),
			$transactions,
			FrozenClock::at( Inputs::AT ),
			static fn(): Currency => Currency::of( 'USD' )
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

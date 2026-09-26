<?php
/**
 * CartB: the reference cart the calculation's budgets and invariants are measured on
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\Engine\PhaseAResult;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Quote\Quotes;
use SEOCart\Support\Percentage;

/**
 * Ten lines across eight products, a percentage code and a free-shipping promotion, two shipping rates and one tax jurisdiction.
 *
 * Owns one fact: the reference cart's contents, so every test that measures the calculation on
 * it measures the same thing. Its prices are chosen so that the percentage lands on half
 * cents and the tax on remainders.
 *
 * @since 0.1.0
 */
final class CartB {

	/**
	 * Each line: key, variant, quantity, unit price.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{string, int, int, string}>
	 */
	public const LINES = array(
		array( 'b1', 501, 1, '19.99' ),
		array( 'b2', 502, 2, '4.95' ),
		array( 'b3', 503, 3, '1.15' ),
		array( 'b4', 504, 1, '99.00' ),
		array( 'b5', 505, 1, '0.35' ),
		array( 'b6', 506, 4, '2.49' ),
		array( 'b7', 507, 1, '12.05' ),
		array( 'b8', 508, 2, '7.45' ),
		array( 'b9', 501, 1, '19.99' ),
		array( 'b10', 502, 1, '4.95' ),
	);

	/**
	 * The lines.
	 *
	 * @since 0.1.0
	 *
	 * @return list<InputLine> The lines.
	 */
	public static function lines(): array {
		return array_map( static fn( array $line ): InputLine => Inputs::line( $line[0], $line[3], $line[2], variantId: $line[1] ), self::LINES );
	}

	/**
	 * The two promotions: a 15 % code, then free shipping.
	 *
	 * @since 0.1.0
	 *
	 * @return list<PromotionFacts> The promotions.
	 */
	public static function promotions(): array {
		return array(
			new PromotionFacts( 1, '00000000-0000-7000-8000-00000000b001', 'CARTB15', PromotionEffect::percent( Percentage::fromString( '15' ) ), 10 ),
			new PromotionFacts( 2, '00000000-0000-7000-8000-00000000b002', '', PromotionEffect::freeShipping(), 20 ),
		);
	}

	/**
	 * The input, asked for at an instant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $at Optional. When it is asked for. Default Inputs::AT.
	 * @return CalculationInput The input.
	 */
	public static function input( string $at = Inputs::AT ): CalculationInput {
		return Inputs::input( self::lines(), promotions: self::promotions(), at: $at );
	}

	/**
	 * The quotes, taken for a first phase's lines.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseAResult $phaseA The first phase's result.
	 * @return Quotes Two shipping rates and one tax rate.
	 */
	public static function quotes( PhaseAResult $phaseA ): Quotes {
		return new Quotes(
			array( Inputs::shippingRate( 'express', '14.95' ), Inputs::shippingRate( 'ground', '6.95' ) ),
			Inputs::taxQuote( array( 'standard' => array( Inputs::rate( '8.25' ) ) ) ),
			$phaseA->packagesFingerprint()
		);
	}
}

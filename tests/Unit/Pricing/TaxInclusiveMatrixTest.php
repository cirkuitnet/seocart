<?php
/**
 * Tests the tax-inclusive matrix: both policies, both rounding modes, every kind of authored amount in both bases, three shapes of rates
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\AdjustmentBase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\FeeDefinition;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Quote\TaxQuote;
use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Pricing\Domain\Taxability;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\Percentage;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\TotalsInvariants;

/**
 * Runs one cart through every cell of the matrix, generated rather than written out: 2 cross-zone
 * policies × 2 tax rounding modes × 4 kinds of authored amount (the catalog prices, a fixed
 * promotion, the shipping rate, a fee) × 2 bases that kind is authored in × 3 shapes of rates (a
 * single rate; two rates that add; a rate and a compound rate) = 96 cells.
 *
 * In each cell the kind named is authored in the basis named and every other kind in the other
 * basis, so every cell mixes bases: a fixed amount off shared with lines of the other basis, a
 * percentage fee on the lines, shipping and fees each taxed as their own basis says. The cart is
 * sold abroad, where the destination's rates differ from the store's, so the two policies differ;
 * and it is priced in euros for a store keeping its accounts in dollars, so every figure has a
 * base-currency twin converted at a frozen rate.
 *
 * Each cell must:
 *
 * - keep `net + tax = gross` at every line, adjustment, component and summary figure, in both
 *   currencies, with each amount's components adding up to its tax, each figure of its scope's
 *   sign, and the summary's subtotal, discounts, shipping and fees adding up to its net
 *   (TotalsInvariants);
 * - follow its policy for every gross-authored amount: under fixed-gross the gross is the amount
 *   as authored; under fixed-net it is `a × (1 + r_dest) ÷ (1 + r_ref)`, rounded once. Per
 *   subtotal, lines of one class and basis are one amount;
 * - at home, where the destination's rates are the store's own, come to the same totals under
 *   both policies, every gross-authored amount at its price as authored.
 *
 * The rates' multipliers the rule is checked against are written out here, not composed by the
 * engine's code.
 *
 * Planted violations, each shown red and removed:
 *
 * - in TaxStep::taxedAmount(), round the net amount `a ÷ (1 + r_ref)` before working out the
 *   gross at the destination under fixed-net: 42 cells fail, such as a handling fee of 1.50
 *   gross charged 1.55 where the rule says 1.54;
 * - in TaxStep::components(), round each component's tax on its own (the amount's net times the
 *   rate's part) instead of splitting the amount's tax by largest remainder: 74 cells fail, an
 *   amount's components no longer adding up to its tax;
 * - in TaxStep::components(), charge a compound rate on the net amount alone: every compound
 *   cell fails, a component's net not being what its rate was charged on;
 * - in TaxStep::taxedAmount(), charge an exempt customer under fixed-gross the gross price less
 *   the store's own rate instead of the destination's: 10 fixed-gross cells fail, the exempt
 *   customer paying 50.80 where a taxed customer's net is 49.05;
 * - in Totals::summarise(), add up the authored amounts for the subtotal, discounts, shipping
 *   and fees again: 84 cells fail, their figures coming to 60.36 against a net of 59.25.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class TaxInclusiveMatrixTest extends TestCase {

	/**
	 * The shapes of rates, for the `standard` class: at the destination and at the store, each rate as rate, compound, priority and jurisdiction, and the multiplier they come to.
	 *
	 * The compound shape lists its compound rate first, so the order a quote lists rates in is not the order they apply in.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{destination: list<array{string, bool, int, string}>, reference: list<array{string, bool, int, string}>, multipliers: array{string, string}}>
	 */
	private const SHAPES = array(
		'single'   => array(
			'destination' => array( array( '25', false, 1, 'XA' ) ),
			'reference'   => array( array( '20', false, 1, 'GB' ) ),
			'multipliers' => array( '1.25', '1.20' ),
		),
		'additive' => array(
			'destination' => array( array( '6.25', false, 1, 'US-TX' ), array( '2', false, 1, 'US-TX-AUS' ) ),
			'reference'   => array( array( '6', false, 1, 'US-WA' ), array( '1', false, 1, 'US-WA-SEA' ) ),
			'multipliers' => array( '1.0825', '1.07' ),
		),
		'compound' => array(
			'destination' => array( array( '9.975', true, 2, 'CA-QC' ), array( '5', false, 1, 'CA' ) ),
			'reference'   => array( array( '7', true, 2, 'CA-BC' ), array( '5', false, 1, 'CA' ) ),
			'multipliers' => array( '1.1547375', '1.1235' ),
		),
	);

	/**
	 * The `reduced` class's rates, the same in every cell: 10 % at the destination, 5 % at the store.
	 *
	 * @since 0.1.0
	 *
	 * @var array{string, string}
	 */
	private const REDUCED_MULTIPLIERS = array( '1.10', '1.05' );

	/**
	 * The kinds of authored amount.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const KINDS = array( 'catalog', 'fixed promotion', 'shipping rate', 'fee' );

	/**
	 * Tests that a cell's figures add up in both currencies, follow its policy, and agree across the policies at home.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_cells
	 *
	 * @param CrossZonePolicy $policy The policy.
	 * @param TaxRoundingMode $mode   The rounding mode.
	 * @param string          $kind   The kind of amount authored in the basis.
	 * @param AmountBasis     $basis  The basis; every other kind is authored in the other.
	 * @param string          $shape  The shape of rates.
	 */
	public function test_every_cell_adds_up_and_follows_its_policy( CrossZonePolicy $policy, TaxRoundingMode $mode, string $kind, AmountBasis $basis, string $shape ): void {
		$abroad = self::price( $policy, $mode, $kind, $basis, $shape, false, false );

		$this->assertSame( array(), TotalsInvariants::problems( $abroad ), 'Abroad.' );
		$this->assertSame( array(), self::policyProblems( $abroad, $policy, $mode, self::multipliers( $shape, false ) ), 'Abroad.' );

		$home      = self::price( $policy, $mode, $kind, $basis, $shape, false, true );
		$otherHome = self::price( CrossZonePolicy::FixedNet === $policy ? CrossZonePolicy::FixedGross : CrossZonePolicy::FixedNet, $mode, $kind, $basis, $shape, false, true );

		$this->assertSame( array(), TotalsInvariants::problems( $home ), 'At home.' );
		$this->assertSame( array(), self::policyProblems( $home, $policy, $mode, self::multipliers( $shape, true ) ), 'At home.' );
		$this->assertSame( self::withoutPolicy( $home ), self::withoutPolicy( $otherHome ), 'Both policies agree at home.' );
	}

	/**
	 * Tests, over every cell with gross-authored amounts, that an exempt customer never pays more than a taxed customer's net.
	 *
	 * The exempt customer pays each gross amount's net as the policy states it, rounded once;
	 * the taxed customer's net is what is left of the gross once the tax is rounded. The two are
	 * the same figure rounded two ways, so they may differ by one minor unit for each amount
	 * rounded: 9.99 at 20 % is a net of 8.325, which the exempt customer pays as 8.33 and a taxed
	 * one, paying 1.67 of tax, as 8.32. Beyond that the exempt customer never pays more. Charged
	 * the gross less the store's own rate where the destination's is higher, the exempt customer
	 * would pay more than a taxed customer's net by the difference in the rates, far beyond it.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_gross_cells
	 *
	 * @param CrossZonePolicy $policy The policy.
	 * @param TaxRoundingMode $mode   The rounding mode.
	 * @param string          $kind   The kind of amount authored gross.
	 * @param AmountBasis     $basis  Gross.
	 * @param string          $shape  The shape of rates.
	 */
	public function test_an_exempt_customer_never_pays_more_than_a_taxed_customers_net( CrossZonePolicy $policy, TaxRoundingMode $mode, string $kind, AmountBasis $basis, string $shape ): void {
		$taxed   = self::price( $policy, $mode, $kind, $basis, $shape, false, false );
		$exempt  = self::price( $policy, $mode, $kind, $basis, $shape, true, false );
		$rounded = count( $taxed->lines ) + count( array_filter( $taxed->adjustments, static fn( $adjustment ): bool => AdjustmentScope::Line !== $adjustment->adjustment->scope ) );

		$this->assertSame( 0, $exempt->summary->tax->minorUnits() );
		$this->assertLessThanOrEqual( $taxed->summary->net->minorUnits() + $rounded, $exempt->summary->grand->minorUnits(), "At most one minor unit over the taxed net for each of the {$rounded} amounts rounded." );
		$this->assertLessThan( $taxed->summary->grand->minorUnits(), $exempt->summary->grand->minorUnits() );
		$this->assertSame( array(), TotalsInvariants::problems( $exempt ) );
	}

	/**
	 * Provides the 96 cells.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{CrossZonePolicy, TaxRoundingMode, string, AmountBasis, string}> The cells, by name.
	 */
	public static function data_cells(): array {
		$cells = array();

		foreach ( CrossZonePolicy::cases() as $policy ) {
			foreach ( TaxRoundingMode::cases() as $mode ) {
				foreach ( self::KINDS as $kind ) {
					foreach ( AmountBasis::cases() as $basis ) {
						foreach ( array_keys( self::SHAPES ) as $shape ) {
							$cells[ "{$policy->value}, {$mode->value}, {$kind} {$basis->value}, {$shape} rates" ] = array( $policy, $mode, $kind, $basis, $shape );
						}
					}
				}
			}
		}

		return $cells;
	}

	/**
	 * Provides the cells whose named kind is authored gross.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{CrossZonePolicy, TaxRoundingMode, string, AmountBasis, string}> The cells.
	 */
	public static function data_gross_cells(): array {
		return array_filter( self::data_cells(), static fn( array $cell ): bool => AmountBasis::Gross === $cell[3] );
	}

	/**
	 * Tests that the matrix is the full cross-product.
	 *
	 * @since 0.1.0
	 */
	public function test_the_matrix_has_every_cell(): void {
		$this->assertCount( 96, self::data_cells() );
		$this->assertCount( 48, self::data_gross_cells() );
	}

	/**
	 * Prices the cart of a cell.
	 *
	 * @since 0.1.0
	 *
	 * @param CrossZonePolicy $policy The policy.
	 * @param TaxRoundingMode $mode   The rounding mode.
	 * @param string          $kind   The kind of amount authored in the basis.
	 * @param AmountBasis     $basis  The basis.
	 * @param string          $shape  The shape of rates.
	 * @param bool            $exempt Whether the customer is exempt.
	 * @param bool            $home   Whether the destination's rates are the store's own.
	 * @return Totals The totals.
	 */
	private static function price( CrossZonePolicy $policy, TaxRoundingMode $mode, string $kind, AmountBasis $basis, string $shape, bool $exempt, bool $home ): Totals {
		$other   = AmountBasis::Net === $basis ? AmountBasis::Gross : AmountBasis::Net;
		$of      = static fn( string $named ): AmountBasis => $named === $kind ? $basis : $other;
		$context = new ConversionContext( Currency::of( 'USD' ), Currency::of( 'EUR' ), ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( '0.91230' ), 5, 'manual', 3, new \DateTimeImmutable( Inputs::AT ) );
		$lines   = array(
			Inputs::line( 'a', '9.99', 3, $of( 'catalog' ), 'standard', 'EUR', 11 ),
			Inputs::line( 'b', '24.50', 1, $of( 'catalog' ), 'standard', 'EUR', 12 ),
			Inputs::line( 'c', '4.35', 2, $of( 'catalog' ), 'reduced', 'EUR', 13 ),
		);
		$codes   = array(
			new PromotionFacts( 1, '00000000-0000-7000-8000-000000000901', 'TENOFF', PromotionEffect::percent( Percentage::fromString( '10' ) ), 10 ),
			new PromotionFacts( 2, '00000000-0000-7000-8000-000000000902', 'FIVEOFF', PromotionEffect::fixed( Inputs::amount( '5.00', $of( 'fixed promotion' ), 'EUR' ) ), 20 ),
		);
		$fees    = array(
			new FeeDefinition( 'handling', Inputs::amount( '1.50', $of( 'fee' ), 'EUR' ), AdjustmentBase::SubtotalAfterDiscounts, Taxability::taxable( 'standard' ) ),
			new FeeDefinition( 'service', Percentage::fromString( '2' ), AdjustmentBase::SubtotalAfterDiscounts, Taxability::notTaxable() ),
			new FeeDefinition( 'insurance', Percentage::fromString( '5' ), AdjustmentBase::SelectedShippingRate, Taxability::taxable( 'standard' ) ),
		);
		$input   = Inputs::input( $lines, 'EUR', new Address( 'FR' ), $codes, $fees, null, $policy, $mode, $exempt, $context );

		return Inputs::calculate( $input, array( Inputs::shippingRate( 'ground', '4.95', $of( 'shipping rate' ), 'standard', 'EUR' ) ), self::taxQuote( $shape, $home ) );
	}

	/**
	 * Builds a cell's tax quote.
	 *
	 * @since 0.1.0
	 *
	 * @param string $shape The shape of the `standard` class's rates.
	 * @param bool   $home  Whether the destination's rates are the store's own.
	 * @return TaxQuote The quote.
	 */
	private static function taxQuote( string $shape, bool $home ): TaxQuote {
		$rates       = static fn( array $spec ): array => array_map( static fn( array $rate ): TaxRateComponent => new TaxRateComponent( $rate[3] . ':' . $rate[0], $rate[3] . ' tax', Percentage::fromString( $rate[0] ), $rate[1], $rate[2], $rate[3] ), $spec );
		$destination = array(
			'standard' => $rates( self::SHAPES[ $shape ]['destination'] ),
			'reduced'  => $rates( array( array( '10', false, 1, 'XA-R' ) ) ),
		);
		$reference   = array(
			'standard' => $rates( self::SHAPES[ $shape ]['reference'] ),
			'reduced'  => $rates( array( array( '5', false, 1, 'GB-R' ) ) ),
		);

		return Inputs::taxQuote( $destination, $home ? $destination : $reference );
	}

	/**
	 * Returns the multipliers of each class, at the destination and at the store.
	 *
	 * @since 0.1.0
	 *
	 * @param string $shape The shape of the `standard` class's rates.
	 * @param bool   $home  Whether the destination's rates are the store's own.
	 * @return array<string, array{Decimal, Decimal}> By class: the destination's multiplier, then the store's.
	 */
	private static function multipliers( string $shape, bool $home ): array {
		$multipliers = array(
			'standard' => self::SHAPES[ $shape ]['multipliers'],
			'reduced'  => self::REDUCED_MULTIPLIERS,
		);

		return array_map( static fn( array $pair ): array => array( Decimal::of( $pair[0] ), Decimal::of( $home ? $pair[0] : $pair[1] ) ), $multipliers );
	}

	/**
	 * Lists every gross-authored amount whose gross does not follow the policy.
	 *
	 * Under fixed-gross the gross is the amount as authored; under fixed-net it is the amount
	 * times the destination's multiplier divided by the store's, rounded once. A line counts
	 * after its discounts. Per subtotal, the lines of one class authored gross are checked as the
	 * one amount they are taxed as; an adjustment outside a line is always its own amount.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals          $totals      The totals.
	 * @param CrossZonePolicy $policy      The policy.
	 * @param TaxRoundingMode $mode        The rounding mode.
	 * @param array           $multipliers Each class's multipliers, at the destination and at the store.
	 * @return list<string> What does not follow it.
	 *
	 * @phpstan-param array<string, array{Decimal, Decimal}> $multipliers
	 */
	private static function policyProblems( Totals $totals, CrossZonePolicy $policy, TaxRoundingMode $mode, array $multipliers ): array {
		$amounts = array();

		foreach ( $totals->lines as $line ) {
			if ( AmountBasis::Gross === $line->lineSubtotal->basis ) {
				$scope = TaxRoundingMode::PerSubtotal === $mode ? 'lines of ' . $line->line->taxClass : 'line ' . $line->line->key;

				$amounts[ $scope ]  ??= array( $line->line->taxClass, Money::zero( $totals->currency ), Money::zero( $totals->currency ) );
				$amounts[ $scope ][1] = $amounts[ $scope ][1]->add( $line->lineSubtotal->amount )->add( $line->lineDiscount );
				$amounts[ $scope ][2] = $amounts[ $scope ][2]->add( $line->amount->gross() );
			}
		}

		foreach ( $totals->adjustments as $adjustment ) {
			$authored = $adjustment->adjustment->authoredAmount;
			$class    = $adjustment->adjustment->taxability->taxClass;

			if ( AdjustmentScope::Line !== $adjustment->adjustment->scope && AmountBasis::Gross === $authored->basis && null !== $class ) {
				$amounts[ 'adjustment ' . $adjustment->adjustment->position ] = array( $class, $authored->amount, $adjustment->amount->gross() );
			}
		}

		$problems = array();
		$rule     = CurrencyRoundingRule::defaultFor( $totals->currency );

		foreach ( $amounts as $scope => list( $class, $authored, $gross ) ) {
			list( $destination, $store ) = $multipliers[ $class ];

			$expected = CrossZonePolicy::FixedGross === $policy ? $authored : Money::ofDecimal( $authored->toDecimal()->multiply( $destination )->divide( $store, $totals->currency->exponent(), $rule->roundingMode() ), $totals->currency, $rule->roundingMode() );

			if ( ! $expected->equals( $gross ) ) {
				$problems[] = sprintf( '%s: authored %s gross, charged %s, the policy says %s', $scope, $authored->toDecimal()->toString(), $gross->toDecimal()->toString(), $expected->toDecimal()->toString() );
			}
		}

		return $problems;
	}

	/**
	 * Returns the totals as an array without the policy they were worked out under.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return array<string, mixed> The totals.
	 */
	private static function withoutPolicy( Totals $totals ): array {
		$array = $totals->toArray();

		unset( $array['cross_zone_policy'] );

		return $array;
	}
}

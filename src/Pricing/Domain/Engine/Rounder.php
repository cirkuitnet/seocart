<?php
/**
 * Rounder: the one place a calculation turns an exact value into minor units
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\RoundingMode;
use SEOCart\Support\TaxedMoney;
use SEOCart\Tax\Domain\EffectiveRate;

defined( 'ABSPATH' ) || exit;

/**
 * Rounds at the calculation's declared boundaries, and records each rounding in the trace.
 *
 * Owns one fact: that a calculation rounds only here. Everything before a boundary is exact:
 * a percentage of an amount, a tax extracted from a gross price, an amount converted at a rate.
 * Each method crosses one boundary, rounds once with the calculation's rounding mode, and
 * records a `rounding` entry: the subject, the exact value, the rounded one and the mode. A
 * replay can therefore check every boundary from the trace alone.
 *
 * An exact product is recorded as it is. A quotient rarely ends, so it is recorded to twelve
 * digits past the minor unit, rounded toward zero; those digits decide the rounding exactly as
 * the full quotient does, since a value cut short toward zero never crosses a rounding tie.
 *
 * Splitting an amount into shares is a boundary too: largest remainder, the leftover minor
 * units to the largest remainders, a tie to the earlier share. Shares are never rounded one by
 * one, so they always add up to the whole.
 *
 * @since 0.1.0
 */
final class Rounder {

	/**
	 * How many digits past the minor unit a quotient is recorded to.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const TRACE_DIGITS = 12;

	/**
	 * The mode a trace records for a split by largest remainder.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LARGEST_REMAINDER = 'largest_remainder';

	/**
	 * Rounds an exact amount to the currency's minor unit: a percentage of an amount.
	 *
	 * @since 0.1.0
	 *
	 * @param TraceBuilder $trace    The calculation's trace, which gives the mode.
	 * @param string       $subject  What is rounded, such as `line:3:promotion:<uuid>`.
	 * @param Decimal      $exact    The exact amount, in major units.
	 * @param Currency     $currency The currency.
	 * @return Money The rounded amount.
	 */
	public function money( TraceBuilder $trace, string $subject, Decimal $exact, Currency $currency ): Money {
		$rounded = Money::ofDecimal( $exact, $currency, $trace->mode() );

		$this->record( $trace, $subject, $exact->toString(), $rounded->toDecimal()->toString(), $trace->mode()->value );

		return $rounded;
	}

	/**
	 * Divides once and rounds the quotient to the currency's minor unit.
	 *
	 * @since 0.1.0
	 *
	 * @param TraceBuilder $trace       The calculation's trace, which gives the mode.
	 * @param string       $subject     What is rounded.
	 * @param Decimal      $dividend    The exact dividend, in major units.
	 * @param Decimal      $divisor     The divisor; not zero.
	 * @param Currency     $currency    The currency of the quotient.
	 * @param bool         $displayOnly Optional. Whether the quotient is shown only and never added
	 *                                  into a total, such as a unit price derived from a line. Default false.
	 * @return Money The rounded quotient.
	 */
	public function quotient( TraceBuilder $trace, string $subject, Decimal $dividend, Decimal $divisor, Currency $currency, bool $displayOnly = false ): Money {
		$rounded = Money::ofDecimal( $dividend->divide( $divisor, $currency->exponent(), $trace->mode() ), $currency, $trace->mode() );
		$exact   = $dividend->divide( $divisor, $currency->exponent() + self::TRACE_DIGITS, RoundingMode::TowardZero );

		$this->record( $trace, $subject, $exact->toString(), $rounded->toDecimal()->toString(), $trace->mode()->value, $displayOnly );

		return $rounded;
	}

	/**
	 * Adds tax to a net amount: the tax is the amount times the rate, rounded once.
	 *
	 * @since 0.1.0
	 *
	 * @param TraceBuilder  $trace   The calculation's trace, which gives the mode.
	 * @param string        $subject What is taxed.
	 * @param Money         $net     The amount before tax.
	 * @param EffectiveRate $rate    The rate.
	 * @return TaxedMoney The amount, its tax and their sum.
	 */
	public function taxOnNet( TraceBuilder $trace, string $subject, Money $net, EffectiveRate $rate ): TaxedMoney {
		$taxed = TaxedMoney::fromNet( $net, $rate->factor(), $trace->mode() );

		$this->record( $trace, $subject, $net->multiply( $rate->factor() )->toString(), $taxed->tax()->toDecimal()->toString(), $trace->mode()->value );

		return $taxed;
	}

	/**
	 * Takes the tax out of a gross amount: the tax is gross × rate ÷ (1 + rate), rounded once.
	 *
	 * @since 0.1.0
	 *
	 * @param TraceBuilder  $trace   The calculation's trace, which gives the mode.
	 * @param string        $subject What is taxed.
	 * @param Money         $gross   The amount including tax.
	 * @param EffectiveRate $rate    The rate.
	 * @return TaxedMoney The amount before tax, the tax and the gross amount.
	 */
	public function taxInGross( TraceBuilder $trace, string $subject, Money $gross, EffectiveRate $rate ): TaxedMoney {
		$taxed = TaxedMoney::fromGross( $gross, $rate->factor(), $trace->mode() );
		$exact = $gross->multiply( $rate->factor() )->divide( $rate->multiplier(), $gross->currency()->exponent() + self::TRACE_DIGITS, RoundingMode::TowardZero );

		$this->record( $trace, $subject, $exact->toString(), $taxed->tax()->toDecimal()->toString(), $trace->mode()->value );

		return $taxed;
	}

	/**
	 * Splits an amount into shares proportional to ratios, and gives each share's residual.
	 *
	 * @since 0.1.0
	 *
	 * @param TraceBuilder $trace   The calculation's trace.
	 * @param string       $subject What is split.
	 * @param Money        $whole   The amount.
	 * @param array        $ratios  The ratios, in share order: zero or more, not all zero.
	 * @return Allocation The shares and their residuals, keyed as the ratios.
	 *
	 * @phpstan-param array<int|string, int|Decimal> $ratios
	 */
	public function split( TraceBuilder $trace, string $subject, Money $whole, array $ratios ): Allocation {
		$shares    = $whole->allocate( $ratios );
		$amount    = Decimal::ofUnscaled( $whole->minorUnits(), 0 );
		$weights   = array_map( static fn( int|Decimal $ratio ): Decimal => is_int( $ratio ) ? Decimal::ofUnscaled( $ratio, 0 ) : $ratio, $ratios );
		$total     = array_reduce( $weights, static fn( Decimal $sum, Decimal $weight ): Decimal => $sum->add( $weight ), Decimal::ofUnscaled( 0, 0 ) );
		$residuals = array();

		foreach ( $weights as $key => $weight ) {
			$proportion        = $amount->multiply( $weight )->divide( $total, 0, RoundingMode::TowardZero );
			$residuals[ $key ] = $shares[ $key ]->minorUnits() - $proportion->toUnscaledInt();
		}

		$this->record( $trace, $subject, $whole->toDecimal()->toString(), self::decimals( $shares ), self::LARGEST_REMAINDER );

		return new Allocation( $shares, $residuals );
	}

	/**
	 * Splits a taxed amount into shares proportional to ratios, keeping the figure it was authored in exact.
	 *
	 * The kept figure and the tax are each split by largest remainder, and the third figure of
	 * each share is derived, so every share keeps `net + tax = gross`. With a net basis the net
	 * and the tax are split, and gross derived. With a gross basis the gross and the tax are
	 * split, and net derived: a gross price is what the customer pays, so its shares must be the
	 * gross prices they stand for; splitting the net and the tax of three lines of 0.03 would give
	 * them 0.04, 0.03 and 0.02. A gross basis is only for a gross that is the price authored: the
	 * caller passes a net basis for a gross that was converted, whose split could otherwise leave
	 * a share with more tax than gross.
	 *
	 * @since 0.1.0
	 *
	 * @param TraceBuilder $trace   The calculation's trace.
	 * @param string       $subject What is split.
	 * @param TaxedMoney   $whole   The taxed amount.
	 * @param array        $ratios  The ratios, in share order: zero or more, not all zero.
	 * @param AmountBasis  $basis   The figure the amount kept as authored: its net, or its gross where that is the price authored.
	 * @return array<int|string, TaxedMoney> The shares, keyed as the ratios.
	 *
	 * @phpstan-param array<int|string, int|Decimal> $ratios
	 */
	public function splitTaxed( TraceBuilder $trace, string $subject, TaxedMoney $whole, array $ratios, AmountBasis $basis ): array {
		$taxes = $whole->tax()->allocate( $ratios );

		$this->record( $trace, $subject . ':tax', $whole->tax()->toDecimal()->toString(), self::decimals( $taxes ), self::LARGEST_REMAINDER );

		if ( AmountBasis::Net === $basis ) {
			$nets = $whole->net()->allocate( $ratios );

			$this->record( $trace, $subject . ':net', $whole->net()->toDecimal()->toString(), self::decimals( $nets ), self::LARGEST_REMAINDER );

			return self::combine( $nets, $taxes, static fn( Money $net, Money $tax ): TaxedMoney => new TaxedMoney( $net, $tax, $net->add( $tax ) ) );
		}

		$grosses = $whole->gross()->allocate( $ratios );

		$this->record( $trace, $subject . ':gross', $whole->gross()->toDecimal()->toString(), self::decimals( $grosses ), self::LARGEST_REMAINDER );

		return self::combine( $grosses, $taxes, static fn( Money $gross, Money $tax ): TaxedMoney => new TaxedMoney( $gross->subtract( $tax ), $tax, $gross ) );
	}

	/**
	 * Builds each share from its authored figure and its tax, keyed as the split was.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, Money> $figures The shares of the authored figure.
	 * @param array<int|string, Money> $taxes   The shares of the tax, keyed the same way.
	 * @param callable                 $build   Makes a taxed share from a figure and a tax.
	 * @return array<int|string, TaxedMoney> The shares.
	 *
	 * @phpstan-param callable(Money, Money): TaxedMoney $build
	 */
	private static function combine( array $figures, array $taxes, callable $build ): array {
		$shares = array();

		foreach ( $figures as $key => $figure ) {
			$shares[ $key ] = $build( $figure, $taxes[ $key ] );
		}

		return $shares;
	}

	/**
	 * Converts an amount in the calculation's currency to the base currency at the frozen rate, rounding once.
	 *
	 * @since 0.1.0
	 *
	 * @param TraceBuilder      $trace   The calculation's trace, which gives the mode.
	 * @param string            $subject What is converted.
	 * @param Money             $amount  The amount, in the context's quote currency.
	 * @param ConversionContext $context The frozen rate.
	 * @return Money The amount in the base currency.
	 */
	public function toBase( TraceBuilder $trace, string $subject, Money $amount, ConversionContext $context ): Money {
		$rounded = $context->convertToBaseMoney( $amount, $trace->mode() );
		$exact   = $amount->toDecimal()->divide( $context->rate(), $context->baseCurrency()->exponent() + self::TRACE_DIGITS, RoundingMode::TowardZero );

		$this->record( $trace, $subject, $exact->toString(), $rounded->toDecimal()->toString(), $trace->mode()->value );

		return $rounded;
	}

	/**
	 * Records a rounding in the trace.
	 *
	 * @since 0.1.0
	 *
	 * @param TraceBuilder        $trace       The trace.
	 * @param string              $subject     What was rounded.
	 * @param string              $exact       The value before rounding.
	 * @param string|list<string> $rounded     The rounded value, or the shares of a split.
	 * @param string              $mode        How it was rounded.
	 * @param bool                $displayOnly Optional. Whether the value is shown only. Default false.
	 */
	private function record( TraceBuilder $trace, string $subject, string $exact, string|array $rounded, string $mode, bool $displayOnly = false ): void {
		$data = array(
			'subject' => $subject,
			'exact'   => $exact,
			'rounded' => $rounded,
			'mode'    => $mode,
		);

		if ( $displayOnly ) {
			$data['display_only'] = true;
		}

		$trace->record( TraceEntry::ROUNDING, $data );
	}

	/**
	 * Writes amounts as decimal strings, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, Money> $amounts The amounts.
	 * @return list<string> Their decimal strings.
	 */
	private static function decimals( array $amounts ): array {
		return array_values( array_map( static fn( Money $amount ): string => $amount->toDecimal()->toString(), $amounts ) );
	}
}

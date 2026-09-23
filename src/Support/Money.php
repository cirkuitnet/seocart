<?php
/**
 * Money: an amount of one currency, in integer minor units
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable amount of money: an integer count of minor units and a currency.
 *
 * This class owns one fact: what an amount is — minor units plus an ISO 4217 currency, the
 * form every monetary column stores — and the arithmetic that keeps it exact.
 *
 * - Amounts combine only within one currency. Mixing currencies is a programming error
 *   (CurrencyMismatchException); a ConversionContext is the only way across.
 * - Integer arithmetic is checked. PHP would silently turn an overflowing integer into a
 *   float, so every operation that could overflow throws ArithmeticOverflowException first.
 * - Multiplying by a rate yields a Decimal, not Money: the result usually has more digits
 *   than a minor unit. Turning a Decimal back into Money always names a RoundingMode, so
 *   rounding happens only at a boundary the caller declares.
 * - allocate() splits an amount by largest remainder with a declared tie-break, so the parts
 *   always sum to the whole.
 *
 * Formatting for people is an adapter's job; adapters format amounts and never compute them
 * (DRY rule 7), which the SEOCart.DRY.MoneyArithmeticInInterfaces sniff checks by method name.
 *
 * @since 0.1.0
 */
final class Money {

	/**
	 * The amount in minor units: cents for USD, yen for JPY, fils for KWD.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $minorUnits;

	/**
	 * The currency of the amount.
	 *
	 * @since 0.1.0
	 *
	 * @var Currency
	 */
	private Currency $currency;

	/**
	 * Creates an amount.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $minor_units The amount in minor units.
	 * @param Currency $currency    The currency.
	 */
	private function __construct( int $minor_units, Currency $currency ) {
		$this->minorUnits = $minor_units;
		$this->currency   = $currency;
	}

	/**
	 * Creates an amount from minor units, the form every monetary column stores.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $minor_units The amount in minor units: 1234 is 12.34 USD, or 1234 JPY.
	 * @param Currency $currency    The currency.
	 * @return self The amount.
	 */
	public static function of( int $minor_units, Currency $currency ): self {
		return new self( $minor_units, $currency );
	}

	/**
	 * Creates a zero amount.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $currency The currency.
	 * @return self Zero in that currency.
	 */
	public static function zero( Currency $currency ): self {
		return new self( 0, $currency );
	}

	/**
	 * Rounds a decimal amount of major units to the currency's minor unit.
	 *
	 * This is a rounding boundary: the mode says how the digits beyond the currency's exponent
	 * are dropped. Money::ofDecimal( Decimal::of( '0.925' ), $usd, RoundingMode::HalfUp ) is
	 * 0.93 USD.
	 *
	 * @since 0.1.0
	 *
	 * @param Decimal      $amount   The amount in major units, at any scale.
	 * @param Currency     $currency The currency.
	 * @param RoundingMode $mode     How the amount is rounded to the minor unit.
	 * @return self The rounded amount.
	 */
	public static function ofDecimal( Decimal $amount, Currency $currency, RoundingMode $mode ): self {
		return new self( $amount->rescale( $currency->exponent(), $mode )->toUnscaledInt(), $currency );
	}

	/**
	 * Returns the amount in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @return int The amount in minor units.
	 */
	public function minorUnits(): int {
		return $this->minorUnits;
	}

	/**
	 * Returns the currency.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency.
	 */
	public function currency(): Currency {
		return $this->currency;
	}

	/**
	 * Returns the amount in major units, exactly, as a decimal at the currency's exponent.
	 *
	 * 1234 minor units of USD is '12.34'; 1234 of JPY is '1234'; 1234 of KWD is '1.234'.
	 *
	 * @since 0.1.0
	 *
	 * @return Decimal The amount in major units.
	 */
	public function toDecimal(): Decimal {
		return Decimal::ofUnscaled( $this->minorUnits, $this->currency->exponent() );
	}

	/**
	 * Adds an amount in the same currency.
	 *
	 * An amount in another currency is refused with a CurrencyMismatchException.
	 *
	 * @since 0.1.0
	 *
	 * @throws ArithmeticOverflowException When the sum does not fit in 64 bits.
	 *
	 * @param Money $other The amount to add.
	 * @return self The sum.
	 */
	public function add( Money $other ): self {
		CurrencyMismatchException::assertSameCurrency( $this->currency, $other->currency );

		$left  = $this->minorUnits;
		$right = $other->minorUnits;

		if ( ( $right > 0 && $left > PHP_INT_MAX - $right ) || ( $right < 0 && $left < PHP_INT_MIN - $right ) ) {
			throw ArithmeticOverflowException::in( 'Money::add()' );
		}

		return new self( $left + $right, $this->currency );
	}

	/**
	 * Subtracts an amount in the same currency.
	 *
	 * An amount in another currency is refused with a CurrencyMismatchException.
	 *
	 * @since 0.1.0
	 *
	 * @throws ArithmeticOverflowException When the difference does not fit in 64 bits.
	 *
	 * @param Money $other The amount to subtract.
	 * @return self The difference.
	 */
	public function subtract( Money $other ): self {
		CurrencyMismatchException::assertSameCurrency( $this->currency, $other->currency );

		$left  = $this->minorUnits;
		$right = $other->minorUnits;

		if ( ( $right < 0 && $left > PHP_INT_MAX + $right ) || ( $right > 0 && $left < PHP_INT_MIN + $right ) ) {
			throw ArithmeticOverflowException::in( 'Money::subtract()' );
		}

		return new self( $left - $right, $this->currency );
	}

	/**
	 * Returns the amount with the opposite sign.
	 *
	 * @since 0.1.0
	 *
	 * @throws ArithmeticOverflowException For the one amount whose negation does not fit in
	 *                                     64 bits, PHP_INT_MIN minor units.
	 *
	 * @return self The negated amount.
	 */
	public function negate(): self {
		if ( PHP_INT_MIN === $this->minorUnits ) {
			throw ArithmeticOverflowException::in( 'Money::negate()' );
		}

		return new self( -$this->minorUnits, $this->currency );
	}

	/**
	 * Multiplies by a factor or a percentage, exactly.
	 *
	 * The result is a Decimal in major units, because it usually has more digits than a minor
	 * unit; Money::ofDecimal() rounds it at the boundary the caller declares. 12.34 USD times
	 * 0.075 is '0.92550'.
	 *
	 * @since 0.1.0
	 *
	 * @param Decimal|Percentage $factor A factor (0.2 for twenty percent) or a Percentage.
	 * @return Decimal The product in major units, at the currency's exponent plus the factor's scale.
	 */
	public function multiply( Decimal|Percentage $factor ): Decimal {
		if ( $factor instanceof Percentage ) {
			$factor = $factor->toFactor();
		}

		return $this->toDecimal()->multiply( $factor );
	}

	/**
	 * Splits the amount into shares proportional to ratios, by largest remainder.
	 *
	 * Every share starts at its exact proportion rounded toward zero. The minor units left over
	 * — fewer than the number of shares — go one each to the shares with the largest remainders;
	 * a tie goes to the share that comes first in the ratios (an ordinal tie-break). So:
	 *
	 * - the shares always sum to the amount;
	 * - each share is within one minor unit of its exact proportion;
	 * - a zero ratio gets zero;
	 * - a negative amount splits into the negated shares of its absolute value;
	 * - the result depends only on the amount and the ratios.
	 *
	 * Money::of( 100, $usd )->allocate( array( 1, 1, 1 ) ) is 0.34, 0.33 and 0.33 USD.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When there is no ratio, a ratio is negative, or every
	 *                                   ratio is zero.
	 *
	 * @param array<int|string, int|Decimal> $ratios The ratios, in share order. Keys are kept.
	 * @return array<int|string, Money> The shares, keyed and ordered as the ratios.
	 */
	public function allocate( array $ratios ): array {
		if ( array() === $ratios ) {
			throw new \InvalidArgumentException( 'Allocation needs at least one ratio.' );
		}

		$weights = array();
		$total   = Decimal::ofUnscaled( 0, 0 );

		foreach ( $ratios as $key => $ratio ) {
			$weight = is_int( $ratio ) ? Decimal::ofUnscaled( $ratio, 0 ) : $ratio;

			if ( $weight->isNegative() ) {
				throw new \InvalidArgumentException( 'An allocation ratio cannot be negative.' );
			}

			$weights[ $key ] = $weight;
			$total           = $total->add( $weight );
		}

		if ( $total->isZero() ) {
			throw new \InvalidArgumentException( 'At least one allocation ratio must be greater than zero.' );
		}

		$amount    = Decimal::ofUnscaled( $this->minorUnits, 0 );
		$magnitude = $amount->isNegative() ? $amount->negate() : $amount;
		$shares    = array();
		$remainder = array();
		$allocated = Decimal::ofUnscaled( 0, 0 );

		foreach ( $weights as $key => $weight ) {
			// Share = magnitude × weight ÷ total. Keep its integer part and the numerator of its fraction.
			$exact             = $magnitude->multiply( $weight );
			$shares[ $key ]    = $exact->divide( $total, 0, RoundingMode::TowardZero );
			$remainder[ $key ] = $exact->subtract( $shares[ $key ]->multiply( $total ) );
			$allocated         = $allocated->add( $shares[ $key ] );
		}

		$left_over = $magnitude->subtract( $allocated )->toUnscaledInt();
		$position  = array_flip( array_keys( $remainder ) );
		$order     = array_keys( $remainder );

		usort(
			$order,
			static function ( $first, $second ) use ( $remainder, $position ): int {
				$by_remainder = $remainder[ $second ]->compare( $remainder[ $first ] );

				return 0 !== $by_remainder ? $by_remainder : $position[ $first ] <=> $position[ $second ];
			}
		);

		$one = Decimal::ofUnscaled( 1, 0 );

		foreach ( array_slice( $order, 0, $left_over ) as $key ) {
			$shares[ $key ] = $shares[ $key ]->add( $one );
		}

		$result = array();

		foreach ( $shares as $key => $share ) {
			$result[ $key ] = new self( ( $amount->isNegative() ? $share->negate() : $share )->toUnscaledInt(), $this->currency );
		}

		return $result;
	}

	/**
	 * Rounds the amount to the currency's cash rounding step.
	 *
	 * Cash rounding is separate from the ISO exponent: a Swiss franc amount keeps two decimal
	 * places but, rounded for cash to a step of 5 minor units, 1.02 becomes 1.00 and 1.03
	 * becomes 1.05. A step of 0 or 1 leaves the amount unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @throws CurrencyMismatchException   When the rule belongs to another currency.
	 * @throws ArithmeticOverflowException When the rounded amount does not fit in 64 bits.
	 *
	 * @param CurrencyRoundingRule $rule The currency's rounding rule: its step and its mode.
	 * @return self The rounded amount.
	 */
	public function roundToCashStep( CurrencyRoundingRule $rule ): self {
		CurrencyMismatchException::assertSameCurrency( $this->currency, $rule->currency() );

		$step = $rule->cashRoundingStepMinor();

		if ( $step <= 1 ) {
			return $this;
		}

		$step_size = Decimal::ofUnscaled( $step, 0 );
		$steps     = Decimal::ofUnscaled( $this->minorUnits, 0 )->divide( $step_size, 0, $rule->roundingMode() );

		return new self( $steps->multiply( $step_size )->toUnscaledInt(), $this->currency );
	}

	/**
	 * Compares with an amount in the same currency.
	 *
	 * @since 0.1.0
	 *
	 * @throws CurrencyMismatchException When the other amount is in another currency.
	 *
	 * @param Money $other The amount to compare with.
	 * @return int -1, 0 or 1 as this amount is smaller than, equal to or larger than the other.
	 */
	public function compare( Money $other ): int {
		CurrencyMismatchException::assertSameCurrency( $this->currency, $other->currency );

		return $this->minorUnits <=> $other->minorUnits;
	}

	/**
	 * Tells whether two amounts are equal: same currency and same number of minor units.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $other The amount to compare with.
	 * @return bool True when both the currency and the amount match; false for any other currency.
	 */
	public function equals( Money $other ): bool {
		return $this->currency->equals( $other->currency ) && $this->minorUnits === $other->minorUnits;
	}

	/**
	 * Tells whether the amount is zero.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for zero.
	 */
	public function isZero(): bool {
		return 0 === $this->minorUnits;
	}

	/**
	 * Tells whether the amount is below zero.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True below zero.
	 */
	public function isNegative(): bool {
		return $this->minorUnits < 0;
	}
}

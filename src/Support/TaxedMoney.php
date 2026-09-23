<?php
/**
 * TaxedMoney: an amount as net, tax and gross, which always add up
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * An amount with its tax: net, tax and gross in one currency.
 *
 * This class owns one fact: the invariant `net + tax = gross`, in minor units, for every
 * amount that carries tax. The constructor refuses a triple that
 * breaks it, and every operation builds its result so that it cannot: tax is computed once and
 * the third figure is derived from the other two, never rounded on its own.
 *
 * Both price-entry modes are supported with one effective rate:
 *
 * - fromNet(): the authored amount is net; tax = net × rate, rounded; gross = net + tax.
 * - fromGross(): the authored amount is gross; tax = gross × rate ÷ (1 + rate), rounded
 *   once; net = gross − tax.
 *
 * Several rates, compound rates, cross-zone policies and per-jurisdiction allocation are
 * built on this type by the tax module, not here.
 *
 * @since 0.1.0
 */
final class TaxedMoney {

	/**
	 * The amount before tax.
	 *
	 * @since 0.1.0
	 *
	 * @var Money
	 */
	private Money $net;

	/**
	 * The tax.
	 *
	 * @since 0.1.0
	 *
	 * @var Money
	 */
	private Money $tax;

	/**
	 * The amount including tax.
	 *
	 * @since 0.1.0
	 *
	 * @var Money
	 */
	private Money $gross;

	/**
	 * Creates a taxed amount from three figures that already add up, such as a stored row.
	 *
	 * Figures in more than one currency are refused with a CurrencyMismatchException.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When net plus tax is not gross, to the minor unit.
	 *
	 * @param Money $net   The amount before tax.
	 * @param Money $tax   The tax.
	 * @param Money $gross The amount including tax.
	 */
	public function __construct( Money $net, Money $tax, Money $gross ) {
		CurrencyMismatchException::assertSameCurrency( $net->currency(), $tax->currency() );
		CurrencyMismatchException::assertSameCurrency( $net->currency(), $gross->currency() );

		if ( ! $net->add( $tax )->equals( $gross ) ) {
			throw new \InvalidArgumentException( 'Net plus tax must equal gross, to the minor unit.' );
		}

		$this->net   = $net;
		$this->tax   = $tax;
		$this->gross = $gross;
	}

	/**
	 * Creates a zero taxed amount.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $currency The currency.
	 * @return self Zero net, zero tax, zero gross.
	 */
	public static function zero( Currency $currency ): self {
		$zero = Money::zero( $currency );

		return new self( $zero, $zero, $zero );
	}

	/**
	 * Adds tax to an authored net amount.
	 *
	 * @since 0.1.0
	 *
	 * @param Money              $net  The authored amount, before tax.
	 * @param Decimal|Percentage $rate The effective rate: a Percentage, or a Decimal factor
	 *                                 (0.2 for twenty percent). Zero or more.
	 * @param RoundingMode       $mode How the tax is rounded to the minor unit.
	 * @return self The net amount, its tax and their sum.
	 */
	public static function fromNet( Money $net, Decimal|Percentage $rate, RoundingMode $mode ): self {
		$tax = Money::ofDecimal( $net->multiply( self::factor( $rate ) ), $net->currency(), $mode );

		return new self( $net, $tax, $net->add( $tax ) );
	}

	/**
	 * Takes the tax out of an authored gross amount.
	 *
	 * The tax is gross × rate ÷ (1 + rate), divided straight to the currency's minor unit so
	 * that it is rounded once; the net amount is what remains.
	 *
	 * @since 0.1.0
	 *
	 * @param Money              $gross The authored amount, including tax.
	 * @param Decimal|Percentage $rate  The effective rate: a Percentage, or a Decimal factor
	 *                                  (0.2 for twenty percent). Zero or more.
	 * @param RoundingMode       $mode  How the tax is rounded to the minor unit.
	 * @return self The net amount, the tax and the gross amount.
	 */
	public static function fromGross( Money $gross, Decimal|Percentage $rate, RoundingMode $mode ): self {
		$factor   = self::factor( $rate );
		$currency = $gross->currency();
		$tax      = $gross->multiply( $factor )->divide( Decimal::ofUnscaled( 1, 0 )->add( $factor ), $currency->exponent(), $mode );
		$tax      = Money::ofDecimal( $tax, $currency, $mode );

		return new self( $gross->subtract( $tax ), $tax, $gross );
	}

	/**
	 * Returns the amount before tax.
	 *
	 * @since 0.1.0
	 *
	 * @return Money The net amount.
	 */
	public function net(): Money {
		return $this->net;
	}

	/**
	 * Returns the tax.
	 *
	 * @since 0.1.0
	 *
	 * @return Money The tax.
	 */
	public function tax(): Money {
		return $this->tax;
	}

	/**
	 * Returns the amount including tax.
	 *
	 * @since 0.1.0
	 *
	 * @return Money The gross amount.
	 */
	public function gross(): Money {
		return $this->gross;
	}

	/**
	 * Returns the currency of all three figures.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency.
	 */
	public function currency(): Currency {
		return $this->net->currency();
	}

	/**
	 * Adds a taxed amount in the same currency, figure by figure.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $other The taxed amount to add.
	 * @return self The sum; its figures still add up because each pair does.
	 */
	public function add( TaxedMoney $other ): self {
		return new self( $this->net->add( $other->net ), $this->tax->add( $other->tax ), $this->gross->add( $other->gross ) );
	}

	/**
	 * Subtracts a taxed amount in the same currency, figure by figure.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $other The taxed amount to subtract.
	 * @return self The difference.
	 */
	public function subtract( TaxedMoney $other ): self {
		return new self( $this->net->subtract( $other->net ), $this->tax->subtract( $other->tax ), $this->gross->subtract( $other->gross ) );
	}

	/**
	 * Returns the taxed amount with the opposite sign.
	 *
	 * @since 0.1.0
	 *
	 * @return self The negated net, tax and gross.
	 */
	public function negate(): self {
		return new self( $this->net->negate(), $this->tax->negate(), $this->gross->negate() );
	}

	/**
	 * Splits the taxed amount into shares proportional to ratios.
	 *
	 * The net amount and the tax are each allocated by Money::allocate() — largest remainder,
	 * ordinal tie-break — and each share's gross is its net plus its tax. So every share keeps
	 * `net + tax = gross`, each figure sums to its total across the shares, net and tax are
	 * each within one minor unit of their exact proportions, and gross, being derived, is
	 * within two.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, int|Decimal> $ratios The ratios, in share order, as Money::allocate() takes them.
	 * @return array<int|string, TaxedMoney> The shares, keyed and ordered as the ratios.
	 */
	public function allocate( array $ratios ): array {
		$taxes  = $this->tax->allocate( $ratios );
		$shares = array();

		foreach ( $this->net->allocate( $ratios ) as $key => $net ) {
			$shares[ $key ] = new self( $net, $taxes[ $key ], $net->add( $taxes[ $key ] ) );
		}

		return $shares;
	}

	/**
	 * Tells whether two taxed amounts are equal, figure by figure.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $other The taxed amount to compare with.
	 * @return bool True when net, tax and gross all match.
	 */
	public function equals( TaxedMoney $other ): bool {
		return $this->net->equals( $other->net ) && $this->tax->equals( $other->tax ) && $this->gross->equals( $other->gross );
	}

	/**
	 * Returns the multiplication factor of a rate, refusing a negative one.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the rate is negative.
	 *
	 * @param Decimal|Percentage $rate A Percentage, or a Decimal factor.
	 * @return Decimal The factor.
	 */
	private static function factor( Decimal|Percentage $rate ): Decimal {
		$factor = $rate instanceof Percentage ? $rate->toFactor() : $rate;

		if ( $factor->isNegative() ) {
			throw new \InvalidArgumentException( 'A tax rate cannot be negative.' );
		}

		return $factor;
	}
}

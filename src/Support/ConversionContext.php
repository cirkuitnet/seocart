<?php
/**
 * ConversionContext: a frozen exchange rate with its identity
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * An exchange rate frozen as a quoted fact: which currencies, which way, how precise, from
 * where and when.
 *
 * This class owns one fact: what a conversion between two currencies was based on, as the
 * immutable `conversion_contexts` row records it (target architecture §21.2), and the
 * conversion arithmetic at that rate. An order references its context, so a refund months
 * later converts at the original rate, and a report never re-converts at today's.
 *
 * The rate reads "1 unit of the base currency = rate units of the quote currency"; the only
 * direction is `base_to_quote`, stored anyway because an inverted rate is the most common
 * multi-currency defect and a bare number cannot reveal one. The rate carries the scale it was
 * quoted at (`rate_scale`), at most 12 places, which the `DECIMAL(24,12)` column holds; the
 * arithmetic uses the quoted scale, so a replay is exact to the last minor unit.
 *
 * The fingerprint is the SHA-256 hex digest of the canonical form, which is these nine lines
 * joined by a line feed, with no trailing line feed:
 *
 *     seocart.conversion_context.v1
 *     base_currency={ISO code}
 *     quote_currency={ISO code}
 *     direction=base_to_quote
 *     rate={the rate, written with exactly rate_scale fractional digits}
 *     rate_scale={integer}
 *     source={source}
 *     source_version={integer}
 *     quoted_at={Y-m-d\TH:i:s\Z, UTC, whole seconds}
 *
 * `quoted_at` is a `DATETIME` without fractional seconds, so the context keeps whole seconds
 * and drops any fraction when it is created; the value it holds is the value storage returns,
 * and a context read back from its row has the same fingerprint.
 *
 * @since 0.1.0
 */
final class ConversionContext {

	/**
	 * The only direction a rate is quoted in: one base unit is `rate` quote units.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DIRECTION_BASE_TO_QUOTE = 'base_to_quote';

	/**
	 * The most fractional digits a rate may have: the scale of `conversion_contexts.rate`.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAXIMUM_RATE_SCALE = 12;

	/**
	 * The first line of the canonical form; it changes if the form ever does.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CANONICAL_FORM_VERSION = 'seocart.conversion_context.v1';

	/**
	 * The currency one unit of which the rate prices.
	 *
	 * @since 0.1.0
	 *
	 * @var Currency
	 */
	private Currency $baseCurrency;

	/**
	 * The currency the rate is expressed in.
	 *
	 * @since 0.1.0
	 *
	 * @var Currency
	 */
	private Currency $quoteCurrency;

	/**
	 * The rate, at its quoted scale.
	 *
	 * @since 0.1.0
	 *
	 * @var Decimal
	 */
	private Decimal $rate;

	/**
	 * Where the rate came from, for example 'manual' or 'identity'.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * The version of the source's rate set this rate was taken from.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $sourceVersion;

	/**
	 * When the rate was quoted, in UTC, to the second.
	 *
	 * @since 0.1.0
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $quotedAt;

	/**
	 * Creates a context, refusing one that cannot be a correct quoted rate.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the direction is not base_to_quote; the rate is
	 *                                   zero or negative; the rate scale is outside 0 to 12 or
	 *                                   differs from the rate's own scale; the source is not
	 *                                   1 to 32 characters of a-z, 0-9, '_', '.' and '-'; or
	 *                                   the source version is negative.
	 *
	 * @param Currency           $base_currency  The currency one unit of which the rate prices.
	 * @param Currency           $quote_currency The currency the rate is expressed in.
	 * @param string             $direction      Always self::DIRECTION_BASE_TO_QUOTE.
	 * @param Decimal            $rate           The rate, written at its quoted scale.
	 * @param int                $rate_scale     The quoted scale, which the rate must have.
	 * @param string             $source         Where the rate came from, for example 'manual'.
	 * @param int                $source_version The version of the source's rate set.
	 * @param \DateTimeImmutable $quoted_at      When the rate was quoted. Any time zone; kept in
	 *                                           UTC, to the second.
	 */
	public function __construct(
		Currency $base_currency,
		Currency $quote_currency,
		string $direction,
		Decimal $rate,
		int $rate_scale,
		string $source,
		int $source_version,
		\DateTimeImmutable $quoted_at
	) {
		if ( self::DIRECTION_BASE_TO_QUOTE !== $direction ) {
			throw new \InvalidArgumentException( 'A conversion context is quoted base_to_quote: one base unit is rate quote units.' );
		}

		if ( 1 !== $rate->sign() ) {
			throw new \InvalidArgumentException( 'An exchange rate must be greater than zero.' );
		}

		if ( $rate_scale < 0 || $rate_scale > self::MAXIMUM_RATE_SCALE || $rate->scale() !== $rate_scale ) {
			throw new \InvalidArgumentException( 'An exchange rate is written with exactly rate_scale fractional digits, and rate_scale is from 0 to 12.' );
		}

		if ( 1 !== preg_match( '/^[a-z0-9_.-]{1,32}\z/', $source ) ) {
			throw new \InvalidArgumentException( 'A rate source is 1 to 32 characters of a-z, 0-9, underscore, dot and hyphen.' );
		}

		if ( $source_version < 0 ) {
			throw new \InvalidArgumentException( 'A rate source version cannot be negative.' );
		}

		$utc = new \DateTimeZone( 'UTC' );

		$this->baseCurrency  = $base_currency;
		$this->quoteCurrency = $quote_currency;
		$this->rate          = $rate;
		$this->source        = $source;
		$this->sourceVersion = $source_version;
		$this->quotedAt      = ( new \DateTimeImmutable( '@' . $quoted_at->getTimestamp() ) )->setTimezone( $utc );
	}

	/**
	 * Returns the identity context of a currency: an amount converted to itself.
	 *
	 * An order in the base currency references this context, so reporting, refunds and the
	 * admin never branch on "no conversion" (data storage §3.10): rate 1.000000000000 at scale
	 * 12, source 'identity', source version 0.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency           $currency  The currency, both base and quote.
	 * @param \DateTimeImmutable $quoted_at When the identity context was created.
	 * @return self The identity context.
	 */
	public static function identity( Currency $currency, \DateTimeImmutable $quoted_at ): self {
		return new self( $currency, $currency, self::DIRECTION_BASE_TO_QUOTE, Decimal::of( '1.000000000000' ), self::MAXIMUM_RATE_SCALE, 'identity', 0, $quoted_at );
	}

	/**
	 * Returns the currency one unit of which the rate prices.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The base currency.
	 */
	public function baseCurrency(): Currency {
		return $this->baseCurrency;
	}

	/**
	 * Returns the currency the rate is expressed in.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The quote currency.
	 */
	public function quoteCurrency(): Currency {
		return $this->quoteCurrency;
	}

	/**
	 * Returns the direction of the rate.
	 *
	 * @since 0.1.0
	 *
	 * @return string Always self::DIRECTION_BASE_TO_QUOTE.
	 */
	public function direction(): string {
		return self::DIRECTION_BASE_TO_QUOTE;
	}

	/**
	 * Returns the rate, at its quoted scale.
	 *
	 * @since 0.1.0
	 *
	 * @return Decimal The rate: one base unit is this many quote units.
	 */
	public function rate(): Decimal {
		return $this->rate;
	}

	/**
	 * Returns the scale the rate was quoted at.
	 *
	 * @since 0.1.0
	 *
	 * @return int The number of fractional digits, 0 to 12.
	 */
	public function rateScale(): int {
		return $this->rate->scale();
	}

	/**
	 * Returns where the rate came from.
	 *
	 * @since 0.1.0
	 *
	 * @return string The source, for example 'manual'.
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Returns the version of the source's rate set.
	 *
	 * @since 0.1.0
	 *
	 * @return int The source version.
	 */
	public function sourceVersion(): int {
		return $this->sourceVersion;
	}

	/**
	 * Returns when the rate was quoted.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The instant, in UTC, to the second.
	 */
	public function quotedAt(): \DateTimeImmutable {
		return $this->quotedAt;
	}

	/**
	 * Returns the canonical form the fingerprint is computed from, as the class docblock defines it.
	 *
	 * @since 0.1.0
	 *
	 * @return string Nine lines joined by a line feed.
	 */
	public function canonicalForm(): string {
		return implode(
			"\n",
			array(
				self::CANONICAL_FORM_VERSION,
				'base_currency=' . $this->baseCurrency->code(),
				'quote_currency=' . $this->quoteCurrency->code(),
				'direction=' . self::DIRECTION_BASE_TO_QUOTE,
				'rate=' . $this->rate->toString(),
				'rate_scale=' . $this->rate->scale(),
				'source=' . $this->source,
				'source_version=' . $this->sourceVersion,
				'quoted_at=' . $this->quotedAt->format( 'Y-m-d\TH:i:s\Z' ),
			)
		);
	}

	/**
	 * Returns the fingerprint stored in `conversion_contexts.fingerprint`.
	 *
	 * @since 0.1.0
	 *
	 * @return string The SHA-256 hex digest of the canonical form: 64 lower-case hexadecimal digits.
	 */
	public function fingerprint(): string {
		return hash( 'sha256', $this->canonicalForm() );
	}

	/**
	 * Converts a base-currency amount to the quote currency, exactly.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $base An amount in the base currency.
	 * @return Decimal The amount in quote major units, at the base exponent plus the rate scale.
	 */
	public function convertToQuote( Money $base ): Decimal {
		CurrencyMismatchException::assertSameCurrency( $this->baseCurrency, $base->currency() );

		return $base->multiply( $this->rate );
	}

	/**
	 * Converts a base-currency amount to the quote currency and rounds it once.
	 *
	 * @since 0.1.0
	 *
	 * @param Money        $base An amount in the base currency.
	 * @param RoundingMode $mode How the converted amount is rounded to the quote minor unit.
	 * @return Money The amount in the quote currency.
	 */
	public function convertToQuoteMoney( Money $base, RoundingMode $mode ): Money {
		return Money::ofDecimal( $this->convertToQuote( $base ), $this->quoteCurrency, $mode );
	}

	/**
	 * Converts a quote-currency amount back to the base currency, to a stated scale.
	 *
	 * @since 0.1.0
	 *
	 * @param Money        $quote An amount in the quote currency.
	 * @param int          $scale The number of fractional digits of the result.
	 * @param RoundingMode $mode  How the quotient loses the digits beyond that scale.
	 * @return Decimal The amount in base major units.
	 */
	public function convertToBase( Money $quote, int $scale, RoundingMode $mode ): Decimal {
		CurrencyMismatchException::assertSameCurrency( $this->quoteCurrency, $quote->currency() );

		return $quote->toDecimal()->divide( $this->rate, $scale, $mode );
	}

	/**
	 * Converts a quote-currency amount back to the base currency and rounds it once.
	 *
	 * @since 0.1.0
	 *
	 * @param Money        $quote An amount in the quote currency.
	 * @param RoundingMode $mode  How the converted amount is rounded to the base minor unit.
	 * @return Money The amount in the base currency.
	 */
	public function convertToBaseMoney( Money $quote, RoundingMode $mode ): Money {
		return Money::ofDecimal( $this->convertToBase( $quote, $this->baseCurrency->exponent(), $mode ), $this->baseCurrency, $mode );
	}
}

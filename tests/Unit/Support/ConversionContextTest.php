<?php
/**
 * Tests ConversionContext: a frozen, fingerprinted exchange rate
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyMismatchException;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\MoneyAssertions;

/**
 * Proves the context's refusals, its canonical form and fingerprint, and conversion both ways.
 *
 * The expected fingerprint was computed outside PHP, with the system's sha256 tool, from the
 * canonical form written out by hand; it is not recomputed from the implementation.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class ConversionContextTest extends TestCase {

	use MoneyAssertions;

	/**
	 * The fingerprint of self::usdToEur(), as the system's sha256 tool printed it.
	 *
	 * It is the digest of this canonical form, nine lines joined by line feeds:
	 *
	 *     seocart.conversion_context.v1
	 *     base_currency=USD
	 *     quote_currency=EUR
	 *     direction=base_to_quote
	 *     rate=0.920000
	 *     rate_scale=6
	 *     source=manual
	 *     source_version=42
	 *     quoted_at=2026-09-22T10:00:00Z
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const USD_TO_EUR_FINGERPRINT = '3ef430e2be2847403eae6b04374914eea068b9034f4d650a167f3c16b62061e3';

	/**
	 * The fingerprint of the US dollar's identity context, as the system's sha256 tool printed it.
	 *
	 * It is the digest of this canonical form, nine lines joined by line feeds:
	 *
	 *     seocart.conversion_context.v1
	 *     base_currency=USD
	 *     quote_currency=USD
	 *     direction=base_to_quote
	 *     rate=1.000000000000
	 *     rate_scale=12
	 *     source=identity
	 *     source_version=0
	 *     quoted_at=1970-01-01T00:00:00Z
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const USD_IDENTITY_FINGERPRINT = 'fe0febd5a60e94f730d905bc2c95c527e4a54901c5b2f22e480556eda6e49130';

	/**
	 * Tests the fields a context keeps, the quoted instant in UTC and to the second.
	 *
	 * @since 0.1.0
	 */
	public function test_it_keeps_its_fields_with_the_instant_in_utc_to_the_second(): void {
		$context = new ConversionContext(
			Currency::of( 'USD' ),
			Currency::of( 'EUR' ),
			ConversionContext::DIRECTION_BASE_TO_QUOTE,
			Decimal::of( '0.920000' ),
			6,
			'manual',
			42,
			new \DateTimeImmutable( '2026-09-22 12:00:00.987654', new \DateTimeZone( 'Europe/Berlin' ) )
		);

		$this->assertSame( 'USD', $context->baseCurrency()->code() );
		$this->assertSame( 'EUR', $context->quoteCurrency()->code() );
		$this->assertSame( 'base_to_quote', $context->direction() );
		$this->assertSame( '0.920000', $context->rate()->toString() );
		$this->assertSame( 6, $context->rateScale() );
		$this->assertSame( 'manual', $context->source() );
		$this->assertSame( 42, $context->sourceVersion() );
		$this->assertSame( '2026-09-22 10:00:00.000000 UTC', $context->quotedAt()->format( 'Y-m-d H:i:s.u T' ), 'Berlin is two hours ahead in September; the fraction of a second is dropped.' );
	}

	/**
	 * Tests the SHA-256 fingerprint against the digest of the documented canonical form, computed outside PHP.
	 *
	 * @since 0.1.0
	 */
	public function test_the_fingerprint_is_the_sha256_of_the_documented_canonical_form(): void {
		$context = self::usdToEur();

		$this->assertSame( self::USD_TO_EUR_FINGERPRINT, $context->fingerprint() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $context->fingerprint() );
	}

	/**
	 * Tests that every field changes the fingerprint, and that the fraction of a second does not.
	 *
	 * @since 0.1.0
	 */
	public function test_every_field_changes_the_fingerprint(): void {
		$usd     = Currency::of( 'USD' );
		$eur     = Currency::of( 'EUR' );
		$quoted  = new \DateTimeImmutable( '2026-09-22 10:00:00', new \DateTimeZone( 'UTC' ) );
		$variant = array(
			'base'           => new ConversionContext( Currency::of( 'GBP' ), $eur, 'base_to_quote', Decimal::of( '0.920000' ), 6, 'manual', 42, $quoted ),
			'quote'          => new ConversionContext( $usd, Currency::of( 'GBP' ), 'base_to_quote', Decimal::of( '0.920000' ), 6, 'manual', 42, $quoted ),
			'rate'           => new ConversionContext( $usd, $eur, 'base_to_quote', Decimal::of( '0.920001' ), 6, 'manual', 42, $quoted ),
			'rate scale'     => new ConversionContext( $usd, $eur, 'base_to_quote', Decimal::of( '0.9200' ), 4, 'manual', 42, $quoted ),
			'source'         => new ConversionContext( $usd, $eur, 'base_to_quote', Decimal::of( '0.920000' ), 6, 'ecb', 42, $quoted ),
			'source version' => new ConversionContext( $usd, $eur, 'base_to_quote', Decimal::of( '0.920000' ), 6, 'manual', 43, $quoted ),
			'quoted at'      => new ConversionContext( $usd, $eur, 'base_to_quote', Decimal::of( '0.920000' ), 6, 'manual', 42, $quoted->modify( '+1 second' ) ),
		);

		foreach ( $variant as $field => $context ) {
			$this->assertNotSame( self::USD_TO_EUR_FINGERPRINT, $context->fingerprint(), $field );
		}

		$this->assertSame(
			self::USD_TO_EUR_FINGERPRINT,
			( new ConversionContext( $usd, $eur, 'base_to_quote', Decimal::of( '0.920000' ), 6, 'manual', 42, new \DateTimeImmutable( '2026-09-22 10:00:00.999', new \DateTimeZone( 'UTC' ) ) ) )->fingerprint(),
			'Storage keeps whole seconds, so a fraction cannot make a second fingerprint.'
		);
	}

	/**
	 * Tests the refusals: direction, rate at or below zero, scale, source and version.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_invalid_contexts
	 *
	 * @param string $direction      The direction.
	 * @param string $rate           The rate.
	 * @param int    $rate_scale     The declared scale.
	 * @param string $source         The source.
	 * @param int    $source_version The source version.
	 */
	public function test_an_impossible_context_is_refused( string $direction, string $rate, int $rate_scale, string $source, int $source_version ): void {
		$this->expectException( \InvalidArgumentException::class );

		new ConversionContext(
			Currency::of( 'USD' ),
			Currency::of( 'EUR' ),
			$direction,
			Decimal::of( $rate ),
			$rate_scale,
			$source,
			$source_version,
			new \DateTimeImmutable( '2026-09-22 10:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Provides contexts that must be refused.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string, int, string, int}> Test cases.
	 */
	public static function data_invalid_contexts(): array {
		return array(
			'inverted direction'            => array( 'quote_to_base', '0.920000', 6, 'manual', 1 ),
			'zero rate'                     => array( 'base_to_quote', '0.000000', 6, 'manual', 1 ),
			'negative rate'                 => array( 'base_to_quote', '-0.920000', 6, 'manual', 1 ),
			'rate written at another scale' => array( 'base_to_quote', '0.92', 6, 'manual', 1 ),
			'scale beyond the column'       => array( 'base_to_quote', '0.9200000000000', 13, 'manual', 1 ),
			'negative scale'                => array( 'base_to_quote', '1', -1, 'manual', 1 ),
			'empty source'                  => array( 'base_to_quote', '0.920000', 6, '', 1 ),
			'source with upper case'        => array( 'base_to_quote', '0.920000', 6, 'Manual', 1 ),
			'source with a space'           => array( 'base_to_quote', '0.920000', 6, 'a b', 1 ),
			'source with a line feed'       => array( 'base_to_quote', '0.920000', 6, "manual\nrate=2", 1 ),
			'source longer than 32'         => array( 'base_to_quote', '0.920000', 6, str_repeat( 'a', 33 ), 1 ),
			'negative source version'       => array( 'base_to_quote', '0.920000', 6, 'manual', -1 ),
		);
	}

	/**
	 * Tests the identity context: one currency converted to itself.
	 *
	 * @since 0.1.0
	 */
	public function test_the_identity_context_converts_an_amount_to_itself(): void {
		$usd      = Currency::of( 'USD' );
		$identity = ConversionContext::identity( $usd );

		$this->assertTrue( $identity->baseCurrency()->equals( $identity->quoteCurrency() ) );
		$this->assertSame( '1.000000000000', $identity->rate()->toString() );
		$this->assertSame( 12, $identity->rateScale() );
		$this->assertSame( 'identity', $identity->source() );
		$this->assertSame( 0, $identity->sourceVersion() );
		$this->assertMoneyEquals( Money::of( 1234, $usd ), $identity->convertToQuoteMoney( Money::of( 1234, $usd ), RoundingMode::HalfUp ) );
		$this->assertMoneyEquals( Money::of( -1234, $usd ), $identity->convertToBaseMoney( Money::of( -1234, $usd ), RoundingMode::TowardZero ) );
	}

	/**
	 * Tests that a currency has one identity context, whenever and wherever it is built.
	 *
	 * The expected fingerprint is a constant computed outside PHP, so the test passes only if the
	 * identity context is quoted at the fixed instant it documents, on whatever day it runs. The
	 * second call is made under another default time zone, on the other side of the date line.
	 *
	 * @since 0.1.0
	 */
	public function test_the_identity_context_is_the_same_on_every_day(): void {
		$default = date_default_timezone_get();

		try {
			$first = ConversionContext::identity( Currency::of( 'USD' ) );

			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- The test proves the identity context ignores the time zone; the finally block restores it.
			date_default_timezone_set( 'Pacific/Kiritimati' );

			$second = ConversionContext::identity( Currency::of( 'USD' ) );
		} finally {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restores the zone the test changed.
			date_default_timezone_set( $default );
		}

		$this->assertSame( '1970-01-01T00:00:00+00:00', $first->quotedAt()->format( DATE_ATOM ) );
		$this->assertSame( self::USD_IDENTITY_FINGERPRINT, $first->fingerprint() );
		$this->assertSame( $first->fingerprint(), $second->fingerprint() );
		$this->assertNotSame( $first->fingerprint(), ConversionContext::identity( Currency::of( 'EUR' ) )->fingerprint(), 'Each currency has its own identity context.' );
	}

	/**
	 * Tests that a context from a currency to itself must have a rate of exactly 1, at any scale.
	 *
	 * Otherwise 100 US dollars would convert to 200 US dollars.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_same_currency_rates
	 *
	 * @param string $rate     The rate.
	 * @param bool   $accepted Whether the context is accepted.
	 */
	public function test_a_same_currency_context_has_a_rate_of_exactly_one( string $rate, bool $accepted ): void {
		$usd   = Currency::of( 'USD' );
		$built = null;

		try {
			$built = new ConversionContext( $usd, $usd, 'base_to_quote', Decimal::of( $rate ), Decimal::of( $rate )->scale(), 'manual', 1, new \DateTimeImmutable( '@0' ) );
		} catch ( \InvalidArgumentException $refused ) {
			$this->assertFalse( $accepted, 'A rate of exactly 1 was refused: ' . $rate );
			$this->assertSame( 'A conversion from a currency to itself has a rate of exactly 1.', $refused->getMessage() );

			return;
		}

		$this->assertTrue( $accepted, 'A same-currency context was accepted at the rate ' . $rate );
		$this->assertMoneyEquals( Money::of( 10000, $usd ), $built->convertToQuoteMoney( Money::of( 10000, $usd ), RoundingMode::HalfUp ) );
	}

	/**
	 * Provides same-currency rates and whether each is accepted.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, bool}> Test cases.
	 */
	public static function data_same_currency_rates(): array {
		return array(
			'two'              => array( '2.000000', false ),
			'just below one'   => array( '0.999999', false ),
			'just above one'   => array( '1.000000000001', false ),
			'one, at scale 0'  => array( '1', true ),
			'one, at scale 6'  => array( '1.000000', true ),
			'one, at scale 12' => array( '1.000000000000', true ),
		);
	}

	/**
	 * Tests converting a base amount to the quote currency, exactly and rounded.
	 *
	 * @since 0.1.0
	 */
	public function test_converting_to_the_quote_currency(): void {
		$context = self::usdToEur();
		$eur     = Currency::of( 'EUR' );

		$this->assertSame( '9.20000000', $context->convertToQuote( Money::of( 1000, Currency::of( 'USD' ) ) )->toString(), '10.00 USD at 0.92, exact, at scale 2 + 6.' );
		$this->assertMoneyEquals( Money::of( 920, $eur ), $context->convertToQuoteMoney( Money::of( 1000, Currency::of( 'USD' ) ), RoundingMode::HalfUp ) );

		$rounding = new ConversionContext( Currency::of( 'USD' ), $eur, 'base_to_quote', Decimal::of( '0.925' ), 3, 'manual', 1, new \DateTimeImmutable( '@0' ) );

		$this->assertMoneyEquals( Money::of( 5, $eur ), $rounding->convertToQuoteMoney( Money::of( 5, Currency::of( 'USD' ) ), RoundingMode::HalfUp ), '0.05 × 0.925 = 0.04625, half up.' );
		$this->assertMoneyEquals( Money::of( 4, $eur ), $rounding->convertToQuoteMoney( Money::of( 5, Currency::of( 'USD' ) ), RoundingMode::TowardZero ) );
	}

	/**
	 * Tests conversion into zero- and three-exponent currencies.
	 *
	 * @since 0.1.0
	 */
	public function test_converting_into_other_exponents(): void {
		$usd    = Currency::of( 'USD' );
		$quoted = new \DateTimeImmutable( '2026-09-22 10:00:00', new \DateTimeZone( 'UTC' ) );
		$to_jpy = new ConversionContext( $usd, Currency::of( 'JPY' ), 'base_to_quote', Decimal::of( '147.25' ), 2, 'manual', 1, $quoted );
		$to_kwd = new ConversionContext( $usd, Currency::of( 'KWD' ), 'base_to_quote', Decimal::of( '0.3071' ), 4, 'manual', 1, $quoted );

		$this->assertSame( '1817.0650', $to_jpy->convertToQuote( Money::of( 1234, $usd ) )->toString() );
		$this->assertMoneyEquals( Money::of( 1817, Currency::of( 'JPY' ) ), $to_jpy->convertToQuoteMoney( Money::of( 1234, $usd ), RoundingMode::HalfUp ) );
		$this->assertMoneyEquals( Money::of( 30710, Currency::of( 'KWD' ) ), $to_kwd->convertToQuoteMoney( Money::of( 10000, $usd ), RoundingMode::HalfUp ) );
		$this->assertMoneyEquals( Money::of( 10000, $usd ), $to_kwd->convertToBaseMoney( Money::of( 30710, Currency::of( 'KWD' ) ), RoundingMode::HalfUp ) );
	}

	/**
	 * Tests converting a quote amount back to the base currency.
	 *
	 * @since 0.1.0
	 */
	public function test_converting_back_to_the_base_currency(): void {
		$context = self::usdToEur();
		$usd     = Currency::of( 'USD' );
		$eur     = Currency::of( 'EUR' );

		$this->assertMoneyEquals( Money::of( 1000, $usd ), $context->convertToBaseMoney( Money::of( 920, $eur ), RoundingMode::HalfUp ) );
		$this->assertMoneyEquals( Money::of( 109, $usd ), $context->convertToBaseMoney( Money::of( 100, $eur ), RoundingMode::HalfUp ), '1.00 ÷ 0.92 = 1.0869…' );
		$this->assertMoneyEquals( Money::of( 108, $usd ), $context->convertToBaseMoney( Money::of( 100, $eur ), RoundingMode::TowardZero ) );
		$this->assertMoneyEquals( Money::of( -109, $usd ), $context->convertToBaseMoney( Money::of( -100, $eur ), RoundingMode::HalfUp ), 'Symmetric below zero.' );
		$this->assertMoneyEquals( Money::of( -108, $usd ), $context->convertToBaseMoney( Money::of( -100, $eur ), RoundingMode::TowardZero ) );
	}

	/**
	 * Tests that an amount in the wrong currency is refused in both directions.
	 *
	 * @since 0.1.0
	 */
	public function test_an_amount_in_the_wrong_currency_is_refused(): void {
		$context = self::usdToEur();
		$refused = 0;

		try {
			$context->convertToQuote( Money::of( 100, Currency::of( 'EUR' ) ) );
		} catch ( CurrencyMismatchException $mismatch ) {
			++$refused;
		}

		try {
			$context->convertToBaseMoney( Money::of( 100, Currency::of( 'USD' ) ), RoundingMode::HalfUp );
		} catch ( CurrencyMismatchException $mismatch ) {
			++$refused;
		}

		$this->assertSame( 2, $refused );
	}

	/**
	 * Returns the context the fingerprint test pins.
	 *
	 * @since 0.1.0
	 *
	 * @return ConversionContext One US dollar is 0.92 euro, quoted to six places.
	 */
	private static function usdToEur(): ConversionContext {
		return new ConversionContext(
			Currency::of( 'USD' ),
			Currency::of( 'EUR' ),
			ConversionContext::DIRECTION_BASE_TO_QUOTE,
			Decimal::of( '0.920000' ),
			6,
			'manual',
			42,
			new \DateTimeImmutable( '2026-09-22 10:00:00.5', new \DateTimeZone( 'UTC' ) )
		);
	}
}

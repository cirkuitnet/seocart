<?php
/**
 * MysqlPresentmentCurrencies: reads a currency's terms and its current rate, on MySQL
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Infrastructure;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Pricing\Application\PresentmentCurrencies;
use SEOCart\Pricing\Application\PresentmentCurrency;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Support\Decimal;
use SEOCart\Support\RoundingMode;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report stored data that cannot be a currency's terms, for the developer; they are never HTML.

/**
 * Finds an enabled currency and its rate in the current exchange-rate version, in one read, once per request.
 *
 * Owns one fact: how a currency's terms are read. The current version is the one the
 * installation record names, which is read with the record at no cost; a store that has never
 * saved a rate set has none, and then no currency but the base one is offered, without a query.
 * Otherwise the currency's row and its rate from the base currency at that version are read
 * together, by their keys, and the answer is kept for the rest of the request, so a request that
 * calculates many times reads once. A new version is a new answer, and so is another site of a
 * network: the version and the site's tables are part of what is kept.
 *
 * The rate is stored at twelve places and read back at the scale it was quoted at, so a rate
 * frozen from it has the fingerprint of the rate the merchant saved. It was quoted when its set
 * was saved, to the second.
 *
 * @since 0.1.0
 */
final class MysqlPresentmentCurrencies implements PresentmentCurrencies {

	/**
	 * An enabled currency, with its rate from the base currency at one version.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND = 'SELECT c.rounding_mode, c.cash_rounding_step_minor, c.conversion_fallback_allowed, r.rate, r.rate_scale, r.source, r.created_at FROM {currencies} c JOIN {exchange_rates} r ON r.base_currency = %s AND r.quote_currency = c.code AND r.version = %d WHERE c.code = %s AND c.is_enabled = 1';

	/**
	 * The connection, which names the current site's tables.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Sends the statements.
	 *
	 * @since 0.1.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Returns the current exchange-rate version, or null when none was ever saved.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): (int|null)
	 */
	private \Closure $currentVersion;

	/**
	 * The terms found in this request, by site, base currency, currency and version; null for a currency not offered.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, PresentmentCurrency|null>
	 */
	private array $found = array();

	/**
	 * Creates the reader. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db             The connection.
	 * @param \Closure $currentVersion Returns the current exchange-rate version (int), or null when none was ever saved.
	 *
	 * @phpstan-param \Closure(): (int|null) $currentVersion
	 */
	public function __construct( Database $db, \Closure $currentVersion ) {
		$this->db             = $db;
		$this->statements     = new ModuleStatements( $db, 'pricing', PricingTables::names() );
		$this->currentVersion = $currentVersion;
	}

	/**
	 * Returns the terms of a currency, or null when prices are not offered in it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the stored terms cannot be read: an unknown rounding
	 *                                   mode, or a rate with digits beyond its quoted scale.
	 *
	 * @param Currency $base     The store's base currency.
	 * @param Currency $currency The currency a cart is in.
	 * @return PresentmentCurrency|null The terms; the base currency's without a query; null for a
	 *                                  currency that is not enabled, or has no rate in the current version.
	 */
	public function find( Currency $base, Currency $currency ): ?PresentmentCurrency {
		if ( $currency->equals( $base ) ) {
			return PresentmentCurrency::base( $base );
		}

		$version = ( $this->currentVersion )();

		if ( null === $version ) {
			return null;
		}

		$key = $this->db->prefix() . ' ' . $base->code() . '>' . $currency->code() . '@' . $version;

		if ( ! array_key_exists( $key, $this->found ) ) {
			$row = $this->statements->rows( self::FIND, $base->code(), $version, $currency->code() )[0] ?? null;

			$this->found[ $key ] = null === $row ? null : self::terms( $row, $base, $currency, $version );
		}

		return $this->found[ $key ];
	}

	/**
	 * Builds a currency's terms from its row.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the row holds an unknown rounding mode, or a rate with digits beyond its quoted scale.
	 *
	 * @param array<string, mixed> $row      The row FIND read.
	 * @param Currency             $base     The base currency.
	 * @param Currency             $currency The currency.
	 * @param int                  $version  The version the rate belongs to.
	 * @return PresentmentCurrency The terms.
	 */
	private static function terms( array $row, Currency $base, Currency $currency, int $version ): PresentmentCurrency {
		$mode = RoundingMode::tryFrom( (string) $row['rounding_mode'] );

		if ( null === $mode ) {
			throw new \UnexpectedValueException( sprintf( 'The currency %1$s is rounded by %2$s, which is not a rounding mode.', $currency->code(), (string) $row['rounding_mode'] ) );
		}

		$scale = (int) $row['rate_scale'];

		return new PresentmentCurrency(
			new CurrencyRoundingRule( $currency, $mode, (int) $row['cash_rounding_step_minor'] ),
			1 === (int) $row['conversion_fallback_allowed'],
			new ConversionContext(
				$base,
				$currency,
				ConversionContext::DIRECTION_BASE_TO_QUOTE,
				self::quotedRate( (string) $row['rate'], $scale, $currency, $version ),
				$scale,
				(string) $row['source'],
				$version,
				new \DateTimeImmutable( (string) $row['created_at'], new \DateTimeZone( 'UTC' ) )
			)
		);
	}

	/**
	 * Returns a stored rate at the scale it was quoted at.
	 *
	 * The column keeps twelve places, and a rate quoted at fewer was padded with zeros when it was
	 * saved; those zeros are dropped here. Any other digit beyond the quoted scale cannot come from
	 * a saved rate, and is refused rather than rounded away.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When a digit beyond the quoted scale is not zero.
	 *
	 * @param string   $stored   The column's value, for example `0.912300000000`.
	 * @param int      $scale    The quoted scale, for example 5.
	 * @param Currency $currency The currency the rate is for, for the message.
	 * @param int      $version  The version the rate belongs to, for the message.
	 * @return Decimal The rate, for example 0.91230.
	 */
	private static function quotedRate( string $stored, int $scale, Currency $currency, int $version ): Decimal {
		$padded = Decimal::of( $stored );
		$rate   = $padded->rescale( $scale, RoundingMode::TowardZero );

		if ( ! $rate->equals( $padded ) ) {
			throw new \UnexpectedValueException( sprintf( 'The stored rate %1$s of %2$s in version %3$d has digits beyond its quoted scale of %4$d.', $stored, $currency->code(), $version, $scale ) );
		}

		return $rate;
	}
}

<?php
/**
 * Tests how a currency's terms and its current rate are read: once per request, at the quoted scale
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Order\Infrastructure\MysqlConversionContexts;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Pricing\Application\PresentmentCurrency;
use SEOCart\Pricing\Infrastructure\MysqlExchangeRates;
use SEOCart\Pricing\Infrastructure\MysqlPresentmentCurrencies;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Order\OrderTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * Reads the terms of currencies from real rows, saved by the real writer, and freezes what it read the way an order does.
 *
 * The current version is held by the test here, as the installation record holds it on a site;
 * the kernel's recording of it is ExchangeRatesTest's.
 *
 * Planted violations, each shown red and removed:
 *
 * - in MysqlPresentmentCurrencies::terms(), build the rate from the stored twelve places as they
 *   are (`Decimal::of( $row['rate'] )`, its scale as the scale): the rate read back is 0.912300000000
 *   at twelve places, so its fingerprint is not the one of the rate saved, and the rescale test
 *   fails;
 * - in MysqlPresentmentCurrencies::find(), key what is kept by the currency alone, without the
 *   version: after a new version is saved the request keeps pricing at the old rate, and the
 *   read-once test fails;
 * - in MysqlPresentmentCurrencies::FIND, drop `c.is_enabled = 1`: a disabled currency with a rate
 *   is offered, and the enabled-and-rated test fails on GBP;
 * - in MysqlPresentmentCurrencies::find(), leave the site out of what is kept: on a network the
 *   second site is priced at the first site's rate, and the network test fails (it runs with
 *   WP_MULTISITE=1).
 *
 * @group international
 *
 * @since 0.1.0
 */
final class PresentmentCurrenciesTest extends OrderTestCase {

	use PricesInCurrencies;

	/**
	 * The current exchange-rate version, as the installation record would hold it.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $version = null;

	/**
	 * Creates the pricing tables, with no rate saved.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createRateTables();

		$this->version = null;
	}

	/**
	 * Tests that the base currency, and any currency of a store that never saved rates, cost no query.
	 *
	 * @since 0.1.0
	 */
	public function test_the_base_currency_and_a_store_without_rates_read_nothing(): void {
		$currencies = $this->currencies();
		$this->enableCurrency( 'EUR' );

		$base = null;
		$euro = false;
		$log  = $this->captureQueries(
			static function () use ( $currencies, &$base, &$euro ): void {
				$base = $currencies->find( Currency::of( 'USD' ), Currency::of( 'USD' ) );
				$euro = $currencies->find( Currency::of( 'USD' ), Currency::of( 'EUR' ) );
			}
		);

		$this->assertQueryCount( 0, $log, 'Queries for the base currency and for a store with no rate version' );
		$this->assertEquals( PresentmentCurrency::base( Currency::of( 'USD' ) ), $base );
		$this->assertNull( $euro, 'No currency is offered at a rate that was never saved.' );
	}

	/**
	 * Tests that an enabled currency is read with its rate and its terms in one query, kept for the request, and read again for a new version.
	 *
	 * @since 0.1.0
	 */
	public function test_an_enabled_currency_is_read_once_per_version_with_its_rate_and_terms(): void {
		$this->enableCurrency( 'CHF', false, RoundingMode::TowardZero, 5 );
		$this->rates()->saveVersion( array( self::rateTo( 'CHF', '0.88660' ) ), Actor::user( 7 ) );

		$currencies = $this->currencies();
		$found      = array();
		$log        = $this->captureQueries(
			static function () use ( $currencies, &$found ): void {
				$found[] = $currencies->find( Currency::of( 'USD' ), Currency::of( 'CHF' ) );
				$found[] = $currencies->find( Currency::of( 'USD' ), Currency::of( 'CHF' ) );
			}
		);

		$this->assertQueryCount( 1, $log, 'Queries for a currency asked about twice in a request' );
		$this->assertNotNull( $found[0] );
		$this->assertSame( $found[0], $found[1] );

		$terms   = $found[0];
		$context = $terms->context;
		$saved   = (string) $this->db->fetchValue( 'SELECT created_at FROM %i WHERE version = 1', $this->db->table( PricingTables::EXCHANGE_RATES ) );

		$this->assertSame( array( RoundingMode::TowardZero, 5, false ), array( $terms->roundingRule->roundingMode(), $terms->roundingRule->cashRoundingStepMinor(), $terms->conversionFallbackAllowed ) );
		$this->assertSame( array( 'USD', 'CHF', '0.88660', 5, 'manual', 1 ), array( $context->baseCurrency()->code(), $context->quoteCurrency()->code(), $context->rate()->toString(), $context->rateScale(), $context->source(), $context->sourceVersion() ) );
		$this->assertSame( substr( $saved, 0, 19 ), $context->quotedAt()->format( 'Y-m-d H:i:s' ), 'The rate was quoted when its set was saved, to the second.' );

		$this->rates()->saveVersion( array( self::rateTo( 'CHF', '0.90' ) ), Actor::user( 7 ) );

		$next = null;
		$log  = $this->captureQueries(
			static function () use ( $currencies, &$next ): void {
				$next = $currencies->find( Currency::of( 'USD' ), Currency::of( 'CHF' ) );
			}
		);

		$this->assertQueryCount( 1, $log, 'Queries for the same currency once a new version is current' );
		$this->assertNotNull( $next );
		$this->assertSame( array( '0.90', 2 ), array( $next->context->rate()->toString(), $next->context->sourceVersion() ) );
	}

	/**
	 * Tests that one reader gives each site of a network its own rate, across switch_to_blog(), though both sites are at the same version.
	 *
	 * @since 0.1.0
	 */
	public function test_each_site_of_a_network_reads_its_own_rate(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs on a network only: set WP_MULTISITE=1.' );
		}

		$this->enableCurrency( 'EUR' );
		$this->rates()->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 7 ) );

		$site = wp_insert_site(
			array(
				'domain' => get_network()->domain,
				'path'   => '/rates-second/',
				'title'  => 'rates-second',
			)
		);

		$this->assertIsInt( $site, is_wp_error( $site ) ? $site->get_error_message() : 'The second site was not created.' );

		$currencies = $this->currencies();
		$read       = array( 'first' => $currencies->find( Currency::of( 'USD' ), Currency::of( 'EUR' ) )?->context->rate()->toString() );

		switch_to_blog( $site );

		try {
			$this->createRateTables();
			$this->enableCurrency( 'EUR' );
			$this->rates()->saveVersion( array( self::rateTo( 'EUR', '0.80' ) ), Actor::user( 7 ) );

			$read['second'] = $currencies->find( Currency::of( 'USD' ), Currency::of( 'EUR' ) )?->context->rate()->toString();
		} finally {
			foreach ( PricingTables::names() as $name ) {
				$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->db->table( $name ) );
			}

			restore_current_blog();
			wp_delete_site( $site );
		}

		$this->assertSame(
			array(
				'first'  => '0.91230',
				'second' => '0.80',
			),
			$read,
			'Each site, at version 1 of its own rates, is priced at its own rate.'
		);
	}

	/**
	 * Tests that a currency is offered only when it is enabled and has a rate in the current version.
	 *
	 * @since 0.1.0
	 */
	public function test_a_currency_needs_to_be_enabled_and_rated_in_the_current_version(): void {
		$this->enableCurrency( 'GBP', enabled: false );
		$this->enableCurrency( 'CHF' );
		$this->enableCurrency( 'JPY' );
		$this->enableCurrency( 'EUR' );

		$this->rates()->saveVersion( array( self::rateTo( 'GBP', '0.79' ), self::rateTo( 'JPY', '149.12' ), self::rateTo( 'EUR', '0.91230' ), self::rateTo( 'SEK', '10.5' ) ), Actor::user( 7 ) );
		$this->rates()->saveVersion( array( self::rateTo( 'GBP', '0.80' ), self::rateTo( 'EUR', '0.92' ), self::rateTo( 'SEK', '10.6' ) ), Actor::user( 7 ) );

		$offered = array();

		foreach ( array( 'GBP', 'CHF', 'JPY', 'SEK', 'EUR' ) as $code ) {
			$offered[ $code ] = $this->currencies()->find( Currency::of( 'USD' ), Currency::of( $code ) )?->context->rate()->toString();
		}

		$this->assertSame(
			array(
				'GBP' => null,
				'CHF' => null,
				'JPY' => null,
				'SEK' => null,
				'EUR' => '0.92',
			),
			$offered,
			'Disabled; enabled with no rate ever; rated in the earlier version only; rated but never enabled; enabled and rated now.'
		);
	}

	/**
	 * Tests that a rate saved at five places is read back at five, so the context frozen from it has the fingerprint of the rate saved, and one row however often it is frozen.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rate_is_read_back_at_its_quoted_scale_and_frozen_once(): void {
		$this->enableCurrency( 'EUR' );
		$this->rates()->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 7 ) );

		$stored   = $this->db->fetchRow( 'SELECT rate, rate_scale, created_at FROM %i WHERE version = 1', $this->db->table( PricingTables::EXCHANGE_RATES ) );
		$expected = new ConversionContext( Currency::of( 'USD' ), Currency::of( 'EUR' ), ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( '0.91230' ), 5, 'manual', 1, new \DateTimeImmutable( (string) ( $stored['created_at'] ?? '' ), new \DateTimeZone( 'UTC' ) ) );
		$first    = $this->currencies()->find( Currency::of( 'USD' ), Currency::of( 'EUR' ) );
		$second   = $this->currencies()->find( Currency::of( 'USD' ), Currency::of( 'EUR' ) );

		$this->assertSame( array( '0.912300000000', '5' ), array( $stored['rate'] ?? null, $stored['rate_scale'] ?? null ), 'The column keeps twelve places, and the scale it was quoted at.' );
		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertSame( array( '0.91230', 5 ), array( $first->context->rate()->toString(), $first->context->rateScale() ) );
		$this->assertSame( $expected->fingerprint(), $first->context->fingerprint() );

		$contexts = new MysqlConversionContexts( new OrderStatements( $this->db ), new SequentialIdGenerator( 1 ) );
		$id       = $this->db->transaction( static fn(): int => $contexts->freeze( $first->context ) );
		$again    = $this->db->transaction( static fn(): int => $contexts->freeze( $second->context ) );

		$this->assertSame( $id, $again, 'The same rate read twice is one frozen context.' );
		$this->assertSame( '1', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( OrderTables::CONVERSION_CONTEXTS ) ) );
		$this->assertSame( $expected->fingerprint(), $contexts->find( $id )?->fingerprint() );
	}

	/**
	 * Tests that a stored rate with a digit beyond its quoted scale is refused, not rounded: no saved rate has one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stored_rate_with_digits_beyond_its_scale_is_refused(): void {
		$this->enableCurrency( 'EUR' );
		$this->rates()->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 7 ) );
		$this->db->execute( 'UPDATE %i SET rate = %s WHERE version = 1', $this->db->table( PricingTables::EXCHANGE_RATES ), '0.912300000001' );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'beyond its quoted scale' );

		$this->currencies()->find( Currency::of( 'USD' ), Currency::of( 'EUR' ) );
	}

	/**
	 * Builds the reader over this test's connection, at the version the test holds. Each call is a new request.
	 *
	 * @since 0.1.0
	 *
	 * @return MysqlPresentmentCurrencies The reader.
	 */
	private function currencies(): MysqlPresentmentCurrencies {
		return new MysqlPresentmentCurrencies( $this->db, fn(): ?int => $this->version );
	}

	/**
	 * Builds the writer over this test's connection, recording each version it saves as the test's current one.
	 *
	 * @since 0.1.0
	 *
	 * @return MysqlExchangeRates The writer.
	 */
	private function rates(): MysqlExchangeRates {
		return self::ratesOver(
			$this->db,
			function ( int $version ): void {
				$this->version = $version;
			}
		);
	}
}

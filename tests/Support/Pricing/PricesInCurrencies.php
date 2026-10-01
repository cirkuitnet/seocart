<?php
/**
 * PricesInCurrencies: the currencies, exchange rates and installation record a test prices in other currencies with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Pricing\Application\ManualRate;
use SEOCart\Pricing\Infrastructure\Migrations\CreateRateTables;
use SEOCart\Pricing\Infrastructure\MysqlExchangeRates;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\KernelTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The installation record is read and put back past every cache, on purpose.

/**
 * Creates the pricing tables, enables currencies, and gives the site an installation record for the current rate version.
 *
 * Owns one fact: how a database test gets a store that sells in another currency. The tables are
 * created by their own migration and dropped by DatabaseTestCase. A currency is enabled by its
 * row, as the merchant's screen will write it. The current rate version lives in the installation
 * record, which a test site does not have: plantBootRecord() writes the record an installed site
 * has, at the code's schema head, so that the kernel's write gate is open and a saved version is
 * recorded; removeBootRecord(), called in tear_down(), puts back what was there before.
 *
 * For a DatabaseTestCase.
 *
 * @since 0.1.0
 */
trait PricesInCurrencies {

	/**
	 * The installation record's text before the test planted one; null when there was none.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $recordBefore = null;

	/**
	 * Whether the test planted an installation record.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $recordPlanted = false;

	/**
	 * Creates `currencies` and `exchange_rates`.
	 *
	 * @since 0.1.0
	 */
	protected function createRateTables(): void {
		( new CreateRateTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Enables a currency besides the base one, on terms the test chooses.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $code          The ISO code.
	 * @param bool         $fallback      Optional. Whether base prices may be converted into it. Default true.
	 * @param RoundingMode $mode          Optional. How its amounts are rounded. Default half up.
	 * @param int          $cashStepMinor Optional. Its cash rounding step, in minor units. Default 0, none.
	 * @param bool         $enabled       Optional. Whether prices are offered in it. Default true.
	 */
	protected function enableCurrency( string $code, bool $fallback = true, RoundingMode $mode = RoundingMode::HalfUp, int $cashStepMinor = 0, bool $enabled = true ): void {
		$this->db->execute(
			'INSERT INTO %i ( code, is_enabled, rounding_mode, cash_rounding_step_minor, conversion_fallback_allowed ) VALUES ( %s, %d, %s, %d, %d )',
			$this->db->table( PricingTables::CURRENCIES ),
			$code,
			(int) $enabled,
			$mode->value,
			$cashStepMinor,
			(int) $fallback
		);
	}

	/**
	 * Builds the rates writer over a connection as the kernel builds it, in a USD store, with the recording of the current version the test gives.
	 *
	 * Its rates lock is a GET_LOCK of that connection, as on a host whose locks are the server's.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db            The connection, which also runs the save's transaction.
	 * @param \Closure $recordVersion Records a saved version (int) as current.
	 * @return MysqlExchangeRates The writer.
	 *
	 * @phpstan-param \Closure(int): void $recordVersion
	 */
	protected static function ratesOver( Database $db, \Closure $recordVersion ): MysqlExchangeRates {
		return new MysqlExchangeRates( $db, $db, array( new LockService( $db, LockMode::GetLock ), 'withLock' ), static fn(): Currency => Currency::of( 'USD' ), $recordVersion );
	}

	/**
	 * Returns a rate from USD, the tests' base currency.
	 *
	 * @since 0.1.0
	 *
	 * @param string $quote The currency, such as `EUR`.
	 * @param string $rate  The rate, at the scale it is quoted at, such as `0.91230`.
	 * @return ManualRate The rate.
	 */
	protected static function rateTo( string $quote, string $rate ): ManualRate {
		return new ManualRate( Currency::of( 'USD' ), Currency::of( $quote ), Decimal::of( $rate ) );
	}

	/**
	 * Writes the installation record of a site installed at the code's schema head, through the real writer.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $rateVersion Optional. The rate version it records already. Default null, none.
	 */
	protected function plantBootRecord( ?int $rateVersion = null ): void {
		$this->recordBefore  = $this->storedBootRecord();
		$this->recordPlanted = true;
		$record              = KernelTestCase::installedRecord( KernelTestCase::codeHead() );

		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn(): BootRecord => null === $rateVersion ? $record : $record->withRateVersion( $rateVersion ) );
	}

	/**
	 * Puts back the installation record the test found, or removes the one it planted. Call it in tear_down().
	 *
	 * @since 0.1.0
	 */
	protected function removeBootRecord(): void {
		global $wpdb;

		if ( ! $this->recordPlanted ) {
			return;
		}

		if ( null === $this->recordBefore ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => BootOption::NAME ) );
		} else {
			$wpdb->update( $wpdb->options, array( 'option_value' => $this->recordBefore ), array( 'option_name' => BootOption::NAME ) );
		}

		$this->recordPlanted = false;

		wp_cache_flush();
	}

	/**
	 * Returns the rate version the stored installation record names, read past every cache.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The version, or null when the record names none or there is no record.
	 */
	protected function storedRateVersion(): ?int {
		return BootRecord::fromJson( $this->storedBootRecord() )->rateVersion();
	}

	/**
	 * Returns the stored installation record, read past every cache.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The text, or null when there is none.
	 */
	protected function storedBootRecord(): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, BootOption::NAME ) );

		return null === $value ? null : (string) $value;
	}
}

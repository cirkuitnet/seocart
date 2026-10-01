<?php
/**
 * Tests saving the exchange rates as the kernel wires it: appended as a new version, made current once committed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Pricing\Application\ExchangeRates;
use SEOCart\Pricing\Application\ManualRate;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * Saves rate sets through the container's writer, on a site installed at the code's schema head.
 *
 * Planted violations, each shown red and removed:
 *
 * - in MysqlExchangeRates::append(), record the version inside the transaction, before the
 *   insert, instead of after its commit: the option's row rolls back with the failed save, but the
 *   request's copy of the record and the cache keep version 1, with no rate stored, and the
 *   failed-insert test fails;
 * - in MysqlExchangeRates::saveVersion(), drop the refusal inside a transaction: the save asks for
 *   the rates lock inside the caller's transaction, which the lock service refuses with its own
 *   exception, and the refusal test fails on its type;
 * - in the kernel's recording of a version, drop the comparison with the recorded one: a save
 *   that finishes after a newer one moves the current version back, and the never-back test fails;
 * - in MysqlExchangeRates::saveVersion(), send one INSERT per rate: the save of two rates sends
 *   two, and the first test fails on the count.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class ExchangeRatesTest extends KernelTestCase {

	use PricesInCurrencies;

	/**
	 * The user recorded as having saved a set; no user row is needed, the column only names one.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SAVED_BY = 42;

	/**
	 * Creates the pricing tables. The kernel's base deletes the installation record before and after.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createRateTables();
	}

	/**
	 * Tests that each save appends its whole set at the next version in one statement, records that version, and leaves every earlier version as it was.
	 *
	 * @since 0.1.0
	 */
	public function test_each_save_appends_the_next_version_and_makes_it_current(): void {
		$this->plantRecord( self::installedRecord( self::codeHead() ) );

		$rates = $this->rates();
		$first = 0;
		$log   = $this->captureQueries(
			static function () use ( $rates, &$first ): void {
				$first = $rates->saveVersion( array( self::rateTo( 'EUR', '0.91230' ), self::rateTo( 'GBP', '0.79' ) ), Actor::user( 0 ) );
			}
		);

		$this->assertSame( 1, $first );
		$this->assertSame( 1, $this->storedRateVersion() );
		$this->assertQueryCount( 1, $log->matching( '/^INSERT INTO `' . preg_quote( $this->db->table( PricingTables::EXCHANGE_RATES ), '/' ) . '`/' ), 'Statements that insert the rates of a set of two' );

		$versionOne = $this->rows( 1 );

		$this->assertSame(
			array(
				array( 'USD', 'EUR', '0.912300000000', '5', 'manual', null ),
				array( 'USD', 'GBP', '0.790000000000', '2', 'manual', null ),
			),
			array_map( static fn( array $row ): array => array( $row['base_currency'], $row['quote_currency'], $row['rate'], $row['rate_scale'], $row['source'], $row['created_by'] ), $versionOne )
		);

		$this->assertSame( 2, $rates->saveVersion( array( self::rateTo( 'EUR', '0.92' ) ), Actor::user( self::SAVED_BY ) ) );
		$this->assertSame( 2, $this->storedRateVersion() );
		$this->assertSame( $versionOne, $this->rows( 1 ), 'A new version changes nothing of an earlier one.' );
		$this->assertSame( array( array( 'EUR', '0.920000000000', (string) self::SAVED_BY ) ), array_map( static fn( array $row ): array => array( $row['quote_currency'], $row['rate'], $row['created_by'] ), $this->rows( 2 ) ) );
	}

	/**
	 * Tests that a save whose insert fails stores nothing and records nothing: not in the option, not in the request's copy of the record, not in the cache.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_whose_insert_fails_records_no_version(): void {
		$this->plantRecord( self::installedRecord( self::codeHead() ) );

		$kernel = $this->container();
		$rates  = $kernel->get( ExchangeRates::class );
		$failed = new \RuntimeException( 'The insert failed on purpose.' );

		$this->assertInstanceOf( ExchangeRates::class, $rates );
		$this->beforeStatement(
			'/^INSERT INTO `' . preg_quote( $this->db->table( PricingTables::EXCHANGE_RATES ), '/' ) . '`/',
			static function () use ( $failed ): void {
				throw $failed;
			}
		);

		try {
			$rates->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) );
			$this->fail( 'A save whose insert failed returned a version.' );
		} catch ( \RuntimeException $thrown ) {
			$this->assertSame( $failed, $thrown );
		}

		$this->assertSame( array(), $this->rows( 1 ) );
		$this->assertNull( $this->storedRateVersion(), 'A version was made current that was never stored.' );
		$this->assertNull( $kernel->get( BootOption::class )->read()->rateVersion(), 'The request goes on pricing at a version that was never stored.' );
		$this->assertNull( BootRecord::fromJson( (string) get_option( BootOption::NAME ) )->rateVersion(), 'The cache holds a version that was never stored.' );
	}

	/**
	 * Tests that a save inside a transaction is refused before it sends anything: its lock is taken outside any.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_inside_a_transaction_is_refused(): void {
		$this->plantRecord( self::installedRecord( self::codeHead() ) );

		$rates = $this->rates();
		$sent  = $this->captureQueries(
			function () use ( $rates ): void {
				try {
					$this->db->transaction( static fn(): int => $rates->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) ) );
					$this->fail( 'A rate set was saved inside a transaction.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'outside any transaction', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $sent->matching( '/GET_LOCK|exchange_rates/' ), 'Statements of a save refused inside a transaction' );
		$this->assertSame( array(), $this->rows( 1 ) );
		$this->assertNull( $this->storedRateVersion() );
	}

	/**
	 * Tests that a save finishing after a newer one never moves the current version back, and that a site without a record is not given one.
	 *
	 * @since 0.1.0
	 */
	public function test_the_current_version_never_moves_back_and_no_record_is_created(): void {
		// The record already names version 5, as if a newer save had finished first.
		$this->plantRecord( self::installedRecord( self::codeHead() )->withRateVersion( 5 ) );

		$this->assertSame( 1, $this->rates()->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) ) );
		$this->assertSame( 5, $this->storedRateVersion() );

		$this->deleteRecord();

		// Without a record the write gate is shut and no lock mode is recorded, so the kernel's own transactions and locks are replaced.
		$ungated = $this->container(
			array(
				TransactionManager::class => fn(): TransactionManager => $this->db,
				LockService::class        => fn(): LockService => new LockService( $this->db, LockMode::GetLock ),
			)
		)->get( ExchangeRates::class );

		$this->assertInstanceOf( ExchangeRates::class, $ungated );
		$this->assertSame( 2, $ungated->saveVersion( array( self::rateTo( 'EUR', '0.92' ) ), Actor::user( 0 ) ) );
		$this->assertNull( $this->storedRecord(), 'Recording a version created an installation record.' );
	}

	/**
	 * Returns sets that cannot be a version.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: list<ManualRate>}> The sets.
	 */
	public static function data_refused_sets(): array {
		$fromPounds = new ManualRate( Currency::of( 'GBP' ), Currency::of( 'EUR' ), Decimal::of( '1.17' ) );

		return array(
			'no rate'                  => array( array() ),
			'a rate from another base' => array( array( self::rateTo( 'EUR', '0.91230' ), $fromPounds ) ),
			'two rates for one'        => array( array( self::rateTo( 'EUR', '0.91230' ), self::rateTo( 'EUR', '0.92' ) ) ),
		);
	}

	/**
	 * Tests that a set that cannot be a version is refused and nothing is written.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_refused_sets
	 *
	 * @param ManualRate[] $set The set.
	 *
	 * @phpstan-param list<ManualRate> $set
	 */
	public function test_a_set_that_cannot_be_a_version_is_refused( array $set ): void {
		$this->plantRecord( self::installedRecord( self::codeHead() ) );

		try {
			$this->rates()->saveVersion( $set, Actor::user( 0 ) );
			$this->fail( 'A set that cannot be a version was saved.' );
		} catch ( \InvalidArgumentException $refused ) {
			$this->assertNotSame( '', $refused->getMessage() );
		}

		$this->assertSame( '0', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->db->table( PricingTables::EXCHANGE_RATES ) ) );
		$this->assertNull( $this->storedRateVersion() );
	}

	/**
	 * Returns the container's writer, over this test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @return ExchangeRates The writer.
	 */
	private function rates(): ExchangeRates {
		$rates = $this->container()->get( ExchangeRates::class );

		$this->assertInstanceOf( ExchangeRates::class, $rates );

		return $rates;
	}

	/**
	 * Reads the rows of one version, in the order they were stored.
	 *
	 * @since 0.1.0
	 *
	 * @param int $version The version.
	 * @return list<array<string, mixed>> The rows.
	 */
	private function rows( int $version ): array {
		return $this->db->fetchAll( 'SELECT * FROM %i WHERE version = %d ORDER BY id', $this->db->table( PricingTables::EXCHANGE_RATES ), $version );
	}
}

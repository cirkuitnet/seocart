<?php
/**
 * Tests two rate saves at once: they publish two whole versions in turn
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\LockService;
use SEOCart\Pricing\Infrastructure\MysqlExchangeRates;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\RunningProbe;

/**
 * A save of {EUR} on this test's connection and a save of {GBP} in a process of its own meet at the worst moment.
 *
 * The store has version 1. This test's save is stopped just before it inserts its set, having
 * taken the rates lock and read version 1 as the newest; at that moment the other process starts
 * its save. The server must show the other save waiting for the rates lock; then this test's save
 * goes on and commits. The other save then reads version 2 as the newest and takes version 3. So
 * version 2 is exactly {EUR} and version 3 exactly {GBP}: two whole sets, one after the other.
 *
 * Planted violation, shown red and removed: in MysqlExchangeRates::underLock(), run the work
 * without the lock. The other save reads version 1 as the newest too, both insert at version 2,
 * and version 2 holds both halves: {EUR, GBP}.
 *
 * @group concurrency
 * @group international
 *
 * @since 0.1.0
 */
final class ExchangeRatesConcurrencyTest extends DatabaseTestCase {

	use PricesInCurrencies;

	/**
	 * Creates the pricing tables.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createRateTables();
	}

	/**
	 * Tests that two saves at once publish two whole versions, one after the other.
	 *
	 * @since 0.1.0
	 */
	public function test_two_saves_at_once_publish_two_whole_versions_in_turn(): void {
		global $wpdb;

		$rates = self::ratesOver( $this->db, static function (): void {} );

		$rates->saveVersion( array( self::rateTo( 'EUR', '0.91230' ), self::rateTo( 'GBP', '0.79' ) ), Actor::user( 0 ) );

		$lock   = (string) $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', LockService::serverLockName( $this->db->databaseName(), $this->db->prefix(), MysqlExchangeRates::LOCK ), MysqlExchangeRates::LOCK_WAIT_SECONDS );
		$probe  = null;
		$waited = null;
		$raced  = $this->beforeStatement(
			'/^INSERT INTO `' . preg_quote( $this->db->table( PricingTables::EXCHANGE_RATES ), '/' ) . '`/',
			function () use ( &$probe, &$waited, $lock ): void {
				$probe  = ChildProcessProbe::start( dirname( __DIR__, 2 ) . '/Support/Pricing/rate-save-probe.php', array( 'GBP', '0.80' ) );
				$waited = $this->awaitProbeWaitingOrEnd( $probe, $lock, 'User lock' );
			}
		);

		$mine = $rates->saveVersion( array( self::rateTo( 'EUR', '0.92' ) ), Actor::user( 0 ) );

		$this->assertTrue( $raced->fired, 'The other save was never started.' );
		$this->assertInstanceOf( RunningProbe::class, $probe );

		$report = $probe->finish();

		$this->assertSame(
			array(
				2 => array( 'EUR' ),
				3 => array( 'GBP' ),
			),
			$this->setsAfterTheFirst(),
			'Two saves at once must publish two whole sets, one version each.'
		);
		$this->assertSame( 2, $mine );
		$this->assertSame( array( 'version' => 3 ), $report, (string) wp_json_encode( $report ) );
		$this->assertTrue( $waited, 'The other save did not wait for the rates lock.' );
	}

	/**
	 * Reads the currencies of every version after the first, as committed.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, list<string>> The quote currencies, by version.
	 */
	private function setsAfterTheFirst(): array {
		$sets = array();

		foreach ( $this->db->fetchAll( 'SELECT version, quote_currency FROM %i WHERE version > 1 ORDER BY version, quote_currency', $this->db->table( PricingTables::EXCHANGE_RATES ) ) as $row ) {
			$sets[ (int) $row['version'] ][] = (string) $row['quote_currency'];
		}

		return $sets;
	}
}

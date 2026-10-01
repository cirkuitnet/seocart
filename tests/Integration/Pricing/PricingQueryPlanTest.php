<?php
/**
 * Tests the query plans of the pricing module's reads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Pricing\Infrastructure\Doctor\RateVersionCheck;
use SEOCart\Pricing\Infrastructure\MysqlPresentmentCurrencies;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\QueryPlan\AllowList;
use SEOCart\Tests\Support\QueryPlan\PlanRecorder;
use SEOCart\Tests\Support\QueryPlan\QueryPlan;
use SEOCart\Tests\Support\QueryPlan\ReadInventory;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The run counts the rows of the tables it explains, directly and unrecorded.

/**
 * Every SELECT the pricing module's source writes is sent, explained and judged by the query-plan rule.
 *
 * The module's reads run over a PlanRecorder: a currency's terms with its current rate, which a
 * calculation in that currency sends once per request, and the newest version stored, which a rate
 * save follows and doctor's rates check compares with the current one.
 * Each plugin SELECT is explained, printed and judged as the order and payment modules' are; the
 * reference dataset has no currencies or rates, and a store has a few dozen at most, so the tables
 * stay under the size at which the rule gates and the run records the plans. And every SELECT the
 * module's source writes must have been sent (ReadInventory), so no read goes unexplained.
 *
 * It runs only when SEOCART_QUERY_PLANS is 1, as `composer test:query-plans` sets it.
 *
 * Planted violation, shown red and removed: leave the currency's read out of exercise(): the run
 * names MysqlPresentmentCurrencies's FIND as a read it did not send.
 *
 * @group performance
 *
 * @since 0.1.0
 */
final class PricingQueryPlanTest extends DatabaseTestCase {

	use PricesInCurrencies;

	/**
	 * Skips the test unless the run is switched on, and creates the pricing tables.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		if ( '1' !== getenv( 'SEOCART_QUERY_PLANS' ) ) {
			$this->markTestSkipped( 'The query-plan run is `composer test:query-plans`.' );
		}

		$this->createRateTables();
	}

	/**
	 * Tests that every read of the pricing module is sent and keeps the query-plan rule, or is allowed with its reason.
	 *
	 * @since 0.1.0
	 */
	public function test_every_pricing_read_is_sent_and_keeps_the_rule(): void {
		global $wpdb;

		$allowed  = AllowList::load( dirname( __DIR__, 3 ) . '/' . AllowList::FILE );
		$recorder = PlanRecorder::open();

		try {
			$this->exercise( new Database( $recorder, true, $this->reporter() ) );
		} finally {
			$recorder->close();
		}

		$report   = array();
		$breaking = array();

		foreach ( $recorder->statements() as $statement ) {
			$plan    = QueryPlan::explain( $wpdb, $statement, static fn( string $table ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
			$verdict = array() === $plan->breaches ? 'ok' : ( isset( $allowed[ $statement->id() ] ) ? 'allowed' : 'BREAKS THE RULE' );

			if ( 'BREAKS THE RULE' === $verdict ) {
				$breaking = array_merge( $breaking, $plan->lines( $verdict ) );
			}

			$report = array_merge( $report, $plan->lines( $verdict ) );
		}

		fwrite( STDOUT, sprintf( "\nThe plans of the pricing module's %d SELECTs:\n%s\n", count( $recorder->statements() ), implode( "\n", $report ) ) );

		$heads  = ReadInventory::of( dirname( __DIR__, 3 ) . '/src/Pricing' );
		$tables = array_map( fn( string $name ): string => $this->db->table( $name ), PricingTables::names() );

		$this->assertNotSame( array(), $heads, 'The pricing source holds no read to judge.' );
		$this->assertSame( array(), ReadInventory::unsent( $heads, $recorder->allSent() ), 'A read the pricing module\'s source writes was not sent; send it in exercise(), so its plan is judged.' );
		$this->assertSame( array(), ReadInventory::unknown( $heads, $recorder->allSent(), $tables ), 'A read of the pricing tables came from outside src/Pricing.' );
		$this->assertSame( array(), $breaking, sprintf( "These pricing SELECTs break the query-plan rule:\n%s\n", implode( "\n", $breaking ) ) );
	}

	/**
	 * Runs the pricing module's reads through the Database under test, on a store with three currencies and two rate versions.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The Database over the recorder.
	 */
	private function exercise( Database $db ): void {
		$version = null;
		$rates   = self::ratesOver(
			$db,
			static function ( int $saved ) use ( &$version ): void {
				$version = $saved;
			}
		);

		foreach ( array( 'EUR', 'GBP', 'CHF' ) as $code ) {
			$this->enableCurrency( $code );
		}

		// A save follows the newest version stored.
		$rates->saveVersion( array( self::rateTo( 'EUR', '0.91230' ), self::rateTo( 'GBP', '0.79' ), self::rateTo( 'CHF', '0.8866' ) ), Actor::user( 0 ) );
		$rates->saveVersion( array( self::rateTo( 'EUR', '0.92' ), self::rateTo( 'GBP', '0.80' ), self::rateTo( 'CHF', '0.89' ) ), Actor::user( 0 ) );

		// A calculation's read of its currency's terms, with the rate of the current version.
		$this->assertNotNull( ( new MysqlPresentmentCurrencies( $db, static fn(): ?int => $version ) )->find( Currency::of( 'USD' ), Currency::of( 'GBP' ) ) );

		// Doctor's comparison of the current version with the newest stored.
		$this->assertTrue( ( new RateVersionCheck( $rates, static fn(): ?int => $version, static function (): void {} ) )->run()->passed );
	}
}

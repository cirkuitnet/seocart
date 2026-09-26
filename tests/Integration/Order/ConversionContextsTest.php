<?php
/**
 * Tests the conversion contexts: frozen once per rate fact, and read back exactly as frozen
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Infrastructure\MysqlConversionContexts;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * An equal context is one row, and a context read back has the fingerprint it was frozen with.
 *
 * Planted violation, shown red and removed: in MysqlConversionContexts::find(), build the rate
 * from the stored value as it is (`Decimal::of( $row['rate'] )`) instead of restoring its quoted
 * scale: the rate read back has twelve places, so it is not the context that was frozen.
 *
 * @since 0.1.0
 */
final class ConversionContextsTest extends OrderTestCase {

	/**
	 * Tests that freezing an equal context twice stores one row and returns its id both times, the identity context included.
	 *
	 * @since 0.1.0
	 */
	public function test_an_equal_context_is_frozen_once(): void {
		$contexts = $this->contexts();
		$rate     = $this->quoted( '1.10', 2 );

		$equal = $this->quoted( '1.10', 2 );

		$first  = $this->db->transaction( static fn(): int => $contexts->freeze( $rate ) );
		$second = $this->db->transaction( static fn(): int => $contexts->freeze( $equal ) );
		$usd    = $this->db->transaction( static fn(): int => $contexts->freeze( ConversionContext::identity( Currency::of( 'USD' ) ) ) );
		$again  = $this->db->transaction( static fn(): int => $contexts->freeze( ConversionContext::identity( Currency::of( 'USD' ) ) ) );

		$this->assertSame( $first, $second );
		$this->assertSame( $usd, $again, 'A currency has one identity context for the life of the store.' );
		$this->assertNotSame( $first, $usd );
		$this->assertSame( '2', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( OrderTables::CONVERSION_CONTEXTS ) ) );
	}

	/**
	 * Tests that a rate quoted at two places, stored at twelve, is read back at two, with the fingerprint it was frozen with.
	 *
	 * @since 0.1.0
	 */
	public function test_a_context_is_read_back_at_its_quoted_scale(): void {
		$contexts = $this->contexts();
		$frozen   = $this->quoted( '1.10', 2 );
		$id       = $this->db->transaction( static fn(): int => $contexts->freeze( $frozen ) );
		$row      = $this->db->fetchRow( 'SELECT rate, rate_scale, fingerprint FROM %i WHERE id = %d', $this->table( OrderTables::CONVERSION_CONTEXTS ), $id );
		$read     = $contexts->find( $id );

		$this->assertSame( '1.100000000000', $row['rate'] ?? null, 'The column keeps twelve places.' );
		$this->assertNotNull( $read );
		$this->assertSame( '1.10', $read->rate()->toString() );
		$this->assertSame( 2, $read->rateScale() );
		$this->assertSame( $row['fingerprint'] ?? null, $read->fingerprint() );
		$this->assertSame( $frozen->fingerprint(), $read->fingerprint() );
		$this->assertEquals( $frozen, $read );

		$identity = $this->db->transaction( static fn(): int => $contexts->freeze( ConversionContext::identity( Currency::of( 'EUR' ) ) ) );

		$this->assertEquals( ConversionContext::identity( Currency::of( 'EUR' ) ), $contexts->find( $identity ) );
		$this->assertNull( $contexts->find( $identity + 1000 ) );
	}

	/**
	 * Tests that a stored rate with a digit beyond its quoted scale is refused, not rounded: no frozen context stores one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stored_rate_with_digits_beyond_its_scale_is_refused(): void {
		$contexts = $this->contexts();
		$frozen   = $this->quoted( '1.10', 2 );
		$id       = $this->db->transaction( static fn(): int => $contexts->freeze( $frozen ) );

		$this->db->execute( 'UPDATE %i SET rate = %s WHERE id = %d', $this->table( OrderTables::CONVERSION_CONTEXTS ), '1.100000000001', $id );

		$this->expectException( \UnexpectedValueException::class );

		$contexts->find( $id );
	}

	/**
	 * Tests that freezing outside a transaction is refused before any statement: a context is written with the order that references it.
	 *
	 * @since 0.1.0
	 */
	public function test_freezing_outside_a_transaction_is_refused(): void {
		$queries = $this->captureQueries(
			function (): void {
				try {
					$this->contexts()->freeze( $this->quoted( '1.10', 2 ) );
					$this->fail( 'A context was frozen outside a transaction.' );
				} catch ( \LogicException $expected ) {
					$this->assertStringContainsString( 'inside a transaction', $expected->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $queries, 'statements before the refusal' );
	}

	/**
	 * Builds the repository over wpdb. A test builds one: each mints its uuids from 1.
	 *
	 * @since 0.1.0
	 *
	 * @return MysqlConversionContexts The repository.
	 */
	private function contexts(): MysqlConversionContexts {
		return new MysqlConversionContexts( new OrderStatements( $this->db ), new SequentialIdGenerator( 1 ) );
	}

	/**
	 * Returns a manual USD to EUR context at a rate and scale.
	 *
	 * @since 0.1.0
	 *
	 * @param string $rate  The rate, written at its scale.
	 * @param int    $scale The scale.
	 * @return ConversionContext The context.
	 */
	private function quoted( string $rate, int $scale ): ConversionContext {
		return new ConversionContext( Currency::of( 'USD' ), Currency::of( 'EUR' ), ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( $rate ), $scale, 'manual', 7, new \DateTimeImmutable( '2026-09-20 08:00:00', new \DateTimeZone( 'UTC' ) ) );
	}
}

<?php
/**
 * Tests doctor's check of the checkout: the binary log, stranded idempotency keys, and placements waiting for their payment
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Doctor\CheckoutChecks;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

/**
 * A key still claimed an hour after it was claimed is stranded: doctor reports it, --repair deletes it, and the client's retry then places its order; a key a placement holds now, or one placed, is never touched.
 *
 * A key's age is set and judged by the database clock.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class CheckoutChecksTest extends PlacementTestCase {

	/**
	 * Tests that a stranded key is reported and deleted, a young claim and an old placed key are left alone, and the retry then owns the key.
	 *
	 * Planted violation: in MysqlIdempotencyKeys, drop
	 * `AND created_at <= UTC_TIMESTAMP() - INTERVAL %d SECOND` from STRANDED and DELETE_STRANDED
	 * (and their values): doctor then reports, and --repair deletes, the key a placement is holding
	 * right now.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stranded_key_is_reported_and_deleted_and_the_retry_places(): void {
		$stranded = $this->claimCommitted( self::keyHash( 'stranded' ), self::digest( 'request 1' ) );
		$young    = $this->claimCommitted( self::keyHash( 'in flight' ), self::digest( 'request 2' ) );
		$placed   = $this->db->transaction(
			function (): IdempotencyClaim {
				$claim = $this->keys->claim( self::SCOPE, self::keyHash( 'placed' ), self::digest( 'request 3' ), self::KEY_TTL );

				$this->keys->complete( $claim->id, 42, '{"status":201}' );

				return $claim;
			}
		);

		$this->keyClaimedAgo( $stranded->id, 2 * 3600 );
		$this->keyClaimedAgo( $young->id, 30 * 60 );
		$this->keyClaimedAgo( $placed->id, 2 * 3600 );

		try {
			$this->claimCommitted( self::keyHash( 'stranded' ), self::digest( 'request 1' ) );
			$this->fail( 'A retry owned a key that is still claimed.' );
		} catch ( CodedException $held ) {
			$this->assertSame( CheckoutError::PlacementInProgress, $held->errorCode() );
		}

		$check  = $this->kernel->get( CheckoutChecks::class );
		$result = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertCount( 1, $result->findings );
		$this->assertStringContainsString( 'idempotency key ' . $stranded->id . ' (' . self::SCOPE . ')', $result->findings[0] );

		$repaired = $check->repair();

		$this->assertSame( CheckoutChecks::NAME, $repaired->check );
		$this->assertCount( 1, $repaired->changes );
		$this->assertTrue( $check->run()->passed, 'The stranded key is still reported after --repair.' );
		$this->assertSame( array( $young->id, $placed->id ), array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT id FROM %i ORDER BY id', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) ), 'id' ) ), '--repair touched a key it must not.' );
		$this->assertTrue( $this->claimCommitted( self::keyHash( 'stranded' ), self::digest( 'request 1' ) )->owned, 'The retry did not own the key once the stranded claim was gone.' );
	}

	/**
	 * Tests that a binary log that records statements is reported as critical, and that this server's is not.
	 *
	 * The server's variables are played by rewriting the one read that asks for them.
	 *
	 * Planted violation: in Database::refusesReadCommitted(), compare the format with 'ROW' instead
	 * of 'STATEMENT': the check then passes a server that refuses every order.
	 *
	 * @since 0.1.0
	 */
	public function test_a_binary_log_by_statement_is_reported(): void {
		$check = $this->kernel->get( CheckoutChecks::class );

		$this->assertTrue( $check->run()->passed, implode( "\n", $check->run()->findings ) );

		$fake = static fn( $query ) => 'SELECT @@log_bin AS log_bin, @@binlog_format AS binlog_format' === $query ? "SELECT 1 AS log_bin, 'STATEMENT' AS binlog_format" : $query;

		add_filter( 'query', $fake );

		try {
			$result = $check->run();
		} finally {
			remove_filter( 'query', $fake );
		}

		$this->assertFalse( $result->passed );
		$this->assertCount( 1, $result->findings );
		$this->assertStringStartsWith( 'Critical: the binary log records statements', $result->findings[0] );
		$this->assertSame( array(), $check->repair()->changes, 'There is nothing to repair: the format is the host\'s setting.' );
	}

	/**
	 * Tests a placement that has waited for its payment more than a day: a warning while its gateway is still deciding, critical with the reason once its gateway cannot be asked about it.
	 *
	 * Planted violation: in CheckoutChecks::run(), give the warning whatever the gateway's state: the
	 * placement of a gateway gone then reads as one the gateway is still deciding.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_waiting_a_day_says_why_its_gateway_cannot_be_asked(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$order   = (string) $this->placement->place( $this->placeInput( 'waiting', StubGateway::PENDING ), self::guest() )['order_uuid'];
		$intents = $this->table( PaymentTables::INTENTS );

		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 25 HOUR', $intents );

		$this->assertSame( array( sprintf( 'Warning: order %s has waited for its payment for more than 24 hours: the gateway is still deciding. Look the payment up with the gateway; the order is settled once the gateway answers.', $order ) ), $this->kernel->get( CheckoutChecks::class )->run()->findings );

		$this->db->execute( "UPDATE %i SET gateway_id = 'gone'", $intents );

		$this->assertSame( array( sprintf( 'Critical: order %s has waited for its payment for more than 24 hours, and its gateway gone cannot be asked about it (not_registered): nothing settles it until a person acts. The gateways check says what to do.', $order ) ), $this->kernel->get( CheckoutChecks::class )->run()->findings );
	}

	/**
	 * Tests that a key completed between the check and its repair is kept: the repair checks again in its statement.
	 *
	 * Planted violation: in MysqlIdempotencyKeys::DELETE_STRANDED, drop `AND state = 'claimed'`: the
	 * repair then deletes a key whose order was placed after the check ran.
	 *
	 * @since 0.1.0
	 */
	public function test_a_key_completed_since_the_check_is_kept(): void {
		$claim = $this->claimCommitted( self::keyHash( 'late' ), self::digest( 'request 1' ) );

		$this->keyClaimedAgo( $claim->id, 2 * 3600 );

		$check = $this->kernel->get( CheckoutChecks::class );

		$this->assertFalse( $check->run()->passed );

		$this->db->execute( "UPDATE %i SET state = 'placed', order_id = 42, response_json = '{}' WHERE id = %d", $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $claim->id );

		$this->assertSame( array(), $check->repair()->changes );
		$this->assertSame( 1, $this->checkoutRows( CheckoutTables::IDEMPOTENCY_KEYS ) );
	}
}

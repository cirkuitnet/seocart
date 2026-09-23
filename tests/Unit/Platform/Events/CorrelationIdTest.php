<?php
/**
 * Tests the correlation id: minted once, accepted only as a UUID, scoped and always restored
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Events;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

/**
 * One id per request, a client's id only when it is a UUID, and scoped work that always puts the id back.
 *
 * Planted violation: in CorrelationId::scoped(), put the previous id back after the work
 * returns instead of in `finally`. A throwing scope then leaks its id.
 *
 * @since 0.1.0
 */
final class CorrelationIdTest extends TestCase {

	/**
	 * A client's id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CLIENT = '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b';

	/**
	 * The id is minted the first time it is needed, once.
	 *
	 * @since 0.1.0
	 */
	public function test_the_id_is_minted_once_when_first_needed(): void {
		$correlation = new CorrelationId( new SequentialIdGenerator() );

		$this->assertSame( SequentialIdGenerator::nth( 1 ), $correlation->current() );
		$this->assertSame( SequentialIdGenerator::nth( 1 ), $correlation->current() );
	}

	/**
	 * A UUID from the client is taken, in lowercase; anything else is ignored.
	 *
	 * @since 0.1.0
	 */
	public function test_only_a_uuid_is_accepted(): void {
		$correlation = new CorrelationId( new SequentialIdGenerator() );

		$correlation->accept( 'not-a-uuid' );
		$correlation->accept( null );
		$correlation->accept( self::CLIENT . "\n<script>" );

		$this->assertSame( SequentialIdGenerator::nth( 1 ), $correlation->current(), 'Nothing was accepted, so the request minted its own.' );

		$correlation->accept( ' ' . strtoupper( self::CLIENT ) . ' ' );

		$this->assertSame( self::CLIENT, $correlation->current() );
	}

	/**
	 * Scoped work runs under the given id, and the id in force comes back afterwards.
	 *
	 * @since 0.1.0
	 */
	public function test_scoped_work_runs_under_the_given_id_and_restores_the_previous_one(): void {
		$correlation = new CorrelationId( new SequentialIdGenerator() );
		$own         = $correlation->current();

		$seen = $correlation->scoped(
			self::CLIENT,
			static function () use ( $correlation, $own ): array {
				return array( $correlation->current(), $correlation->scoped( $own, fn() => $correlation->current() ), $correlation->current() );
			}
		);

		$this->assertSame( array( self::CLIENT, $own, self::CLIENT ), $seen, 'Nested scopes restore their own previous id.' );
		$this->assertSame( $own, $correlation->current() );
	}

	/**
	 * A scope that throws still puts the previous id back.
	 *
	 * @since 0.1.0
	 */
	public function test_a_scope_that_throws_restores_the_previous_id(): void {
		$correlation = new CorrelationId( new SequentialIdGenerator() );
		$own         = $correlation->current();

		try {
			$correlation->scoped(
				self::CLIENT,
				static function (): void {
					throw new \RuntimeException( 'The work failed.' );
				}
			);
			$this->fail( 'The work\'s exception must propagate.' );
		} catch ( \RuntimeException $expected ) {
			$this->assertSame( 'The work failed.', $expected->getMessage() );
		}

		$this->assertSame( $own, $correlation->current() );
	}

	/**
	 * With no id, or one that is not a UUID, the work runs under the id in force, which it may mint.
	 *
	 * @since 0.1.0
	 */
	public function test_without_an_id_the_work_runs_under_the_id_in_force(): void {
		$correlation = new CorrelationId( new SequentialIdGenerator() );

		$inside = $correlation->scoped( null, fn() => $correlation->current() );

		$this->assertSame( SequentialIdGenerator::nth( 1 ), $inside );
		$this->assertSame( $inside, $correlation->current(), 'An id minted inside an unscoped call stays the request\'s id.' );
		$this->assertSame( $inside, $correlation->scoped( 'garbage', fn() => $correlation->current() ) );
	}

	/**
	 * A scope entered before the request had an id leaves none behind: the request then mints its own.
	 *
	 * @since 0.1.0
	 */
	public function test_a_scope_before_the_first_id_leaves_the_request_its_own(): void {
		$correlation = new CorrelationId( new SequentialIdGenerator() );

		$this->assertSame( self::CLIENT, $correlation->scoped( self::CLIENT, fn() => $correlation->current() ) );
		$this->assertSame( SequentialIdGenerator::nth( 1 ), $correlation->current() );
	}
}

<?php
/**
 * Tests how the lock probe decides between GET_LOCK and the locks table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockProbe;

/**
 * Every branch of LockProbe::decide(), with a scripted server.
 *
 * GET_LOCK is trusted only when the lock is granted at once, the server says this very
 * connection holds it, and the release succeeds. Anything else chooses the table.
 *
 * Planted violation: in LockProbe::decide(), compare `null !== $holder` instead of
 * `(string) $threadId === $holder`. A foreign holder, which is what a connection pooler
 * produces, is then trusted.
 *
 * @since 0.1.0
 */
final class LockProbeDecideTest extends TestCase {

	/**
	 * The thread id the scripted connection has.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const OWN_THREAD = 42;

	/**
	 * The statements the probe sent, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $sent = array();

	/**
	 * Tests that a persistent connection chooses the table without sending anything.
	 *
	 * @since 0.1.0
	 */
	public function test_a_persistent_connection_chooses_the_table_without_asking_the_server(): void {
		$this->assertSame( LockMode::Table, LockProbe::decide( 'p:db.example.com', self::OWN_THREAD, $this->server( '1', '42', '1' ) ) );
		$this->assertSame( array(), $this->sent );
	}

	/**
	 * Tests that three expected answers choose GET_LOCK, and that the lock is released.
	 *
	 * @since 0.1.0
	 */
	public function test_granted_held_by_this_connection_and_released_chooses_get_lock(): void {
		$this->assertSame( LockMode::GetLock, LockProbe::decide( 'localhost:/tmp/mysql.sock', self::OWN_THREAD, $this->server( '1', '42', '1' ) ) );
		$this->assertSame( array( 'GET_LOCK', 'IS_USED_LOCK', 'RELEASE_LOCK' ), $this->sent );
	}

	/**
	 * Lists answer triples that must choose the table.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string|null, string|null, string|null, list<string>}> The three answers and the statements expected to be sent.
	 */
	public static function untrustworthy(): array {
		return array(
			'not granted at once'        => array( '0', '42', '1', array( 'GET_LOCK' ) ),
			'GET_LOCK answers NULL'      => array( null, '42', '1', array( 'GET_LOCK' ) ),
			'held by another connection' => array( '1', '7', '1', array( 'GET_LOCK', 'IS_USED_LOCK', 'RELEASE_LOCK' ) ),
			'holder unknown'             => array( '1', null, '1', array( 'GET_LOCK', 'IS_USED_LOCK', 'RELEASE_LOCK' ) ),
			'release not acknowledged'   => array( '1', '42', '0', array( 'GET_LOCK', 'IS_USED_LOCK', 'RELEASE_LOCK' ) ),
			'release answers NULL'       => array( '1', '42', null, array( 'GET_LOCK', 'IS_USED_LOCK', 'RELEASE_LOCK' ) ),
		);
	}

	/**
	 * Tests that any unexpected answer chooses the table, and that a granted lock is still released.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider untrustworthy
	 *
	 * @param string|null $granted  The answer to GET_LOCK.
	 * @param string|null $holder   The answer to IS_USED_LOCK.
	 * @param string|null $released The answer to RELEASE_LOCK.
	 * @param string[]    $expected The statements the probe must send.
	 */
	public function test_an_unexpected_answer_chooses_the_table( ?string $granted, ?string $holder, ?string $released, array $expected ): void {
		$this->assertSame( LockMode::Table, LockProbe::decide( 'db.example.com:3306', self::OWN_THREAD, $this->server( $granted, $holder, $released ) ) );
		$this->assertSame( $expected, $this->sent );
	}

	/**
	 * Tests that a failed statement chooses the table instead of failing the probe.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_statement_chooses_the_table(): void {
		$refusing = static function (): mixed {
			throw QueryFailed::fromErrno( 1142, '42000', 'SELECT GET_LOCK( %s, 0 )', 'command denied', false );
		};

		$this->assertSame( LockMode::Table, LockProbe::decide( 'db.example.com', self::OWN_THREAD, $refusing ) );
	}

	/**
	 * Builds a scripted server that answers the three probe statements.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $granted  The answer to GET_LOCK.
	 * @param string|null $holder   The answer to IS_USED_LOCK.
	 * @param string|null $released The answer to RELEASE_LOCK.
	 * @return \Closure(string): (string|null) The fetcher.
	 */
	private function server( ?string $granted, ?string $holder, ?string $released ): \Closure {
		return function ( string $sql ) use ( $granted, $holder, $released ): ?string {
			$this->assertStringContainsString( '%s', $sql, 'The probe must leave the lock name to the placeholder.' );

			foreach ( array(
				'IS_USED_LOCK' => $holder,
				'RELEASE_LOCK' => $released,
				'GET_LOCK'     => $granted,
			) as $function => $answer ) {
				if ( str_contains( $sql, $function ) ) {
					$this->sent[] = $function;

					return $answer;
				}
			}

			$this->fail( 'Unexpected probe statement: ' . $sql );
		};
	}
}

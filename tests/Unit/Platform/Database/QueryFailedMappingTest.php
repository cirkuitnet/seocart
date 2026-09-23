<?php
/**
 * Tests the error-number table that types database failures, and the set of database error codes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\Exception\TransactionRetryable;

/**
 * T14: the two hand-maintained lists of the Database module, each held to a set-equality test (DRY rule 11).
 *
 * The first list is QueryFailed's table from MySQL error number to exception class. The second
 * is the set of `database.*` codes, one per concrete exception class, which is the set of rows
 * the one error table must carry. Adding an exception class without its row, or a row without
 * its class, turns this red.
 *
 * Planted violation: in QueryFailed::CLASSES, delete the line for 1205. The mapping of 1205 and
 * the equality with the documented table both turn red.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class QueryFailedMappingTest extends TestCase {

	/**
	 * The documented table: error number to class, outside a transaction window.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, class-string<DatabaseException>>
	 */
	private const DOCUMENTED = array(
		1062 => DuplicateKey::class,
		1205 => TransactionRetryable::class,
		1213 => TransactionRetryable::class,
	);

	/**
	 * The `database.*` codes the error table receives: one per concrete exception class.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const CODES = array(
		'database.duplicate_key',
		'database.forbidden_in_transaction',
		'database.lock_lost',
		'database.lock_not_acquired',
		'database.migration_failed',
		'database.query_failed',
		'database.transaction_depth',
		'database.transaction_lost',
		'database.transaction_retryable',
	);

	/**
	 * Lists each error number with the class it becomes inside and outside a transaction window.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{int, bool, class-string<DatabaseException>}> Error number, inside a window, expected class.
	 */
	public static function errnos(): array {
		return array(
			'1062 duplicate entry'                  => array( 1062, false, DuplicateKey::class ),
			'1062 inside a window'                  => array( 1062, true, DuplicateKey::class ),
			'1213 deadlock'                         => array( 1213, true, TransactionRetryable::class ),
			'1205 lock-wait timeout'                => array( 1205, true, TransactionRetryable::class ),
			'1205 outside a window'                 => array( 1205, false, TransactionRetryable::class ),
			'2006 server gone, inside a window'     => array( 2006, true, TransactionIntegrityLost::class ),
			'2013 connection lost, inside a window' => array( 2013, true, TransactionIntegrityLost::class ),
			'2006 server gone, outside a window'    => array( 2006, false, QueryFailed::class ),
			'2013 connection lost, outside'         => array( 2013, false, QueryFailed::class ),
			'0 refused before the server saw it'    => array( 0, true, QueryFailed::class ),
			'1305 missing savepoint'                => array( 1305, true, QueryFailed::class ),
			'9999 anything else'                    => array( 9999, false, QueryFailed::class ),
		);
	}

	/**
	 * Tests that each error number becomes the documented class, and keeps its facts.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider errnos
	 *
	 * @param int    $errno    The error number.
	 * @param bool   $inside   Whether the statement ran inside a window.
	 * @param string $expected The name of the class it must become.
	 */
	public function test_each_error_number_becomes_the_documented_class( int $errno, bool $inside, string $expected ): void {
		$failure = QueryFailed::fromErrno( $errno, '40001', 'UPDATE wp_seocart_orders SET state = \'paid\'', 'server text', $inside );

		$this->assertSame( $expected, get_class( $failure ), sprintf( 'Error %d %s a window.', $errno, $inside ? 'inside' : 'outside' ) );

		if ( $failure instanceof QueryFailed ) {
			$this->assertSame( $errno, $failure->errno() );
			$this->assertSame( '40001', $failure->sqlstate() );
			$this->assertSame( 'server text', $failure->context()['server_message'] );
		}

		if ( $failure instanceof TransactionIntegrityLost ) {
			$this->assertSame( TransactionIntegrityLost::CONNECTION_LOST, $failure->reason() );
		}
	}

	/**
	 * Tests that the table in QueryFailed is exactly the documented one, so a new entry cannot go untested.
	 *
	 * @since 0.1.0
	 */
	public function test_the_error_number_table_equals_the_documented_table(): void {
		$table = ( new \ReflectionClassConstant( QueryFailed::class, 'CLASSES' ) )->getValue();

		ksort( $table );

		$this->assertSame( self::DOCUMENTED, $table, 'QueryFailed::CLASSES changed: update the documented table here in the same change.' );
	}

	/**
	 * Tests that the statement is kept only in part, so values past the first 120 characters never reach a log.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_beginning_of_the_statement_is_kept(): void {
		$failure = new QueryFailed( 1064, '42000', "SELECT\n\n  " . str_repeat( 'x', 300 ), '' );

		$this->assertSame( QueryFailed::STATEMENT_LENGTH, strlen( $failure->statement() ) );
		$this->assertStringStartsWith( 'SELECT x', $failure->statement() );
	}

	/**
	 * Tests that every concrete exception class declares its own code, and that the codes are exactly the documented set.
	 *
	 * @since 0.1.0
	 */
	public function test_every_exception_class_has_its_own_code_and_the_codes_are_the_documented_set(): void {
		$codes = array();

		foreach ( self::exceptionClasses() as $class ) {
			$reflection = new \ReflectionClass( $class );

			if ( $reflection->isAbstract() ) {
				continue;
			}

			$this->assertTrue( $reflection->isSubclassOf( DatabaseException::class ), $class . ' must extend DatabaseException.' );
			$this->assertSame( $class, ( new \ReflectionClassConstant( $class, 'CODE' ) )->getDeclaringClass()->getName(), $class . ' must declare its own CODE.' );
			$this->assertArrayNotHasKey( $class::CODE, $codes, $class . ' repeats the code of ' . ( $codes[ $class::CODE ] ?? '' ) . '.' );

			$codes[ $class::CODE ] = $class;
		}

		$found = array_keys( $codes );
		sort( $found );

		$this->assertSame( self::CODES, $found, 'The database error codes changed: every code needs exactly one row in the error table.' );
	}

	/**
	 * Lists every class declared under src/Platform/Database/Exception/.
	 *
	 * @since 0.1.0
	 *
	 * @return list<class-string> The class names.
	 */
	private static function exceptionClasses(): array {
		$classes = array();

		foreach ( (array) glob( dirname( __DIR__, 4 ) . '/src/Platform/Database/Exception/*.php' ) as $file ) {
			$class = 'SEOCart\\Platform\\Database\\Exception\\' . basename( (string) $file, '.php' );

			if ( class_exists( $class ) ) {
				$classes[] = $class;
			}
		}

		return $classes;
	}
}

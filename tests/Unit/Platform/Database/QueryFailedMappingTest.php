<?php
/**
 * Tests the error-number table that types database failures, and how each class maps to a catalog case
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\Exception\TransactionRetryable;
use SEOCart\Support\Error\ErrorDefinition;

/**
 * T14: the hand-maintained lists of the Database module, each held to a set-equality test (DRY rule 11).
 *
 * The first list is QueryFailed's table from MySQL error number to exception class. The second
 * is the pairing of exception classes with DatabaseError cases: every concrete class names
 * exactly one case in its CODE constant, and every case is named by exactly one class. The
 * third is the HTTP status of each case.
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
	 * The HTTP status of each case: a retry may succeed (503), a duplicate is a conflict (409), anything else is a server fault.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int>
	 */
	private const STATUSES = array(
		'database.duplicate_key'            => 409,
		'database.forbidden_in_transaction' => 500,
		'database.lock_lost'                => 500,
		'database.lock_not_acquired'        => 503,
		'database.migration_failed'         => 500,
		'database.query_failed'             => 500,
		'database.transaction_depth'        => 500,
		'database.transaction_lost'         => 503,
		'database.transaction_retryable'    => 500,
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
	 * Tests that each error number becomes the documented class, with its own code and its facts.
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
		$failure = QueryFailed::fromErrno( $errno, '40001', "UPDATE wp_seocart_orders SET state = 'paid'", 'server text', $inside );

		$this->assertSame( $expected, get_class( $failure ), sprintf( 'Error %d %s a window.', $errno, $inside ? 'inside' : 'outside' ) );
		$this->assertSame( $expected::CODE, $failure->errorCode() );

		if ( $failure instanceof QueryFailed ) {
			$this->assertSame( $errno, $failure->errno() );
			$this->assertSame( '40001', $failure->sqlstate() );
			$this->assertSame( 'server text', $failure->serverMessage() );
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
		$failure = QueryFailed::fromErrno( 1064, '42000', "SELECT\n\n  " . str_repeat( 'x', 300 ), '', false );

		$this->assertInstanceOf( QueryFailed::class, $failure );
		$this->assertSame( QueryFailed::STATEMENT_LENGTH, strlen( $failure->statement() ) );
		$this->assertStringStartsWith( 'SELECT x', $failure->statement() );
	}

	/**
	 * Tests that every concrete exception class names its own catalog case, and that the pairing covers every case once.
	 *
	 * Planted violation: set LockLost::CODE to DatabaseError::LockNotAcquired.
	 *
	 * @since 0.1.0
	 */
	public function test_every_class_raises_its_own_case_and_every_case_has_a_class(): void {
		$named = array();

		foreach ( self::exceptionClasses() as $class ) {
			$reflection = new \ReflectionClass( $class );

			if ( DatabaseException::class === $class ) {
				continue;
			}

			$this->assertTrue( $reflection->isSubclassOf( DatabaseException::class ), $class . ' must extend DatabaseException.' );
			$this->assertFalse( $reflection->isAbstract(), $class . ' must be concrete: only DatabaseException is abstract.' );

			$code = new \ReflectionClassConstant( $class, 'CODE' );

			$this->assertSame( $class, $code->getDeclaringClass()->getName(), $class . ' must name its own case in CODE.' );
			$this->assertInstanceOf( DatabaseError::class, $code->getValue(), $class . '::CODE must be a DatabaseError case.' );
			$this->assertArrayNotHasKey( $code->getValue()->name, $named, $class . ' names the same case as ' . ( $named[ $code->getValue()->name ] ?? '' ) . '.' );

			$named[ $code->getValue()->name ] = $class;
		}

		$cases = array_map( static fn( DatabaseError $each ): string => $each->name, DatabaseError::cases() );

		sort( $cases );
		ksort( $named );

		$this->assertSame( $cases, array_keys( $named ), 'Every DatabaseError case is raised by exactly one exception class.' );
	}

	/**
	 * Tests the HTTP status of every case.
	 *
	 * Planted violation: in DatabaseError::definitions(), answer 500 for TransactionLost.
	 *
	 * @since 0.1.0
	 */
	public function test_each_case_answers_with_its_documented_status(): void {
		$statuses = array();

		foreach ( DatabaseError::cases() as $case ) {
			$statuses[ $case->value ] = ErrorDefinition::of( $case )->httpStatus();
		}

		ksort( $statuses );

		$this->assertSame( self::STATUSES, $statuses );
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

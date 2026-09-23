<?php
/**
 * LogsTestCase: the base of the tests that write log lines to a real `logs` table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Logging;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Logging\FallbackLog;
use SEOCart\Platform\Logging\Level;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\SecondDatabase;

/**
 * A DatabaseTestCase with the `logs` table, a correlation id and a logger that records its fallback.
 *
 * Owns one fact: how a logging test gets a logger over real tables. The table is created by
 * its own migration in set_up(), and the base tear_down() drops it. The logger's fallback lines
 * land in `$this->fallback` instead of PHP's error log, which the base class requires to stay
 * empty. By default the logger's own connection is a SecondDatabase, counted in
 * `$this->separateOpened` and closed in tear_down(); a test that passes null to logger() gets
 * the production LogConnection instead.
 *
 * @since 0.1.0
 */
abstract class LogsTestCase extends DatabaseTestCase {

	/**
	 * The process's correlation id, minting sequential ids.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	protected CorrelationId $correlation;

	/**
	 * Every line the logger wrote to its fallback, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	protected array $fallback = array();

	/**
	 * How many times a logger opened its own connection.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	protected int $separateOpened = 0;

	/**
	 * Every callable a logger registered to run when the process ends, in order. None runs unless a test runs it.
	 *
	 * @since 0.1.0
	 *
	 * @var list<callable>
	 */
	protected array $atShutdown = array();

	/**
	 * The second databases the loggers opened.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $secondDatabases = array();

	/**
	 * Creates the table and the correlation id.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$this->correlation    = new CorrelationId( new SequentialIdGenerator() );
		$this->fallback       = array();
		$this->separateOpened = 0;
		$this->atShutdown     = array();
	}

	/**
	 * Closes the second databases.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		foreach ( $this->secondDatabases as $second ) {
			$second->close();
		}

		$this->secondDatabases = array();

		parent::tear_down();
	}

	/**
	 * Builds a logger over `$this->db` whose fallback and end-of-process work are recorded.
	 *
	 * @since 0.1.0
	 *
	 * @param Redactor|null $redactor     Optional. Default null, the production redactor.
	 * @param Level         $minimum      Optional. The minimum level. Default Level::Debug.
	 * @param bool          $counted      Optional. True for a counted SecondDatabase as the logger's own
	 *                                    connection, false for the production LogConnection. Default true.
	 * @param callable|null $openSeparate Optional. An opener of the logger's own connection, instead
	 *                                    of either. Default null.
	 * @return Logger The logger.
	 */
	protected function logger( ?Redactor $redactor = null, Level $minimum = Level::Debug, bool $counted = true, ?callable $openSeparate = null ): Logger {
		return new Logger(
			$this->db,
			$this->correlation,
			$redactor ?? self::productionRedactor(),
			$minimum,
			$openSeparate ?? ( $counted ? $this->countedConnection() : null ),
			$this->fallbackLog()
		);
	}

	/**
	 * Builds a FallbackLog that records its lines in `$this->fallback` and its end-of-process work in `$this->atShutdown`.
	 *
	 * @since 0.1.0
	 *
	 * @return FallbackLog The fallback.
	 */
	protected function fallbackLog(): FallbackLog {
		return new FallbackLog(
			function ( string $line ): void {
				$this->fallback[] = $line;
			},
			function ( callable $work ): void {
				$this->atShutdown[] = $work;
			}
		);
	}

	/**
	 * Returns an opener of the logger's own connection that counts and remembers what it opens.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(): Database The opener.
	 */
	protected function countedConnection(): \Closure {
		return function (): Database {
			++$this->separateOpened;

			$second                  = new SecondDatabase( $this->reporter() );
			$this->secondDatabases[] = $second;

			return $second->db();
		};
	}

	/**
	 * Returns the redactor the plugin runs with: the production data registry and every declared field.
	 *
	 * @since 0.1.0
	 *
	 * @return Redactor The redactor.
	 */
	protected static function productionRedactor(): Redactor {
		return Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() );
	}

	/**
	 * Returns the full name of the log table.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	protected function logsTable(): string {
		return $this->db->table( LogsTable::NAME );
	}

	/**
	 * Reads the log lines, oldest first.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array<string, mixed>> The rows, every column as the database returned it.
	 */
	protected function lines(): array {
		return $this->db->fetchAll( 'SELECT * FROM %i ORDER BY id', $this->logsTable() );
	}

	/**
	 * Reads the log lines of one machine code, oldest first.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code The machine code.
	 * @return list<array<string, mixed>> The rows.
	 */
	protected function linesOf( string $code ): array {
		return $this->db->fetchAll( 'SELECT * FROM %i WHERE machine_code = %s ORDER BY id', $this->logsTable(), $code );
	}

	/**
	 * Reads the only log line, failing the test when there is not exactly one.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The row.
	 */
	protected function onlyLine(): array {
		$lines = $this->lines();

		$this->assertCount( 1, $lines, 'Exactly one line was expected.' );

		return $lines[0];
	}

	/**
	 * Returns the context of a line, decoded.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $line A row from lines().
	 * @return array<mixed> The context; empty when the column is NULL.
	 */
	protected static function context( array $line ): array {
		return null === $line['context_json'] ? array() : (array) json_decode( (string) $line['context_json'], true );
	}
}

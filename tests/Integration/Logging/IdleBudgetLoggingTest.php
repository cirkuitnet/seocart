<?php
/**
 * Tests that the logging module and doctor cost nothing until a line is written or a check runs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Logging;

use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\Cli\DoctorCommand;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Logging\Level;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\LogRetention;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Support\SystemClock;
use SEOCart\Tests\Support\Logging\DeclaredFields;
use SEOCart\Tests\Support\Logging\LogsTestCase;

/**
 * Building the logger, the reporter, the redactor, the sweep and doctor sends no query and adds no hook, and a line costs one statement.
 *
 * Nothing here is wired into the plugin's boot yet, so the idle-request budgets measured in a
 * child process are untouched; this measures the module itself, in-process.
 *
 * Planted violations: `add_action( 'shutdown', '__return_null' )` in Logger::__construct() (one
 * more hook); `$this->db->fetchValue( 'SELECT 1' )` in Logger::__construct() (one query).
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class IdleBudgetLoggingTest extends LogsTestCase {

	/**
	 * Tests that constructing everything sends nothing and registers nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_constructing_the_module_sends_nothing_and_registers_nothing(): void {
		$hooks = self::callbackCount();
		$built = array();

		$log = $this->captureQueries(
			function () use ( &$built ): void {
				$redactor = Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() );
				$logger   = new Logger( $this->db, $this->correlation, $redactor, Level::Info );
				$reporter = new Reporter( static fn(): Logger => $logger, $this->correlation );
				$registry = OwnedData::registry();
				$migrator = new Migrator( $this->db, new LockService( $this->db, LockMode::Table ), new MigrationsTableState( $this->db ), $registry->migrations(), new SystemClock(), $reporter );
				$doctor   = new Doctor( $this->db, $registry, $migrator, new Outbox( $this->db ) );

				$built = array( $redactor, $logger, $reporter, new LogRetention( $this->db ), $doctor, $doctor->checks(), $doctor->residueChecks(), new DoctorCommand( $doctor, static function (): void {} ) );
			}
		);

		$this->assertCount( 8, $built );
		$this->assertQueryCount( 0, $log, 'Constructing the logging module and doctor' );
		$this->assertSame( $hooks, self::callbackCount(), 'Constructing the logging module or doctor added a hook callback.' );
	}

	/**
	 * Tests that a line outside a transaction costs exactly its INSERT, and adds no hook.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_costs_one_statement(): void {
		$logger = $this->logger();
		$hooks  = self::callbackCount();

		$log = $this->captureQueries( static fn() => $logger->warning( 'test.cost', 'Measured.', array( 'n' => 1 ) ) );

		$this->assertQueryCount( 1, $log, 'One line' );
		$this->assertQueryCount( 1, $log->ofType( 'INSERT' ), 'The line\'s INSERT' );
		$this->assertSame( $hooks, self::callbackCount() );
	}

	/**
	 * Counts every callback registered on every hook.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	private static function callbackCount(): int {
		global $wp_filter;

		$count = 0;

		foreach ( $wp_filter as $hook ) {
			foreach ( $hook->callbacks as $callbacks ) {
				$count += count( $callbacks );
			}
		}

		return $count;
	}
}

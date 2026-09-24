<?php
/**
 * Tests that doctor's list of checks is every check class under src/
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cli;

use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\Cli\Doctor\MigrationsCheck;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Doctor::checks() and residueChecks() are a hand-kept list, held to the Check classes that exist.
 *
 * Doctor is built here as the kernel builds it, so its lists hold the platform's checks and the
 * checks the modules contribute through Modules. Together they must name every concrete Check
 * class under src/, and nothing else, so a check that is written and never run fails here.
 *
 * Planted violations: drop LocksCheck from Doctor::checks(); drop the stock check from the
 * Doctor that Modules builds.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class DoctorListsTest extends DatabaseTestCase {

	/**
	 * Tests that the doctor's lists hold exactly the Check classes under src/.
	 *
	 * @since 0.1.0
	 */
	public function test_the_lists_hold_every_check_class_under_src(): void {
		$found = array();

		foreach ( PhpSource::files( 'src' ) as $source ) {
			if ( ! str_contains( $source, 'Check' ) ) {
				continue;
			}

			foreach ( PhpSource::declarations( $source ) as $class ) {
				if ( class_exists( $class ) && ( new \ReflectionClass( $class ) )->implementsInterface( Check::class ) && ( new \ReflectionClass( $class ) )->isInstantiable() ) {
					$found[] = $class;
				}
			}
		}

		$registry = OwnedData::registry();
		$migrator = new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $registry->migrations(), FrozenClock::at( '2026-09-23 12:00:00' ), $this->reporter() );
		$queue    = new ActionSchedulerQueue( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new JobHandlers( JobHandlers::PRODUCTION, 'strval' ), new CorrelationId( new SequentialIdGenerator() ), $this->reporter() );
		$platform = new Doctor( $this->db, $registry, $migrator, new Outbox( $this->db ), $queue );
		$doctor   = KernelContainer::build( $this->db, $this->reporter() )->get( Doctor::class );
		$listed   = array_map( 'get_class', array_merge( $doctor->checks(), $doctor->residueChecks() ) );

		sort( $found );
		sort( $listed );

		$this->assertContains( MigrationsCheck::class, $found, 'The search found not even the migrations check; it cannot be trusted.' );
		$this->assertSame( $found, $listed, "Doctor must run every Check class under src/, each once: the platform's in checks(), the modules' through Modules." );
		$this->assertSame(
			array_map( 'get_class', $platform->checks() ),
			array_slice( array_map( 'get_class', $doctor->checks() ), 0, count( $platform->checks() ) ),
			"The platform's checks run first, then the modules'."
		);
	}
}

<?php
/**
 * Tests that the production list of job handlers is every handler class the plugin ships
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Jobs;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * JobHandlers::PRODUCTION holds exactly the job handler classes under src/.
 *
 * The list is kept by hand, so it gets the companion test every hand-kept identifier list
 * gets: a handler class that is shipped but not listed would have its jobs fail for want of a
 * handler, and a listed class that no longer exists would break the registry. The classes are
 * found by reading the source, so the check does not trust the list it checks. The whole
 * production registry is also built, which applies its declaration rules to every handler.
 *
 * Planted violations: remove OutboxRetention from PRODUCTION; add a handler class under src/
 * that is not listed.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class RegisteredHandlersTest extends TestCase {

	/**
	 * Tests that the listed handlers and the handler classes under src/ are the same set.
	 *
	 * @since 0.1.0
	 */
	public function test_the_production_list_is_every_handler_class_under_src(): void {
		$shipped = array();

		foreach ( PhpSource::files( 'src' ) as $path => $source ) {
			if ( ! str_contains( $source, 'JobHandler' ) ) {
				continue;
			}

			foreach ( PhpSource::declarations( $source ) as $class ) {
				$this->assertTrue( class_exists( $class ) || interface_exists( $class ) || enum_exists( $class ) || trait_exists( $class ), "{$class}, declared in {$path}, cannot be loaded by its name." );

				if ( class_exists( $class ) && is_subclass_of( $class, JobHandler::class ) && ( new \ReflectionClass( $class ) )->isInstantiable() ) {
					$shipped[] = $class;
				}
			}
		}

		$this->assertContains( 'SEOCart\\Platform\\Jobs\\Handlers\\OutboxCatchUp', $shipped, 'The search did not find even the outbox catch-up handler; it cannot be trusted to find the others.' );

		$listed = JobHandlers::PRODUCTION;

		sort( $shipped );
		sort( $listed );

		$this->assertSame( array(), array_values( array_diff( $shipped, $listed ) ), 'These job handlers are shipped under src/ but not listed in JobHandlers::PRODUCTION: their jobs would find no handler.' );
		$this->assertSame( array(), array_values( array_diff( $listed, $shipped ) ), 'These classes are listed in JobHandlers::PRODUCTION but are not job handler classes under src/.' );
		$this->assertSame( count( $listed ), count( array_unique( $listed ) ), 'A handler is listed twice.' );

		// Registering builds nothing, so the resolver is never called.
		$registry = new JobHandlers( JobHandlers::PRODUCTION, 'strval' );

		$this->assertCount( count( $listed ), $registry->names() );
	}

	/**
	 * Tests that the group the queue stores every job in is the one job group the data registry declares, owned by the Jobs module.
	 *
	 * Uninstall, "Delete all store data" and `doctor --residue` learn what the plugin owns from
	 * the registry, so a queue group it did not declare would be invisible to all three.
	 *
	 * Planted violation: remove the job-group line from OwnedData::registry().
	 *
	 * @since 0.1.0
	 */
	public function test_the_queues_group_is_the_job_group_the_data_registry_declares(): void {
		$this->assertSame( array( JobQueue::GROUP => 'Jobs' ), OwnedData::registry()->jobGroups() );
	}
}

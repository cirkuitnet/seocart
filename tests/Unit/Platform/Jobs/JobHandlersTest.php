<?php
/**
 * Tests the registry of job handlers
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Jobs;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Jobs\Handlers\MigrationAttempt;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Jobs\DeclaredJob;
use SEOCart\Tests\Support\Jobs\RecordingJob;
use SEOCart\Tests\Support\Jobs\RecurringJob;

/**
 * Handlers are registered by name, read without being built, and built once, when first run.
 *
 * @since 0.1.0
 */
final class JobHandlersTest extends TestCase {

	/**
	 * Tests that registering reads each class's declaration and builds nothing.
	 *
	 * Planted violation: resolve every class in the constructor (`$this->handler( $name )` at the
	 * end of the loop); the resolver is then called at construction.
	 *
	 * @since 0.1.0
	 */
	public function test_registering_builds_no_handler_and_the_first_run_builds_one_once(): void {
		RecurringJob::reset();

		$built    = array();
		$handlers = new JobHandlers(
			array( RecordingJob::class, RecurringJob::class ),
			static function ( string $handlerClass ) use ( &$built ): JobHandler {
				$built[] = $handlerClass;

				return new $handlerClass( new CorrelationId( new SequentialIdGenerator() ) );
			}
		);

		$this->assertSame( array(), $built, 'Registering handlers must not build any.' );
		$this->assertSame( array( RecordingJob::NAME, RecurringJob::NAME ), $handlers->names() );
		$this->assertTrue( $handlers->has( RecordingJob::NAME ) );
		$this->assertFalse( $handlers->has( 'test.unknown' ) );
		$this->assertSame( array( RecurringJob::NAME => 60 ), $handlers->recurring() );
		$this->assertSame( 3, $handlers->maxAttempts( RecordingJob::NAME ) );
		$this->assertSame( array(), $built, 'Reading declarations must not build a handler.' );

		$first  = $handlers->handler( RecordingJob::NAME );
		$second = $handlers->handler( RecordingJob::NAME );

		$this->assertSame( $first, $second );
		$this->assertSame( array( RecordingJob::class ), $built );
	}

	/**
	 * Tests every registration the registry refuses.
	 *
	 * Planted violation: remove the duplicate-name check; the "a name twice" case turns red.
	 *
	 * @dataProvider refusedRegistrations
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed>                               $classes  The classes to register.
	 * @param array{0: string, 1: int|null, 2: int}|null $declared What DeclaredJob declares, or null.
	 * @param string                                     $expected Part of the message.
	 */
	public function test_a_registration_that_cannot_be_right_is_refused( array $classes, ?array $declared, string $expected ): void {
		if ( null !== $declared ) {
			DeclaredJob::$declared = $declared;
		}

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $expected );

		new JobHandlers( $classes, static fn( string $handlerClass ): JobHandler => new $handlerClass() );
	}

	/**
	 * Supplies the refused registrations.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: array<mixed>, 1: array{0: string, 1: int|null, 2: int}|null, 2: string}> Case => classes, declaration, message part.
	 */
	public static function refusedRegistrations(): array {
		return array(
			'a class that is not a handler' => array( array( \stdClass::class ), null, 'is not a job handler class' ),
			'a class that does not exist'   => array( array( 'SEOCart\Nowhere\Handler' ), null, 'is not a job handler class' ),
			'a name that is not a string'   => array( array( 42 ), null, 'int is not a job handler class' ),
			'a one-word name'               => array( array( DeclaredJob::class ), array( 'sweep', null, 1 ), 'which is not two or more lowercase words' ),
			'a name twice'                  => array( array( RecordingJob::class, DeclaredJob::class ), array( RecordingJob::NAME, null, 1 ), 'is registered twice' ),
			'an interval under a minute'    => array( array( DeclaredJob::class ), array( 'test.fast', 30, 1 ), 'the shortest interval is 60' ),
			'no attempt'                    => array( array( DeclaredJob::class ), array( 'test.never', null, 0 ), 'declares 0 attempts' ),
			'too many attempts'             => array( array( DeclaredJob::class ), array( 'test.forever', null, 11 ), 'declares 11 attempts' ),
		);
	}

	/**
	 * Tests that an unknown handler, or a resolver that returns the wrong object, is a programming error.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unknown_handler_or_a_wrong_resolver_is_a_logic_error(): void {
		$handlers = new JobHandlers( array( RecordingJob::class ), static fn(): object => new \stdClass() );

		try {
			$handlers->handler( 'test.unknown' );
			$this->fail( 'An unknown handler was resolved.' );
		} catch ( \LogicException $unknown ) {
			$this->assertStringContainsString( 'No job handler is registered as test.unknown', $unknown->getMessage() );
		}

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'The resolver returned stdClass' );

		$handlers->handler( RecordingJob::NAME );
	}

	/**
	 * Tests the migration job's key: one job per target, and a different one per target.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_job_is_keyed_by_its_target(): void {
		$job = MigrationAttempt::job( '20260922_0001_platform_bootstrap' );

		$this->assertSame( MigrationAttempt::name(), $job->handler );
		$this->assertSame( array(), $job->payload );
		$this->assertEquals( $job, MigrationAttempt::job( '20260922_0001_platform_bootstrap' ) );
		$this->assertNotSame( $job->uniqueKey, MigrationAttempt::job( '20260923_0001_outbox' )->uniqueKey );
		$this->assertStringStartsWith( 'schema.migrate:', (string) $job->uniqueKey );
	}
}

<?php
/**
 * Tests that the kernel's hand-kept lists name exactly what the code base declares
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Kernel;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Jobs\JobRunner;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\ErrorCatalogs;
use SEOCart\Tests\Unit\Platform\Kernel\Fixtures\InheritedEvent;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Each list the kernel keeps by hand has a companion test, because a list that can drift from what
 * it lists is a parallel list: the error catalogs, the domain events and the capability prefix the
 * mapper's filter checks. The migration chain is not kept here: the kernel wires the data
 * registry's, and WiredMigrationsTest holds the two equal. It also checks two rules the kernel's
 * own code must keep: only the kernel reaches its static container, and a code the kernel only
 * reports is never a code of the error table.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - Remove KernelError from Modules::ERROR_CATALOGS: the catalog list differs from the search.
 * - Add an event under src/ that implements DomainEvent only through an abstract base class, and
 *   leave it out of Modules::EVENT_CLASSES: the event list differs from the search.
 * - Call `Kernel::container()` from any other file under src/: the entry-point rule fails.
 * - Change Modules::CAPABILITY_PREFIX: it no longer equals the declaration's.
 * - Change Modules::JOB_HOOK: it no longer equals the runner's hook.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class KernelListsTest extends TestCase {

	/**
	 * Tests that the error catalogs the kernel composes are exactly the catalogs under src/, and compose.
	 *
	 * @since 0.1.0
	 */
	public function test_the_error_catalog_list_is_every_catalog_under_src(): void {
		$found  = array_keys( ErrorCatalogs::under( 'src' ) );
		$listed = Modules::ERROR_CATALOGS;

		sort( $listed );

		$this->assertNotSame( array(), $found, 'The search found no catalog, so the comparison would prove nothing.' );
		$this->assertSame( $found, $listed, 'Modules::ERROR_CATALOGS must list every error catalog under src/, and nothing else.' );
		$this->assertNotSame( array(), ErrorTable::compose( ...Modules::ERROR_CATALOGS )->definitions() );
	}

	/**
	 * Tests that the event list is every domain event under src/. The search is first shown to find
	 * an event that implements DomainEvent only through its base class, and to leave the abstract
	 * base out, so a search that finds nothing cannot pass for one that found everything.
	 *
	 * @since 0.1.0
	 */
	public function test_the_event_list_is_every_domain_event_under_src(): void {
		$this->assertSame( array( InheritedEvent::class ), self::domainEventsUnder( 'tests/Unit/Platform/Kernel/Fixtures' ), 'The search did not find exactly the concrete fixture event, so an empty result would prove nothing.' );

		$listed = Modules::EVENT_CLASSES;

		sort( $listed );

		$this->assertSame( self::domainEventsUnder( 'src' ), $listed, 'Modules::EVENT_CLASSES must list every domain event under src/, and nothing else.' );
	}

	/**
	 * Tests that the prefix the capability filter checks is the declaration's.
	 *
	 * @since 0.1.0
	 */
	public function test_the_capability_prefix_is_the_declarations(): void {
		$this->assertSame( CapabilityDeclaration::PREFIX, Modules::CAPABILITY_PREFIX );
	}

	/**
	 * Tests that the hook the kernel adds for the plugin's jobs is the one the runner and the queue use.
	 *
	 * @since 0.1.0
	 */
	public function test_the_job_hook_is_the_runners(): void {
		$this->assertSame( JobRunner::HOOK, Modules::JOB_HOOK );
	}

	/**
	 * Tests that no file under src/ but the kernel's own reaches the static container.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_kernel_reaches_the_static_container(): void {
		$callers = array();

		foreach ( PhpSource::files( 'src' ) as $file => $source ) {
			if ( 'src/Platform/Kernel/Kernel.php' !== $file && 1 === preg_match( '/\bKernel\s*::\s*container\s*\(/', $source ) ) {
				$callers[] = $file;
			}
		}

		$this->assertSame( array(), $callers, 'Only the WordPress entry points in Kernel may reach the container statically; everything else receives what it needs.' );
	}

	/**
	 * Tests that the codes the kernel reports are never codes of the error table.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernels_reported_codes_are_not_error_codes(): void {
		$reported = array();

		foreach ( PhpSource::files( 'src/Platform/Kernel' ) as $source ) {
			preg_match_all( "/'(kernel\\.[a-z0-9_]+)'/", $source, $matches );
			$reported = array_merge( $reported, $matches[1] );
		}

		$table = array();

		foreach ( ErrorTable::compose( ...Modules::ERROR_CATALOGS )->definitions() as $row ) {
			$table[] = (string) $row->code()->value;
		}

		$this->assertNotSame( array(), $reported, 'The search found no reported code, so the comparison would prove nothing.' );
		$this->assertSame( array(), array_values( array_intersect( $reported, $table ) ), 'A reported code spells a code of the error table.' );
	}

	/**
	 * Finds the concrete domain events declared under a directory.
	 *
	 * Every declaration there is loaded and kept when it is an instantiable class that implements
	 * DomainEvent, directly, through a base class or through another interface, as the error-catalog
	 * search does. A declaration the autoloader cannot load by its name fails the test, named,
	 * rather than being skipped.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory A directory relative to the repository root.
	 * @return list<string> The event classes, sorted.
	 *
	 * @throws \Error When a declaration fails to load for any reason other than a missing global parent class.
	 */
	private static function domainEventsUnder( string $directory ): array {
		$found      = array();
		$unloadable = array();

		foreach ( PhpSource::files( $directory ) as $path => $source ) {
			foreach ( PhpSource::declarations( $source ) as $class ) {
				try {
					$loadable = class_exists( $class ) || interface_exists( $class ) || trait_exists( $class );
				} catch ( \Error $error ) {
					// A class whose parent is a global class the unit suite never loads (a WordPress
					// class such as wpdb) cannot be loaded here, and cannot be a domain event either:
					// events are pure data and load without WordPress. Any other failure is raised.
					if ( 1 === preg_match( '/^Class "[A-Za-z_][A-Za-z0-9_]*" not found$/', $error->getMessage() ) ) {
						continue;
					}

					throw $error;
				}

				if ( ! $loadable ) {
					$unloadable[] = $class . ' (declared in ' . $path . ')';

					continue;
				}

				if ( is_subclass_of( $class, DomainEvent::class ) && ( new \ReflectionClass( $class ) )->isInstantiable() ) {
					$found[] = $class;
				}
			}
		}

		self::assertSame( array(), $unloadable, 'These declarations cannot be loaded by their names: the file name or namespace does not match the autoloader.' );

		sort( $found );

		return $found;
	}
}

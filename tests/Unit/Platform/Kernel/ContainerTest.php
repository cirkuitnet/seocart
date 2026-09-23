<?php
/**
 * Tests the service container
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Kernel;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Support\Clock;
use SEOCart\Support\SystemClock;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * Builds on first use, once; one factory per service; an override wins; an unknown id, a cycle or a
 * service of the wrong type is a programming error.
 *
 * @since 0.1.0
 */
final class ContainerTest extends TestCase {

	/**
	 * Tests that binding builds nothing and that a service is built once, with the container.
	 *
	 * @since 0.1.0
	 */
	public function test_a_service_is_built_on_first_use_and_only_once(): void {
		$container = new Container();
		$builds    = 0;
		$given     = null;

		$container->bind(
			Clock::class,
			static function ( Container $c ) use ( &$builds, &$given ): Clock {
				++$builds;
				$given = $c;

				return new SystemClock();
			}
		);

		$this->assertSame( 0, $builds, 'Binding built the service.' );
		$this->assertTrue( $container->has( Clock::class ) );

		$first = $container->get( Clock::class );

		$this->assertSame( $first, $container->get( Clock::class ) );
		$this->assertSame( 1, $builds );
		$this->assertSame( $container, $given );
	}

	/**
	 * Tests that an override wins over the bound factory, which is never called.
	 *
	 * @since 0.1.0
	 */
	public function test_an_override_wins_over_the_binding(): void {
		$frozen    = FrozenClock::at( '2026-09-23T00:00:00Z' );
		$container = new Container( array( Clock::class => static fn(): Clock => $frozen ) );

		$container->bind(
			Clock::class,
			static function (): Clock {
				throw new \LogicException( 'The bound factory ran although an override was given.' );
			}
		);

		$this->assertSame( $frozen, $container->get( Clock::class ) );
	}

	/**
	 * Tests that a service id can be bound once only.
	 *
	 * @since 0.1.0
	 */
	public function test_an_id_is_bound_once(): void {
		$container = new Container();
		$container->bind( Clock::class, static fn(): Clock => new SystemClock() );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'already bound' );

		$container->bind( Clock::class, static fn(): Clock => new SystemClock() );
	}

	/**
	 * Tests that an unknown id is a programming error.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unknown_id_is_a_programming_error(): void {
		$container = new Container();

		$this->assertFalse( $container->has( Clock::class ) );
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'Nothing is bound' );

		$container->get( Clock::class );
	}

	/**
	 * Tests that a service that needs itself fails instead of recursing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cycle_fails(): void {
		$container = new Container();
		$container->bind( Clock::class, static fn( Container $c ): Clock => $c->get( Clock::class ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'needs itself' );

		$container->get( Clock::class );
	}

	/**
	 * Tests that a factory must build an instance of the type its id names.
	 *
	 * @since 0.1.0
	 */
	public function test_a_service_of_another_type_is_refused(): void {
		$container = new Container();
		$container->bind( Clock::class, static fn(): object => new \ArrayObject() );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'built a ArrayObject' );

		$container->get( Clock::class );
	}

	/**
	 * Tests that an id that names no type yet is bound and resolved by name.
	 *
	 * @since 0.1.0
	 */
	public function test_an_id_that_names_no_type_is_resolved_by_name(): void {
		$service   = new \ArrayObject();
		$container = new Container();

		$container->bind( 'SEOCart\\Not\\Yet\\Defined', static fn(): object => $service );

		$this->assertTrue( $container->has( 'SEOCart\\Not\\Yet\\Defined' ) );
		$this->assertSame( $service, $container->get( 'SEOCart\\Not\\Yet\\Defined' ) ); // @phpstan-ignore argument.type (An id may name a class another module has not defined yet.)
	}
}

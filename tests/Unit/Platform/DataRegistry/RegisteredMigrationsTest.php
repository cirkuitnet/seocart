<?php
/**
 * Tests that every migration class the plugin ships is in the production registry, and nothing else is
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\DataRegistry;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * The migration chain the registry builds holds exactly the migration classes under src/.
 *
 * The other registry checks compare the registry with itself: its tables with the tables its
 * own migrations declare, and a database migrated by its own chain with its own tables. A
 * module that contributes nothing at all passes every one of them, and its tables are never
 * created on a new site. So the chain is held equal, in both directions, to every concrete
 * class under src/ that implements the migration interface, found by reading the source, as
 * DRY rule 11 asks of a hand-kept list. A declaration in a migration source that the
 * autoloader cannot load by its name fails the test too, named, rather than being skipped.
 *
 * Planted violations: drop the outbox line from OwnedData::registry(); add a migration class
 * under src/ that no module contributes; add a migration file whose class name does not match
 * the file's path.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class RegisteredMigrationsTest extends TestCase {

	/**
	 * Tests that the registry's migrations and the migration classes under src/ are the same set.
	 *
	 * @since 0.1.0
	 */
	public function test_the_registered_migrations_are_every_migration_class_under_src(): void {
		$shipped = self::migrationClasses();

		$this->assertContains( 'SEOCart\\Platform\\Database\\Migrations\\PlatformBootstrapMigration', $shipped, 'The search found not even the bootstrap migration; it cannot be trusted to find the others.' );

		$registered = array_map( 'get_class', OwnedData::registry()->migrations() );

		sort( $registered );

		$this->assertSame( array(), array_values( array_diff( $shipped, $registered ) ), 'These migrations are shipped under src/, but no module contributes them to OwnedData::registry(): a new site would never run them.' );
		$this->assertSame( array(), array_values( array_diff( $registered, $shipped ) ), 'These migrations are registered, but are not concrete migration classes under src/.' );
		$this->assertSame( count( $registered ), count( array_unique( $registered ) ), 'A migration class is registered twice.' );
	}

	/**
	 * Finds every concrete class under src/ that implements the migration interface.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The class names, sorted.
	 */
	private static function migrationClasses(): array {
		$classes    = array();
		$unloadable = array();

		foreach ( PhpSource::files( 'src' ) as $path => $source ) {
			if ( ! str_contains( $source, 'Migration' ) ) {
				continue;
			}

			foreach ( PhpSource::declarations( $source ) as $class ) {
				if ( interface_exists( $class ) || trait_exists( $class ) || enum_exists( $class ) ) {
					continue;
				}

				if ( ! class_exists( $class ) ) {
					// A class the autoloader cannot find is shipped but can never run: it must not pass unseen.
					$unloadable[] = $class . ' (declared in ' . $path . ')';

					continue;
				}

				$reflection = new \ReflectionClass( $class );

				if ( $reflection->implementsInterface( Migration::class ) && $reflection->isInstantiable() ) {
					$classes[] = $reflection->getName();
				}
			}
		}

		self::assertSame( array(), $unloadable, 'These declarations in migration sources cannot be loaded by their names: the file name or namespace does not match the autoloader.' );

		sort( $classes );

		return $classes;
	}
}

<?php
/**
 * Tests that the migrator the kernel wires runs exactly the data registry's migrations
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Tests\Support\KernelTestCase;

/**
 * The data registry is the one production list of what the plugin owns in a site, its migrations
 * included. The coverage tests prove the registry's migrations create the registry's tables; this
 * test proves the migrator the kernel wires runs those migrations and no others, so the two cannot
 * drift apart.
 *
 * The wired migrator is the production binding, built by Modules::register() with only the
 * connection replaced by the test's. Its chain is read back the way an operator sees it: on a site
 * without plugin tables, status() lists every migration of the chain as pending, in order, and
 * codeHead() names the last.
 *
 * Planted violation: in Modules::databaseRegister(), give the migrator the registry's migrations
 * and one more, `array_merge( $c->get( DataRegistry::class )->migrations(), array( new
 * \SEOCart\Tests\Support\Migrations\CreatesTestTable( '20990101_0001_unregistered', 'unregistered' ) ) )`:
 * the pending list and the code head differ from the registry's.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class WiredMigrationsTest extends KernelTestCase {

	/**
	 * Tests that the wired migrator's chain is the registry's migrations, in the same order.
	 *
	 * @since 0.1.0
	 */
	public function test_the_wired_migrator_runs_exactly_the_registrys_migrations(): void {
		$registered = array_map( static fn( Migration $migration ): string => $migration->id(), OwnedData::registry()->migrations() );

		$this->assertNotSame( array(), $registered, 'The registry lists no migration, so the comparison would prove nothing.' );
		$this->assertFalse( $this->pluginTableExists( 'migrations' ), 'The site already has plugin tables, so status() would not list the whole chain.' );

		$container = new Container( array( Database::class => fn(): Database => $this->db ) );

		Modules::register( $container );

		$migrator = $container->get( Migrator::class );

		$this->assertSame( $registered, $migrator->status()->pending(), 'The kernel wires the migrator with other migrations than the data registry lists.' );
		$this->assertSame( $registered[ count( $registered ) - 1 ], $migrator->codeHead() );
	}
}

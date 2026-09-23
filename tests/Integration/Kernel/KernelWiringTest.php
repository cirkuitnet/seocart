<?php
/**
 * Tests which hooks and files the kernel costs each kind of request
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Tests\Support\ChildProcessProbe;
use WP_UnitTestCase;

/**
 * The kernel's wiring, as a request really boots it: on an idle front-end request the plugin adds
 * exactly the hooks listed here and loads four files, and the hooks of the admin, WP-CLI, cron and
 * a network appear in their own kind of request only.
 *
 * Every request is booted in a child process of its own (tests/Support/idle-request-probe.php for
 * the idle request, tests/Support/kernel-hooks-probe.php for the others), because the kernel
 * decides what to add when it boots and the PHPUnit process booted as a front-end request once.
 * A callback is compared by its description without the line number, so moving code within a
 * file does not break the list.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In Modules::authorizationSubscribe(), remove the `map_meta_cap` filter: every list differs.
 * - In Modules::subscribe(), add `$container->get( \SEOCart\Platform\Database\Database::class );`:
 *   the idle request loads the database module's files, and the file list differs.
 *
 * @since 0.1.0
 */
final class KernelWiringTest extends WP_UnitTestCase {

	/**
	 * How the idle report names a callback written in the module wiring.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULES = 'closure at src/Platform/Kernel/Modules.php';

	/**
	 * Tests the idle front-end request: exactly the kernel's hooks, and its four files.
	 *
	 * @since 0.1.0
	 */
	public function test_an_idle_request_costs_the_listed_hooks_and_four_files(): void {
		$report = ChildProcessProbe::run( dirname( __DIR__, 2 ) . '/Support/idle-request-probe.php', array( 'with-plugin' ) );

		$this->assertTrue( $report['plugin_loaded'], 'The probe did not load SEOCart, so the lists prove nothing.' );
		$this->assertSame( self::expectedHooks( 'front' ), self::describe( $report['hooks'] ) );
		$this->assertSame(
			array( 'seocart.php', 'src/Platform/Kernel/Container.php', 'src/Platform/Kernel/Kernel.php', 'src/Platform/Kernel/Modules.php' ),
			array_keys( $report['files'] ),
			'An idle request loads the main file, the kernel, the container and the module wiring, and nothing else.'
		);
	}

	/**
	 * Returns the kinds of request whose hooks are checked.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string}> The kinds.
	 */
	public static function contexts(): array {
		return array(
			'a front-end request' => array( 'front' ),
			'an admin request'    => array( 'admin' ),
			'a WP-CLI run'        => array( 'cli' ),
			'a cron run'          => array( 'cron' ),
		);
	}

	/**
	 * Tests that each kind of request gets its own hooks, and no other kind's.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider contexts
	 *
	 * @param string $context The kind of request.
	 */
	public function test_each_kind_of_request_gets_its_own_hooks_only( string $context ): void {
		$report = ChildProcessProbe::run( dirname( __DIR__, 2 ) . '/Support/kernel-hooks-probe.php', array( $context ) );

		$this->assertSame( 'admin' === $context, $report['is_admin'], 'The probe did not boot the kind of request it was asked for.' );
		$this->assertSame( is_multisite(), $report['multisite'] );
		$this->assertSame( self::expectedHooks( $context ), self::describe( $report['hooks'] ) );
	}

	/**
	 * Returns the hooks the plugin must have added after booting in a kind of request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $context front, admin, cli or cron.
	 * @return list<string> Each hook as `name @priority callback`, sorted.
	 */
	private static function expectedHooks( string $context ): array {
		$plugin = plugin_basename( SEOCART_PLUGIN_FILE );
		$hooks  = array(
			'plugins_loaded @10 SEOCart\\Platform\\Kernel\\Kernel::boot',
			'activate_' . $plugin . ' @10 SEOCart\\Platform\\Kernel\\Kernel::activate',
			'deactivate_' . $plugin . ' @10 SEOCart\\Platform\\Kernel\\Kernel::deactivate',
			'map_meta_cap @10 ' . self::MODULES,
		);

		if ( 'admin' === $context ) {
			$hooks[] = 'admin_init @10 ' . self::MODULES;
			$hooks[] = 'admin_notices @10 ' . self::MODULES;
			$hooks[] = 'network_admin_notices @10 ' . self::MODULES;
			$hooks[] = 'admin_post_seocart_dismiss_notice @10 ' . self::MODULES;
			$hooks[] = 'admin_post_seocart_safe_mode @10 ' . self::MODULES;
			$hooks[] = 'admin_post_seocart_retry_migration @10 ' . self::MODULES;
		}

		if ( 'admin' === $context || 'cli' === $context ) {
			$hooks[] = 'gettext_with_context_default @10 SEOCart\\Platform\\Authorization\\RoleNames::translate';
		}

		if ( 'cli' === $context ) {
			$hooks[] = 'cli_init @10 ' . self::MODULES;
		}

		if ( 'cron' === $context ) {
			$hooks[] = 'init @10 ' . self::MODULES;
		}

		if ( is_multisite() ) {
			$hooks[] = 'wp_initialize_site @20 ' . self::MODULES;
			$hooks[] = 'wpmu_drop_tables @10 ' . self::MODULES;
		}

		sort( $hooks );

		return $hooks;
	}

	/**
	 * Describes reported hooks the way expectedHooks() lists them.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array{hook: string, priority: int, callback: string}> $hooks The probe's hooks.
	 * @return list<string> Each hook as `name @priority callback`, without line numbers, sorted.
	 */
	private static function describe( array $hooks ): array {
		$described = array();

		foreach ( $hooks as $hook ) {
			$described[] = $hook['hook'] . ' @' . $hook['priority'] . ' ' . (string) preg_replace( '/:\d+$/', '', $hook['callback'] );
		}

		sort( $described );

		return $described;
	}
}

<?php
/**
 * Tests the degraded-mode notice, its dismissal, and when a page load tries the pending migrations
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Database\Exception\MigrationFailed;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Kernel\Notices;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Migrations\CreatesTestTable;

/**
 * The merchant is told when the store refuses changes and why, can dismiss the notice until the
 * condition changes, and a page load tries a pending migration but never repeats a failed one.
 *
 * The notices are rendered through the hooks the kernel adds on an admin request, for the
 * administrator the test suite installs.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In Lifecycle::attemptMigration(), remove the `failed()` check:
 *   test_a_failed_migration_is_never_retried_by_a_page_load sees an attempt and fails.
 * - In Notices::isDismissed(), compare `false !== get_user_option(...)` instead of the fingerprint:
 *   test_a_dismissed_notice_returns_when_its_condition_changes never sees the notice again and fails.
 *
 * @since 0.1.0
 */
final class DegradedNoticeTest extends KernelTestCase {

	/**
	 * The id of the fixture migration the code carries and the database has not applied.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PENDING = '20990101_0001_notice_pending';

	/**
	 * The fixture migration, shared by the test's containers so its runs are counted in one place.
	 *
	 * @since 0.1.0
	 *
	 * @var CreatesTestTable
	 */
	private CreatesTestTable $pending;

	/**
	 * Makes this an admin request by the site's administrator.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->pending = new CreatesTestTable( self::PENDING, 'notice_pending', false );

		wp_set_current_user( 1 );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Removes the administrator's dismissals and leaves the admin screen.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		delete_user_option( 1, Notices::DISMISSED_OPTION . Notices::DEGRADED );
		delete_user_option( 1, Notices::DISMISSED_OPTION . Notices::SAFE_MODE );
		unset( $_GET['_wpnonce'], $_GET['notice'], $_REQUEST['_wpnonce'], $_REQUEST['notice'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Tests that an up-to-date store shows no notice at all.
	 *
	 * @since 0.1.0
	 */
	public function test_an_up_to_date_store_shows_no_notice(): void {
		$this->installed();

		$this->assertSame( '', $this->notices( $this->container() ) );
	}

	/**
	 * Tests the notice of a site without a record.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_being_installed_says_so(): void {
		$this->assertStringContainsString( 'SEOCart is finishing its installation; reload this page.', $this->notices( $this->container() ) );
	}

	/**
	 * Tests the notice of a pending migration: what is happening, and the command that finishes it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_pending_migration_says_the_store_refuses_changes(): void {
		$this->installed();

		$html = $this->notices( $this->newerCode() );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'SEOCart is updating its database; the store refuses changes until it finishes.', $html );
		$this->assertStringContainsString( '<code>wp seocart migrate</code>', $html );
		$this->assertStringNotContainsString( 'failed', $html );
		$this->assertStringContainsString( 'action=' . Notices::DISMISS_ACTION, $html );
	}

	/**
	 * Tests the notice of a failed migration: its id, and a nonce-checked Retry link.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_migration_is_named_and_can_be_retried(): void {
		$this->installed();
		$this->failPendingMigration();

		$html = $this->notices( $this->newerCode() );

		$this->assertStringContainsString( 'The migration <code>' . self::PENDING . '</code> failed.', $html );
		$this->assertMatchesRegularExpression( '/action=' . Notices::RETRY_ACTION . '(&|&amp;|&#038;)_wpnonce=[0-9a-f]{10}/', $html );
	}

	/**
	 * Tests the notice of a database a newer version migrated.
	 *
	 * @since 0.1.0
	 */
	public function test_a_newer_schema_says_downgrades_are_not_supported(): void {
		$this->plantRecord( self::installedRecord( '20991231_0001_from_a_newer_version' ) );

		$html = $this->notices( $this->container() );

		$this->assertStringContainsString( 'This database was updated by a newer version of SEOCart', $html );
		$this->assertStringContainsString( 'downgrades are not supported', $html );
	}

	/**
	 * Tests the dismissal: the dismiss link records the condition, the notice goes, and it comes back
	 * as soon as the condition is another.
	 *
	 * @since 0.1.0
	 */
	public function test_a_dismissed_notice_returns_when_its_condition_changes(): void {
		$this->installed();

		$container = $this->newerCode();

		$this->followDismissLink( $container, Notices::DEGRADED );

		$this->assertNotFalse( get_user_option( Notices::DISMISSED_OPTION . Notices::DEGRADED, 1 ), 'The dismissal was not recorded.' );
		$this->assertSame( '', $this->notices( $container ), 'The dismissed notice is still shown.' );

		$this->failPendingMigration();

		$this->assertStringContainsString( 'failed', $this->notices( $this->newerCode() ), 'The notice did not come back when its condition changed.' );
	}

	/**
	 * Tests that the dismiss link refuses a request without the nonce.
	 *
	 * @since 0.1.0
	 */
	public function test_the_dismiss_link_needs_its_nonce(): void {
		$this->installed();
		$this->subscribeAdmin( $this->newerCode() );

		$_GET['notice']     = Notices::DEGRADED;
		$_REQUEST['notice'] = Notices::DEGRADED;

		$this->expectException( \WPDieException::class );

		do_action( 'admin_post_' . Notices::DISMISS_ACTION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the admin-post.php action the notice's link calls, fired as admin-post.php fires it.
	}

	/**
	 * Tests that a page load applies a pending migration once, and then has nothing to do.
	 *
	 * @since 0.1.0
	 */
	public function test_a_page_load_applies_a_pending_migration_once(): void {
		$this->installed();

		$this->newerCode()->get( Lifecycle::class )->reconcile();
		$this->newerCode()->get( Lifecycle::class )->reconcile();

		$this->assertSame( 1, $this->pending->runs );
		$this->assertTrue( $this->pluginTableExists( 'test_notice_pending' ) );
		$this->assertSame( self::PENDING, BootRecord::fromJson( $this->storedRecord() )->schemaHead() );
	}

	/**
	 * Tests that a failed migration is never retried by a page load, only by the Retry link.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_migration_is_never_retried_by_a_page_load(): void {
		$this->installed();
		$this->failPendingMigration();

		$this->pending->runs     = 0;
		$this->pending->beforeUp = null;

		$this->newerCode()->get( Lifecycle::class )->reconcile();

		$this->assertSame( 0, $this->pending->runs, 'A page load ran a migration that had failed.' );

		$this->newerCode()->get( Lifecycle::class )->retryMigration();

		$this->assertSame( 1, $this->pending->runs, 'The Retry link did not run the migration.' );
	}

	/**
	 * Installs the site as the code before the pending migration would.
	 *
	 * @since 0.1.0
	 */
	private function installed(): void {
		$this->container()->get( Lifecycle::class )->activate();
	}

	/**
	 * Returns a container whose code carries the pending migration, which cannot run half-applied.
	 *
	 * @since 0.1.0
	 *
	 * @return Container The container.
	 */
	private function newerCode(): Container {
		$report  = $this->reporter();
		$pending = $this->pending;

		return $this->container(
			array(
				Migrator::class => static fn( Container $c ): Migrator => KernelContainer::migrator( $c, array( new PlatformBootstrapMigration(), $pending ), $report ),
			)
		);
	}

	/**
	 * Runs the pending migration so that it fails, as a broken release would.
	 *
	 * @since 0.1.0
	 */
	private function failPendingMigration(): void {
		$this->pending->beforeUp = static function (): void {
			throw new \RuntimeException( 'The fixture migration fails on purpose.' );
		};

		try {
			$this->newerCode()->get( Migrator::class )->migrate( new MigrationRunOptions( 0 ) );
			$this->fail( 'The fixture migration did not fail.' );
		} catch ( MigrationFailed $failed ) {
			$this->assertSame( self::PENDING, $failed->migrationId() );
		}
	}

	/**
	 * Adds the hooks the kernel adds on an admin request, resolving from a container.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private function subscribeAdmin( Container $container ): void {
		$this->assertTrue( is_admin(), 'The test is not an admin request.' );

		Modules::subscribe( $container );
	}

	/**
	 * Returns what the admin notices print.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container the hooks resolve from.
	 * @return string The HTML.
	 */
	private function notices( Container $container ): string {
		remove_all_actions( 'admin_notices' );
		$this->subscribeAdmin( $container );

		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core hook, fired as an admin screen fires it.

		return trim( (string) ob_get_clean() );
	}

	/**
	 * Follows a notice's dismiss link, as the administrator's browser would, until it redirects.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container the hooks resolve from.
	 * @param string    $notice    The notice's key.
	 */
	private function followDismissLink( Container $container, string $notice ): void {
		$this->subscribeAdmin( $container );

		$nonce = wp_create_nonce( Notices::DISMISS_ACTION );

		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_GET['notice']       = $notice;
		$_REQUEST['notice']   = $notice;

		add_filter(
			'wp_redirect',
			static function ( $location ): string {
				// Stops the request where the handler would redirect and exit.
				if ( is_string( $location ) ) {
					throw new \RuntimeException( 'Redirected to ' . $location );
				}

				return '';
			}
		);

		try {
			do_action( 'admin_post_' . Notices::DISMISS_ACTION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the admin-post.php action the notice's link calls, fired as admin-post.php fires it.
			$this->fail( 'The dismiss link did not redirect back.' );
		} catch ( \RuntimeException $redirected ) {
			$this->assertStringStartsWith( 'Redirected to ', $redirected->getMessage() );
		}
	}
}

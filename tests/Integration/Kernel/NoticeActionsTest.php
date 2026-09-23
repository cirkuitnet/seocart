<?php
/**
 * Tests that the notices' actions refuse whoever may not take them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Kernel\Notices;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Migrations\CreatesTestTable;

/**
 * Each action a notice offers changes the store, so each checks its nonce and the capability again
 * when it runs: a link that reaches the wrong user, or a request forged without the nonce, changes
 * nothing and ends as forbidden.
 *
 * The actions are fired as admin-post.php fires them, through the hooks the kernel adds on an admin
 * request. A refusal ends the request with wp_die(), which the test library turns into a
 * WPDieException carrying the HTTP status. An action that is let through redirects, which these
 * tests turn into an exception as well, so a missing check fails the test instead of ending it.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In Notices::handleSafeModeChoice(), remove the `current_user_can()` check: the subscriber's
 *   answers are let through, and on a network so are the site administrator's.
 * - In Notices::handleRetry(), remove the `current_user_can()` check: the subscriber's retry runs
 *   the pending migration.
 * - In Notices::handleSafeModeChoice(), remove `check_admin_referer()`: an answer without a nonce is
 *   let through.
 * - In Notices::handleRetry(), remove `check_admin_referer()`: a retry without a nonce runs.
 * - In Notices::handleDismiss(), remove the `current_user_can()` check: the subscriber's dismissals
 *   are recorded, and on a network so is the site administrator's.
 *
 * @since 0.1.0
 */
final class NoticeActionsTest extends KernelTestCase {

	use CreatesUsers;

	/**
	 * The id of the fixture migration the code carries and the database has not applied.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PENDING = '20990101_0001_actions_pending';

	/**
	 * The fixture migration a let-through retry would run.
	 *
	 * @since 0.1.0
	 *
	 * @var CreatesTestTable
	 */
	private CreatesTestTable $pending;

	/**
	 * Makes this an admin request, and turns a redirect into an exception.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->pending = new CreatesTestTable( self::PENDING, 'actions_pending', false );

		set_current_screen( 'dashboard' );
		add_filter(
			'wp_redirect',
			static function ( $location ): string {
				// Stops the request where a let-through action would redirect and exit.
				if ( is_string( $location ) ) {
					throw new \RuntimeException( 'Redirected to ' . $location );
				}

				return '';
			}
		);
	}

	/**
	 * Forgets the request's arguments, leaves the admin screen and deletes the users the test created.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		unset( $_GET['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['choice'], $_REQUEST['choice'], $_GET['notice'], $_REQUEST['notice'] );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a subscriber who holds a valid nonce is refused both answers to the Safe Mode question.
	 *
	 * @since 0.1.0
	 */
	public function test_a_subscriber_with_a_valid_nonce_cannot_answer_the_safe_mode_question(): void {
		$this->copiedStore();

		wp_set_current_user( $this->createUser( 'subscriber' ) );

		foreach ( array( 'adopt', 'copy' ) as $choice ) {
			$this->assertForbidden( Notices::SAFE_MODE_ACTION, array( 'choice' => $choice ), true );
		}
	}

	/**
	 * Tests that a subscriber who holds a valid nonce is refused the dismissal of either notice, and none is recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_subscriber_with_a_valid_nonce_cannot_dismiss_a_notice(): void {
		$this->copiedStore();

		wp_set_current_user( $this->createUser( 'subscriber' ) );

		foreach ( array( Notices::SAFE_MODE, Notices::DEGRADED ) as $notice ) {
			$this->assertForbidden( Notices::DISMISS_ACTION, array( 'notice' => $notice ), true );
		}
	}

	/**
	 * Tests that a subscriber who holds a valid nonce is refused the Retry link, and no migration runs.
	 *
	 * @since 0.1.0
	 */
	public function test_a_subscriber_with_a_valid_nonce_cannot_retry_a_migration(): void {
		$this->copiedStore();

		wp_set_current_user( $this->createUser( 'subscriber' ) );

		$this->assertForbidden( Notices::RETRY_ACTION, array(), true );
	}

	/**
	 * Tests that on a network the Safe Mode question is the super admins' alone: a site's own
	 * administrator, with a valid nonce, is refused both answers, and the dismissal of the notice.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_administrator_who_is_not_a_super_admin_cannot_answer_on_a_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only a network has administrators who are not super admins.' );
		}

		$this->copiedStore();

		$administrator = $this->createUser( 'administrator' );

		wp_set_current_user( $administrator );

		$this->assertFalse( is_super_admin( $administrator ) );
		$this->assertTrue( current_user_can( 'manage_options' ), 'The user does not administer the site, so the refusal would prove nothing.' );

		foreach ( array( 'adopt', 'copy' ) as $choice ) {
			$this->assertForbidden( Notices::SAFE_MODE_ACTION, array( 'choice' => $choice ), true );
		}

		$this->assertForbidden( Notices::DISMISS_ACTION, array( 'notice' => Notices::SAFE_MODE ), true );
	}

	/**
	 * Tests that the Safe Mode answers and the Retry link refuse a request without the nonce, from a
	 * user who may take every one of them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_safe_mode_answers_and_the_retry_need_their_nonces(): void {
		$this->copiedStore();

		wp_set_current_user( 1 );

		$this->assertTrue( current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) && current_user_can( 'activate_plugins' ), 'The user may not take the actions, so the refusals would prove nothing.' );

		$this->assertForbidden( Notices::SAFE_MODE_ACTION, array( 'choice' => 'adopt' ), false );
		$this->assertForbidden( Notices::SAFE_MODE_ACTION, array( 'choice' => 'copy' ), false );
		$this->assertForbidden( Notices::RETRY_ACTION, array(), false );
	}

	/**
	 * Installs the store, moves it to another address as a copy would be, and adds the kernel's
	 * admin hooks over code that carries a migration the database has not applied.
	 *
	 * @since 0.1.0
	 */
	private function copiedStore(): void {
		$this->container()->get( Lifecycle::class )->activate();

		add_filter( 'home_url', static fn(): string => 'https://copy.example.net' );

		$report  = $this->reporter();
		$pending = $this->pending;

		Modules::subscribe(
			$this->container(
				array(
					Migrator::class => static fn( Container $c ): Migrator => KernelContainer::migrator( $c, array( new PlatformBootstrapMigration(), $pending ), $report ),
				)
			)
		);
	}

	/**
	 * Fires an action as admin-post.php would, and requires it to end as forbidden with nothing changed:
	 * not the boot record, not the migrations, not the current user's dismissals.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $action    The admin-post.php action, which is also its nonce action.
	 * @param array<string, string> $arguments The request's other arguments.
	 * @param bool                  $withNonce Whether the request carries a valid nonce for the current user.
	 */
	private function assertForbidden( string $action, array $arguments, bool $withNonce ): void {
		$before = $this->storedRecord();

		unset( $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );

		if ( $withNonce ) {
			$nonce = wp_create_nonce( $action );

			$_GET['_wpnonce']     = $nonce;
			$_REQUEST['_wpnonce'] = $nonce;
		}

		foreach ( $arguments as $name => $value ) {
			$_GET[ $name ]     = $value;
			$_REQUEST[ $name ] = $value;
		}

		$label = $action . ( isset( $arguments['choice'] ) ? ' (' . $arguments['choice'] . ')' : '' ) . ( isset( $arguments['notice'] ) ? ' (' . $arguments['notice'] . ')' : '' ) . ( $withNonce ? '' : ' without a nonce' );

		try {
			do_action( 'admin_post_' . $action ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the admin-post.php action a notice's link calls, fired as admin-post.php fires it.
			$this->fail( "The action {$label} neither ended the request nor redirected." );
		} catch ( \WPDieException $refused ) {
			$this->assertSame( 403, $refused->getCode(), "The action {$label} was refused, but not as forbidden." );
		} catch ( \RuntimeException $redirected ) {
			$this->fail( "The action {$label} was let through. " . $redirected->getMessage() );
		}

		$this->assertSame( $before, $this->storedRecord(), "The refused action {$label} changed the boot record." );
		$this->assertSame( 0, $this->pending->runs, "The refused action {$label} ran a migration." );

		foreach ( array( Notices::SAFE_MODE, Notices::DEGRADED ) as $notice ) {
			$this->assertFalse( get_user_option( Notices::DISMISSED_OPTION . $notice ), "The refused action {$label} recorded a dismissal of the {$notice} notice." );
		}
	}
}

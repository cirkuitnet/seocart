<?php
/**
 * CreatesUsers: users a committing test creates, and deletes again
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * Creates the users a DatabaseTestCase acts as, and deletes them in its tear_down().
 *
 * Owns one fact: how a test that commits gets a user of its own. The WordPress factories may not
 * be used there, because their rows would be committed and outlive the test. And the site's
 * first user will not do when a test narrows capabilities with a `user_has_cap` filter: on a
 * network that user is a super admin, whom WordPress grants every capability before the filter
 * runs, so a refusal the test expects never happens. A user created here is never a super admin.
 *
 * The test calls deleteCreatedUsers() from its tear_down().
 *
 * @since 0.1.0
 */
trait CreatesUsers {

	/**
	 * The ids of the users this test created.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $createdUsers = array();

	/**
	 * Creates a user of the current site with a role, and remembers to delete it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $role The role, for example `administrator` or `subscriber`.
	 * @return int The user's id.
	 */
	protected function createUser( string $role ): int {
		$login = 'seocart_test_' . strtolower( wp_generate_password( 12, false ) );
		$id    = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 32 ),
				'user_email' => $login . '@example.org',
				'role'       => $role,
			)
		);

		if ( is_wp_error( $id ) ) {
			self::fail( 'The test user could not be created: ' . $id->get_error_message() );
		}

		$this->createdUsers[] = $id;

		return $id;
	}

	/**
	 * Deletes every user this test created, from the whole network when there is one.
	 *
	 * @since 0.1.0
	 */
	protected function deleteCreatedUsers(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		foreach ( $this->createdUsers as $id ) {
			if ( is_multisite() ) {
				wpmu_delete_user( $id );
			} else {
				wp_delete_user( $id );
			}
		}

		$this->createdUsers = array();
	}
}

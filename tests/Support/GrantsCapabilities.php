<?php
/**
 * GrantsCapabilities: a user of the test's own who holds the capabilities a test names
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Authorization\Actor;

/**
 * Creates a subscriber and grants it capabilities through `user_has_cap`, for a test that acts as someone allowed to do one thing.
 *
 * Owns one fact: how a test gets an actor with exactly the capabilities it needs without installing
 * the plugin's roles. The filter is the test's, so it goes with the test's hooks; the user is
 * created with CreatesUsers::createUser(), which the class using this trait provides and whose
 * users it deletes in its tear_down().
 *
 * @since 0.2.0
 */
trait GrantsCapabilities {

	/**
	 * Creates a user of the current site with a role, and remembers to delete it: CreatesUsers::createUser().
	 *
	 * @since 0.2.0
	 *
	 * @param string $role The role.
	 * @return int The user's id.
	 */
	abstract protected function createUser( string $role ): int;

	/**
	 * Returns a new subscriber who holds the capabilities given, acting in person.
	 *
	 * @since 0.2.0
	 *
	 * @param string ...$capabilities The capabilities, such as `seocart_capture_payments`.
	 * @return Actor The user.
	 */
	protected function userGranted( string ...$capabilities ): Actor {
		$user = $this->createUser( 'subscriber' );

		add_filter(
			'user_has_cap',
			static function ( $caps, $cap, $args ) use ( $user, $capabilities ) {
				if ( (int) ( $args[1] ?? 0 ) === $user ) {
					foreach ( $capabilities as $capability ) {
						$caps[ $capability ] = true;
					}
				}

				return $caps;
			},
			10,
			3
		);

		return Actor::user( $user );
	}
}

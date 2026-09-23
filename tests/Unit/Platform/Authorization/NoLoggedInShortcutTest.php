<?php
/**
 * Tests that the authorization module never asks whether the user is logged in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Authorization;

use PHPUnit\Framework\TestCase;

/**
 * Guards the rule that being logged in exempts nobody from a check.
 *
 * The two documented vulnerabilities behind the order-access design lived in the seam between
 * a logged-in path and a guest path. The authorization path therefore has no such branch: every
 * decision goes through current_user_can(), and a visitor who is not logged in is simply a user
 * with no capabilities. This test reads the module's source for the function name, as a call or
 * as a callback string, until the SEOCart coding standard carries the equivalent sniff that
 * security.md §5 names.
 *
 * Planted violation: add `if ( is_user_logged_in() ) { return true; }` as the first line of
 * PermissionCallback::__invoke(). The failure must name the file and the line.
 *
 * @since 0.1.0
 */
final class NoLoggedInShortcutTest extends TestCase {

	/**
	 * The function that must not appear.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FORBIDDEN = 'is_user_logged_in';

	/**
	 * Tests that no file of the authorization module names the function.
	 *
	 * @since 0.1.0
	 */
	public function test_no_authorization_source_asks_whether_the_user_is_logged_in(): void {
		$directory = dirname( __DIR__, 4 ) . '/src/Platform/Authorization';
		$files     = glob( $directory . '/*.php' );
		$files     = false === $files ? array() : $files;
		$found     = array();

		$this->assertContains( $directory . '/PermissionCallback.php', $files, 'The scan did not find the permission callback, so an empty result would prove nothing.' );

		foreach ( $files as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
					continue;
				}

				if ( 0 === strcasecmp( trim( $token[1], "\\'\"" ), self::FORBIDDEN ) ) {
					$found[] = basename( $file ) . ':' . $token[2];
				}
			}
		}

		$this->assertSame( array(), $found, self::FORBIDDEN . '() is used in the authorization path. Check a capability through current_user_can() instead; a visitor who is not logged in holds none.' );
	}
}

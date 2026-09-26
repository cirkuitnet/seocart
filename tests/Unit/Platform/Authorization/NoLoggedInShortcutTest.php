<?php
/**
 * Tests that the authorization path and the Store API never ask whether the user is logged in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Authorization;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Guards the rule that being logged in exempts nobody from a check.
 *
 * The two documented vulnerabilities behind the order-access design lived in the seam between
 * a logged-in path and a guest path. The authorization path therefore has no such branch: every
 * decision goes through current_user_can(), and a visitor who is not logged in is simply a user
 * with no capabilities. The Store API's HTTP pieces, its request policy included, keep the same
 * rule. This test reads their source for the function name, as a call or as a callback string,
 * until the SEOCart coding standard carries an equivalent sniff.
 *
 * Planted violations, each of which must fail naming the file and the line:
 * - add `if ( is_user_logged_in() ) { return true; }` as the first line of
 *   PermissionCallback::__invoke();
 * - add `if ( is_user_logged_in() ) { return true; }` as the first line of
 *   StoreRequestPolicy::allows().
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
	 * The directories scanned: the authorization module and the Store API's HTTP pieces.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const DIRECTORIES = array( 'src/Platform/Authorization', 'src/Cart/Interfaces' );

	/**
	 * Tests that no file of the authorization path or the Store API names the function.
	 *
	 * @since 0.1.0
	 */
	public function test_no_authorization_source_asks_whether_the_user_is_logged_in(): void {
		$files = array();

		foreach ( self::DIRECTORIES as $directory ) {
			$files += PhpSource::files( $directory );
		}

		$found = array();

		$this->assertArrayHasKey( 'src/Platform/Authorization/PermissionCallback.php', $files, 'The scan did not find the permission callback, so an empty result would prove nothing.' );
		$this->assertArrayHasKey( 'src/Cart/Interfaces/StoreApi/StoreRequestPolicy.php', $files, 'The scan did not find the Store API\'s request policy, so an empty result would prove nothing.' );

		foreach ( $files as $file => $source ) {
			foreach ( token_get_all( $source ) as $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
					continue;
				}

				if ( 0 === strcasecmp( trim( $token[1], "\\'\"" ), self::FORBIDDEN ) ) {
					$found[] = $file . ':' . $token[2];
				}
			}
		}

		$this->assertSame( array(), $found, self::FORBIDDEN . '() is used in the authorization path. Check a capability through current_user_can() instead; a visitor who is not logged in holds none.' );
	}
}

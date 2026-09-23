<?php
/**
 * Tests that the role-name filter hands on values of the wrong type unchanged
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Authorization;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\RoleNames;

/**
 * Proves, without WordPress, that the filter never throws on another filter's bug.
 *
 * Each case returns before any translation is looked up, which is why it runs without
 * WordPress. The integration test of the same name checks the translation itself.
 *
 * @since 0.1.0
 */
final class RoleNamesTest extends TestCase {

	/**
	 * Tests that a value of the wrong type comes back unchanged instead of raising a TypeError.
	 *
	 * Planted violation: in RoleNames::translate(), declare the parameters as `string` again.
	 *
	 * @since 0.1.0
	 */
	public function test_values_of_the_wrong_type_pass_through(): void {
		$this->assertSame( 'kept', RoleNames::translate( 'kept', null, 'User role' ), 'A text that is not a string.' );
		$this->assertNull( RoleNames::translate( null, 'Store manager', 'User role' ), 'A translation that is not a string.' );
		$this->assertSame( array( 'kept' ), RoleNames::translate( array( 'kept' ), 'Store manager', 'User role' ), 'A translation that is an array.' );
		$this->assertSame( 'kept', RoleNames::translate( 'kept', 'Store manager', null ), 'A context that is not a string.' );
	}

	/**
	 * Tests that a text in another context is not treated as a role name.
	 *
	 * @since 0.1.0
	 */
	public function test_another_context_passes_through(): void {
		$this->assertSame( 'kept', RoleNames::translate( 'kept', 'Store manager', 'Menu item' ) );
	}
}

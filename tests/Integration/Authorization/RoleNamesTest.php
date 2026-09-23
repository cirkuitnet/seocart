<?php
/**
 * Tests that the shipped role names are translated where WordPress renders them, and only there
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Authorization;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\RoleNames;
use WP_UnitTestCase;

/**
 * Holds RoleNames to the declaration, and checks the translation through core's own renderer.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class RoleNamesTest extends WP_UnitTestCase {

	/**
	 * Tests that RoleNames translates exactly the declared role names, each through a literal of the same text.
	 *
	 * With no translation loaded, a translation call returns its own literal. A key whose value
	 * differs from it was therefore paired with the wrong literal, which the string extractor
	 * would then offer to translators under the wrong name.
	 *
	 * Planted violation: in RoleNames::translations(), change `_x( 'Reporter', …` to
	 * `_x( 'Reporters', …`; or add a role to CapabilityDeclaration without adding it here.
	 *
	 * @since 0.1.0
	 */
	public function test_every_shipped_role_name_has_exactly_one_translation(): void {
		$declaration = new CapabilityDeclaration();
		$declared    = array();

		foreach ( $declaration->roles() as $role ) {
			if ( $declaration->isShippedRole( $role ) ) {
				$declared[] = (string) $declaration->roleName( $role );
			}
		}

		$translations = RoleNames::translations();

		$this->assertEqualsCanonicalizing( $declared, array_keys( $translations ), 'RoleNames and CapabilityDeclaration name different roles.' );

		foreach ( $translations as $name => $translation ) {
			$this->assertSame( $name, $translation, "The name {$name} is translated through a different literal." );
		}
	}

	/**
	 * Tests that the users screens' renderer, translate_user_role(), shows the plugin's translation.
	 *
	 * @since 0.1.0
	 */
	public function test_core_renders_the_plugins_translation(): void {
		add_filter(
			'gettext_with_context_seocart',
			static function ( string $translation, string $text, string $context ): string {
				return 'User role' === $context ? 'Translated: ' . $text : $translation;
			},
			10,
			3
		);

		$this->assertSame( 'Store manager', translate_user_role( 'Store manager' ), 'Without the filter hooked, core cannot know the plugin\'s domain.' );

		add_filter( 'gettext_with_context_default', array( RoleNames::class, 'translate' ), 10, 3 );

		$this->assertSame( 'Translated: Store manager', translate_user_role( 'Store manager' ) );
		$this->assertSame( 'Administrator', translate_user_role( 'Administrator' ), 'A core role name is core\'s to translate.' );
		$this->assertSame( 'Store manager', RoleNames::translate( 'Store manager', 'Store manager', 'Menu item' ), 'The same text in another context is not a role name.' );
	}
}

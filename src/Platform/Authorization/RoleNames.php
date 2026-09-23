<?php
/**
 * RoleNames: translates the display names of the shipped roles where WordPress renders them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the translations of the shipped role names at render time.
 *
 * A role's display name is stored untranslated, as core stores its own, and every screen that
 * shows it passes it through translate_user_role(), in core's text domain and the `User role`
 * context. The kernel hooks translate() to that domain's filter on the screens that list roles:
 *
 *     add_filter( 'gettext_with_context_default', array( RoleNames::class, 'translate' ), 10, 3 );
 *
 * The translation calls must spell each name as a literal, or the string extractor would not
 * find it. That makes the names below a second list next to CapabilityDeclaration's, so a
 * contract test holds the two to the same set.
 *
 * @since 0.1.0
 */
final class RoleNames {

	/**
	 * The gettext context core renders role names in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CONTEXT = 'User role';

	/**
	 * Filters `gettext_with_context_default`: translates a shipped role's name in the plugin's domain.
	 *
	 * @since 0.1.0
	 *
	 * @param string $translation What core's text domain made of the text.
	 * @param string $text        The text being translated.
	 * @param string $context     The gettext context.
	 * @return string The plugin's translation for one of its role names; otherwise the translation unchanged.
	 */
	public static function translate( string $translation, string $text, string $context ): string {
		if ( self::CONTEXT !== $context ) {
			return $translation;
		}

		return self::translations()[ $text ] ?? $translation;
	}

	/**
	 * Returns the translation of every shipped role name.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Translations, keyed by the untranslated display name.
	 */
	public static function translations(): array {
		return array(
			'Store manager'  => _x( 'Store manager', 'User role', 'seocart' ),
			'Order agent'    => _x( 'Order agent', 'User role', 'seocart' ),
			'Catalog editor' => _x( 'Catalog editor', 'User role', 'seocart' ),
			'Reporter'       => _x( 'Reporter', 'User role', 'seocart' ),
			'Store customer' => _x( 'Store customer', 'User role', 'seocart' ),
		);
	}
}

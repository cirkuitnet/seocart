<?php
/**
 * SiteLocale: every post is in the site's language
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Localization;

use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * The locale of every post on a store in one language: the site's locale.
 *
 * Owns one fact: that without a multilingual plugin a post's content is in the site's language.
 * That is get_locale(), the site's setting, and never the language of the user who is looking:
 * an administrator whose profile says German still writes the products of an English store. The
 * answer is kept per site for the rest of the request.
 *
 * Inside switch_to_blog(), get_locale() still answers the first site's language, which it keeps
 * for the request; there the switched site's own setting is read instead: its WPLANG option, the
 * network's when the site has none, and en_US when neither says.
 *
 * @since 0.1.0
 */
final class SiteLocale implements PostLocales {

	/**
	 * The locale of each site asked about so far, keyed by site id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, Locale>
	 */
	private array $locales = array();

	/**
	 * Returns the site's locale, whatever the post.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the site's locale is not a WordPress locale.
	 *
	 * @param int $postId The post's id. Every post has the site's locale.
	 * @return Locale The site's locale.
	 */
	public function localeOf( int $postId ): Locale {
		$site = get_current_blog_id();

		return $this->locales[ $site ] ??= Locale::of( self::siteSetting() );
	}

	/**
	 * Returns the current site's locale setting, also inside switch_to_blog().
	 *
	 * @since 0.1.0
	 *
	 * @return string The WordPress locale, such as en_US.
	 */
	private static function siteSetting(): string {
		if ( ! is_multisite() || ! ms_is_switched() ) {
			return get_locale();
		}

		foreach ( array( get_option( 'WPLANG' ), get_site_option( 'WPLANG' ) ) as $setting ) {
			if ( is_string( $setting ) && '' !== $setting ) {
				return $setting;
			}
		}

		return 'en_US';
	}
}

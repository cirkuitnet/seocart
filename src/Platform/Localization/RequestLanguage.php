<?php
/**
 * RequestLanguage: the language WordPress translates this request's words in
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Localization;

use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * The language WordPress translates the current request's words in.
 *
 * Owns one fact: where the plugin asks for the request's language, which is not the site's content
 * locale (PostLocales::siteLocale()). WordPress settles it in determine_locale(): a signed-in user's
 * profile language in the admin or on a JSON request that asks for it with `_locale=user`, what a
 * `pre_determine_locale` or `determine_locale` filter answers, and otherwise the site's locale as
 * the `locale` filter leaves it. Every text domain, an extension's included, is loaded in it, so it
 * is the language a label resolved in this request is written in. Nothing else in the plugin calls
 * determine_locale().
 *
 * @since 0.2.0
 */
final class RequestLanguage {

	/**
	 * Returns the locale WordPress translates this request's words in.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When WordPress names no locale the plugin can keep: one outside a
	 *                                   WordPress locale's form, or longer than Locale::MAX_LENGTH.
	 *
	 * @return Locale The locale.
	 */
	public function locale(): Locale {
		return Locale::of( determine_locale() );
	}
}

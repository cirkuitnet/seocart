<?php
/**
 * PostLocales: which locale a post's content is written in
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
 * Tells the locale of a post, as the site's multilingual setup sees it.
 *
 * Owns one fact: where the plugin learns a post's locale, so no module asks a multilingual
 * plugin, or WordPress, itself. A store in one language answers with the site locale for every
 * post (SiteLocale); a multilingual plugin's adapter answers with the language it assigned.
 * Binding a post to a product records this locale.
 *
 * @since 0.1.0
 */
interface PostLocales {

	/**
	 * Returns the locale of a post's content.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return Locale The WordPress locale, such as en_US.
	 */
	public function localeOf( int $postId ): Locale;
}

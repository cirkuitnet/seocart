<?php
/**
 * PostLocales: which locale a post's content is written in, and which posts translate it
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
 * Tells the locale of a post and the posts that translate it, as the site's multilingual setup sees them, and passes on what that setup changes.
 *
 * Owns one fact: where the plugin learns a post's language and translations, so no module asks a
 * multilingual plugin, or WordPress, itself. A store in one language answers with the site
 * locale for every post and no translations (SiteLocale); a multilingual plugin's adapter
 * answers with the language it assigned and the translation group it keeps, and gives a post the
 * language and group the plugin's own REST fields ask for. Binding a post to a product records
 * its locale; the posts of one translation group present one product.
 *
 * @since 0.1.0
 */
interface PostLocales {

	/**
	 * Returns the locale of a post's content, as the multilingual setup records it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return Locale|null The WordPress locale, such as en_US; null when the setup records none for the post, as a
	 *                     multilingual plugin does for a post of a type it does not translate.
	 */
	public function localeOf( int $postId ): ?Locale;

	/**
	 * Returns the locale a post is bound in when the multilingual setup records none for it: the site's.
	 *
	 * @since 0.1.0
	 *
	 * @return Locale The site's WordPress locale.
	 */
	public function siteLocale(): Locale;

	/**
	 * Returns the posts of a post's translation group with their locales: the posts that present the same content in each language, itself included.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return array<int, Locale> The locale of each post, by post id; only the post itself when it has no translation, and
	 *                            nothing when the setup records no locale for the post.
	 */
	public function translationsOf( int $postId ): array;

	/**
	 * Tells whether the setup keeps a translation group for a post at all.
	 *
	 * False for a store in one language (SiteLocale), whatever `languages()` names: a store may
	 * publish in one language and still keep no group for any post, since there is nothing to
	 * group a post with. True for a multilingual plugin's adapter, even on a site that currently
	 * publishes in one language: a binding's stored locale can still disagree with the language
	 * the plugin gives the post, and doctor's translation-group check must still catch that.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when translationsOf() answers a real group, not just a post alone.
	 */
	public function translatesPosts(): bool;

	/**
	 * Tells whether the site publishes in a locale.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale The locale.
	 * @return bool True when posts may be written in it.
	 */
	public function publishesIn( Locale $locale ): bool;

	/**
	 * Returns the languages the site publishes in, each by the code the multilingual plugin names it with in its own links.
	 *
	 * The product editor reads the language of a new translation from the code in the multilingual
	 * plugin's "add translation" link, and turns it into a locale with this.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Locale> The locale of each language, by its code.
	 */
	public function languages(): array;

	/**
	 * Gives a post a locale and, when another post is named, makes it that post's translation in the locale.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $postId        The post.
	 * @param Locale   $locale        Its locale, one the site publishes in.
	 * @param int|null $translationOf The post whose translation group it joins, or null.
	 */
	public function assign( int $postId, Locale $locale, ?int $translationOf ): void;

	/**
	 * Tells a watcher, for the rest of the request, when a post's language or translation group changes other than through assign().
	 *
	 * The multilingual plugin may change them after WordPress has fired its post hooks, or without
	 * writing the post at all. Watching adds nothing where there is nothing to watch.
	 *
	 * @since 0.1.0
	 *
	 * @param TranslationWatcher $watcher The watcher.
	 */
	public function watch( TranslationWatcher $watcher ): void;
}

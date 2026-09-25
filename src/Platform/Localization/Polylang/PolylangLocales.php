<?php
/**
 * PolylangLocales: posts' languages and translation groups as Polylang keeps them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Localization\Polylang;

use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Platform\Localization\SiteLocale;
use SEOCart\Platform\Localization\TranslationWatcher;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes posts' languages and translation groups through Polylang's public functions, and reports the changes Polylang makes on its own.
 *
 * Owns one fact: how Polylang's languages map onto the plugin's locales. Polylang names a
 * language by a slug, such as `de`, and records the WordPress locale it stands for, such as
 * de_DE; the plugin works in locales only, so every slug is turned into its locale here, and
 * back. A post Polylang gives no language, as it gives none to a post of a type it does not
 * translate, has no locale here and no translation group: the catalog then leaves its binding as
 * it is, and binds a new one in the site's locale, never in Polylang's default language.
 *
 * Polylang records a post's language and group after WordPress has fired the post's hooks when a
 * plugin calls pll_insert_post(), and without writing the post at all when one calls
 * pll_save_post_translations() or pll_set_post_language(). Once watch() is called, which the
 * post lifecycle does when a product post changes, a listener on `set_object_terms` hears those
 * changes for the rest of the request: it returns at once for any taxonomy but Polylang's two,
 * and for anything but a product post, and tells the watcher which post changed. Changes made
 * through assign() are the plugin's own and are not reported. Nothing is hooked before watch(),
 * so an idle request pays nothing for this class.
 *
 * Polylang's own REST fields for a post's language and translations belong to its paid edition;
 * the plugin's product endpoint offers its own, which reach Polylang through assign().
 *
 * @since 0.1.0
 */
final class PolylangLocales implements PostLocales {

	/**
	 * The taxonomy Polylang records a post's language in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LANGUAGE_TAXONOMY = 'language';

	/**
	 * The taxonomy Polylang records a post's translation group in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const GROUP_TAXONOMY = 'post_translations';

	/**
	 * The watcher told of Polylang's own changes, once watch() was called.
	 *
	 * @since 0.1.0
	 *
	 * @var TranslationWatcher|null
	 */
	private ?TranslationWatcher $watcher = null;

	/**
	 * Whether assign() is writing, so the changes it makes are not reported.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $assigning = false;

	/**
	 * The site's one locale, the binding of a post Polylang gives no language.
	 *
	 * @since 0.1.0
	 *
	 * @var SiteLocale|null
	 */
	private ?SiteLocale $site = null;

	/**
	 * Returns the locale of a post's language, or null when Polylang gives the post none.
	 *
	 * Polylang gives no language to a post of a type it does not translate, which the product post
	 * type is until the merchant turns its translation on. Such a post is not in Polylang's default
	 * language: that one is Polylang's choice for the site's pages, and may change at any time.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When Polylang records no WordPress locale for the language.
	 *
	 * @param int $postId The post's id.
	 * @return Locale|null The locale, or null.
	 */
	public function localeOf( int $postId ): ?Locale {
		$locale = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $postId, 'locale' ) : false;

		return is_string( $locale ) && '' !== $locale ? Locale::of( $locale ) : null;
	}

	/**
	 * Returns the site's locale, as the site's language setting gives it.
	 *
	 * @since 0.1.0
	 *
	 * @return Locale The locale.
	 */
	public function siteLocale(): Locale {
		$this->site ??= new SiteLocale();

		return $this->site->siteLocale();
	}

	/**
	 * Returns the posts of a post's translation group, with their locales, the post itself included.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return array<int, Locale> The locale of each post, by post id; nothing when Polylang gives the post no language.
	 */
	public function translationsOf( int $postId ): array {
		$own = $this->localeOf( $postId );

		if ( null === $own ) {
			return array();
		}

		$group   = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $postId ) : array();
		$locales = $this->languages();
		$posts   = array( $postId => $own );

		foreach ( is_array( $group ) ? $group : array() as $slug => $member ) {
			$member = (int) $member;

			if ( $member > 0 && $member !== $postId && isset( $locales[ (string) $slug ] ) ) {
				$posts[ $member ] = $locales[ (string) $slug ];
			}
		}

		ksort( $posts );

		return $posts;
	}

	/**
	 * Tells that Polylang keeps a translation group for every post it gives a language, whatever the site's current languages.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Always true.
	 */
	public function translatesPosts(): bool {
		return true;
	}

	/**
	 * Tells whether a locale is one of Polylang's languages.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale The locale.
	 * @return bool True when a language stands for it.
	 */
	public function publishesIn( Locale $locale ): bool {
		return null !== $this->slugOf( $locale );
	}

	/**
	 * Gives a post the language that stands for a locale and, when another post is named, adds it to that post's translation group.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $postId        The post.
	 * @param Locale   $locale        Its locale, one of Polylang's languages.
	 * @param int|null $translationOf The post whose group it joins, or null.
	 */
	public function assign( int $postId, Locale $locale, ?int $translationOf ): void {
		$slug = $this->slugOf( $locale );

		if ( null === $slug || ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) || ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_get_post_language' ) ) {
			return;
		}

		$this->assigning = true;

		try {
			pll_set_post_language( $postId, $slug );

			if ( null === $translationOf ) {
				return;
			}

			$group    = (array) pll_get_post_translations( $translationOf );
			$language = pll_get_post_language( $translationOf, 'slug' );

			if ( is_string( $language ) && '' !== $language ) {
				$group[ $language ] = $translationOf;
			}

			$group[ $slug ] = $postId;

			pll_save_post_translations( array_map( 'intval', $group ) );
		} finally {
			$this->assigning = false;
		}
	}

	/**
	 * Starts telling a watcher of the changes Polylang makes to product posts' languages and groups, for the rest of the request.
	 *
	 * The listener is added once, however often this is called; the latest watcher is told.
	 *
	 * @since 0.1.0
	 *
	 * @param TranslationWatcher $watcher The watcher.
	 */
	public function watch( TranslationWatcher $watcher ): void {
		$added         = null !== $this->watcher;
		$this->watcher = $watcher;

		if ( $added ) {
			return;
		}

		add_action(
			'set_object_terms',
			function ( $objectId, $terms, $termTaxonomyIds, $taxonomy ): void {
				if ( self::LANGUAGE_TAXONOMY !== $taxonomy && self::GROUP_TAXONOMY !== $taxonomy ) {
					return;
				}

				if ( $this->assigning || null === $this->watcher || ProductCapabilities::POST_TYPE !== get_post_type( (int) $objectId ) ) {
					return;
				}

				$this->watcher->translationsChanged( (int) $objectId );
			},
			10,
			4
		);
	}

	/**
	 * Returns Polylang's languages: the locale each slug stands for, the slug being the code Polylang's links name a language with.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Locale> The locales, by slug.
	 */
	public function languages(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}

		$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
		$mapped  = array();

		foreach ( $slugs as $index => $slug ) {
			$locale = $locales[ $index ] ?? null;

			if ( is_string( $slug ) && is_string( $locale ) && '' !== $locale ) {
				$mapped[ $slug ] = Locale::of( $locale );
			}
		}

		return $mapped;
	}

	/**
	 * Returns the slug of the language that stands for a locale.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale The locale.
	 * @return string|null The slug, or null when no language stands for it.
	 */
	private function slugOf( Locale $locale ): ?string {
		foreach ( $this->languages() as $slug => $candidate ) {
			if ( $candidate->equals( $locale ) ) {
				return $slug;
			}
		}

		return null;
	}
}

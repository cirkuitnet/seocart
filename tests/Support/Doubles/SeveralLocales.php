<?php
/**
 * SeveralLocales: the locale port of a store in several languages, kept in memory
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Platform\Localization\TranslationWatcher;
use SEOCart\Support\Locale;

/**
 * Keeps posts' languages and translation groups in memory, as a multilingual plugin keeps them, so the catalog's multilingual rules can be tested without one.
 *
 * Owns one fact: what an integration test of the catalog sees of a multilingual setup. A post has
 * the default locale until it is given another; a group is a set of posts, one per locale.
 * assign() changes both as a multilingual plugin's API does, and change() does what the plugin
 * does on its own, telling the watcher as a plugin's hooks would. The conformance suite runs the
 * same rules against a real multilingual plugin.
 *
 * @since 0.1.0
 */
final class SeveralLocales implements PostLocales {

	/**
	 * The locales published in, the default first.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Locale>
	 */
	private array $published;

	/**
	 * Each post's locale, when it was given one.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, Locale>
	 */
	private array $locales = array();

	/**
	 * Each post's group: the posts of it by locale, shared by every member.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array<string, int>>
	 */
	private array $groups = array();

	/**
	 * The posts the setup currently gives no language, as a real multilingual plugin can leave one
	 * once it had a language: forgetLanguage() puts a post here.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, true>
	 */
	private array $noLanguage = array();

	/**
	 * The watcher, once one watches.
	 *
	 * @since 0.1.0
	 *
	 * @var TranslationWatcher|null
	 */
	private ?TranslationWatcher $watcher = null;

	/**
	 * What assign() was told, in order: the post, the locale and the post it translates.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{0: int, 1: string, 2: int|null}>
	 */
	public array $assigned = array();

	/**
	 * Creates the setup.
	 *
	 * @since 0.1.0
	 *
	 * @param string ...$locales The locales published in, the default first. Default en_US, en_GB and de_DE.
	 */
	public function __construct( string ...$locales ) {
		$this->published = array_map( array( Locale::class, 'of' ), array() === $locales ? array( 'en_US', 'en_GB', 'de_DE' ) : $locales );
	}

	/**
	 * Returns the post's locale, or the default one; null once forgetLanguage() has been called for it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return Locale|null The locale, or null.
	 */
	public function localeOf( int $postId ): ?Locale {
		if ( isset( $this->noLanguage[ $postId ] ) ) {
			return null;
		}

		return $this->locales[ $postId ] ?? $this->published[0];
	}

	/**
	 * Returns the default locale, the site's.
	 *
	 * @since 0.1.0
	 *
	 * @return Locale The locale.
	 */
	public function siteLocale(): Locale {
		return $this->published[0];
	}

	/**
	 * Returns the post's group, with each member's locale.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return array<int, Locale> The locales, by post.
	 */
	public function translationsOf( int $postId ): array {
		$own = $this->localeOf( $postId );

		if ( null === $own ) {
			return array();
		}

		$group = array( $postId => $own );

		foreach ( $this->groups[ $postId ] ?? array() as $member ) {
			$memberLocale = $this->localeOf( $member );

			if ( null !== $memberLocale ) {
				$group[ $member ] = $memberLocale;
			}
		}

		ksort( $group );

		return $group;
	}

	/**
	 * Tells that this double, like a multilingual plugin's adapter, keeps a translation group for every post, however many languages are published in.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Always true.
	 */
	public function translatesPosts(): bool {
		return true;
	}

	/**
	 * Tells whether the locale is one published in.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale The locale.
	 * @return bool True when it is.
	 */
	public function publishesIn( Locale $locale ): bool {
		foreach ( $this->published as $published ) {
			if ( $published->equals( $locale ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the languages published in, each named by its language code, the first part of its locale, or by its locale when two share a code.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Locale> The locales, by code.
	 */
	public function languages(): array {
		$languages = array();

		foreach ( $this->published as $locale ) {
			$code = strtok( $locale->toString(), '_' );

			$languages[ isset( $languages[ $code ] ) ? strtolower( str_replace( '_', '-', $locale->toString() ) ) : $code ] = $locale;
		}

		return $languages;
	}

	/**
	 * Gives the post the locale and, when another post is named, puts it in that post's group; records the call.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $postId        The post.
	 * @param Locale   $locale        The locale.
	 * @param int|null $translationOf The post whose group it joins, or null.
	 */
	public function assign( int $postId, Locale $locale, ?int $translationOf ): void {
		$this->assigned[] = array( $postId, $locale->toString(), $translationOf );

		$this->place( $postId, $locale, $translationOf );
	}

	/**
	 * Takes the language a post had away, as a multilingual plugin can leave a translation once
	 * its own language is deleted: it keeps no group, and localeOf() answers null for it, without
	 * telling the watcher.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 */
	public function forgetLanguage( int $postId ): void {
		foreach ( $this->groups[ $postId ] ?? array() as $member ) {
			if ( $member !== $postId ) {
				$this->groups[ $member ] = array_values( array_diff( $this->groups[ $member ] ?? array(), array( $postId ) ) );
			}
		}

		unset( $this->groups[ $postId ], $this->locales[ $postId ] );

		$this->noLanguage[ $postId ] = true;
	}

	/**
	 * Keeps the watcher.
	 *
	 * @since 0.1.0
	 *
	 * @param TranslationWatcher $watcher The watcher.
	 */
	public function watch( TranslationWatcher $watcher ): void {
		$this->watcher = $watcher;
	}

	/**
	 * Does what a multilingual plugin does on its own: gives a post a locale and a group, then tells the watcher, when one watches.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $postId        The post.
	 * @param string   $locale        Its locale.
	 * @param int|null $translationOf The post whose group it joins, or null to leave its group.
	 */
	public function change( int $postId, string $locale, ?int $translationOf ): void {
		$this->place( $postId, Locale::of( $locale ), $translationOf );

		$this->watcher?->translationsChanged( $postId );
	}

	/**
	 * Gives a post a locale and a group.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $postId        The post.
	 * @param Locale   $locale        Its locale.
	 * @param int|null $translationOf The post whose group it joins, or null to leave its group.
	 */
	private function place( int $postId, Locale $locale, ?int $translationOf ): void {
		$this->locales[ $postId ] = $locale;

		foreach ( $this->groups[ $postId ] ?? array() as $member ) {
			if ( $member !== $postId ) {
				$this->groups[ $member ] = array_values( array_diff( $this->groups[ $member ] ?? array(), array( $postId ) ) );
			}
		}

		unset( $this->groups[ $postId ] );

		if ( null === $translationOf ) {
			return;
		}

		$members = array_values( array_unique( array_merge( $this->groups[ $translationOf ] ?? array( $translationOf ), array( $translationOf, $postId ) ) ) );

		foreach ( $members as $member ) {
			$this->groups[ $member ] = $members;
		}
	}
}

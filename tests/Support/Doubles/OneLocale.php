<?php
/**
 * OneLocale: the locale port of a store in one language, which records what it is told
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
 * Answers as a store in one language does, without WordPress, and records every assign() and watch().
 *
 * Owns one fact: what a unit test sees of the locale port. Every post is in the one locale, has
 * no translation, and only that locale is published in.
 *
 * @since 0.1.0
 */
final class OneLocale implements PostLocales {

	/**
	 * The store's locale.
	 *
	 * @since 0.1.0
	 *
	 * @var Locale
	 */
	private Locale $locale;

	/**
	 * What assign() was told, in order: the post, the locale and the post it translates.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{0: int, 1: string, 2: int|null}>
	 */
	public array $assigned = array();

	/**
	 * How often watch() was called.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $watched = 0;

	/**
	 * Creates the port for one locale.
	 *
	 * @since 0.1.0
	 *
	 * @param string $locale Optional. The store's locale. Default en_US.
	 */
	public function __construct( string $locale = 'en_US' ) {
		$this->locale = Locale::of( $locale );
	}

	/**
	 * Returns the store's locale.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId Unused.
	 * @return Locale The locale.
	 */
	public function localeOf( int $postId ): Locale {
		return $this->locale;
	}

	/**
	 * Returns the store's locale.
	 *
	 * @since 0.1.0
	 *
	 * @return Locale The locale.
	 */
	public function siteLocale(): Locale {
		return $this->locale;
	}

	/**
	 * Returns the post alone, in the store's locale.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return array<int, Locale> The post.
	 */
	public function translationsOf( int $postId ): array {
		return array( $postId => $this->locale );
	}

	/**
	 * Tells whether a locale is the store's.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale The locale.
	 * @return bool True for the store's locale.
	 */
	public function publishesIn( Locale $locale ): bool {
		return $locale->equals( $this->locale );
	}

	/**
	 * Returns the store's one language, named by its locale.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Locale> The locale, by itself.
	 */
	public function languages(): array {
		return array( $this->locale->toString() => $this->locale );
	}

	/**
	 * Records what it is told.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $postId        The post.
	 * @param Locale   $locale        The locale.
	 * @param int|null $translationOf The post it translates, or null.
	 */
	public function assign( int $postId, Locale $locale, ?int $translationOf ): void {
		$this->assigned[] = array( $postId, $locale->toString(), $translationOf );
	}

	/**
	 * Counts the call; nothing ever changes.
	 *
	 * @since 0.1.0
	 *
	 * @param TranslationWatcher $watcher Unused.
	 */
	public function watch( TranslationWatcher $watcher ): void {
		++$this->watched;
	}
}

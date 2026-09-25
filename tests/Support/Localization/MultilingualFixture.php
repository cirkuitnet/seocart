<?php
/**
 * MultilingualFixture: what the multilingual conformance suite asks of a multilingual plugin
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Localization;

use SEOCart\Platform\Localization\PostLocales;

/**
 * Drives one multilingual plugin the way its own screens and API do, so the conformance suite can run unchanged against any of them.
 *
 * Owns one fact: the vocabulary between the conformance suite and a multilingual plugin. The
 * suite's rows call only this and the plugin under test; everything a particular multilingual
 * plugin names differently, its language codes, its functions and its settings, stays in the
 * implementation. Languages are named by WordPress locale everywhere here. The site has three:
 * en_US, the default, en_GB and de_DE.
 *
 * @since 0.1.0
 */
interface MultilingualFixture {

	/**
	 * Gives the site the three languages and lets the plugin translate product posts, once per process.
	 *
	 * @since 0.1.0
	 */
	public function boot(): void;

	/**
	 * Returns the locale port under test, as the kernel chooses it while the plugin is active.
	 *
	 * @since 0.1.0
	 *
	 * @return PostLocales The adapter.
	 */
	public function adapter(): PostLocales;

	/**
	 * Writes a product post in a language through the multilingual plugin, as its editor or another plugin would, and returns it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $locale The language.
	 * @param string $title  The title.
	 * @param string $status The status.
	 * @return int The post.
	 */
	public function write( string $locale, string $title, string $status ): int;

	/**
	 * Makes a post a translation of another: puts it in the other post's translation group.
	 *
	 * @since 0.1.0
	 *
	 * @param int $original The post it translates.
	 * @param int $postId   The post.
	 */
	public function link( int $original, int $postId ): void;

	/**
	 * Takes a post out of its translation group, leaving the group's other posts together.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 */
	public function unlink( int $postId ): void;

	/**
	 * Returns the language the multilingual plugin gives a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return string|null Its locale, or null when it has none.
	 */
	public function languageOf( int $postId ): ?string;

	/**
	 * Returns a post's translation group as the multilingual plugin keeps it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return array<string, int> The posts of the group, the post itself included, by locale.
	 */
	public function groupOf( int $postId ): array;

	/**
	 * Makes a language the site's default one.
	 *
	 * @since 0.1.0
	 *
	 * @param string $locale The language.
	 */
	public function setDefaultLanguage( string $locale ): void;

	/**
	 * Turns the plugin's translation of product posts on or off, as its settings screen does; boot() turns it on.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $translated Whether product posts are translated.
	 */
	public function translateProducts( bool $translated ): void;

	/**
	 * Turns on the plugin's copying of custom fields between the posts of a translation group.
	 *
	 * @since 0.1.0
	 */
	public function copyCustomFields(): void;

	/**
	 * Deletes a post for good, as WordPress deletes it with the multilingual plugin active.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 */
	public function delete( int $postId ): void;
}

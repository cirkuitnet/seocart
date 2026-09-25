<?php
/**
 * PolylangFixture: the multilingual conformance suite's fixture for Polylang
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Localization;

use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Localization\Polylang\PolylangLocales;
use SEOCart\Platform\Localization\PostLocales;

/**
 * Drives Polylang through its own classes and public functions: the languages of the test site, a post in a language, a translation group, the default language and the copying of custom fields.
 *
 * Owns one fact: how the multilingual conformance suite gets a real Polylang, and speaks to it.
 * The integration bootstrap loads Polylang's main file when SEOCART_ML_ADAPTER is `polylang` and
 * SEOCART_ML_PLUGIN names that file, as WordPress loads an active plugin. On a site with no
 * language Polylang then sets nothing up, since it sets up its post handling only when languages
 * exist. boot() creates the three languages of a disposable instance through Polylang's own
 * model, en_US first as the default, makes the product post type one Polylang translates, and
 * runs Polylang's own initialisation again in its admin context, as an editor's request finds
 * it. Nothing of Polylang's storage is written here directly. Polylang's classes and functions
 * are named as strings, since the plugin's static analysis does not know them.
 *
 * WordPress's test framework empties the terms table after each test class, which removes the
 * languages; the conformance suite is therefore one class, and boot() runs once.
 *
 * @since 0.1.0
 */
final class PolylangFixture implements MultilingualFixture {

	/**
	 * The languages, the default first: the locale, Polylang's slug, and a name, as the disposable instances have them.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{locale: string, slug: string, name: string}>
	 */
	public const LANGUAGES = array(
		array(
			'locale' => 'en_US',
			'slug'   => 'en',
			'name'   => 'English (US)',
		),
		array(
			'locale' => 'en_GB',
			'slug'   => 'en-gb',
			'name'   => 'English (UK)',
		),
		array(
			'locale' => 'de_DE',
			'slug'   => 'de',
			'name'   => 'Deutsch',
		),
	);

	/**
	 * Whether boot() has run in this process.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Tells whether Polylang was loaded for this run.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when its main file was loaded.
	 */
	public static function loaded(): bool {
		return defined( 'POLYLANG_VERSION' ) && class_exists( 'Polylang' );
	}

	/**
	 * Creates the languages when the site has none, lets Polylang translate product posts, and boots Polylang with them.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When Polylang is not loaded, or refuses a language.
	 */
	public function boot(): void {
		if ( self::$booted ) {
			return;
		}

		if ( ! self::loaded() ) {
			throw new \RuntimeException( 'Polylang is not loaded: set SEOCART_ML_ADAPTER=polylang and SEOCART_ML_PLUGIN to the path of polylang.php.' );
		}

		$model = self::make( 'PLL_Admin_Model', self::make( 'WP_Syntex\Polylang\Options\Options' ) );

		// As Polylang's admin context does once it is set up: the model may list its languages from here on.
		$model->languages->set_ready();

		if ( ! $model->has_languages() ) {
			foreach ( self::LANGUAGES as $language ) {
				$added = $model->languages->add( $language + array( 'no_default_cat' => true ) );

				if ( is_wp_error( $added ) && $added->has_errors() ) {
					throw new \RuntimeException( sprintf( 'Polylang refused the language %s: %s', $language['locale'], $added->get_error_message() ) );
				}
			}
		}

		$model->options['post_types'] = array( ProductCapabilities::POST_TYPE );
		$model->options->save();

		add_filter( 'pll_context', static fn(): string => 'PLL_Admin' );

		self::make( 'Polylang' )->init();

		self::$booted = true;
	}

	/**
	 * Returns the Polylang adapter.
	 *
	 * @since 0.1.0
	 *
	 * @return PostLocales The adapter.
	 */
	public function adapter(): PostLocales {
		return new PolylangLocales();
	}

	/**
	 * Writes a product post in a language with pll_insert_post(), as another plugin would.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When Polylang refuses the post.
	 *
	 * @param string $locale The language.
	 * @param string $title  The title.
	 * @param string $status The status.
	 * @return int The post.
	 */
	public function write( string $locale, string $title, string $status ): int {
		$postId = (int) self::call(
			'pll_insert_post',
			array(
				'post_type'   => ProductCapabilities::POST_TYPE,
				'post_title'  => $title,
				'post_status' => $status,
			),
			self::slug( $locale )
		);

		if ( $postId < 1 ) {
			throw new \RuntimeException( sprintf( 'Polylang did not write the post in %s.', $locale ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer's message in a test.
		}

		return $postId;
	}

	/**
	 * Puts a post in another post's group with pll_save_post_translations().
	 *
	 * @since 0.1.0
	 *
	 * @param int $original The post it translates.
	 * @param int $postId   The post.
	 */
	public function link( int $original, int $postId ): void {
		$group = (array) self::call( 'pll_get_post_translations', $original );

		$group[ (string) self::call( 'pll_get_post_language', $postId, 'slug' ) ] = $postId;

		self::call( 'pll_save_post_translations', array_map( 'intval', $group ) );
	}

	/**
	 * Saves the group of a post's other posts without it, with pll_save_post_translations(), which takes the post out of it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 */
	public function unlink( int $postId ): void {
		$group = array_filter( array_map( 'intval', (array) self::call( 'pll_get_post_translations', $postId ) ), static fn( int $member ): bool => $member !== $postId );

		if ( array() !== $group ) {
			self::call( 'pll_save_post_translations', $group );
		}
	}

	/**
	 * Returns the locale of the language Polylang gives a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return string|null The locale, or null when the post has no language.
	 */
	public function languageOf( int $postId ): ?string {
		$locale = self::call( 'pll_get_post_language', $postId, 'locale' );

		return is_string( $locale ) && '' !== $locale ? $locale : null;
	}

	/**
	 * Returns a post's group as Polylang keeps it, by locale.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return array<string, int> The posts, by locale.
	 */
	public function groupOf( int $postId ): array {
		$group = array();

		foreach ( (array) self::call( 'pll_get_post_translations', $postId ) as $slug => $member ) {
			$group[ self::locale( (string) $slug ) ] = (int) $member;
		}

		ksort( $group );

		return $group;
	}

	/**
	 * Makes a language Polylang's default one, through its language model.
	 *
	 * @since 0.1.0
	 *
	 * @param string $locale The language.
	 */
	public function setDefaultLanguage( string $locale ): void {
		self::call( 'PLL' )->model->languages->update_default( self::slug( $locale ) );
	}

	/**
	 * Sets whether Polylang translates product posts, and empties Polylang's cache of the translated post types.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $translated Whether product posts are translated.
	 */
	public function translateProducts( bool $translated ): void {
		$polylang = self::call( 'PLL' );

		$polylang->options['post_types'] = $translated ? array( ProductCapabilities::POST_TYPE ) : array();
		$polylang->model->cache->clean();
	}

	/**
	 * Turns on Polylang's synchronisation of custom fields and taxonomies between translations.
	 *
	 * @since 0.1.0
	 */
	public function copyCustomFields(): void {
		self::call( 'PLL' )->options['sync'] = array( 'post_meta', 'taxonomies' );
	}

	/**
	 * Deletes a post for good with wp_delete_post(), which Polylang hears.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 */
	public function delete( int $postId ): void {
		wp_delete_post( $postId, true );
	}

	/**
	 * Returns the slug of the Polylang language that stands for a locale.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When no language of the site stands for it.
	 *
	 * @param string $locale The locale.
	 * @return string The slug.
	 */
	private static function slug( string $locale ): string {
		foreach ( self::LANGUAGES as $language ) {
			if ( $locale === $language['locale'] ) {
				return $language['slug'];
			}
		}

		throw new \InvalidArgumentException( sprintf( 'The test site has no language for %s.', $locale ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer's message in a test.
	}

	/**
	 * Returns the locale a Polylang slug stands for on the test site.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When no language of the site has the slug.
	 *
	 * @param string $slug The slug.
	 * @return string The locale.
	 */
	private static function locale( string $slug ): string {
		foreach ( self::LANGUAGES as $language ) {
			if ( $slug === $language['slug'] ) {
				return $language['locale'];
			}
		}

		throw new \InvalidArgumentException( sprintf( 'The test site has no language with the slug %s.', $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer's message in a test.
	}

	/**
	 * Calls one of Polylang's public functions.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When Polylang did not define the function.
	 *
	 * @param string $name    The function, such as pll_save_post_translations.
	 * @param mixed  ...$args Its arguments.
	 * @return mixed What it returns.
	 */
	private static function call( string $name, mixed ...$args ): mixed {
		if ( ! function_exists( $name ) ) {
			throw new \LogicException( sprintf( 'Polylang did not define %s(); boot() the site first.', $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer's message in a test.
		}

		return $name( ...$args );
	}

	/**
	 * Builds an object of one of Polylang's classes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    The class.
	 * @param mixed  ...$args Its constructor's arguments.
	 * @return mixed The object.
	 */
	private static function make( string $name, mixed ...$args ): mixed {
		return new $name( ...$args );
	}
}

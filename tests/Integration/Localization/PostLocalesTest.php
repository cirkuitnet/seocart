<?php
/**
 * Tests SiteLocale: without a multilingual plugin, every post is in the site's language
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Localization;

use SEOCart\Platform\Localization\SiteLocale;
use WP_UnitTestCase;

/**
 * A post's locale is the site's, never the language of the user who is looking: an administrator
 * whose profile says German, working in the admin of an English site, still gets `en_US`. The
 * answer is the site's setting, as get_locale() gives it, kept for the rest of the request.
 *
 * Inside switch_to_blog() the answer is the switched site's own setting, although get_locale()
 * there still answers the first site's.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In SiteLocale::siteSetting(), return determine_locale() instead of get_locale(): the
 *   administrator's `de_DE` comes back, and test_the_locale_is_the_sites_whatever_the_users_language fails.
 * - In SiteLocale::siteSetting(), drop the switched-site branch (always return get_locale()): on
 *   a network, test_a_switched_site_has_its_own_locale gets the first site's `en_US` and fails.
 *
 * @since 0.1.0
 */
final class PostLocalesTest extends WP_UnitTestCase {

	/**
	 * Puts the request back on the front end.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		set_current_screen( 'front' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Tests that an administrator whose own locale is German, in the admin of an English site, gets the site's locale.
	 *
	 * @since 0.1.0
	 */
	public function test_the_locale_is_the_sites_whatever_the_users_language(): void {
		$administrator = self::factory()->user->create(
			array(
				'role'   => 'administrator',
				'locale' => 'de_DE',
			)
		);
		$postId        = self::factory()->post->create();

		wp_set_current_user( $administrator );
		set_current_screen( 'dashboard' );

		$this->assertSame( 'en_US', get_locale(), 'The site is not an English site.' );
		$this->assertSame( 'de_DE', determine_locale(), 'The request is not in the administrator\'s own language, so the check would prove nothing.' );

		$this->assertSame( 'en_US', ( new SiteLocale() )->localeOf( $postId )->toString() );
	}

	/**
	 * Tests that the answer follows the site's setting, and is kept for the rest of the request.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sites_setting_is_read_once(): void {
		$locales = new SiteLocale();
		$french  = static fn(): string => 'fr_FR';

		add_filter( 'locale', $french );

		$this->assertSame( 'fr_FR', $locales->localeOf( 1 )->toString() );

		remove_filter( 'locale', $french );

		$this->assertSame( 'fr_FR', $locales->localeOf( 2 )->toString(), 'The answer is kept for the request.' );
		$this->assertSame( 'en_US', ( new SiteLocale() )->localeOf( 2 )->toString() );
	}

	/**
	 * Tests that inside switch_to_blog() the answer is the switched site's own locale, and the first site's again after it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_switched_site_has_its_own_locale(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A second site needs a network.' );
		}

		$german  = self::factory()->blog->create();
		$locales = new SiteLocale();

		update_blog_option( $german, 'WPLANG', 'de_DE' );

		$this->assertSame( 'en_US', $locales->localeOf( 1 )->toString(), 'The first site is not an English site.' );

		switch_to_blog( $german );

		try {
			$this->assertSame( 'en_US', get_locale(), 'get_locale() already answers the switched site, so the check would prove nothing.' );
			$this->assertSame( 'de_DE', $locales->localeOf( 1 )->toString() );
			$this->assertSame( 'de_DE', ( new SiteLocale() )->localeOf( 1 )->toString() );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 'en_US', $locales->localeOf( 1 )->toString(), 'The first site\'s answer changed after the switch.' );
	}
}

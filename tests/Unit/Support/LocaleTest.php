<?php
/**
 * Tests Locale: WordPress locales and their BCP 47 tags
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Locale;

/**
 * Proves which strings are WordPress locales, including variants, and how they read as BCP 47.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class LocaleTest extends TestCase {

	/**
	 * Tests that WordPress locales are accepted and kept as given.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_wordpress_locales
	 *
	 * @param string $locale The WordPress locale.
	 * @param string $bcp47  Its BCP 47 tag.
	 */
	public function test_a_wordpress_locale_is_accepted_and_has_a_bcp47_tag( string $locale, string $bcp47 ): void {
		$value = Locale::of( $locale );

		$this->assertSame( $locale, $value->toString() );
		$this->assertSame( $bcp47, $value->toBcp47() );
	}

	/**
	 * Provides WordPress locales with their BCP 47 tags.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string}> Test cases.
	 */
	public static function data_wordpress_locales(): array {
		return array(
			'US English'                       => array( 'en_US', 'en-US' ),
			'British English'                  => array( 'en_GB', 'en-GB' ),
			'German'                           => array( 'de_DE', 'de-DE' ),
			'Brazilian Portuguese'             => array( 'pt_BR', 'pt-BR' ),
			'a language without a region'      => array( 'ja', 'ja' ),
			'a three-letter language'          => array( 'ast', 'ast' ),
			'formal German drops the variant'  => array( 'de_DE_formal', 'de-DE' ),
			'informal Swiss German'            => array( 'de_CH_informal', 'de-CH' ),
			'Portuguese after the 1990 reform' => array( 'pt_PT_ao90', 'pt-PT' ),
			'a variant without a region'       => array( 'art_xemoji', 'art' ),
		);
	}

	/**
	 * Tests that anything but a WordPress locale is refused; normalizing is the adapter's job.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_strings_that_are_not_wordpress_locales
	 *
	 * @param string $given The string.
	 */
	public function test_anything_else_is_refused( string $given ): void {
		$this->expectException( \InvalidArgumentException::class );

		Locale::of( $given );
	}

	/**
	 * Provides strings that are not WordPress locales.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public static function data_strings_that_are_not_wordpress_locales(): array {
		return array(
			'empty'               => array( '' ),
			'a BCP 47 tag'        => array( 'en-US' ),
			'lower-case region'   => array( 'en_us' ),
			'upper-case language' => array( 'EN_US' ),
			'one letter'          => array( 'e' ),
			'a language name'     => array( 'english' ),
			'three-letter region' => array( 'en_USA' ),
			'trailing underscore' => array( 'en_US_' ),
			'upper-case variant'  => array( 'de_DE_Formal' ),
			'trailing line feed'  => array( "en_US\n" ),
			'a numeric region'    => array( 'es_419' ),
		);
	}

	/**
	 * Tests equality, variant included.
	 *
	 * @since 0.1.0
	 */
	public function test_equality_includes_the_variant(): void {
		$this->assertTrue( Locale::of( 'de_DE' )->equals( Locale::of( 'de_DE' ) ) );
		$this->assertFalse( Locale::of( 'de_DE' )->equals( Locale::of( 'de_DE_formal' ) ), 'A formal store is a different translation, so a different locale.' );
	}
}

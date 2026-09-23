<?php
/**
 * Locale: a WordPress locale such as en_GB or de_DE_formal
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A WordPress locale.
 *
 * This class owns one fact: the form a locale takes in SEOCart — the WordPress locale that
 * every `locale` column stores (data storage §3.1) — and how that form is written as a BCP 47
 * language tag. A WordPress locale is a language code of two or three lower-case letters, an
 * optional region of two upper-case letters, and an optional variant of four or more lower-case
 * letters and digits: 'ja', 'en_GB', 'pt_BR', 'ast', 'de_DE_formal', 'pt_PT_ao90'.
 *
 * Variants are accepted because WordPress ships translations under them (de_DE_formal is one
 * of the most used), so a store can run in one. They are dropped from the BCP 47 form, since
 * a WordPress variant names a translation's register or spelling reform, not a registered
 * BCP 47 subtag ('ao90' is not even well-formed there): de_DE_formal is 'de-DE'.
 *
 * Turning a URL language code, a BCP 47 tag or a visitor's choice into a Locale is the
 * multilingual adapter's job (backlog A-07), not this class's: it only accepts the exact form.
 *
 * @since 0.1.0
 */
final class Locale {

	/**
	 * The shape of a WordPress locale: language, optional region, optional variant.
	 *
	 * WordPress variants (formal, informal, ao90) are at least four characters long, which is
	 * what keeps a lower-case region such as en_us from passing as a variant.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PATTERN = '/^([a-z]{2,3})(?:_([A-Z]{2}))?(?:_[a-z0-9]{4,})?\z/';

	/**
	 * The WordPress locale, for example 'en_GB'.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $locale;

	/**
	 * Creates a locale from a string already checked against PATTERN.
	 *
	 * @since 0.1.0
	 *
	 * @param string $locale The WordPress locale.
	 */
	private function __construct( string $locale ) {
		$this->locale = $locale;
	}

	/**
	 * Returns the locale a WordPress locale string names.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the string is not a WordPress locale.
	 *
	 * @param string $locale A WordPress locale, for example 'en_US' or 'de_DE_formal'.
	 * @return self The locale.
	 */
	public static function of( string $locale ): self {
		if ( 1 !== preg_match( self::PATTERN, $locale ) ) {
			throw new \InvalidArgumentException( 'A WordPress locale is a language of two or three lower-case letters, an optional region of two upper-case letters and an optional variant, joined by underscores: en_US, ja, de_DE_formal.' );
		}

		return new self( $locale );
	}

	/**
	 * Returns the WordPress locale, as a `locale` column stores it.
	 *
	 * @since 0.1.0
	 *
	 * @return string The WordPress locale, for example 'de_DE_formal'.
	 */
	public function toString(): string {
		return $this->locale;
	}

	/**
	 * Returns the locale as a BCP 47 language tag: language and region, without the variant.
	 *
	 * @since 0.1.0
	 *
	 * @return string The tag, for example 'en-GB' for en_GB and 'de-DE' for de_DE_formal.
	 */
	public function toBcp47(): string {
		preg_match( self::PATTERN, $this->locale, $parts );

		$language = $parts[1] ?? $this->locale;
		$region   = $parts[2] ?? '';

		return '' === $region ? $language : $language . '-' . $region;
	}

	/**
	 * Tells whether two locales are the same.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $other The locale to compare with.
	 * @return bool True when both are the same WordPress locale, variant included.
	 */
	public function equals( Locale $other ): bool {
		return $this->locale === $other->locale;
	}
}

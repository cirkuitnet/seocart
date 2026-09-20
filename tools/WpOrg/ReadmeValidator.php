<?php
/**
 * ReadmeValidator: the WordPress.org directory rules for readme.txt
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg;

use SEOCart\Tools\Docs\ExternalServicesSection;

/**
 * Checks readme.txt against the plugin directory's rules and against the plugin header.
 *
 * This class owns one fact: what the WordPress.org directory, and this project's release
 * process, require of readme.txt. The rules come from the Detailed Plugin Guidelines
 * (4: public source and build steps; 7: external services; 12: at most five tags and no
 * competitor's name; 15: version numbers) and from the Plugin Developer FAQ (`Stable tag`
 * is never `trunk`; `Tested up to` is a real version). Where readme.txt repeats a fact
 * that is declared elsewhere (the plugin header's version and platform floors, or
 * composer.json's `support.source`), the rule is agreement with that declaration, not a
 * further copy of the value.
 *
 * Two things cannot be judged from the working tree, so they are not judged here:
 *
 * - Guideline 15 also requires the version to increase with every release. That needs
 *   the previous release's tag, so it belongs to the release workflow, which can compare
 *   the pushed tag with the one before it.
 * - `Tested up to` is checked for its format and against `Requires at least` only. That
 *   it names a WordPress release that exists, and the current one, cannot be known
 *   offline: "99.9" passes. It is an item of the release checklist.
 *
 * @since 0.1.0
 */
final class ReadmeValidator {

	/**
	 * The exact value of the `License` header. The project is GPL-3.0-or-later.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LICENSE = 'GPLv3 or later';

	/**
	 * The most tags the directory accepts (guideline 12).
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_TAGS = 5;

	/**
	 * The longest short description the directory displays without cutting it off.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_SHORT_DESCRIPTION_LENGTH = 150;

	/**
	 * The header fields readme.txt must carry.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const REQUIRED_HEADERS = array(
		'Contributors',
		'Tags',
		'Requires at least',
		'Tested up to',
		'Requires PHP',
		'Stable tag',
		'License',
		'License URI',
	);

	/**
	 * The title of the section that links the public source and gives the build steps (guideline 4).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SOURCE_SECTION = 'Source code and build steps';

	/**
	 * The commands that build the release zip from source, each of which the source section must show.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const BUILD_STEPS = array( 'composer install', 'npm ci', 'npm run build', 'php bin/build-zip.php' );

	/**
	 * Names of other e-commerce products, none of which may appear in readme.txt.
	 *
	 * The one list. Lower case, words separated by single spaces; text is compared after
	 * the same normalization, and matches when it contains a name as a whole word or phrase.
	 *
	 * The directory forbids such a name as a tag (guideline 12) and in the plugin's name
	 * (guideline 17). The project's own rule is wider: readme.txt names no other product
	 * anywhere, so the plugin name, the short description and every section are checked
	 * too. The directory does allow a mention in a section's body. Should a release ever
	 * need one, for example to credit a bundled library whose package name contains such
	 * a name, narrow checkCompetitorNames() to the name and the short description and
	 * record the reason here.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const COMPETITOR_NAMES = array(
		'woocommerce',
		'woo',
		'shopify',
		'magento',
		'adobe commerce',
		'bigcommerce',
		'prestashop',
		'opencart',
		'ecwid',
		'wix',
		'squarespace',
		'easy digital downloads',
		'edd',
		'surecart',
		'fluentcart',
		'studiocart',
		'cart66',
		'easycart',
		'wp ecommerce',
		'wp e commerce',
		'shopware',
		'bigcartel',
		'big cartel',
	);

	/**
	 * Validates a readme against the rules, the plugin header and, at release time, the git tag.
	 *
	 * @since 0.1.0
	 *
	 * @param Readme                $readme        The parsed readme.txt.
	 * @param array<string, string> $pluginHeaders The main file's header fields: PluginHeaders::read().
	 * @param string                $sourceUrl     The public source repository: composer.json's `support.source`.
	 * @param string|null           $gitTag        Optional. The release's git tag, for example 'v0.1.0'.
	 *                                             Default null: the tag is not checked.
	 * @return list<Violation> Every broken rule; empty when the readme is valid.
	 */
	public function validate( Readme $readme, array $pluginHeaders, string $sourceUrl, ?string $gitTag = null ): array {
		return array_merge(
			$this->checkHeaders( $readme, $pluginHeaders ),
			$this->checkVersions( $readme, $pluginHeaders, $gitTag ),
			$this->checkLicense( $readme ),
			$this->checkTags( $readme ),
			$this->checkShortDescription( $readme ),
			$this->checkCompetitorNames( $readme ),
			$this->checkSections( $readme, $sourceUrl )
		);
	}

	/**
	 * Checks that every required field exists, exactly once, in the readme and in the plugin header.
	 *
	 * @since 0.1.0
	 *
	 * @param Readme                $readme        The parsed readme.txt.
	 * @param array<string, string> $pluginHeaders The main file's header fields.
	 * @return list<Violation> The broken rules.
	 */
	private function checkHeaders( Readme $readme, array $pluginHeaders ): array {
		$violations = array();

		foreach ( PluginHeaders::FIELDS as $field ) {
			if ( '' === ( $pluginHeaders[ $field ] ?? '' ) ) {
				$violations[] = new Violation( 'plugin-header-missing', "The plugin's main file has no \"{$field}\" header." );
			}
		}

		foreach ( self::REQUIRED_HEADERS as $field ) {
			if ( '' === ( $readme->header( $field ) ?? '' ) ) {
				$violations[] = new Violation( 'header-missing', "readme.txt has no \"{$field}\" header." );
			}
		}

		foreach ( $readme->duplicateHeaders() as $field ) {
			$violations[] = new Violation( 'header-duplicate', "readme.txt states the \"{$field}\" header more than once." );
		}

		$name = $pluginHeaders['Plugin Name'] ?? '';

		if ( '' !== $name && $readme->name() !== $name ) {
			$violations[] = new Violation( 'readme-name-mismatch', "readme.txt must open with \"=== {$name} ===\", the plugin header's Plugin Name; found \"{$readme->name()}\"." );
		}

		return $violations;
	}

	/**
	 * Checks the stable tag, the platform floors, `Tested up to` and the git tag.
	 *
	 * @since 0.1.0
	 *
	 * @param Readme                $readme        The parsed readme.txt.
	 * @param array<string, string> $pluginHeaders The main file's header fields.
	 * @param string|null           $gitTag        The release's git tag, or null.
	 * @return list<Violation> The broken rules.
	 */
	private function checkVersions( Readme $readme, array $pluginHeaders, ?string $gitTag ): array {
		$violations = array();
		$version    = $pluginHeaders['Version'] ?? '';
		$stable     = $readme->header( 'Stable tag' ) ?? '';

		if ( 'trunk' === strtolower( $stable ) ) {
			$violations[] = new Violation( 'stable-tag-trunk', 'Stable tag must never be "trunk"; it names the released version.' );
		} elseif ( '' !== $stable && ! $this->isSemanticVersion( $stable ) ) {
			$violations[] = new Violation( 'stable-tag-not-semver', "Stable tag must be a MAJOR.MINOR.PATCH version; found \"{$stable}\"." );
		} elseif ( '' !== $stable && '' !== $version && $stable !== $version ) {
			$violations[] = new Violation( 'stable-tag-version-mismatch', "Stable tag is \"{$stable}\" but the plugin header's Version is \"{$version}\". They must be equal." );
		}

		if ( null !== $gitTag && 'v' . $version !== $gitTag ) {
			$violations[] = new Violation( 'git-tag-mismatch', "The git tag is \"{$gitTag}\" but the plugin header's Version is \"{$version}\"; the tag must be \"v{$version}\"." );
		}

		$changelog = $readme->section( 'Changelog' );

		if ( null !== $changelog && $this->isSemanticVersion( $stable ) && 1 !== preg_match( '/^=\s*' . preg_quote( $stable, '/' ) . '\s*=\s*$/m', $changelog ) ) {
			$violations[] = new Violation( 'changelog-version-missing', "The Changelog section has no \"= {$stable} =\" entry for the stable tag." );
		}

		$requires = $readme->header( 'Requires at least' ) ?? '';
		$tested   = $readme->header( 'Tested up to' ) ?? '';

		if ( '' !== $tested && 1 !== preg_match( '/^\d+\.\d+(\.\d+)?$/', $tested ) ) {
			$violations[] = new Violation( 'tested-up-to-invalid', "Tested up to must be a real WordPress version number such as 7.1; found \"{$tested}\"." );
		} elseif ( '' !== $tested && '' !== $requires && version_compare( $tested, $requires, '<' ) ) {
			$violations[] = new Violation( 'tested-up-to-below-minimum', "Tested up to ({$tested}) is lower than Requires at least ({$requires})." );
		}

		$floors = array(
			'Requires at least' => 'requires-at-least-mismatch',
			'Requires PHP'      => 'requires-php-mismatch',
		);

		foreach ( $floors as $field => $code ) {
			$inReadme = $readme->header( $field ) ?? '';
			$inPlugin = $pluginHeaders[ $field ] ?? '';

			if ( '' !== $inReadme && '' !== $inPlugin && $inReadme !== $inPlugin ) {
				$violations[] = new Violation( $code, "readme.txt says \"{$field}: {$inReadme}\" but the plugin header says \"{$inPlugin}\". They must be equal." );
			}
		}

		return $violations;
	}

	/**
	 * Checks the licence headers.
	 *
	 * @since 0.1.0
	 *
	 * @param Readme $readme The parsed readme.txt.
	 * @return list<Violation> The broken rules.
	 */
	private function checkLicense( Readme $readme ): array {
		$violations = array();
		$license    = $readme->header( 'License' ) ?? '';
		$uri        = $readme->header( 'License URI' ) ?? '';

		if ( '' !== $license && self::LICENSE !== $license ) {
			$violations[] = new Violation( 'license-not-gplv3-or-later', 'License must be exactly "' . self::LICENSE . "\"; found \"{$license}\"." );
		}

		if ( '' !== $uri && 1 !== preg_match( '#^https://\S+$#', $uri ) ) {
			$violations[] = new Violation( 'license-uri-invalid', "License URI must be an https:// link to the licence text; found \"{$uri}\"." );
		}

		return $violations;
	}

	/**
	 * Checks the number of tags and that none of them names another product.
	 *
	 * @since 0.1.0
	 *
	 * @param Readme $readme The parsed readme.txt.
	 * @return list<Violation> The broken rules.
	 */
	private function checkTags( Readme $readme ): array {
		$violations = array();
		$tags       = $readme->tags();

		if ( count( $tags ) > self::MAX_TAGS ) {
			$violations[] = new Violation( 'too-many-tags', 'readme.txt has ' . count( $tags ) . ' tags; the directory accepts at most ' . self::MAX_TAGS . '.' );
		}

		foreach ( $tags as $tag ) {
			$name = $this->competitorIn( $tag );

			if ( null !== $name ) {
				$violations[] = new Violation( 'competitor-tag', "The tag \"{$tag}\" names another product (\"{$name}\"). A competitor's name must never be used as a tag." );
			}
		}

		return $violations;
	}

	/**
	 * Checks that the plugin name, the short description and the sections name no other product.
	 *
	 * The tags have their own rule, checkTags(), because the directory itself forbids those.
	 *
	 * @since 0.1.0
	 *
	 * @param Readme $readme The parsed readme.txt.
	 * @return list<Violation> The broken rules, one per place that names another product.
	 */
	private function checkCompetitorNames( Readme $readme ): array {
		$violations = array();
		$places     = array(
			'plugin name'       => $readme->name(),
			'short description' => $readme->shortDescription(),
		);

		foreach ( $readme->sections() as $title => $body ) {
			$places[ "\"{$title}\" section" ] = $title . "\n" . $body;
		}

		foreach ( $places as $place => $text ) {
			$name = $this->competitorIn( $text );

			if ( null !== $name ) {
				$violations[] = new Violation( 'competitor-name-in-readme', "The {$place} names another product (\"{$name}\"). readme.txt must not name a competitor anywhere." );
			}
		}

		return $violations;
	}

	/**
	 * Finds the first competitor's name that a text contains as a whole word or phrase.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text A tag, a title or running text, in any letter case.
	 * @return string|null The name as self::COMPETITOR_NAMES spells it, or null when there is none.
	 */
	private function competitorIn( string $text ): ?string {
		$normalized = trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $text ) ) );

		foreach ( self::COMPETITOR_NAMES as $name ) {
			if ( 1 === preg_match( '/(?<![a-z0-9])' . preg_quote( $name, '/' ) . '(?![a-z0-9])/', $normalized ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Checks that a short description exists and fits.
	 *
	 * @since 0.1.0
	 *
	 * @param Readme $readme The parsed readme.txt.
	 * @return list<Violation> The broken rules.
	 */
	private function checkShortDescription( Readme $readme ): array {
		$short  = $readme->shortDescription();
		$length = (int) preg_match_all( '/./us', $short );

		if ( '' === $short ) {
			return array( new Violation( 'short-description-missing', 'readme.txt has no short description between the headers and the first section.' ) );
		}

		if ( $length > self::MAX_SHORT_DESCRIPTION_LENGTH ) {
			return array( new Violation( 'short-description-too-long', "The short description is {$length} characters long; the limit is " . self::MAX_SHORT_DESCRIPTION_LENGTH . '.' ) );
		}

		return array();
	}

	/**
	 * Checks the required sections, the public source link and the build steps.
	 *
	 * @since 0.1.0
	 *
	 * @param Readme $readme    The parsed readme.txt.
	 * @param string $sourceUrl The public source repository: composer.json's `support.source`.
	 * @return list<Violation> The broken rules.
	 */
	private function checkSections( Readme $readme, string $sourceUrl ): array {
		$violations = array();

		foreach ( array( 'Description', ExternalServicesSection::TITLE, self::SOURCE_SECTION, 'Changelog' ) as $title ) {
			if ( null === $readme->section( $title ) ) {
				$violations[] = new Violation( 'section-missing', "readme.txt has no \"== {$title} ==\" section." );
			}
		}

		$source = $readme->section( self::SOURCE_SECTION );

		if ( null === $source ) {
			return $violations;
		}

		/*
		 * The URL must stand alone: after whitespace or an opening bracket or quote, and before
		 * whitespace or the end, with at most a trailing slash and closing punctuation between.
		 * A plain "contains" would accept a longer URL that merely starts with the right one,
		 * such as another repository or a page below this one.
		 */
		$standalone = '/(?<![^\s(\[<"\'`*])' . preg_quote( $sourceUrl, '/' ) . '\/?[.,;:!?)\]>"\'`*]*(?=\s|$)/';

		if ( '' === $sourceUrl || 1 !== preg_match( $standalone, $source ) ) {
			$violations[] = new Violation( 'source-link-missing', 'The "' . self::SOURCE_SECTION . "\" section must link the public source, {$sourceUrl} (composer.json's support.source)." );
		}

		$missing = array_filter( self::BUILD_STEPS, static fn ( string $step ): bool => ! str_contains( $source, $step ) );

		if ( array() !== $missing ) {
			$violations[] = new Violation( 'build-steps-missing', 'The "' . self::SOURCE_SECTION . '" section does not show these build steps: ' . implode( ', ', $missing ) . '.' );
		}

		return $violations;
	}

	/**
	 * Determines whether a string is a MAJOR.MINOR.PATCH version.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version The candidate.
	 * @return bool True for a version such as 0.1.0.
	 */
	private function isSemanticVersion( string $version ): bool {
		return 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version );
	}
}

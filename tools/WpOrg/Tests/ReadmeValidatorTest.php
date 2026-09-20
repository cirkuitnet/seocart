<?php
/**
 * Tests that every readme rule fires on its planted violation, and only on it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\WpOrg\Readme;
use SEOCart\Tools\WpOrg\ReadmeValidator;
use SEOCart\Tools\WpOrg\Violation;

/**
 * Proves the readme validator can fail.
 *
 * Fixtures/readme/valid.txt breaks no rule. Every other file in that directory is a copy
 * of it with exactly one rule broken, and is named after the violation code it must
 * produce — so each fixture is the planted violation for its rule.
 *
 * @since 0.1.0
 */
final class ReadmeValidatorTest extends TestCase {

	/**
	 * The plugin header every fixture is validated against.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const PLUGIN_HEADERS = array(
		'Plugin Name'       => 'SEOCart',
		'Version'           => '0.1.0',
		'Requires at least' => '7.1',
		'Requires PHP'      => '8.3',
	);

	/**
	 * The public source repository every fixture is validated against.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SOURCE_URL = 'https://github.com/cirkuitnet/seocart';

	/**
	 * The violation codes that have a fixture file of the same name.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const FIXTURE_CODES = array(
		'readme-name-mismatch',
		'header-missing',
		'header-duplicate',
		'stable-tag-trunk',
		'stable-tag-not-semver',
		'stable-tag-version-mismatch',
		'changelog-version-missing',
		'tested-up-to-invalid',
		'tested-up-to-below-minimum',
		'requires-at-least-mismatch',
		'requires-php-mismatch',
		'license-not-gplv3-or-later',
		'license-uri-invalid',
		'too-many-tags',
		'competitor-tag',
		'competitor-name-in-readme',
		'short-description-missing',
		'short-description-too-long',
		'section-missing',
		'source-link-missing',
		'build-steps-missing',
	);

	/**
	 * The violation codes that no readme fixture can plant, because an argument of validate() causes them.
	 *
	 * Each has its own test: test_git_tag_must_agree_with_the_plugin_version() and
	 * test_missing_plugin_header_is_reported().
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const ARGUMENT_CODES = array( 'git-tag-mismatch', 'plugin-header-missing' );

	/**
	 * Validates a fixture and returns the codes of the violations found.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $fixture       Fixture name without the extension.
	 * @param array<string, string> $pluginHeaders Optional. Plugin header fields. Default self::PLUGIN_HEADERS.
	 * @param string|null           $gitTag        Optional. A git tag. Default null.
	 * @return list<string> The violation codes, in order.
	 */
	private function codes( string $fixture, array $pluginHeaders = self::PLUGIN_HEADERS, ?string $gitTag = null ): array {
		return $this->codesOf( (string) file_get_contents( __DIR__ . "/Fixtures/readme/{$fixture}.txt" ), $pluginHeaders, $gitTag );
	}

	/**
	 * Validates the text of a readme and returns the codes of the violations found.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $text          The readme's content.
	 * @param array<string, string> $pluginHeaders Optional. Plugin header fields. Default self::PLUGIN_HEADERS.
	 * @param string|null           $gitTag        Optional. A git tag. Default null.
	 * @return list<string> The violation codes, in order.
	 */
	private function codesOf( string $text, array $pluginHeaders = self::PLUGIN_HEADERS, ?string $gitTag = null ): array {
		return array_map(
			static fn ( Violation $violation ): string => $violation->code,
			( new ReadmeValidator() )->validate( Readme::parse( $text ), $pluginHeaders, self::SOURCE_URL, $gitTag )
		);
	}

	/**
	 * Returns the valid fixture with one piece of its text replaced.
	 *
	 * @since 0.1.0
	 *
	 * @param string $search  Text the valid fixture contains.
	 * @param string $replace Its replacement.
	 * @return string The edited readme.
	 */
	private function validWith( string $search, string $replace ): string {
		$valid = (string) file_get_contents( __DIR__ . '/Fixtures/readme/valid.txt' );

		$this->assertStringContainsString( $search, $valid, 'The valid fixture no longer contains the text this test replaces.' );

		return str_replace( $search, $replace, $valid );
	}

	/**
	 * Provides one case per fixture that plants a violation.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> Violation codes keyed by themselves.
	 */
	public static function fixtureCodes(): array {
		return array_combine( self::FIXTURE_CODES, array_map( static fn ( string $code ): array => array( $code ), self::FIXTURE_CODES ) );
	}

	/**
	 * Tests that the valid fixture breaks no rule.
	 *
	 * @since 0.1.0
	 */
	public function test_valid_readme_has_no_violations(): void {
		$this->assertSame( array(), $this->codes( 'valid' ) );
	}

	/**
	 * Tests that a planted violation produces its own error and no other.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider fixtureCodes
	 *
	 * @param string $code The violation code, which is also the fixture's name.
	 */
	public function test_planted_violation_is_reported( string $code ): void {
		$this->assertSame( array( $code ), $this->codes( $code ) );
	}

	/**
	 * Tests that no fixture file exists without a test case.
	 *
	 * @since 0.1.0
	 */
	public function test_every_fixture_is_exercised(): void {
		$files = array_map(
			static fn ( string $path ): string => basename( $path, '.txt' ),
			(array) glob( __DIR__ . '/Fixtures/readme/*.txt' )
		);

		$expected = array_merge( array( 'valid' ), self::FIXTURE_CODES );

		sort( $files );
		sort( $expected );

		$this->assertSame( $expected, $files );
	}

	/**
	 * Tests that no rule exists without a planted violation.
	 *
	 * In ReadmeValidator.php every kebab-case string literal is a violation code, and every
	 * violation code is such a literal. A rule added without a fixture, or without a test
	 * of its own, fails here.
	 *
	 * @since 0.1.0
	 */
	public function test_every_code_the_validator_can_report_is_planted(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/ReadmeValidator.php' );

		preg_match_all( "/'([a-z][a-z0-9]*(?:-[a-z0-9]+)+)'/", $source, $matches );

		$reportable = array_values( array_unique( $matches[1] ) );
		$planted    = array_merge( self::FIXTURE_CODES, self::ARGUMENT_CODES );

		sort( $reportable );
		sort( $planted );

		$this->assertSame( $planted, $reportable );
	}

	/**
	 * Tests that the git tag must be the plugin version with a leading "v".
	 *
	 * @since 0.1.0
	 */
	public function test_git_tag_must_agree_with_the_plugin_version(): void {
		$this->assertSame( array(), $this->codes( 'valid', self::PLUGIN_HEADERS, 'v0.1.0' ) );
		$this->assertSame( array( 'git-tag-mismatch' ), $this->codes( 'valid', self::PLUGIN_HEADERS, 'v9.9.9' ) );
		$this->assertSame( array( 'git-tag-mismatch' ), $this->codes( 'valid', self::PLUGIN_HEADERS, '0.1.0' ) );
	}

	/**
	 * Tests that changing the plugin header's Version alone breaks the agreement.
	 *
	 * @since 0.1.0
	 */
	public function test_plugin_version_must_equal_the_stable_tag(): void {
		$headers = array_merge( self::PLUGIN_HEADERS, array( 'Version' => '9.9.9' ) );

		$this->assertSame( array( 'stable-tag-version-mismatch' ), $this->codes( 'valid', $headers ) );
	}

	/**
	 * Tests that a plugin header the readme must agree with cannot be absent.
	 *
	 * @since 0.1.0
	 */
	public function test_missing_plugin_header_is_reported(): void {
		$headers = array_merge( self::PLUGIN_HEADERS, array( 'Version' => '' ) );

		$this->assertSame( array( 'plugin-header-missing' ), $this->codes( 'valid', $headers ) );
	}

	/**
	 * Provides tags and whether each names another product.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, bool}> Tag and expected verdict, keyed by tag.
	 */
	public static function tags(): array {
		return array(
			'woocommerce'            => array( 'woocommerce', true ),
			'WooCommerce'            => array( 'WooCommerce', true ),
			'woocommerce-payments'   => array( 'woocommerce-payments', true ),
			'shopify alternative'    => array( 'shopify alternative', true ),
			'Easy Digital Downloads' => array( 'Easy Digital Downloads', true ),
			'easy-digital-downloads' => array( 'easy-digital-downloads', true ),
			'edd'                    => array( 'edd', true ),
			'wp e-commerce'          => array( 'wp e-commerce', true ),
			'ecommerce'              => array( 'ecommerce', false ),
			'embedded'               => array( 'embedded', false ),
			'wooden toys'            => array( 'wooden toys', false ),
			'store'                  => array( 'store', false ),
		);
	}

	/**
	 * Tests that a competitor's name is matched as a whole word or phrase, in any letter case.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider tags
	 *
	 * @param string $tag          The tag.
	 * @param bool   $isCompetitor Whether the tag names another product.
	 */
	public function test_competitor_names_are_matched_as_whole_words( string $tag, bool $isCompetitor ): void {
		$expect = $isCompetitor ? array( 'competitor-tag' ) : array();

		$this->assertSame( $expect, $this->codesOf( $this->validWith( 'checkout, store', "checkout, {$tag}" ) ) );
	}

	/**
	 * Tests that a competitor's name is found wherever the readme puts it, not only in a section's body.
	 *
	 * @since 0.1.0
	 */
	public function test_competitor_name_is_found_in_every_part_of_the_readme(): void {
		$inShortDescription = $this->validWith( 'A fixture readme that breaks no rule.', 'An alternative to Shopify.' );
		$inSectionTitle     = $this->validWith( '== Description ==', "== Moving from Magento ==\n\nText.\n\n== Description ==" );
		$inPluginName       = $this->validWith( '=== SEOCart ===', '=== SEOCart for BigCommerce ===' );

		$this->assertSame( array( 'competitor-name-in-readme' ), $this->codesOf( $inShortDescription ) );
		$this->assertSame( array( 'competitor-name-in-readme' ), $this->codesOf( $inSectionTitle ) );
		$this->assertSame( array( 'readme-name-mismatch', 'competitor-name-in-readme' ), $this->codesOf( $inPluginName ) );
	}

	/**
	 * Provides ways of writing a link in the source section, and whether each links the source repository.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, bool}> The link as written and the expected verdict.
	 */
	public static function sourceLinks(): array {
		return array(
			'end of a sentence'   => array( self::SOURCE_URL . '.', true ),
			'trailing slash'      => array( self::SOURCE_URL . '/.', true ),
			'in brackets'         => array( '(' . self::SOURCE_URL . ').', true ),
			'in backticks'        => array( '`' . self::SOURCE_URL . '`.', true ),
			'another repository'  => array( self::SOURCE_URL . '-fork.', false ),
			'a page below it'     => array( self::SOURCE_URL . '/issues.', false ),
			'a clone address'     => array( self::SOURCE_URL . '.git.', false ),
			'inside another link' => array( 'https://example.org/?to=' . self::SOURCE_URL . '.', false ),
		);
	}

	/**
	 * Tests that the source link must be the repository's URL itself, not a longer URL that starts with it.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider sourceLinks
	 *
	 * @param string $link     The link as the source section writes it.
	 * @param bool   $isSource Whether it links the source repository.
	 */
	public function test_source_link_must_be_the_repository_itself( string $link, bool $isSource ): void {
		$expect = $isSource ? array() : array( 'source-link-missing' );

		$this->assertSame( $expect, $this->codesOf( $this->validWith( self::SOURCE_URL . '.', $link ) ) );
	}

	/**
	 * Tests that an empty source URL fails instead of letting the link rule pass on nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_empty_source_url_fails(): void {
		$readme = Readme::parse( (string) file_get_contents( __DIR__ . '/Fixtures/readme/valid.txt' ) );
		$codes  = array_map(
			static fn ( Violation $violation ): string => $violation->code,
			( new ReadmeValidator() )->validate( $readme, self::PLUGIN_HEADERS, '' )
		);

		$this->assertSame( array( 'source-link-missing' ), $codes );
	}

	/**
	 * Tests that every build step readme.txt must show names something the repository has.
	 *
	 * The steps are text in a readme, so nothing else notices when a script is renamed. A step
	 * of a form this test does not know fails it: a new kind of step needs a new rule here.
	 *
	 * @since 0.1.0
	 */
	public function test_build_steps_name_things_that_exist(): void {
		$root    = dirname( __DIR__, 3 );
		$scripts = json_decode( (string) file_get_contents( $root . '/package.json' ), true, 512, JSON_THROW_ON_ERROR )['scripts'];

		foreach ( ReadmeValidator::BUILD_STEPS as $step ) {
			if ( 1 === preg_match( '/^npm run (\S+)$/', $step, $matches ) ) {
				$this->assertArrayHasKey( $matches[1], $scripts, "package.json has no \"{$matches[1]}\" script." );
			} elseif ( 1 === preg_match( '/^php (\S+)$/', $step, $matches ) ) {
				$this->assertFileExists( $root . '/' . $matches[1] );
			} elseif ( 'composer install' === $step ) {
				$this->assertFileExists( $root . '/composer.lock' );
			} elseif ( 'npm ci' === $step ) {
				$this->assertFileExists( $root . '/package-lock.json' );
			} else {
				$this->fail( "No rule here knows how to verify the build step \"{$step}\"." );
			}
		}
	}
}

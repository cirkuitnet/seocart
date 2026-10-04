<?php
/**
 * Tests that an extension's required checks are the jobs of its CI
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Extension\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Keeps tools/github/extension-ruleset.json, the jobs of .github/workflows/extension-ci.yml
 * and SEOCart's own PHP matrix in step.
 *
 * A required check names a job; a job that is renamed while the ruleset keeps the old name
 * blocks every merge in every extension, and a job the ruleset does not name is not required.
 * The names are read from the workflow as GitHub forms them: the caller's job (`ci`, from the
 * template bin/dev/new-extension.sh writes), a slash, then the job's name with each matrix
 * value of the inputs' defaults.
 *
 * @since 0.2.0
 */
final class ExtensionRulesetTest extends TestCase {

	/**
	 * Tests that the ruleset requires exactly the checks extension-ci.yml produces.
	 *
	 * @since 0.2.0
	 */
	public function test_the_required_checks_are_the_jobs_of_the_extension_ci(): void {
		$ruleset  = json_decode( (string) file_get_contents( self::root() . '/tools/github/extension-ruleset.json' ), true, 512, JSON_THROW_ON_ERROR );
		$required = array();

		foreach ( $ruleset['rules'] as $rule ) {
			foreach ( $rule['parameters']['required_status_checks'] ?? array() as $check ) {
				$required[] = $check['context'];
			}
		}

		$this->assertSame( 'ci', self::callerJob(), 'The ruleset names the checks after the caller job "ci" in the extension template.' );
		$this->assertEqualsCanonicalizing( self::checkNames(), $required );
	}

	/**
	 * Tests that an extension's unit tests run on SEOCart's PHP versions unless its caller says otherwise.
	 *
	 * @since 0.2.0
	 */
	public function test_the_php_matrix_is_seocarts(): void {
		preg_match( '/^\s*php: &php-versions \[(.*)\]$/m', (string) file_get_contents( self::root() . '/.github/workflows/ci.yml' ), $core );

		$this->assertNotEmpty( $core, 'ci.yml declares no PHP matrix (php: &php-versions [...]).' );
		$this->assertSame( array_map( static fn ( string $v ): string => trim( $v, " '" ), explode( ',', $core[1] ) ), self::inputDefault( 'php' ) );
	}

	/**
	 * Returns the names of the checks extension-ci.yml produces, as an extension's ruleset sees them.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> For example `ci / unit-tests (PHP 8.3)`.
	 */
	private static function checkNames(): array {
		$workflow = (string) file_get_contents( self::root() . '/.github/workflows/extension-ci.yml' );
		$lines    = explode( "\n", substr( $workflow, (int) strpos( $workflow, "\njobs:\n" ) ) );
		$jobs     = array();
		$in_job   = null;

		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^    ([a-z-]+):$/', $line, $match ) ) {
				$in_job          = $match[1];
				$jobs[ $in_job ] = $in_job;
			} elseif ( null !== $in_job && 1 === preg_match( '/^        name: (.+)$/', $line, $match ) ) {
				$jobs[ $in_job ] = $match[1];
			}
		}

		$names = array();

		foreach ( $jobs as $name ) {
			$variants = array( $name );

			foreach ( array( 'php', 'wp' ) as $input ) {
				$placeholder = '${{ matrix.' . $input . ' }}';
				$expanded    = array();

				foreach ( $variants as $variant ) {
					if ( ! str_contains( $variant, $placeholder ) ) {
						$expanded[] = $variant;
						continue;
					}

					foreach ( self::inputDefault( $input ) as $value ) {
						$expanded[] = str_replace( $placeholder, $value, $variant );
					}
				}

				$variants = $expanded;
			}

			foreach ( $variants as $variant ) {
				$names[] = self::callerJob() . ' / ' . $variant;
			}
		}

		return $names;
	}

	/**
	 * Returns the default of a JSON-list input of extension-ci.yml.
	 *
	 * @since 0.2.0
	 *
	 * @param string $input The input, `php` or `wp`.
	 * @return list<string> The values.
	 */
	private static function inputDefault( string $input ): array {
		$workflow = (string) file_get_contents( self::root() . '/.github/workflows/extension-ci.yml' );

		preg_match( '/^            ' . $input . ":\n(?:                .*\n)*?                default: '(.*)'$/m", $workflow, $match );

		return json_decode( $match[1] ?? 'null', true, 512, JSON_THROW_ON_ERROR ) ?? array();
	}

	/**
	 * Returns the job of the extension's ci.yml that calls extension-ci.yml.
	 *
	 * @since 0.2.0
	 *
	 * @return string The job's id.
	 */
	private static function callerJob(): string {
		$template = (string) file_get_contents( self::root() . '/bin/dev/extension-template/.github/workflows/ci.yml.tmpl' );

		preg_match( '/^    ([a-z-]+):\n        uses: cirkuitnet\/seocart\/\.github\/workflows\/extension-ci\.yml@/m', $template, $match );

		return $match[1] ?? '';
	}

	/**
	 * Returns this checkout's root.
	 *
	 * @since 0.2.0
	 *
	 * @return string The root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 3 );
	}
}

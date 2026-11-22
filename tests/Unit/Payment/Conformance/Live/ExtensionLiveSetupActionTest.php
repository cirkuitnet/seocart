<?php
/**
 * Tests the composite action that sets up a job of a payment extension's live workflow
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;

/**
 * The action installs and never touches a credential: none of its steps names a secret, a settings file or the settings step, or runs the extension's provider script, which writes that file; it is a composite action whose local paths name SEOCart's checkout beside the extension, with the inputs `wordpress` and `browser`.
 *
 * Planted violations, each shown red and removed (in memory, and in the file): `secrets.` named in
 * a step's env; the settings file read by a step; the provider's settings step run by the action.
 *
 * @since 0.2.0
 */
final class ExtensionLiveSetupActionTest extends TestCase {

	use Plants;

	/**
	 * Tests that the action as committed touches no credential and keeps its shape.
	 *
	 * @since 0.2.0
	 */
	public function test_the_action_touches_no_credential_and_keeps_its_shape(): void {
		$this->assertSame( array(), self::problems( self::action() ) );
	}

	/**
	 * Tests that a secret, the settings file or the settings step named in the action is found.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider planted
	 *
	 * @param string   $expected Text of the problem expected.
	 * @param \Closure $plant    The plant: the lines in, broken out.
	 */
	public function test_a_credential_named_in_the_action_is_found( string $expected, \Closure $plant ): void {
		$this->assertStringContainsString( $expected, implode( "\n", self::problems( $plant( self::action() ) ) ) );
	}

	/**
	 * Returns one plant per way the action could touch a credential or lose its shape.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each problem expected and its plant, by name.
	 */
	public static function planted(): array {
		$composer = '/^              install: full$/';

		return array(
			'a secret in a step\'s env'       => array( 'names a secret or the settings: `KEY: ${{ secrets.FIXTURE_PAY_API_KEY }}`', static fn( array $lines ): array => self::after( self::after( $lines, $composer, '          env:' ), '/^          env:$/', '              KEY: ${{ secrets.FIXTURE_PAY_API_KEY }}' ) ),
			'the settings file read'          => array( 'names a secret or the settings: `run: cat "$RUNNER_TEMP/seocart-live/settings.json"`', static fn( array $lines ): array => self::replaced( $lines, '/^          run: node --test$/', '          run: cat "$RUNNER_TEMP/seocart-live/settings.json"' ) ),
			'the settings step run'           => array( 'runs the extension\'s own script: `run: sh ext/bin/ci/fixture-pay-sandbox.sh configure`', static fn( array $lines ): array => self::replaced( $lines, '/^          run: node --test$/', '          run: sh ext/bin/ci/fixture-pay-sandbox.sh configure' ) ),
			'a secret in capitals'            => array( 'names a secret or the settings: `run: echo "${{ toJSON(SECRETS) }}"`', static fn( array $lines ): array => self::replaced( $lines, '/^          run: node --test$/', '          run: echo "${{ toJSON(SECRETS) }}"' ) ),
			'a local path of its own folder'  => array( 'names a local path outside SEOCart\'s checkout beside the extension: `uses: ./.github/actions/setup-php-composer`', static fn( array $lines ): array => self::replaced( $lines, '/^          uses: \.\/seocart\/\.github\/actions\/setup-php-composer$/', '          uses: ./.github/actions/setup-php-composer' ) ),
			'not composite'                   => array( 'does not run `using: composite`', static fn( array $lines ): array => self::replaced( $lines, '/^    using: composite$/', "    using: 'node24'" ) ),
			'the browser input set up always' => array( 'does not take the input browser with the default \'false\'', static fn( array $lines ): array => self::replaced( $lines, "/^        default: 'false'$/", "        default: 'true'" ) ),
		);
	}

	/**
	 * Returns what the action breaks, read from its lines: any line but a comment that names a secret or the settings, or runs the extension's own script; a `./` path other than SEOCart's checkout beside the extension; and the shape its callers rely on.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The action's lines.
	 * @return list<string> What is wrong; none when nothing is.
	 * @phpstan-param list<string> $lines
	 */
	private static function problems( array $lines ): array {
		$problems = array();
		$text     = implode( "\n", $lines );

		foreach ( $lines as $at => $line ) {
			if ( str_starts_with( ltrim( $line ), '#' ) ) {
				continue;
			}

			if ( 1 === preg_match( '/secrets|settings/i', $line ) ) {
				$problems[] = sprintf( 'Line %1$d names a secret or the settings: `%2$s`.', $at + 1, trim( $line ) );
			}

			if ( str_contains( $line, 'ext/bin/' ) ) {
				$problems[] = sprintf( 'Line %1$d runs the extension\'s own script: `%2$s`.', $at + 1, trim( $line ) );
			}

			if ( 1 === preg_match( '/^\s*(?:-\s+)?uses:\s+\.\/(?!seocart\/)/', $line ) ) {
				$problems[] = sprintf( 'Line %1$d names a local path outside SEOCart\'s checkout beside the extension: `%2$s`.', $at + 1, trim( $line ) );
			}
		}

		if ( 1 !== preg_match( '/^runs:\n    using: composite$/m', $text ) ) {
			$problems[] = 'The action does not run `using: composite`.';
		}

		foreach ( array(
			'wordpress' => 'true',
			'browser'   => 'false',
		) as $input => $default ) {
			if ( 1 !== preg_match( '/^    ' . $input . ':\n(?:        .*\n)*?        default: \'' . $default . '\'$/m', $text ) ) {
				$problems[] = sprintf( 'The action does not take the input %1$s with the default \'%2$s\'.', $input, $default );
			}
		}

		return $problems;
	}

	/**
	 * Returns the action's lines.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The lines.
	 */
	private static function action(): array {
		return self::linesOf( dirname( __DIR__, 5 ) . '/.github/actions/extension-live-setup/action.yml' );
	}
}

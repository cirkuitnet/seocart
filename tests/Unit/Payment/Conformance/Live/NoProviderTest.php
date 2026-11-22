<?php
/**
 * Tests that SEOCart's pieces of the live workflow name no payment provider
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;

/**
 * What SEOCart shares among its payment extensions' live workflows (tests/Conformance/Payment/Live, bin/ci/extension-live*.sh and the action extension-live-setup) holds no provider's name and no provider's key prefix, in any letter case: what is a provider's is its extension's declaration. The fixture extension, which stands for a provider, is the one place outside them.
 *
 * Planted violation, shown red and removed: `'Stripe'` written into Verdict.php.
 *
 * @since 0.2.0
 */
final class NoProviderTest extends TestCase {

	/**
	 * A provider's name or key prefix, in any letter case.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PROVIDER = '/stripe|paypal|authorize|(?<![a-z0-9])(?:sk|rk|pk)_|whsec_/i';

	/**
	 * Tests that no file of SEOCart's pieces names a provider.
	 *
	 * @since 0.2.0
	 */
	public function test_no_file_names_a_provider(): void {
		$files = self::files();

		$this->assertGreaterThanOrEqual( 12, count( $files ), 'The pieces were not found, so nothing was read.' );
		$this->assertSame( array(), self::findings( $files ) );
	}

	/**
	 * Tests that a provider's name or key prefix is found wherever it is written, and that words that only look like one are not.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider texts
	 *
	 * @param string $text  A line.
	 * @param bool   $found Whether it names a provider.
	 */
	public function test_a_provider_is_found_in_any_case( string $text, bool $found ): void {
		$this->assertSame( $found ? array( 'Verdict.php:1: ' . $text ) : array(), self::findings( array( 'Verdict.php' => $text . "\n" ) ) );
	}

	/**
	 * Returns lines that name a provider and lines that do not.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, bool}> The line, and whether it names one; by name.
	 */
	public static function texts(): array {
		return array(
			'a name in capitals'      => array( '$lines = array( \'## Stripe test mode\' );', true ),
			'a name in a slug'        => array( 'ext/bin/ci/paypal-sandbox.sh', true ),
			'a name in a secret'      => array( 'AUTHORIZE_NET_SIGNATURE_KEY', true ),
			'a key prefix'            => array( '\'/sk_(?:test|live)_/\'', true ),
			'a signing secret prefix' => array( 'WHSEC_', true ),
			'an authorization header' => array( 'an authorization header', false ),
			'a word ending in sk_'    => array( 'task_list', false ),
			'a publishable word'      => array( 'a pk-less id', false ),
		);
	}

	/**
	 * Returns each line of the files that names a provider.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $files Each file's text, by its path.
	 * @return list<string> Each finding, as `<path>:<line>: <text>`.
	 */
	private static function findings( array $files ): array {
		$found = array();

		foreach ( $files as $path => $text ) {
			foreach ( explode( "\n", $text ) as $at => $line ) {
				if ( 1 === preg_match( self::PROVIDER, $line ) ) {
					$found[] = sprintf( '%1$s:%2$d: %3$s', $path, $at + 1, trim( $line ) );
				}
			}
		}

		return $found;
	}

	/**
	 * Returns the text of each file of SEOCart's pieces of the live workflow, by its path from SEOCart's root.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The texts.
	 */
	private static function files(): array {
		$root  = dirname( __DIR__, 5 );
		$files = array();

		foreach ( array( 'tests/Conformance/Payment/Live/*', 'bin/ci/extension-live*.sh', '.github/actions/extension-live-setup/*' ) as $pattern ) {
			$matched = glob( $root . '/' . $pattern );

			foreach ( false === $matched ? array() : $matched as $file ) {
				$files[ substr( $file, strlen( $root ) + 1 ) ] = (string) file_get_contents( $file );
			}
		}

		return $files;
	}
}

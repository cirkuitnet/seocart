<?php
/**
 * Tests that the integration test configuration template can be filled in safely
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Guards the one property of tests/wp-tests-config.template.php that its two writers rely on.
 *
 * The writers, bin/dev/provision-test-db.sh and bin/ci/prepare-integration.sh, replace every
 * occurrence of a token, wherever it is. A token that also appears in a comment would copy
 * the database password into the generated file a second time, would let a value that holds
 * a comment terminator break the file, and would let a writer find a token whose define()
 * call is gone.
 *
 * Which tokens exist is the writers' business and is checked by them when they run. This test
 * does not list them.
 *
 * @since 0.1.0
 */
final class TestsConfigTemplateTest extends TestCase {

	/**
	 * Tests that every token is written once, as the whole of a single-quoted string.
	 *
	 * @since 0.1.0
	 */
	public function test_every_token_is_written_once_as_a_whole_quoted_string(): void {
		$template = (string) file_get_contents( dirname( __DIR__, 2 ) . '/wp-tests-config.template.php' );

		preg_match_all( '/__[A-Z][A-Z_]*__/', $template, $tokens );
		preg_match_all( "/'(__[A-Z][A-Z_]*__)'/", $template, $quoted );

		$this->assertNotSame( array(), $tokens[0], 'No token was found in the template, so the pattern that reads them is broken.' );

		$this->assertSame(
			array_values( array_unique( $tokens[0] ) ),
			$tokens[0],
			'A token appears more than once in the template. Name it without its underscores anywhere but in its define() call.'
		);

		$this->assertSame( $quoted[1], $tokens[0], 'A token appears outside a single-quoted string, where the value that replaces it would not be a string.' );
	}
}

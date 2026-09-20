<?php
/**
 * Tests the readme.txt parser
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\WpOrg\Readme;

/**
 * Covers the four parts of a readme.txt the validator reads.
 *
 * @since 0.1.0
 */
final class ReadmeTest extends TestCase {

	/**
	 * Tests that the name, headers, short description and sections are read.
	 *
	 * @since 0.1.0
	 */
	public function test_parses_the_parts_of_a_readme(): void {
		$readme = Readme::parse( (string) file_get_contents( __DIR__ . '/Fixtures/readme/valid.txt' ) );

		$this->assertSame( 'SEOCart', $readme->name() );
		$this->assertSame( '0.1.0', $readme->header( 'Stable tag' ) );
		$this->assertSame( '0.1.0', $readme->header( 'STABLE TAG' ) );
		$this->assertSame( 'https://www.gnu.org/licenses/gpl-3.0.html', $readme->header( 'License URI' ) );
		$this->assertNull( $readme->header( 'Donate link' ) );
		$this->assertSame( array(), $readme->duplicateHeaders() );
		$this->assertSame( array( 'ecommerce', 'shop', 'cart', 'checkout', 'store' ), $readme->tags() );
		$this->assertSame( 'A fixture readme that breaks no rule.', $readme->shortDescription() );
		$this->assertSame( 'This fixture connects to nothing.', $readme->section( 'External services' ) );
		$this->assertSame( "= 0.1.0 =\n* The first fixture.", $readme->section( 'changelog' ) );
		$this->assertNull( $readme->section( 'Screenshots' ) );
		$this->assertSame( array( 'description', 'external services', 'source code and build steps', 'changelog' ), array_keys( $readme->sections() ) );
	}

	/**
	 * Tests that Windows line endings do not change the result.
	 *
	 * @since 0.1.0
	 */
	public function test_accepts_windows_line_endings(): void {
		$text   = str_replace( "\n", "\r\n", (string) file_get_contents( __DIR__ . '/Fixtures/readme/valid.txt' ) );
		$readme = Readme::parse( $text );

		$this->assertSame( '0.1.0', $readme->header( 'Stable tag' ) );
		$this->assertSame( 'This fixture connects to nothing.', $readme->section( 'External services' ) );
	}

	/**
	 * Tests that a repeated header is recorded and the first value is kept.
	 *
	 * @since 0.1.0
	 */
	public function test_records_duplicate_headers(): void {
		$readme = Readme::parse( "=== Name ===\nStable tag: 1.0.0\nStable tag: trunk\n\nShort.\n" );

		$this->assertSame( '1.0.0', $readme->header( 'Stable tag' ) );
		$this->assertSame( array( 'stable tag' ), $readme->duplicateHeaders() );
	}

	/**
	 * Tests that a readme without a title line, headers or sections parses to empty parts.
	 *
	 * @since 0.1.0
	 */
	public function test_parses_an_empty_readme(): void {
		$readme = Readme::parse( '' );

		$this->assertSame( '', $readme->name() );
		$this->assertSame( array(), $readme->tags() );
		$this->assertSame( '', $readme->shortDescription() );
		$this->assertNull( $readme->section( 'Description' ) );
	}

	/**
	 * Tests that a multi-line short description is joined into one line.
	 *
	 * @since 0.1.0
	 */
	public function test_joins_a_wrapped_short_description(): void {
		$readme = Readme::parse( "=== Name ===\nTags: one\n\nFirst line\nsecond line.\n\n== Description ==\n\nBody.\n" );

		$this->assertSame( 'First line second line.', $readme->shortDescription() );
		$this->assertSame( 'Body.', $readme->section( 'Description' ) );
	}
}

<?php
/**
 * Tests that the extension guide shows the checkout field registration the tests run
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\Checkout\AcmeCheckoutFields;

/**
 * Guards the one registration docs/writing-an-extension.md quotes: the fixture extension's, which the integration tests walk through every surface.
 *
 * The guide shows the method as the fixture holds it, one indent less, so the example a reader
 * copies is the one that runs; this test owns that fact.
 *
 * Planted violation, shown red and removed: change one option's label in the fixture's
 * registration: the guide then quotes another registration than the one the tests run.
 *
 * @group contract
 *
 * @since 0.2.0
 */
final class CheckoutFieldsGuideTest extends TestCase {

	/**
	 * Tests that the guide quotes the fixture's registration, word for word.
	 *
	 * @since 0.2.0
	 */
	public function test_the_guide_quotes_the_registration_the_tests_run(): void {
		$method = new \ReflectionMethod( AcmeCheckoutFields::class, 'register' );
		$lines  = array_slice( (array) file( (string) $method->getFileName(), FILE_IGNORE_NEW_LINES ), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 );
		$quoted = implode( "\n", array_map( static fn( string $line ): string => str_starts_with( $line, "\t" ) ? substr( $line, 1 ) : $line, $lines ) );
		$guide  = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/writing-an-extension.md' );

		$this->assertStringContainsString( "```php\n" . $quoted . "\n```", $guide, 'docs/writing-an-extension.md does not quote the fixture extension\'s registration as it is.' );
	}
}

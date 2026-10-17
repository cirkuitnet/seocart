<?php
/**
 * Tests for the integration half of the payment conformance script
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\ConformanceRun;
use SEOCart\Tests\Conformance\Payment\GatewayConformanceCase;

/**
 * An extension without a conformance test is skipped loudly, the file to add named; one with a test is found.
 *
 * @since 0.2.0
 */
final class ConformanceRunTest extends TestCase {

	/**
	 * The extension's root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Makes the extension's root.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/seocart-conformance-run-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/tests/Integration', 0700, true );
	}

	/**
	 * Removes the extension's root.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		$test = $this->root . '/' . ConformanceRun::TEST_FILE;

		if ( is_file( $test ) ) {
			unlink( $test );
		}

		rmdir( $this->root . '/tests/Integration' );
		rmdir( $this->root . '/tests' );
		rmdir( $this->root );
		parent::tearDown();
	}

	/**
	 * Tests that an extension without a conformance test is skipped with a warning annotation under GitHub Actions and a line that says SKIPPED, naming the file to add.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_extension_without_a_conformance_test_is_skipped_loudly(): void {
		$out = fopen( 'php://memory', 'w+b' );
		$this->assertNotFalse( $out );

		$status = ConformanceRun::main( '/nowhere', $this->root, true, $out );
		rewind( $out );
		$printed = (string) stream_get_contents( $out );
		fclose( $out );

		$this->assertSame( 0, $status );
		$this->assertStringStartsWith( '::warning title=Conformance suite skipped::' . $this->root . ' has no payment conformance test yet', $printed );
		$this->assertStringContainsString( "\nconformance: SKIPPED: " . $this->root . ' has no payment conformance test yet', $printed );
		$this->assertStringContainsString( 'Add ' . ConformanceRun::TEST_FILE . ', a test that extends ' . GatewayConformanceCase::class, $printed );
	}

	/**
	 * Tests that an extension's test that extends the case is found.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_extensions_conformance_test_is_found(): void {
		$this->assertNull( ConformanceRun::conformanceTestIn( $this->root ) );

		file_put_contents( $this->root . '/' . ConformanceRun::TEST_FILE, "<?php\nfinal class ConformanceTest extends GatewayConformanceCase {}\n" );

		$this->assertSame( $this->root . '/' . ConformanceRun::TEST_FILE, ConformanceRun::conformanceTestIn( $this->root ) );
	}
}

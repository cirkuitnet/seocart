<?php
/**
 * Tests for the command that writes a new extension's repository, run as the developer runs it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Extension\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Tools\Packaging\Tests\TemporaryDirectory;

/**
 * Runs `php tools/extension.php new` in a process of its own, which is where it differs from the
 * tests of Skeleton: there is no test bootstrap, so ABSPATH is not defined unless the command
 * defines it, and SEOCart's files, which end the script without a word when it is not, are read
 * for the contract the generated files are written from.
 *
 * @since 0.2.0
 */
final class ExtensionCommandTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * Tests that the command writes the skeleton, and says which action and gateway id it registers.
	 *
	 * @since 0.2.0
	 */
	public function test_new_writes_a_skeleton_that_registers_the_gateway(): void {
		$target = $this->directory . '/seocart-gateway-for-example';

		list( $code, $output ) = self::runCommand( 'new', dirname( __DIR__, 3 ), 'seocart-gateway-for-example', '--type=payments', '--label=Example', '--dir=' . $target );

		$this->assertSame( 0, $code, $output );
		$this->assertStringContainsString( 'new-extension: wrote ' . $target, $output );
		$this->assertStringContainsString( 'seocart-gateway-for-example.php hooks ' . GatewayRegistry::ACTION . ' to register the gateway "example"', $output );
		$this->assertStringContainsString( "'" . GatewayRegistry::ACTION . "'", (string) file_get_contents( $target . '/seocart-gateway-for-example.php' ) );
		$this->assertStringContainsString( "public const ID = 'example';", (string) file_get_contents( $target . '/src/Gateway.php' ) );
	}

	/**
	 * Tests that the id can be named, and that one the contract refuses is refused with a usage error.
	 *
	 * @since 0.2.0
	 */
	public function test_new_takes_a_gateway_id_and_refuses_a_bad_one(): void {
		$core   = dirname( __DIR__, 3 );
		$target = $this->directory . '/seocart-gateway-for-2checkout';

		list( $code, $output ) = self::runCommand( 'new', $core, 'seocart-gateway-for-2checkout', '--type=payments', '--label=2Checkout', '--dir=' . $target );

		$this->assertSame( 2, $code );
		$this->assertStringContainsString( 'gives the gateway id "2checkout", which is not one', $output );
		$this->assertDirectoryDoesNotExist( $target );

		list( $code, $output ) = self::runCommand( 'new', $core, 'seocart-gateway-for-2checkout', '--type=payments', '--label=2Checkout', '--gateway-id=two_checkout', '--dir=' . $target );

		$this->assertSame( 0, $code, $output );
		$this->assertStringContainsString( 'to register the gateway "two_checkout"', $output );
		$this->assertStringContainsString( "public const ID = 'two_checkout';", (string) file_get_contents( $target . '/src/Gateway.php' ) );
	}

	/**
	 * Runs the command in a new process.
	 *
	 * @since 0.2.0
	 *
	 * @param string ...$arguments The command line after `php tools/extension.php`.
	 * @return array{0: int, 1: string} The exit code, and what the process printed to its output and its error output.
	 */
	private static function runCommand( string ...$arguments ): array {
		$process = proc_open(
			array_merge( array( PHP_BINARY, dirname( __DIR__, 2 ) . '/extension.php' ), $arguments ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			return array( -1, 'php could not be started.' );
		}

		$output = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array( proc_close( $process ), $output );
	}
}

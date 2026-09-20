<?php
/**
 * Tests that the plugin autoloader accepts class names only
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration;

use ReflectionFunction;
use SEOCart\Platform\Kernel\Kernel;
use WP_UnitTestCase;

/**
 * Proves that a class name cannot make the release autoloader leave `src/`.
 *
 * PHP itself never hands an autoloader a name that is not a legal class name, so
 * `class_exists()` cannot reach the guard in seocart.php: a name with a dot or a slash is
 * refused by the engine first. `spl_autoload_call()` has no such check and passes any string
 * on, which is the one way a crafted name arrives, and the way these tests send it.
 *
 * Every other autoloader is suspended meanwhile, so the closure registered by seocart.php is
 * the only one under test.
 *
 * @since 0.1.0
 */
final class AutoloaderTest extends WP_UnitTestCase {

	/**
	 * Tests that traversal is refused without including the file it names.
	 *
	 * Planted violation: remove the whole-name `preg_match()` guard from seocart.php. The
	 * assertion on the canary constant must fail.
	 *
	 * @since 0.1.0
	 */
	public function test_traversal_is_refused_without_loading_the_target(): void {
		$this->assertFalse( $this->existsForThePluginAutoloaderAlone( 'SEOCart\\..\\tests\\Support\\TraversalCanary' ) );
		$this->assertFalse( defined( 'SEOCART_TESTS_TRAVERSAL_CANARY_LOADED' ), 'The autoloader left src/ and required the canary file.' );
	}

	/**
	 * Tests that malformed names are refused without raising a warning.
	 *
	 * PHPUnit converts warnings into test failures, so reaching every assertion proves both
	 * the false result and the absence of a warning.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider malformedClassNames
	 *
	 * @param string $class_name The malformed class name.
	 */
	public function test_malformed_names_are_refused_without_a_warning( string $class_name ): void {
		$this->assertFalse( $this->existsForThePluginAutoloaderAlone( $class_name ) );
	}

	/**
	 * Provides malformed names that begin with the plugin namespace.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}>
	 */
	public function malformedClassNames(): array {
		return array(
			'forward slash'      => array( 'SEOCart\\Platform/Kernel' ),
			'trailing backslash' => array( 'SEOCart\\Platform\\' ),
			'empty segment'      => array( 'SEOCart\\\\Kernel' ),
			'NUL byte'           => array( "SEOCart\\Platform\0Kernel" ),
		);
	}

	/**
	 * Tests that a plain namespaced class still loads.
	 *
	 * @since 0.1.0
	 */
	public function test_plain_class_name_still_loads(): void {
		$this->assertTrue( $this->existsForThePluginAutoloaderAlone( Kernel::class ) );
	}

	/**
	 * Hands a name to the autoloader from seocart.php alone, and restores every other one.
	 *
	 * No assertion runs while the other autoloaders are detached: it would need PHPUnit
	 * classes that only Composer's autoloader can find. A class that is already declared is
	 * not handed over, because the closure requires its file without checking.
	 *
	 * @since 0.1.0
	 *
	 * @param string $class_name The name to hand over. It need not be a legal class name.
	 * @return bool Whether a class of that name exists afterwards.
	 */
	private function existsForThePluginAutoloaderAlone( string $class_name ): bool {
		$fallbacks = array();
		$found     = 0;

		foreach ( spl_autoload_functions() as $autoloader ) {
			if ( $autoloader instanceof \Closure && $this->isPluginAutoloader( $autoloader ) ) {
				++$found;
				continue;
			}

			spl_autoload_unregister( $autoloader );
			$fallbacks[] = $autoloader;
		}

		try {
			if ( ! class_exists( $class_name, false ) ) {
				spl_autoload_call( $class_name );
			}

			$exists = class_exists( $class_name, false );
		} finally {
			foreach ( $fallbacks as $autoloader ) {
				spl_autoload_register( $autoloader );
			}
		}

		$this->assertSame( 1, $found, 'Expected exactly one autoloader closure registered from seocart.php.' );

		return $exists;
	}

	/**
	 * Tells whether a closure was declared in the main plugin file.
	 *
	 * @since 0.1.0
	 *
	 * @param \Closure $autoloader An autoloader closure.
	 * @return bool True for the closure registered by seocart.php.
	 */
	private function isPluginAutoloader( \Closure $autoloader ): bool {
		return realpath( (string) ( new ReflectionFunction( $autoloader ) )->getFileName() )
			=== realpath( dirname( __DIR__, 2 ) . '/seocart.php' );
	}
}

<?php
/**
 * Tests the watch that catches Composer loading a class the plugin should have loaded by itself
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\AutoloaderWatch;
use SEOCart\Tests\Support\PluginOwnership;

/**
 * Proves the watch with real autoloaders and real class files, and no WordPress.
 *
 * Every test builds a throw-away plugin checkout with one shipped class and one test class, a
 * Composer class loader of its own that can find both, and a stand-in for the autoloader of
 * seocart.php. The project's real Composer loader is never handed to the watch, so the test
 * run's own class loading is left alone. A class cannot be unloaded, so the class names carry
 * a random suffix and every test gets classes that no earlier test has loaded.
 *
 * @since 0.1.0
 */
final class AutoloaderWatchTest extends TestCase {

	use TemporaryPluginDirectory;

	/**
	 * A Composer class loader that maps the throw-away checkout the way composer.json maps the real one.
	 *
	 * @since 0.1.0
	 *
	 * @var ClassLoader
	 */
	private ClassLoader $composer;

	/**
	 * The stand-ins for the plugin's autoloader that the test registered.
	 *
	 * @since 0.1.0
	 *
	 * @var list<\Closure(string): void>
	 */
	private array $pluginAutoloaders = array();

	/**
	 * The classes a stand-in for the plugin's autoloader loaded.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $loadedByPlugin = array();

	/**
	 * Name of the shipped class in the throw-away checkout.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $shippedClass;

	/**
	 * Name of the test class in the throw-away checkout.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $harnessClass;

	/**
	 * Creates the checkout and registers a Composer loader for it, in front, where Composer puts its own.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$suffix = bin2hex( random_bytes( 4 ) );

		$this->shippedClass = 'SEOCart\\Watched\\Shipped' . $suffix;
		$this->harnessClass = 'SEOCart\\Tests\\Watched\\Harness' . $suffix;

		$shipped_file = 'src/Watched/Shipped' . $suffix . '.php';
		$harness_file = 'tests/Watched/Harness' . $suffix . '.php';

		$directory = $this->createPluginDirectory(
			array(
				'composer.json' => '{"autoload-dev":{"psr-4":{"SEOCart\\\\Tests\\\\":"tests/"}}}',
				$shipped_file   => "<?php\nnamespace SEOCart\\Watched;\nfinal class Shipped" . $suffix . " {}\n",
				$harness_file   => "<?php\nnamespace SEOCart\\Tests\\Watched;\nfinal class Harness" . $suffix . " {}\n",
			)
		);

		$this->composer = new ClassLoader();
		$this->composer->addPsr4( 'SEOCart\\', $directory . '/src' );
		$this->composer->addPsr4( 'SEOCart\\Tests\\', $directory . '/tests' );
		$this->composer->register( true );
	}

	/**
	 * Removes every autoloader the test registered, and the checkout.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		AutoloaderWatch::stop();

		$this->composer->unregister();

		foreach ( $this->pluginAutoloaders as $autoloader ) {
			spl_autoload_unregister( $autoloader );
		}

		$this->removePluginDirectory();

		parent::tearDown();
	}

	/**
	 * Registers a stand-in for the autoloader of seocart.php, and starts the watch behind it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $base The directory the stand-in maps the plugin namespace to: 'src' is right,
	 *                     anything else is an autoloader that finds nothing.
	 */
	private function loadPluginThenStartWatch( string $base ): void {
		$autoloader = function ( string $class_name ) use ( $base ): void {
			$file = $this->pluginDirectory . '/' . $base . '/' . str_replace( '\\', '/', substr( $class_name, 8 ) ) . '.php';

			if ( 0 === strncmp( $class_name, 'SEOCart\\', 8 ) && is_file( $file ) ) {
				$this->loadedByPlugin[] = $class_name;

				require $file;
			}
		};

		spl_autoload_register( $autoloader );

		$this->pluginAutoloaders[] = $autoloader;

		AutoloaderWatch::start( array( $this->composer ), PluginOwnership::fromComposerManifest( $this->pluginDirectory ) );
	}

	/**
	 * Tests that a plugin class is recorded when the plugin's autoloader cannot find it and Composer can.
	 *
	 * @since 0.1.0
	 */
	public function test_a_plugin_class_that_only_composer_finds_is_recorded(): void {
		$this->loadPluginThenStartWatch( 'source' );

		$this->assertTrue( class_exists( $this->shippedClass ), 'Composer still loads the class, which is why nothing else would notice.' );
		$this->assertSame( array( $this->shippedClass ), AutoloaderWatch::missed() );
	}

	/**
	 * Tests that the plugin's autoloader is asked before Composer, and that what it loads is not recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_plugin_class_the_plugin_loads_by_itself_is_not_recorded(): void {
		$this->loadPluginThenStartWatch( 'src' );

		$this->assertTrue( class_exists( $this->shippedClass ) );
		$this->assertSame( array( $this->shippedClass ), $this->loadedByPlugin, 'Composer was registered in front; the watch must have moved it behind the plugin\'s autoloader.' );
		$this->assertSame( array(), AutoloaderWatch::missed() );
	}

	/**
	 * Tests that test code, which only Composer is meant to load, is not recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_test_class_loaded_by_composer_is_not_recorded(): void {
		$this->loadPluginThenStartWatch( 'src' );

		$this->assertTrue( class_exists( $this->harnessClass ) );
		$this->assertSame( array(), AutoloaderWatch::missed() );
	}

	/**
	 * Tests that asking for a plugin class that does not exist anywhere is not recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_plugin_class_nobody_can_find_is_not_recorded(): void {
		$this->loadPluginThenStartWatch( 'src' );

		$this->assertFalse( class_exists( $this->shippedClass . 'Absent' ) );
		$this->assertSame( array(), AutoloaderWatch::missed() );
	}

	/**
	 * Tests that the watch reports whether it is registered, and that starting it again forgets the earlier record.
	 *
	 * @since 0.1.0
	 */
	public function test_the_watch_says_whether_it_is_watching_and_starts_with_an_empty_record(): void {
		$this->assertFalse( AutoloaderWatch::isWatching() );

		$this->loadPluginThenStartWatch( 'source' );

		$this->assertTrue( AutoloaderWatch::isWatching() );
		$this->assertTrue( class_exists( $this->shippedClass ) );
		$this->assertCount( 1, AutoloaderWatch::missed() );

		AutoloaderWatch::stop();

		$this->assertFalse( AutoloaderWatch::isWatching() );

		$this->loadPluginThenStartWatch( 'source' );

		$this->assertSame( array(), AutoloaderWatch::missed() );
	}
}

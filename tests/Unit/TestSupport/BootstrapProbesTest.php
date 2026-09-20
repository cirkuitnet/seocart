<?php
/**
 * Tests the file and hook probes on data, with no WordPress involved
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\BootstrapProbes;
use SEOCart\Tests\Support\PluginOwnership;

/**
 * Proves what the probes count, and what they print when a budget breaks.
 *
 * @since 0.1.0
 */
final class BootstrapProbesTest extends TestCase {

	use TemporaryPluginDirectory;

	/**
	 * The probes under test, for the temporary plugin directory.
	 *
	 * @since 0.1.0
	 *
	 * @var BootstrapProbes
	 */
	private BootstrapProbes $probes;

	/**
	 * Creates a plugin directory whose files have known sizes.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$directory = $this->createPluginDirectory(
			array(
				'seocart.php'         => str_pad( '<?php', 10 ),
				'src/Kernel.php'      => str_pad( '<?php', 2048 ),
				'src/hooks.php'       => "<?php\n\nreturn static function () {};",
				'tests/hooks.php'     => '<?php return static function () {};',
				'tests/bootstrap.php' => str_pad( '<?php', 4096 ),
				'vendor/autoload.php' => str_pad( '<?php', 8192 ),
			)
		);

		$this->probes = new BootstrapProbes( new PluginOwnership( $directory, array( 'SEOCart\\Tests\\' => 'tests/' ) ) );
	}

	/**
	 * Removes the plugin directory.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		$this->removePluginDirectory();

		parent::tearDown();
	}

	/**
	 * Tests that only shipped plugin files are listed, by relative path, with their size in bytes.
	 *
	 * @since 0.1.0
	 */
	public function test_plugin_files_lists_shipped_files_with_their_sizes(): void {
		$directory = $this->pluginDirectory;

		$files = $this->probes->pluginFiles(
			array(
				'/wordpress/wp-settings.php',
				$directory . '/vendor/autoload.php',
				$directory . '/tests/bootstrap.php',
				$directory . '/src/Kernel.php',
				$directory . '/seocart.php',
			)
		);

		$this->assertSame(
			array(
				'seocart.php'    => 10,
				'src/Kernel.php' => 2048,
			),
			$files
		);
	}

	/**
	 * Tests that the plugin's registrations are picked out of a hook table of plain arrays.
	 *
	 * @since 0.1.0
	 */
	public function test_plugin_hooks_lists_the_registrations_that_belong_to_the_plugin(): void {
		$hooks = $this->probes->pluginHooks( $this->hookTable() );

		$this->assertSame(
			array(
				array(
					'hook'     => 'plugins_loaded',
					'priority' => 10,
					'callback' => 'SEOCart\\Platform\\Kernel\\Kernel::boot',
				),
				array(
					'hook'     => 'admin_notices',
					'priority' => 10,
					'callback' => 'seocart_render_requirements_notice',
				),
				array(
					'hook'     => 'init',
					'priority' => 5,
					'callback' => 'closure at src/hooks.php:3',
				),
			),
			$hooks
		);
	}

	/**
	 * Tests that a hook held in an object with a public `callbacks` property, as WP_Hook is, reads the same.
	 *
	 * @since 0.1.0
	 */
	public function test_plugin_hooks_reads_hook_objects_like_plain_arrays(): void {
		$as_objects = array();

		foreach ( $this->hookTable() as $hook => $priorities ) {
			$as_objects[ $hook ] = (object) array( 'callbacks' => $priorities );
		}

		$this->assertSame( $this->probes->pluginHooks( $this->hookTable() ), $this->probes->pluginHooks( $as_objects ) );
	}

	/**
	 * Tests that one callback registered at two priorities is two registrations.
	 *
	 * @since 0.1.0
	 */
	public function test_plugin_hooks_counts_each_priority_as_a_registration(): void {
		$registration = array(
			'function'      => 'seocart_flush',
			'accepted_args' => 1,
		);

		$hooks = $this->probes->pluginHooks(
			array(
				'shutdown' => array(
					10 => array( 'seocart_flush' => $registration ),
					20 => array( 'seocart_flush' => $registration ),
				),
			)
		);

		$this->assertCount( 2, $hooks );
	}

	/**
	 * Tests the name given to each form of callback.
	 *
	 * @since 0.1.0
	 */
	public function test_describe_callback_names_each_form_of_callback(): void {
		$this->assertSame( 'seocart_flush', $this->probes->describeCallback( 'seocart_flush' ) );
		$this->assertSame( 'SEOCart\\Foo::bar', $this->probes->describeCallback( array( 'SEOCart\\Foo', 'bar' ) ) );
		$this->assertSame( self::class . '->setUp', $this->probes->describeCallback( array( $this, 'setUp' ) ) );
		$this->assertSame( 'closure at src/hooks.php:3', $this->probes->describeCallback( require $this->pluginDirectory . '/src/hooks.php' ) );
		$this->assertSame( 'ArrayObject (invokable)', $this->probes->describeCallback( new \ArrayObject() ) );
		$this->assertSame( '(unrecognized callback)', $this->probes->describeCallback( 42 ) );
	}

	/**
	 * Tests the report printed when a file budget breaks.
	 *
	 * @since 0.1.0
	 */
	public function test_describe_files_prints_each_file_and_the_total(): void {
		$this->assertSame(
			"        10 bytes  seocart.php\n"
			. "      2048 bytes  src/Kernel.php\n"
			. '      2058 bytes  in 2 files',
			BootstrapProbes::describeFiles(
				array(
					'seocart.php'    => 10,
					'src/Kernel.php' => 2048,
				)
			)
		);
	}

	/**
	 * Tests the report printed when the hook budget breaks.
	 *
	 * @since 0.1.0
	 */
	public function test_describe_hooks_prints_each_registration(): void {
		$this->assertSame( '  (no registrations)', BootstrapProbes::describeHooks( array() ) );

		$this->assertSame(
			"  plugins_loaded @10  SEOCart\\Platform\\Kernel\\Kernel::boot\n"
			. '  init @5  closure at src/hooks.php:3',
			BootstrapProbes::describeHooks(
				array(
					array(
						'hook'     => 'plugins_loaded',
						'priority' => 10,
						'callback' => 'SEOCart\\Platform\\Kernel\\Kernel::boot',
					),
					array(
						'hook'     => 'init',
						'priority' => 5,
						'callback' => 'closure at src/hooks.php:3',
					),
				)
			)
		);
	}

	/**
	 * Builds a hook table shaped like `$wp_filter`: three registrations by the plugin among four by others.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<int, array<string, array{function: mixed, accepted_args: int}>>> The hook table.
	 */
	private function hookTable(): array {
		$entry = static function ( $callback ): array {
			return array(
				'function'      => $callback,
				'accepted_args' => 1,
			);
		};

		return array(
			'plugins_loaded' => array(
				0  => array(
					'wp_maybe_load_widgets' => $entry( 'wp_maybe_load_widgets' ),
				),
				10 => array(
					'kernel' => $entry( array( 'SEOCart\\Platform\\Kernel\\Kernel', 'boot' ) ),
					'other'  => $entry( array( 'Acme\\Plugin', 'boot' ) ),
				),
			),
			'admin_notices'  => array(
				10 => array(
					'seocart_render_requirements_notice' => $entry( 'seocart_render_requirements_notice' ),
				),
			),
			'init'           => array(
				5  => array(
					'shipped' => $entry( require $this->pluginDirectory . '/src/hooks.php' ),
				),
				20 => array(
					'harness' => $entry( require $this->pluginDirectory . '/tests/hooks.php' ),
					'test'    => $entry( array( $this, 'setUp' ) ),
				),
			),
		);
	}
}

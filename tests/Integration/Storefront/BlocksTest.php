<?php
/**
 * Tests how the storefront blocks are registered with WordPress
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Storefront;

use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Storefront\BlockRenderer;
use SEOCart\Platform\Storefront\Blocks;
use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * Every storefront block is in WordPress's registry once `init` has run: dynamic, interactive,
 * offered by the editor through WordPress's own render, and loading no file of its own; and
 * registering it builds none of the renderers, which are resolved when a block renders.
 *
 * The kernel registered the blocks when the suite booted WordPress. The registration test takes
 * them out of the registry, registers them again over a container that records what it builds,
 * and puts the kernel's back afterwards.
 *
 * Planted violation, shown red and removed: in Blocks::register(), resolve the renderer before
 * the callback, `$built = $container->get( $renderer );`, and call `$built->render()` in it; the
 * registration test then finds the renderers built before any block rendered.
 *
 * @since 0.2.0
 */
final class BlocksTest extends WP_UnitTestCase {

	/**
	 * The kernel's registrations, taken out by a test and put back after it.
	 *
	 * @since 0.2.0
	 *
	 * @var list<WP_Block_Type>
	 */
	private array $kernels = array();

	/**
	 * Puts back the kernel's registrations a test took out.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( $this->kernels as $type ) {
			if ( $registry->is_registered( $type->name ) ) {
				$registry->unregister( $type->name );
			}

			$registry->register( $type );
		}

		$this->kernels = array();

		parent::tear_down();
	}

	/**
	 * Tests that each block is registered after `init`, with a render callback, the editor's automatic registration, interactivity, and no script, module or stylesheet.
	 *
	 * @since 0.2.0
	 */
	public function test_each_block_is_registered_dynamic_and_without_files_of_its_own(): void {
		$this->assertGreaterThan( 0, did_action( 'init' ), 'WordPress has not run init, so the registry proves nothing.' );

		foreach ( array_keys( Modules::STOREFRONT_BLOCKS ) as $name ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

			$this->assertInstanceOf( WP_Block_Type::class, $type, "{$name} is not registered after init." );
			$this->assertTrue( $type->is_dynamic(), "{$name} has no render callback." );
			$this->assertTrue( $type->supports['autoRegister'] ?? false, "{$name} is not offered by the editor through WordPress's own render." );
			$this->assertTrue( $type->supports['interactivity'] ?? false, "{$name} does not declare interactivity, so WordPress would not process its directives." );
			$this->assertSame( array( 'postId', 'postType' ), $type->get_uses_context(), "{$name}'s context" );

			foreach ( array( 'editor_script_handles', 'script_handles', 'view_script_handles', 'view_script_module_ids', 'style_handles', 'editor_style_handles', 'view_style_handles' ) as $files ) {
				$this->assertSame( array(), $type->{$files}, "{$name} names files of its own in {$files}." );
			}
		}
	}

	/**
	 * Tests that registering the blocks builds none of their renderers, and that rendering a block builds its own and no other.
	 *
	 * @since 0.2.0
	 */
	public function test_registering_builds_no_renderer(): void {
		$built     = array();
		$overrides = array();
		$registry  = WP_Block_Type_Registry::get_instance();

		foreach ( Modules::STOREFRONT_BLOCKS as $name => $renderer ) {
			$overrides[ $renderer ] = static function () use ( &$built, $renderer ): BlockRenderer {
				$built[] = $renderer;

				return Kernel::container()->get( $renderer );
			};

			$this->kernels[] = $registry->unregister( $name );
		}

		Blocks::register( Modules::STOREFRONT_BLOCKS, new Container( $overrides ) );

		$this->assertSame( array(), $built, 'Registering the blocks built their renderers.' );

		$first = array_key_first( Modules::STOREFRONT_BLOCKS );

		render_block(
			array(
				'blockName'    => $first,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		$this->assertSame( array( Modules::STOREFRONT_BLOCKS[ $first ] ), $built, 'Rendering one block built another block\'s renderer, or none.' );
	}
}

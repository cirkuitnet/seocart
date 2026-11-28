<?php
/**
 * Tests that the storefront's list of blocks and their block.json files are one list
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Storefront;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Storefront\Blocks;

/**
 * Holds Modules::STOREFRONT_BLOCKS and the block.json files under src/Platform/Storefront/blocks/
 * equal, and each file to what a storefront block is.
 *
 * The list names each block's renderer, a BlockRenderer by its declared type, which static
 * analysis holds; the files are what WordPress registers. A block in the
 * list without a file would fail to register, and a file without an entry would never be
 * registered, so the two are compared in both directions. A storefront block names no script,
 * module, stylesheet or render file in its block.json: the renderer enqueues what the block needs
 * while it renders, and the build writes no asset file WordPress could read beside the block.
 *
 * Planted violations, each shown red and removed: add `"viewScriptModule": "file:./view.js"` to
 * one block.json (the member test); add a fourth entry to STOREFRONT_BLOCKS with no file (the
 * list test, the list's side); copy one folder under blocks/ as `planted/` with the name
 * `seocart/planted` (the list test, the files' side).
 *
 * @since 0.2.0
 */
final class BlockListTest extends TestCase {

	/**
	 * The block.json members a storefront block never has: each would load a file the renderer loads instead, or replace the renderer.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const NO_ASSET_MEMBERS = array( 'editorScript', 'script', 'viewScript', 'viewScriptModule', 'style', 'editorStyle', 'render' );

	/**
	 * The block categories WordPress itself registers.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const CORE_CATEGORIES = array( 'text', 'media', 'design', 'widgets', 'theme', 'embed' );

	/**
	 * Tests that every block of the list has its file, and every file is a block of the list.
	 *
	 * @since 0.2.0
	 */
	public function test_the_list_and_the_block_files_are_equal_in_both_directions(): void {
		$files = self::blockFiles();
		$named = array_keys( $files );
		$list  = array_keys( Modules::STOREFRONT_BLOCKS );

		$this->assertNotSame( array(), $files, 'No block.json file was found under src/Platform/Storefront/blocks/, so an equal list would prove nothing.' );
		$this->assertSame( array(), array_values( array_diff( $list, $named ) ), 'These blocks are in Modules::STOREFRONT_BLOCKS but have no block.json file under src/Platform/Storefront/blocks/.' );
		$this->assertSame( array(), array_values( array_diff( $named, $list ) ), 'These block.json files name a block that is not in Modules::STOREFRONT_BLOCKS, so nothing registers them.' );

		foreach ( $files as $name => $file ) {
			$this->assertSame( realpath( Blocks::folder( $name ) . '/block.json' ), $file, "The block.json file of {$name} is not in the folder named after it, where Blocks::register() looks." );
		}
	}

	/**
	 * Tests that no block.json file names a script, a module, a stylesheet or a render file.
	 *
	 * @since 0.2.0
	 */
	public function test_no_block_file_names_an_asset_or_a_render_file(): void {
		foreach ( self::blockFiles() as $name => $file ) {
			$this->assertSame( array(), array_values( array_intersect( self::NO_ASSET_MEMBERS, array_keys( self::decode( $file ) ) ) ), "The block.json file of {$name} names a file its renderer must load instead." );
		}
	}

	/**
	 * Tests that every block.json file declares what a storefront block is: a dynamic block of the plugin's text domain, in a core category, interactive, offered by the editor through WordPress's own render, with no attribute it requires.
	 *
	 * @since 0.2.0
	 */
	public function test_every_block_file_declares_a_storefront_block(): void {
		foreach ( self::blockFiles() as $name => $file ) {
			$block = self::decode( $file );

			$this->assertSame( 3, $block['apiVersion'] ?? null, "{$name}: apiVersion" );
			$this->assertSame( 'seocart', $block['textdomain'] ?? null, "{$name}: textdomain" );
			$this->assertContains( $block['category'] ?? null, self::CORE_CATEGORIES, "{$name}: a core category" );
			$this->assertNotSame( '', (string) ( $block['title'] ?? '' ), "{$name}: title" );
			$this->assertNotSame( '', (string) ( $block['description'] ?? '' ), "{$name}: description" );
			$this->assertSame( array( 'postId', 'postType' ), $block['usesContext'] ?? null, "{$name}: usesContext" );
			$this->assertSame(
				array(
					'html'          => false,
					'interactivity' => true,
					'autoRegister'  => true,
				),
				$block['supports'] ?? null,
				"{$name}: supports"
			);

			foreach ( (array) ( $block['attributes'] ?? array() ) as $attribute => $definition ) {
				$this->assertArrayNotHasKey( 'required', (array) $definition, "{$name}: the attribute {$attribute} is a presentation attribute, which a block never requires." );
			}
		}
	}

	/**
	 * Returns the block.json files under src/Platform/Storefront/blocks/, keyed by the block each names.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The files' real paths, keyed by block name.
	 */
	private static function blockFiles(): array {
		$files = array();

		foreach ( (array) glob( dirname( __DIR__, 4 ) . '/src/Platform/Storefront/blocks/*/block.json' ) as $file ) {
			$files[ (string) ( self::decode( (string) $file )['name'] ?? $file ) ] = (string) realpath( (string) $file );
		}

		ksort( $files );

		return $files;
	}

	/**
	 * Reads one block.json file.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The file.
	 * @return array<string, mixed> Its members.
	 */
	private static function decode( string $file ): array {
		return (array) json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
	}
}

<?php
/**
 * Tests that the storefront files the build writes and the files its loader asks for are one list
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Storefront;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Storefront\StorefrontAssets;

/**
 * Holds the build's entries under blocks/ (STOREFRONT_MODULES in webpack.config.js) equal to what
 * StorefrontAssets loads, each module's file and the stylesheet's, and each module the build leaves
 * out of an importer's bundle (STOREFRONT_EXTERNALS) to a module the loader registers.
 *
 * An entry the loader never asks for is a file built for nothing; a file the loader asks for that
 * the build does not write is a module no page can load; a module left out of a bundle that the
 * loader does not register is an import the page's import map cannot resolve. The build's two lists
 * are read from the text of webpack.config.js, which only the build runs.
 *
 * Planted violations, each shown red and removed: add `'blocks/planted': join( ASSETS, 'blocks',
 * 'store', 'index.js' ),` to STOREFRONT_MODULES (the entry test); add `'@seocart/planted'` to
 * STOREFRONT_EXTERNALS (the externals test).
 *
 * @since 0.2.0
 */
final class BuildEntriesTest extends TestCase {

	/**
	 * Tests that the build writes under blocks/ exactly the files the loader asks for.
	 *
	 * @since 0.2.0
	 */
	public function test_the_build_writes_the_files_the_loader_asks_for(): void {
		$loaded = array_merge( array_values( StorefrontAssets::MODULES ), array( StorefrontAssets::STYLESHEET ) );
		$built  = array_values( array_filter( self::listed( 'STOREFRONT_MODULES', '/\'([a-z\/-]+)\'\s*:/' ), static fn( string $entry ): bool => str_starts_with( $entry, 'blocks/' ) ) );

		sort( $loaded );
		sort( $built );

		$this->assertNotSame( array(), $built, 'No entry under blocks/ was read from webpack.config.js, so equal lists would prove nothing.' );
		$this->assertSame( $loaded, $built, 'webpack.config.js and StorefrontAssets name different storefront files.' );
	}

	/**
	 * Tests that every module the build leaves out of a bundle is one the loader registers.
	 *
	 * @since 0.2.0
	 */
	public function test_every_module_left_out_of_a_bundle_is_one_the_loader_registers(): void {
		$externals = self::listed( 'STOREFRONT_EXTERNALS', '/\'(@[a-z\/-]+)\'/' );

		$this->assertNotSame( array(), $externals, 'No module was read from STOREFRONT_EXTERNALS, so the test would prove nothing.' );
		$this->assertSame( array(), array_values( array_diff( $externals, array_keys( StorefrontAssets::MODULES ) ) ), 'webpack.config.js leaves a module out of a bundle that StorefrontAssets does not register.' );
	}

	/**
	 * Returns what one constant of webpack.config.js lists.
	 *
	 * @since 0.2.0
	 *
	 * @param string $constant The constant's name.
	 * @param string $pattern  The pattern of one item; its first group is the item.
	 * @return string[] The items, in order; none when the constant is not found.
	 */
	private static function listed( string $constant, string $pattern ): array {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/webpack.config.js' );

		if ( 1 !== preg_match( '/const ' . $constant . ' = [\[{](.*?)[\]}];/s', $source, $match ) ) {
			return array();
		}

		preg_match_all( $pattern, $match[1], $items );

		return $items[1];
	}
}

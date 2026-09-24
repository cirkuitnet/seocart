<?php
/**
 * Tests the scan for literal `seocart_` filter and action calls
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Docs\HookLiteralScan;

/**
 * A fixture tree, held entirely in memory: one file per shape the scan must recognize, plus two
 * that must report nothing (a hook that is not `seocart_`, and a call whose name is a constant).
 *
 * @since 0.1.0
 */
final class HookLiteralScanTest extends TestCase {

	/**
	 * Tests that the scan finds a clean single-quoted literal, an interpolated double-quoted one,
	 * a `_ref_array` call, and a concatenated one, and reports nothing for the other two files.
	 *
	 * @since 0.1.0
	 */
	public function test_it_finds_each_shape_in_a_fixture_tree(): void {
		$sources = array(
			'fixture/single-quoted.php'       => "<?php\napply_filters( 'seocart_static_one', true );\n",
			'fixture/double-interpolated.php' => "<?php\n\$suffix = 'x';\ndo_action( \"seocart_dynamic_{\$suffix}\" );\n",
			'fixture/ref-array.php'           => "<?php\napply_filters_ref_array( 'seocart_ref_array_hook', array( \$a ) );\n",
			'fixture/concatenated.php'        => "<?php\n\$suffix = 'y';\ndo_action( 'seocart_concat_' . \$suffix );\n",
			'fixture/other-plugin.php'        => "<?php\napply_filters( 'other_plugin_hook', true );\n",
			'fixture/from-a-constant.php'     => "<?php\napply_filters( SomeDeclaration::NAME, true );\n",
		);

		$found = HookLiteralScan::find( $sources );

		$this->assertSame(
			array(
				array(
					'file'    => 'fixture/single-quoted.php',
					'hook'    => 'seocart_static_one',
					'dynamic' => false,
				),
				array(
					'file'    => 'fixture/double-interpolated.php',
					'hook'    => 'seocart_dynamic_…',
					'dynamic' => true,
				),
				array(
					'file'    => 'fixture/ref-array.php',
					'hook'    => 'seocart_ref_array_hook',
					'dynamic' => false,
				),
				array(
					'file'    => 'fixture/concatenated.php',
					'hook'    => 'seocart_concat_…',
					'dynamic' => true,
				),
			),
			$found,
			'The scan must find exactly these four calls; a hook that is not seocart_ and a call built from a constant report nothing.'
		);
	}

	/**
	 * Tests that a `do_action_deprecated()` call with a plain literal is found like any other.
	 *
	 * @since 0.1.0
	 */
	public function test_it_finds_a_deprecated_variant(): void {
		$found = HookLiteralScan::find(
			array(
				'fixture/deprecated.php' => "<?php\ndo_action_deprecated( 'seocart_old_hook', array(), '0.2.0' );\n",
			)
		);

		$this->assertSame(
			array(
				array(
					'file'    => 'fixture/deprecated.php',
					'hook'    => 'seocart_old_hook',
					'dynamic' => false,
				),
			),
			$found
		);
	}
}

<?php
/**
 * Tests that no tracked file cites the maintainers' private design and planning material
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Docs\PrivateReferences;

/**
 * Keeps the public repository self-contained.
 *
 * What counts as a citation, and how the tracked files are read, is
 * tools/Docs/PrivateReferences.php; its self-test is tools/Docs/Tests/PrivateReferencesTest.php.
 * Every SEOCart extension runs the same check over its own checkout.
 *
 * @since 0.1.0
 */
final class PrivateReferencesTest extends TestCase {

	/**
	 * Tests that no tracked text file cites private material.
	 *
	 * @since 0.1.0
	 */
	public function test_no_tracked_file_cites_private_material(): void {
		$found = PrivateReferences::findIn( dirname( __DIR__, 3 ) );

		$this->assertSame( array(), $found, PrivateReferences::explain( $found ) );
	}
}

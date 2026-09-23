<?php
/**
 * Tests the order of the log levels
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Logging;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Logging\Level;

/**
 * A level reaches every minimum at or below it, and none above it.
 *
 * Planted violation: in Level::rank(), give Warning the rank of Error.
 *
 * @since 0.1.0
 */
final class LevelTest extends TestCase {

	/**
	 * Tests every pair of levels against the order debug, info, warning, error.
	 *
	 * @since 0.1.0
	 */
	public function test_the_levels_are_ordered_from_debug_to_error(): void {
		$order = array( Level::Debug, Level::Info, Level::Warning, Level::Error );

		$this->assertSame( Level::cases(), $order, 'The cases are declared in their order.' );

		foreach ( $order as $i => $level ) {
			foreach ( $order as $j => $minimum ) {
				$this->assertSame( $i >= $j, Level::reaches( $level, $minimum ), sprintf( '%s against a minimum of %s', $level->value, $minimum->value ) );
			}
		}
	}
}

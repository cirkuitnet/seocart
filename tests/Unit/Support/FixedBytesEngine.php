<?php
/**
 * FixedBytesEngine: a random engine that always returns the same bytes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use Random\Engine;

/**
 * A Random\Engine that returns the same eight bytes every time.
 *
 * All zero bytes make every draw from a power-of-two range its minimum; all 0xFF bytes make it
 * its maximum. That pins the random fields of a version 7 UUID, so a test can spell out the
 * identifier it expects and force the counter to run out within one millisecond.
 *
 * @since 0.1.0
 */
final class FixedBytesEngine implements Engine {

	/**
	 * The bytes returned by every call.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $bytes;

	/**
	 * Creates the engine.
	 *
	 * @since 0.1.0
	 *
	 * @param string $byte The byte to repeat, for example "\x00" or "\xFF".
	 */
	public function __construct( string $byte ) {
		$this->bytes = str_repeat( $byte, 8 );
	}

	/**
	 * Returns the fixed bytes.
	 *
	 * @since 0.1.0
	 *
	 * @return string Eight bytes.
	 */
	public function generate(): string {
		return $this->bytes;
	}
}

<?php
/**
 * RecordingWake: a publisher wake that counts its calls and never drains
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

/**
 * Stands in for OutboxDrainer::scheduleAtShutdown() in tests.
 *
 * Owns one fact: how often a publisher woke a drainer. It never drains, which is also how a
 * test plays a process that dies right after COMMIT; so PHPUnit's own process never drains at
 * its shutdown, and a test calls drain() when it wants delivery.
 *
 * @since 0.1.0
 */
final class RecordingWake {

	/**
	 * How many times the wake ran.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $calls = 0;

	/**
	 * Records one wake.
	 *
	 * @since 0.1.0
	 */
	public function __invoke(): void {
		++$this->calls;
	}

	/**
	 * Returns how many times the wake ran.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	public function calls(): int {
		return $this->calls;
	}
}

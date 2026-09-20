<?php
/**
 * FrozenClock: a clock that shows one instant until a test moves it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Support\Clock;

/**
 * A Clock that never moves on its own.
 *
 * Time passes only when the test says so, which is how an expiry rule is tested without
 * sleeping: freeze, act, advance past the deadline, act again.
 *
 * @since 0.1.0
 */
final class FrozenClock implements Clock {

	/**
	 * The instant the clock shows, in UTC.
	 *
	 * @since 0.1.0
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $instant;

	/**
	 * Freezes the clock at an instant.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $instant The instant to show. Any time zone; it is converted to UTC.
	 */
	public function __construct( \DateTimeImmutable $instant ) {
		$this->setTo( $instant );
	}

	/**
	 * Freezes a clock at an instant written as a string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $instant A date and time that PHP can parse, for example '2026-01-15 12:00:00'.
	 *                        It is read as UTC unless it names another zone or offset itself.
	 * @return self The frozen clock.
	 */
	public static function at( string $instant ): self {
		return new self( new \DateTimeImmutable( $instant, new \DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Returns the frozen instant.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The instant the clock shows, in UTC.
	 */
	public function now(): \DateTimeImmutable {
		return $this->instant;
	}

	/**
	 * Moves the clock to another instant.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $instant The instant to show from now on. It is converted to UTC.
	 */
	public function setTo( \DateTimeImmutable $instant ): void {
		$this->instant = $instant->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Moves the clock forward.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateInterval $interval How far to move.
	 */
	public function advance( \DateInterval $interval ): void {
		$this->instant = $this->instant->add( $interval );
	}
}

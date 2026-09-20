<?php
/**
 * SequenceClock: a clock that shows a scripted list of instants, one per reading
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Support\Clock;

/**
 * A Clock that returns the next scripted instant each time it is read.
 *
 * Use it when the code under test reads the time more than once and the readings must
 * differ, for example to prove that two rows are ordered by their creation time. Reading
 * the clock more often than the script allows is an error, not a repeat of the last
 * instant: a test that did not expect the extra reading should fail.
 *
 * @since 0.1.0
 */
final class SequenceClock implements Clock {

	/**
	 * The scripted instants, in UTC, in the order they are returned.
	 *
	 * @since 0.1.0
	 *
	 * @var list<\DateTimeImmutable>
	 */
	private array $instants = array();

	/**
	 * How many instants have been returned so far.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Scripts the clock.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable ...$instants The instants to return, in order. Each is converted to UTC.
	 */
	public function __construct( \DateTimeImmutable ...$instants ) {
		$utc = new \DateTimeZone( 'UTC' );

		foreach ( $instants as $instant ) {
			$this->instants[] = $instant->setTimezone( $utc );
		}
	}

	/**
	 * Returns the next scripted instant.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When every scripted instant has already been returned.
	 *
	 * @return \DateTimeImmutable The next instant, in UTC.
	 */
	public function now(): \DateTimeImmutable {
		if ( ! isset( $this->instants[ $this->position ] ) ) {
			throw new \LogicException(
				sprintf(
					'SequenceClock was read %d times but only %d instants were scripted.',
					$this->position + 1,
					count( $this->instants )
				)
			);
		}

		return $this->instants[ $this->position++ ];
	}
}

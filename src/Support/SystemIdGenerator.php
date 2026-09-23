<?php
/**
 * SystemIdGenerator: the production IdGenerator, minting version 7 UUIDs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

use Random\Randomizer;

defined( 'ABSPATH' ) || exit;

/**
 * The IdGenerator that production code runs with: RFC 9562 version 7 UUIDs.
 *
 * This class owns one fact: how a public identifier is laid out and kept in order. Each
 * identifier is 128 bits, written as 36 lower-case characters grouped 8-4-4-4-12:
 *
 * - 48 bits: the Unix time in milliseconds, read from the injected Clock;
 * - 4 bits: the version, 7;
 * - 12 bits (rand_a) and, after the 2 variant bits 10, 62 bits (rand_b): random.
 *
 * Identifiers from one generator are strictly increasing, so they sort in the order they were
 * minted (RFC 9562 §6.2, method 2). In a new millisecond, rand_a and rand_b are drawn afresh.
 * Within the same millisecond — or when the clock steps backwards — the previous timestamp is
 * kept and the 74 random bits, read as one counter, grow by a random step from 1 to 2^31, so
 * a neighbour's identifier cannot be guessed by adding one. If the counter ever ran out, the
 * generator would move its timestamp one millisecond ahead of the clock rather than repeat
 * or go backwards.
 *
 * Randomness comes from a Randomizer, by default PHP's cryptographically secure engine; a test
 * passes a seeded one. Nothing is a float: the millisecond count is read as an integer from
 * the clock's formatted time.
 *
 * @since 0.1.0
 */
final class SystemIdGenerator implements IdGenerator {

	/**
	 * The largest timestamp 48 bits hold, in milliseconds since 1970: in the year 10889.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAXIMUM_TIMESTAMP = 0xFFFFFFFFFFFF;

	/**
	 * The largest value of rand_a, 12 bits.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAXIMUM_RANDOM_A = 0xFFF;

	/**
	 * The largest value of rand_b, 62 bits.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAXIMUM_RANDOM_B = 0x3FFFFFFFFFFFFFFF;

	/**
	 * The largest random part of a step within one millisecond; a step is one more than it.
	 *
	 * The range from 0 to this value is a power of two, so the Randomizer never has to reject
	 * and redraw a value.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAXIMUM_RANDOM_STEP = 0x7FFFFFFF;

	/**
	 * The clock the timestamp is read from.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * The source of the random bits.
	 *
	 * @since 0.1.0
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * The timestamp of the last identifier, or -1 before the first.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $timestamp = -1;

	/**
	 * The rand_a field of the last identifier.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $randomA = 0;

	/**
	 * The rand_b field of the last identifier.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $randomB = 0;

	/**
	 * Creates a generator.
	 *
	 * @since 0.1.0
	 *
	 * @param Clock           $clock  The clock the timestamp is read from.
	 * @param Randomizer|null $random Optional. The source of the random bits. Default a
	 *                                Randomizer on PHP's cryptographically secure engine.
	 */
	public function __construct( Clock $clock, ?Randomizer $random = null ) {
		$this->clock  = $clock;
		$this->random = $random ?? new Randomizer();
	}

	/**
	 * Mints a new identifier, greater than every identifier this generator minted before.
	 *
	 * @since 0.1.0
	 *
	 * @return string A lowercase version 7 UUID, for example `0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b`.
	 */
	public function generate(): string {
		$now = $this->milliseconds();

		if ( $now > $this->timestamp ) {
			$this->startMillisecond( $now );
		} else {
			$this->advance();
		}

		$time = sprintf( '%012x', $this->timestamp );
		$tail = sprintf( '%x%015x', 8 | ( $this->randomB >> 60 ), $this->randomB & 0x0FFFFFFFFFFFFFFF );

		return sprintf(
			'%s-%s-7%03x-%s-%s',
			substr( $time, 0, 8 ),
			substr( $time, 8 ),
			$this->randomA,
			substr( $tail, 0, 4 ),
			substr( $tail, 4 )
		);
	}

	/**
	 * Reads the clock as a whole number of milliseconds since 1970.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the clock reads a time a version 7 UUID cannot hold.
	 *
	 * @return int The Unix time in milliseconds.
	 */
	private function milliseconds(): int {
		$milliseconds = (int) $this->clock->now()->format( 'Uv' );

		if ( $milliseconds < 0 || $milliseconds > self::MAXIMUM_TIMESTAMP ) {
			throw new \LogicException( 'A version 7 UUID holds a time from 1970 to the year 10889; the clock read a time outside that range.' );
		}

		return $milliseconds;
	}

	/**
	 * Starts a millisecond with fresh random bits.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the timestamp no longer fits 48 bits.
	 *
	 * @param int $timestamp The Unix time in milliseconds.
	 */
	private function startMillisecond( int $timestamp ): void {
		if ( $timestamp > self::MAXIMUM_TIMESTAMP ) {
			throw new \LogicException( 'A version 7 UUID timestamp ran past the year 10889.' );
		}

		$this->timestamp = $timestamp;
		$this->randomA   = $this->random->getInt( 0, self::MAXIMUM_RANDOM_A );
		$this->randomB   = $this->random->getInt( 0, self::MAXIMUM_RANDOM_B );
	}

	/**
	 * Moves the 74-bit counter formed by rand_a and rand_b forward by a random step.
	 *
	 * @since 0.1.0
	 */
	private function advance(): void {
		$step = 1 + $this->random->getInt( 0, self::MAXIMUM_RANDOM_STEP );

		if ( $this->randomB <= self::MAXIMUM_RANDOM_B - $step ) {
			$this->randomB += $step;

			return;
		}

		// rand_b wraps: rand_b + step - 2^62, written so that no intermediate exceeds 2^62.
		$this->randomB -= self::MAXIMUM_RANDOM_B - $step + 1;

		if ( $this->randomA < self::MAXIMUM_RANDOM_A ) {
			++$this->randomA;

			return;
		}

		$this->startMillisecond( $this->timestamp + 1 );
	}
}

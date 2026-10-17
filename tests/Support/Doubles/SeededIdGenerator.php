<?php
/**
 * SeededIdGenerator: the platform's id port, giving the same ids for the same seed
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\SystemIdGenerator;

/**
 * Mints the ids the plugin's own generator mints, version 7 UUIDs, from a seed instead of the clock and chance.
 *
 * Owns one fact: which ids a run of a scenario gets. It is the plugin's SystemIdGenerator, given
 * a clock frozen at one instant and a random engine seeded from the text given, so the same seed
 * yields the same sequence of ids in every run, and a request a gateway sends that carries one
 * (an intent's uuid, an order's uuid) is byte-identical each time it is recorded or replayed.
 * Reseeding starts a new sequence: a test seeds it at the start of each scenario.
 *
 * @since 0.2.0
 */
final class SeededIdGenerator implements IdGenerator {

	/**
	 * The instant every id's time field holds.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INSTANT = '2026-01-01T00:00:00Z';

	/**
	 * The generator of the current seed.
	 *
	 * @since 0.2.0
	 *
	 * @var SystemIdGenerator
	 */
	private SystemIdGenerator $ids;

	/**
	 * Starts the sequence of a seed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $seed Any text; the same text gives the same ids.
	 */
	public function __construct( string $seed ) {
		$this->ids = self::generatorOf( $seed );
	}

	/**
	 * Starts the sequence of another seed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $seed Any text; the same text gives the same ids.
	 */
	public function reseed( string $seed ): void {
		$this->ids = self::generatorOf( $seed );
	}

	/**
	 * Returns the next id of the sequence.
	 *
	 * @since 0.2.0
	 *
	 * @return string A lower-case version 7 UUID.
	 */
	public function generate(): string {
		return $this->ids->generate();
	}

	/**
	 * Builds the plugin's own generator over a frozen clock and a seeded engine.
	 *
	 * @since 0.2.0
	 *
	 * @param string $seed The seed.
	 * @return SystemIdGenerator The generator.
	 */
	private static function generatorOf( string $seed ): SystemIdGenerator {
		return new SystemIdGenerator( FrozenClock::at( self::INSTANT ), new Randomizer( new Xoshiro256StarStar( hash( 'sha256', $seed, true ) ) ) );
	}
}

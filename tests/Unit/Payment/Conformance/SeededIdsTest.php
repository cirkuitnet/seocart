<?php
/**
 * Tests the seeded id port the conformance suite runs on
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\Doubles\SeededIdGenerator;

/**
 * The same seed gives the same ids, another seed others, each a version 7 UUID.
 *
 * @since 0.2.0
 */
final class SeededIdsTest extends TestCase {

	/**
	 * Tests that a seed's sequence repeats, another seed's differs, and reseeding starts over.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_seed_gives_the_same_ids_every_time(): void {
		$first  = self::sequence( new SeededIdGenerator( 'stub money-path' ) );
		$again  = self::sequence( new SeededIdGenerator( 'stub money-path' ) );
		$other  = self::sequence( new SeededIdGenerator( 'stub void' ) );
		$reused = new SeededIdGenerator( 'stub void' );
		$reused->generate();
		$reused->reseed( 'stub money-path' );

		$this->assertSame( $first, $again );
		$this->assertSame( array(), array_intersect( $first, $other ) );
		$this->assertSame( $first, self::sequence( $reused ) );
		$this->assertCount( 5, array_unique( $first ) );

		foreach ( $first as $id ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id );
		}
	}

	/**
	 * Draws five ids.
	 *
	 * @since 0.2.0
	 *
	 * @param SeededIdGenerator $ids The port.
	 * @return list<string> The ids.
	 */
	private static function sequence( SeededIdGenerator $ids ): array {
		return array( $ids->generate(), $ids->generate(), $ids->generate(), $ids->generate(), $ids->generate() );
	}
}

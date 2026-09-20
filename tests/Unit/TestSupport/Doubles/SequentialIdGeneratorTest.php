<?php
/**
 * Tests the SequentialIdGenerator test double
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport\Doubles;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\IdGenerator;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

/**
 * Proves that the identifiers are predictable and still well-formed version 7 UUIDs.
 *
 * @since 0.1.0
 */
final class SequentialIdGeneratorTest extends TestCase {

	/**
	 * A lowercase UUID with the version nibble 7 and the RFC 4122 variant bits (8, 9, a or b).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UUID_V7_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/**
	 * Tests that the double can stand in for the port.
	 *
	 * @since 0.1.0
	 */
	public function test_it_is_an_id_generator(): void {
		$this->assertInstanceOf( IdGenerator::class, new SequentialIdGenerator() );
	}

	/**
	 * Tests that the sequence starts at one and counts up, spelled out so the format is pinned.
	 *
	 * @since 0.1.0
	 */
	public function test_identifiers_count_up_from_one(): void {
		$ids = new SequentialIdGenerator();

		$this->assertSame( '00000000-0000-7000-8000-000000000001', $ids->generate() );
		$this->assertSame( '00000000-0000-7000-8000-000000000002', $ids->generate() );
		$this->assertSame( '00000000-0000-7000-8000-000000000003', $ids->generate() );
	}

	/**
	 * Tests that two generators produce the same identifiers, and that nth() names them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sequence_is_deterministic(): void {
		$first  = new SequentialIdGenerator();
		$second = new SequentialIdGenerator();

		for ( $sequence = 1; $sequence <= 20; $sequence++ ) {
			$id = $first->generate();

			$this->assertSame( $id, $second->generate() );
			$this->assertSame( $id, SequentialIdGenerator::nth( $sequence ) );
		}
	}

	/**
	 * Tests that a generator can start anywhere in the sequence.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sequence_can_start_at_another_number(): void {
		$this->assertSame( '00000000-0000-7000-8000-0000000000ff', ( new SequentialIdGenerator( 255 ) )->generate() );
	}

	/**
	 * Tests that every identifier is a lowercase version 7 UUID, from the smallest to the largest sequence number.
	 *
	 * @since 0.1.0
	 */
	public function test_every_identifier_is_a_well_formed_version_7_uuid(): void {
		foreach ( array( 0, 1, 9, 10, 255, 4096, 1000000, 0xFFFFFFFFFFFF ) as $sequence ) {
			$this->assertMatchesRegularExpression( self::UUID_V7_PATTERN, SequentialIdGenerator::nth( $sequence ), "Sequence number {$sequence}." );
		}
	}

	/**
	 * Tests that identifiers sort in the order they were minted, as production version 7 UUIDs do.
	 *
	 * @since 0.1.0
	 */
	public function test_identifiers_sort_in_the_order_they_were_minted(): void {
		$ids    = new SequentialIdGenerator();
		$minted = array();

		// Three hundred crosses two hexadecimal digit boundaries, where a padding bug would reorder them.
		for ( $count = 0; $count < 300; $count++ ) {
			$minted[] = $ids->generate();
		}

		$sorted = $minted;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $minted, $sorted );
		$this->assertCount( 300, array_unique( $minted ) );
	}

	/**
	 * Tests that a sequence number that would break the UUID shape is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_sequence_numbers_that_do_not_fit
	 *
	 * @param int $sequence A sequence number outside the supported range.
	 */
	public function test_a_sequence_number_that_does_not_fit_is_refused( int $sequence ): void {
		$this->expectException( \OutOfRangeException::class );

		SequentialIdGenerator::nth( $sequence );
	}

	/**
	 * Provides sequence numbers outside the supported range.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{int}> Test cases.
	 */
	public static function data_sequence_numbers_that_do_not_fit(): array {
		return array(
			'negative'                         => array( -1 ),
			'one more than twelve digits hold' => array( 0xFFFFFFFFFFFF + 1 ),
		);
	}
}

<?php
/**
 * SequentialIdGenerator: an identifier generator whose output a test can predict
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Support\IdGenerator;

/**
 * An IdGenerator that counts.
 *
 * Every identifier is a well-formed version 7 UUID, so it passes the same validation and
 * fits the same column as a production identifier, but nothing in it is random: the
 * timestamp and the first random field are zero, and the last twelve hexadecimal digits
 * hold the sequence number. The first three identifiers are therefore
 *
 *     00000000-0000-7000-8000-000000000001
 *     00000000-0000-7000-8000-000000000002
 *     00000000-0000-7000-8000-000000000003
 *
 * and, like production identifiers, they sort in the order they were minted. A test names
 * an expected identifier with nth() instead of spelling it out.
 *
 * @since 0.1.0
 */
final class SequentialIdGenerator implements IdGenerator {

	/**
	 * The largest sequence number that fits the twelve hexadecimal digits reserved for it.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAXIMUM_SEQUENCE = 0xFFFFFFFFFFFF;

	/**
	 * The sequence number of the next identifier.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $next;

	/**
	 * Starts the sequence.
	 *
	 * @since 0.1.0
	 *
	 * @param int $first Optional. The sequence number of the first identifier. Default 1.
	 */
	public function __construct( int $first = 1 ) {
		$this->next = $first;
	}

	/**
	 * Mints the next identifier in the sequence.
	 *
	 * @since 0.1.0
	 *
	 * @return string A lowercase version 7 UUID.
	 */
	public function generate(): string {
		return self::nth( $this->next++ );
	}

	/**
	 * Returns the identifier that carries a given sequence number.
	 *
	 * @since 0.1.0
	 *
	 * @throws \OutOfRangeException When the sequence number does not fit the identifier.
	 *
	 * @param int $sequence The sequence number, from 0 to 281474976710655.
	 * @return string A lowercase version 7 UUID.
	 */
	public static function nth( int $sequence ): string {
		if ( $sequence < 0 || $sequence > self::MAXIMUM_SEQUENCE ) {
			throw new \OutOfRangeException(
				sprintf( 'Sequence number %d does not fit the last twelve hexadecimal digits of a UUID.', $sequence )
			);
		}

		/*
		 * Field by field: a zero 48-bit timestamp; the version nibble 7 and a zero 12-bit
		 * random field; the variant bits 10 and a zero 14-bit random field, which together
		 * read 8000; then the sequence number.
		 */
		return sprintf( '00000000-0000-7000-8000-%012x', $sequence );
	}
}

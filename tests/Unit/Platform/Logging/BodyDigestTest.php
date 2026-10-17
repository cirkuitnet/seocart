<?php
/**
 * Tests that a body's logged hash is never card-shaped and is the body's SHA-256
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Logging;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Logging\BodyDigest;
use SEOCart\Platform\Logging\CardNumbers;

/**
 * The hash a log line carries for a body is written so that the card-number filter cannot remove part of it.
 *
 * Plain hexadecimal would be removed in part from about 1 hash in 232 (a run of 13 to 19 digits that
 * passes the checksum), which is why the groups are short and joined by colons. The guarantee is
 * the form: a group is shorter than any card number and a colon never joins two groups of digits.
 * The 3 000 bodies are `0` to `2999`, so every run draws the same ones; about 13 of them are
 * card-shaped when written as one run, the first being `338`.
 *
 * Planted violations, each shown red and removed: in BodyDigest::of(), return the hash as one run
 * of hexadecimal (the filter finds card-shaped digests and the form is wrong); make GROUP_LENGTH 16
 * (the group is no shorter than a card number and the form is wrong).
 *
 * @since 0.2.0
 */
final class BodyDigestTest extends TestCase {

	/**
	 * How many bodies are tried.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const BODIES = 3000;

	/**
	 * Tests that no digest is card-shaped.
	 *
	 * @since 0.2.0
	 */
	public function test_a_digest_is_never_card_shaped(): void {
		$caught = array();

		for ( $body = 0; $body < self::BODIES; $body++ ) {
			if ( CardNumbers::contains( BodyDigest::of( (string) $body ) ) ) {
				$caught[] = $body;
			}
		}

		$this->assertSame( array(), $caught, 'The card filter would remove part of these bodies\' digests.' );
	}

	/**
	 * Tests that a digest is eight groups of eight hexadecimal digits joined by colons, and is the body's SHA-256 once the colons are taken out.
	 *
	 * @since 0.2.0
	 */
	public function test_a_digest_is_the_bodys_sha256_in_groups_joined_by_colons(): void {
		$wrong = array();

		for ( $body = 0; $body < self::BODIES; $body++ ) {
			$digest = BodyDigest::of( (string) $body );

			if ( 1 !== preg_match( '/^[0-9a-f]{8}(?::[0-9a-f]{8}){7}\z/D', $digest ) || str_replace( ':', '', $digest ) !== hash( 'sha256', (string) $body ) ) {
				$wrong[] = $body;
			}
		}

		$this->assertSame( array(), $wrong, 'These bodies\' digests are not their SHA-256 in groups of eight.' );
		$this->assertSame( '9f86d081:884c7d65:9a2feaa0:c55ad015:a3bf4f1b:2b0b822c:d15d6c15:b0f00a08', BodyDigest::of( 'test' ), 'The known digest of the word test.' );
	}

	/**
	 * Tests that a group is shorter than the fewest digits a card number has.
	 *
	 * @since 0.2.0
	 */
	public function test_a_group_is_shorter_than_any_card_number(): void {
		$this->assertLessThan( CardNumbers::MIN_DIGITS, BodyDigest::GROUP_LENGTH );
	}
}

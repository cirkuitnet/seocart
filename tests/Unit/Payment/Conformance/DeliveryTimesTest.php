<?php
/**
 * Tests what in a webhook delivery's headers reads as a time
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\DeliveryTimes;

/**
 * A `t=` part, a Unix time within ten years of the signing, an RFC 3339 date and an HTTP date in a header are times; a digest, an id and a number that is no time near the signing are not.
 *
 * @since 0.2.0
 */
final class DeliveryTimesTest extends TestCase {

	/**
	 * When the deliveries are signed.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SIGNED_AT = '2026-06-01T12:00:00+00:00';

	/**
	 * Returns header values that hold a time, with what each holds.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> The value, and what it holds.
	 */
	public function dated(): array {
		$unix = 'a Unix time within ten years of when it was signed';

		return array(
			'a t= part'                            => array( 't=1780315200,v1=' . str_repeat( 'ab', 32 ), 'a `t=<digits>` part' ),
			'a t= part in capitals, after a space' => array( 'scheme=v1, T=1', 'a `t=<digits>` part' ),
			'a Unix time in seconds'               => array( '1780315200', $unix ),
			'a Unix time in milliseconds'          => array( '1780315200123', $unix ),
			'a Unix time with a fraction'          => array( 'ts:1780315200.25', $unix ),
			'a Unix time nine years before'        => array( (string) ( 1780315200 - 9 * 31557600 ), $unix ),
			'an RFC 3339 instant'                  => array( '2026-06-01T12:00:00Z', 'an RFC 3339 date' ),
			'an RFC 3339 date alone'               => array( 'signed 2026-06-01', 'an RFC 3339 date' ),
			'an HTTP date'                         => array( 'Mon, 01 Jun 2026 12:00:00 GMT', 'an HTTP date' ),
			'an RFC 850 date'                      => array( 'Monday, 01-Jun-26 12:00:00 GMT', 'an HTTP date' ),
			'an asctime date'                      => array( 'Mon Jun  1 12:00:00 2026', 'an HTTP date' ),
		);
	}

	/**
	 * Tests that a header that holds a time is found, with what it holds.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider dated
	 * @group conformance
	 *
	 * @param string $value What the header holds.
	 * @param string $holds What it is.
	 */
	public function test_a_time_in_a_header_is_found( string $value, string $holds ): void {
		$this->assertSame(
			array( 'x-signature', $holds ),
			DeliveryTimes::heldIn(
				array(
					'content-type' => 'application/json',
					'X-Signature'  => $value,
				),
				new \DateTimeImmutable( self::SIGNED_AT )
			)
		);
	}

	/**
	 * Returns header values that hold no time.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> The value.
	 */
	public function undated(): array {
		return array(
			'a SHA-512 digest with digits in it'   => array( 'sha512=' . strtoupper( str_repeat( 'a1780315200b', 10 ) ) . 'ABCDEFAB' ),
			'a bare 64-hex digest'                 => array( str_repeat( '17803152', 8 ) ),
			'a uuid'                               => array( '01942f00-0000-7000-8000-00000000a001' ),
			'a media type'                         => array( 'application/json; charset=utf-8' ),
			'digits inside a word'                 => array( 'evt1780315200x' ),
			'a Unix time eleven years before'      => array( (string) ( 1780315200 - 11 * 31557600 ) ),
			'a number too short to be a Unix time' => array( 'v=17803152' ),
			'a word that holds t='                 => array( 'format=v1' ),
		);
	}

	/**
	 * Tests that headers that hold no time are not taken for a time.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider undated
	 * @group conformance
	 *
	 * @param string $value What the header holds.
	 */
	public function test_a_header_of_no_time_is_not_one( string $value ): void {
		$this->assertNull( DeliveryTimes::heldIn( array( 'x-signature' => $value ), new \DateTimeImmutable( self::SIGNED_AT ) ) );
	}
}

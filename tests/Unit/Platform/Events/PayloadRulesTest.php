<?php
/**
 * Tests the rules an event's payload keeps to, and the stored form it is written in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Events;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Tests\Support\Events\AnyPayload;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;

/**
 * Ids and plain values only, two levels deep, within the cap; and a payload read back is the payload written.
 *
 * Outbox::encode() is the one writer of the stored form and decode() the one reader; both
 * are pure, so this runs without WordPress.
 *
 * Planted violation: in Outbox::checkField(), remove the loop that checks each item of a
 * list. A list holding a list (three levels) is then accepted.
 *
 * @since 0.1.0
 */
final class PayloadRulesTest extends TestCase {

	/**
	 * Returns payloads that break a rule, each with the field the refusal must name.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: array<array-key, mixed>, 1: string}> Payload and field.
	 */
	public static function refusedPayloads(): array {
		return array(
			'an object'                  => array( array( 'total' => new \stdClass() ), 'total' ),
			'three levels deep'          => array( array( 'line_ids' => array( array( 1, 2 ) ) ), 'line_ids' ),
			'a key that is not a string' => array( array( 0 => 17 ), '0' ),
			'a key not in snake_case'    => array( array( 'Line Ids' => 1 ), 'Line Ids' ),
			'a 17 KiB string'            => array( array( 'note' => str_repeat( 'x', 17 * 1024 ) ), 'note' ),
			'a map'                      => array( array( 'totals' => array( 'net' => 1 ) ), 'totals' ),
			'a float'                    => array( array( 'amount' => 12.5 ), 'amount' ),
			'a float in a list'          => array( array( 'amounts' => array( 1, 2.5 ) ), 'amounts' ),
		);
	}

	/**
	 * Every payload that breaks a rule is refused with a message naming the event and the field.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedPayloads
	 *
	 * @param array<array-key, mixed> $payload The payload.
	 * @param string                  $field   The field the refusal must name.
	 */
	public function test_a_payload_that_breaks_a_rule_is_refused_naming_the_field( array $payload, string $field ): void {
		try {
			Outbox::encode( new AnyPayload( $payload ), Publisher::DEFAULT_PAYLOAD_CAP_BYTES );
			$this->fail( 'The payload must be refused.' );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( AnyPayload::class, $refused->getMessage() );
			$this->assertStringContainsString( '"' . $field . '"', $refused->getMessage() );
		}
	}

	/**
	 * Plain values and flat lists of them pass, and read back unchanged.
	 *
	 * @since 0.1.0
	 */
	public function test_plain_values_and_flat_lists_pass_and_read_back_unchanged(): void {
		$payload = array(
			'line_ids' => array( 1, 2, 3 ),
			'labels'   => array( 'a', 'b' ),
			'mixed'    => array( 1, 'two', true, null ),
			'count'    => 7,
			'active'   => false,
			'note'     => 'ünïcödé / slash',
			'missing'  => null,
			'empty'    => array(),
		);

		$stored = Outbox::decode( Outbox::encode( new AnyPayload( $payload ), Publisher::DEFAULT_PAYLOAD_CAP_BYTES ) );

		$this->assertSame( $payload, $stored['p'] );
		$this->assertSame( 1, $stored['v'] );
	}

	/**
	 * An event rebuilt from its stored form equals the event that was stored, instant included.
	 *
	 * @since 0.1.0
	 */
	public function test_both_fixtures_round_trip_through_the_stored_form(): void {
		$happened = new ThingHappened( 42, 'a note', array( 7, 8 ), new \DateTimeImmutable( '2026-09-23 12:34:56.789012', new \DateTimeZone( 'Europe/Paris' ) ) );
		$stored   = Outbox::decode( Outbox::encode( $happened, Publisher::DEFAULT_PAYLOAD_CAP_BYTES ) );
		$rebuilt  = ThingHappened::fromPayload( $stored['p'], $stored['at'], $stored['v'] );

		$this->assertSame( $happened->toPayload(), $rebuilt->toPayload() );
		$this->assertSame( $happened->occurredAt()->format( 'U.u' ), $rebuilt->occurredAt()->format( 'U.u' ), 'The instant survives to the microsecond.' );
		$this->assertSame( '+00:00', $rebuilt->occurredAt()->format( 'P' ), 'Instants are stored in UTC.' );

		$noticed = new ThingNoticed( 9 );
		$stored  = Outbox::decode( Outbox::encode( $noticed, Publisher::DEFAULT_PAYLOAD_CAP_BYTES ) );
		$rebuilt = ThingNoticed::fromPayload( $stored['p'], $stored['at'], $stored['v'] );

		$this->assertSame( $noticed->toPayload(), $rebuilt->toPayload() );
		$this->assertSame( $noticed->occurredAt()->format( 'U.u' ), $rebuilt->occurredAt()->format( 'U.u' ) );
	}

	/**
	 * The stored form is `{"v":…,"at":…,"p":{…}}`, with slashes unescaped and an empty payload as an object.
	 *
	 * @since 0.1.0
	 */
	public function test_the_stored_form(): void {
		$this->assertSame(
			'{"v":1,"at":"2026-09-23T10:00:00.000000+00:00","p":{"path":"a/b"}}',
			Outbox::encode( new AnyPayload( array( 'path' => 'a/b' ) ), Publisher::DEFAULT_PAYLOAD_CAP_BYTES )
		);
		$this->assertSame(
			'{"v":1,"at":"2026-09-23T10:00:00.000000+00:00","p":{}}',
			Outbox::encode( new AnyPayload( array() ), Publisher::DEFAULT_PAYLOAD_CAP_BYTES )
		);
	}

	/**
	 * The cap counts the whole encoded form: a payload just under it passes, one byte more is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_the_cap_is_the_length_of_the_encoded_form(): void {
		$empty = strlen( Outbox::encode( new AnyPayload( array( 'note' => '' ) ), Publisher::DEFAULT_PAYLOAD_CAP_BYTES ) );
		$fits  = new AnyPayload( array( 'note' => str_repeat( 'x', Publisher::DEFAULT_PAYLOAD_CAP_BYTES - $empty ) ) );

		$this->assertSame( Publisher::DEFAULT_PAYLOAD_CAP_BYTES, strlen( Outbox::encode( $fits, Publisher::DEFAULT_PAYLOAD_CAP_BYTES ) ) );

		$this->expectException( \LogicException::class );

		Outbox::encode( new AnyPayload( array( 'note' => str_repeat( 'x', Publisher::DEFAULT_PAYLOAD_CAP_BYTES - $empty + 1 ) ) ), Publisher::DEFAULT_PAYLOAD_CAP_BYTES );
	}

	/**
	 * An aggregate type that does not fit its column, a negative id and text that is not UTF-8 are refused.
	 *
	 * @since 0.1.0
	 */
	public function test_the_aggregate_and_the_text_must_fit_their_columns(): void {
		$refused = array(
			'type too long'  => new AnyPayload( array(), str_repeat( 'a', 33 ) ),
			'type not snake' => new AnyPayload( array(), 'Order' ),
			'negative id'    => new AnyPayload( array(), 'thing', -1 ),
			'invalid UTF-8'  => new AnyPayload( array( 'note' => "\xC3\x28" ) ),
		);

		foreach ( $refused as $case => $event ) {
			try {
				Outbox::encode( $event, Publisher::DEFAULT_PAYLOAD_CAP_BYTES );
				$this->fail( $case . ' must be refused.' );
			} catch ( \LogicException $expected ) {
				$this->assertStringContainsString( AnyPayload::class, $expected->getMessage(), $case );
			}
		}

		$this->assertStringStartsWith( '{"v":1,', Outbox::encode( new AnyPayload( array(), str_repeat( 'a', 32 ), 0 ), Publisher::DEFAULT_PAYLOAD_CAP_BYTES ), 'A 32-character type and id 0 fit.' );
	}

	/**
	 * A stored value that is not the stored form is refused when it is read.
	 *
	 * @since 0.1.0
	 */
	public function test_decode_refuses_what_encode_never_writes(): void {
		foreach ( array( '{"v":1,"p":{}}', '{"v":"1","at":"2026-09-23T10:00:00.000000+00:00","p":{}}', '{"v":1,"at":"yesterday","p":{}}', '[1,2]' ) as $stored ) {
			try {
				Outbox::decode( $stored );
				$this->fail( $stored . ' must be refused.' );
			} catch ( \UnexpectedValueException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}

		$this->expectException( \JsonException::class );

		Outbox::decode( '{"v":1,' );
	}
}

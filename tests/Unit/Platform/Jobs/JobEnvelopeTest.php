<?php
/**
 * Tests the form a job takes in the queue's storage
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Jobs;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobEnvelope;

/**
 * The stored form: its field order, its size limit, and the prefixes the queue looks jobs up by.
 *
 * @since 0.1.0
 */
final class JobEnvelopeTest extends TestCase {

	/**
	 * A correlation id in the canonical form.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CORRELATION = '00000000-0000-7000-8000-000000000001';

	/**
	 * Tests the stored form of a queued job and of a recurring one, field by field and in order.
	 *
	 * @since 0.1.0
	 */
	public function test_the_stored_form_lists_its_fields_in_a_fixed_order(): void {
		$queued    = new JobEnvelope( new Job( 'notification.send', array( 'order_id' => 42 ), 'outbox:17:notification.send' ), self::CORRELATION, 2 );
		$recurring = new JobEnvelope( new Job( 'outbox.catch_up' ), null, 1, 300 );

		$this->assertSame( '[{"h":"notification.send","k":"outbox:17:notification.send","c":"' . self::CORRELATION . '","n":2,"p":{"order_id":42}}]', $queued->encode() );
		$this->assertSame( '[{"h":"outbox.catch_up","r":300}]', $recurring->encode() );
		$this->assertSame( '[{"h":"outbox.catch_up","n":1}]', ( new JobEnvelope( new Job( 'outbox.catch_up' ) ) )->encode(), 'Absent parts are left out.' );
	}

	/**
	 * Tests that what the hook receives reads back as the same envelope.
	 *
	 * @since 0.1.0
	 */
	public function test_the_stored_form_reads_back_unchanged(): void {
		$envelopes = array(
			new JobEnvelope(
				new Job(
					'notification.send',
					array(
						'order_id' => 42,
						'resend'   => true,
						'note'     => null,
					),
					'outbox:17:notification.send'
				),
				self::CORRELATION,
				3
			),
			new JobEnvelope( new Job( 'outbox.catch_up' ), null, 1, 300 ),
			new JobEnvelope( new Job( 'schema.migrate', array(), 'schema.migrate:abc' ) ),
		);

		foreach ( $envelopes as $envelope ) {
			$stored = json_decode( $envelope->encode(), true, 512, JSON_THROW_ON_ERROR );

			$this->assertEquals( $envelope, JobEnvelope::fromStored( $stored[0] ) );
		}
	}

	/**
	 * Tests that a field a later release may add is ignored, and a malformed one refused.
	 *
	 * @since 0.1.0
	 */
	public function test_reading_ignores_unknown_fields_and_refuses_malformed_ones(): void {
		$read = JobEnvelope::fromStored(
			array(
				'h' => 'outbox.catch_up',
				'n' => 1,
				'z' => 'added later',
			)
		);

		$this->assertSame( 'outbox.catch_up', $read->job->handler );

		$malformed = array(
			'not a map'          => 'outbox.catch_up',
			'no handler'         => array( 'n' => 1 ),
			'a numeric attempt'  => array(
				'h' => 'outbox.catch_up',
				'n' => '2',
			),
			'a bad handler name' => array( 'h' => 'Outbox' ),
			'attempt zero'       => array(
				'h' => 'outbox.catch_up',
				'n' => 0,
			),
			'a list payload'     => array(
				'h' => 'outbox.catch_up',
				'p' => array( 1, 2 ),
			),
		);

		foreach ( $malformed as $case => $fields ) {
			try {
				JobEnvelope::fromStored( $fields );
				$this->fail( "A stored job with {$case} was read." );
			} catch ( \UnexpectedValueException $refused ) {
				$this->assertNotSame( '', $refused->getMessage(), $case );
			}
		}
	}

	/**
	 * Tests the size limit: the stored form must fit the queue's indexed column.
	 *
	 * Planted violation: raise MAX_ENCODED_LENGTH to 8000; the long payload is accepted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_job_too_large_to_store_whole_is_refused(): void {
		$fits = new JobEnvelope( new Job( 'notification.send', array( 'body' => str_repeat( 'x', 90 ) ) ), self::CORRELATION );

		$this->assertLessThanOrEqual( JobEnvelope::MAX_ENCODED_LENGTH, strlen( $fits->encode() ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'carry ids, not documents' );

		new JobEnvelope( new Job( 'notification.send', array( 'body' => str_repeat( 'x', 200 ) ) ), self::CORRELATION );
	}

	/**
	 * Tests what a recurring job and an attempt may be.
	 *
	 * @since 0.1.0
	 */
	public function test_a_recurring_job_carries_its_handler_and_interval_only(): void {
		$refused = array(
			'an interval under a minute' => static fn() => new JobEnvelope( new Job( 'outbox.catch_up' ), null, 1, 59 ),
			'a key'                      => static fn() => new JobEnvelope( new Job( 'outbox.catch_up', array(), 'k' ), null, 1, 300 ),
			'a payload'                  => static fn() => new JobEnvelope( new Job( 'outbox.catch_up', array( 'a_b' => 1 ) ), null, 1, 300 ),
			'a correlation id'           => static fn() => new JobEnvelope( new Job( 'outbox.catch_up' ), self::CORRELATION, 1, 300 ),
			'a retry'                    => static fn() => new JobEnvelope( new Job( 'outbox.catch_up' ), null, 2, 300 ),
			'attempt zero'               => static fn() => new JobEnvelope( new Job( 'outbox.catch_up' ), null, 0 ),
			'an empty correlation id'    => static fn() => new JobEnvelope( new Job( 'outbox.catch_up' ), '' ),
		);

		foreach ( $refused as $case => $build ) {
			try {
				$build();
				$this->fail( "An envelope with {$case} was built." );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage(), $case );
			}
		}

		$retry = ( new JobEnvelope( new Job( 'notification.send', array(), 'k1' ), self::CORRELATION ) )->nextAttempt();

		$this->assertSame( 2, $retry->attempt );
		$this->assertSame( 'k1', $retry->job->uniqueKey );
		$this->assertSame( self::CORRELATION, $retry->correlationId );
	}

	/**
	 * Tests that each prefix is exactly how the stored forms it names start, and how no other does.
	 *
	 * The queue finds a handler's jobs, a keyed job and a recurring job by these prefixes, so a
	 * prefix that matched a longer key or another handler would cancel or block the wrong job.
	 *
	 * Planted violation: cut the key's closing quote off keyPrefix() (return the opening without
	 * its last character, and no comma); `outbox:1` then also matches the stored form of `outbox:12`.
	 *
	 * @since 0.1.0
	 */
	public function test_the_lookup_prefixes_match_exactly_the_forms_they_name(): void {
		$keyed      = ( new JobEnvelope( new Job( 'notification.send', array( 'order_id' => 1 ), 'outbox:1' ), self::CORRELATION ) )->encode();
		$longer     = ( new JobEnvelope( new Job( 'notification.send', array(), 'outbox:12' ), self::CORRELATION ) )->encode();
		$unkeyed    = ( new JobEnvelope( new Job( 'notification.send' ), self::CORRELATION ) )->encode();
		$other      = ( new JobEnvelope( new Job( 'notification.sender' ) ) )->encode();
		$recurring  = ( new JobEnvelope( new Job( 'notification.send' ), null, 1, 300 ) )->encode();
		$keyPrefix  = JobEnvelope::keyPrefix( 'notification.send', 'outbox:1' );
		$handler    = JobEnvelope::prefixFor( 'notification.send' );
		$recurrence = JobEnvelope::recurringPrefix( 'notification.send' );

		$this->assertStringStartsWith( $keyPrefix, $keyed );
		$this->assertStringStartsNotWith( $keyPrefix, $longer );
		$this->assertStringStartsNotWith( $keyPrefix, $unkeyed );

		foreach ( array( $keyed, $longer, $unkeyed, $recurring ) as $form ) {
			$this->assertStringStartsWith( $handler, $form );
		}

		$this->assertStringStartsNotWith( $handler, $other );
		$this->assertStringStartsWith( $recurrence, $recurring );
		$this->assertStringStartsNotWith( $recurrence, $unkeyed );
		$this->assertStringStartsNotWith( $recurrence, $keyed );
	}
}

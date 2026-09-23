<?php
/**
 * Tests what a job may carry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Jobs;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Jobs\Job;

/**
 * A job carries a dotted handler name, ids and short strings, and an optional key; nothing else.
 *
 * @since 0.1.0
 */
final class JobTest extends TestCase {

	/**
	 * Tests that a well-formed job keeps what it was given.
	 *
	 * @since 0.1.0
	 */
	public function test_a_well_formed_job_keeps_its_parts(): void {
		$job = new Job(
			'notification.send',
			array(
				'order_id' => 42,
				'message'  => 'order_confirmation',
				'resend'   => false,
				'note'     => null,
			),
			'outbox:17:notification.send'
		);

		$this->assertSame( 'notification.send', $job->handler );
		$this->assertSame(
			array(
				'order_id' => 42,
				'message'  => 'order_confirmation',
				'resend'   => false,
				'note'     => null,
			),
			$job->payload
		);
		$this->assertSame( 'outbox:17:notification.send', $job->uniqueKey );
		$this->assertNull( ( new Job( 'outbox.catch_up' ) )->uniqueKey );
	}

	/**
	 * Tests every shape a job refuses.
	 *
	 * Planted violation: accept a float, by adding `|| is_float( $value )` to the accepted types in
	 * Job::__construct(); the "a float" case turns red.
	 *
	 * @dataProvider refusedJobs
	 *
	 * @since 0.1.0
	 *
	 * @param \Closure $build    Builds the job.
	 * @param string   $expected Part of the message.
	 */
	public function test_a_malformed_job_is_refused( \Closure $build, string $expected ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $expected );

		$build();
	}

	/**
	 * Supplies the refused shapes.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: \Closure(): Job, 1: string}> Case => builder, message part.
	 */
	public static function refusedJobs(): array {
		return array(
			'a one-word handler name'       => array( static fn() => new Job( 'sweep' ), 'is not two or more lowercase words' ),
			'an uppercase handler name'     => array( static fn() => new Job( 'Outbox.CatchUp' ), 'is not two or more lowercase words' ),
			'a handler name with a newline' => array( static fn() => new Job( "outbox.catch_up\n" ), 'is not two or more lowercase words' ),
			'a handler name too long'       => array( static fn() => new Job( 'a.' . str_repeat( 'b', Job::HANDLER_MAX_LENGTH ) ), 'is not two or more lowercase words' ),
			'a key with a space'            => array( static fn() => new Job( 'outbox.catch_up', array(), 'outbox 17' ), 'may hold lowercase letters' ),
			'a key with a percent sign'     => array( static fn() => new Job( 'outbox.catch_up', array(), 'outbox%17' ), 'may hold lowercase letters' ),
			'a key too long'                => array( static fn() => new Job( 'outbox.catch_up', array(), str_repeat( 'k', Job::KEY_MAX_LENGTH + 1 ) ), 'may hold lowercase letters' ),
			'a numbered payload'            => array( static fn() => new Job( 'outbox.catch_up', array( 42 ) ), 'is not a lowercase snake_case name' ),
			'a camelCase field'             => array( static fn() => new Job( 'outbox.catch_up', array( 'orderId' => 42 ) ), 'is not a lowercase snake_case name' ),
			'a float'                       => array( static fn() => new Job( 'outbox.catch_up', array( 'amount' => 9.99 ) ), 'of type float' ),
			'an array'                      => array( static fn() => new Job( 'outbox.catch_up', array( 'ids' => array( 1, 2 ) ) ), 'of type array' ),
			'an object'                     => array( static fn() => new Job( 'outbox.catch_up', array( 'when' => new \DateTimeImmutable() ) ), 'of type DateTimeImmutable' ),
		);
	}
}

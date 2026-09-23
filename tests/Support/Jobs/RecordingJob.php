<?php
/**
 * RecordingJob: a fixture job handler that records its runs and follows a plan
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Jobs;

use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Logging\CorrelationId;

/**
 * A queued-job handler, three attempts, that records each run and does what its plan says.
 *
 * Owns one fact: what the jobs tests observe of a run. Each run appends the payload, the
 * correlation id in force and the path of the Action Scheduler class loaded (which copy runs
 * the job) to `$runs`, then takes the next step of `$plan`: `ok` returns null, `throw` throws,
 * an integer returns that delay. An empty plan means `ok`. Tests reset both in set_up().
 *
 * @since 0.1.0
 */
final class RecordingJob implements JobHandler {

	/**
	 * The handler name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'test.recording';

	/**
	 * One entry per run, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{payload: array<string, int|string|bool|null>, correlation_id: string, library: string}>
	 */
	public static array $runs = array();

	/**
	 * What the next runs do, in order: `ok`, `throw`, or a delay in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string|int>
	 */
	public static array $plan = array();

	/**
	 * The correlation id holder, read during a run.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * Creates the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param CorrelationId $correlation The correlation id holder.
	 */
	public function __construct( CorrelationId $correlation ) {
		$this->correlation = $correlation;
	}

	/**
	 * Forgets the runs and the plan.
	 *
	 * @since 0.1.0
	 */
	public static function reset(): void {
		self::$runs = array();
		self::$plan = array();
	}

	/**
	 * Returns the handler name.
	 *
	 * @since 0.1.0
	 *
	 * @return string NAME.
	 */
	public static function name(): string {
		return self::NAME;
	}

	/**
	 * Returns how often it recurs.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null Null: it runs when queued.
	 */
	public static function recurrence(): ?int {
		return null;
	}

	/**
	 * Returns the attempts.
	 *
	 * @since 0.1.0
	 *
	 * @return int 3.
	 */
	public static function maxAttempts(): int {
		return 3;
	}

	/**
	 * Records the run and follows the plan.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the plan says so.
	 *
	 * @param array<string, int|string|bool|null> $payload The payload.
	 * @return int|null What the plan says.
	 */
	public function handle( array $payload ): ?int {
		self::$runs[] = array(
			'payload'        => $payload,
			'correlation_id' => $this->correlation->current(),
			'library'        => class_exists( 'ActionScheduler', false ) ? (string) ( new \ReflectionMethod( 'ActionScheduler', 'store' ) )->getFileName() : '',
		);

		$step = array_shift( self::$plan ) ?? 'ok';

		if ( 'throw' === $step ) {
			throw new \RuntimeException( 'Planned failure of run ' . count( self::$runs ) );
		}

		return is_int( $step ) ? $step : null;
	}
}

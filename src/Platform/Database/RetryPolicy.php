<?php
/**
 * RetryPolicy: how often, and after what pause, a deadlocked unit of work runs again
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A bounded, jittered retry of a whole unit of work.
 *
 * Owns one fact: the retry schedule. After failed attempt n the pause is a random number of
 * milliseconds in [0, base * 2^(n-1)] ("full jitter"), so two runners that collided do not
 * collide again in step. The policy is a value; the pause and the random draw are performed
 * by Database with callables its constructor receives, so a test can observe both.
 *
 * The application service passes a policy on its outermost call. The domain never sees it.
 *
 * @since 0.1.0
 */
final class RetryPolicy {

	/**
	 * How many times the unit of work may run in total, the first run included.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $attempts;

	/**
	 * The ceiling of the first pause, in milliseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $baseMs;

	/**
	 * Creates a policy. Use none() or deadlocks().
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When there is no attempt or the pause is negative.
	 *
	 * @param int $attempts How many runs in total, at least 1.
	 * @param int $baseMs   The ceiling of the first pause, in milliseconds, at least 0.
	 */
	private function __construct( int $attempts, int $baseMs ) {
		if ( $attempts < 1 || $baseMs < 0 ) {
			throw new \InvalidArgumentException( 'A retry policy needs at least one attempt and a pause of zero milliseconds or more.' );
		}

		$this->attempts = $attempts;
		$this->baseMs   = $baseMs;
	}

	/**
	 * Returns the policy that never retries.
	 *
	 * @since 0.1.0
	 *
	 * @return self One attempt.
	 */
	public static function none(): self {
		return new self( 1, 0 );
	}

	/**
	 * Returns the policy for deadlocks and lock-wait timeouts.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attempts Optional. How many runs in total, the first included. Default 3.
	 * @param int $baseMs   Optional. The ceiling of the first pause, in milliseconds. Default 50.
	 * @return self The policy.
	 */
	public static function deadlocks( int $attempts = 3, int $baseMs = 50 ): self {
		return new self( $attempts, $baseMs );
	}

	/**
	 * Returns how many times the unit of work may run in total.
	 *
	 * @since 0.1.0
	 *
	 * @return int At least 1.
	 */
	public function attempts(): int {
		return $this->attempts;
	}

	/**
	 * Returns the longest pause allowed after a failed attempt.
	 *
	 * @since 0.1.0
	 *
	 * @param int $failedAttempt The number of the attempt that failed, starting at 1.
	 * @return int The ceiling in milliseconds: base * 2^(failedAttempt - 1).
	 */
	public function ceilingMs( int $failedAttempt ): int {
		return $this->baseMs * ( 2 ** max( 0, $failedAttempt - 1 ) );
	}
}

<?php
/**
 * RateLimit: how many requests one client may send to one bucket in one window
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A declaration error is a message for the developer's test run, never HTML; this class may not call WordPress.

/**
 * A cap on the requests of one client, counted per bucket in fixed windows.
 *
 * Owns one fact: what a counter may be named and how long its window may be. The bucket names
 * what is counted, such as `cart.write`, and becomes the `scope` of a counter row, so it is short
 * lowercase text; the window is at most a day, which is how long the `rate_counters` table keeps
 * a row. The two adapters check a counter's name and window with checkCounter(), so a counter
 * that could not be kept cannot be counted either.
 *
 * Declarations are data: nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class RateLimit {

	/**
	 * The longest window, in seconds: a day, the retention of a counter row.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LONGEST_WINDOW_SECONDS = 86400;

	/**
	 * The shape of a bucket: dot-separated lowercase words, at most 64 characters.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const BUCKET_PATTERN = '/^(?=.{1,64}\z)[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*\z/';

	/**
	 * What is counted.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $bucket;

	/**
	 * The most requests a client may send in one window.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * The length of a window, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $windowSeconds;

	/**
	 * Declares the limit.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the bucket or the window is refused by checkCounter(),
	 *                                   or the limit is below 1.
	 *
	 * @param string $bucket         What is counted, such as `cart.write`.
	 * @param int    $limit          The most requests a client may send in one window.
	 * @param int    $window_seconds The length of a window, in seconds.
	 */
	public function __construct( string $bucket, int $limit, int $window_seconds ) {
		self::checkCounter( $bucket, $window_seconds );

		if ( $limit < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'The rate limit of %s must allow at least one request.', $bucket ) );
		}

		$this->bucket        = $bucket;
		$this->limit         = $limit;
		$this->windowSeconds = $window_seconds;
	}

	/**
	 * Checks a counter's bucket and window.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the bucket is not dot-separated lowercase words of at
	 *                                   most 64 characters, or the window is not 1 to
	 *                                   LONGEST_WINDOW_SECONDS seconds.
	 *
	 * @param string $bucket         What is counted.
	 * @param int    $window_seconds The length of a window, in seconds.
	 */
	public static function checkCounter( string $bucket, int $window_seconds ): void {
		if ( 1 !== preg_match( self::BUCKET_PATTERN, $bucket ) ) {
			throw new \InvalidArgumentException( sprintf( 'The rate-limit bucket "%s" must be dot-separated lowercase words of at most 64 characters, such as cart.write.', $bucket ) );
		}

		if ( $window_seconds < 1 || $window_seconds > self::LONGEST_WINDOW_SECONDS ) {
			throw new \InvalidArgumentException( sprintf( 'The rate-limit window of %1$s must be 1 to %2$d seconds long.', $bucket, self::LONGEST_WINDOW_SECONDS ) );
		}
	}

	/**
	 * Returns what is counted.
	 *
	 * @since 0.1.0
	 *
	 * @return string The bucket.
	 */
	public function bucket(): string {
		return $this->bucket;
	}

	/**
	 * Returns the most requests a client may send in one window.
	 *
	 * @since 0.1.0
	 *
	 * @return int The limit, at least 1.
	 */
	public function limit(): int {
		return $this->limit;
	}

	/**
	 * Returns the length of a window.
	 *
	 * @since 0.1.0
	 *
	 * @return int Seconds, 1 to LONGEST_WINDOW_SECONDS.
	 */
	public function windowSeconds(): int {
		return $this->windowSeconds;
	}
}

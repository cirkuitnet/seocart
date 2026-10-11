<?php
/**
 * RateLimiter: counts one client's requests to one bucket, per fixed window
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * The port every abuse control counts through.
 *
 * A counter is named by a bucket (what is counted, such as `cart.write`), a client identity and
 * the window it falls in. Windows are fixed and aligned to the Unix epoch: a 60-second window runs
 * from one whole minute to the next, so every request of a client in that minute shares the
 * counter, and the next minute starts at zero. Whether a count is too high is the caller's
 * decision; the port only counts, or counts only below a limit the caller names.
 *
 * Two adapters exist, and the kernel chooses one per request: ObjectCacheRateLimiter where a
 * persistent object cache is in use, TableRateLimiter otherwise. A transient is never used: without
 * a persistent object cache it is an options write on every request counted, and with one it is
 * evicted like any cache entry anyway.
 *
 * @since 0.1.0
 */
interface RateLimiter {

	/**
	 * Counts one request and returns the count of its window, this request included.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When RateLimit::checkCounter() refuses the bucket or the window.
	 *
	 * @param string         $bucket   What is counted, such as `cart.write`.
	 * @param ClientIdentity $identity Who sent the request.
	 * @param int            $window   The length of a window, in seconds, 1 to RateLimit::LONGEST_WINDOW_SECONDS.
	 * @return int The requests counted in the current window, at least 1.
	 */
	public function hit( string $bucket, ClientIdentity $identity, int $window ): int;

	/**
	 * Returns the count of the current window without counting.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When RateLimit::checkCounter() refuses the bucket or the window.
	 *
	 * @param string         $bucket   What is counted.
	 * @param ClientIdentity $identity Whose requests.
	 * @param int            $window   The length of a window, in seconds.
	 * @return int The requests counted in the current window so far, 0 when none.
	 */
	public function peek( string $bucket, ClientIdentity $identity, int $window ): int;

	/**
	 * Counts one request only while its window has counted fewer than a limit, deciding it in one atomic step: of requests racing at the limit, exactly one is counted.
	 *
	 * For a caller that acts on the first `$limit` requests of a window alone, such as one that logs
	 * them. The table writes nothing once its count is at the limit; the object cache's counter may
	 * run past it in memory, and only the first `$limit` increments report a request counted.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When RateLimit::checkCounter() refuses the bucket or the window, or the limit is below 1.
	 *
	 * @param string         $bucket   What is counted.
	 * @param ClientIdentity $identity Who sent the request.
	 * @param int            $window   The length of a window, in seconds.
	 * @param int            $limit    The most requests a window counts, at least 1.
	 * @return bool True when the request was counted, one of the first `$limit`; false when the window was at the limit.
	 */
	public function countBelow( string $bucket, ClientIdentity $identity, int $window, int $limit ): bool;
}

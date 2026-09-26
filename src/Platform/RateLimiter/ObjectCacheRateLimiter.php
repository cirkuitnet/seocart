<?php
/**
 * ObjectCacheRateLimiter: counts requests in the persistent object cache
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter;

use SEOCart\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The rate limiter of a site with a persistent object cache: no query at all.
 *
 * Owns one fact: how a hit is counted in the object cache. A counter is one cache entry, keyed by
 * the bucket, the identity and the start of its window, created at 0 with wp_cache_add() and
 * increased with wp_cache_incr(). The mainstream persistent drop-ins (Memcached, Redis) implement
 * both atomically on the server, so concurrent hits are all counted. The entry expires when its
 * window ends, and the next window has another key, so a counter restarts even where the cache
 * ignores expiry.
 *
 * A cache may evict an entry at any time. An evicted counter starts again from 1: the limit is
 * looser for that client for the rest of the window, and a request is never refused wrongly. A
 * cache that neither adds nor increments the counter, because it is down or refuses writes, is
 * not trusted with a count at all: the hit is counted in the counter table instead
 * (TableRateLimiter), and so is every later hit and peek of the same request. A broken cache
 * therefore never lifts the limit. The table starts that window's count afresh, so at worst, when
 * the cache fails part-way through a window, the limit doubles for that one window: the same as an
 * evicted counter.
 *
 * The windows are aligned to the Unix epoch on the clock the service is given, the same windows
 * TableRateLimiter computes on the database clock.
 *
 * @since 0.1.0
 */
final class ObjectCacheRateLimiter implements RateLimiter {

	/**
	 * The cache group of every counter.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP = 'seocart_rate_limits';

	/**
	 * The clock.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Returns the limiter that counts when the cache fails: the counter table's.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): RateLimiter
	 */
	private \Closure $fallback;

	/**
	 * Whether the cache has failed to count during this request, which is then counted by the fallback.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $failed = false;

	/**
	 * Creates the adapter.
	 *
	 * @since 0.1.0
	 *
	 * @param Clock    $clock    The clock the windows are aligned on.
	 * @param \Closure $fallback Returns the limiter that counts when the cache fails; called only then.
	 *
	 * @phpstan-param \Closure(): RateLimiter $fallback
	 */
	public function __construct( Clock $clock, \Closure $fallback ) {
		$this->clock    = $clock;
		$this->fallback = $fallback;
	}

	/**
	 * Tells whether the site can count in its object cache.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a persistent object cache is in use and provides wp_cache_incr().
	 */
	public static function isAvailable(): bool {
		return self::isUsable( (bool) wp_using_ext_object_cache(), function_exists( 'wp_cache_incr' ) );
	}

	/**
	 * Tells whether an object cache can count: it must be persistent, and it must increment.
	 *
	 * WordPress's wp_cache_supports() is not asked about incrementing: it knows no such feature,
	 * and no drop-in declares one, so the question would always be answered no.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $persistent Whether a persistent object cache is in use.
	 * @param bool $increments Whether wp_cache_incr() exists.
	 * @return bool True when both hold.
	 */
	public static function isUsable( bool $persistent, bool $increments ): bool {
		return $persistent && $increments;
	}

	/**
	 * Counts one request, sending no query while the cache works.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the bucket or the window is refused.
	 *
	 * @param string         $bucket   What is counted.
	 * @param ClientIdentity $identity Who sent the request.
	 * @param int            $window   The length of a window, in seconds.
	 * @return int The requests counted in the current window, this one included.
	 */
	public function hit( string $bucket, ClientIdentity $identity, int $window ): int {
		RateLimit::checkCounter( $bucket, $window );

		if ( $this->failed ) {
			return ( $this->fallback )()->hit( $bucket, $identity, $window );
		}

		$now   = $this->clock->now()->getTimestamp();
		$start = $now - $now % $window;
		$key   = self::key( $bucket, $identity, $start );
		$ttl   = max( 1, $start + $window - $now );

		wp_cache_add( $key, 0, self::GROUP, $ttl );

		$count = wp_cache_incr( $key, 1, self::GROUP );

		// Evicted between the add and the increment: start the counter again at this hit.
		if ( false === $count ) {
			$count = wp_cache_add( $key, 1, self::GROUP, $ttl ) ? 1 : wp_cache_incr( $key, 1, self::GROUP );
		}

		// The cache neither added nor incremented the counter: it cannot be trusted with the count.
		if ( false === $count ) {
			$this->failed = true;

			return ( $this->fallback )()->hit( $bucket, $identity, $window );
		}

		return (int) $count;
	}

	/**
	 * Returns the count of the current window without counting.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the bucket or the window is refused.
	 *
	 * @param string         $bucket   What is counted.
	 * @param ClientIdentity $identity Whose requests.
	 * @param int            $window   The length of a window, in seconds.
	 * @return int The requests counted in the current window, 0 when there is no entry.
	 */
	public function peek( string $bucket, ClientIdentity $identity, int $window ): int {
		RateLimit::checkCounter( $bucket, $window );

		if ( $this->failed ) {
			return ( $this->fallback )()->peek( $bucket, $identity, $window );
		}

		$now = $this->clock->now()->getTimestamp();

		return (int) wp_cache_get( self::key( $bucket, $identity, $now - $now % $window ), self::GROUP );
	}

	/**
	 * Returns the cache key of a counter.
	 *
	 * @since 0.1.0
	 *
	 * @param string         $bucket   What is counted.
	 * @param ClientIdentity $identity Whose requests.
	 * @param int            $start    The Unix time the window starts at.
	 * @return string The key, at most 140 characters.
	 */
	private static function key( string $bucket, ClientIdentity $identity, int $start ): string {
		return $bucket . ':' . $identity->key() . ':' . $start;
	}
}

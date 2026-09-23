<?php
/**
 * DeclaredJob: a fixture job handler whose declaration a test sets
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Jobs;

use SEOCart\Platform\Jobs\JobHandler;

/**
 * A handler that declares whatever name, interval and attempts `$declared` holds, and does nothing.
 *
 * Owns one fact: a handler declaration a registry test can make wrong on purpose.
 *
 * @since 0.1.0
 */
final class DeclaredJob implements JobHandler {

	/**
	 * The declaration: name, interval, attempts.
	 *
	 * @since 0.1.0
	 *
	 * @var array{0: string, 1: int|null, 2: int}
	 */
	public static array $declared = array( 'test.declared', null, 1 );

	/**
	 * Returns the declared name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public static function name(): string {
		return self::$declared[0];
	}

	/**
	 * Returns the declared interval.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The interval.
	 */
	public static function recurrence(): ?int {
		return self::$declared[1];
	}

	/**
	 * Returns the declared attempts.
	 *
	 * @since 0.1.0
	 *
	 * @return int The attempts.
	 */
	public static function maxAttempts(): int {
		return self::$declared[2];
	}

	/**
	 * Does nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload The payload.
	 * @return int|null Null.
	 */
	public function handle( array $payload ): ?int {
		return null;
	}
}

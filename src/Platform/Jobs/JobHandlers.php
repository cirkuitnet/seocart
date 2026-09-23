<?php
/**
 * JobHandlers: the registry of job handlers, resolved by name when a job runs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

use SEOCart\Platform\Jobs\Handlers\JobHistoryCleanup;
use SEOCart\Platform\Jobs\Handlers\MigrationAttempt;
use SEOCart\Platform\Jobs\Handlers\OutboxCatchUp;
use SEOCart\Platform\Jobs\Handlers\OutboxRetention;
use SEOCart\Platform\Logging\LogRetentionJob;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A registration error is a message for the developer's test run, never HTML; this class may not call WordPress.

/**
 * Maps handler names to handler classes, and builds a handler only when one of its jobs runs.
 *
 * Owns one fact: which handlers exist, by name. The registry is built from handler class
 * names and a resolver that returns an instance of a class, for example the container's
 * get(); it reads each class's static name(), recurrence() and maxAttempts() and constructs
 * nothing. handler() resolves a class the first time one of its jobs runs, once per registry.
 *
 * PRODUCTION is the one list of the plugin's handlers. A module that adds a handler adds its
 * class there, and a test holds the list equal to every handler class under src/.
 *
 * Construction refuses a class that is not a handler, a name that breaks Job::HANDLER_PATTERN,
 * a name registered twice, an interval below JobEnvelope::MIN_INTERVAL_SECONDS, and attempts
 * outside 1 to MAX_ATTEMPTS. Building the registry does no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final class JobHandlers {

	/**
	 * The plugin's job handlers.
	 *
	 * @since 0.1.0
	 *
	 * @var list<class-string<JobHandler>>
	 */
	public const PRODUCTION = array(
		OutboxCatchUp::class,
		OutboxRetention::class,
		MigrationAttempt::class,
		JobHistoryCleanup::class,
		LogRetentionJob::class,
	);

	/**
	 * The most attempts a handler may declare.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_ATTEMPTS = 10;

	/**
	 * Handler name => class.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, class-string<JobHandler>>
	 */
	private array $classes = array();

	/**
	 * Returns an instance of a handler class.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(class-string<JobHandler>): JobHandler
	 */
	private $resolve;

	/**
	 * Handler name => the instance resolved for it.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, JobHandler>
	 */
	private array $resolved = array();

	/**
	 * Registers handler classes. Constructs none of them.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a class or a declaration breaks the rules above.
	 *
	 * @param array<mixed> $classes The handler class names, for example PRODUCTION.
	 * @param callable     $resolve Returns an instance (JobHandler) of the class name it is given.
	 */
	public function __construct( array $classes, callable $resolve ) {
		foreach ( $classes as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) || ! is_subclass_of( $class, JobHandler::class ) ) {
				throw new \InvalidArgumentException( sprintf( '%s is not a job handler class.', is_string( $class ) ? $class : get_debug_type( $class ) ) );
			}

			$name     = $class::name();
			$every    = $class::recurrence();
			$attempts = $class::maxAttempts();

			if ( strlen( $name ) > Job::HANDLER_MAX_LENGTH || 1 !== preg_match( Job::HANDLER_PATTERN, $name ) ) {
				throw new \InvalidArgumentException( sprintf( 'The handler %s is named "%s", which is not two or more lowercase words joined by dots.', $class, $name ) );
			}

			if ( isset( $this->classes[ $name ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'The handler name %s is registered twice, by %s and %s.', $name, $this->classes[ $name ], $class ) );
			}

			if ( null !== $every && $every < JobEnvelope::MIN_INTERVAL_SECONDS ) {
				throw new \InvalidArgumentException( sprintf( 'The handler %s recurs every %d seconds; the shortest interval is %d.', $name, $every, JobEnvelope::MIN_INTERVAL_SECONDS ) );
			}

			if ( $attempts < 1 || $attempts > self::MAX_ATTEMPTS ) {
				throw new \InvalidArgumentException( sprintf( 'The handler %s declares %d attempts; it may declare 1 to %d.', $name, $attempts, self::MAX_ATTEMPTS ) );
			}

			$this->classes[ $name ] = $class;
		}

		$this->resolve = $resolve;
	}

	/**
	 * Tells whether a handler is registered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The handler name.
	 * @return bool True when it is.
	 */
	public function has( string $name ): bool {
		return isset( $this->classes[ $name ] );
	}

	/**
	 * Returns every registered handler name.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> In registration order.
	 */
	public function names(): array {
		return array_keys( $this->classes );
	}

	/**
	 * Returns the recurring handlers with their intervals.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> Handler name => seconds between runs.
	 */
	public function recurring(): array {
		$recurring = array();

		foreach ( $this->classes as $name => $class ) {
			$every = $class::recurrence();

			if ( null !== $every ) {
				$recurring[ $name ] = $every;
			}
		}

		return $recurring;
	}

	/**
	 * Returns how many times a queued job of a handler is attempted.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the handler is not registered.
	 *
	 * @param string $name The handler name.
	 * @return int The attempts.
	 */
	public function maxAttempts( string $name ): int {
		return $this->classFor( $name )::maxAttempts();
	}

	/**
	 * Returns a handler, building it on first use.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the handler is not registered, or the resolver returns something else.
	 *
	 * @param string $name The handler name.
	 * @return JobHandler The handler.
	 */
	public function handler( string $name ): JobHandler {
		if ( isset( $this->resolved[ $name ] ) ) {
			return $this->resolved[ $name ];
		}

		$class    = $this->classFor( $name );
		$instance = ( $this->resolve )( $class );

		if ( ! $instance instanceof $class ) {
			throw new \LogicException( sprintf( 'The resolver returned %s for the job handler %s.', get_debug_type( $instance ), $class ) );
		}

		$this->resolved[ $name ] = $instance;

		return $instance;
	}

	/**
	 * Returns the class of a registered handler.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the handler is not registered.
	 *
	 * @param string $name The handler name.
	 * @return class-string<JobHandler> The class.
	 */
	private function classFor( string $name ): string {
		if ( ! isset( $this->classes[ $name ] ) ) {
			throw new \LogicException( sprintf( 'No job handler is registered as %s.', $name ) );
		}

		return $this->classes[ $name ];
	}
}

<?php
/**
 * HookBridge: fires a domain event as its `seocart_` action, one listener at a time
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the delivery of an event into `do_action()`, and keeps each listener's failure to itself.
 *
 * Owns one fact: how a listener is run, and what happens when it fails. dispatch() fires
 * `do_action( $envelope->hook, $envelope->event, $envelope )`, so did_action(),
 * current_action() and doing_action() behave as for any action, and listeners run in
 * priority order, then in the order they were added.
 *
 * A plain `do_action()` cannot keep one listener from the next: an exception in a priority-10
 * callback skips priority 20 and leaves the hook's iteration state behind. So, for the
 * duration of a dispatch, every callback of the action is replaced in place by a wrapper that
 * runs it inside `try`/`catch`, reports a failure (`events.listener_failed`, naming the
 * callback, its plugin, the exception class and message) and returns, so the next listener
 * runs. A listener slower than the threshold is reported as `events.listener_slow`, which is
 * a report and not a failure. A listener's exception never causes the event to be delivered
 * again: its side effect did not happen, and the other listeners are not punished for it.
 *
 * The wrappers sit in the action's own WP_Hook, under each callback's own id, and each is put
 * back when the dispatch ends. So remove_action(), has_action() and add_action() called while
 * the action runs act on the real hook, exactly as WordPress defines it for a hook that
 * changes while it runs: a callback removed during the dispatch does not run later in it and
 * stays removed; a callback added at a later priority runs in the same dispatch, contained
 * like the others, and stays added; nothing is lost and nothing comes back.
 *
 * WordPress runs the callbacks of the special `all` hook before the action's own, and a
 * listener one of them adds is contained too: for the duration of a dispatch the bridge puts a
 * callback of its own on the action at the lowest priority, `PHP_INT_MIN`, which wraps whatever
 * was added before any listener runs.
 *
 * The limit, stated plainly: containment covers the listeners added to the action, before or
 * during a dispatch. It does not cover callbacks on `all`, nor a listener an `all` callback
 * adds after removing the bridge's callback, or at `PHP_INT_MIN` itself (WordPress runs that
 * one in the same pass as the bridge's, from a list taken before either ran). There, an escaped
 * exception fails the attempt, the caller treats it as a failed delivery, and the row is
 * retried, or parked when its attempts are spent; the bridge still puts the action's callbacks
 * and WordPress's stack of current actions back as they were. The bridge never adds to or removes from `all`: after a throwing
 * `all` callback, WordPress leaves that hook in a state in which changing it warns.
 *
 * A dispatch may nest inside another, also of the same action. The re-entrancy guard of the
 * publisher asks isDispatching() whether an event for the same aggregate is being delivered
 * anywhere in the current chain.
 *
 * @since 0.1.0
 */
final class HookBridge {

	/**
	 * How long a listener may take before it is reported as slow, in milliseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DEFAULT_SLOW_MILLISECONDS = 1000;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * Returns a monotonic time in nanoseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * How long a listener may take before it is reported as slow, in nanoseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $slowNanoseconds;

	/**
	 * The dispatches in progress in this process, innermost last: the envelope, the bridge that
	 * dispatches it, and each wrapped entry with its WP_Hook and the callback it replaced.
	 *
	 * Held by the class, not the instance, so that the re-entrancy guard sees every dispatch.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{envelope: EventEnvelope, bridge: self, wrapped: list<array{hook: \WP_Hook, priority: int, id: string, wrapper: \Closure, callback: callable}>}>
	 */
	private static array $frames = array();

	/**
	 * The bridge's own first callback on each action being dispatched, keyed by action.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, \Closure>
	 */
	private static array $sentinels = array();

	/**
	 * Every wrapper installed in a hook right now, mapped to the callback it runs.
	 *
	 * @since 0.1.0
	 *
	 * @var \WeakMap<\Closure, callable>|null
	 */
	private static ?\WeakMap $wrappers = null;

	/**
	 * Creates the bridge. Registers nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param callable      $report           Receives a report code (string) and its context (array).
	 * @param callable|null $clock            Optional. Returns a monotonic time in nanoseconds. Default null,
	 *                                        which uses hrtime().
	 * @param int           $slowMilliseconds Optional. How long a listener may take before it is reported
	 *                                        as slow. Default DEFAULT_SLOW_MILLISECONDS.
	 */
	public function __construct( callable $report, ?callable $clock = null, int $slowMilliseconds = self::DEFAULT_SLOW_MILLISECONDS ) {
		$this->report          = $report;
		$this->clock           = $clock ?? static fn(): int => (int) hrtime( true );
		$this->slowNanoseconds = max( 0, $slowMilliseconds ) * 1000000;
	}

	/**
	 * Fires the event's action and runs every listener, each contained.
	 *
	 * Whatever a listener throws is reported, never thrown. What this method throws comes from
	 * outside any listener, for example a callback on the `all` hook; the caller treats that
	 * as a failure of the delivery.
	 *
	 * @since 0.1.0
	 *
	 * @param EventEnvelope $envelope The event and its delivery metadata.
	 */
	public function dispatch( EventEnvelope $envelope ): void {
		global $wp_current_filter;

		$current = is_array( $wp_current_filter ) ? count( $wp_current_filter ) : 0;

		self::$frames[] = array(
			'envelope' => $envelope,
			'bridge'   => $this,
			'wrapped'  => array(),
		);

		$frame = array_key_last( self::$frames );

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Always `seocart_` followed by a catalogued event name: EventEnvelope::hookFor().
			add_action( $envelope->hook, self::sentinel( $envelope->hook ), PHP_INT_MIN, 0 );

			$this->wrapPending( $frame );

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Always `seocart_` followed by a catalogued event name: EventEnvelope::hookFor().
			do_action( $envelope->hook, $envelope->event, $envelope );
		} finally {
			// An exception from an `all` callback leaves do_action()'s entry on the stack of current actions.
			if ( is_array( $wp_current_filter ) && count( $wp_current_filter ) > $current ) {
				array_splice( $wp_current_filter, $current );
			}

			self::restore( $frame );

			array_pop( self::$frames );

			if ( null === self::innermostFrame( $envelope->hook ) ) {
				remove_action( $envelope->hook, self::sentinel( $envelope->hook ), PHP_INT_MIN );
			}
		}
	}

	/**
	 * Tells whether an event for the same aggregate is being delivered, here or further out.
	 *
	 * @since 0.1.0
	 *
	 * @param DomainEvent $event The event about to be published.
	 * @return bool True when a dispatch in progress carries an event of the same name for the
	 *              same aggregate type and id.
	 */
	public function isDispatching( DomainEvent $event ): bool {
		foreach ( self::$frames as $frame ) {
			$delivered = $frame['envelope']->event;

			if ( $delivered::eventName() === $event::eventName()
				&& $delivered->aggregateType() === $event->aggregateType()
				&& $delivered->aggregateId() === $event->aggregateId()
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lists the callbacks registered on an action, for doctor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook The action, for example `seocart_order_placed`.
	 * @return list<array{hook: string, callback: string, priority: int, plugin: string}> One entry per
	 *         callback, in the order they run. `plugin` is the owning plugin's directory, or
	 *         `mu-plugin`, `theme`, `core` or `unknown`.
	 */
	public function listeners( string $hook ): array {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return array();
		}

		$listeners = array();

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $entries ) {
			foreach ( $entries as $entry ) {
				if ( $entry['function'] instanceof \Closure && self::isSentinel( $entry['function'] ) ) {
					continue;
				}

				$described = self::describe( self::unwrapped( $entry['function'] ) );

				$listeners[] = array(
					'hook'     => $hook,
					'callback' => $described['name'],
					'priority' => (int) $priority,
					'plugin'   => self::owner( $described['file'] ),
				);
			}
		}

		return $listeners;
	}

	/**
	 * Replaces every callback of a frame's action that is not wrapped yet with a containing wrapper.
	 *
	 * Called when the dispatch starts, again by the sentinel once the `all` callbacks have run,
	 * and again after every listener returns, so a callback added before its priority is reached
	 * is wrapped before it runs. It reads the action's WP_Hook afresh each time.
	 *
	 * @since 0.1.0
	 *
	 * @param int $frame The frame's index.
	 */
	private function wrapPending( int $frame ): void {
		global $wp_filter;

		$hookName = self::$frames[ $frame ]['envelope']->hook;

		if ( ! isset( $wp_filter[ $hookName ] ) || ! $wp_filter[ $hookName ] instanceof \WP_Hook ) {
			return;
		}

		$hook     = $wp_filter[ $hookName ];
		$wrappers = self::wrappers();

		foreach ( $hook->callbacks as $priority => $entries ) {
			foreach ( $entries as $id => $entry ) {
				$callback = $entry['function'];

				if ( $callback instanceof \Closure && ( isset( $wrappers[ $callback ] ) || self::isSentinel( $callback ) ) ) {
					continue;
				}

				$wrapper = $this->contain( $hookName, $callback );

				$wrappers[ $wrapper ] = $callback;

				$hook->callbacks[ $priority ][ $id ]['function'] = $wrapper;

				self::$frames[ $frame ]['wrapped'][] = array(
					'hook'     => $hook,
					'priority' => (int) $priority,
					'id'       => (string) $id,
					'wrapper'  => $wrapper,
					'callback' => $callback,
				);
			}
		}
	}

	/**
	 * Puts back the callbacks a frame wrapped, where each still is.
	 *
	 * An entry removed during the dispatch stays removed; an entry that was replaced by another
	 * registration under the same id keeps the new one.
	 *
	 * @since 0.1.0
	 *
	 * @param int $frame The frame's index.
	 */
	private static function restore( int $frame ): void {
		$wrappers = self::wrappers();

		foreach ( self::$frames[ $frame ]['wrapped'] as $entry ) {
			$hook = $entry['hook'];

			if ( isset( $hook->callbacks[ $entry['priority'] ][ $entry['id'] ] ) && $hook->callbacks[ $entry['priority'] ][ $entry['id'] ]['function'] === $entry['wrapper'] ) {
				$hook->callbacks[ $entry['priority'] ][ $entry['id'] ]['function'] = $entry['callback'];
			}

			unset( $wrappers[ $entry['wrapper'] ] );
		}
	}

	/**
	 * Returns the bridge's own first callback on an action: it wraps the action's callbacks once the `all` callbacks have run.
	 *
	 * WordPress runs the `all` callbacks after counting the action and pushing it on the stack
	 * of current actions, and before the action's own callbacks. At the lowest priority this
	 * callback runs before every listener, so whatever the `all` callbacks added is wrapped
	 * before its priority is reached. dispatch() adds it before do_action() and removes it when
	 * the last dispatch of the action ends. It takes no argument and is never wrapped itself.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hookName The action.
	 * @return \Closure The callback, the same one for every dispatch of the action.
	 */
	private static function sentinel( string $hookName ): \Closure {
		self::$sentinels[ $hookName ] ??= static function () use ( $hookName ): void {
			$frame = self::innermostFrame( $hookName );

			if ( null !== $frame ) {
				self::$frames[ $frame ]['bridge']->wrapPending( $frame );
			}
		};

		return self::$sentinels[ $hookName ];
	}

	/**
	 * Builds the wrapper that runs one callback contained.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $hookName The action.
	 * @param callable $callback The listener.
	 * @return \Closure The wrapper. It takes whatever arguments WordPress hands the callback.
	 */
	private function contain( string $hookName, callable $callback ): \Closure {
		return function ( ...$args ) use ( $hookName, $callback ) {
			$started = ( $this->clock )();

			try {
				call_user_func_array( $callback, $args );
			} catch ( \Throwable $failure ) {
				$this->reportListener(
					ReportCode::ListenerFailed,
					$hookName,
					$callback,
					array(
						'exception' => get_class( $failure ),
						'message'   => $failure->getMessage(),
					)
				);
			} finally {
				$elapsed = ( $this->clock )() - $started;

				if ( $elapsed > $this->slowNanoseconds ) {
					$this->reportListener( ReportCode::ListenerSlow, $hookName, $callback, array( 'milliseconds' => intdiv( $elapsed, 1000000 ) ) );
				}

				$frame = self::innermostFrame( $hookName );

				if ( null !== $frame ) {
					self::$frames[ $frame ]['bridge']->wrapPending( $frame );
				}
			}

			return null;
		};
	}

	/**
	 * Reports something about one listener. A reporter that throws loses its report, never the dispatch.
	 *
	 * @since 0.1.0
	 *
	 * @param ReportCode           $code     The code.
	 * @param string               $hookName The action.
	 * @param callable             $callback The listener.
	 * @param array<string, mixed> $detail   What happened.
	 */
	private function reportListener( ReportCode $code, string $hookName, callable $callback, array $detail ): void {
		$frame     = self::innermostFrame( $hookName );
		$envelope  = null === $frame ? null : self::$frames[ $frame ]['envelope'];
		$described = self::describe( $callback );

		try {
			( $this->report )(
				$code->value,
				array(
					'hook'      => $hookName,
					'outbox_id' => $envelope?->outboxId,
					'attempt'   => $envelope?->attempt,
					'callback'  => $described['name'],
					'plugin'    => self::owner( $described['file'] ),
				) + $detail
			);
		} catch ( \Throwable $reporterFailed ) {
			// Nowhere is left to report to, and the next listener must still run.
			unset( $reporterFailed );
		}
	}

	/**
	 * Returns the index of the innermost dispatch of an action.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hookName The action.
	 * @return int|null The frame's index, or null when the action is not being dispatched.
	 */
	private static function innermostFrame( string $hookName ): ?int {
		for ( $index = count( self::$frames ) - 1; $index >= 0; --$index ) {
			if ( self::$frames[ $index ]['envelope']->hook === $hookName ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Tells whether a callback is one of the bridge's own first callbacks.
	 *
	 * @since 0.1.0
	 *
	 * @param \Closure $callback The callback.
	 * @return bool True for a sentinel.
	 */
	private static function isSentinel( \Closure $callback ): bool {
		return in_array( $callback, self::$sentinels, true );
	}

	/**
	 * Returns the registry of installed wrappers.
	 *
	 * @since 0.1.0
	 *
	 * @return \WeakMap<\Closure, callable> The registry.
	 */
	private static function wrappers(): \WeakMap {
		self::$wrappers ??= new \WeakMap();

		return self::$wrappers;
	}

	/**
	 * Returns the callback a wrapper runs, or the callback itself when it is not a wrapper.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $callback A registered callback.
	 * @return mixed The listener as its author registered it.
	 */
	private static function unwrapped( mixed $callback ): mixed {
		$wrappers = self::wrappers();

		return $callback instanceof \Closure && isset( $wrappers[ $callback ] ) ? $wrappers[ $callback ] : $callback;
	}

	/**
	 * Names a callback and finds the file it is defined in.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $callback The callback.
	 * @return array{name: string, file: string} `Class::method`, `function()` or `closure at file:line`, and the
	 *                                           defining file, or an empty string when it cannot be found.
	 */
	private static function describe( mixed $callback ): array {
		try {
			if ( $callback instanceof \Closure ) {
				$function = new \ReflectionFunction( $callback );
				$file     = (string) $function->getFileName();

				return array(
					'name' => sprintf( 'closure at %s:%d', $file, (int) $function->getStartLine() ),
					'file' => $file,
				);
			}

			if ( is_string( $callback ) && ! str_contains( $callback, '::' ) ) {
				return array(
					'name' => $callback . '()',
					'file' => (string) ( new \ReflectionFunction( $callback ) )->getFileName(),
				);
			}

			if ( is_string( $callback ) ) {
				list( $class, $name ) = explode( '::', $callback, 2 );

				$method = new \ReflectionMethod( $class, $name );
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && ( is_object( $callback[0] ) || is_string( $callback[0] ) ) && is_string( $callback[1] ) ) {
				$method = new \ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_object( $callback ) ) {
				$method = new \ReflectionMethod( $callback, '__invoke' );
			} else {
				return array(
					'name' => get_debug_type( $callback ),
					'file' => '',
				);
			}

			return array(
				'name' => $method->getDeclaringClass()->getName() . '::' . $method->getName(),
				'file' => (string) $method->getFileName(),
			);
		} catch ( \ReflectionException $unknown ) {
			return array(
				'name' => is_string( $callback ) ? $callback : get_debug_type( $callback ),
				'file' => '',
			);
		}
	}

	/**
	 * Names what a callback's file belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file The file, or an empty string.
	 * @return string The plugin's directory (or its file, for a single-file plugin), `mu-plugin`,
	 *                `theme`, `core` or `unknown`.
	 */
	private static function owner( string $file ): string {
		if ( '' === $file ) {
			return 'unknown';
		}

		$path = wp_normalize_path( $file );

		if ( null !== self::relativeTo( $path, WPMU_PLUGIN_DIR ) ) {
			return 'mu-plugin';
		}

		// plugin_basename() maps a plugin whose directory is a symlink back to its name under plugins/.
		$basename = plugin_basename( $file );

		if ( trim( $path, '/' ) !== $basename ) {
			return explode( '/', $basename )[0];
		}

		$inPlugins = self::relativeTo( $path, WP_PLUGIN_DIR );

		if ( null !== $inPlugins ) {
			return explode( '/', $inPlugins )[0];
		}

		if ( null !== self::relativeTo( $path, get_theme_root() ) ) {
			return 'theme';
		}

		if ( null !== self::relativeTo( $path, ABSPATH . 'wp-includes' ) || null !== self::relativeTo( $path, ABSPATH . 'wp-admin' ) ) {
			return 'core';
		}

		return 'unknown';
	}

	/**
	 * Returns a file's path relative to a directory it lies in, as written or through symlinks.
	 *
	 * Reflection reports a file's real path, while WordPress's directory constants keep any
	 * symlink they were defined with, so both spellings of the directory are tried.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path      The file's normalized path.
	 * @param string $directory The directory.
	 * @return string|null The path relative to the directory, or null when the file is not in it.
	 */
	private static function relativeTo( string $path, string $directory ): ?string {
		foreach ( array( $directory, (string) realpath( $directory ) ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			$prefix = rtrim( wp_normalize_path( $candidate ), '/' ) . '/';

			if ( str_starts_with( $path, $prefix ) ) {
				return substr( $path, strlen( $prefix ) );
			}
		}

		return null;
	}
}

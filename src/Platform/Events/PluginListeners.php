<?php
/**
 * PluginListeners: puts the plugin's own event listeners on their actions, once, when the first event is dispatched
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Adds each of the plugin's listeners to the action of every event it hears, once per process.
 *
 * Owns one fact: when the plugin's own listeners are on their actions. Not at boot, where each
 * would be a hook on every request, idle ones included. Not where an event is published either:
 * a stored event is delivered by whichever process drains the outbox (the publishing request at
 * its end, the job runner, `wp seocart outbox drain`), and a process that published nothing would
 * deliver it to no listener. The hook bridge calls attach() before it dispatches its first event
 * in the process, so every delivery, wherever it runs, finds the listeners on their actions, and
 * a request that dispatches nothing resolves no listener and adds no hook.
 *
 * A listener is a service with a method `on( DomainEvent $event, EventEnvelope $envelope ): void`.
 * attach() resolves each one, then adds that method at priority 10, with both arguments, to the
 * action of each event it hears. A second call adds nothing. A call that failed part-way may be
 * made again: adding the same method of the same service to an action twice leaves it there once.
 *
 * @since 0.2.0
 */
final class PluginListeners {

	/**
	 * Each listener's class, with the event classes it hears.
	 *
	 * @since 0.2.0
	 *
	 * @var array<class-string, list<class-string<DomainEvent>>>
	 */
	private array $listeners;

	/**
	 * Returns the listener service of a class.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(class-string): object
	 */
	private $resolve;

	/**
	 * Whether the listeners are on their actions.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $attached = false;

	/**
	 * Creates the list. Resolves nothing and adds nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param array<class-string, list<class-string<DomainEvent>>> $listeners Each listener's class, with the event classes it hears.
	 * @param callable                                             $resolve   Returns the listener service of a class (object).
	 *
	 * @phpstan-param callable(class-string): object $resolve
	 */
	public function __construct( array $listeners, callable $resolve ) {
		$this->listeners = $listeners;
		$this->resolve   = $resolve;
	}

	/**
	 * Puts every listener on the actions of the events it hears, the first time it is called.
	 *
	 * @since 0.2.0
	 */
	public function attach(): void {
		if ( $this->attached ) {
			return;
		}

		foreach ( $this->listeners as $class => $events ) {
			$listener = ( $this->resolve )( $class );

			foreach ( $events as $event ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Always `seocart_` followed by a catalogued event name: EventEnvelope::hookFor().
				add_action( EventEnvelope::hookFor( $event::eventName() ), array( $listener, 'on' ), 10, 2 );
			}
		}

		$this->attached = true;
	}
}

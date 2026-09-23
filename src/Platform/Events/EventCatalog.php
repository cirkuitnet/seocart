<?php
/**
 * EventCatalog: the one list of the domain events the plugin publishes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A catalog error names a class for the developer's test run, never HTML.

/**
 * Maps every published event's name to its class, and back.
 *
 * Owns one fact: which events exist. An event class that is not in the catalog cannot be
 * published, and a stored event whose name is not in it cannot be delivered, so every event
 * is either listed here, and so bridged to a `seocart_` action, or not an event at all. The
 * kernel assembles the catalog from the module providers; the hooks reference, doctor and the
 * drainer read the same instance.
 *
 * Constructing it loads each class and asks its name, and does nothing else: no I/O and no
 * WordPress call. A name or a class listed twice fails at construction, so a module's test
 * can prove its events are registered.
 *
 * @since 0.1.0
 */
final class EventCatalog {

	/**
	 * An event name: lowercase snake_case, at most 64 characters, the width of `outbox.event_name`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * Event name => class.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, class-string<DomainEvent>>
	 */
	private array $classes = array();

	/**
	 * Class => event name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $names = array();

	/**
	 * Builds the catalog.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When an entry is not a concrete DomainEvent class, its name
	 *                                   is not lowercase snake_case of at most 64 characters, or a
	 *                                   class or a name is listed twice.
	 *
	 * @param iterable $events The event classes, as class names.
	 *
	 * @phpstan-param iterable<mixed> $events
	 */
	public function __construct( iterable $events ) {
		foreach ( $events as $candidate ) {
			if ( ! is_string( $candidate ) || ! is_subclass_of( $candidate, DomainEvent::class ) ) {
				throw new \InvalidArgumentException( sprintf( 'The event catalog lists %s, which is not a class implementing %s.', is_string( $candidate ) ? $candidate : get_debug_type( $candidate ), DomainEvent::class ) );
			}

			$reflection = new \ReflectionClass( $candidate );

			if ( $reflection->isAbstract() ) {
				throw new \InvalidArgumentException( sprintf( 'The event catalog lists %s, which is abstract.', $candidate ) );
			}

			$class = $reflection->getName();
			$name  = $class::eventName();

			if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
				throw new \InvalidArgumentException( sprintf( 'Event %s is named "%s"; a name is lowercase snake_case of at most 64 characters.', $class, $name ) );
			}

			if ( isset( $this->names[ $class ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'The event catalog lists %s twice.', $class ) );
			}

			if ( isset( $this->classes[ $name ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Events %s and %s are both named "%s".', $this->classes[ $name ], $class, $name ) );
			}

			$this->classes[ $name ] = $class;
			$this->names[ $class ]  = $name;
		}
	}

	/**
	 * Returns the name of a listed event class.
	 *
	 * @since 0.1.0
	 *
	 * @param string $className The class name.
	 * @return string|null The event's name, or null when the class is not listed.
	 */
	public function nameOf( string $className ): ?string {
		return $this->names[ $className ] ?? null;
	}

	/**
	 * Returns the class of a listed event name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The event's name, for example `stock_adjusted`.
	 * @return class-string<DomainEvent>|null The class, or null when no listed event has the name.
	 */
	public function classFor( string $name ): ?string {
		return $this->classes[ $name ] ?? null;
	}

	/**
	 * Returns every listed event name.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names, in the order the events were listed.
	 */
	public function names(): array {
		return array_keys( $this->classes );
	}
}

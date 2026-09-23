<?php
/**
 * Container: builds each service once, from the factory its module bound
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The LogicExceptions below name service ids for the developer who bound them; they are never rendered as HTML.

/**
 * The plugin's service container.
 *
 * Owns one fact: how a service is found and built. Each module binds a factory per service id,
 * normally a class or interface name, and the factory receives the container to resolve what the
 * service needs. A service is built on the first get() and the same instance is returned for the
 * rest of the request. Binding builds nothing, so registering every module costs a request only
 * the closures.
 *
 * The shape follows PSR-11 (get() and has(), string ids, factories that receive the container)
 * without the interface: nothing outside the plugin resolves from it, and an unknown id is a
 * programming error, a \LogicException. There is no autowiring and no reflection: a service that
 * nobody bound does not exist.
 *
 * The overrides given to the constructor win over bind(). They exist for tests, which build a
 * container with a double in place of one service and then run the production bindings on it.
 * Production passes none.
 *
 * @since 0.1.0
 */
final class Container {

	/**
	 * The factories bound by the modules, keyed by service id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, callable(Container): object>
	 */
	private array $factories = array();

	/**
	 * The factories given to the constructor, keyed by service id. They win over bound ones.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, callable(Container): object>
	 */
	private array $overrides;

	/**
	 * The services built so far, keyed by service id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * The ids whose factories are running, so that a cycle fails instead of recursing forever.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Creates an empty container.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, callable(Container): object> $overrides Optional. Factories that replace whatever
	 *                                                              is bound under the same id. Default none.
	 */
	public function __construct( array $overrides = array() ) {
		$this->overrides = $overrides;
	}

	/**
	 * Binds the factory of a service. Builds nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the id is already bound: one service, one factory.
	 *
	 * @param string                     $id      The service id, normally the class or interface name.
	 * @param callable(Container):object $factory Builds the service; receives this container.
	 */
	public function bind( string $id, callable $factory ): void {
		if ( isset( $this->factories[ $id ] ) ) {
			throw new \LogicException( sprintf( 'The service %s is already bound; a service has one factory.', $id ) );
		}

		$this->factories[ $id ] = $factory;
	}

	/**
	 * Returns a service, building it on the first call.
	 *
	 * When the id names a class or an interface, the service must be an instance of it.
	 *
	 * @since 0.1.0
	 *
	 * @template T of object
	 *
	 * @throws \LogicException When nothing is bound under the id, when resolving it needs itself, or
	 *                         when its factory built something of another type.
	 *
	 * @param string $id The service id.
	 * @return object The service.
	 *
	 * @phpstan-param class-string<T> $id
	 * @phpstan-return T
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			/**
			 * The service built earlier under this id, checked then.
			 *
			 * @var T $built
			 */
			$built = $this->instances[ $id ];

			return $built;
		}

		$factory = $this->overrides[ $id ] ?? $this->factories[ $id ] ?? null;

		if ( null === $factory ) {
			throw new \LogicException( sprintf( 'Nothing is bound under the service id %s.', $id ) );
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new \LogicException( sprintf( 'The service %s needs itself to be built.', $id ) );
		}

		$this->resolving[ $id ] = true;

		try {
			$service = $factory( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		if ( ( class_exists( $id ) || interface_exists( $id ) ) && ! $service instanceof $id ) {
			throw new \LogicException( sprintf( 'The factory of %1$s built a %2$s.', $id, get_class( $service ) ) );
		}

		$this->instances[ $id ] = $service;

		/**
		 * The service, checked above against its id when the id names a type.
		 *
		 * @var T $service
		 */
		return $service;
	}

	/**
	 * Tells whether a factory is bound or given under an id.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id The service id.
	 * @return bool True when get() can build it.
	 */
	public function has( string $id ): bool {
		return isset( $this->overrides[ $id ] ) || isset( $this->factories[ $id ] );
	}
}

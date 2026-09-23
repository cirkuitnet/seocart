<?php
/**
 * OperationRegistry: the operations the plugin exposes, built only when a surface asks
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

use SEOCart\Support\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

/**
 * Holds one factory per operation and builds the definitions on first use.
 *
 * This class owns one fact: which operations exist, each under exactly one id and each surface
 * address used by exactly one of them. Adding an operation stores its factory and its id and
 * builds nothing, so a request that registers no REST route, no ability and no command pays for
 * no definition. all() builds every definition once and then refuses the whole set when:
 *
 * - a factory returns something other than an OperationDefinition, or a definition under an id
 *   other than the one it was added with;
 * - two operations share a REST route and method, an ability or a command.
 *
 * Every adapter registers its surface from all(), so a registry that breaks these rules fails the
 * first time any surface is registered — before WordPress could serve one operation under
 * another's address.
 *
 * @since 0.1.0
 */
final class OperationRegistry {

	/**
	 * The factories, keyed by operation id, in the order they were added.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, callable(): OperationDefinition>
	 */
	private array $factories = array();

	/**
	 * The definitions, once built and checked.
	 *
	 * @since 0.1.0
	 *
	 * @var list<OperationDefinition>|null
	 */
	private ?array $definitions = null;

	/**
	 * Adds an operation's factory.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the id is already registered, or the definitions were already built.
	 *
	 * @param string   $id      The operation id the factory's definition declares.
	 * @param callable $factory Builds the definition. Called once, by all().
	 *
	 * @phpstan-param callable(): OperationDefinition $factory
	 */
	public function add( string $id, callable $factory ): void {
		if ( null !== $this->definitions ) {
			SchemaException::raise( 'The operation %1$s is added after the registry was built; add every operation before the first surface is registered.', $id );
		}

		if ( isset( $this->factories[ $id ] ) ) {
			SchemaException::raise( 'The operation %1$s is registered twice.', $id );
		}

		$this->factories[ $id ] = $factory;
	}

	/**
	 * Returns every definition, building them on the first call.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When a factory breaks its id, or two operations share an address.
	 *
	 * @return list<OperationDefinition> The definitions, in the order they were added.
	 */
	public function all(): array {
		if ( null !== $this->definitions ) {
			return $this->definitions;
		}

		$definitions = array();
		$addresses   = array();

		foreach ( $this->factories as $id => $factory ) {
			$definition = $factory();

			// @phpstan-ignore instanceof.alwaysTrue (A factory is module code; the wrong return type must be refused here, by name.)
			if ( ! $definition instanceof OperationDefinition || $definition->id() !== $id ) {
				SchemaException::raise( 'The factory registered as %1$s does not return the definition of %1$s.', (string) $id );
			}

			foreach ( self::addresses( $definition ) as $address ) {
				if ( isset( $addresses[ $address ] ) ) {
					SchemaException::raise( 'The operations %1$s and %2$s share the address %3$s.', $addresses[ $address ], (string) $id, $address );
				}

				$addresses[ $address ] = (string) $id;
			}

			$definitions[] = $definition;
		}

		$this->definitions = $definitions;

		return $definitions;
	}

	/**
	 * Lists the surface addresses one definition occupies.
	 *
	 * Two routes that differ only in the names of their parameters are one address, because
	 * WordPress would match the same URLs to both.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The definition.
	 * @return list<string> Its REST, ability and command addresses.
	 */
	private static function addresses( OperationDefinition $definition ): array {
		$addresses = array();
		$rest      = $definition->rest();

		if ( null !== $rest ) {
			$addresses[] = 'REST ' . $definition->httpMethod() . ' ' . preg_replace( '/\{[^}]*\}/', '{}', $rest->route() );
		}

		if ( null !== $definition->abilityName() ) {
			$addresses[] = 'ability ' . $definition->abilityName();
		}

		if ( null !== $definition->cli() ) {
			$addresses[] = 'command wp ' . $definition->cli()->command();
		}

		return $addresses;
	}
}

<?php
/**
 * CapabilityMapper: the one map_meta_cap callback, which fails closed on the plugin's capabilities
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * Maps every check on a plugin capability to primitives, or to a denial.
 *
 * This class owns one fact: how a check on a capability in the plugin's namespace becomes the
 * list of primitives core tests against the user. It is the single rule engine behind every
 * permission callback, admin screen and CLI command, because each of them ends in
 * current_user_can(), and current_user_can() ends here. The kernel hooks map() to
 * `map_meta_cap` once:
 *
 *     add_filter( 'map_meta_cap', array( $mapper, 'map' ), 10, 4 );
 *
 * For a capability in the plugin's namespace (CapabilityDeclaration::isPluginCapability()):
 *
 * - a declared primitive passes through unchanged;
 * - a registered meta capability is resolved by its module's resolver, and denied when the
 *   resource cannot be resolved or the resolver names a capability the plugin never declared;
 * - anything else is denied with `do_not_allow`, which core honours even for a multisite super
 *   admin. That covers an unknown name, a meta capability nobody registered, and one of the
 *   product post type's meta capabilities checked while the post type is not registered.
 *
 * Every other capability, core's and other plugins', is left exactly as it came. Core maps the
 * product post type's meta capabilities itself, through ProductCapabilities::map(), before this
 * filter sees them, and the filter then sees `edit_post`, `read_post` or `delete_post`, which
 * it leaves alone.
 *
 * Resolvers are registered as factories and built on first use, so hooking the mapper costs an
 * idle request nothing beyond the objects themselves.
 *
 * @since 0.1.0
 */
final class CapabilityMapper {

	/**
	 * The capability core never grants to anyone.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DENY = 'do_not_allow';

	/**
	 * The declaration that says what the plugin's capabilities are.
	 *
	 * @since 0.1.0
	 *
	 * @var CapabilityDeclaration
	 */
	private CapabilityDeclaration $declaration;

	/**
	 * Resolver factories, keyed by the meta capability they resolve.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, callable(): MetaCapabilityResolver>
	 */
	private array $factories = array();

	/**
	 * Resolvers already built, keyed by the meta capability they resolve.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, MetaCapabilityResolver>
	 */
	private array $resolvers = array();

	/**
	 * Creates the mapper.
	 *
	 * @since 0.1.0
	 *
	 * @param CapabilityDeclaration $declaration The plugin's capability declaration.
	 */
	public function __construct( CapabilityDeclaration $declaration ) {
		$this->declaration = $declaration;
	}

	/**
	 * Registers the resolver of a plugin meta capability.
	 *
	 * @since 0.1.0
	 *
	 * @param string                             $capability The meta capability, in the plugin's namespace.
	 * @param callable(): MetaCapabilityResolver $factory Builds the resolver on the first check that needs it.
	 *
	 * @throws \InvalidArgumentException When the name is outside the plugin's namespace, is already a
	 *                                   primitive or one of the product post type's meta capabilities,
	 *                                   or already has a resolver.
	 */
	public function registerMetaCapability( string $capability, callable $factory ): void {
		if ( ! $this->declaration->isPluginCapability( $capability ) ) {
			throw new \InvalidArgumentException( 'A meta capability must be named in the plugin\'s namespace.' );
		}

		if ( $this->declaration->isPrimitive( $capability ) ) {
			throw new \InvalidArgumentException( 'A primitive capability, which roles are granted, cannot also be a meta capability.' );
		}

		if ( $this->declaration->isMetaCapability( $capability ) ) {
			throw new \InvalidArgumentException( 'The product post type\'s meta capabilities are mapped by core, not by a resolver.' );
		}

		if ( isset( $this->factories[ $capability ] ) ) {
			throw new \InvalidArgumentException( 'A meta capability can have one resolver only.' );
		}

		$this->factories[ $capability ] = $factory;
	}

	/**
	 * Tells whether a meta capability has a registered resolver.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability A capability name.
	 * @return bool True once registerMetaCapability() has accepted it.
	 */
	public function isRegisteredMetaCapability( string $capability ): bool {
		return isset( $this->factories[ $capability ] );
	}

	/**
	 * Filters `map_meta_cap`: maps a plugin capability to primitives, or denies it.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]          $caps   The primitives core mapped the capability to.
	 * @param string            $cap    The capability being checked.
	 * @param int               $userId The user being checked.
	 * @param array<int, mixed> $args   What followed the capability in the check, the resource first.
	 * @return string[] The primitives the user must hold, or `do_not_allow` alone.
	 */
	public function map( array $caps, string $cap, int $userId, array $args ): array {
		if ( ! $this->declaration->isPluginCapability( $cap ) || $this->declaration->isPrimitive( $cap ) ) {
			return $caps;
		}

		if ( ! isset( $this->factories[ $cap ] ) ) {
			return array( self::DENY );
		}

		$primitives = $this->resolver( $cap )->primitivesFor( $userId, $args );

		if ( null === $primitives || array() === $primitives ) {
			return array( self::DENY );
		}

		foreach ( $primitives as $primitive ) {
			// A resolver may name core primitives, but never a plugin capability that is not a declared primitive.
			if ( $this->declaration->isPluginCapability( $primitive ) && ! $this->declaration->isPrimitive( $primitive ) ) {
				return array( self::DENY );
			}
		}

		return $primitives;
	}

	/**
	 * Returns the resolver of a registered meta capability, building it on first use.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability A registered meta capability.
	 * @return MetaCapabilityResolver The resolver.
	 */
	private function resolver( string $capability ): MetaCapabilityResolver {
		if ( ! isset( $this->resolvers[ $capability ] ) ) {
			$this->resolvers[ $capability ] = ( $this->factories[ $capability ] )();
		}

		return $this->resolvers[ $capability ];
	}
}

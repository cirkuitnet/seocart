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
 * - one of the plugin's declared meta capabilities with a registered resolver maps to the
 *   primitives the resolver names. It is denied when the resolver cannot resolve the resource,
 *   when building or asking the resolver fails, and when the answer names anything but declared
 *   plugin primitives: a core capability such as `read` would admit every customer;
 * - anything else is denied with `do_not_allow`, which core honours even for a multisite super
 *   admin. That covers an unknown name, a declared meta capability no module has registered a
 *   resolver for yet, and one of the product post type's meta capabilities checked while the
 *   post type is not registered.
 *
 * Every other capability, core's and other plugins', is left exactly as it came, and so is
 * anything that is not a capability name at all: a foreign bug such as `current_user_can( null )`
 * stays core's to answer and never becomes an exception here. Core maps the product post type's
 * meta capabilities itself, through ProductCapabilities::map(), before this filter sees them,
 * and the filter then sees `edit_post`, `read_post` or `delete_post`, which it leaves alone.
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
	 * @param string                             $capability One of CapabilityDeclaration::pluginMetaCapabilities().
	 * @param callable(): MetaCapabilityResolver $factory    Builds the resolver on the first check that needs it.
	 *
	 * @throws \InvalidArgumentException When the capability is not one of the plugin's declared meta
	 *                                   capabilities, or already has a resolver.
	 */
	public function registerMetaCapability( string $capability, callable $factory ): void {
		if ( ! $this->declaration->isPluginMetaCapability( $capability ) ) {
			throw new \InvalidArgumentException( 'Only a meta capability that CapabilityDeclaration declares for the plugin to map can have a resolver.' );
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
	 * The parameters are not typed: the values arrive from whatever called current_user_can() and
	 * from the filters that ran before this one, and a wrong type there is someone else's bug,
	 * which must not turn a capability check into an exception.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $caps   The primitives core mapped the capability to: string[] from core.
	 * @param mixed $cap    The capability being checked: a string from any correct caller.
	 * @param mixed $userId The user being checked: an int from core.
	 * @param mixed $args   What followed the capability in the check, the resource first: an array from core.
	 * @return mixed `$caps` unchanged for anything that is not a plugin capability or is a plugin
	 *               primitive; otherwise the declared primitives the user must hold, or
	 *               `do_not_allow` alone.
	 */
	public function map( $caps, $cap, $userId, $args ) {
		if ( ! is_string( $cap ) || ! $this->declaration->isPluginCapability( $cap ) || $this->declaration->isPrimitive( $cap ) ) {
			return $caps;
		}

		if ( ! isset( $this->factories[ $cap ] ) ) {
			return array( self::DENY );
		}

		try {
			$primitives = $this->resolver( $cap )->primitivesFor( is_numeric( $userId ) ? (int) $userId : 0, is_array( $args ) ? $args : array() );
		} catch ( \Throwable $failure ) {
			// A resolver that cannot answer has not granted anything. The logger arrives with the logging module.
			return array( self::DENY );
		}

		if ( null === $primitives || array() === $primitives ) {
			return array( self::DENY );
		}

		foreach ( $primitives as $primitive ) {
			// @phpstan-ignore function.alreadyNarrowedType (A resolver is module code; an answer of the wrong type must deny, not throw.)
			if ( ! is_string( $primitive ) || ! $this->declaration->isPrimitive( $primitive ) ) {
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

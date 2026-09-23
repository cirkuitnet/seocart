<?php
/**
 * Tests the map_meta_cap callback as a function of its arguments
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Authorization;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityMapper;
use SEOCart\Platform\Authorization\MetaCapabilityResolver;

/**
 * Proves the mapping rules without WordPress: the callback reads only its arguments.
 *
 * The integration test of the same name proves the rules through current_user_can(), with
 * real roles.
 *
 * @since 0.1.0
 */
final class CapabilityMapperTest extends TestCase {

	/**
	 * The mapper under test.
	 *
	 * @since 0.1.0
	 *
	 * @var CapabilityMapper
	 */
	private CapabilityMapper $mapper;

	/**
	 * Builds the mapper.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new CapabilityMapper( new CapabilityDeclaration() );
	}

	/**
	 * Tests that a capability outside the plugin's namespace comes back exactly as core mapped it.
	 *
	 * This includes core's `edit_post` on a product, which core has already mapped through the
	 * product post type's capability map by the time the filter runs.
	 *
	 * @since 0.1.0
	 */
	public function test_capabilities_outside_the_namespace_pass_through_untouched(): void {
		$this->assertSame( array( 'edit_others_seocart_products', 'edit_published_seocart_products' ), $this->mapper->map( array( 'edit_others_seocart_products', 'edit_published_seocart_products' ), 'edit_post', 1, array( 5 ) ) );
		$this->assertSame( array( 'manage_options' ), $this->mapper->map( array( 'manage_options' ), 'manage_options', 1, array() ) );
		$this->assertSame( array( 'do_not_allow' ), $this->mapper->map( array( 'do_not_allow' ), 'edit_post', 1, array() ) );
		$this->assertSame( array( 'manage_woocommerce' ), $this->mapper->map( array( 'manage_woocommerce' ), 'manage_woocommerce', 1, array() ) );
	}

	/**
	 * Tests that a declared primitive passes through, with or without a resource.
	 *
	 * @since 0.1.0
	 */
	public function test_declared_primitives_pass_through(): void {
		$this->assertSame( array( 'seocart_view_orders' ), $this->mapper->map( array( 'seocart_view_orders' ), 'seocart_view_orders', 1, array() ) );
		$this->assertSame( array( 'edit_others_seocart_products' ), $this->mapper->map( array( 'edit_others_seocart_products' ), 'edit_others_seocart_products', 1, array( 9 ) ) );
	}

	/**
	 * Tests that anything else in the plugin's namespace is denied.
	 *
	 * Planted violation: in CapabilityMapper::map(), make the final branch return `$caps`
	 * instead of `array( self::DENY )` when no resolver is registered.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider unknownPluginCapabilities
	 *
	 * @param string $capability A plugin capability that is neither a primitive nor a registered meta capability.
	 */
	public function test_unknown_plugin_capabilities_are_denied( string $capability ): void {
		$this->assertSame( array( CapabilityMapper::DENY ), $this->mapper->map( array( $capability ), $capability, 1, array( 5 ) ) );
	}

	/**
	 * Provides plugin capabilities that nothing declares or registers.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}>
	 */
	public function unknownPluginCapabilities(): array {
		return array(
			'unknown with the prefix'                    => array( 'seocart_frobnicate' ),
			'unknown on the product base'                => array( 'frobnicate_seocart_products' ),
			'future meta capability, not registered'     => array( 'seocart_view_order' ),
			'product meta capability, type unregistered' => array( 'edit_seocart_product' ),
		);
	}

	/**
	 * Tests that a registered meta capability maps to what its resolver names.
	 *
	 * @since 0.1.0
	 */
	public function test_a_registered_meta_capability_maps_through_its_resolver(): void {
		$this->mapper->registerMetaCapability( 'seocart_selftest_view_widget', self::resolverFactory( array( 42 => array( 'seocart_view_orders', 'read' ) ) ) );

		$this->assertTrue( $this->mapper->isRegisteredMetaCapability( 'seocart_selftest_view_widget' ) );
		$this->assertSame( array( 'seocart_view_orders', 'read' ), $this->mapper->map( array( 'seocart_selftest_view_widget' ), 'seocart_selftest_view_widget', 3, array( 42 ) ) );
	}

	/**
	 * Tests that a resource the resolver cannot resolve is denied.
	 *
	 * Planted violation: in CapabilityMapper::map(), return `$caps` instead of
	 * `array( self::DENY )` when the resolver returns null.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unresolvable_resource_is_denied(): void {
		$this->mapper->registerMetaCapability( 'seocart_selftest_view_widget', self::resolverFactory( array( 42 => array( 'seocart_view_orders' ) ) ) );

		$this->assertSame( array( CapabilityMapper::DENY ), $this->mapper->map( array( 'seocart_selftest_view_widget' ), 'seocart_selftest_view_widget', 3, array( 7 ) ), 'An unknown resource.' );
		$this->assertSame( array( CapabilityMapper::DENY ), $this->mapper->map( array( 'seocart_selftest_view_widget' ), 'seocart_selftest_view_widget', 3, array() ), 'No resource at all.' );
	}

	/**
	 * Tests that a resolver answer which would widen access is denied instead.
	 *
	 * An empty list would require nothing, so it would allow everyone. A plugin capability that
	 * is not a declared primitive would be checked against the roles as it is, with no mapping.
	 *
	 * @since 0.1.0
	 */
	public function test_a_resolver_answer_that_would_widen_access_is_denied(): void {
		$this->mapper->registerMetaCapability(
			'seocart_selftest_view_widget',
			self::resolverFactory(
				array(
					1 => array(),
					2 => array( 'seocart_frobnicate' ),
					3 => array( 'seocart_view_orders', 'edit_seocart_product' ),
				)
			)
		);

		foreach ( array( 1, 2, 3 ) as $resource ) {
			$this->assertSame( array( CapabilityMapper::DENY ), $this->mapper->map( array(), 'seocart_selftest_view_widget', 3, array( $resource ) ), "Resource {$resource}." );
		}
	}

	/**
	 * Tests that a resolver is built on the first check that needs it, and only once.
	 *
	 * @since 0.1.0
	 */
	public function test_a_resolver_is_built_lazily_and_once(): void {
		$built   = 0;
		$factory = self::resolverFactory( array( 42 => array( 'read' ) ) );

		$this->mapper->registerMetaCapability(
			'seocart_selftest_view_widget',
			static function () use ( &$built, $factory ): MetaCapabilityResolver {
				++$built;

				return $factory();
			}
		);

		$this->assertSame( 0, $built, 'Registering built the resolver.' );

		$this->mapper->map( array(), 'seocart_selftest_view_widget', 1, array( 42 ) );
		$this->mapper->map( array(), 'seocart_selftest_view_widget', 1, array( 7 ) );

		$this->assertSame( 1, $built );
	}

	/**
	 * Tests that registration refuses a name that cannot be a plugin meta capability.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider unregistrableNames
	 *
	 * @param string $capability The name to register.
	 */
	public function test_registration_refuses_names_that_cannot_be_plugin_meta_capabilities( string $capability ): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->mapper->registerMetaCapability( $capability, self::resolverFactory( array() ) );
	}

	/**
	 * Provides names that registerMetaCapability() must refuse.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}>
	 */
	public function unregistrableNames(): array {
		return array(
			'outside the namespace'     => array( 'edit_post' ),
			'a declared primitive'      => array( 'seocart_view_orders' ),
			'a product meta capability' => array( 'edit_seocart_product' ),
		);
	}

	/**
	 * Tests that one meta capability cannot get a second resolver.
	 *
	 * @since 0.1.0
	 */
	public function test_registration_refuses_a_second_resolver(): void {
		$this->mapper->registerMetaCapability( 'seocart_selftest_view_widget', self::resolverFactory( array() ) );

		$this->expectException( \InvalidArgumentException::class );

		$this->mapper->registerMetaCapability( 'seocart_selftest_view_widget', self::resolverFactory( array() ) );
	}

	/**
	 * Returns a factory for a resolver that knows a fixed set of resources.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, list<string>> $primitives The primitives each known resource maps to, keyed by its id.
	 * @return callable(): MetaCapabilityResolver The factory.
	 */
	private static function resolverFactory( array $primitives ): callable {
		$resolver = new class( $primitives ) implements MetaCapabilityResolver {

			/**
			 * The primitives each known resource maps to.
			 *
			 * @since 0.1.0
			 *
			 * @var array<int, list<string>>
			 */
			private array $primitives;

			/**
			 * Creates the resolver.
			 *
			 * @since 0.1.0
			 *
			 * @param array<int, list<string>> $primitives The primitives each known resource maps to.
			 */
			public function __construct( array $primitives ) {
				$this->primitives = $primitives;
			}

			/**
			 * Maps a known resource to its primitives.
			 *
			 * @since 0.1.0
			 *
			 * @param int               $userId The user being checked.
			 * @param array<int, mixed> $args   The resource id first.
			 * @return list<string>|null The primitives, or null for an unknown resource.
			 */
			public function primitivesFor( int $userId, array $args ): ?array {
				$resource = $args[0] ?? null;

				return is_int( $resource ) && isset( $this->primitives[ $resource ] ) ? $this->primitives[ $resource ] : null;
			}
		};

		return static fn(): MetaCapabilityResolver => $resolver;
	}
}

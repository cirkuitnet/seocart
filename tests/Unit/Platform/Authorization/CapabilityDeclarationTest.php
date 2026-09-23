<?php
/**
 * Tests the capability declaration: the vocabulary and the role bundles
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Authorization;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\ProductCapabilities;

/**
 * Pins the declaration to the security model, and proves it is data.
 *
 * The expected lists below are the specification, security.md §4.2 and §4.3, restated once
 * on purpose: a test that derived them from the class under test would prove nothing. Two
 * additions to the written model are pinned here too: `edit_private_seocart_products`, which
 * core's map_meta_cap() uses to decide who may edit someone else's private product, and core's
 * `read` on every shipped role, without which WordPress refuses the user the dashboard and
 * their own profile.
 *
 * @since 0.1.0
 */
final class CapabilityDeclarationTest extends TestCase {

	/**
	 * The vocabulary, by group.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, list<string>>
	 */
	private const VOCABULARY = array(
		CapabilityDeclaration::GROUP_CATALOG     => array(
			'edit_seocart_products',
			'edit_others_seocart_products',
			'edit_published_seocart_products',
			'edit_private_seocart_products',
			'publish_seocart_products',
			'read_private_seocart_products',
			'delete_seocart_products',
			'delete_others_seocart_products',
			'delete_published_seocart_products',
			'delete_private_seocart_products',
			'seocart_manage_catalog',
			'seocart_manage_inventory',
		),
		CapabilityDeclaration::GROUP_ORDERS      => array(
			'seocart_view_orders',
			'seocart_edit_orders',
			'seocart_create_orders',
			'seocart_delete_orders',
			'seocart_manage_fulfillment',
		),
		CapabilityDeclaration::GROUP_MONEY       => array(
			'seocart_capture_payments',
			'seocart_void_payments',
			'seocart_refund_orders',
			'seocart_manage_stored_value',
			'seocart_override_money_state',
		),
		CapabilityDeclaration::GROUP_SENSITIVITY => array(
			'seocart_view_customer_pii',
			'seocart_view_payment_details',
			'seocart_export_customers',
			'seocart_export_orders',
		),
		CapabilityDeclaration::GROUP_STORE       => array(
			'seocart_manage_marketing',
			'seocart_manage_settings',
			'seocart_manage_secrets',
			'seocart_manage_appearance',
			'seocart_view_reports',
			'seocart_manage_integrations',
		),
	);

	/**
	 * The declaration under test.
	 *
	 * @since 0.1.0
	 *
	 * @var CapabilityDeclaration
	 */
	private CapabilityDeclaration $declaration;

	/**
	 * Builds the declaration.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->declaration = new CapabilityDeclaration();
	}

	/**
	 * Tests that the whole declaration is built and read with WordPress absent (DRY rule 12).
	 *
	 * Nothing is stubbed: a WordPress call would be a fatal error for an undefined function.
	 *
	 * @since 0.1.0
	 */
	public function test_it_is_built_and_read_with_wordpress_absent(): void {
		$this->assertFalse( function_exists( 'add_role' ), 'WordPress is loaded in the unit suite, so this test would prove nothing.' );
		$this->assertFalse( function_exists( '__' ), 'WordPress is loaded in the unit suite, so this test would prove nothing.' );

		$declaration = new CapabilityDeclaration();

		foreach ( $declaration->roles() as $role ) {
			$this->assertSame( $declaration->isShippedRole( $role ), null !== $declaration->roleName( $role ), "The {$role} role has a name exactly when the plugin ships it." );
			$this->assertNotSame( array(), $declaration->bundle( $role ), "The {$role} role has an empty bundle." );
		}

		foreach ( $declaration->primitives() as $capability ) {
			$this->assertNotNull( $declaration->group( $capability ), $capability );
		}

		$this->assertNotSame( array(), $declaration->metaCapabilities() );
	}

	/**
	 * Tests that the vocabulary is exactly the security model's, group by group.
	 *
	 * @since 0.1.0
	 */
	public function test_the_vocabulary_is_the_security_models(): void {
		$actual = array();

		foreach ( $this->declaration->primitives() as $capability ) {
			$actual[ (string) $this->declaration->group( $capability ) ][] = $capability;
		}

		$this->assertEqualsCanonicalizing( array_keys( self::VOCABULARY ), array_keys( $actual ), 'The groups differ.' );

		foreach ( self::VOCABULARY as $group => $capabilities ) {
			$this->assertEqualsCanonicalizing( $capabilities, $actual[ $group ], "The {$group} group differs." );
		}

		$this->assertSame( count( $this->declaration->primitives() ), count( array_unique( $this->declaration->primitives() ) ), 'A primitive is listed twice.' );
	}

	/**
	 * Tests that the product post type's primitives come from its capability map, not from a second list.
	 *
	 * @since 0.1.0
	 */
	public function test_the_product_primitives_are_the_capability_maps(): void {
		foreach ( ProductCapabilities::primitives() as $capability ) {
			$this->assertSame( CapabilityDeclaration::GROUP_CATALOG, $this->declaration->group( $capability ), $capability );
		}

		$this->assertSame(
			ProductCapabilities::primitives(),
			array_slice( $this->declaration->primitives(), 0, count( ProductCapabilities::primitives() ) )
		);
	}

	/**
	 * Tests that meta capabilities are declared apart, as security.md §4.4 names them, and never granted to a role.
	 *
	 * @since 0.1.0
	 */
	public function test_meta_capabilities_are_never_primitives_nor_granted(): void {
		$this->assertSame(
			array( 'seocart_view_order', 'seocart_edit_order', 'seocart_refund_order', 'seocart_view_customer' ),
			$this->declaration->pluginMetaCapabilities(),
			'The meta capabilities the plugin maps itself.'
		);

		$this->assertSame(
			array_merge( ProductCapabilities::metaCapabilities(), $this->declaration->pluginMetaCapabilities() ),
			$this->declaration->metaCapabilities(),
			'Every meta capability: the product post type\'s, which core maps, then the plugin\'s own.'
		);

		foreach ( ProductCapabilities::metaCapabilities() as $meta ) {
			$this->assertTrue( $this->declaration->isMetaCapability( $meta ), $meta );
			$this->assertFalse( $this->declaration->isPluginMetaCapability( $meta ), $meta . ' is core\'s to map, not a resolver\'s.' );
		}

		foreach ( $this->declaration->pluginMetaCapabilities() as $meta ) {
			$this->assertTrue( $this->declaration->isPluginMetaCapability( $meta ), $meta );
			$this->assertTrue( $this->declaration->isPluginCapability( $meta ), $meta . ' is outside the plugin\'s namespace, so the mapper would never see it.' );
		}

		$this->assertFalse( $this->declaration->isMetaCapability( 'seocart_view_orders' ), 'A primitive is not a meta capability.' );
		$this->assertFalse( $this->declaration->isMetaCapability( 'edit_post' ), 'Core\'s own meta capability is not the plugin\'s.' );

		foreach ( $this->declaration->metaCapabilities() as $meta ) {
			$this->assertTrue( $this->declaration->isMetaCapability( $meta ), $meta );
			$this->assertFalse( $this->declaration->isPrimitive( $meta ), $meta . ' is declared as a primitive as well.' );

			foreach ( $this->declaration->roles() as $role ) {
				$this->assertNotContains( $meta, $this->declaration->bundle( $role ), "The {$role} role is granted the meta capability {$meta}." );
			}
		}
	}

	/**
	 * Tests that the administrator is granted every primitive, and nothing core does not already give it.
	 *
	 * @since 0.1.0
	 */
	public function test_administrator_is_granted_everything(): void {
		$this->assertEqualsCanonicalizing( $this->declaration->primitives(), $this->declaration->bundle( 'administrator' ) );
		$this->assertFalse( $this->declaration->isShippedRole( 'administrator' ), 'The administrator role is core\'s: it must never be created or renamed.' );
		$this->assertNull( $this->declaration->roleName( 'administrator' ) );
	}

	/**
	 * Tests that the store manager is granted everything except writing secrets.
	 *
	 * @since 0.1.0
	 */
	public function test_store_manager_is_granted_everything_but_secrets(): void {
		$expected = array_merge( array( 'read' ), array_values( array_diff( $this->declaration->primitives(), array( 'seocart_manage_secrets' ) ) ) );

		$this->assertEqualsCanonicalizing( $expected, $this->declaration->bundle( 'seocart_manager' ) );
	}

	/**
	 * Tests the bundles of the narrow roles, against the security model.
	 *
	 * @since 0.1.0
	 */
	public function test_the_narrow_roles_carry_exactly_their_bundles(): void {
		$this->assertEqualsCanonicalizing(
			array( 'read', 'seocart_view_orders', 'seocart_edit_orders', 'seocart_view_customer_pii', 'seocart_manage_fulfillment', 'seocart_capture_payments', 'seocart_refund_orders' ),
			$this->declaration->bundle( 'seocart_order_agent' ),
			'Order agent'
		);

		$this->assertEqualsCanonicalizing(
			array_merge( array( 'read' ), self::VOCABULARY[ CapabilityDeclaration::GROUP_CATALOG ] ),
			$this->declaration->bundle( 'seocart_catalog_editor' ),
			'Catalog editor: the catalog group, which includes managing inventory, and no order or customer visibility.'
		);

		$this->assertEqualsCanonicalizing(
			array( 'read', 'seocart_view_reports', 'seocart_view_orders' ),
			$this->declaration->bundle( 'seocart_reporter' ),
			'Reporter: no PII capability, so detail screens and exports redact.'
		);

		$this->assertSame( array( 'read' ), $this->declaration->bundle( 'seocart_customer' ), 'Customer: no admin capability at all.' );
	}

	/**
	 * Tests that every bundle is made of declared primitives, plus core's `read` on the shipped roles.
	 *
	 * @since 0.1.0
	 */
	public function test_every_bundle_is_made_of_declared_primitives(): void {
		foreach ( $this->declaration->roles() as $role ) {
			foreach ( $this->declaration->bundle( $role ) as $capability ) {
				$this->assertTrue(
					$this->declaration->isPrimitive( $capability ) || ( 'read' === $capability && $this->declaration->isShippedRole( $role ) ),
					"The {$role} role is granted {$capability}, which the vocabulary does not declare."
				);
			}
		}
	}

	/**
	 * Tests the shipped roles and their untranslated display names.
	 *
	 * @since 0.1.0
	 */
	public function test_the_shipped_roles_and_their_names(): void {
		$this->assertSame(
			array( 'administrator', 'seocart_manager', 'seocart_order_agent', 'seocart_catalog_editor', 'seocart_reporter', 'seocart_customer' ),
			$this->declaration->roles()
		);

		$this->assertSame( 'Store manager', $this->declaration->roleName( 'seocart_manager' ) );
		$this->assertSame( 'Order agent', $this->declaration->roleName( 'seocart_order_agent' ) );
		$this->assertSame( 'Catalog editor', $this->declaration->roleName( 'seocart_catalog_editor' ) );
		$this->assertSame( 'Reporter', $this->declaration->roleName( 'seocart_reporter' ) );
		$this->assertSame( 'Store customer', $this->declaration->roleName( 'seocart_customer' ) );

		$this->assertNull( $this->declaration->roleName( 'editor' ), 'A role the declaration does not know has no name.' );
		$this->assertSame( array(), $this->declaration->bundle( 'editor' ), 'A role the declaration does not know has no bundle.' );
	}

	/**
	 * Tests which names lie in the plugin's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider namespaceCases
	 *
	 * @param string $capability A capability name.
	 * @param bool   $expected   Whether it is the plugin's.
	 */
	public function test_the_plugin_namespace( string $capability, bool $expected ): void {
		$this->assertSame( $expected, $this->declaration->isPluginCapability( $capability ) );
	}

	/**
	 * Provides names inside and outside the plugin's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function namespaceCases(): array {
		return array(
			'declared primitive'          => array( 'seocart_view_orders', true ),
			'unknown with the prefix'     => array( 'seocart_frobnicate', true ),
			'product primitive'           => array( 'edit_others_seocart_products', true ),
			'product meta capability'     => array( 'edit_seocart_product', true ),
			'unknown on the product base' => array( 'frobnicate_seocart_products', true ),
			'core primitive'              => array( 'edit_posts', false ),
			'core meta capability'        => array( 'edit_post', false ),
			'another plugin'              => array( 'manage_woocommerce', false ),
			'prefix inside another name'  => array( 'acme_seocart_bridge', false ),
			'base without the separator'  => array( 'editseocart_products', false ),
			'core read'                   => array( 'read', false ),
			'core never-grant'            => array( 'do_not_allow', false ),
			'empty'                       => array( '', false ),
		);
	}
}

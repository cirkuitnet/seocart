<?php
/**
 * CapabilityDeclaration: the plugin's capability vocabulary and the role bundles built from it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * The one declaration of what may be granted, and to which role.
 *
 * This class owns one fact: the plugin's capabilities and the bundles its roles carry. The
 * installer grants from it, the map_meta_cap callback denies whatever it does not declare, a
 * permission callback refuses to be built for anything it does not declare, and the ownership
 * registry and the deletion job read the same lists, so no second copy of the vocabulary
 * exists anywhere.
 *
 * Primitives are what roles are granted. Meta capabilities are checked on one resource and are
 * never role-assignable. The product post type's three are declared by ProductCapabilities and
 * mapped by core. The plugin's own are declared here and mapped by the resolvers that modules
 * register with CapabilityMapper; until a module registers one, it is denied. A capability that
 * is in the plugin's namespace and is neither a primitive nor a meta capability is unknown, and
 * unknown means denied.
 *
 * No code ever asks whether a user has one of these roles. A role exists only to make a set of
 * capabilities assignable, and a merchant may edit or replace it.
 *
 * Declarations are data: building this object does no I/O, calls no WordPress function and
 * translates nothing. Role display names are source strings, stored untranslated the way core
 * stores its own, and translated only when rendered (RoleNames).
 *
 * @since 0.1.0
 */
final class CapabilityDeclaration {

	/**
	 * The prefix of every capability the plugin names, apart from the product post type's.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PREFIX = 'seocart_';

	/**
	 * Group: the catalog, including the product post type's primitives.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP_CATALOG = 'catalog';

	/**
	 * Group: reading and changing orders.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP_ORDERS = 'orders';

	/**
	 * Group: authority over money.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP_MONEY = 'money';

	/**
	 * Group: seeing and exporting sensitive data.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP_SENSITIVITY = 'sensitivity';

	/**
	 * Group: configuring the store.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP_STORE = 'store';

	/**
	 * Every group, in the order the vocabulary lists them.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const GROUPS = array(
		self::GROUP_CATALOG,
		self::GROUP_ORDERS,
		self::GROUP_MONEY,
		self::GROUP_SENSITIVITY,
		self::GROUP_STORE,
	);

	/**
	 * The plugin's primitives beyond the product post type's, each with its group.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const PRIMITIVES = array(
		'seocart_manage_catalog'       => self::GROUP_CATALOG,
		'seocart_manage_inventory'     => self::GROUP_CATALOG,
		'seocart_view_orders'          => self::GROUP_ORDERS,
		'seocart_edit_orders'          => self::GROUP_ORDERS,
		'seocart_create_orders'        => self::GROUP_ORDERS,
		'seocart_delete_orders'        => self::GROUP_ORDERS,
		'seocart_manage_fulfillment'   => self::GROUP_ORDERS,
		'seocart_capture_payments'     => self::GROUP_MONEY,
		'seocart_void_payments'        => self::GROUP_MONEY,
		'seocart_refund_orders'        => self::GROUP_MONEY,
		'seocart_manage_stored_value'  => self::GROUP_MONEY,
		'seocart_override_money_state' => self::GROUP_MONEY,
		'seocart_view_customer_pii'    => self::GROUP_SENSITIVITY,
		'seocart_view_payment_details' => self::GROUP_SENSITIVITY,
		'seocart_export_customers'     => self::GROUP_SENSITIVITY,
		'seocart_export_orders'        => self::GROUP_SENSITIVITY,
		'seocart_manage_marketing'     => self::GROUP_STORE,
		'seocart_manage_settings'      => self::GROUP_STORE,
		'seocart_manage_secrets'       => self::GROUP_STORE,
		'seocart_manage_appearance'    => self::GROUP_STORE,
		'seocart_view_reports'         => self::GROUP_STORE,
		'seocart_manage_integrations'  => self::GROUP_STORE,
	);

	/**
	 * The meta capabilities the plugin maps itself: each is checked on one resource, through the
	 * resolver its module registers with CapabilityMapper, and is denied until one is registered.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const PLUGIN_META_CAPABILITIES = array(
		'seocart_view_order',
		'seocart_edit_order',
		'seocart_refund_order',
		'seocart_view_customer',
	);

	/**
	 * The core capabilities every role the plugin ships carries.
	 *
	 * Without `read`, WordPress refuses the user the dashboard and their own profile screen,
	 * where password changes, application passwords and two-factor settings live. Every core
	 * role carries it, and a role without it could not be used at all.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const CORE_BASELINE = array( 'read' );

	/**
	 * The roles and their bundles.
	 *
	 * `name` is the display name the plugin creates the role with, or null for a core role,
	 * which is granted to but never created or renamed. A bundle is every primitive in
	 * `groups`, plus `capabilities`, minus `except`.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{name: string|null, groups: list<string>, capabilities: list<string>, except: list<string>}>
	 */
	private const ROLES = array(
		'administrator'          => array(
			'name'         => null,
			'groups'       => self::GROUPS,
			'capabilities' => array(),
			'except'       => array(),
		),
		'seocart_manager'        => array(
			'name'         => 'Store manager',
			'groups'       => self::GROUPS,
			'capabilities' => array(),
			'except'       => array( 'seocart_manage_secrets' ),
		),
		'seocart_order_agent'    => array(
			'name'         => 'Order agent',
			'groups'       => array(),
			'capabilities' => array(
				'seocart_view_orders',
				'seocart_edit_orders',
				'seocart_view_customer_pii',
				'seocart_manage_fulfillment',
				'seocart_capture_payments',
				'seocart_refund_orders',
			),
			'except'       => array(),
		),
		'seocart_catalog_editor' => array(
			'name'         => 'Catalog editor',
			'groups'       => array( self::GROUP_CATALOG ),
			'capabilities' => array(),
			'except'       => array(),
		),
		'seocart_reporter'       => array(
			'name'         => 'Reporter',
			'groups'       => array(),
			'capabilities' => array( 'seocart_view_reports', 'seocart_view_orders' ),
			'except'       => array(),
		),
		'seocart_customer'       => array(
			'name'         => 'Store customer',
			'groups'       => array(),
			'capabilities' => array(),
			'except'       => array(),
		),
	);

	/**
	 * Every primitive with its group, the product post type's first.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $primitives;

	/**
	 * Builds the declaration.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->primitives = array_fill_keys( ProductCapabilities::primitives(), self::GROUP_CATALOG ) + self::PRIMITIVES;
	}

	/**
	 * Returns every primitive capability: the whole vocabulary a role can be granted.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The primitives, grouped, the product post type's first.
	 */
	public function primitives(): array {
		return array_keys( $this->primitives );
	}

	/**
	 * Tells whether a capability is one of the plugin's primitives.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability A capability name.
	 * @return bool True for a declared primitive.
	 */
	public function isPrimitive( string $capability ): bool {
		return isset( $this->primitives[ $capability ] );
	}

	/**
	 * Returns the group a primitive belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability A capability name.
	 * @return string|null One of the GROUP_* constants, or null when the capability is not a declared primitive.
	 */
	public function group( string $capability ): ?string {
		return $this->primitives[ $capability ] ?? null;
	}

	/**
	 * Returns every meta capability: the product post type's, then the plugin's own.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The meta capabilities.
	 */
	public function metaCapabilities(): array {
		return array_merge( ProductCapabilities::metaCapabilities(), self::PLUGIN_META_CAPABILITIES );
	}

	/**
	 * Tells whether a capability is a declared meta capability, the product post type's or the plugin's own.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability A capability name.
	 * @return bool True for a declared meta capability.
	 */
	public function isMetaCapability( string $capability ): bool {
		return in_array( $capability, $this->metaCapabilities(), true );
	}

	/**
	 * Returns the meta capabilities the plugin maps itself, through CapabilityMapper's resolvers.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The meta capabilities, without the product post type's, which core maps.
	 */
	public function pluginMetaCapabilities(): array {
		return self::PLUGIN_META_CAPABILITIES;
	}

	/**
	 * Tells whether a capability is one the plugin maps itself, through a resolver.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability A capability name.
	 * @return bool True for a declared meta capability that is not the product post type's.
	 */
	public function isPluginMetaCapability( string $capability ): bool {
		return in_array( $capability, self::PLUGIN_META_CAPABILITIES, true );
	}

	/**
	 * Tells whether a capability name lies in the plugin's namespace.
	 *
	 * That is every name with the plugin prefix, and every name built on one of the product
	 * post type's capability bases, such as `edit_others_seocart_products`. Inside that
	 * namespace, whatever is not declared is denied.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability A capability name.
	 * @return bool True when the name is the plugin's to declare.
	 */
	public function isPluginCapability( string $capability ): bool {
		if ( str_starts_with( $capability, self::PREFIX ) ) {
			return true;
		}

		foreach ( ProductCapabilities::capabilityType() as $base ) {
			if ( str_ends_with( $capability, '_' . $base ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns every role the declaration grants to: core's administrator and the shipped roles.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Role names.
	 */
	public function roles(): array {
		return array_keys( self::ROLES );
	}

	/**
	 * Tells whether a role is one the plugin creates, as opposed to a core role it grants to.
	 *
	 * @since 0.1.0
	 *
	 * @param string $role A role name.
	 * @return bool True for a role the plugin ships.
	 */
	public function isShippedRole( string $role ): bool {
		return null !== $this->roleName( $role );
	}

	/**
	 * Returns the untranslated display name the plugin creates a shipped role with.
	 *
	 * @since 0.1.0
	 *
	 * @param string $role A role name.
	 * @return string|null The source string, or null for a core role or an unknown one.
	 */
	public function roleName( string $role ): ?string {
		return self::ROLES[ $role ]['name'] ?? null;
	}

	/**
	 * Returns the capabilities a role is granted.
	 *
	 * @since 0.1.0
	 *
	 * @param string $role A role name.
	 * @return list<string> The bundle. Empty for a role the declaration does not know.
	 */
	public function bundle( string $role ): array {
		if ( ! isset( self::ROLES[ $role ] ) ) {
			return array();
		}

		$spec         = self::ROLES[ $role ];
		$capabilities = $this->isShippedRole( $role ) ? self::CORE_BASELINE : array();

		foreach ( $this->primitives as $capability => $group ) {
			if ( in_array( $group, $spec['groups'], true ) ) {
				$capabilities[] = $capability;
			}
		}

		$capabilities = array_diff( array_merge( $capabilities, $spec['capabilities'] ), $spec['except'] );

		return array_values( array_unique( $capabilities ) );
	}
}

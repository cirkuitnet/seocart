<?php
/**
 * DeclarationSnapshot: everything the capability declaration answers, as plain data
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\ProductCapabilities;

/**
 * Builds the capability declaration and asks it every question it answers.
 *
 * The declaration must be pure data: building it and reading it may call no WordPress
 * function. CapabilityDeclarationTest proves that by taking this snapshot in a process
 * without WordPress, tests/Support/declaration-probe.php, and comparing it with the snapshot
 * taken in the test process. Both sides run this same code, so a difference means the
 * declaration depends on its surroundings.
 *
 * @since 0.1.0
 */
final class DeclarationSnapshot {

	/**
	 * Builds the declaration and reads everything it answers.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The answers, in a form that survives a JSON round trip unchanged.
	 */
	public static function take(): array {
		$declaration = new CapabilityDeclaration();
		$primitives  = array();
		$meta        = array();
		$roles       = array();

		foreach ( $declaration->primitives() as $capability ) {
			$primitives[ $capability ] = array(
				'group'     => $declaration->group( $capability ),
				'primitive' => $declaration->isPrimitive( $capability ),
				'meta'      => $declaration->isMetaCapability( $capability ),
				'plugin'    => $declaration->isPluginCapability( $capability ),
			);
		}

		foreach ( $declaration->metaCapabilities() as $capability ) {
			$meta[ $capability ] = array(
				'primitive'   => $declaration->isPrimitive( $capability ),
				'meta'        => $declaration->isMetaCapability( $capability ),
				'plugin_meta' => $declaration->isPluginMetaCapability( $capability ),
				'plugin'      => $declaration->isPluginCapability( $capability ),
			);
		}

		foreach ( $declaration->roles() as $role ) {
			$roles[ $role ] = array(
				'shipped' => $declaration->isShippedRole( $role ),
				'name'    => $declaration->roleName( $role ),
				'bundle'  => $declaration->bundle( $role ),
			);
		}

		return array(
			'primitives'               => $primitives,
			'meta_capabilities'        => $meta,
			'plugin_meta_capabilities' => $declaration->pluginMetaCapabilities(),
			'roles'                    => $roles,
			'product'                  => array(
				'post_type'         => ProductCapabilities::POST_TYPE,
				'registration'      => ProductCapabilities::registrationArguments(),
				'primitives'        => ProductCapabilities::primitives(),
				'meta_capabilities' => ProductCapabilities::metaCapabilities(),
			),
		);
	}
}

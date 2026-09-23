<?php
/**
 * Operations: the list of every operation the plugin exposes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the registry of the plugin's operations.
 *
 * This class owns one fact: which operations the plugin exposes. The kernel registers the REST
 * routes, abilities and commands from this registry, the documentation generators document it,
 * and the contract tests walk it, so all three see the same list. A module adds one line per
 * operation, naming the id and the static method that returns the definition:
 *
 *     $registry->add( 'inventory.adjust_stock', array( AdjustStockOperation::class, 'definition' ) );
 *
 * The list is empty until the first module declares an operation.
 *
 * @since 0.1.0
 */
final class Operations {

	/**
	 * Returns a new registry holding the factory of every operation. Builds no definition.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationRegistry The registry.
	 */
	public static function registry(): OperationRegistry {
		return new OperationRegistry();
	}
}

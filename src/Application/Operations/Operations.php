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

use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\StoreOperations;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Order\Application\OrderOperations;
use SEOCart\Order\Interfaces\StoreApi\OrderStoreOperations;
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Platform\Settings\SettingsOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the registry of the plugin's operations.
 *
 * This class owns one fact: which operations the plugin exposes. The kernel registers the REST
 * routes, abilities and commands from this registry, the documentation generators document it,
 * and the contract tests walk it, so all three see the same list. A module adds one line per
 * operation, naming the id (a constant on the module's operations class) and the static method
 * that returns the definition:
 *
 *     $registry->add( InventoryOperations::ADJUST_STOCK, array( InventoryOperations::class, 'adjustStock' ) );
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
		$registry = new OperationRegistry();

		$registry->add( SettingsOperations::GET, array( SettingsOperations::class, 'get' ) );
		$registry->add( SettingsOperations::UPDATE, array( SettingsOperations::class, 'update' ) );
		$registry->add( InventoryOperations::ADJUST_STOCK, array( InventoryOperations::class, 'adjustStock' ) );
		$registry->add( StoreOperations::GET_SESSION, array( StoreOperations::class, 'getSession' ) );
		$registry->add( CartOperations::GET_CART, array( CartOperations::class, 'getCart' ) );
		$registry->add( CartOperations::ADD_LINES, array( CartOperations::class, 'addLines' ) );
		$registry->add( CartOperations::UPDATE_LINE, array( CartOperations::class, 'updateLine' ) );
		$registry->add( OrderStoreOperations::GET_STATUS, array( OrderStoreOperations::class, 'getStatus' ) );
		$registry->add( CheckoutOperations::UPDATE_SESSION, array( CheckoutOperations::class, 'updateSession' ) );
		$registry->add( CheckoutOperations::PLACE_ORDER, array( CheckoutOperations::class, 'placeOrder' ) );
		$registry->add( CheckoutOperations::RESUME_PAYMENT, array( CheckoutOperations::class, 'resumePayment' ) );
		$registry->add( CartOperations::APPLY_CODE, array( CartOperations::class, 'applyCode' ) );
		$registry->add( CartOperations::REMOVE_CODE, array( CartOperations::class, 'removeCode' ) );
		$registry->add( CheckoutOperations::CHANGE_CURRENCY, array( CheckoutOperations::class, 'changeCurrency' ) );
		$registry->add( PaymentOperations::REFUND_ORDER, array( PaymentOperations::class, 'refundOrder' ) );
		$registry->add( PaymentOperations::SETTLE_REFUND_CLAIM, array( PaymentOperations::class, 'settleRefundClaim' ) );
		$registry->add( OrderOperations::CLEAR_UNRECONCILED_MONEY, array( OrderOperations::class, 'clearUnreconciledMoney' ) );
		$registry->add( PaymentOperations::CAPTURE_PAYMENT, array( PaymentOperations::class, 'capturePayment' ) );
		$registry->add( PaymentOperations::VOID_PAYMENT, array( PaymentOperations::class, 'voidPayment' ) );

		return $registry;
	}
}

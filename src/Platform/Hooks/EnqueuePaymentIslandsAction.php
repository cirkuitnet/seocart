<?php
/**
 * EnqueuePaymentIslandsAction: the action a gateway plugin adds the script of its payment island on
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Hooks;

use SEOCart\Checkout\Interfaces\Storefront\PaymentIslands;

defined( 'ABSPATH' ) || exit;

/**
 * Fires when a page shows the checkout's payment methods, so a gateway plugin can add the script of its payment island.
 *
 * The listener receives the page's PaymentIslands and calls its add() with the gateway's id and
 * its script; the script is enqueued only when the page offers that gateway's payment method, so a
 * page that offers none of a plugin's methods loads none of its scripts. The action never fires on
 * a page that shows no payment methods, so a gateway plugin costs every other request only its
 * own add_action() call.
 *
 * @since 0.2.0
 */
final class EnqueuePaymentIslandsAction implements ActionDeclaration {

	/**
	 * The action's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = 'seocart_enqueue_payment_islands';

	/**
	 * Returns the action's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string NAME.
	 */
	public static function name(): string {
		return self::NAME;
	}

	/**
	 * Says when the action fires.
	 *
	 * @since 0.2.0
	 *
	 * @return string One sentence.
	 */
	public static function firesWhen(): string {
		return 'Once on a page that shows the checkout\'s payment methods, before its scripts are printed; never on a page that shows none.';
	}

	/**
	 * Returns the argument a listener receives: the page's payment islands.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The class, keyed `islands`.
	 */
	public static function arguments(): array {
		return array( 'islands' => PaymentIslands::class );
	}
}

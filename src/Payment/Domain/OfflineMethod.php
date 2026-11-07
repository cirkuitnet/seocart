<?php
/**
 * OfflineMethod: the payment methods SEOCart offers itself, paid outside any provider
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The offline payment methods: bank transfer, cheque and cash on delivery.
 *
 * Owns one fact: which payment methods are SEOCart's own, paid outside any provider. Each is a
 * gateway of its own, registered on every site under its value as the gateway id. The shopper
 * promises to pay when the order is placed, so the order is accepted and waits for the money; a
 * person records the payment received when it arrives, with the day it arrived, and a refund of
 * it is money returned outside a provider. Only cash on delivery is paid when the order is
 * delivered, so only its orders may be dispatched before they are paid.
 *
 * @since 0.2.0
 */
enum OfflineMethod: string {

	/**
	 * The shopper pays by a transfer to the merchant's bank account.
	 *
	 * @since 0.2.0
	 */
	case BankTransfer = 'bank_transfer';

	/**
	 * The shopper pays by sending a cheque.
	 *
	 * @since 0.2.0
	 */
	case Cheque = 'cheque';

	/**
	 * The shopper pays the courier when the order is delivered.
	 *
	 * @since 0.2.0
	 */
	case CashOnDelivery = 'cash_on_delivery';

	/**
	 * Returns the method's name for people, as a closure around a literal translation call, run only when a screen shows it.
	 *
	 * @since 0.2.0
	 *
	 * @return \Closure(): string The label.
	 */
	public function label(): \Closure {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::BankTransfer   => static fn(): string => __( 'Bank transfer', 'seocart' ),
			self::Cheque         => static fn(): string => __( 'Cheque', 'seocart' ),
			self::CashOnDelivery => static fn(): string => __( 'Cash on delivery', 'seocart' ),
		};
	}

	/**
	 * Tells whether the shopper pays when the order is delivered, so the order may be dispatched before it is paid.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True for cash on delivery.
	 */
	public function paysOnDelivery(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return self::CashOnDelivery === $this;
	}

	/**
	 * Returns the gateway ids of the offline payment methods, in the order they register.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The ids.
	 */
	public static function ids(): array {
		return array_map( static fn( self $method ): string => $method->value, self::cases() );
	}

	/**
	 * Tells whether a gateway id is one of the offline payment methods.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id, as an intent records it.
	 * @return bool True for bank_transfer, cheque and cash_on_delivery.
	 */
	public static function isOffline( string $gatewayId ): bool {
		return null !== self::tryFrom( $gatewayId );
	}
}

<?php
/**
 * Settlement: how a provider says it settles an amount with the merchant
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Gateway;

use SEOCart\Support\CurrencyMismatchException;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The provider's own account of a payment's settlement: the amount in the merchant's settlement currency, its rate and its fee.
 *
 * Owns one fact: a settlement as the provider reported it. It is stored beside the ledger row
 * as the provider's fact and never used for the plugin's own amounts: every base amount comes
 * from the rate the order was placed at.
 *
 * @since 0.1.0
 */
final readonly class Settlement {

	/**
	 * Records the settlement.
	 *
	 * @since 0.1.0
	 *
	 * @throws CurrencyMismatchException When the fee is in another currency than the amount.
	 *
	 * @param Money   $amount The amount settled, in the settlement currency.
	 * @param Decimal $rate   The provider's rate from the payment's currency to the settlement currency.
	 * @param Money   $fee    The provider's fee, in the settlement currency.
	 * @param string  $source Who reported it: the gateway's id.
	 */
	public function __construct(
		public Money $amount,
		public Decimal $rate,
		public Money $fee,
		public string $source
	) {
		CurrencyMismatchException::assertSameCurrency( $amount->currency(), $fee->currency() );
	}
}

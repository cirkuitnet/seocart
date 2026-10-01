<?php
/**
 * PaymentGateway: what the plugin asks of a payment provider
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * A payment provider, as the payment service calls it.
 *
 * Owns one fact: the calls a gateway answers. Each call may go over the network, so the payment
 * service makes it only outside any transaction, and applies the result in a transaction of its
 * own afterwards; a gateway never touches the plugin's tables. A gateway receives tokens and
 * references, never card data: no request carries a card number, a security code or an expiry.
 *
 * The provider object a result names is the object of that attempt's outcome, a charge, a
 * capture or a refund, never the long-lived provider intent: the plugin applies each result once
 * by the provider, that object and the operation.
 *
 * @since 0.1.0
 */
interface PaymentGateway {

	/**
	 * The capability of asking the customer to confirm a payment, for example with their bank.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SCA = 'sca';

	/**
	 * The capability of capturing less than was authorized.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PARTIAL_CAPTURE = 'partial_capture';

	/**
	 * Returns the gateway's id, which `payment_intents.gateway_id` and the ledger's provider record.
	 *
	 * @since 0.1.0
	 *
	 * @return string A lowercase word, for example `stub`.
	 */
	public function id(): string;

	/**
	 * Tells whether the gateway has a capability.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability One of this interface's capability constants.
	 * @return bool True when it has it.
	 */
	public function supports( string $capability ): bool;

	/**
	 * Asks the provider to authorize an intent's amount.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the provider could not be asked or did not answer.
	 *
	 * @param PaymentRequest $request The intent and the customer's payment token.
	 * @return GatewayResult The provider's answer, not yet applied.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult;

	/**
	 * Asks the provider to capture an authorized intent.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the provider could not be asked or did not answer.
	 *
	 * @param CaptureRequest $request The intent and the amount.
	 * @return GatewayResult The provider's answer, not yet applied.
	 */
	public function capture( CaptureRequest $request ): GatewayResult;

	/**
	 * Asks the provider where an intent stands now, for an intent whose result never arrived.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the provider could not be asked or did not answer.
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The provider's latest answer, not yet applied; null while the
	 *                            provider is still deciding, or has no record of the intent.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult;
}

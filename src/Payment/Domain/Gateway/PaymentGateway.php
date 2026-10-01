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
	 * Asks the provider to give back part or all of what an intent captured.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the provider could not be asked or did not answer.
	 *
	 * @param GatewayRefund $request The intent, the amount and the refund's idempotency key.
	 * @return GatewayResult The provider's answer, not yet applied: an approval names the provider's refund.
	 */
	public function refund( GatewayRefund $request ): GatewayResult;

	/**
	 * Asks the provider where an intent stands now, for an intent whose result never arrived.
	 *
	 * "The provider has no record of this intent" is an answer, and a final one: the authorization
	 * never reached it, so nothing can be charged, and it is answered as a declined authorization
	 * whose error code is `not_found`, which ends the placement. It is not "still deciding", which
	 * is null and keeps the placement waiting; nothing ends a placement on time alone. To say it
	 * safely, an adapter sends the intent's uuid as the provider's idempotency key with every
	 * authorization, so a call that reached the provider is always found by it, and answers
	 * not-found only for an intent older than the reconciliation's stale threshold. An adapter
	 * whose provider cannot be asked by that key answers null, still deciding, never not-found.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the provider could not be asked or did not answer.
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The provider's latest answer, not yet applied, a declined
	 *                            authorization `not_found` when it has no record of the intent;
	 *                            null while the provider is still deciding.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult;
}

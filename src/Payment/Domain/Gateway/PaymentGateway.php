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
	 * The error code of a decline that says the provider has no record of what it was asked about: an intent it never authorized, or a refund it has not made.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'not_found';

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
	 * Asks the provider what became of a refund it was asked for before, whose answer was never recorded.
	 *
	 * The refund service asks this, never refund() again, when it finds a refund already claimed:
	 * the earlier request may still be waiting for the provider, or its answer may have been lost,
	 * or its process may have died after the provider gave the money back. Asked twice, a provider
	 * that does not honour the idempotency key would give the money back twice.
	 *
	 * An adapter must be able to find a refund by the refund's uuid alone. It sends the uuid as the
	 * provider's idempotency key with every refund, and also in a field of the refund the provider
	 * can be searched by, such as its metadata or its reference, because an idempotency key is not
	 * a lookup: some providers forget it within a day, and some have none. It answers:
	 *
	 * - the refund's result, approved or declined, naming the same provider refund object refund()
	 *   named, so the ledger records it once whichever answer arrives;
	 * - a declined refund whose error code is `not_found` only when the provider says, for certain,
	 *   that it has made no refund under that uuid so far;
	 * - null when it cannot say: the provider cannot be searched by the uuid, or it has not decided.
	 *
	 * In this version a not-found is information for a person, never acted on: the refund service
	 * treats it as it treats null, and leaves the refund's claim open for a person to settle. The
	 * first request may still be on its way to the provider, however long ago it was claimed, and
	 * taking its not-found for a decline would let a second refund of the same units be made.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the provider could not be asked or did not answer.
	 *
	 * @param GatewayRefund $request The refund, as it was asked: the intent, the amount and the refund's uuid.
	 * @return GatewayResult|null The provider's answer about the refund, not yet applied; a declined
	 *                            refund `not_found` when the provider made none under the uuid; null
	 *                            when it cannot say.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult;

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
	 * "The provider has expired or cancelled this intent" is a final answer too: nothing can be
	 * charged any more, and it is answered as a declined authorization whose error code is
	 * `expired`, which ends the placement as a decline does. A real adapter answers it from its
	 * provider's own state. The query carries the intent's expiry, the window the plugin gives a
	 * wait for the customer or the gateway, which stands for a provider that reports no window of
	 * its own, and whether it had passed by the database's clock; an intent with no expiry, or one
	 * not yet past it, is never answered expired on that account.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the provider could not be asked or did not answer.
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The provider's latest answer, not yet applied, a declined
	 *                            authorization `not_found` when it has no record of the intent, or
	 *                            `expired` when it expired or cancelled it; null while the provider
	 *                            is still deciding.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult;
}

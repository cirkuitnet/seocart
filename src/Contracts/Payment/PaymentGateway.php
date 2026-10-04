<?php
/**
 * PaymentGateway: what the plugin asks of a payment provider
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * A payment provider, as the payment service calls it: the interface a gateway plugin implements.
 *
 * Owns one fact: the calls a gateway answers. Each call that may go over the network is made only
 * outside any transaction, and its answer is applied by the plugin in a transaction of its own
 * afterwards; a gateway never touches the plugin's tables. A gateway receives tokens and
 * references, never card data: no request carries a card number, a security code or an expiry.
 *
 * describe() is data: it reads nothing, sends nothing, calls no WordPress function and translates
 * nothing (the label is a closure). The plugin calls it once, when the gateway is registered, and
 * refuses before any call an operation the descriptor's capability matrix does not declare.
 *
 * The provider object a result names is the object of that attempt's outcome, a charge, a
 * capture or a refund, never the long-lived provider intent: the plugin applies each result once
 * by the provider, that object and the operation.
 *
 * Every request carries the mode of the intent it is about, and the gateway reads the credentials
 * of that mode (ExtensionContext::settings()), never of the mode the store is set to now: a live
 * intent stays live after the merchant switches to test.
 *
 * @since 0.1.0
 * @since 0.2.0 Moved to the public contract. describe() replaces id() and supports(); isAvailable(),
 *              void() and readWebhook() were added, and every request carries its mode.
 *
 * @api
 */
interface PaymentGateway {

	/**
	 * The version of this contract, which a gateway states in its descriptor as the version it was written against.
	 *
	 * Semantic versioning, independent of the plugin's version. Before 1.0 a minor version is a
	 * major one: the registry refuses a gateway written against another major.minor
	 * (GatewayRegistry::supportsContract()).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONTRACT_VERSION = '0.2.0';

	/**
	 * The error code of a decline that says the provider has no record of what it was asked about: an intent it never authorized, or a refund it has not made.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * Returns the gateway's one declaration: its id, label, modes, settings, capability matrix and idempotency profile.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor, built without I/O.
	 */
	public function describe(): GatewayDescriptor;

	/**
	 * Tells whether the gateway can take a new payment in the context given, by its own rules.
	 *
	 * Called only once the plugin found the gateway registered, configured for the mode, and its
	 * capability matrix allowing the currency for the account's country; so it may only narrow
	 * what the matrix allows, for example by a provider's smallest amount. Pure: it reads nothing
	 * and sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param AvailabilityContext $context The payment: currency, amount, billing country, channel, mode and the account's settings.
	 * @return bool True when the gateway can take it.
	 */
	public function isAvailable( AvailabilityContext $context ): bool;

	/**
	 * Asks the provider to authorize an intent's amount.
	 *
	 * The request's idempotency key is the intent's uuid (PaymentRequest::idempotencyKey()).
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
	 * A provider that captured already answers that capture, the same object, never an error.
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
	 * Asks the provider to cancel an authorization without taking its amount.
	 *
	 * A provider that says the authorization had already succeeded answers the authorization's
	 * approval instead, which the plugin applies as an ordinary authorization.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the provider could not be asked or did not answer.
	 *
	 * @param VoidRequest $request The intent and why it is voided.
	 * @return GatewayResult The provider's answer, not yet applied.
	 */
	public function void( VoidRequest $request ): GatewayResult;

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
	 * A not-found is information for a person, never acted on: the refund service treats it as it
	 * treats null, and leaves the refund's claim open for a person to settle. The first request may
	 * still be on its way to the provider, however long ago it was claimed, and taking its
	 * not-found for a decline would let a second refund of the same units be made.
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
	 * is null and keeps the placement waiting; nothing ends a placement on time alone. An adapter
	 * answers not-found only when its provider can be searched by the intent's uuid, sent as the
	 * key or in a searchable field with every authorization. The plugin itself accepts a not-found
	 * only from a gateway whose idempotency profile says it is searchable, and only for an intent
	 * older than the profile's search delay and the reconciliation's stale threshold, by the
	 * database's clock; any other not-found is taken as null.
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

	/**
	 * Reads a delivery the provider sent to the plugin's webhook: verified first, then parsed.
	 *
	 * The gateway checks the delivery's signature over the raw body with a constant-time comparison,
	 * and its signed timestamp against the context's clock, before it decodes anything; a delivery
	 * that fails either is answered rejected, never thrown. A verified delivery about a payment is
	 * answered with the result it reports; anything else the plugin does not act on is ignored.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery: the gateway and mode it was sent to, its headers and its raw body.
	 * @return WebhookReading What the delivery says, or why it was rejected or ignored.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading;
}

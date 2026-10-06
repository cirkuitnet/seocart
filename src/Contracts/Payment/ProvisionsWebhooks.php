<?php
/**
 * ProvisionsWebhooks: a gateway that can set up its provider's webhook endpoint for this site
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented, besides PaymentGateway, by a gateway whose provider's webhook endpoints can be set up through the provider's API.
 *
 * Owns one fact: how a gateway sets up the endpoint its provider delivers events to, so a
 * merchant who saves credentials need not copy a URL and a signing secret by hand. Optional: a
 * gateway without it has its endpoint set up by the merchant, who saves the signing secret with
 * the credentials.
 *
 * The plugin calls provisionWebhooks() when credentials are saved, outside any transaction, and
 * the gateway calls its provider through its own HTTP client (ExtensionContext::http()) only. The
 * plugin stores the signing secret the answer carries, sealed, as the mode's setting
 * GatewayDescriptor::WEBHOOK_SECRET, which the gateway must therefore declare as a credential,
 * and which its readWebhook() reads. It keeps the endpoint's id beside the secret, under
 * GatewayDescriptor::WEBHOOK_ENDPOINT, a name no gateway may declare, and forgets it when a
 * merchant enters a signing secret by hand, which is no endpoint's it set up. A gateway that sets
 * up its own endpoints takes no payment in a mode until that mode's webhook_secret is saved.
 *
 * An endpoint is this site's when the provider's record of it carries, as metadata, the target's
 * install uuid and mode, and its URL is the target's URL. A copied site keeps the original's
 * install uuid but serves another URL, so the URL is part of the test: the endpoints of the site
 * it was copied from are never touched. The gateway:
 *
 * - lists only this site's endpoints for the mode;
 * - reuses the endpoint whose signing secret the plugin holds: the one WebhookTarget::$endpointId
 *   names, while WebhookTarget::$secretHeld;
 * - replaces any other endpoint of this site for the mode, whose secret the plugin does not hold
 *   (a provider shows a secret only when the endpoint is created): deletes it, then creates one;
 * - creates one when there is none, with the target's URL, events and metadata;
 * - never lists, changes or deletes an endpoint tagged with another install uuid or mode, or
 *   one of this install and mode at another URL, which it counts instead
 *   (WebhookProvisioning::$elsewhere).
 *
 * Throws GatewayUnavailable when the provider cannot be asked or refuses: the plugin keeps the
 * credentials it saved and reports the webhook step failed, naming the exception by its class,
 * since its message may quote a credential.
 *
 * @since 0.2.0
 *
 * @api
 */
interface ProvisionsWebhooks {

	/**
	 * Returns the provider's event types an endpoint this gateway sets up subscribes to: those its readWebhook() reads.
	 *
	 * Data: reads nothing and sends nothing. Called once, when the gateway is registered.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The event types, at least one, such as `payment_intent.succeeded`.
	 */
	public function webhookEvents(): array;

	/**
	 * Sets up the provider's webhook endpoint for this site and a mode: reuses, replaces or creates it.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the provider could not be asked, or refused.
	 *
	 * @param WebhookTarget $target The endpoint this site needs: its URL, the mode, the owner tag and the events.
	 * @return WebhookProvisioning What was done, with the signing secret of an endpoint it created.
	 */
	public function provisionWebhooks( WebhookTarget $target ): WebhookProvisioning;
}

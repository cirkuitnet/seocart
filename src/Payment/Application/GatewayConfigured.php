<?php
/**
 * GatewayConfigured: what saving a gateway's credentials came to, and its webhook endpoint
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

/**
 * The outcome of GatewayConfiguration::configure(): the credentials saved, and what became of the webhook endpoint.
 *
 * Owns one fact: what a configure reports. The credentials are saved whenever this exists; the
 * webhook step is skipped, reused, replaced, created or failed, with the provider's endpoint id
 * when one was set up, the count of this site's endpoints at another URL that were left alone, and
 * why it failed, in a sentence that carries no secret.
 *
 * @since 0.2.0
 */
final readonly class GatewayConfigured {

	/**
	 * The webhook step was not run: it was not asked for, or the gateway sets up no endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * The webhook step failed; the credentials are saved all the same.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * Records the outcome.
	 *
	 * @since 0.2.0
	 *
	 * @param int         $version    The settings document's version after the last write.
	 * @param string      $webhooks   SKIPPED, FAILED, or WebhookProvisioning's REUSED, REPLACED or CREATED.
	 * @param string|null $endpointId The provider's id of the endpoint set up; null when none was.
	 * @param int         $elsewhere  How many of this site's endpoints for the mode are at another URL, left alone.
	 * @param string|null $failure    Why the webhook step failed; null when it did not.
	 */
	public function __construct(
		public int $version,
		public string $webhooks,
		public ?string $endpointId = null,
		public int $elsewhere = 0,
		public ?string $failure = null
	) {
	}
}

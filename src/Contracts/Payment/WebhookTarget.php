<?php
/**
 * WebhookTarget: the webhook endpoint a site needs its provider to deliver to, for one gateway and mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a programming error to the developer; they are never HTML.

/**
 * What the plugin asks a gateway to set up (ProvisionsWebhooks::provisionWebhooks()): where its provider delivers, which events, and how the endpoint is known as this site's.
 *
 * Owns one fact: the endpoint a site needs. The URL is the plugin's webhook route for the
 * gateway and the mode; the install uuid and the mode are the owner tag the gateway records on
 * the endpoint as metadata, and lists by; an endpoint is this site's only when that tag and the
 * URL both match. When the plugin holds the signing secret of an endpoint it set up for the mode,
 * the target names that endpoint: the one endpoint the gateway may reuse.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class WebhookTarget {

	/**
	 * Records the target.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the URL is not an http(s) URL, the install uuid is empty,
	 *                                   the events are none or not all non-empty text, or the endpoint
	 *                                   id is empty, longer than WebhookProvisioning::ENDPOINT_ID_MAX_LENGTH,
	 *                                   more than one line, or given while no secret is held.
	 *
	 * @param string      $url         The URL the provider delivers to: the plugin's route for the gateway and mode.
	 * @param Mode        $mode        The mode the endpoint is for.
	 * @param string      $installUuid The site's installation uuid: with the mode, the tag the endpoint carries.
	 * @param array       $events      The event types the endpoint subscribes to: ProvisionsWebhooks::webhookEvents().
	 * @param bool        $secretHeld  Whether the plugin holds, and can open, the signing secret of an endpoint it set up for this mode: the one $endpointId names.
	 * @param string|null $endpointId  Optional. The provider's id of that endpoint; null when the plugin holds no endpoint's secret, as after a secret entered by hand. Default null.
	 *
	 * @phpstan-param list<string> $events
	 */
	public function __construct(
		public string $url,
		public Mode $mode,
		public string $installUuid,
		public array $events,
		public bool $secretHeld,
		public ?string $endpointId = null
	) {
		if ( 1 !== preg_match( '#^https?://[^\s/]+/\S*\z#', $url ) ) {
			throw new \InvalidArgumentException( 'A webhook target is an http or https URL with a host and a path.' );
		}

		if ( '' === trim( $installUuid ) ) {
			throw new \InvalidArgumentException( 'A webhook target carries the site\'s installation uuid.' );
		}

		if ( array() === $events || ! self::isListOfText( $events ) ) {
			throw new \InvalidArgumentException( 'A webhook target subscribes to at least one event type, each non-empty text.' );
		}

		if ( null !== $endpointId && ( ! $secretHeld || '' === $endpointId || strlen( $endpointId ) > WebhookProvisioning::ENDPOINT_ID_MAX_LENGTH || 1 === preg_match( '/[\r\n]/', $endpointId ) ) ) {
			throw new \InvalidArgumentException( sprintf( 'A webhook target names an endpoint only while its signing secret is held, by an id of one line of 1 to %d bytes.', WebhookProvisioning::ENDPOINT_ID_MAX_LENGTH ) );
		}
	}

	/**
	 * Tells whether values are a list of non-empty text.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $values The values, as a gateway gave them.
	 * @return bool True when they are.
	 */
	private static function isListOfText( array $values ): bool {
		if ( ! array_is_list( $values ) ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return false;
			}
		}

		return true;
	}
}

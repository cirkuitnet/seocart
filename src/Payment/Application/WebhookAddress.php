<?php
/**
 * WebhookAddress: the one declaration of the route a payment provider delivers its events to
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;

/**
 * The address of the webhook route: `POST /seocart/v1/webhooks/{gateway_id}/{mode}`, one URL per gateway and mode.
 *
 * Owns one fact: where a provider delivers. The route that receives the deliveries registers its
 * pattern from here, and setting up a provider's endpoint builds its URL from here, so the two
 * cannot drift apart. The gateway segment is a gateway id as the descriptor defines one, and the
 * mode segment one of Mode's values: nothing is restated.
 *
 * @since 0.2.0
 */
final class WebhookAddress {

	/**
	 * The REST namespace of the route: the plugin's own.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAMESPACE = RestBinding::NAMESPACE;

	/**
	 * Cannot be called: the class is used through its static functions only.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Returns the route's pattern within the namespace, as register_rest_route() takes it.
	 *
	 * @since 0.2.0
	 *
	 * @return string `/webhooks/(?P<gateway_id>…)/(?P<mode>test|live)`.
	 */
	public static function pattern(): string {
		$modes = implode( '|', array_map( static fn( Mode $mode ): string => $mode->value, Mode::cases() ) );

		return '/webhooks/(?P<gateway_id>' . GatewayDescriptor::ID_SEGMENT . ')/(?P<mode>' . $modes . ')';
	}

	/**
	 * Returns the route's path within the namespace for a gateway and a mode.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @return string For example `/webhooks/stripe/test`.
	 */
	public static function path( string $gatewayId, Mode $mode ): string {
		return '/webhooks/' . $gatewayId . '/' . $mode->value;
	}

	/**
	 * Returns the URL a provider delivers a gateway's events of a mode to, on this site.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @return string The URL, as rest_url() builds it.
	 */
	public static function url( string $gatewayId, Mode $mode ): string {
		return rest_url( self::NAMESPACE . self::path( $gatewayId, $mode ) );
	}
}

<?php
/**
 * WebhookRequestPolicy: what a webhook delivery must look like before its handler reads it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Interfaces\Rest;

use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Platform\Authorization\RequestPolicy;
use SEOCart\Platform\Http\OutboundClient;
use SEOCart\Platform\Rest\HttpMethod;
use SEOCart\Support\Error\CodedException;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The webhook route's request policy: the shape a provider's delivery must have, checked before the handler verifies it.
 *
 * Owns one fact: what a delivery must look like to be read at all. The route is guarded by
 * PermissionCallback::signed() with this policy. It checks no signature: a permission callback may
 * be asked several times for one request, and verifying an HMAC over a megabyte, opening a secret
 * and resolving the gateway belong to the handler, which runs once. The checks run cheapest first,
 * each refusal with its own code:
 *
 * 1. The request arrived as a write (HttpMethod): a GET that names POST with `?_method=` is still
 *    a GET, and is refused `payment.webhook_read_method`, never routed as a write.
 * 2. The body is not empty (`payment.webhook_body_empty`) and at most MAX_BODY_BYTES
 *    (`payment.webhook_body_too_large`). WordPress has read the body already, so measuring it
 *    costs nothing more.
 *
 * No header, no nonce and no cart token: a provider sends none. The policy changes nothing and
 * counts nothing, so it may be asked any number of times.
 *
 * @since 0.2.0
 */
final class WebhookRequestPolicy implements RequestPolicy {

	/**
	 * The largest body a delivery may have, in bytes: the same figure the outbound client allows a provider's answer, since a provider's object is a few kilobytes either way.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_BODY_BYTES = OutboundClient::MAX_RESPONSE_BYTES;

	/**
	 * Builds the errors a request is refused with.
	 *
	 * @since 0.2.0
	 *
	 * @var ErrorTranslator
	 */
	private ErrorTranslator $translator;

	/**
	 * Creates the policy.
	 *
	 * @since 0.2.0
	 *
	 * @param ErrorTranslator $translator Builds the errors a request is refused with.
	 */
	public function __construct( ErrorTranslator $translator ) {
		$this->translator = $translator;
	}

	/**
	 * Tells whether a delivery has the shape the handler reads. Changes nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Request $request    The request being answered.
	 * @param string|null     $capability Null: a signed request checks no capability.
	 * @return true|WP_Error True, or the refusal.
	 */
	public function allows( WP_REST_Request $request, ?string $capability ): bool|WP_Error {
		unset( $capability );

		if ( ! HttpMethod::isWrite( $request ) ) {
			return $this->refuse( PaymentError::WebhookReadMethod );
		}

		$length = strlen( $request->get_body() );

		if ( 0 === $length ) {
			return $this->refuse( PaymentError::WebhookBodyEmpty );
		}

		if ( $length > self::MAX_BODY_BYTES ) {
			return $this->refuse( PaymentError::WebhookBodyTooLarge, array( 'max_bytes' => self::MAX_BODY_BYTES ) );
		}

		return true;
	}

	/**
	 * Builds a refusal.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentError         $code    The refusal's code.
	 * @param array<string, mixed> $context Optional. The values its message names. Default none.
	 * @return WP_Error The error, with the documented data members.
	 */
	private function refuse( PaymentError $code, array $context = array() ): WP_Error {
		return $this->translator->translate( CodedException::because( $code, $context ) );
	}
}

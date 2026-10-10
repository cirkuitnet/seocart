<?php
/**
 * CaptureRequest: what a gateway is given to capture an authorized intent
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A capture request: the intent, the provider's reference to it, the amount to take and the intent's mode.
 *
 * Owns one fact: what a capture sends a provider. The amount is what is captured: everything
 * authorized, or less only for a gateway whose matrix declares `partial_capture`, the provider
 * releasing the rest. Its idempotency key is derived from the intent, whatever the amount, so a
 * capture asked again after its answer was lost is the same capture at the provider.
 *
 * @since 0.1.0
 * @since 0.2.0 Moved to the public contract, with the mode and the idempotency key.
 *
 * @api
 */
final readonly class CaptureRequest {

	/**
	 * Records the request.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The mode was added.
	 *
	 * @param string      $intentUuid       The intent's uuid.
	 * @param string|null $providerIntentId The provider's reference to the intent, when it gave one.
	 * @param Money       $amount           The amount to capture.
	 * @param Mode        $mode             The intent's mode, whose credentials the call uses.
	 */
	public function __construct(
		public string $intentUuid,
		public ?string $providerIntentId,
		public Money $amount,
		public Mode $mode
	) {
	}

	/**
	 * Returns the key the provider is sent with the capture.
	 *
	 * @since 0.2.0
	 *
	 * @return string The intent's uuid followed by `:capture`.
	 */
	public function idempotencyKey(): string {
		return $this->intentUuid . ':capture';
	}
}

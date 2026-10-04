<?php
/**
 * VoidRequest: what a gateway is given to cancel an authorization
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A void request: the intent, the provider's reference to it, the amount authorized, the intent's mode and why it is voided.
 *
 * Owns one fact: what a void sends a provider. Its idempotency key is derived from the intent,
 * so a void asked again after its answer was lost is the same void at the provider.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class VoidRequest {

	/**
	 * Records the request.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $intentUuid       The intent's uuid.
	 * @param string|null $providerIntentId The provider's reference to the intent, when it gave one.
	 * @param Money       $amount           The amount the intent authorized.
	 * @param Mode        $mode             The intent's mode, whose credentials the call uses.
	 * @param string      $reason           Why it is voided, a code such as `action_window_ended`.
	 */
	public function __construct(
		public string $intentUuid,
		public ?string $providerIntentId,
		public Money $amount,
		public Mode $mode,
		public string $reason
	) {
	}

	/**
	 * Returns the key the provider is sent with the void.
	 *
	 * @since 0.2.0
	 *
	 * @return string The intent's uuid followed by `:void`.
	 */
	public function idempotencyKey(): string {
		return $this->intentUuid . ':void';
	}
}

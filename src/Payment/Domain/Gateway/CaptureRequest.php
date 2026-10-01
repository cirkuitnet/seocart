<?php
/**
 * CaptureRequest: what a gateway is given to capture an authorized intent
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Gateway;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A capture request: the intent, the provider's reference to it, and the amount to take.
 *
 * Owns one fact: what a capture sends a provider. The amount is the intent's frozen amount:
 * capturing less than was authorized waits for a gateway that supports it.
 *
 * @since 0.1.0
 */
final readonly class CaptureRequest {

	/**
	 * Records the request.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $intentUuid       The intent's uuid.
	 * @param string|null $providerIntentId The provider's reference to the intent, when it gave one.
	 * @param Money       $amount           The amount to capture.
	 */
	public function __construct(
		public string $intentUuid,
		public ?string $providerIntentId,
		public Money $amount
	) {
	}
}

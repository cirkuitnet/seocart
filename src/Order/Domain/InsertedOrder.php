<?php
/**
 * InsertedOrder: what placing an order returns to its caller
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The identity of an order just written, the rate it was placed at, and its access key.
 *
 * Owns one fact: what the caller that placed an order learns about it. The frozen conversion
 * context is the one the order's payment intents are created at, in the same transaction. The
 * access key is here and nowhere else: the order stores only its hash, so this is the one moment
 * the key exists, and the caller hands it to the shopper and never logs it.
 *
 * @since 0.1.0
 */
final readonly class InsertedOrder {

	/**
	 * Records the order's identity.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id                  The internal id, for the rest of the caller's transaction.
	 * @param string $uuid                The public identifier.
	 * @param string $orderNumber         The number shown to people.
	 * @param int    $conversionContextId The frozen rate the order was placed at, which its intents are created at.
	 * @param string $accessKey           The raw access key, which a guest reads the order with.
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public string $orderNumber,
		public int $conversionContextId,
		public string $accessKey
	) {
	}
}

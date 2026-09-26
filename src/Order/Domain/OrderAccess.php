<?php
/**
 * OrderAccess: what deciding who may see an order needs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The owner and the access key of an order, read by its public identifier.
 *
 * Owns one fact: the facts an order's access check compares a request with. Whether the key has
 * expired is decided by the database clock in the read itself, so no PHP clock decides it.
 *
 * @since 0.1.0
 */
final readonly class OrderAccess {

	/**
	 * Records the facts.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $uuid          The order's public identifier.
	 * @param int|null    $customerId    The WordPress user the order belongs to, or null for a guest order.
	 * @param string|null $accessKeyHash The hash of the order's access key, or null when it has none.
	 * @param bool        $keyExpired    Whether the key's time is up, by the database clock; true when there is no key.
	 */
	public function __construct(
		public string $uuid,
		public ?int $customerId,
		public ?string $accessKeyHash,
		public bool $keyExpired
	) {
	}
}

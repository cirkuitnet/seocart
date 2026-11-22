<?php
/**
 * AccountOrderClaim: answers a signed-in customer's claim of an order they placed as a guest
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Interfaces\StoreApi;

use SEOCart\Order\Application\OrderAccessPolicy;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The service behind `order.claim_order`.
 *
 * Owns one fact: what a claim answers. OrderAccessPolicy::claim() decides and writes; this service
 * hands it the order's uuid and the key the request's header carries, read as the status read
 * reads it (OrderStatusRead::presentedKey()), and answers the order's uuid, the same for a claim
 * and for the owner's repeat. It sets no cookie.
 *
 * @since 0.2.0
 */
final class AccountOrderClaim {

	/**
	 * Decides who may make a guest's order theirs, and makes it so.
	 *
	 * @since 0.2.0
	 *
	 * @var OrderAccessPolicy
	 */
	private OrderAccessPolicy $policy;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param OrderAccessPolicy $policy Decides who may make a guest's order theirs, and makes it so.
	 */
	public function __construct( OrderAccessPolicy $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Makes a guest's order the actor's, or answers that it is theirs already.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `order.not_found` for every refusal.
	 *
	 * @param array<string, mixed> $input The prepared input: the order's uuid, and the key when the header carries one.
	 * @param Actor                $actor The user WordPress authenticated the request as, 0 for a visitor.
	 * @return array{uuid: string} The order's uuid.
	 */
	public function claim( array $input, Actor $actor ): array {
		$uuid = (string) $input['uuid'];

		$this->policy->claim( $uuid, $actor, OrderStatusRead::presentedKey( $input ) );

		return array( 'uuid' => $uuid );
	}
}

<?php
/**
 * LocationDetails: what a merchant writes about a location
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

use SEOCart\Support\Address;

defined( 'ABSPATH' ) || exit;

/**
 * The fields of a location a merchant edits: its name, address and phone, what it does, and where it stands in routing.
 *
 * Owns one fact: which of a location's fields are the merchant's to write, and the declared list
 * of how soon a pickup order is ready. Whether the location is active and whether it is the
 * store's default are not here: each changes through a rule of its own. The address is the
 * postal part of an Address, its names, phone, email and tax id left empty, or null until the
 * merchant enters one. It is public: shoppers read it on pickup confirmations, and it is printed
 * on labels. The phone is the merchant's contact for the location: personal data, which a
 * shopper's erasure never reaches.
 *
 * @since 0.2.0
 */
final readonly class LocationDetails {

	/**
	 * How soon a pickup order is usually ready: the keys the storefront words.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const PICKUP_READY_IN = array( '1h', '2h', 'next_business_day', '2_4_days' );

	/**
	 * Records the fields.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $name               The name the merchant gave the location.
	 * @param Address|null $address            Where it is, or null until it is entered.
	 * @param string|null  $phone              Its phone number, or null for none.
	 * @param bool         $fulfilsOnline      Whether it ships online orders.
	 * @param bool         $offersPickup       Whether shoppers may collect orders there.
	 * @param string|null  $pickupReadyIn      How soon a pickup order is ready, one of PICKUP_READY_IN, or null.
	 * @param string|null  $pickupInstructions What a shopper collecting an order is told, or null for nothing.
	 * @param int          $priority           Where it stands in the order locations are tried: the lowest first.
	 */
	public function __construct(
		public string $name,
		public ?Address $address,
		public ?string $phone,
		public bool $fulfilsOnline,
		public bool $offersPickup,
		public ?string $pickupReadyIn,
		public ?string $pickupInstructions,
		public int $priority
	) {
	}
}

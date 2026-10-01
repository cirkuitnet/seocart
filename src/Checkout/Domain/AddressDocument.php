<?php
/**
 * AddressDocument: an address as the checkout stores it and the Store API carries it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain;

use SEOCart\Support\Address;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * Turns an address into a document of its fields, keyed by name, and back.
 *
 * Owns one fact: the names of an address's fields outside PHP. The checkout session stores each
 * address as this document, in JSON, and the Store API reads and writes the same names, so a
 * field is named once. The names are the parameters of Address's constructor, in its order.
 * Every value is a string; an empty string means "not given", as it does to Address.
 *
 * @since 0.1.0
 */
final class AddressDocument {

	/**
	 * The fields of a document, in order, each with its longest value in characters.
	 *
	 * The lengths are those of the order's address columns, so an address the checkout accepts
	 * always fits the order it becomes.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int>
	 */
	public const FIELDS = array(
		'country'    => 2,
		'first_name' => 100,
		'last_name'  => 100,
		'company'    => 255,
		'line1'      => 255,
		'line2'      => 255,
		'city'       => 100,
		'region'     => 64,
		'postcode'   => 32,
		'phone'      => 32,
		'email'      => 255,
		'tax_id'     => 64,
	);

	/**
	 * Cannot be called: the class is a pair of static functions.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Writes an address as a document.
	 *
	 * @since 0.1.0
	 *
	 * @param Address $address The address.
	 * @return array<string, string> Every field, in FIELDS order.
	 */
	public static function of( Address $address ): array {
		return array(
			'country'    => $address->country(),
			'first_name' => $address->firstName(),
			'last_name'  => $address->lastName(),
			'company'    => $address->company(),
			'line1'      => $address->line1(),
			'line2'      => $address->line2(),
			'city'       => $address->city(),
			'region'     => $address->region(),
			'postcode'   => $address->postcode(),
			'phone'      => $address->phone(),
			'email'      => $address->email(),
			'tax_id'     => $address->taxId(),
		);
	}

	/**
	 * Reads an address from a document: a field it leaves out is not given, and a name it does not know is ignored.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the country is not two upper-case letters, or a field is not a string.
	 *
	 * @param array<string, mixed> $document The document.
	 * @return Address The address.
	 */
	public static function toAddress( array $document ): Address {
		$fields = array();

		foreach ( array_keys( self::FIELDS ) as $name ) {
			$value = $document[ $name ] ?? '';

			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'The address field %s must be a string.', $name ) );
			}

			$fields[ $name ] = $value;
		}

		return new Address( ...$fields );
	}
}

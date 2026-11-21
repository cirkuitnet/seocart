<?php
/**
 * BuiltInFields: the checkout fields SEOCart itself declares
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain\Fields;

use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Contracts\Checkout\Section;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the built-in checkout fields, in the order the form shows them, and compiles the address the session write carries them in.
 *
 * Owns one fact: which fields the checkout asks for out of the box, with their words, the
 * presences a store may choose for each, the default first, and what the API reference says of
 * each. Each field is written once, in declared(); everything else is compiled from that list.
 * An address member's name and longest value are AddressDocument's (FIELDS): a field is named by
 * its member, and its length is read from there.
 *
 * Five fields are locked required, so no store can make them optional or hidden: the email
 * address, which the order's messages, its receipt and the gateway's contact need; the country,
 * which tax, shipping, routing and the gateway read; the last name, line 1 and the city, without
 * which no carrier delivers and no gateway checks an address. A single name goes in the last name.
 * The postcode and the region are optional until the store requires them: which countries use
 * them is a per-country rule the checkout does not know yet.
 *
 * The order note is the one built-in field that is no address member: optional or hidden, never
 * required, it rides in the session write's custom fields and is kept with the order's values.
 *
 * Declarations are data: building them performs no I/O, calls no WordPress function and
 * translates nothing.
 *
 * @since 0.2.0
 */
final class BuiltInFields {

	/**
	 * Who declared the built-in fields, as the staff's field list names it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SOURCE = 'core';

	/**
	 * Cannot be called: the class is static functions over one list.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Returns the built-in fields, in the order the form shows them.
	 *
	 * @since 0.2.0
	 *
	 * @return list<CheckoutField> The fields.
	 */
	public static function all(): array {
		return array_map( static fn( array $declared ): CheckoutField => $declared['field'], self::declared() );
	}

	/**
	 * Returns the members of an address on the wire: each built-in field the address carries, as a field of the session write's schema.
	 *
	 * Each member is personal data, named and limited as its field declares, with its label. An
	 * address cannot be held without its country (Address), so the country is the one member the
	 * wire asks for whenever an address is sent; every other required field is asked for when the
	 * order is placed, never by the write.
	 *
	 * @since 0.2.0
	 *
	 * @param string $address Carrier::BILLING or Carrier::SHIPPING.
	 * @return list<FieldSpec> The members, in form order.
	 */
	public static function addressMembers( string $address ): array {
		$members = array();

		foreach ( self::declared() as $declared ) {
			$field = $declared['field'];

			if ( ! in_array( $address, Carrier::addressesOf( $field ), true ) ) {
				continue;
			}

			$members[] = new FieldSpec(
				name: $field->key,
				type: FieldType::String,
				description: $declared['description'],
				label: $field->label,
				example: $declared['example'],
				required: CheckoutFieldType::Country === $field->type,
				max_length: $field->maxLength,
				privacy: Privacy::Pii
			);
		}

		return $members;
	}

	/**
	 * Declares the fields, each once, with what the API reference says of its value.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{field: CheckoutField, description: string, example: string}> The fields, in form order.
	 */
	private static function declared(): array {
		$required = array( Presence::Required );
		$optional = array( Presence::Optional, Presence::Hidden, Presence::Required );
		$hidden   = array( Presence::Hidden, Presence::Optional, Presence::Required );
		$asked    = array( Presence::Optional, Presence::Required );

		return array(
			self::member( 'email', Section::Contact, CheckoutFieldType::Email, static fn(): string => __( 'Email address', 'seocart' ), $required, 'email', 'The e-mail address.', 'ada@example.com' ),
			self::member( 'country', Section::Address, CheckoutFieldType::Country, static fn(): string => __( 'Country', 'seocart' ), $required, 'country', 'The ISO 3166-1 alpha-2 country code, two upper-case letters.', 'GB' ),
			self::member( 'first_name', Section::Address, CheckoutFieldType::Text, static fn(): string => __( 'First name', 'seocart' ), array( Presence::Required, Presence::Optional ), 'given-name', 'The given name.', 'Ada' ),
			self::member( 'last_name', Section::Address, CheckoutFieldType::Text, static fn(): string => __( 'Last name', 'seocart' ), $required, 'family-name', 'The family name, or the whole name of a person who has one name.', 'Lovelace' ),
			self::member( 'company', Section::Address, CheckoutFieldType::Text, static fn(): string => __( 'Company', 'seocart' ), $hidden, 'organization', 'The company name.', 'Analytical Engines Ltd' ),
			self::member( 'line1', Section::Address, CheckoutFieldType::Text, static fn(): string => __( 'Address line 1', 'seocart' ), $required, 'address-line1', 'The first address line.', '12 St James\'s Square' ),
			self::member( 'line2', Section::Address, CheckoutFieldType::Text, static fn(): string => __( 'Address line 2', 'seocart' ), $optional, 'address-line2', 'The second address line.', 'Flat 3' ),
			self::member( 'city', Section::Address, CheckoutFieldType::Text, static fn(): string => __( 'City', 'seocart' ), $required, 'address-level2', 'The city or town.', 'London' ),
			self::member( 'region', Section::Address, CheckoutFieldType::Text, static fn(): string => __( 'Region', 'seocart' ), $asked, 'address-level1', 'The state, province, county or other subdivision.', 'Greater London' ),
			self::member( 'postcode', Section::Address, CheckoutFieldType::Text, static fn(): string => __( 'Postcode', 'seocart' ), $asked, 'postal-code', 'The postal code.', 'SW1Y 4JH' ),
			self::member( 'phone', Section::Address, CheckoutFieldType::Tel, static fn(): string => __( 'Phone', 'seocart' ), $optional, 'tel', 'The phone number.', '+44 20 7946 0000' ),
			self::member( 'tax_id', Section::Address, CheckoutFieldType::Text, static fn(): string => __( 'Tax ID', 'seocart' ), $hidden, null, 'The tax identifier, such as a VAT number.', 'GB000000000' ),
			array(
				'field'       => new CheckoutField( key: 'order_note', section: Section::Order, type: CheckoutFieldType::Textarea, label: static fn(): string => __( 'Order note', 'seocart' ), maxLength: 1000, presences: array( Presence::Optional, Presence::Hidden ) ),
				'description' => 'A note the shopper leaves for the store with the order.',
				'example'     => 'Please leave it with the porter.',
			),
		);
	}

	/**
	 * Declares one field named as an address member, its longest value the member's.
	 *
	 * @since 0.2.0
	 *
	 * @param string            $key          The address member: a key of AddressDocument::FIELDS.
	 * @param Section           $section      Where the form shows it.
	 * @param CheckoutFieldType $type         What its value is.
	 * @param \Closure          $label        Its label, translated when called.
	 * @param Presence[]        $presences    The presences a store may choose, the default first.
	 * @param string|null       $autocomplete The HTML autocomplete token, or null for none.
	 * @param string            $description  What the API reference says of its value: one English sentence.
	 * @param string            $example      A value the API reference shows.
	 * @return array{field: CheckoutField, description: string, example: string} The field, with its description and example.
	 *
	 * @phpstan-param \Closure(): string $label
	 * @phpstan-param list<Presence>     $presences
	 */
	private static function member( string $key, Section $section, CheckoutFieldType $type, \Closure $label, array $presences, ?string $autocomplete, string $description, string $example ): array {
		return array(
			'field'       => new CheckoutField( key: $key, section: $section, type: $type, label: $label, maxLength: AddressDocument::FIELDS[ $key ], presences: $presences, autocomplete: $autocomplete ),
			'description' => $description,
			'example'     => $example,
		);
	}
}

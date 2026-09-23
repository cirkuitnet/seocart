<?php
/**
 * Address: a postal address with the contact details that travel with it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable billing or shipping address.
 *
 * This class owns one fact: which fields make up an address in SEOCart — the columns of
 * `order_addresses` without the storage metadata (the row id, the order it belongs to and the
 * role it plays there) and without the record of a validation (`validated_at`,
 * `validation_provider`), which describes a check made on the address by a provider rather
 * than the address itself. Whether a field is required, how long it may be and how it is
 * validated are declared by the checkout fields that collect it, not here.
 *
 * Every field is personal data. The privacy classification is declared once, where the data
 * is stored and exposed — the `pii` classification of the `order_addresses` columns in its
 * table definition, and the privacy flag of the checkout field specifications — and the
 * exporter, eraser, log redaction and REST visibility are derived from there. This value
 * object carries no second copy of that classification. It has no string form, so it cannot
 * end up in a log line or an error context by accident; an error context holds scalars only.
 *
 * @since 0.1.0
 */
final class Address {

	/**
	 * The ISO 3166-1 alpha-2 country code.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $country;

	/**
	 * The given name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $firstName;

	/**
	 * The family name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $lastName;

	/**
	 * The company name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $company;

	/**
	 * The first address line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $line1;

	/**
	 * The second address line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $line2;

	/**
	 * The city or town.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $city;

	/**
	 * The state, province, county or other subdivision.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $region;

	/**
	 * The postal code.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $postcode;

	/**
	 * The phone number.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $phone;

	/**
	 * The e-mail address.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $email;

	/**
	 * The tax identifier, such as a VAT number.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $taxId;

	/**
	 * Creates an address. Pass the fields by name; an empty string means "not given".
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the country is not two upper-case letters.
	 *
	 * @param string $country    The ISO 3166-1 alpha-2 country code, for example 'GB'.
	 * @param string $first_name Optional. The given name. Default empty.
	 * @param string $last_name  Optional. The family name. Default empty.
	 * @param string $company    Optional. The company name. Default empty.
	 * @param string $line1      Optional. The first address line. Default empty.
	 * @param string $line2      Optional. The second address line. Default empty.
	 * @param string $city       Optional. The city or town. Default empty.
	 * @param string $region     Optional. The state, province, county or other subdivision. Default empty.
	 * @param string $postcode   Optional. The postal code. Default empty.
	 * @param string $phone      Optional. The phone number. Default empty.
	 * @param string $email      Optional. The e-mail address. Default empty.
	 * @param string $tax_id     Optional. The tax identifier, such as a VAT number. Default empty.
	 */
	public function __construct(
		string $country,
		string $first_name = '',
		string $last_name = '',
		string $company = '',
		string $line1 = '',
		string $line2 = '',
		string $city = '',
		string $region = '',
		string $postcode = '',
		string $phone = '',
		string $email = '',
		string $tax_id = ''
	) {
		if ( 1 !== preg_match( '/^[A-Z]{2}\z/', $country ) ) {
			throw new \InvalidArgumentException( 'An address country is an ISO 3166-1 alpha-2 code: two upper-case letters, such as GB.' );
		}

		$this->country   = $country;
		$this->firstName = $first_name;
		$this->lastName  = $last_name;
		$this->company   = $company;
		$this->line1     = $line1;
		$this->line2     = $line2;
		$this->city      = $city;
		$this->region    = $region;
		$this->postcode  = $postcode;
		$this->phone     = $phone;
		$this->email     = $email;
		$this->taxId     = $tax_id;
	}

	/**
	 * Returns the ISO 3166-1 alpha-2 country code.
	 *
	 * @since 0.1.0
	 *
	 * @return string The country code, for example 'GB'.
	 */
	public function country(): string {
		return $this->country;
	}

	/**
	 * Returns the given name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The given name, or an empty string.
	 */
	public function firstName(): string {
		return $this->firstName;
	}

	/**
	 * Returns the family name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The family name, or an empty string.
	 */
	public function lastName(): string {
		return $this->lastName;
	}

	/**
	 * Returns the company name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The company name, or an empty string.
	 */
	public function company(): string {
		return $this->company;
	}

	/**
	 * Returns the first address line.
	 *
	 * @since 0.1.0
	 *
	 * @return string The first line, or an empty string.
	 */
	public function line1(): string {
		return $this->line1;
	}

	/**
	 * Returns the second address line.
	 *
	 * @since 0.1.0
	 *
	 * @return string The second line, or an empty string.
	 */
	public function line2(): string {
		return $this->line2;
	}

	/**
	 * Returns the city or town.
	 *
	 * @since 0.1.0
	 *
	 * @return string The city, or an empty string.
	 */
	public function city(): string {
		return $this->city;
	}

	/**
	 * Returns the state, province, county or other subdivision.
	 *
	 * @since 0.1.0
	 *
	 * @return string The region, or an empty string.
	 */
	public function region(): string {
		return $this->region;
	}

	/**
	 * Returns the postal code.
	 *
	 * @since 0.1.0
	 *
	 * @return string The postal code, or an empty string.
	 */
	public function postcode(): string {
		return $this->postcode;
	}

	/**
	 * Returns the phone number.
	 *
	 * @since 0.1.0
	 *
	 * @return string The phone number, or an empty string.
	 */
	public function phone(): string {
		return $this->phone;
	}

	/**
	 * Returns the e-mail address.
	 *
	 * @since 0.1.0
	 *
	 * @return string The e-mail address, or an empty string.
	 */
	public function email(): string {
		return $this->email;
	}

	/**
	 * Returns the tax identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string The tax identifier, or an empty string.
	 */
	public function taxId(): string {
		return $this->taxId;
	}

	/**
	 * Tells whether two addresses are the same, field by field.
	 *
	 * @since 0.1.0
	 *
	 * @param Address $other The address to compare with.
	 * @return bool True when every field is identical.
	 */
	public function equals( Address $other ): bool {
		return get_object_vars( $this ) === get_object_vars( $other );
	}
}

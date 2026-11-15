<?php
/**
 * BillingContact: the name and postal address a payment is billed to, as a gateway is given them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The order's billing contact: given and family name, company, two address lines, city, region, postcode and country.
 *
 * Owns one fact: what of the billing address a gateway is given with an authorization. Each value
 * is the order's own billing field as the checkout took it, within the limits of the order's
 * address fields, as plain text: UTF-8 with no control character (CONTROL_CHARACTER), which SEOCart
 * makes of one the checkout took a space; an empty string means not given. The shopper's e-mail
 * address, phone number and tax identifier are not part of it.
 *
 * Every value is personal data. An adapter sends the contact only where its provider needs it,
 * such as for its address check, and names it in what its host sends (OutboundHost::$dataSent).
 * SEOCart's log writes it, as any value of a log line's context, by its JSON form, each field under
 * the name the plugin declares personal data (jsonSerialize()), the company name (organization)
 * under `company`; its members copied out under their own names, such as by get_object_vars() or
 * an array cast, are redacted too, since each name is declared personal data (LOGGED_AS,
 * personalData()). A dump shows its values masked (__debugInfo()). A field copied out of it under a
 * name of the adapter's own choosing is beyond that redaction: an adapter logs none of it.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class BillingContact implements \JsonSerializable {

	/**
	 * A character no value of a contact holds, as a regular expression's character class, read as UTF-8.
	 *
	 * Every Unicode control character (category Cc: U+0000 to U+001F and U+007F to U+009F) and the
	 * line and paragraph separators (U+2028, U+2029): each starts a new line, or does something
	 * other than show a character, wherever the contact is written.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONTROL_CHARACTER = '[\p{Cc}\x{2028}\x{2029}]';

	/**
	 * The name each member is logged as: the order's address field it holds, by member.
	 *
	 * The one declaration of the names the contact is known by outside PHP. Its JSON form names each
	 * value by it (jsonSerialize()), and the log's redaction learns each of these names and each
	 * member's own (personalData()), so a value is redacted whichever of the two a log line's context
	 * names it by. Every public member is listed: a test holds the list to the class's members.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const LOGGED_AS = array(
		'country'      => 'country',
		'firstName'    => 'first_name',
		'lastName'     => 'last_name',
		'organization' => 'company',
		'line1'        => 'line1',
		'line2'        => 'line2',
		'city'         => 'city',
		'region'       => 'region',
		'postcode'     => 'postcode',
	);

	/**
	 * Records the contact.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the country is not two upper-case letters, or a value is not valid UTF-8
	 *                                   plain text: it holds a control character (CONTROL_CHARACTER).
	 *
	 * @param string $country   The ISO 3166-1 alpha-2 country code, for example 'US'.
	 * @param string $firstName Optional. The given name. Default empty.
	 * @param string $lastName  Optional. The family name. Default empty.
	 * @param string $organization Optional. The company name, the order's company field. Default empty.
	 * @param string $line1     Optional. The first address line. Default empty.
	 * @param string $line2     Optional. The second address line. Default empty.
	 * @param string $city      Optional. The city or town. Default empty.
	 * @param string $region    Optional. The state, province, county or other subdivision. Default empty.
	 * @param string $postcode  Optional. The postal code. Default empty.
	 */
	public function __construct(
		public string $country,
		public string $firstName = '',
		public string $lastName = '',
		public string $organization = '',
		public string $line1 = '',
		public string $line2 = '',
		public string $city = '',
		public string $region = '',
		public string $postcode = ''
	) {
		if ( 1 !== preg_match( '/^[A-Z]{2}\z/', $country ) ) {
			throw new \InvalidArgumentException( 'A billing contact\'s country is an ISO 3166-1 alpha-2 code: two upper-case letters, such as US.' );
		}

		foreach ( array( $firstName, $lastName, $organization, $line1, $line2, $city, $region, $postcode ) as $value ) {
			// preg_match() gives 0 for plain text, 1 for a control character, and false for text that is not valid UTF-8.
			if ( 0 !== preg_match( '/' . self::CONTROL_CHARACTER . '/u', $value ) ) {
				throw new \InvalidArgumentException( 'A billing contact\'s value is plain text: valid UTF-8, with no control character and no line or paragraph separator.' );
			}
		}
	}

	/**
	 * Returns the personal-data fields SEOCart's log redacts the contact under: each member's own name, lower-cased, and each name it is logged as (LOGGED_AS).
	 *
	 * The log compares a context's keys without regard to case, so a member copied out under its own
	 * name, such as `firstName`, is redacted as one under the order's name, such as `first_name`.
	 * SEOCart's kernel declares these fields to the log's redaction with the plugin's other fields;
	 * a name another declaration already makes personal data stays so, and one it makes secret stays
	 * secret.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The fields, each personal data, each name once.
	 */
	public static function personalData(): array {
		$names = array_values( array_unique( array_merge( array_map( 'strtolower', array_keys( self::LOGGED_AS ) ), array_values( self::LOGGED_AS ) ) ) );

		return array_map(
			static fn( string $name ): FieldSpec => new FieldSpec(
				name: $name,
				type: FieldType::String,
				description: 'A value of the billing contact a gateway is given with an authorization.',
				label: static fn(): string => __( 'Billing contact', 'seocart' ),
				example: '',
				privacy: Privacy::Pii
			),
			$names
		);
	}

	/**
	 * Returns the contact's fields under the names the plugin declares personal data, the order's address fields' (LOGGED_AS): what SEOCart's log redacts.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The fields, by name.
	 */
	public function jsonSerialize(): array {
		$fields = array();

		foreach ( self::LOGGED_AS as $member => $name ) {
			$fields[ $name ] = $this->{$member};
		}

		return $fields;
	}

	/**
	 * Returns what a dump of the contact shows: each value given masked, each one not given empty.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The fields, by name, masked.
	 */
	public function __debugInfo(): array {
		return array_map( static fn( string $value ): string => '' === $value ? '' : '[masked]', get_object_vars( $this ) );
	}
}

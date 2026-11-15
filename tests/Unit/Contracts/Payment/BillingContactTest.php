<?php
/**
 * Tests the billing contact a gateway is given with an authorization
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\BillingContact;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The contact is the order's billing address without its e-mail address, phone number and tax identifier; it holds plain text alone; its JSON form names its fields as the plugin declares them personal data; a dump masks them; and a request carries none unless given one.
 *
 * @group contract
 * @since 0.2.0
 */
final class BillingContactTest extends TestCase {

	/**
	 * Returns the address the tests' order is billed to.
	 *
	 * @since 0.2.0
	 *
	 * @return Address The address.
	 */
	private static function address(): Address {
		return new Address(
			country: 'US',
			first_name: 'Ada',
			last_name: 'Lovelace',
			company: 'Analytical Engines',
			line1: '1 Main Street',
			line2: 'Suite 2',
			city: 'Austin',
			region: 'TX',
			postcode: '78701',
			phone: '+1 512 555 0100',
			email: 'ada@example.com',
			tax_id: 'US-TAX-1'
		);
	}

	/**
	 * Tests that the contact of an address carries its name, company, lines, city, region, postcode and country, and none of its e-mail address, phone number and tax identifier.
	 *
	 * @since 0.2.0
	 */
	public function test_the_contact_of_an_address_is_its_name_and_postal_address(): void {
		$contact = PaymentService::billingContactOf( self::address() );

		$this->assertSame( array( 'US', 'Ada', 'Lovelace', 'Analytical Engines', '1 Main Street', 'Suite 2', 'Austin', 'TX', '78701' ), array_values( get_object_vars( $contact ) ) );
		$this->assertStringNotContainsString( 'ada@example.com', (string) json_encode( $contact ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The unit suite loads no WordPress.
		$this->assertStringNotContainsString( '555', (string) json_encode( $contact ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The unit suite loads no WordPress.
		$this->assertStringNotContainsString( 'US-TAX-1', (string) json_encode( $contact ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The unit suite loads no WordPress.
	}

	/**
	 * Tests that the contact's JSON form names each field as the order's address field is named, the names the plugin declares personal data.
	 *
	 * @since 0.2.0
	 */
	public function test_the_json_form_names_the_fields_as_the_order_does(): void {
		$this->assertSame(
			array(
				'country'    => 'US',
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'company'    => 'Analytical Engines',
				'line1'      => '1 Main Street',
				'line2'      => 'Suite 2',
				'city'       => 'Austin',
				'region'     => 'TX',
				'postcode'   => '78701',
			),
			PaymentService::billingContactOf( self::address() )->jsonSerialize()
		);
	}

	/**
	 * Tests that a dump of the contact shows each value given masked and each one not given empty.
	 *
	 * @since 0.2.0
	 */
	public function test_a_dump_masks_the_values(): void {
		ob_start();
		var_dump( new BillingContact( 'US', 'Ada', '', '', '1 Main Street' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- What a dump of the contact shows is under test.
		$dump = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Ada', $dump );
		$this->assertStringNotContainsString( 'Main Street', $dump );
		$this->assertStringContainsString( '[masked]', $dump );
		$this->assertSame(
			array(
				'country'      => '[masked]',
				'firstName'    => '[masked]',
				'lastName'     => '',
				'organization' => '',
				'line1'        => '[masked]',
				'line2'        => '',
				'city'         => '',
				'region'       => '',
				'postcode'     => '',
			),
			( new BillingContact( 'US', 'Ada', '', '', '1 Main Street' ) )->__debugInfo()
		);
	}

	/**
	 * Returns contacts the contract refuses: a country that is no ISO 3166-1 alpha-2 code, and values that are no plain text.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{array<string, string>}> The contact's fields, by name.
	 */
	public function refused(): array {
		return array(
			'a country in lower case'          => array( array( 'country' => 'us' ) ),
			'a country of three letters'       => array( array( 'country' => 'USA' ) ),
			'an empty country'                 => array( array( 'country' => '' ) ),
			'a line break in a name'           => array( array( 'firstName' => "Ada\nLovelace" ) ),
			'a tab in an address line'         => array( array( 'line1' => "1\tMain Street" ) ),
			'a nul byte in the city'           => array( array( 'city' => "Austin\0" ) ),
			'a delete character in the region' => array( array( 'region' => "T\x7FX" ) ),
			'bytes that are not UTF-8'         => array( array( 'postcode' => "787\xC3" ) ),
		);
	}

	/**
	 * Tests that a contact of a country that is no two upper-case letters, or of a value that is no plain text, is refused.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider refused
	 *
	 * @param array<string, string> $fields The contact's fields, by name.
	 */
	public function test_a_value_that_is_no_plain_text_or_country_is_refused( array $fields ): void {
		$this->expectException( \InvalidArgumentException::class );

		new BillingContact( ...( $fields + array( 'country' => 'US' ) ) );
	}

	/**
	 * Tests that the contact of an address whose text the checkout took with control characters, or with bytes that are not UTF-8, is plain text and is never refused: each run of control characters is one space, each such byte a `?`.
	 *
	 * @since 0.2.0
	 */
	public function test_the_contact_of_an_address_with_control_characters_is_plain_text(): void {
		$contact = PaymentService::billingContactOf( new Address( country: 'US', first_name: "Ada\tB.", line1: "1 Main Street\r\nApt 2", city: "Aus\x7Ftin", postcode: "787\xC301" ) );

		$this->assertSame( array( 'Ada B.', '1 Main Street Apt 2', 'Aus tin', '787?01' ), array( $contact->firstName, $contact->line1, $contact->city, $contact->postcode ) );
	}

	/**
	 * Tests that a contact of letters beyond ASCII and of empty values is taken as it is.
	 *
	 * @since 0.2.0
	 */
	public function test_text_beyond_ascii_and_empty_values_are_taken(): void {
		$contact = new BillingContact( 'DE', 'Jürgen', 'Müller-Lüdenscheidt', '', 'Straße 1', '', 'Köln' );

		$this->assertSame( array( 'DE', 'Jürgen', 'Müller-Lüdenscheidt', '', 'Straße 1', '', 'Köln', '', '' ), array_values( get_object_vars( $contact ) ) );
	}

	/**
	 * Tests that an authorization request carries no billing contact unless it is given one, so a gateway written before it was added is given what it was.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_carries_no_contact_unless_given_one(): void {
		$amount  = Money::of( 2000, Currency::of( 'USD' ) );
		$contact = PaymentService::billingContactOf( self::address() );

		$this->assertNull( ( new PaymentRequest( '01928c3e-0000-7000-8000-000000000001', $amount, 'tok', Mode::Test, '01928c3e-0000-7000-8000-0000000000a1', '1001' ) )->billing );
		$this->assertSame( $contact, ( new PaymentRequest( '01928c3e-0000-7000-8000-000000000001', $amount, 'tok', Mode::Test, '01928c3e-0000-7000-8000-0000000000a1', '1001', null, $contact ) )->billing );
	}
}

<?php
/**
 * Tests that a placement gives the gateway the order's billing contact, and that a log line keeps none of it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Contracts\Payment\BillingContact;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;

/**
 * A guest's and a customer's placement hand the gateway the billing address the checkout holds, as its billing contact, with no statement more; a line logged with the contact in its message and its context keeps none of its values.
 *
 * Planted violation, shown red and removed: in PlaceOrder::place(), give paid() no billing
 * address: the gateway is given no contact, and both placements' tests fail.
 *
 * @group contract
 * @since 0.2.0
 */
final class BillingContactPlacementTest extends PlacementTestCase {

	use CreatesUsers;

	/**
	 * Removes the users the test made.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a guest's placement hands the gateway the checkout's billing address as its contact.
	 *
	 * @since 0.2.0
	 */
	public function test_a_guests_placement_hands_the_gateway_the_billing_contact(): void {
		$this->assertContactHanded( self::guest() );
	}

	/**
	 * Tests that a signed-in customer's placement hands the gateway the checkout's billing address as its contact.
	 *
	 * @since 0.2.0
	 */
	public function test_a_customers_placement_hands_the_gateway_the_billing_contact(): void {
		$this->assertContactHanded( Actor::user( $this->createUser( 'customer' ) ) );
	}

	/**
	 * Tests that a line logged with the contact, in its message as JSON and in its context as the contact and as its fields under the names it gives them, keeps none of its values.
	 *
	 * @since 0.2.0
	 */
	public function test_a_line_logged_with_the_contact_keeps_none_of_it(): void {
		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$contact = self::contact();

		$this->kernel->get( Logger::class )->error(
			'gateway.planted',
			'Sent the contact ' . wp_json_encode( $contact ) . '.',
			array(
				'billing' => $contact,
				'fields'  => $contact->jsonSerialize(),
				'city'    => $contact->city,
			)
		);

		$lines = $this->db->fetchAll( 'SELECT message, context_json FROM %i WHERE machine_code = %s', $this->table( LogsTable::NAME ), 'gateway.planted' );

		$this->assertCount( 1, $lines, 'The planted line was written.' );

		foreach ( array_filter( get_object_vars( self::contact() ) ) as $field => $value ) {
			$this->assertStringNotContainsString( $value, (string) wp_json_encode( $lines[0] ), sprintf( 'The log line keeps the contact\'s %s.', $field ) );
		}
	}

	/**
	 * Tests that a line logged with the contact's members copied into its context under their own names, from its public properties and from an array cast, keeps none of their values.
	 *
	 * Planted violation, shown red and removed: leave one member out of the names the contact
	 * declares for the log (BillingContact::LOGGED_AS): the line keeps that member's value.
	 *
	 * @since 0.2.0
	 */
	public function test_a_line_logged_with_the_contacts_members_by_their_own_names_keeps_none_of_them(): void {
		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$contact = new BillingContact( 'GB', 'Augusta', 'Byron-King', 'Analytical Engines Ltd', '12 St James Square', 'Flat 3', 'Marylebone', 'Greater London', 'NW1 6XE' );

		$this->kernel->get( Logger::class )->error(
			'gateway.planted',
			'Sent the contact.',
			array(
				'properties' => get_object_vars( $contact ),
				'cast'       => (array) $contact,
			)
		);

		$lines = $this->db->fetchAll( 'SELECT message, context_json FROM %i WHERE machine_code = %s', $this->table( LogsTable::NAME ), 'gateway.planted' );

		$this->assertCount( 1, $lines, 'The planted line was written.' );
		$this->assertSame( array_keys( get_object_vars( $contact ) ), array_keys( (array) $contact ), 'The cast names the members as the properties do.' );

		foreach ( get_object_vars( $contact ) as $member => $value ) {
			$this->assertStringNotContainsString( $value, (string) wp_json_encode( $lines[0] ), sprintf( 'The log line keeps the contact\'s %s.', $member ) );
		}
	}

	/**
	 * Returns the contact of the billing address the test case's checkout is written with (CheckoutTestCase::address()).
	 *
	 * @since 0.2.0
	 *
	 * @return BillingContact The contact.
	 */
	private static function contact(): BillingContact {
		$billing = self::address( 'US' );

		return new BillingContact( country: $billing->country(), firstName: $billing->firstName(), lastName: $billing->lastName(), line1: $billing->line1(), city: $billing->city(), postcode: $billing->postcode() );
	}

	/**
	 * Places an order of one unit as an actor and asserts the gateway was given the checkout's billing contact.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor $actor Who places it.
	 */
	private function assertContactHanded( Actor $actor ): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$answer = $this->placement->place( $this->placeInput(), $actor );

		$this->assertSame( 'approved', $answer['outcome'] );
		$this->assertCount( 1, $this->gateway->authorizations );
		$this->assertEquals( self::contact(), $this->gateway->authorizations[0]->billing );
	}
}

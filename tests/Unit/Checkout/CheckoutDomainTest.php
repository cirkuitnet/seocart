<?php
/**
 * Tests the checkout's values: the address document, the frozen quotes, the session's staleness rule and the key's hash
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutSession;
use SEOCart\Checkout\Domain\FrozenQuotes;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Support\Address;

/**
 * The checkout's values, without a database.
 *
 * Planted violation: in AddressDocument::FIELDS, give `postcode` 40 characters: an address the
 * checkout accepts could then not be written to the order it becomes.
 *
 * @since 0.1.0
 */
final class CheckoutDomainTest extends TestCase {

	/**
	 * Tests that an address goes to its document and back unchanged, and that the document names Address's fields in its constructor's order.
	 *
	 * @since 0.1.0
	 */
	public function test_an_address_round_trips_through_its_document(): void {
		$address  = new Address( 'GB', 'Ada', 'Lovelace', 'Analytical Engines', '12 St James\'s Square', 'Flat 3', 'London', 'Greater London', 'SW1Y 4JH', '+44 20 7946 0000', 'ada@example.com', 'GB000000000' );
		$document = AddressDocument::of( $address );

		$this->assertSame( array_keys( AddressDocument::FIELDS ), array_keys( $document ) );
		$this->assertSame( array_keys( AddressDocument::FIELDS ), array_map( static fn( \ReflectionParameter $parameter ): string => $parameter->getName(), ( new \ReflectionMethod( Address::class, '__construct' ) )->getParameters() ) );
		$this->assertTrue( $address->equals( AddressDocument::toAddress( $document ) ) );
		$this->assertTrue(
			( new Address( 'DE' ) )->equals(
				AddressDocument::toAddress(
					array(
						'country' => 'DE',
						'unknown' => 'ignored',
					)
				)
			),
			'A field left out is not given, and an unknown one ignored.'
		);
	}

	/**
	 * Tests that a document Address cannot hold is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_document_address_cannot_hold_is_refused(): void {
		foreach ( array(
			array( 'country' => 'gb' ),
			array(
				'country' => 'GB',
				'city'    => 7,
			),
			array(),
		) as $document ) {
			try {
				AddressDocument::toAddress( $document );
				$this->fail( 'A document Address cannot hold was read: ' . (string) json_encode( $document ) );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertNotSame( '', $refused->getMessage() );
			}
		}
	}

	/**
	 * Tests that every field of an address fits the order's address column of the same name.
	 *
	 * @since 0.1.0
	 */
	public function test_every_address_field_fits_the_orders_address_column(): void {
		$columns = array();

		foreach ( OrderTables::addresses()->columns() as $column ) {
			if ( 1 === preg_match( '/^(?:var)?char\((\d+)\)$/', $column->type(), $length ) ) {
				$columns[ $column->name() ] = (int) $length[1];
			}
		}

		foreach ( AddressDocument::FIELDS as $field => $length ) {
			$this->assertSame( $columns[ $field ] ?? null, $length, "The address field {$field} does not have the length of the order's column." );
		}
	}

	/**
	 * Tests that a session's quotes are current at exactly their version, and that its delivery is its shipping address and method.
	 *
	 * @since 0.1.0
	 */
	public function test_a_sessions_quotes_are_current_at_their_version_only(): void {
		$details = new CheckoutDetails( null, new Address( 'GB' ), 'flat', null );
		$quoted  = new CheckoutSession( 7, $details, 3, new FrozenQuotes( null, hash( 'sha256', 'tax' ) ) );
		$none    = new CheckoutSession( 7, $details, 0, null );

		$this->assertSame( array( false, true, false ), array( $quoted->quotesAreCurrentAt( 2 ), $quoted->quotesAreCurrentAt( 3 ), $quoted->quotesAreCurrentAt( 4 ) ) );
		$this->assertFalse( $none->quotesAreCurrentAt( 0 ), 'A session without quotes had current ones.' );
		$this->assertSame(
			array(
				'destination'         => $details->shippingAddress,
				'shipping_method_key' => 'flat',
			),
			CheckoutSession::deliveryOf( $quoted )
		);
		$this->assertSame(
			array(
				'destination'         => null,
				'shipping_method_key' => null,
			),
			CheckoutSession::deliveryOf( null )
		);
	}

	/**
	 * Tests what a method key is: lower-case ASCII letters, digits, dots, colons, hyphens and underscores, 1 to 64 of them; details refuse anything else.
	 *
	 * @since 0.1.0
	 */
	public function test_a_method_key_is_lower_case_ascii_of_a_bounded_length(): void {
		foreach ( array( 'flat', 'stub', 'carrier.express:next-day_1', str_repeat( 'k', CheckoutDetails::METHOD_KEY_MAX_LENGTH ) ) as $key ) {
			$this->assertTrue( CheckoutDetails::isMethodKey( $key ), $key );
		}

		foreach ( array( '', 'Flat', 'flat rate', "\u{1F600}", 'été', "flat\n", str_repeat( 'k', CheckoutDetails::METHOD_KEY_MAX_LENGTH + 1 ) ) as $key ) {
			$this->assertFalse( CheckoutDetails::isMethodKey( $key ), $key );
		}

		$this->expectException( \InvalidArgumentException::class );

		new CheckoutDetails( null, null, 'Flat', null );
	}

	/**
	 * Tests that a tax quote fingerprint must be a SHA-256 in lower-case hexadecimal.
	 *
	 * @since 0.1.0
	 */
	public function test_a_tax_fingerprint_is_a_sha256(): void {
		$this->assertSame( hash( 'sha256', 'tax' ), ( new FrozenQuotes( null, hash( 'sha256', 'tax' ) ) )->taxFingerprint );

		$this->expectException( \InvalidArgumentException::class );

		new FrozenQuotes( null, strtoupper( hash( 'sha256', 'tax' ) ) );
	}

	/**
	 * Tests the key's hash: the cart token's hash and the key, so the same key on two carts is two keys; a key is 1 to 64 characters.
	 *
	 * @since 0.1.0
	 */
	public function test_a_key_is_hashed_with_its_cart(): void {
		$cartA = hash( 'sha256', 'cart a' );
		$cartB = hash( 'sha256', 'cart b' );

		$this->assertSame( hash( 'sha256', $cartA . '|attempt-1' ), IdempotencyClaim::keyHash( $cartA, 'attempt-1' ) );
		$this->assertNotSame( IdempotencyClaim::keyHash( $cartA, 'attempt-1' ), IdempotencyClaim::keyHash( $cartB, 'attempt-1' ) );
		$this->assertSame( 64, strlen( IdempotencyClaim::keyHash( $cartA, str_repeat( 'k', IdempotencyClaim::MAX_KEY_LENGTH ) ) ) );

		foreach ( array( array( $cartA, '' ), array( $cartA, str_repeat( 'k', IdempotencyClaim::MAX_KEY_LENGTH + 1 ) ), array( 'not a hash', 'attempt-1' ) ) as list( $cart, $key ) ) {
			try {
				IdempotencyClaim::keyHash( $cart, $key );
				$this->fail( 'A key hash was made of a key of ' . strlen( $key ) . ' characters on ' . $cart . '.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertNotSame( '', $refused->getMessage() );
			}
		}
	}
}

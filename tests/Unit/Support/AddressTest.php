<?php
/**
 * Tests Address: the fields of an order address snapshot
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Address;

/**
 * Proves that an address keeps every field it is given and refuses an invalid country.
 *
 * @since 0.1.0
 */
final class AddressTest extends TestCase {

	/**
	 * Tests that every field is kept.
	 *
	 * @since 0.1.0
	 */
	public function test_it_keeps_every_field(): void {
		$address = self::sample();

		$this->assertSame(
			array( 'GB', 'Ada', 'Lovelace', 'Analytical Ltd', '12 St James Square', 'Flat 2', 'London', 'Greater London', 'SW1Y 4JH', '+44 20 7946 0000', 'ada@example.org', 'GB123456789' ),
			array(
				$address->country(),
				$address->firstName(),
				$address->lastName(),
				$address->company(),
				$address->line1(),
				$address->line2(),
				$address->city(),
				$address->region(),
				$address->postcode(),
				$address->phone(),
				$address->email(),
				$address->taxId(),
			)
		);
	}

	/**
	 * Tests that fields not given are empty, so a digital order's billing address can be a country and an e-mail.
	 *
	 * @since 0.1.0
	 */
	public function test_fields_not_given_are_empty(): void {
		$address = new Address( country: 'DE', email: 'kundin@example.org' );

		$this->assertSame( 'DE', $address->country() );
		$this->assertSame( 'kundin@example.org', $address->email() );
		$this->assertSame( '', $address->line1() );
		$this->assertSame( '', $address->taxId() );
	}

	/**
	 * Tests equality field by field.
	 *
	 * @since 0.1.0
	 */
	public function test_equality_is_field_by_field(): void {
		$this->assertTrue( self::sample()->equals( self::sample() ) );
		$this->assertFalse( self::sample()->equals( new Address( country: 'GB', first_name: 'Ada' ) ) );
	}

	/**
	 * Tests that a country that is not two upper-case letters is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_invalid_countries
	 *
	 * @param string $country The country.
	 */
	public function test_an_invalid_country_is_refused( string $country ): void {
		$this->expectException( \InvalidArgumentException::class );

		new Address( country: $country );
	}

	/**
	 * Provides countries that must be refused.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public static function data_invalid_countries(): array {
		return array(
			'empty'      => array( '' ),
			'lower case' => array( 'gb' ),
			'alpha-3'    => array( 'GBR' ),
			'a name'     => array( 'Germany' ),
			'line feed'  => array( "GB\n" ),
		);
	}

	/**
	 * Returns an address with every field set.
	 *
	 * @since 0.1.0
	 *
	 * @return Address The address.
	 */
	private static function sample(): Address {
		return new Address(
			country: 'GB',
			first_name: 'Ada',
			last_name: 'Lovelace',
			company: 'Analytical Ltd',
			line1: '12 St James Square',
			line2: 'Flat 2',
			city: 'London',
			region: 'Greater London',
			postcode: 'SW1Y 4JH',
			phone: '+44 20 7946 0000',
			email: 'ada@example.org',
			tax_id: 'GB123456789'
		);
	}
}

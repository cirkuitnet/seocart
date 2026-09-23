<?php
/**
 * Tests which values a setting accepts for writing, and how a stored value is read back
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Settings;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Settings\InternationalSettings;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingValues;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Support\SettingsFixtures;

/**
 * SettingValues on the fixture settings and the base currency.
 *
 * Whether these answers match what WordPress's validator lets through on the operation surfaces is
 * the agreement test's to show; this test pins the answers themselves.
 *
 * @since 0.1.0
 */
final class SettingValuesTest extends TestCase {

	/**
	 * Tests that a value that fits its field is accepted as it is.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider acceptedValues
	 *
	 * @param string     $setting The setting's name.
	 * @param int|string $value   The value.
	 */
	public function test_a_value_that_fits_is_accepted( string $setting, int|string $value ): void {
		$this->assertSame( $value, SettingValues::check( SettingsFixtures::registry()->setting( $setting ), $value ) );
	}

	/**
	 * Returns values that fit their fields.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: int|string}> The cases.
	 */
	public static function acceptedValues(): array {
		return array(
			'the smallest integer allowed' => array( 'hold_minutes', 1 ),
			'the largest integer allowed'  => array( 'hold_minutes', 1440 ),
			'an allowed value'             => array( 'weight_unit', 'oz' ),
			'a lower-case uuid'            => array( 'default_market', 'aaaaaaaa-0000-4000-8000-000000000001' ),
			'text at the length limit'     => array( 'store_name', str_repeat( 'ü', 20 ) ),
			'empty text'                   => array( 'store_name', '' ),
			'a document text'              => array( 'account', 'acct_1' ),
		);
	}

	/**
	 * Tests that a value that does not fit its field is a programming error, never stored.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedValues
	 *
	 * @param string $setting The setting's name.
	 * @param mixed  $value   The value.
	 * @param string $reason  What the message must say is wrong.
	 */
	public function test_a_value_that_does_not_fit_is_refused( string $setting, mixed $value, string $reason ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The value given for the setting ' . $setting . ' ' . $reason . '.' );

		SettingValues::check( SettingsFixtures::registry()->setting( $setting ), $value );
	}

	/**
	 * Returns values that do not fit their fields.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string}> The cases.
	 */
	public static function refusedValues(): array {
		return array(
			'an integer written as text'      => array( 'hold_minutes', '30', 'is not an integer' ),
			'a float'                         => array( 'hold_minutes', 30.0, 'is not an integer' ),
			'below the minimum'               => array( 'hold_minutes', 0, 'is outside the range the field allows' ),
			'above the maximum'               => array( 'hold_minutes', 1441, 'is outside the range the field allows' ),
			'a number for text'               => array( 'weight_unit', 5, 'is not text' ),
			'a value not allowed'             => array( 'weight_unit', 'KG', 'is not one of the values the field allows' ),
			'an upper-case uuid'              => array( 'default_market', 'AAAAAAAA-0000-4000-8000-000000000001', 'is not a lower-case uuid' ),
			'a uuid with a trailing new line' => array( 'default_market', "aaaaaaaa-0000-4000-8000-000000000001\n", 'is not a lower-case uuid' ),
			'text over the length limit'      => array( 'store_name', str_repeat( 'ü', 21 ), 'is longer than the field allows' ),
			'a boolean'                       => array( 'store_name', true, 'is not text' ),
			'null'                            => array( 'account', null, 'is not text' ),
			'a list'                          => array( 'account', array( 'a' ), 'is not text' ),
		);
	}

	/**
	 * Tests that the setting's own check runs after the field's constraints, and its answer is what is stored.
	 *
	 * @since 0.1.0
	 */
	public function test_the_settings_own_check_decides_last(): void {
		$base_currency = Settings::registry()->setting( InternationalSettings::BASE_CURRENCY );

		$this->assertSame( 'JPY', SettingValues::check( $base_currency, 'JPY' ) );

		try {
			SettingValues::check( $base_currency, 'XYZ' );
			$this->fail( 'An unknown currency was accepted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( SupportError::UnknownCurrency, $refused->errorCode(), 'The check\'s own code must reach the client.' );
			$this->assertSame( array( 'currency' => 'XYZ' ), $refused->context() );
		}

		$this->expectException( \InvalidArgumentException::class );

		// The field refuses a number before the check is asked.
		SettingValues::check( $base_currency, 978 );
	}

	/**
	 * Tests that stored text is read back as the value it stands for.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stored_value_is_read_back(): void {
		$registry = SettingsFixtures::registry();

		$this->assertSame( 30, SettingValues::fromStorage( $registry->setting( 'hold_minutes' ), '30' ) );
		$this->assertSame( 30, SettingValues::fromStorage( $registry->setting( 'hold_minutes' ), 30 ), 'A document holds a JSON integer.' );
		$this->assertSame( 'lb', SettingValues::fromStorage( $registry->setting( 'weight_unit' ), 'lb' ) );
		$this->assertSame( '1440', SettingValues::toOption( 1440 ) );
		$this->assertSame( '-3', SettingValues::toOption( -3 ) );
		$this->assertSame( 'lb', SettingValues::toOption( 'lb' ) );
	}

	/**
	 * Tests that a secret's plain text is judged by its field, and only its sealed shape is stored or read.
	 *
	 * Planted violation: in SettingValues::forStorage(), return `self::check( $setting, $value )` for
	 * every setting. The plain text then passes as a value to store.
	 *
	 * @since 0.1.0
	 */
	public function test_a_secret_is_stored_and_read_only_in_its_sealed_shape(): void {
		$api_key = SettingsFixtures::withSecrets()->setting( 'api_key' );
		$sealed  = 'v1:0123456789abcdef:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:AAAA';

		$this->assertSame( 'sk_test_plain', SettingValues::check( $api_key, 'sk_test_plain' ), 'The plain text is not judged by the field.' );
		$this->assertSame( $sealed, SettingValues::forStorage( $api_key, $sealed ) );
		$this->assertSame( $sealed, SettingValues::fromStorage( $api_key, $sealed ) );

		foreach ( array( 'sk_test_plain', '', 'v1:', 'v1:has space', 'v1:line' . "\n" . 'break', 42 ) as $plain ) {
			try {
				SettingValues::forStorage( $api_key, $plain );
				$this->fail( 'A secret that is not sealed was accepted for storage.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringNotContainsString( 'sk_test_plain', $refused->getMessage(), 'The refusal repeats the secret.' );
			}

			try {
				SettingValues::fromStorage( $api_key, $plain );
				$this->fail( 'A stored secret that is not sealed was read.' );
			} catch ( CodedException $reported ) {
				$this->assertSame( SettingsError::StoredValueInvalid, $reported->errorCode() );
				$this->assertSame( array( 'option' => 'seocart_fixture_gateway_api_key' ), $reported->context() );
			}
		}
	}

	/**
	 * Tests that a stored value its setting cannot hold is reported with the option's name, and never used.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider unusableStoredValues
	 *
	 * @param string $setting The setting's name.
	 * @param mixed  $stored  The stored value.
	 * @param string $option  The option the report must name.
	 */
	public function test_an_unusable_stored_value_is_reported( string $setting, mixed $stored, string $option ): void {
		$registry = 'base_currency' === $setting ? Settings::registry() : SettingsFixtures::registry();

		try {
			SettingValues::fromStorage( $registry->setting( $setting ), $stored );
			$this->fail( 'An unusable stored value was used.' );
		} catch ( CodedException $reported ) {
			$this->assertSame( SettingsError::StoredValueInvalid, $reported->errorCode() );
			$this->assertSame( array( 'option' => $option ), $reported->context() );
		}
	}

	/**
	 * Returns stored values their settings cannot hold.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string}> The cases.
	 */
	public static function unusableStoredValues(): array {
		return array(
			'text that is not a number'        => array( 'hold_minutes', 'many', 'seocart_fixture_scalars_hold_minutes' ),
			'a number with a leading zero'     => array( 'hold_minutes', '030', 'seocart_fixture_scalars_hold_minutes' ),
			'a number in exponent notation'    => array( 'hold_minutes', '1e3', 'seocart_fixture_scalars_hold_minutes' ),
			'a number too large for PHP'       => array( 'hold_minutes', '99999999999999999999', 'seocart_fixture_scalars_hold_minutes' ),
			'a number out of range'            => array( 'hold_minutes', '0', 'seocart_fixture_scalars_hold_minutes' ),
			'a value no longer allowed'        => array( 'weight_unit', 'stone', 'seocart_fixture_scalars_weight_unit' ),
			'a document value of another type' => array( 'mode', 1, 'seocart_fixture_document' ),
			'a code the check refuses'         => array( 'base_currency', 'XYZ', 'seocart_international_base_currency' ),
		);
	}
}

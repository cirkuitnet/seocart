<?php
/**
 * Tests the settings declarations: the production list, the option names and every refused declaration
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Settings;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\OptionGrantLedger;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\DataRegistry\OptionDefinition;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Settings\InternationalSettings;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsRegistry;
use SEOCart\Platform\Settings\Storage;
use SEOCart\Support\Currency;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\SchemaException;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Support\SettingsFixtures;

/**
 * The registry and the settings it is built from.
 *
 * The production list is pinned by name: the three options the plugin stores so far, with the
 * settings each holds. Every rule the constructors enforce has a case that breaks it.
 *
 * @since 0.1.0
 */
final class SettingsRegistryTest extends TestCase {

	/**
	 * Tests that the production list holds the base currency, the grant record and the data keys, and exposes the base currency only.
	 *
	 * @since 0.1.0
	 */
	public function test_the_production_list_holds_the_base_currency_the_grant_record_and_the_data_keys(): void {
		$registry = Settings::registry();
		$roles    = ( new CapabilityDeclaration() )->roles();
		$options  = array_map( static fn( array $settings ): array => array_map( static fn( Setting $setting ): string => $setting->name(), $settings ), $registry->options() );

		$this->assertSame(
			array(
				'seocart_international_base_currency' => array( InternationalSettings::BASE_CURRENCY ),
				'seocart_capability_grants'           => $roles,
				'seocart_data_keys'                   => array( SecretKeys::ACTIVE, SecretKeys::RETIRING, SecretKeys::CANARY ),
			),
			$options
		);

		$this->assertSame( array( InternationalSettings::BASE_CURRENCY ), array_map( static fn( Setting $setting ): string => $setting->name(), $registry->exposed() ) );
		$this->assertSame( 'USD', $registry->setting( InternationalSettings::BASE_CURRENCY )->field()->defaultValue() );
		$this->assertSame( array( SupportError::UnknownCurrency ), $registry->setting( InternationalSettings::BASE_CURRENCY )->errors() );

		foreach ( $registry->group( OptionGrantLedger::GROUP ) as $setting ) {
			$this->assertSame( Storage::Document, $setting->storage() );
			$this->assertFalse( $setting->isExposed(), 'The grant record is internal.' );
		}

		foreach ( $registry->group( SecretKeys::GROUP ) as $setting ) {
			$this->assertSame( Storage::Document, $setting->storage() );
			$this->assertFalse( $setting->isExposed(), 'The data keys are internal.' );
			$this->assertTrue( $setting->isSecret(), 'A data key slot is not classified secret.' );
		}
	}

	/**
	 * Tests that a secret is text without a default, may be exposed without one, and names its record.
	 *
	 * @since 0.1.0
	 */
	public function test_a_secret_is_text_without_a_default_and_names_its_record(): void {
		$secret = Setting::scalar(
			'gateway',
			self::field(
				'api_key',
				array(
					'privacy'       => Privacy::Secret,
					'default_value' => null,
				)
			),
			true
		);
		$slot   = Settings::registry()->setting( SecretKeys::CANARY );

		$this->assertTrue( $secret->isSecret() );
		$this->assertTrue( $secret->isExposed() );
		$this->assertSame( 'seocart_gateway_api_key/api_key', $secret->recordName() );
		$this->assertSame( 'seocart_data_keys/secrets_canary', $slot->recordName() );
		$this->assertFalse( Setting::scalar( 'gateway', self::field( 'mode' ), true )->isSecret() );
	}

	/**
	 * Tests that a scalar lives in `seocart_{group}_{name}` and a document in `seocart_{group}`.
	 *
	 * @since 0.1.0
	 */
	public function test_option_names_follow_the_naming_rule(): void {
		$registry = SettingsFixtures::registry();

		$this->assertSame( 'seocart_fixture_scalars_hold_minutes', $registry->setting( 'hold_minutes' )->optionName() );
		$this->assertSame( 'seocart_fixture_money_ledger_currency', $registry->setting( 'ledger_currency' )->optionName() );
		$this->assertSame( 'seocart_fixture_document', $registry->setting( 'mode' )->optionName() );
		$this->assertSame( 'seocart_fixture_document', $registry->setting( 'retries' )->optionName() );
		$this->assertSame( array( 'mode', 'account', 'retries' ), array_map( static fn( Setting $setting ): string => $setting->name(), $registry->options()['seocart_fixture_document'] ) );
		$this->assertCount( 6, $registry->options(), 'Five scalars and one document are six options.' );
	}

	/**
	 * Tests that the exposed settings, a group and the whole registry are listed in declaration order.
	 *
	 * @since 0.1.0
	 */
	public function test_the_lookups_answer_in_declaration_order(): void {
		$registry = SettingsFixtures::registry();

		$this->assertSame( array( 'hold_minutes', 'weight_unit', 'default_market', 'store_name', 'ledger_currency' ), array_map( static fn( Setting $setting ): string => $setting->name(), $registry->exposed() ) );
		$this->assertSame( array( 'mode', 'account', 'retries' ), array_map( static fn( Setting $setting ): string => $setting->name(), $registry->group( SettingsFixtures::DOCUMENT ) ) );
		$this->assertCount( 8, $registry->all() );
	}

	/**
	 * Tests that each option is declared for the data registry: the settings module's, never
	 * autoloaded, with its setting's description or its document's purpose, and classified as its
	 * most sensitive setting.
	 *
	 * @since 0.1.0
	 */
	public function test_every_option_is_declared_for_the_data_registry(): void {
		$this->assertSame(
			array(
				'seocart_fixture_scalars_hold_minutes'   => array( 'Settings', 'Minutes a checkout hold lasts.', false, Classification::Financial ),
				'seocart_fixture_scalars_weight_unit'    => array( 'Settings', 'Unit product weights are entered in.', false, Classification::Public ),
				'seocart_fixture_scalars_default_market' => array( 'Settings', 'Market a visitor is placed in before choosing one.', false, Classification::Public ),
				'seocart_fixture_scalars_store_name'     => array( 'Settings', 'Name the store signs its messages with.', false, Classification::Public ),
				'seocart_fixture_money_ledger_currency'  => array( 'Settings', 'Currency of the fixture ledger.', false, Classification::Public ),
				'seocart_fixture_document'               => array( 'Settings', SettingsFixtures::DOCUMENT_PURPOSE, false, Classification::Financial ),
			),
			self::described( SettingsFixtures::registry()->optionDefinitions() ),
			'The document holds one financial setting among public ones, so it is financial.'
		);

		$this->assertSame(
			array(
				'seocart_international_base_currency' => array( 'Settings', 'ISO 4217 code of the currency the store keeps its accounts in, in upper case.', false, Classification::Public ),
				'seocart_capability_grants'           => array( 'Settings', OptionGrantLedger::PURPOSE, false, Classification::Public ),
				'seocart_data_keys'                   => array( 'Settings', SecretKeys::PURPOSE, false, Classification::Secret ),
			),
			self::described( Settings::registry()->optionDefinitions() )
		);
	}

	/**
	 * Tests that the data registry lists every settings option exactly as the settings registry declares it, and no other settings option.
	 *
	 * Planted violation: in OwnedData::registry(), pass `array_slice( Settings::registry()->optionDefinitions(), 1 )`.
	 * The base currency's option is then written but not registered.
	 *
	 * @since 0.1.0
	 */
	public function test_the_data_registry_lists_every_settings_option(): void {
		$listed = array_values( array_filter( OwnedData::registry()->options(), static fn( OptionDefinition $option ): bool => SettingsRegistry::MODULE === $option->module() ) );

		$this->assertNotEmpty( $listed, 'The data registry lists no settings option.' );
		$this->assertEquals( Settings::registry()->optionDefinitions(), $listed );
	}

	/**
	 * Tests that asking for a setting or a group that is not declared is a programming error.
	 *
	 * @since 0.1.0
	 */
	public function test_an_undeclared_name_is_a_programming_error(): void {
		$registry = SettingsFixtures::registry();

		try {
			$registry->setting( 'nope' );
			$this->fail( 'An undeclared setting was returned.' );
		} catch ( \InvalidArgumentException $expected ) {
			$this->assertStringContainsString( 'nope', $expected->getMessage() );
		}

		$this->expectException( \InvalidArgumentException::class );

		$registry->group( 'nope' );
	}

	/**
	 * Tests that each broken declaration is refused, with a message naming what is wrong.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedDeclarations
	 *
	 * @param \Closure $build   Builds the declaration; must throw.
	 * @param string   $message A part of the message the refusal must carry.
	 */
	public function test_a_broken_declaration_is_refused( \Closure $build, string $message ): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( $message );

		$build();
	}

	/**
	 * Returns one broken declaration per rule.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: \Closure, 1: string}> The cases.
	 */
	public static function refusedDeclarations(): array {
		return array(
			'a group that is not snake_case'        => array( static fn() => Setting::scalar( 'Bad-Group', self::field( 'a' ), false ), 'not a lower-case snake_case name' ),
			'the kernel\'s group'                   => array( static fn() => Setting::inDocument( 'boot', self::field( 'a' ), false ), 'or is reserved' ),
			'a required field'                      => array(
				static fn() => Setting::scalar(
					'g',
					self::field(
						'a',
						array(
							'required'      => true,
							'default_value' => null,
						)
					),
					false
				),
				'required or nullable',
			),
			'a nullable field'                      => array( static fn() => Setting::scalar( 'g', self::field( 'a', array( 'nullable' => true ) ), false ), 'required or nullable' ),
			'an exposed setting without a default'  => array( static fn() => Setting::scalar( 'g', self::field( 'a', array( 'default_value' => null ) ), true ), 'exposed but has no default' ),
			'a personal-data setting'               => array( static fn() => Setting::scalar( 'g', self::field( 'a', array( 'privacy' => Privacy::Pii ) ), false ), 'holds personal data' ),
			'a secret with a default'               => array( static fn() => Setting::scalar( 'g', self::field( 'a', array( 'privacy' => Privacy::Secret ) ), false ), 'must be text without a default' ),
			'a secret that is not text'             => array(
				static fn() => Setting::scalar(
					'g',
					self::field(
						'a',
						array(
							'privacy'       => Privacy::Secret,
							'type'          => FieldType::Integer,
							'example'       => 1,
							'default_value' => null,
						)
					),
					false
				),
				'must be text without a default',
			),
			'error codes without a check'           => array( static fn() => Setting::scalar( 'g', self::field( 'a' ), false, null, array( SupportError::UnknownCurrency ) ), 'no check that could raise them' ),
			'an error code twice'                   => array( static fn() => Setting::scalar( 'g', self::field( 'a' ), false, static fn( int|string $value ): int|string => $value, array( SupportError::UnknownCurrency, SupportError::UnknownCurrency ) ), 'distinct cases' ),
			'an option name the table cannot hold'  => array( static fn() => Setting::scalar( str_repeat( 'g', 170 ), self::field( 'a_rather_long_setting_name' ), false ), 'longer than the 191 characters' ),
			'an item that is not a Setting'         => array(
				// @phpstan-ignore argument.type (A stray item is what the registry must refuse.)
				static fn() => new SettingsRegistry( array( self::field( 'a' ) ) ),
				'must be a Setting',
			),
			'two settings with one name'            => array( static fn() => new SettingsRegistry( array( Setting::scalar( 'g', self::field( 'a' ), false ), Setting::scalar( 'h', self::field( 'a' ), false ) ) ), 'Two settings are named a' ),
			'a group mixing options and a document' => array( static fn() => new SettingsRegistry( array( Setting::scalar( 'g', self::field( 'a' ), false ), Setting::inDocument( 'g', self::field( 'b' ), false ) ) ), 'mixes independent options and a document' ),
			'a scalar and a document in one option' => array( static fn() => new SettingsRegistry( array( Setting::scalar( 'g', self::field( 'a' ), false ), Setting::inDocument( 'g_a', self::field( 'b' ), false ) ) ), 'would both be stored in the option seocart_g_a' ),
			'two scalars in one option'             => array( static fn() => new SettingsRegistry( array( Setting::scalar( 'g', self::field( 'a_b' ), false ), Setting::scalar( 'g_a', self::field( 'b' ), false ) ) ), 'would both be stored in the option seocart_g_a_b' ),
			'an exposed document'                   => array( static fn() => new SettingsRegistry( array( Setting::inDocument( 'g', self::field( 'a' ), true ) ) ), 'in a document and exposed' ),
			'a document without a purpose'          => array( static fn() => new SettingsRegistry( array( Setting::inDocument( 'g', self::field( 'a' ), false ) ) ), 'The document g needs a purpose' ),
			'a purpose on two lines'                => array( static fn() => new SettingsRegistry( array( Setting::inDocument( 'g', self::field( 'a' ), false ) ), array( 'g' => "What it holds.\nAnd more." ) ), 'The document g needs a purpose' ),
			'a purpose for independent options'     => array( static fn() => new SettingsRegistry( array( Setting::scalar( 'g', self::field( 'a' ), false ) ), array( 'g' => 'What it holds.' ) ), 'which is not a document of the registry' ),
			'a purpose for a group with no setting' => array(
				static fn() => new SettingsRegistry(
					array( Setting::inDocument( 'g', self::field( 'a' ), false ) ),
					array(
						'g' => 'What it holds.',
						'h' => 'What it holds.',
					)
				),
				'A purpose is declared for h',
			),
			'a default its own check refuses'       => array(
				static fn() => Setting::scalar( 'g', self::field( 'a', array( 'default_value' => 'eur' ) ), false, static fn( int|string $code ): string => Currency::of( (string) $code )->code(), array( SupportError::UnknownCurrency ) ),
				'The default of the setting a is not a value the setting accepts',
			),
			'a default its field refuses'           => array(
				static fn() => Setting::scalar(
					'g',
					self::field(
						'a',
						array(
							'default_value' => 'stone',
							'allowed'       => array( 'kg', 'lb' ),
						)
					),
					false
				),
				'The default of the setting a is not a value the setting accepts',
			),
			'a default its check would change'      => array(
				static fn() => Setting::scalar( 'g', self::field( 'a', array( 'default_value' => 'eur' ) ), false, static fn( int|string $code ): string => strtoupper( (string) $code ) ),
				'The default of the setting a is not a value the setting accepts',
			),
		);
	}

	/**
	 * Returns what option definitions say, keyed by option name.
	 *
	 * @since 0.1.0
	 *
	 * @param OptionDefinition[] $definitions The definitions.
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: Classification}> Module, purpose, autoload and classification.
	 */
	private static function described( array $definitions ): array {
		$described = array();

		foreach ( $definitions as $definition ) {
			$described[ $definition->name() ] = array( $definition->module(), $definition->purpose(), $definition->autoloads(), $definition->classification() );
		}

		return $described;
	}

	/**
	 * Declares a text field with a default, with some parts replaced.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name  The field name.
	 * @param array<string, mixed> $parts Optional. Named constructor arguments to replace. Default none.
	 * @return FieldSpec The field.
	 */
	private static function field( string $name, array $parts = array() ): FieldSpec {
		return new FieldSpec(
			...array_merge(
				array(
					'name'          => $name,
					'type'          => FieldType::String,
					'description'   => 'A fixture setting.',
					'label'         => static fn(): string => 'Fixture',
					'example'       => 'x',
					'default_value' => 'x',
				),
				$parts
			)
		);
	}
}

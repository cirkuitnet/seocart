<?php
/**
 * SettingsFixtures: a settings registry that uses every field type and both storage shapes, for tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsRegistry;
use SEOCart\Support\Currency;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\SupportError;

/**
 * Declares settings shaped like the ones the store will hold, which the production list does not have yet.
 *
 * A group of exposed scalars — a bounded integer, a text limited to a set of values, a uuid, a
 * text with a length limit, and a currency with its own check — and an internal document of three
 * settings, one of them without a default. Every option name starts with `seocart_fixture_`, so a
 * test that commits can remove them all. Their texts are plain strings rather than gettext calls,
 * so no fixture text can reach the plugin's translation template.
 *
 * withSecrets() adds secrets shaped like a gateway's: two exposed scalar secrets, and an internal
 * document that holds a secret beside an ordinary setting; and the data keys document the vault
 * needs, whose option, `seocart_data_keys`, is the plugin's own and does not start with the prefix.
 *
 * @since 0.1.0
 */
final class SettingsFixtures {

	/**
	 * The group of the exposed scalars.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SCALARS = 'fixture_scalars';

	/**
	 * The group of the currency, a scalar of a group of its own.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MONEY = 'fixture_money';

	/**
	 * The group of the internal document.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DOCUMENT = 'fixture_document';

	/**
	 * The group of the exposed secrets.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SECRETS = 'fixture_gateway';

	/**
	 * The group of the internal document that holds a secret.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VAULT = 'fixture_vault';

	/**
	 * What the internal document that holds a secret holds.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VAULT_PURPOSE = 'The webhook endpoint of the fixture gateway and the secret it signs with.';

	/**
	 * The start of every fixture option name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION_PREFIX = 'seocart_fixture_';

	/**
	 * What the internal document holds.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DOCUMENT_PURPOSE = 'The configuration of the fixture gateway.';

	/**
	 * The default of the uuid setting.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DEFAULT_MARKET = '0b6f2c52-7f0a-4c1e-9a55-3f0a4e0f6d21';

	/**
	 * Returns the registry of every fixture setting.
	 *
	 * @since 0.1.0
	 *
	 * @return SettingsRegistry The registry.
	 */
	public static function registry(): SettingsRegistry {
		return new SettingsRegistry( array_merge( self::scalars(), self::document() ), array( self::DOCUMENT => self::DOCUMENT_PURPOSE ) );
	}

	/**
	 * Returns a registry of the exposed scalars, the secrets and the data keys document.
	 *
	 * @since 0.1.0
	 *
	 * @return SettingsRegistry The registry.
	 */
	public static function withSecrets(): SettingsRegistry {
		return new SettingsRegistry(
			array_merge( self::scalars(), self::secrets(), SecretKeys::settings() ),
			array(
				self::VAULT       => self::VAULT_PURPOSE,
				SecretKeys::GROUP => SecretKeys::PURPOSE,
			)
		);
	}

	/**
	 * Declares the secrets: two exposed scalars, and a document that holds one beside an ordinary setting.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Setting> The settings.
	 */
	public static function secrets(): array {
		return array(
			Setting::scalar(
				group: self::SECRETS,
				field: new FieldSpec(
					name: 'api_key',
					type: FieldType::String,
					description: 'Key the fixture gateway signs its calls with.',
					label: static fn(): string => 'API key',
					example: 'sk_test_example',
					max_length: 60,
					privacy: Privacy::Secret
				),
				exposed: true
			),
			Setting::scalar(
				group: self::SECRETS,
				field: new FieldSpec(
					name: 'webhook_secret',
					type: FieldType::String,
					description: 'Secret the fixture gateway signs its webhooks with.',
					label: static fn(): string => 'Webhook secret',
					example: 'whsec_example',
					max_length: 60,
					privacy: Privacy::Secret
				),
				exposed: true
			),
			Setting::inDocument(
				group: self::VAULT,
				field: new FieldSpec(
					name: 'signing_secret',
					type: FieldType::String,
					description: 'Secret the fixture endpoint verifies deliveries with.',
					label: static fn(): string => 'Signing secret',
					example: 'sign_example',
					max_length: 80,
					privacy: Privacy::Secret
				),
				exposed: false
			),
			Setting::inDocument(
				group: self::VAULT,
				field: new FieldSpec(
					name: 'endpoint',
					type: FieldType::String,
					description: 'Address the fixture gateway delivers webhooks to.',
					label: static fn(): string => 'Endpoint',
					example: 'https://shop.example/hooks',
					default_value: 'https://shop.example/hooks',
					max_length: 200
				),
				exposed: false
			),
		);
	}

	/**
	 * Declares the exposed scalars.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Setting> The settings.
	 */
	public static function scalars(): array {
		return array(
			Setting::scalar(
				group: self::SCALARS,
				field: new FieldSpec(
					name: 'hold_minutes',
					type: FieldType::Integer,
					description: 'Minutes a checkout hold lasts.',
					label: static fn(): string => 'Hold minutes',
					example: 30,
					default_value: 15,
					minimum: 1,
					maximum: 1440,
					privacy: Privacy::Financial
				),
				exposed: true
			),
			Setting::scalar(
				group: self::SCALARS,
				field: new FieldSpec(
					name: 'weight_unit',
					type: FieldType::String,
					description: 'Unit product weights are entered in.',
					label: static fn(): string => 'Weight unit',
					example: 'lb',
					default_value: 'kg',
					allowed: array( 'kg', 'g', 'lb', 'oz' )
				),
				exposed: true
			),
			Setting::scalar(
				group: self::SCALARS,
				field: new FieldSpec(
					name: 'default_market',
					type: FieldType::Uuid,
					description: 'Market a visitor is placed in before choosing one.',
					label: static fn(): string => 'Default market',
					example: 'aaaaaaaa-0000-4000-8000-000000000001',
					default_value: self::DEFAULT_MARKET
				),
				exposed: true
			),
			Setting::scalar(
				group: self::SCALARS,
				field: new FieldSpec(
					name: 'store_name',
					type: FieldType::String,
					description: 'Name the store signs its messages with.',
					label: static fn(): string => 'Store name',
					example: 'Corner Shop',
					default_value: 'Shop',
					max_length: 20,
					translatable: true
				),
				exposed: true
			),
			Setting::scalar(
				group: self::MONEY,
				field: new FieldSpec(
					name: 'ledger_currency',
					type: FieldType::String,
					description: 'Currency of the fixture ledger.',
					label: static fn(): string => 'Ledger currency',
					example: 'EUR',
					default_value: 'USD'
				),
				exposed: true,
				check: static fn( int|string $code ): string => Currency::of( (string) $code )->code(),
				errors: array( SupportError::UnknownCurrency )
			),
		);
	}

	/**
	 * Declares the internal document.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Setting> The settings.
	 */
	public static function document(): array {
		return array(
			Setting::inDocument(
				group: self::DOCUMENT,
				field: new FieldSpec(
					name: 'mode',
					type: FieldType::String,
					description: 'Whether the fixture gateway is live.',
					label: static fn(): string => 'Mode',
					example: 'live',
					default_value: 'test',
					allowed: array( 'test', 'live' )
				),
				exposed: false
			),
			Setting::inDocument(
				group: self::DOCUMENT,
				field: new FieldSpec(
					name: 'account',
					type: FieldType::String,
					description: 'Account the fixture gateway charges into.',
					label: static fn(): string => 'Account',
					example: 'acct_1',
					max_length: 40
				),
				exposed: false
			),
			Setting::inDocument(
				group: self::DOCUMENT,
				field: new FieldSpec(
					name: 'retries',
					type: FieldType::Integer,
					description: 'Times the fixture gateway retries a call.',
					label: static fn(): string => 'Retries',
					example: 3,
					default_value: 2,
					minimum: 0,
					maximum: 5,
					privacy: Privacy::Financial
				),
				exposed: false
			),
		);
	}
}

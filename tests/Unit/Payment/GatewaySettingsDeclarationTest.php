<?php
/**
 * Tests how a gateway's declared settings are kept: one document per gateway, each setting once per mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\GatewayContext;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\GatewaySettingsDeclaration;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\Storage;
use SEOCart\Support\Currency;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\SchemaException;

/**
 * A gateway's settings are one document, `seocart_gateway_{id}`, never exposed, holding its mode when it has more than one and each declared setting once per mode; the stand-in, with one mode and no settings, has none. The stand-in is registered off production, or where the site says so; contract versions agree by major and minor before 1.0.
 *
 * Planted violation, shown red and removed: in GatewaySettingsDeclaration::forMode(), store the
 * setting under its declared name: the two modes' settings then share one name, which the
 * settings of both modes cannot.
 *
 * @since 0.2.0
 */
final class GatewaySettingsDeclarationTest extends TestCase {

	/**
	 * Tests the settings of a gateway with two modes: its mode, test by default, and each setting once per mode, in one document.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_is_kept_as_one_document_with_each_setting_per_mode(): void {
		$settings = GatewaySettingsDeclaration::of( self::descriptor( array( Mode::Test, Mode::Live ) ) );
		$names    = array_map( static fn( Setting $setting ): string => $setting->name(), $settings );

		$this->assertSame( array( 'example_mode', 'example_test_secret_key', 'example_test_account_country', 'example_live_secret_key', 'example_live_account_country' ), $names );

		foreach ( $settings as $setting ) {
			$this->assertSame( array( 'gateway_example', 'seocart_gateway_example', Storage::Document, false ), array( $setting->group(), $setting->optionName(), $setting->storage(), $setting->isExposed() ), $setting->name() );
		}

		$this->assertSame( array( 'test', array( 'test', 'live' ) ), array( $settings[0]->field()->defaultValue(), $settings[0]->field()->allowedValues() ), 'A store goes live only when told to.' );
		$this->assertTrue( $settings[1]->isSecret(), 'A declared credential stays one.' );
		$this->assertSame( 'seocart_gateway_example/example_live_secret_key', $settings[3]->recordName(), 'A sealed credential is bound to its mode\'s record.' );
		$this->assertSame( 'example_live_secret_key', GatewaySettingsDeclaration::storedName( 'example', Mode::Live, 'secret_key' ) );
	}

	/**
	 * Tests that a gateway with one mode has no mode setting, and one with no settings either has no document at all.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_with_one_mode_has_no_mode_setting_and_the_stub_no_document(): void {
		$this->assertSame( array( 'example_test_secret_key', 'example_test_account_country' ), array_map( static fn( Setting $setting ): string => $setting->name(), GatewaySettingsDeclaration::of( self::descriptor( array( Mode::Test ) ) ) ) );
		$this->assertNull( GatewaySettingsDeclaration::modeSetting( self::descriptor( array( Mode::Live ) ) ) );
		$this->assertSame( array(), GatewaySettingsDeclaration::of( ( new StubGateway() )->describe() ), 'The stand-in has nothing to keep.' );
	}

	/**
	 * Tests that a declared setting that cannot be a setting is refused as one.
	 *
	 * @since 0.2.0
	 */
	public function test_a_setting_that_cannot_be_kept_is_refused(): void {
		$required = new FieldSpec( name: 'secret_key', type: FieldType::String, description: 'A key.', label: static fn(): string => 'Key', example: 'x', required: true, privacy: Privacy::Secret );

		$this->expectException( SchemaException::class );

		GatewaySettingsDeclaration::of( self::descriptor( array( Mode::Test ), array( $required ) ) );
	}

	/**
	 * Tests the data registry's declaration of the documents: one family, secret, never autoloaded, owned by the settings module.
	 *
	 * @since 0.2.0
	 */
	public function test_the_documents_are_one_secret_option_family(): void {
		$family = GatewaySettingsDeclaration::optionFamily();

		$this->assertSame( array( 'seocart_gateway_%', 'Settings', Classification::Secret, false, true ), array( $family->name(), $family->module(), $family->classification(), $family->autoloads(), $family->isFamily() ) );
		$this->assertTrue( $family->covers( 'seocart_gateway_stripe' ) );
		$this->assertFalse( $family->covers( 'seocart_gateway_' ), 'The prefix alone is no gateway\'s document.' );
		$this->assertFalse( $family->covers( 'seocart_international_base_currency' ) );
	}

	/**
	 * Tests where the stand-in is registered: anywhere but a production site, and on one only where SEOCART_STUB_GATEWAY says so.
	 *
	 * Planted violation, shown red and removed: in Gateways::stubAllowed(), return true: the
	 * production site without the constant then gets the stand-in.
	 *
	 * @since 0.2.0
	 */
	public function test_the_stand_in_is_registered_off_production_or_when_declared(): void {
		$this->assertFalse( Gateways::stubAllowed( 'production', false ), 'A live store never offers a gateway that approves every payment.' );
		$this->assertTrue( Gateways::stubAllowed( 'production', true ) );
		$this->assertFalse( Gateways::stubAllowed( 'production', '1' ), 'Only the constant defined true allows it.' );

		foreach ( array( 'local', 'development', 'staging' ) as $type ) {
			$this->assertTrue( Gateways::stubAllowed( $type, false ), $type );
		}
	}

	/**
	 * Tests which contract versions a gateway may be written against.
	 *
	 * @since 0.2.0
	 */
	public function test_contract_versions_agree_by_major_and_minor_before_one(): void {
		$gateways = new Gateways(
			null,
			static function (): GatewayContext {
				throw new \LogicException( 'No context is asked for.' );
			},
			static function (): void {
			},
			static function (): void {
			}
		);

		$this->assertSame( '0.2.0', PaymentGateway::CONTRACT_VERSION );
		$this->assertTrue( $gateways->supportsContract( '0.2.0' ) );
		$this->assertTrue( $gateways->supportsContract( '0.2' ) );
		$this->assertTrue( $gateways->supportsContract( '0.2.7' ), 'A patch never breaks a gateway.' );
		$this->assertFalse( $gateways->supportsContract( '0.1.0' ), 'Before 1.0 a minor version is a major one.' );
		$this->assertFalse( $gateways->supportsContract( '0.3.0' ) );
		$this->assertFalse( $gateways->supportsContract( '9.0.0' ) );
		$this->assertFalse( $gateways->supportsContract( 'latest' ) );
	}

	/**
	 * Builds a descriptor of the gateway `example`.
	 *
	 * @since 0.2.0
	 *
	 * @param array      $modes    The modes.
	 * @param array|null $settings Optional. The settings. Default a secret key and an account country.
	 * @return GatewayDescriptor The descriptor.
	 *
	 * @phpstan-param list<Mode>           $modes
	 * @phpstan-param list<FieldSpec>|null $settings
	 */
	private static function descriptor( array $modes, ?array $settings = null ): GatewayDescriptor {
		$settings ??= array(
			new FieldSpec( name: 'secret_key', type: FieldType::String, description: 'The secret API key.', label: static fn(): string => 'Secret key', example: 'sk_test_x', privacy: Privacy::Secret ),
			new FieldSpec( name: 'account_country', type: FieldType::String, description: 'The account\'s country.', label: static fn(): string => 'Country', example: 'US', max_length: 2 ),
		);

		return new GatewayDescriptor( 'example', static fn(): string => 'Example', GatewayDescriptor::TYPE_PAYMENTS, PaymentGateway::CONTRACT_VERSION, $modes, $settings, new CapabilityMatrix( array( new MatrixRow( Currency::of( 'USD' ), 'US', Operations::REQUIRED ) ) ), new IdempotencyProfile( 86400, true, 60 ), array() );
	}
}

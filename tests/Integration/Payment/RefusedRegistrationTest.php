<?php
/**
 * Tests that a refused gateway registration is kept for the request with its reason, and that every registration carries the plugin it was loaded from
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\GatewayContext;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\RefusedRegistration;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Doubles\ProvisioningGateway;
use SEOCart\Tests\Support\Doubles\UndescribableGateway;
use SEOCart\Tests\Support\Payment\TestGateways;

/**
 * Each refusal (a registration after the action, a descriptor that cannot be built, a contract the plugin does not support, an id registered already, a gateway that sets up its own webhook endpoints without the credential for their signing secret, a listener that throws) is kept with its reason and the slug of the plugin its class was loaded from; an accepted gateway carries its plugin's slug; a class outside the plugins directory, such as the stand-in here, has none. A gateway that declares `webhook_endpoint`, the name the plugin keeps a webhook endpoint's id under, is refused: its descriptor cannot be built.
 *
 * The test doubles' directory is linked into the plugins directory as `seocart-fixture-gateway`,
 * as WordPress records a plugin directory linked from elsewhere, so the doubles' classes are
 * loaded from that plugin.
 *
 * Planted violations, each shown red and removed:
 * - in Gateways::refuse(), log the refusal and keep nothing: refused() is then empty;
 * - in GatewayDescriptor::checkSettings(), drop `webhook_endpoint` from the names the plugin
 *   keeps: `manual`, which declares it, is registered.
 *
 * @since 0.2.0
 */
final class RefusedRegistrationTest extends DatabaseTestCase {

	/**
	 * The plugin the test doubles' directory stands for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PLUGIN = 'seocart-fixture-gateway';

	/**
	 * The linked plugin directories as they were before the test.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private array $pathsBefore = array();

	/**
	 * Links the test doubles' directory into the plugins directory.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		global $wp_plugin_paths;

		parent::set_up();

		$this->pathsBefore = (array) $wp_plugin_paths;
		$doubles           = dirname( (string) ( new \ReflectionClass( DeclaredGateway::class ) )->getFileName() );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test links a directory as WordPress does for a plugin linked from elsewhere, and puts the list back.
		$wp_plugin_paths[ wp_normalize_path( WP_PLUGIN_DIR . '/' . self::PLUGIN ) ] = wp_normalize_path( (string) realpath( $doubles ) );
	}

	/**
	 * Puts the linked plugin directories back.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		global $wp_plugin_paths;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Puts back what set_up() changed.
		$wp_plugin_paths = $this->pathsBefore;

		parent::tear_down();
	}

	/**
	 * Tests the refusals kept with their reasons and plugins, the accepted gateway's plugin, and none for a class outside the plugins directory.
	 *
	 * @since 0.2.0
	 */
	public function test_each_refusal_is_kept_with_its_reason_and_plugin(): void {
		$late     = null;
		$gateways = $this->gateways();

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ) use ( &$late ): void {
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'fixture', array( Mode::Test ), array() ) ) );
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'fixture', array( Mode::Test ), array() ) ) );
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'future', array( Mode::Test ), array(), null, null, '9.0.0' ) ) );
				$registry->register( new UndescribableGateway() );
				$registry->register( new ProvisioningGateway( DeclaredGateway::descriptor( 'no_secret', array( Mode::Test ), array() ) ) );
				$late = $registry;
			}
		);
		add_action(
			GatewayRegistry::ACTION,
			static function (): void {
				throw new \RuntimeException( 'A broken gateway plugin; card 4111 1111 1111 1111.' );
			},
			20
		);

		$this->assertSame( array( StubGateway::ID, 'fixture' ), $gateways->ids() );
		$this->assertNotNull( $late );
		$late->register( new DeclaredGateway( DeclaredGateway::descriptor( 'latecomer', array( Mode::Test ), array() ) ) );

		$this->assertSame( self::PLUGIN, $gateways->pluginOf( 'fixture' ), 'An accepted gateway carries the plugin its class was loaded from.' );
		$this->assertNull( $gateways->pluginOf( StubGateway::ID ), 'A class outside the plugins directory comes from no plugin.' );

		$refused = array_map( static fn( RefusedRegistration $refusal ): array => array( $refusal->gateway, $refusal->plugin, $refusal->reason ), $gateways->refused() );

		$this->assertSame(
			array(
				array( 'fixture', self::PLUGIN, 'duplicate' ),
				array( 'future', self::PLUGIN, 'incompatible' ),
				array( UndescribableGateway::class, self::PLUGIN, 'invalid_descriptor' ),
				array( 'no_secret', self::PLUGIN, 'webhooks_undeclared' ),
				array( \RuntimeException::class, null, 'registration_failed' ),
				array( DeclaredGateway::class, self::PLUGIN, 'late' ),
			),
			$refused
		);
		$this->assertStringContainsString( '9.0.0', $gateways->refused()[1]->detail, 'The incompatible contract is named.' );
		$this->assertStringContainsString( PaymentGateway::CONTRACT_VERSION, $gateways->refused()[1]->detail );
		$this->assertStringNotContainsString( '4111', (string) wp_json_encode( array_map( static fn( RefusedRegistration $refusal ): array => $refusal->toArray(), $gateways->refused() ) ), 'No card number is kept.' );
		$this->assertSame( array( 'gateway', 'plugin', 'reason', 'detail' ), array_keys( $gateways->refused()[0]->toArray() ) );
	}

	/**
	 * Tests that a gateway declaring `webhook_endpoint` is refused when it registers, with a reason that names the setting: `manual` sets up no endpoint, and declares it beside `secret_key` and `webhook_secret`, where a signing secret entered by hand would clear it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_declaring_the_webhook_endpoint_setting_is_refused(): void {
		$manual   = static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'manual', array( Mode::Test ), array( self::setting( 'secret_key' ), self::setting( GatewayDescriptor::WEBHOOK_SECRET ), self::setting( GatewayDescriptor::WEBHOOK_ENDPOINT, Privacy::Public ) ) );
		$gateways = $this->gateways();

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ) use ( $manual ): void {
				$registry->register( new UndescribableGateway( $manual ) );
			}
		);

		$this->assertSame( array( StubGateway::ID ), $gateways->ids(), 'The gateway declaring webhook_endpoint was registered.' );
		$this->assertSame( array( array( UndescribableGateway::class, self::PLUGIN, 'invalid_descriptor' ) ), array_map( static fn( RefusedRegistration $refusal ): array => array( $refusal->gateway, $refusal->plugin, $refusal->reason ), $gateways->refused() ) );

		try {
			$manual();
			$this->fail( 'A descriptor declaring webhook_endpoint was built.' );
		} catch ( \InvalidArgumentException $refusal ) {
			$this->assertSame( 'A gateway declares each setting once, and none named mode or webhook_endpoint, which the plugin keeps itself: webhook_endpoint.', $refusal->getMessage(), 'The developer is told which name is the plugin\'s.' );
		}
	}

	/**
	 * Returns the registry, with the stand-in and no gateway context: no gateway here is called.
	 *
	 * @since 0.2.0
	 *
	 * @return Gateways The registry, not yet built.
	 */
	private function gateways(): Gateways {
		return new Gateways(
			new StubGateway(),
			static function ( string $gatewayId ): GatewayContext {
				throw new \LogicException( sprintf( 'The gateway %s is never called here, and needs no context.', $gatewayId ) );
			},
			$this->reporter(),
			static function (): void {
			},
			TestGateways::switches()
		);
	}

	/**
	 * Declares a setting: a credential unless told otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param string  $name    Its name.
	 * @param Privacy $privacy Optional. Its privacy class. Default Privacy::Secret, a credential.
	 * @return FieldSpec The setting.
	 */
	private static function setting( string $name, Privacy $privacy = Privacy::Secret ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::String, description: 'A setting of the test gateway.', label: static fn(): string => 'Setting', example: 'x', privacy: $privacy );
	}
}

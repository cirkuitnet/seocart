<?php
/**
 * Tests what a refused or missing gateway registration tells the plugin's developer
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\CredentialUnavailable;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\GatewayContext;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Doubles\UndescribableGateway;
use SEOCart\Tests\Support\Payment\TestGateways;

/**
 * A descriptor SEOCart refuses is refused with SEOCart's own reason, which field and which rule, and a gateway that is not registered is refused with the cause, so a developer whose test run cannot write the log still reads what happened.
 *
 * @since 0.2.0
 */
final class RegistrationRefusalTest extends DatabaseTestCase {

	/**
	 * Tests that a descriptor SEOCart refuses carries SEOCart's own validation text.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_descriptor_carries_seocarts_own_reason(): void {
		$gateways = $this->registering(
			new UndescribableGateway(),
			new UndescribableGateway( static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'carded', array( Mode::Test ), array( self::setting( 'card_number' ) ) ) )
		);

		$this->assertSame( array( StubGateway::ID ), $gateways->ids() );

		$details = array_map( static fn( $refused ): string => $refused->detail, $gateways->refused() );

		$this->assertStringContainsString( 'A gateway\'s id is lower-case snake_case of at most 32 characters, and the id given is not.', $details[0] );
		$this->assertStringContainsString( 'The setting card_number reads as card data, which never reaches the plugin.', $details[1] );
		$this->assertSame( $details, array_column( array_column( $this->reports, 'context' ), 'detail' ), 'The log line carries the same detail.' );
	}

	/**
	 * Tests that a refused descriptor's detail names the field and the rule, never the value refused, a key-shaped one included.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_descriptor_never_quotes_the_value_it_refuses(): void {
		$key      = 'sk-live-51hqlyjwdarjtt1zdp7dc';
		$value    = 'sk_live_51hqlyjwdarjtt1zdp7dc';
		$row      = new MatrixRow( Currency::of( 'USD' ), 'GB', Operations::REQUIRED );
		$gateways = $this->registering(
			new UndescribableGateway( static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'Sk_Live_51HqLyjWDarjtT1zdp7dc' ) ),
			new UndescribableGateway( static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'hosted', array( Mode::Test ), array(), null, null, PaymentGateway::CONTRACT_VERSION, array( DeclaredGateway::host( $key ), DeclaredGateway::host( $key ) ) ) ),
			new UndescribableGateway( static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'celled', array( Mode::Test ), array(), new CapabilityMatrix( array( $row, $row ) ) ) ),
			new UndescribableGateway( static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'countried', array( Mode::Test ), array(), new CapabilityMatrix( array( new MatrixRow( Currency::of( 'USD' ), 'sk_live_51hq', Operations::REQUIRED ) ) ) ) ),
			new UndescribableGateway( static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'settinged', array( Mode::Test ), array( self::setting( 'api_key', $value ), self::setting( 'api_key', $value ) ) ) ),
			new UndescribableGateway( static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'endpointed', array( Mode::Test ), array(), null, null, PaymentGateway::CONTRACT_VERSION, array( new OutboundHost( $key, 'Example', 'A provider a test gateway calls.', 'http://api.example.com/v1', 'The amount of the payment.', 'When the test asks the gateway.', 'https://example.com/terms', 'https://example.com/privacy' ) ) ) )
		);

		$details = array_map( static fn( $refused ): string => $refused->detail, $gateways->refused() );
		$written = implode( ' ', $details ) . ' ' . (string) wp_json_encode( $this->reports );

		$this->assertStringContainsString( 'A gateway\'s id is lower-case snake_case of at most 32 characters, and the id given is not.', $details[0] );
		$this->assertStringContainsString( 'A gateway declares each host once, and two of its hosts have the same id.', $details[1] );
		$this->assertStringContainsString( 'A capability matrix declares each cell once, and two of its rows have the same currency and account country.', $details[2] );
		$this->assertStringContainsString( 'A capability row\'s account country is two upper-case letters or *, and the country given is not.', $details[3] );
		$this->assertStringContainsString( 'A gateway declares each setting once, and none named mode or webhook_endpoint, which the plugin keeps itself: api_key.', $details[4], 'The refusal names the setting.' );
		$this->assertStringContainsString( 'An outbound host\'s "endpoint" must be an https:// URL on one lower-case host, with no port, credentials, query or fragment.', $details[5] );

		foreach ( array( 'Sk_Live_51HqLyjWDarjtT1zdp7dc', $key, 'USD/GB', 'sk_live_51hq', $value ) as $refused ) {
			$this->assertStringNotContainsString( $refused, $written, 'A refusal never quotes the value it refuses.' );
		}
	}

	/**
	 * Tests that a gateway's own exception is named by its class only, never by its message.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateways_own_exception_is_named_by_its_class_only(): void {
		$gateways = $this->registering(
			new UndescribableGateway(
				static function (): GatewayDescriptor {
					throw new \RuntimeException( 'sk_live_conformance_secret_value' );
				}
			)
		);

		$detail = $gateways->refused()[0]->detail;

		$this->assertStringContainsString( \RuntimeException::class, $detail );
		$this->assertStringNotContainsString( 'sk_live_conformance_secret_value', $detail . (string) wp_json_encode( $this->reports ) );
	}

	/**
	 * Tests that an exception raised in SEOCart's own source, outside the classes that check a declaration, is named by its class only, its message withheld.
	 *
	 * @since 0.2.0
	 */
	public function test_an_exception_of_seocart_outside_the_declaration_classes_is_named_by_its_class_only(): void {
		$gateways = $this->registering(
			new UndescribableGateway(
				static function (): GatewayDescriptor {
					// The contract's own check, whose message quotes the reason it was given.
					throw new CredentialUnavailable( 'sk_live_conformance_secret_value', 'api_key' );
				}
			)
		);

		$detail = $gateways->refused()[0]->detail;

		$this->assertSame( 'Its descriptor could not be built: describe(), or the settings it declares, threw InvalidArgumentException.', $detail );
		$this->assertStringNotContainsString( 'sk_live_conformance_secret_value', $detail . (string) wp_json_encode( $this->reports ) );
	}

	/**
	 * Tests that asking for a gateway whose registration was refused raises the same error, caused by the refusal.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_gateway_is_unavailable_because_of_its_refusal(): void {
		$gateways = $this->registering( new UndescribableGateway( static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'carded', array( Mode::Test ), array( self::setting( 'card_number' ) ) ) ) );
		$refused  = self::refusalOf( static fn() => $gateways->get( 'carded', Mode::Test ) );

		$this->assertSame( PaymentError::GatewayUnavailable, $refused->errorCode() );
		$this->assertSame(
			array(
				'gateway_id' => 'carded',
				'reason'     => 'not_registered',
			),
			$refused->context(),
			'The error and its details are what they always were.'
		);
		$cause = $refused->getPrevious();
		$this->assertInstanceOf( \LogicException::class, $cause );
		$this->assertStringContainsString( 'SEOCart refused these registrations in this request:', $cause->getMessage() );
		$this->assertStringContainsString( 'invalid_descriptor', $cause->getMessage() );
		$this->assertStringContainsString( 'The setting card_number reads as card data', $cause->getMessage() );
	}

	/**
	 * Tests that a gateway nothing registered is unavailable because nothing listens to the action, or because no listener registered it.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unregistered_gateway_says_whether_anything_listened(): void {
		remove_all_actions( GatewayRegistry::ACTION );

		$silent = self::refusalOf( fn() => $this->gateways()->get( 'example', Mode::Test ) );
		$this->assertStringContainsString( 'nothing listens to ' . GatewayRegistry::ACTION, (string) $silent->getPrevious()?->getMessage() );

		$listened = self::refusalOf( fn() => $this->registering( new DeclaredGateway( DeclaredGateway::descriptor( 'other', array( Mode::Test ), array() ) ) )->get( 'example', Mode::Test ) );
		$this->assertStringContainsString( 'the listeners of ' . GatewayRegistry::ACTION . ' ran, none registered that id', (string) $listened->getPrevious()?->getMessage() );
	}

	/**
	 * Builds a registry whose action registers the given gateways.
	 *
	 * @since 0.2.0
	 *
	 * @param \SEOCart\Contracts\Payment\PaymentGateway ...$registered The gateways.
	 * @return Gateways The registry.
	 */
	private function registering( \SEOCart\Contracts\Payment\PaymentGateway ...$registered ): Gateways {
		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ) use ( $registered ): void {
				foreach ( $registered as $gateway ) {
					$registry->register( $gateway );
				}
			}
		);

		return $this->gateways();
	}

	/**
	 * Builds a registry with the stand-in and the test's reporter.
	 *
	 * @since 0.2.0
	 *
	 * @return Gateways The registry.
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
	 * Returns the coded refusal a piece of work raises.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $work The work.
	 * @return CodedException The refusal.
	 */
	private static function refusalOf( \Closure $work ): CodedException {
		try {
			$work();
		} catch ( CodedException $refused ) {
			return $refused;
		}

		self::fail( 'The work was not refused.' );
	}

	/**
	 * Declares a secret setting.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name    The setting's name.
	 * @param string $example Optional. Its example value. Default `x`.
	 * @return FieldSpec The setting.
	 */
	private static function setting( string $name, string $example = 'x' ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::String, description: 'A setting of the test gateway.', label: static fn(): string => 'Setting', example: $example, privacy: Privacy::Secret );
	}
}

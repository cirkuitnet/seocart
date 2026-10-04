<?php
/**
 * Tests what a gateway may declare about itself
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

/**
 * A descriptor refuses an id a settings group and an intent cannot hold, modes that are none or repeat, settings named `mode` or after card data, a contract version that is not one, and a host declared twice; an idempotency profile counts seconds from 0.
 *
 * Planted violation, shown red and removed: in GatewayDescriptor's CARD_NAME, drop `card`: a
 * setting named `card_number` is then accepted.
 *
 * @since 0.2.0
 */
final class GatewayDescriptorTest extends TestCase {

	/**
	 * Tests a well-formed descriptor: its label is translated only when asked for, and its secrets are the settings of the class Privacy::Secret.
	 *
	 * @since 0.2.0
	 */
	public function test_a_well_formed_descriptor_lists_its_secrets(): void {
		$asked      = 0;
		$descriptor = self::descriptor(
			'stripe',
			array( Mode::Test, Mode::Live ),
			array( self::field( 'secret_key', Privacy::Secret ), self::field( 'account_country' ) ),
			static function () use ( &$asked ): string {
				++$asked;

				return 'Stripe';
			}
		);

		$this->assertSame( 0, $asked, 'Describing a gateway translates nothing.' );
		$this->assertSame( 'Stripe', $descriptor->label() );
		$this->assertSame( array( 'secret_key' ), array_map( static fn( FieldSpec $field ): string => $field->name(), $descriptor->secrets() ) );
	}

	/**
	 * Tests the ids a gateway may not take.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider refusedIds
	 *
	 * @param string $id The id.
	 */
	public function test_an_id_a_settings_group_cannot_hold_is_refused( string $id ): void {
		$this->expectException( \InvalidArgumentException::class );

		self::descriptor( $id );
	}

	/**
	 * Ids that are not lower-case snake_case of at most 32 characters.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string}> The ids.
	 */
	public static function refusedIds(): array {
		return array(
			'a hyphen'            => array( 'authorize-net' ),
			'an upper-case one'   => array( 'Stripe' ),
			'a double underscore' => array( 'pay__pal' ),
			'a trailing one'      => array( 'stripe_' ),
			'a leading digit'     => array( '2checkout' ),
			'33 characters'       => array( str_repeat( 'a', 33 ) ),
		);
	}

	/**
	 * Tests the settings a gateway may not declare, and the modes and versions it may not state.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider refusedDeclarations
	 *
	 * @param array  $modes    The modes.
	 * @param array  $settings The settings.
	 * @param string $contract The contract version.
	 *
	 * @phpstan-param list<Mode>      $modes
	 * @phpstan-param list<FieldSpec> $settings
	 */
	public function test_a_declaration_a_gateway_may_not_make_is_refused( array $modes, array $settings, string $contract ): void {
		$this->expectException( \InvalidArgumentException::class );

		new GatewayDescriptor( 'example', static fn(): string => 'Example', GatewayDescriptor::TYPE_PAYMENTS, $contract, $modes, $settings, self::matrix(), new IdempotencyProfile( null, false, 0 ), array() );
	}

	/**
	 * Declarations a gateway may not make.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: list<Mode>, 1: list<FieldSpec>, 2: string}> The modes, the settings and the contract version.
	 */
	public static function refusedDeclarations(): array {
		$version = PaymentGateway::CONTRACT_VERSION;

		return array(
			'no mode'                        => array( array(), array(), $version ),
			'a mode twice'                   => array( array( Mode::Test, Mode::Test ), array(), $version ),
			'a setting named mode'           => array( array( Mode::Test ), array( self::field( 'mode' ) ), $version ),
			'a setting twice'                => array( array( Mode::Test ), array( self::field( 'key' ), self::field( 'key' ) ), $version ),
			'a card number'                  => array( array( Mode::Test ), array( self::field( 'card_number' ) ), $version ),
			'a security code'                => array( array( Mode::Test ), array( self::field( 'cvv' ) ), $version ),
			'an expiry'                      => array( array( Mode::Test ), array( self::field( 'card_exp_month' ) ), $version ),
			'an account number'              => array( array( Mode::Test ), array( self::field( 'account_number' ) ), $version ),
			'an account country as a secret' => array( array( Mode::Test ), array( self::field( GatewayDescriptor::ACCOUNT_COUNTRY, Privacy::Secret ) ), $version ),
			'a version that is not one'      => array( array( Mode::Test ), array(), '0.2' ),
		);
	}

	/**
	 * Tests that a descriptor keeps the hosts it declares, and refuses a host that is no declaration or one declared twice: a request names its host by id.
	 *
	 * Planted violation, shown red and removed: in GatewayDescriptor::checkHosts(), drop the check
	 * of a repeated id: the descriptor declaring `example` twice is then accepted.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_declares_each_host_once(): void {
		$hosts      = array( self::host( 'example' ), self::host( 'other' ) );
		$descriptor = new GatewayDescriptor( 'example', static fn(): string => 'Example', GatewayDescriptor::TYPE_PAYMENTS, PaymentGateway::CONTRACT_VERSION, array( Mode::Test ), array(), self::matrix(), new IdempotencyProfile( null, true, 0 ), $hosts );

		$this->assertSame( $hosts, $descriptor->hosts );

		$refusals = array(
			'twice'             => array( self::host( 'example' ), self::host( 'example' ) ),
			'not a declaration' => array( 'https://api.example.example/v1/*' ),
		);

		foreach ( $refusals as $case => $refused ) {
			try {
				new GatewayDescriptor( 'example', static fn(): string => 'Example', GatewayDescriptor::TYPE_PAYMENTS, PaymentGateway::CONTRACT_VERSION, array( Mode::Test ), array(), self::matrix(), new IdempotencyProfile( null, true, 0 ), $refused );
				$this->fail( "A host {$case} was accepted." );
			} catch ( \InvalidArgumentException $refusal ) {
				$this->assertStringContainsString( 'host', $refusal->getMessage(), $case );
			}
		}
	}

	/**
	 * Tests that a name that only contains a card word is not read as card data, and that an idempotency profile counts its seconds from 0.
	 *
	 * @since 0.2.0
	 */
	public function test_a_profile_counts_seconds_from_zero_and_ordinary_names_pass(): void {
		$descriptor = self::descriptor( 'example', array( Mode::Test ), array( self::field( 'company_name' ), self::field( 'tracking_url' ), self::field( 'publishable_key' ) ) );

		$this->assertCount( 3, $descriptor->settings, 'A word that only contains a card word is no card field.' );

		$this->expectException( \InvalidArgumentException::class );

		new IdempotencyProfile( -1, true, 0 );
	}

	/**
	 * Builds a descriptor with the stand-in's matrix.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $id       The id.
	 * @param array         $modes    Optional. The modes. Default test.
	 * @param array         $settings Optional. The settings. Default none.
	 * @param \Closure|null $label    Optional. The label. Default `Example`.
	 * @return GatewayDescriptor The descriptor.
	 *
	 * @phpstan-param list<Mode>      $modes
	 * @phpstan-param list<FieldSpec> $settings
	 */
	private static function descriptor( string $id, array $modes = array( Mode::Test ), array $settings = array(), ?\Closure $label = null ): GatewayDescriptor {
		return new GatewayDescriptor( $id, $label ?? static fn(): string => 'Example', GatewayDescriptor::TYPE_PAYMENTS, PaymentGateway::CONTRACT_VERSION, $modes, $settings, self::matrix(), new IdempotencyProfile( 86400, true, 60 ), array() );
	}

	/**
	 * Returns a matrix of one row.
	 *
	 * @since 0.2.0
	 *
	 * @return CapabilityMatrix USD for an account of any country, the required operations.
	 */
	private static function matrix(): CapabilityMatrix {
		return new CapabilityMatrix( array( new MatrixRow( Currency::of( 'USD' ), MatrixRow::ANY_COUNTRY, Operations::REQUIRED ) ) );
	}

	/**
	 * Declares an external service on the host `api.{name}.example`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The service's id.
	 * @return OutboundHost The declaration.
	 */
	private static function host( string $name ): OutboundHost {
		return new OutboundHost( $name, 'Example', 'A provider.', 'https://api.' . $name . '.example/v1/*', 'References only.', 'When a payment is made.', 'https://example.com/terms', 'https://example.com/privacy' );
	}

	/**
	 * Declares a text setting.
	 *
	 * @since 0.2.0
	 *
	 * @param string  $name    The name.
	 * @param Privacy $privacy Optional. The privacy class. Default Privacy::Public.
	 * @return FieldSpec The field.
	 */
	private static function field( string $name, Privacy $privacy = Privacy::Public ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::String, description: 'A setting of the test gateway.', label: static fn(): string => 'Setting', example: 'x', privacy: $privacy );
	}
}

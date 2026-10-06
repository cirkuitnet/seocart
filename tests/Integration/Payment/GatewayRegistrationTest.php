<?php
/**
 * Tests how gateway plugins register their gateways: through the real action, once, and refused without harm
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
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Doubles\UndescribableGateway;
use SEOCart\Tests\Support\Payment\TestGateways;

/**
 * The registry registers the stand-in first, then fires the registration action once, the first time it is asked for a gateway; a gateway plugin registers through the action; a duplicate id, a contract the plugin does not support, a malformed descriptor, a host declared twice, a registration after the action ran and a listener that throws are each refused with a log line and never stop the others.
 *
 * Planted violations, each shown red and removed:
 * - in Gateways::register(), drop the duplicate check: the second `example` replaces the first;
 * - in Gateways::register(), drop the contract check: the gateway written against 9.0.0 is
 *   registered;
 * - in GatewayDescriptor::checkHosts(), drop the check of a repeated id: `doubled` is registered.
 *
 * @since 0.2.0
 */
final class GatewayRegistrationTest extends DatabaseTestCase {

	/**
	 * Tests that a gateway plugin registers through the action, which fires once, on the first ask and not before.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_registers_through_the_action_fired_once_on_first_use(): void {
		$fired    = 0;
		$example  = new DeclaredGateway( DeclaredGateway::descriptor( 'example', array( Mode::Test ), array() ) );
		$gateways = $this->gateways( new StubGateway() );

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ) use ( &$fired, $example ): void {
				++$fired;

				if ( $registry->supportsContract( '0.2.0' ) ) {
					$registry->register( $example );
				}
			}
		);

		$this->assertSame( 0, $fired, 'Building the registry fires nothing.' );
		$this->assertSame( $example, $gateways->get( 'example', Mode::Test ) );
		$this->assertInstanceOf( StubGateway::class, $gateways->get( StubGateway::ID, Mode::Test ), 'The stand-in is registered too.' );
		$gateways->descriptor( 'example' );
		$this->assertSame( 1, $fired, 'The action fires once per request.' );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests the refusals: each is a log line, the gateways registered before it stay, and none is fatal.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_registration_is_logged_and_harms_nothing(): void {
		$first    = new DeclaredGateway( DeclaredGateway::descriptor( 'example', array( Mode::Test ), array() ) );
		$gateways = $this->gateways( null );
		$late     = null;

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ) use ( $first, &$late ): void {
				$registry->register( $first );
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'example', array( Mode::Test ), array() ) ) );
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'future', array( Mode::Test ), array(), null, null, '9.0.0' ) ) );
				$registry->register( new UndescribableGateway() );
				$registry->register( new UndescribableGateway( static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'doubled', array( Mode::Test ), array(), null, null, PaymentGateway::CONTRACT_VERSION, array( DeclaredGateway::host( 'twice' ), DeclaredGateway::host( 'twice' ) ) ) ) );
				$late = $registry;
			}
		);
		add_action(
			GatewayRegistry::ACTION,
			static function (): void {
				throw new \RuntimeException( 'A broken gateway plugin.' );
			},
			20
		);

		$this->assertSame( $first, $gateways->get( 'example', Mode::Test ), 'The first registration of an id stays.' );
		$this->assertNotNull( $late );
		$late->register( new DeclaredGateway( DeclaredGateway::descriptor( 'latecomer', array( Mode::Test ), array() ) ) );

		$this->assertSame(
			array(
				array( Gateways::REJECTED, 'duplicate' ),
				array( Gateways::INCOMPATIBLE, null ),
				array( Gateways::REJECTED, 'invalid_descriptor' ),
				array( Gateways::REJECTED, 'invalid_descriptor' ),
				array( Gateways::REJECTED, 'registration_failed' ),
				array( Gateways::REJECTED, 'late' ),
			),
			array_map( static fn( array $report ): array => array( $report['code'], $report['context']['reason'] ?? null ), $this->reports )
		);
		$this->assertSame( array( '9.0.0', '0.2.0' ), array( $this->reports[1]['context']['written_against'], $this->reports[1]['context']['contract'] ) );
		$this->assertSame( 'Its descriptor could not be built: describe(), or the settings it declares, threw InvalidArgumentException.', $this->reports[3]['context']['detail'], 'A gateway whose hosts are not each declared once is refused, and what it threw is named by its class.' );

		foreach ( array( 'future', 'latecomer', 'doubled' ) as $refused ) {
			try {
				$gateways->get( $refused, Mode::Test );
				$this->fail( "The refused gateway {$refused} was registered." );
			} catch ( CodedException $unavailable ) {
				$this->assertSame( PaymentError::GatewayUnavailable, $unavailable->errorCode() );
				$this->assertSame(
					array(
						'gateway_id' => $refused,
						'reason'     => 'not_registered',
					),
					$unavailable->context()
				);
			}
		}
	}

	/**
	 * Tests that a gateway id that is not one is refused a context.
	 *
	 * @since 0.2.0
	 */
	public function test_a_context_is_minted_only_for_a_gateway_id(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->gateways( null )->context( 'not-an-id' );
	}

	/**
	 * Builds a registry over the test's reporter.
	 *
	 * @since 0.2.0
	 *
	 * @param StubGateway|null $stub The stand-in, or null.
	 * @return Gateways The registry.
	 */
	private function gateways( ?StubGateway $stub ): Gateways {
		return new Gateways(
			$stub,
			static function ( string $gatewayId ): GatewayContext {
				throw new \LogicException( sprintf( 'The gateway %s has no settings, and needs no context.', $gatewayId ) );
			},
			$this->reporter(),
			static function (): void {
			},
			TestGateways::switches()
		);
	}
}

<?php
/**
 * Tests `wp seocart gateway mode`: only the mode new payments are created in changes, and a payment keeps its own
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
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Infrastructure\Cli\GatewayCommand;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Payment\GatewayCommandRecorder;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * Switching `second` from live to test writes its mode alone, every sealed credential kept byte for byte, and new payments are then created in test; a payment authorized live before the switch is captured with the live credential. The stand-in, which has one mode, has nothing to switch; a mode the gateway is not set up for, and a user who may not manage the store's settings, are refused, and nothing is written.
 *
 * Planted violation, shown red and removed: in GatewayConfiguration::write(), write the values
 * given alone, dropping what the document held: the live credential is gone, and the live capture
 * is refused `credentials_missing`.
 *
 * @since 0.2.0
 */
final class GatewayModeTest extends RefundTestCase {

	/**
	 * The production wiring, with the test's data keys.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * The gateway, as it registered.
	 *
	 * @since 0.2.0
	 *
	 * @var DeclaredGateway|null
	 */
	private ?DeclaredGateway $second = null;

	/**
	 * Builds the kernel and has `second` register through the action.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		GatewayKernel::deleteOptions();

		$this->kernel = GatewayKernel::over( $this->db, $this->reporter(), $this->publisherOver( $this->db ) );

		add_action(
			GatewayRegistry::ACTION,
			function ( GatewayRegistry $registry ): void {
				$this->second = new DeclaredGateway( DeclaredGateway::descriptor( 'second' ), $registry->context( 'second' ) );

				$registry->register( $this->second );
			}
		);
	}

	/**
	 * Deletes the data keys and the gateway's document.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests the switch: the mode alone written, new payments in test, and a live payment captured with the live credential.
	 *
	 * @since 0.2.0
	 */
	public function test_switching_the_mode_changes_only_where_new_payments_go(): void {
		$this->configure( Mode::Test, 'sk_test_planted' );
		$this->configure( Mode::Live, 'sk_live_planted' );

		$live    = $this->authorizedLive();
		$before  = GatewayKernel::storedDocument( 'second' );
		$command = new GatewayCommandRecorder( $this->kernel );

		$this->assertSame( GatewayCommand::EXIT_OK, $command->run( array( 'mode', 'second', 'test' ), array(), $this->userWithRole( 'seocart_manager' )->userId() ) );
		$this->assertSame( sprintf( 'New payments through second are created in test mode (its settings are at version %d).', $before['version'] + 1 ), $command->lines[0] );
		$this->assertSame( 'Its 1 open payment keeps the mode each was created in.', $command->last() );

		$after = GatewayKernel::storedDocument( 'second' );

		$this->assertSame( $before['version'] + 1, $after['version'] );
		$this->assertSame( array( 'second_mode' => 'test' ) + $before['values'], $after['values'], 'Only the mode changed; every sealed credential is kept byte for byte.' );
		$this->assertNotNull( $this->second );
		$this->second->opened = array();
		$this->second->calls  = array();

		$this->kernel->get( PaymentService::class )->capture( $live, $this->userWithRole() );

		$this->assertSame( array( 'capture' ), array_column( $this->second->calls, 'method' ) );
		$this->assertSame( array( 'live' ), array_column( $this->second->calls, 'mode' ), 'The live payment is captured live.' );
		$this->assertSame( array( 'sk_live_planted' ), $this->second->opened, 'With the live credential.' );
		$this->assertSame( Mode::Test, $this->nextRequest()->configuredMode( 'second' ), 'New payments are created in test mode.' );
	}

	/**
	 * Tests the refusals: a gateway with one mode, a mode not set up, a user who may not, no user, and a mode that is none; nothing written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_switch_that_cannot_be_made_writes_nothing(): void {
		$this->configure( Mode::Live, 'sk_live_planted' );

		$before  = GatewayKernel::storedDocument( 'second' );
		$command = new GatewayCommandRecorder( $this->kernel );
		$manager = $this->userWithRole( 'seocart_manager' )->userId();

		$this->assertSame( GatewayCommand::EXIT_FAILED, $command->run( array( 'mode', StubGateway::ID, 'live' ), array(), $manager ) );
		$this->assertSame( 'The gateway stub has one mode, test: there is nothing to switch.', $command->last() );

		$this->assertSame( GatewayCommand::EXIT_FAILED, $command->run( array( 'mode', 'second', 'test' ), array(), $manager ) );
		$this->assertSame( 'The gateway second is not set up for test mode: save its test credentials with configure first. New payments stay in live mode.', $command->last() );

		$this->assertSame( GatewayCommand::EXIT_FAILED, $command->run( array( 'mode', 'second', 'live' ), array(), $this->userWithRole()->userId() ) );
		$this->assertStringStartsWith( 'authorization.denied:', $command->last() );

		$this->assertSame( GatewayCommand::EXIT_FAILED, $command->run( array( 'mode', 'second', 'live' ) ) );
		$this->assertSame( 'This action changes the store, so it runs only as a user: run it with --user=<login>.', $command->last() );

		$this->assertSame( GatewayCommand::EXIT_FAILED, $command->run( array( 'mode', 'second', 'sandbox' ), array(), $manager ) );
		$this->assertStringStartsWith( 'Usage: wp seocart gateway', $command->last() );

		$this->assertSame( $before, GatewayKernel::storedDocument( 'second' ), 'Nothing was written.' );
	}

	/**
	 * Saves `second`'s settings for a mode, and makes it its mode.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode   $mode   The mode.
	 * @param string $secret The secret key.
	 */
	private function configure( Mode $mode, string $secret ): void {
		GatewayKernel::writeDocument(
			$this->kernel,
			DeclaredGateway::descriptor( 'second' ),
			array(
				'second_mode' => $mode->value,
				GatewayKernel::name( 'second', $mode, 'secret_key' ) => $secret,
				GatewayKernel::name( 'second', $mode, GatewayDescriptor::ACCOUNT_COUNTRY ) => 'US',
			)
		);
	}

	/**
	 * Creates a live intent through `second` and has it authorized.
	 *
	 * @since 0.2.0
	 *
	 * @return string The intent's uuid.
	 */
	private function authorizedLive(): string {
		$uuid     = GatewayKernel::openIntent( $this->kernel, 'second', Mode::Live );
		$payments = $this->kernel->get( PaymentService::class );
		$result   = $payments->authorize( $uuid, array( PaymentService::PAYMENT_TOKEN => StubGateway::APPROVE ), 'order', 'SC-1' );

		$this->db->transaction( static fn() => $payments->applyGatewayResult( $result, Actor::user( 0 ) ) );

		return $uuid;
	}

	/**
	 * Returns the registry of a new request.
	 *
	 * @since 0.2.0
	 *
	 * @return Gateways The registry.
	 */
	private function nextRequest(): Gateways {
		return GatewayKernel::request( $this->kernel, $this->db, $this->reporter(), $this->publisherOver( $this->db ) )->get( Gateways::class );
	}
}

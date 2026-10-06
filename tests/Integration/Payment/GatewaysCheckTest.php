<?php
/**
 * Tests doctor's and Site Health's check of the gateways that hold open payments
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
use SEOCart\Payment\Infrastructure\Doctor\GatewaysCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Support\Clock;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Payment\GatewayCommandRecorder;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * Every gateway with open payments that can be asked about them passes, Site Health good; a gateway that is not registered, or has no saved credentials for its payments' mode, is a warning (Site Health recommended), and a critical finding once one of those payments has waited more than a day; live payments in Safe Mode are a warning, however long they wait.
 *
 * Planted violations, each shown red and removed:
 * - in GatewaysCheck::findings(), pass over a gateway that is not registered: the check passes;
 * - in GatewaysCheck::findings(), wait ten days before a finding is critical: the payment that
 *   waited more than a day stays a warning.
 *
 * @since 0.2.0
 */
final class GatewaysCheckTest extends RefundTestCase {

	/**
	 * The production wiring, with the test's data keys.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

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
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'second' ), $registry->context( 'second' ) ) );
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
	 * Tests a store with no open payment, then one whose every gateway can be asked: passed, good.
	 *
	 * @since 0.2.0
	 */
	public function test_every_gateway_that_can_be_asked_passes(): void {
		$this->assertSame( array( true, 'good' ), $this->verdict(), 'No payment is open.' );

		$this->configure( Mode::Test );
		GatewayKernel::openIntent( $this->kernel, 'second', Mode::Test );
		GatewayKernel::openIntent( $this->kernel, StubGateway::ID, Mode::Test );

		$result = $this->check()->run();

		$this->assertTrue( $result->passed );
		$this->assertSame( 'Every gateway with open payments can be asked about them: second (1 open, test, enabled), stub (1 open, test, enabled).', $result->summary );
		$this->assertSame( 'good', $this->check()->siteHealthTest()['status'] );
	}

	/**
	 * Tests a gateway that is not registered: a warning, and a critical finding once a payment waited more than a day.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_gone_with_open_payments_is_a_warning_then_critical(): void {
		GatewayKernel::openIntent( $this->kernel, StubGateway::ID, Mode::Test );
		GatewayKernel::openIntent( $this->kernel, StubGateway::ID, Mode::Test );
		$this->db->execute( "UPDATE %i SET gateway_id = 'gone'", $this->table( PaymentTables::INTENTS ) );

		$result = $this->check()->run();

		$this->assertFalse( $result->passed );
		$this->assertSame( array( 'Warning: gone is not registered, and 2 of its test payments are open: they cannot be captured, voided, refunded or reconciled until the gateway\'s plugin is active again.' ), $result->findings );
		$this->assertSame( 'recommended', $this->check()->siteHealthTest()['status'] );

		// One of them has gone unchanged for more than a day, by the database's clock.
		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 25 HOUR ORDER BY id LIMIT 1', $this->table( PaymentTables::INTENTS ) );

		$result = $this->check()->run();
		$health = $this->check()->siteHealthTest();

		$this->assertSame( array( 'Critical: gone is not registered, and 2 of its test payments are open: they cannot be captured, voided, refunded or reconciled until the gateway\'s plugin is active again. One of them has waited for its gateway\'s answer for more than a day.' ), $result->findings );
		$this->assertSame( array( 'critical', GatewaysCheck::TEST ), array( $health['status'], $health['test'] ) );
		$this->assertStringContainsString( 'gone is not registered', $health['description'] );
	}

	/**
	 * Tests a gateway whose plugin is active but whose registration SEOCart refused: doctor's check and the command's status name the refusal, and doctor points to the status, instead of advising to activate the plugin.
	 *
	 * Planted violation, shown red and removed: in GatewayStatuses::refusalReasons(), return no
	 * reason: both advise activating a plugin that is active.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_whose_registration_was_refused_is_named_with_the_refusal(): void {
		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'future', array( Mode::Test ), array(), null, null, '9.0.0' ) ) );
			}
		);

		GatewayKernel::openIntent( $this->kernel, StubGateway::ID, Mode::Test );
		$this->db->execute( "UPDATE %i SET gateway_id = 'future'", $this->table( PaymentTables::INTENTS ) );

		$this->assertSame( array( 'Warning: future is not registered: SEOCart refused its registration (incompatible), and 1 of its test payments is open: it cannot be captured, voided, refunded or reconciled until the registration is accepted. wp seocart gateway status says why.' ), $this->check()->run()->findings );

		$command = new GatewayCommandRecorder( $this->kernel );

		$this->assertSame( 0, $command->run( array( 'status' ) ) );
		$this->assertContains( 'Not registered: future, with 1 open payment, which cannot be captured, voided, refunded or reconciled until SEOCart accepts its registration: it was refused (incompatible), as its Refused line above says.', $command->lines );
		$this->assertContains( 'Refused: future, from no plugin (incompatible): It was written against the payment contract 9.0.0, and this version of SEOCart implements 0.2.0.', $command->lines );
	}

	/**
	 * Tests the other reasons: credentials missing for the payments' mode, and live payments in Safe Mode, which stay warnings.
	 *
	 * @since 0.2.0
	 */
	public function test_missing_credentials_and_safe_mode_are_warnings(): void {
		$this->configure( Mode::Live );
		GatewayKernel::openIntent( $this->kernel, 'second', Mode::Test );
		GatewayKernel::openIntent( $this->kernel, 'second', Mode::Live );
		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 25 HOUR', $this->table( PaymentTables::INTENTS ) );

		$this->assertSame( array( 'Critical: second has no saved test settings, and 1 of its test payments is open: it cannot be captured, refunded or reconciled until they are saved again with wp seocart gateway configure. One of them has waited for its gateway\'s answer for more than a day.' ), $this->check()->run()->findings );

		$safe = GatewayKernel::request( $this->kernel, $this->db, $this->reporter(), $this->publisherOver( $this->db ), array( SafeMode::class => static fn( Container $c ): SafeMode => new SafeMode( $c->get( BootOption::class ), $c->get( Clock::class ), true ) ) );

		$this->assertContains( 'Warning: Safe Mode is on, and 1 of second\'s live payments is open: SEOCart makes no live call, so it waits until Safe Mode ends.', $safe->get( GatewaysCheck::class )->run()->findings );
	}

	/**
	 * Returns the check as a new request builds it.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewaysCheck The check.
	 */
	private function check(): GatewaysCheck {
		return GatewayKernel::request( $this->kernel, $this->db, $this->reporter(), $this->publisherOver( $this->db ) )->get( GatewaysCheck::class );
	}

	/**
	 * Returns whether the check passes and Site Health's status.
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: bool, 1: string} The two.
	 */
	private function verdict(): array {
		return array( $this->check()->run()->passed, $this->check()->siteHealthTest()['status'] );
	}

	/**
	 * Saves `second`'s settings for a mode, and makes it its mode.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 */
	private function configure( Mode $mode ): void {
		GatewayKernel::writeDocument(
			$this->kernel,
			DeclaredGateway::descriptor( 'second' ),
			array(
				'second_mode' => $mode->value,
				GatewayKernel::name( 'second', $mode, 'secret_key' ) => 'sk_planted',
				GatewayKernel::name( 'second', $mode, GatewayDescriptor::ACCOUNT_COUNTRY ) => 'US',
			)
		);
	}
}

<?php
/**
 * Tests how a gateway's settings for a mode are judged from the stored document alone, and why the registry would refuse a payment's gateway, opening no credential
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
use SEOCart\Payment\Application\CredentialState;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;
use SEOCart\Tests\Support\Payment\GatewayKernel;

/**
 * A mode is configured when every setting without a default is saved and each credential is a sealed value naming a data key the site holds; missing when one was never saved; unreadable when a credential names a key the site does not hold, or the document holds text that is no sealed value. A credential altered after its header still reads configured, so nothing was opened to judge it, and the registry refuses it when it opens it before a call. unavailableFor() gives the reason get() would.
 *
 * Planted violations, each shown red and removed:
 * - in GatewayModeSettings::credentials(), judge a credential by opening it (secret()) instead of
 *   by its header: the altered credential then reads unreadable;
 * - in Gateways::get(), open the credentials before the mode is checked: the test-only gateway
 *   asked for a live payment is refused for its credentials, and so is the live payment in Safe
 *   Mode.
 *
 * @since 0.2.0
 */
final class GatewayCredentialsTest extends DatabaseTestCase {

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

		$this->kernel = GatewayKernel::over( $this->db, $this->reporter(), new RecordingEventPublisher() );

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
	 * Tests configured, missing, a key the site does not hold, a credential altered after its header, and text that is no sealed value.
	 *
	 * @since 0.2.0
	 */
	public function test_the_credentials_are_judged_from_the_stored_text_alone(): void {
		$this->assertSame( array( CredentialState::Missing, CredentialState::Missing ), $this->states(), 'Nothing saved.' );

		$this->configure( Mode::Live, 'sk_live_planted' );

		$this->assertSame( array( CredentialState::Missing, CredentialState::Configured ), $this->states(), 'Live saved, test never.' );
		$this->assertSame( 'credentials_missing', $this->gateways()->unavailableFor( 'second', Mode::Test ) );
		$this->assertNull( $this->gateways()->unavailableFor( 'second', Mode::Live ) );

		GatewayKernel::alterStored( 'second', Mode::Live, 'secret_key', static fn( string $sealed ): string => substr( $sealed, 0, -12 ) . str_repeat( 'A', 12 ) );

		$this->assertSame( CredentialState::Configured, $this->states()[1], 'A credential altered after its header reads as configured: nothing was opened.' );

		try {
			$this->gateways()->get( 'second', Mode::Live );
			$this->fail( 'A credential that does not open was used.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( 'credentials_unreadable', $refused->context()['reason'] ?? null, 'Opening it before a call refuses it.' );
		}

		$this->configure( Mode::Live, 'sk_live_planted' );
		GatewayKernel::alterStored( 'second', Mode::Live, 'secret_key', static fn( string $sealed ): string => (string) preg_replace( '/^v1:[0-9a-f]{16}:/', 'v1:' . str_repeat( '0', 16 ) . ':', $sealed ) );

		$this->assertSame( CredentialState::Unreadable, $this->states()[1], 'A credential sealed with a key the site does not hold.' );
		$this->assertSame( 'credentials_unreadable', $this->gateways()->unavailableFor( 'second', Mode::Live ) );

		GatewayKernel::alterStored( 'second', Mode::Live, 'secret_key', static fn(): string => 'sk_live_plain_text' );

		$this->assertSame( array( CredentialState::Unreadable, CredentialState::Unreadable ), $this->states(), 'Text that is no sealed value makes the document unreadable, both modes with it.' );
	}

	/**
	 * Tests the reasons unavailableFor() gives before the credentials: not registered, and a mode the gateway does not declare.
	 *
	 * @since 0.2.0
	 */
	public function test_the_reason_a_gateway_cannot_be_asked_is_the_one_get_gives(): void {
		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'test_only', array( Mode::Test ), array() ) ) );
			}
		);

		$gateways = $this->gateways();

		$this->assertSame( 'not_registered', $gateways->unavailableFor( 'gone', Mode::Test ) );
		$this->assertSame( 'live_mode_not_declared', $gateways->unavailableFor( 'test_only', Mode::Live ) );
		$this->assertNull( $gateways->unavailableFor( 'test_only', Mode::Test ), 'A gateway with no settings needs none.' );
		$this->assertSame( CredentialState::Configured, $gateways->credentials( 'test_only', Mode::Test ) );
	}

	/**
	 * Tests that get() refuses for the mode before it opens a credential, on gateways with settings documents: a test-only gateway asked for a live payment, and a live payment in Safe Mode whose live credentials were never saved.
	 *
	 * @since 0.2.0
	 */
	public function test_get_refuses_for_the_mode_before_it_opens_a_credential(): void {
		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'test_only', array( Mode::Test ) ), $registry->context( 'test_only' ) ) );
			}
		);

		$this->configure( Mode::Test, 'sk_test_planted' );

		$this->assertSame( 'live_mode_not_declared', $this->refusal( $this->gateways(), 'test_only', Mode::Live ), 'A test-only gateway with settings, asked for a live payment.' );
		$this->assertSame( 'credentials_missing', $this->refusal( $this->gateways(), 'second', Mode::Live ), 'Out of Safe Mode, the live payment is refused for its missing credentials.' );

		$safe = GatewayKernel::request( $this->kernel, $this->db, $this->reporter(), new RecordingEventPublisher(), array( SafeMode::class => static fn( Container $c ): SafeMode => new SafeMode( $c->get( BootOption::class ), $c->get( Clock::class ), true ) ) );

		$this->assertSame( 'safe_mode', $this->refusal( $safe->get( Gateways::class ), 'second', Mode::Live ), 'In Safe Mode, the live payment is refused for Safe Mode first.' );
	}

	/**
	 * Returns why get() refuses a gateway for a mode.
	 *
	 * @since 0.2.0
	 *
	 * @param Gateways $gateways  The registry.
	 * @param string   $gatewayId The gateway.
	 * @param Mode     $mode      The payment's mode.
	 * @return string|null The reason `payment.gateway_unavailable` gives; null when get() hands the gateway over.
	 */
	private function refusal( Gateways $gateways, string $gatewayId, Mode $mode ): ?string {
		try {
			$gateways->get( $gatewayId, $mode );
		} catch ( CodedException $refused ) {
			return (string) ( $refused->context()['reason'] ?? '' );
		}

		return null;
	}

	/**
	 * Returns `second`'s credential state for its test and live modes, read afresh.
	 *
	 * @since 0.2.0
	 *
	 * @return list<CredentialState> Test's, then live's.
	 */
	private function states(): array {
		wp_cache_flush();

		return array( $this->gateways()->credentials( 'second', Mode::Test ), $this->gateways()->credentials( 'second', Mode::Live ) );
	}

	/**
	 * Writes `second`'s settings for a mode.
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
				GatewayKernel::name( 'second', $mode, 'secret_key' ) => $secret,
				GatewayKernel::name( 'second', $mode, GatewayDescriptor::ACCOUNT_COUNTRY ) => 'US',
			)
		);
	}


	/**
	 * Returns the kernel's gateway registry.
	 *
	 * @since 0.2.0
	 *
	 * @return Gateways The registry.
	 */
	private function gateways(): Gateways {
		return $this->kernel->get( Gateways::class );
	}
}

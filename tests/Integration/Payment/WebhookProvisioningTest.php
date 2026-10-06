<?php
/**
 * Tests that saving a gateway's credentials sets up its provider's webhook endpoint for this site: reused, replaced or created, outside any transaction, never another site's, never live in Safe Mode, and never half-written
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\CredentialUnavailable;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\GatewayConfiguration;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\WebhookAddress;
use SEOCart\Payment\Infrastructure\Cli\GatewayCommand;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Support\Clock;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Doubles\ProvisioningGateway;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Payment\FakeWebhookProvider;
use SEOCart\Tests\Support\Payment\GatewayCommandRecorder;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * `second` sets up its provider's endpoints itself. `wp seocart gateway configure` saves its settings, then has it set up this site's endpoint for the mode, and saves the signing secret sealed with the endpoint's id beside it: created when the site has none, reused while the site holds that endpoint's secret, replaced when it does not, as after a secret entered by hand, which forgets the id, or once the stored secret no longer opens. An endpoint the gateway says it reused whose secret the site does not hold fails the step. An endpoint of another installation, or of this one at another address (a copy of the site), is never listed as the site's, changed or deleted, and the second is counted. In Safe Mode a live set-up is refused before anything is read or written. A provider that fails leaves the settings saved, the signing secret absent and the step reported failed, with no card number in what is printed or logged. Every call to the provider is made outside any transaction.
 *
 * Planted violations, each shown red and removed:
 * - in ProvisioningGateway::provisionWebhooks(), drop the install uuid from the test of an
 *   endpoint: another installation's endpoint is deleted;
 * - in ProvisioningGateway::provisionWebhooks(), drop the URL from the test: the copy's
 *   endpoint is deleted;
 * - in GatewayConfiguration::configure(), write the settings with the signing secret, once the
 *   provider answered: a provider that fails leaves no settings;
 * - in GatewayConfiguration::configure(), set the endpoint up inside the settings' write: the
 *   provider is called inside a transaction;
 * - in GatewayConfiguration::changes(), keep the endpoint's id when a signing secret is entered by
 *   hand: the next set-up reuses the endpoint created before with the hand-entered secret;
 * - in GatewayConfiguration::provision(), check only that a secret is held when the gateway says
 *   it reused an endpoint, as before: another endpoint is taken for the one whose secret is held;
 * - in GatewayConfiguration::provision(), save the signing secret without the endpoint's id: the
 *   endpoint is replaced on every set-up, never reused;
 * - in GatewayConfiguration::heldEndpoint(), judge the stored secret by its header
 *   (SecretVault::isHeld()) instead of opening it, as before: a secret altered after its header
 *   is said to still verify the endpoint, and no fresh secret is saved;
 * - in GatewayConfiguration::provision(), report the provider's message with the card numbers and
 *   the credentials given in the run taken out, as before: the stored signing secret it quoted is
 *   printed and logged.
 *
 * @since 0.2.0
 */
final class WebhookProvisioningTest extends PaymentTestCase {

	/**
	 * The installation uuid the planted boot record carries.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INSTALL = '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b';

	/**
	 * The production wiring, with the test's data keys.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * The provider.
	 *
	 * @since 0.2.0
	 *
	 * @var FakeWebhookProvider
	 */
	private FakeWebhookProvider $provider;

	/**
	 * The settings files the test wrote.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $files = array();

	/**
	 * Plants the boot record, builds the kernel, answers the provider's API, and has `second` register through the action.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		GatewayKernel::deleteOptions();

		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn(): BootRecord => KernelTestCase::installedRecord() );

		$this->kernel   = GatewayKernel::over( $this->db, $this->reporter(), $this->publisherOver( $this->db ) );
		$this->provider = new FakeWebhookProvider( fn(): int => $this->db->depth() );

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new ProvisioningGateway( self::descriptor(), $registry->context( 'second' ) ) );
			}
		);
	}

	/**
	 * Deletes the files, the boot record, the data keys and the gateway's document.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		$wpdb->delete( $wpdb->options, array( 'option_name' => BootOption::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removes the record the test planted.
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests the endpoint created with the route's URL, the events and the owner tag, its secret saved sealed; reused while the secret is held; replaced once it is lost.
	 *
	 * @since 0.2.0
	 */
	public function test_configure_creates_reuses_and_replaces_this_sites_endpoint(): void {
		$url     = WebhookAddress::url( 'second', Mode::Test );
		$command = $this->command();

		$this->assertStringEndsWith( '/seocart/v1/webhooks/second/test', $url );
		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertSame( array( 'GET', 'POST' ), $this->provider->methods() );

		$created = $this->provider->endpoints['we_1'];

		$this->assertSame( array( $url, ProvisioningGateway::EVENTS, self::tag( 'test' ) ), array( $created['url'], $created['enabled_events'], $created['metadata'] ) );
		$this->assertStringStartsWith( 'v1:', $this->storedSecret( Mode::Test ), 'The signing secret is stored sealed.' );
		$this->assertSame( $created['secret'], $this->openedSecret( Mode::Test ), 'The secret saved is the one the provider gave.' );
		$this->assertContains( 'Webhook endpoint we_1: created. Its signing secret is saved.', $command->lines );
		$this->assertSame( 'we_1', $this->storedEndpoint( Mode::Test ), 'The endpoint\'s id is kept beside its signing secret.' );

		$sealed                   = $this->storedSecret( Mode::Test );
		$this->provider->requests = array();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertSame( array( 'GET' ), $this->provider->methods(), 'Nothing is created while the site holds the secret.' );
		$this->assertSame( $sealed, $this->storedSecret( Mode::Test ), 'The secret held is kept as it is.' );
		$this->assertContains( 'Webhook endpoint we_1: reused. The signing secret saved before still verifies its deliveries.', $command->lines );

		GatewayKernel::alterStored( 'second', Mode::Test, GatewayDescriptor::WEBHOOK_SECRET, static fn(): ?string => null );
		$this->provider->requests = array();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertSame( array( 'GET', 'DELETE', 'POST' ), $this->provider->methods() );
		$this->assertSame( array( 'we_2' ), array_keys( $this->provider->endpoints ), 'The endpoint whose secret was lost is replaced.' );
		$this->assertSame( $this->provider->endpoints['we_2']['secret'], $this->openedSecret( Mode::Test ) );
		$this->assertContains( 'Webhook endpoint we_2: replaced. Its signing secret is saved.', $command->lines );
		$this->assertSame( 'we_2', $this->storedEndpoint( Mode::Test ) );

		foreach ( array( $created['secret'], $this->provider->endpoints['we_2']['secret'], 'sk_test_planted' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $command->printed() . $this->logText(), 'A secret was printed or logged.' );
		}
	}

	/**
	 * Tests that another installation's endpoint, and this installation's at another address, are never deleted, and the second is counted.
	 *
	 * @since 0.2.0
	 */
	public function test_an_endpoint_of_another_install_or_address_is_never_touched(): void {
		$url = WebhookAddress::url( 'second', Mode::Test );

		$this->provider->hold( 'we_other_install', $url, '01999999-0000-7000-8000-000000000000', 'test' );
		$this->provider->hold( 'we_copy', 'https://copy.example/wp-json/seocart/v1/webhooks/second/test', self::INSTALL, 'test' );
		$this->provider->hold( 'we_live', WebhookAddress::url( 'second', Mode::Live ), self::INSTALL, 'live' );

		$command = $this->command();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertContains( 'Webhook endpoint we_1: created. Its signing secret is saved.', $command->lines );
		$this->assertContains( '1 webhook endpoint of this site for test mode at another address was left alone: if the site has moved, delete it in the provider\'s dashboard.', $command->lines );

		GatewayKernel::alterStored( 'second', Mode::Test, GatewayDescriptor::WEBHOOK_SECRET, static fn(): ?string => null );

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertSame( array( 'we_other_install', 'we_copy', 'we_live', 'we_2' ), array_keys( $this->provider->endpoints ), 'Only the site\'s own endpoint was replaced.' );
		$this->assertSame( array( ProvisioningGateway::API . '/we_1' ), array_column( array_filter( $this->provider->requests, static fn( array $request ): bool => 'DELETE' === $request['method'] ), 'url' ) );
	}

	/**
	 * Tests that in Safe Mode a live set-up is refused before the file is read or anything written, that the live settings alone can be saved, and that a test set-up goes on.
	 *
	 * @since 0.2.0
	 */
	public function test_safe_mode_refuses_a_live_set_up_before_anything(): void {
		$safe    = GatewayKernel::request( $this->kernel, $this->db, $this->reporter(), $this->publisherOver( $this->db ), array( SafeMode::class => static fn( Container $c ): SafeMode => new SafeMode( $c->get( BootOption::class ), $c->get( Clock::class ), true ) ) );
		$command = new GatewayCommandRecorder( $safe );
		$admin   = $this->userWithRole( 'administrator' )->userId();

		// A file that does not exist: refused for Safe Mode, it is never opened.
		$this->assertSame(
			GatewayCommand::EXIT_FAILED,
			$command->run(
				array( 'configure', 'second' ),
				array(
					'mode'      => 'live',
					'from-file' => '/nonexistent/second-live.json',
				),
				$admin
			)
		);
		$this->assertStringStartsWith( 'payment.gateway_unavailable:', $command->last() );
		$this->assertStringContainsString( 'safe_mode', $command->last() );
		$this->assertSame( array(), $this->provider->requests, 'No live call.' );
		$this->assertSame( array(), GatewayKernel::storedDocument( 'second' ), 'Nothing was written.' );

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Live, array( 'webhooks' => false ) ) );
		$this->assertSame( 'Webhook endpoint: skipped.', $command->lines[ count( $command->lines ) - 2 ] ?? '' );
		$this->assertSame( array(), $this->provider->requests, 'Saving the live settings alone calls nothing.' );

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertSame( array( 'GET', 'POST' ), $this->provider->methods(), 'A test set-up goes on in Safe Mode.' );
	}

	/**
	 * Tests that a provider that fails leaves the settings saved and the signing secret absent, reports the step failed, and prints and logs no card number and no credential.
	 *
	 * @since 0.2.0
	 */
	public function test_a_provider_failure_leaves_the_settings_saved_and_reports_the_step(): void {
		$this->provider->failCreation = 'card 4242 4242 4242 4242 cannot be used with the key sk_test_planted';

		$command = $this->command();

		$this->assertSame( GatewayCommand::EXIT_ATTENTION, $this->configure( $command, Mode::Test ) );

		$document = GatewayKernel::storedDocument( 'second' );

		$this->assertSame( 1, $document['version'] ?? null, 'The settings were written once.' );
		$this->assertStringStartsWith( 'v1:', (string) ( $document['values'][ GatewayKernel::name( 'second', Mode::Test, 'secret_key' ) ] ?? '' ), 'The credential is saved, sealed.' );
		$this->assertArrayNotHasKey( GatewayKernel::name( 'second', Mode::Test, GatewayDescriptor::WEBHOOK_SECRET ), $document['values'] ?? array(), 'No signing secret is saved.' );
		$this->assertSame( 'Webhook endpoint: failed. The provider did not set up the endpoint (SEOCart\Contracts\Payment\GatewayUnavailable). The settings are saved; run configure again to retry.', $command->lines[1] ?? '' );
		$this->assertContains( 'second takes no payment in test mode until its test settings are complete (they are missing).', $command->lines );
		$this->assertCount( 1, $this->logContexts( GatewayConfiguration::PROVISIONING_FAILED ) );

		foreach ( array( '4242', 'sk_test_planted' ) as $value ) {
			$this->assertStringNotContainsString( $value, $command->printed() . $this->logText(), 'A card number or a credential was printed or logged.' );
		}
	}

	/**
	 * Tests that a signing secret entered by hand forgets the endpoint's id, so the next set-up replaces the endpoint created before instead of saying the hand-entered secret verifies it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_secret_entered_by_hand_is_never_taken_for_an_endpoints(): void {
		$command = $this->command();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertSame( 'we_1', $this->storedEndpoint( Mode::Test ) );

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test, array( 'webhooks' => false ), array( GatewayDescriptor::WEBHOOK_SECRET => 'whsec_entered_by_hand' ) ) );
		$this->assertSame( 'whsec_entered_by_hand', $this->openedSecret( Mode::Test ) );

		$forgotten                = $this->storedEndpoint( Mode::Test );
		$this->provider->requests = array();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertNotContains( 'Webhook endpoint we_1: reused. The signing secret saved before still verifies its deliveries.', $command->lines, 'The hand-entered secret was said to verify the endpoint created before.' );
		$this->assertNull( $forgotten, 'A secret entered by hand is no endpoint\'s this site set up.' );
		$this->assertSame( array( 'GET', 'DELETE', 'POST' ), $this->provider->methods(), 'The endpoint created before is replaced.' );
		$this->assertSame( array( 'we_2' ), array_keys( $this->provider->endpoints ) );
		$this->assertSame( array( $this->provider->endpoints['we_2']['secret'], 'we_2' ), array( $this->openedSecret( Mode::Test ), $this->storedEndpoint( Mode::Test ) ) );
		$this->assertContains( 'Webhook endpoint we_2: replaced. Its signing secret is saved.', $command->lines );
	}

	/**
	 * Tests that only the endpoint whose secret the site holds is reused: a gateway that says it reused another fails the step and nothing is saved, and one that follows the contract replaces the other.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_endpoint_whose_secret_is_held_is_reused(): void {
		$command = $this->command();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );

		// The endpoint was deleted in the provider's dashboard, and another made there for this site's address and tag.
		unset( $this->provider->endpoints['we_1'] );
		$this->provider->hold( 'we_dashboard', WebhookAddress::url( 'second', Mode::Test ), self::INSTALL, 'test' );

		$gateway = $this->kernel->get( Gateways::class )->provisioner( 'second', Mode::Test );

		$this->assertInstanceOf( ProvisioningGateway::class, $gateway );

		$held                       = $this->openedSecret( Mode::Test );
		$gateway->ignoresEndpointId = true;

		$this->assertSame( GatewayCommand::EXIT_ATTENTION, $this->configure( $command, Mode::Test ), 'An endpoint whose signing secret this site does not hold was taken as reused.' );
		$this->assertSame( 'Webhook endpoint: failed. The gateway kept the endpoint we_dashboard, whose signing secret this site does not hold, so its deliveries could not be verified. Run configure again.', $command->lines[ count( $command->lines ) - 1 ] );
		$this->assertSame( array( $held, 'we_1' ), array( $this->openedSecret( Mode::Test ), $this->storedEndpoint( Mode::Test ) ), 'Nothing was saved for the endpoint reused.' );

		$gateway->ignoresEndpointId = false;
		$this->provider->requests   = array();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertSame( array( 'GET', 'DELETE', 'POST' ), $this->provider->methods() );
		$this->assertSame( array( 'we_2' ), array_keys( $this->provider->endpoints ), 'The endpoint whose secret the site does not hold is replaced.' );
		$this->assertSame( 'we_2', $this->storedEndpoint( Mode::Test ) );
	}

	/**
	 * Tests that a signing secret that no longer opens is held by no one: altered after its header, it still names a key the site holds, yet the next set-up replaces its endpoint and saves a fresh secret instead of saying the old one still verifies.
	 *
	 * @since 0.2.0
	 */
	public function test_a_signing_secret_that_does_not_open_is_replaced(): void {
		$command = $this->command();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );

		$first = $this->provider->endpoints['we_1']['secret'];

		GatewayKernel::alterStored( 'second', Mode::Test, GatewayDescriptor::WEBHOOK_SECRET, static fn( string $sealed ): string => substr( $sealed, 0, -12 ) . str_repeat( 'A', 12 ) );

		$this->assertSame( 'we_1', $this->storedEndpoint( Mode::Test ), 'The endpoint\'s id stays beside the damaged secret.' );
		$this->assertTrue( $this->kernel->get( SecretVault::class )->isHeld( $this->storedSecret( Mode::Test ) ), 'The damaged secret still names a key the site holds.' );

		try {
			$this->openedSecret( Mode::Test );
			$this->fail( 'The damaged secret opened.' );
		} catch ( CredentialUnavailable $unopened ) {
			$this->assertSame( CredentialUnavailable::UNREADABLE, $unopened->reason, 'The damaged secret does not open.' );
		}

		$this->provider->requests = array();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );
		$this->assertNotContains( 'Webhook endpoint we_1: reused. The signing secret saved before still verifies its deliveries.', $command->lines, 'A signing secret that does not open was said to verify the endpoint.' );
		$this->assertSame( array( 'GET', 'DELETE', 'POST' ), $this->provider->methods(), 'The endpoint is replaced.' );
		$this->assertSame( array( 'we_2' ), array_keys( $this->provider->endpoints ) );
		$this->assertContains( 'Webhook endpoint we_2: replaced. Its signing secret is saved.', $command->lines );
		$this->assertSame( array( $this->provider->endpoints['we_2']['secret'], 'we_2' ), array( $this->openedSecret( Mode::Test ), $this->storedEndpoint( Mode::Test ) ), 'A fresh secret that opens is saved with its endpoint\'s id.' );

		foreach ( array( $first, $this->provider->endpoints['we_2']['secret'] ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $command->printed() . $this->logText(), 'A secret was printed or logged.' );
		}
	}

	/**
	 * Tests that a provider failure whose message quotes the signing secret this site holds and a card number is reported by its class alone: neither is printed or logged, and the secret held stays.
	 *
	 * @since 0.2.0
	 */
	public function test_a_provider_failure_is_reported_without_its_message(): void {
		$command = $this->command();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ) );

		$held = $this->openedSecret( Mode::Test );

		$this->provider->failListing = sprintf( 'the endpoint we_1 signs with %s; card 4242 4242 4242 4242 was declined', $held );

		$this->assertSame( GatewayCommand::EXIT_ATTENTION, $this->configure( $command, Mode::Test ) );
		$this->assertSame( 'Webhook endpoint: failed. The provider did not set up the endpoint (SEOCart\Contracts\Payment\GatewayUnavailable). The settings are saved; run configure again to retry.', $command->lines[ count( $command->lines ) - 1 ] );
		$this->assertSame( $held, $this->openedSecret( Mode::Test ), 'The signing secret held before stays.' );
		$this->assertCount( 1, $this->logContexts( GatewayConfiguration::PROVISIONING_FAILED ) );

		foreach ( array( $held, '4242' ) as $value ) {
			$this->assertStringNotContainsString( $value, $command->printed() . $this->logText(), 'The provider\'s message was printed or logged.' );
		}
	}

	/**
	 * Tests that every call to the provider is made outside any transaction, and that a set-up asked for inside one is refused before anything is written or sent.
	 *
	 * @since 0.2.0
	 */
	public function test_every_call_to_the_provider_is_made_outside_any_transaction(): void {
		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $this->command(), Mode::Test ) );
		$this->assertNotSame( array(), $this->provider->requests );
		$this->assertSame( array( 0 ), array_values( array_unique( array_column( $this->provider->requests, 'depth' ) ) ), 'Every call at transaction depth 0.' );

		$configuration = $this->kernel->get( GatewayConfiguration::class );
		$actor         = Actor::system( 'cli', $this->userWithRole( 'administrator' )->userId() );
		$requests      = count( $this->provider->requests );
		$version       = GatewayKernel::storedDocument( 'second' )['version'] ?? null;

		try {
			$this->db->transaction( static fn() => $configuration->configure( 'second', Mode::Test, self::values(), true, $actor ) );
			$this->fail( 'The provider was asked inside a transaction.' );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( 'outside any transaction', $refused->getMessage() );
		}

		$this->assertCount( $requests, $this->provider->requests, 'Nothing was sent.' );
		$this->assertSame( $version, GatewayKernel::storedDocument( 'second' )['version'] ?? null, 'Nothing was written.' );
	}

	/**
	 * Declares `second`: test and live modes, a secret key, an account country and the webhook signing secret, and the provider's host.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	private static function descriptor(): GatewayDescriptor {
		return DeclaredGateway::descriptor(
			'second',
			array( Mode::Test, Mode::Live ),
			array(
				new FieldSpec( name: 'secret_key', type: FieldType::String, description: 'The provider\'s secret key.', label: static fn(): string => 'Secret key', example: 'sk_test_x', privacy: Privacy::Secret ),
				new FieldSpec( name: GatewayDescriptor::ACCOUNT_COUNTRY, type: FieldType::String, description: 'The account\'s country.', label: static fn(): string => 'Account country', example: 'US', max_length: 2 ),
				new FieldSpec( name: GatewayDescriptor::WEBHOOK_SECRET, type: FieldType::String, description: 'The webhook endpoint\'s signing secret.', label: static fn(): string => 'Webhook signing secret', example: 'whsec_x', privacy: Privacy::Secret ),
			),
			null,
			null,
			PaymentGateway::CONTRACT_VERSION,
			array( DeclaredGateway::host( 'provider' ) )
		);
	}

	/**
	 * Returns the settings a configure file gives.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The values, by declared name.
	 */
	private static function values(): array {
		return array(
			'secret_key'                       => 'sk_test_planted',
			GatewayDescriptor::ACCOUNT_COUNTRY => 'US',
		);
	}

	/**
	 * Returns the owner tag of this site's endpoints for a mode.
	 *
	 * @since 0.2.0
	 *
	 * @param string $mode The mode.
	 * @return array<string, string> The metadata.
	 */
	private static function tag( string $mode ): array {
		return array(
			'seocart_install' => self::INSTALL,
			'seocart_mode'    => $mode,
		);
	}

	/**
	 * Returns the command, built from the kernel.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayCommandRecorder The command, recording what it prints.
	 */
	private function command(): GatewayCommandRecorder {
		return new GatewayCommandRecorder( $this->kernel );
	}

	/**
	 * Runs configure for a mode as an administrator, from a file only its owner can read.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayCommandRecorder     $command The command.
	 * @param Mode                       $mode    The mode.
	 * @param array<string, string|bool> $options Optional. Further options. Default none.
	 * @param array<string, string>      $values  Optional. Settings the file gives besides the usual ones, by declared name. Default none.
	 * @return int The exit code.
	 */
	private function configure( GatewayCommandRecorder $command, Mode $mode, array $options = array(), array $values = array() ): int {
		$file = (string) wp_tempnam( 'seocart-gateway' );

		$this->files[] = $file;

		file_put_contents( $file, (string) wp_json_encode( $values + self::values() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test's own temporary file.
		chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The owner's alone, as configure asks.

		return $command->run(
			array( 'configure', 'second' ),
			$options + array(
				'mode'      => $mode->value,
				'from-file' => $file,
			),
			$this->userWithRole( 'administrator' )->userId()
		);
	}

	/**
	 * Returns a mode's signing secret as the document stores it.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return string The stored text; empty when there is none.
	 */
	private function storedSecret( Mode $mode ): string {
		return (string) ( GatewayKernel::storedDocument( 'second' )['values'][ GatewayKernel::name( 'second', $mode, GatewayDescriptor::WEBHOOK_SECRET ) ] ?? '' );
	}

	/**
	 * Returns the id kept beside a mode's signing secret: the endpoint the secret is for.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return string|null The id; null when none is kept.
	 */
	private function storedEndpoint( Mode $mode ): ?string {
		$id = GatewayKernel::storedDocument( 'second' )['values'][ GatewayKernel::name( 'second', $mode, GatewayDescriptor::WEBHOOK_ENDPOINT ) ] ?? null;

		return null === $id ? null : (string) $id;
	}

	/**
	 * Opens a mode's signing secret, as the gateway reads it.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return string The secret.
	 */
	private function openedSecret( Mode $mode ): string {
		wp_cache_flush();

		return $this->kernel->get( Gateways::class )->context( 'second' )->settings( $mode )->secret( GatewayDescriptor::WEBHOOK_SECRET );
	}

	/**
	 * Returns the contexts of the log's lines under a code.
	 *
	 * @since 0.2.0
	 *
	 * @param string $code The code.
	 * @return list<array<string, mixed>> The contexts.
	 */
	private function logContexts( string $code ): array {
		return array_map( static fn( array $line ): array => (array) json_decode( (string) $line['context_json'], true ), $this->db->fetchAll( 'SELECT context_json FROM %i WHERE machine_code = %s', $this->db->table( LogsTable::NAME ), $code ) );
	}

	/**
	 * Returns everything the log holds, as one text.
	 *
	 * @since 0.2.0
	 *
	 * @return string The log.
	 */
	private function logText(): string {
		return (string) wp_json_encode( $this->db->fetchAll( 'SELECT machine_code, message, context_json FROM %i', $this->db->table( LogsTable::NAME ) ) );
	}
}

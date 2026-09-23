<?php
/**
 * SecretSettingsSurfacesTest: a secret written through the settings operations never comes back out
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\SecretsHarness;
use WP_REST_Request;

/**
 * The settings operations over the fixture secrets, wired on the REST route and the command the way
 * the kernel will wire them, with the vault sealing and real keys committed to the database.
 *
 * Every output a client can get — each REST response with its status and body, the schema an
 * OPTIONS request describes, each command's printed item and failure line, the service's answers,
 * and whatever the invoker and the translator report — is gathered and searched for the plain
 * text, and for the sealed form, of every secret written. A secret is absent from every read, not
 * null: the field is not there.
 *
 * The test commits, so it runs as a DatabaseTestCase: no WordPress factories. The acting user is
 * an administrator the test creates and deletes, whose plugin capabilities a `user_has_cap` filter
 * sets to exactly the ones a test grants. Not the site's first user: on a network that user is a
 * super admin, whom WordPress grants every capability before the filter runs.
 *
 * @since 0.1.0
 */
final class SecretSettingsSurfacesTest extends DatabaseTestCase {

	use CreatesUsers;

	/**
	 * The secrets the tests write.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const SECRETS = array( 'sk_live_surface_8Hq3Vn6Rt', 'whsec_surface_2Mx9Kd4Pz' );

	/**
	 * The module under the service.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretsHarness
	 */
	private SecretsHarness $secrets;

	/**
	 * The service every surface runs.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * The surfaces.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * The acting user's id.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $userId = 0;

	/**
	 * The plugin capabilities the acting user holds.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $granted = array();

	/**
	 * Every output a client received, as text.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $outputs = array();

	/**
	 * Wires the operations over the fixture secrets, with a key, as the settings manager.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		SecretsHarness::removeAll();
		SecretsHarness::createRegistry( $this->db );

		$this->secrets = new SecretsHarness( $this->db );
		$this->service = new SettingsService( $this->secrets->registry, $this->secrets->store, new Authorizer( new CapabilityDeclaration() ), $this->secrets->vault, $this->db );

		$registry = new OperationRegistry();

		$registry->add( SettingsOperations::GET, fn() => SettingsOperations::get( $this->secrets->registry ) );
		$registry->add( SettingsOperations::UPDATE, fn() => SettingsOperations::update( $this->secrets->registry ) );

		$this->surfaces = new OperationSurfaces( $registry, $this->service );
		$this->granted  = array( 'seocart_manage_settings', 'seocart_manage_secrets' );
		$this->outputs  = array();

		add_filter(
			'user_has_cap',
			function ( array $capabilities ): array {
				foreach ( array_keys( $capabilities ) as $capability ) {
					if ( str_starts_with( (string) $capability, 'seocart_' ) ) {
						unset( $capabilities[ $capability ] );
					}
				}

				foreach ( $this->granted as $capability ) {
					$capabilities[ $capability ] = true;
				}

				return $capabilities;
			}
		);

		$this->userId = $this->createUser( 'administrator' );

		wp_set_current_user( $this->userId );

		$this->secrets->keys->initialize();
	}

	/**
	 * Discards the surfaces and removes what the test wrote, the acting user among it.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();
		SecretsHarness::removeAll();
		wp_set_current_user( 0 );
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a secret written on every surface is stored sealed and is absent from every read.
	 *
	 * Planted violation: in SettingsService::get(), return every exposed setting, secrets included,
	 * and in ResourceSchema::serialize(), serialize a secret field like any other. The sealed value
	 * then reaches the reads, and the test finds the field and its sealed form.
	 *
	 * @since 0.1.0
	 */
	public function test_a_secret_written_through_the_settings_operations_is_absent_from_every_read(): void {
		$patch = $this->rest(
			'PATCH',
			array(
				'api_key'     => self::SECRETS[0],
				'weight_unit' => 'lb',
			)
		);

		$this->assertSame( 200, $patch->get_status() );
		$this->assertSame( 'lb', $patch->get_data()['weight_unit'] ?? null );
		$this->assertSecretsAbsent( $patch->get_data() );

		$command = $this->cli( 'seocart settings update', array( 'webhook_secret' => self::SECRETS[1] ) );

		$this->assertNull( $command['failure'] );
		$this->assertSecretsAbsent( $command['printed']['item'] ?? array() );

		$this->assertSame( self::SECRETS[0], $this->secrets->vault->reveal( 'api_key' ), 'The secret written on the route was not stored.' );
		$this->assertSame( self::SECRETS[1], $this->secrets->vault->reveal( 'webhook_secret' ), 'The secret written by the command was not stored.' );

		$read = $this->rest( 'GET', array() );

		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( 'lb', $read->get_data()['weight_unit'] ?? null, 'The read did not return the settings, so its lack of secrets proves nothing.' );
		$this->assertSecretsAbsent( $read->get_data() );

		$get = $this->cli( 'seocart settings get', array() );

		$this->assertSecretsAbsent( $get['printed']['item'] ?? array() );

		$answer = $this->service->update( array( 'api_key' => self::SECRETS[0] ), Actor::user( $this->userId ) );

		$this->outputs[] = (string) wp_json_encode( $answer );

		$this->assertSecretsAbsent( $answer );
		$this->assertSecretsAbsent( $this->service->get( array(), Actor::user( $this->userId ) ) );

		$schema          = rest_do_request( new WP_REST_Request( 'OPTIONS', '/seocart/v1/settings' ) )->get_data();
		$this->outputs[] = (string) wp_json_encode( $schema );

		$this->assertArrayNotHasKey( 'api_key', $schema['schema']['properties'] ?? array(), 'The resource schema describes a secret.' );

		$this->assertNothingPrintedASecret();
	}

	/**
	 * Tests that a secret write refused for want of the secrets capability stores nothing and prints no secret.
	 *
	 * Refused on the route, by the command and by the service; the ordinary setting sent with it is
	 * not saved either.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_secret_write_stores_nothing_and_prints_no_secret(): void {
		$this->granted = array( 'seocart_manage_settings' );

		$patch = $this->rest(
			'PATCH',
			array(
				'api_key'     => self::SECRETS[0],
				'weight_unit' => 'lb',
			)
		);

		$this->assertSame( 403, $patch->get_status() );
		$this->assertSame( 'authorization.denied', $patch->as_error()->get_error_code() );

		$command = $this->cli( 'seocart settings update', array( 'api_key' => self::SECRETS[1] ) );

		$this->assertStringStartsWith( 'authorization.denied: ', (string) $command['failure'] );

		try {
			$this->service->update( array( 'api_key' => self::SECRETS[0] ), Actor::user( $this->userId ) );
			$this->fail( 'The service stored a secret for a user who may not manage secrets.' );
		} catch ( CodedException $refused ) {
			$this->outputs[] = $refused->getMessage() . ' ' . wp_json_encode( $refused->context() );
		}

		$this->assertNull( SecretsHarness::stored( 'seocart_fixture_gateway_api_key' ) );
		$this->assertNull( SecretsHarness::stored( 'seocart_fixture_scalars_weight_unit' ), 'The ordinary setting sent with the refused secret was saved.' );
		$this->assertNothingPrintedASecret();
	}

	/**
	 * Tests that a secret that does not fit its field, or cannot be sealed, is refused without being printed.
	 *
	 * @since 0.1.0
	 */
	public function test_a_secret_that_cannot_be_stored_is_refused_without_being_printed(): void {
		$long = str_repeat( 'k', 61 );

		$too_long = $this->rest( 'PATCH', array( 'api_key' => $long ) );

		$this->assertSame( 400, $too_long->get_status() );
		$this->assertStringNotContainsString( $long, (string) wp_json_encode( $too_long->get_data() ) );

		SecretsHarness::tamper(
			SecretsHarness::DATA_KEYS_OPTION,
			(string) wp_json_encode(
				array(
					'version' => 9,
					'values'  => (object) array(),
				)
			)
		);

		$unsealable = $this->rest( 'PATCH', array( 'api_key' => self::SECRETS[0] ) );

		$this->assertSame( 500, $unsealable->get_status() );
		$this->assertSame( 'secrets.keys_damaged', $unsealable->as_error()->get_error_code() );

		$command = $this->cli( 'seocart settings update', array( 'webhook_secret' => self::SECRETS[1] ) );

		$this->assertStringStartsWith( 'secrets.keys_damaged: ', (string) $command['failure'] );
		$this->assertNull( SecretsHarness::stored( 'seocart_fixture_gateway_api_key' ) );
		$this->assertNull( SecretsHarness::stored( 'seocart_fixture_gateway_webhook_secret' ) );
		$this->assertNothingPrintedASecret();
	}

	/**
	 * Sends a request to the settings route and records the response.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method The method.
	 * @param array<string, mixed> $body   The body.
	 * @return \WP_REST_Response The response.
	 */
	private function rest( string $method, array $body ): \WP_REST_Response {
		$response = $this->surfaces->rest( $method, SettingsOperations::ROUTE, $body );

		$this->outputs[] = $response->get_status() . ' ' . wp_json_encode( $response->get_data() ) . ' ' . wp_json_encode( $response->get_headers() );

		return $response;
	}

	/**
	 * Runs a settings command and records what it printed and reported.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $name    The command.
	 * @param array<string, string> $options The options.
	 * @return array{printed: array{item: array<string, mixed>, format: string}|null, failure: string|null} The outcome.
	 */
	private function cli( string $name, array $options ): array {
		$outcome = $this->surfaces->cli( $name, array(), $options );

		$this->outputs[] = (string) wp_json_encode( $outcome );

		return $outcome;
	}

	/**
	 * Asserts that an output carries no secret field.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $output The output.
	 */
	private function assertSecretsAbsent( mixed $output ): void {
		$this->assertIsArray( $output );
		$this->assertArrayNotHasKey( 'api_key', $output );
		$this->assertArrayNotHasKey( 'webhook_secret', $output );
	}

	/**
	 * Asserts that no output, report or log holds a secret, plain or sealed.
	 *
	 * @since 0.1.0
	 */
	private function assertNothingPrintedASecret(): void {
		foreach ( $this->surfaces->reported as $failure ) {
			$this->outputs[] = get_class( $failure ) . ' ' . $failure->getMessage();
		}

		foreach ( $this->surfaces->reportedInternal as $report ) {
			$this->outputs[] = $report['error']->getMessage() . ' ' . wp_json_encode( $report['error']->context() );
		}

		$text = implode( "\n", $this->outputs );

		foreach ( array( 'seocart_fixture_gateway_api_key', 'seocart_fixture_gateway_webhook_secret' ) as $option ) {
			$sealed = SecretsHarness::stored( $option );

			if ( null !== $sealed ) {
				$this->assertStringNotContainsString( $sealed, $text, "The sealed value of {$option} reached a client." );
			}

			$this->assertStringNotContainsString( self::SECRETS[0], (string) $sealed );
			$this->assertStringNotContainsString( self::SECRETS[1], (string) $sealed );
		}

		$this->assertGreaterThan( 1, count( $this->outputs ), 'Too little was gathered for the search to prove anything.' );

		foreach ( self::SECRETS as $secret ) {
			$this->assertStringNotContainsString( $secret, $text, 'A secret reached a client.' );
		}
	}
}

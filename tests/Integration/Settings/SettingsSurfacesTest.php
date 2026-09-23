<?php
/**
 * Tests the settings operations on their REST route and their command, against the service
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Settings;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Settings\InternationalSettings;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\OperationSurfaceWalker;
use SEOCart\Tests\Support\RoutePermissionWalker;
use WP_Ability;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The two settings operations wired the way the kernel will wire them, driven through the REST
 * route, the WP-CLI command and the service with the same input, for the same user.
 *
 * Settings have no ability, so the parity is between two surfaces and the service: the same
 * output for the same read, the same stored value after the same change, the same error code for
 * the same refused value, the same refusal for a user without the settings capability. The walks
 * that gate every plugin route and command run over the same wiring, and WordPress's own settings
 * endpoint is shown to carry nothing of the plugin's.
 *
 * @since 0.1.0
 */
final class SettingsSurfacesTest extends WP_UnitTestCase {

	/**
	 * The three surfaces, wired for the settings operations.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * The service every surface runs.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * The store behind the service.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * Wires the settings operations on every surface.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		$this->store    = new SettingsStore(
			Settings::registry(),
			new Database(
				$wpdb,
				true,
				static function ( string $code ): void {
					throw new \LogicException( 'The database wrapper reported ' . $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test failure, never rendered.
				}
			)
		);
		$this->service  = new SettingsService( Settings::registry(), $this->store );
		$this->surfaces = new OperationSurfaces( self::registry(), $this->service );
	}

	/**
	 * Discards the REST server and the Abilities registries.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests that a read answers the same on the route, the command and the service.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_is_the_same_everywhere(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_settings' ) ) );

		$expected = array( 'base_currency' => 'USD' );
		$response = $this->surfaces->rest( 'GET', '/settings' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected, $response->get_data() );
		$this->assertSame(
			array(
				'printed' => array(
					'item'   => $expected,
					'format' => 'table',
				),
				'failure' => null,
			),
			$this->surfaces->cli( 'seocart settings get', array(), array() )
		);
		$this->assertSame( $expected, $this->service->get( array(), self::actor() ) );
	}

	/**
	 * Tests that a change is stored and answered the same way from the route, the command and the service.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_a_change_is_the_same_everywhere(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_settings' ) ) );

		$response = $this->surfaces->rest( 'PATCH', '/settings', array( 'base_currency' => 'EUR' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'base_currency' => 'EUR' ), $response->get_data() );
		$this->assertSame( 'EUR', $this->storedBaseCurrency() );

		$command = $this->surfaces->cli( 'seocart settings update', array(), array( 'base_currency' => 'GBP' ) );

		$this->assertNull( $command['failure'] );
		$this->assertSame( array( 'base_currency' => 'GBP' ), $command['printed']['item'] ?? null );
		$this->assertSame( 'GBP', $this->storedBaseCurrency() );

		$this->assertSame( array( 'base_currency' => 'JPY' ), $this->service->update( array( 'base_currency' => 'JPY' ), self::actor() ) );
		$this->assertSame( 'JPY', $this->storedBaseCurrency() );

		$this->assertSame( array( 'base_currency' => 'JPY' ), $this->surfaces->rest( 'GET', '/settings' )->get_data(), 'A read does not see the last change.' );
	}

	/**
	 * Tests that a change that names no setting changes nothing and answers with the settings.
	 *
	 * Planted violation: in SettingsOperations::field(), pass
	 * `default_value: $required ? null : $field->defaultValue()`. The route then fills in the
	 * default for the absent setting and overwrites EUR with USD.
	 *
	 * @since 0.1.0
	 */
	public function test_a_change_that_names_no_setting_changes_nothing(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_settings' ) ) );

		$this->store->writeScalars( array( InternationalSettings::BASE_CURRENCY => 'EUR' ) );

		$response = $this->surfaces->rest( 'PATCH', '/settings', array( 'unrelated' => 'x' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'base_currency' => 'EUR' ), $response->get_data() );
		$this->assertSame( array( 'base_currency' => 'EUR' ), $this->surfaces->cli( 'seocart settings update', array(), array() )['printed']['item'] ?? null );
		$this->assertSame( 'EUR', $this->storedBaseCurrency() );
	}

	/**
	 * Tests that an unknown currency is refused with the same code everywhere, and nothing is stored.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_an_unknown_currency_is_refused_the_same_everywhere(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_settings' ) ) );

		$response = $this->surfaces->rest( 'PATCH', '/settings', array( 'base_currency' => 'XYZ' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'currency.unknown', $response->as_error()->get_error_code() );

		$command = $this->surfaces->cli( 'seocart settings update', array(), array( 'base_currency' => 'XYZ' ) );

		$this->assertNull( $command['printed'] );
		$this->assertStringStartsWith( 'currency.unknown: ', (string) $command['failure'] );

		try {
			$this->service->update( array( 'base_currency' => 'XYZ' ), self::actor() );
			$this->fail( 'The service stored an unknown currency.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( SupportError::UnknownCurrency, $refused->errorCode() );
		}

		$this->assertNull( $this->storedBaseCurrency(), 'A refused change was stored.' );
		$this->assertSame( array(), $this->surfaces->reported, 'A refusal the client caused was reported as an internal failure.' );
	}

	/**
	 * Tests that a value of the wrong type is refused by the route's schema before the service runs.
	 *
	 * @since 0.1.0
	 */
	public function test_a_value_of_the_wrong_type_is_refused_by_the_schema(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_settings' ) ) );

		$response = $this->surfaces->rest( 'PATCH', '/settings', array( 'base_currency' => 978 ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
		$this->assertNull( $this->storedBaseCurrency() );
	}

	/**
	 * Tests that a user without the settings capability is refused on every surface, and a visitor too.
	 *
	 * @since 0.1.0
	 */
	public function test_a_user_without_the_settings_capability_is_refused_everywhere(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_inventory', 'seocart_manage_secrets' ) ) );

		$this->assertSame( 403, $this->surfaces->rest( 'GET', '/settings' )->get_status() );
		$this->assertSame( 403, $this->surfaces->rest( 'PATCH', '/settings', array( 'base_currency' => 'EUR' ) )->get_status() );
		$this->assertStringStartsWith( 'rest_forbidden: ', (string) $this->surfaces->cli( 'seocart settings get', array(), array() )['failure'] );
		$this->assertStringStartsWith( 'rest_forbidden: ', (string) $this->surfaces->cli( 'seocart settings update', array(), array( 'base_currency' => 'EUR' ) )['failure'] );

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->surfaces->rest( 'GET', '/settings' )->get_status() );
		$this->assertSame( 401, $this->surfaces->rest( 'PATCH', '/settings', array( 'base_currency' => 'EUR' ) )->get_status() );

		$this->assertNull( $this->storedBaseCurrency(), 'A refused user changed a setting.' );
	}

	/**
	 * Tests that the route describes the settings resource to an OPTIONS request.
	 *
	 * @since 0.1.0
	 */
	public function test_the_route_serves_the_settings_schema(): void {
		$response = rest_do_request( new WP_REST_Request( 'OPTIONS', '/seocart/v1/settings' ) );
		$data     = $response->get_data();

		$this->assertSame( array( 'GET', 'PATCH' ), $data['methods'] ?? null );
		$this->assertSame( 'Settings', $data['schema']['title'] ?? null );
		$this->assertSame( array( 'base_currency' ), array_keys( $data['schema']['properties'] ?? array() ) );
	}

	/**
	 * Tests that the settings routes pass the permission walk that gates every plugin route.
	 *
	 * @since 0.1.0
	 */
	public function test_the_settings_routes_pass_the_route_walker(): void {
		$walk = ( new RoutePermissionWalker() )->walk( $this->surfaces->server() );

		$this->assertContains( '/seocart/v1/settings', $walk['plugin_routes'], 'The walk did not visit the settings route, so a clean result would prove nothing.' );
		$this->assertSame( array(), $walk['violations'], RoutePermissionWalker::describe( $walk['violations'] ) );
	}

	/**
	 * Tests that every settings route and command resolves to its operation, and the other way round.
	 *
	 * @since 0.1.0
	 */
	public function test_every_settings_surface_resolves_to_its_operation(): void {
		$registry = self::registry();
		$names    = array_values( array_map( static fn( WP_Ability $ability ): string => $ability->get_name(), wp_get_abilities() ) );

		$this->assertSame( array( 'seocart settings get', 'seocart settings update' ), array_keys( $this->surfaces->commands ) );
		$this->assertSame( array(), OperationSurfaceWalker::restViolations( $this->surfaces->server(), $registry ) );
		$this->assertSame( array(), OperationSurfaceWalker::commandViolations( array_keys( $this->surfaces->commands ), $registry, array() ) );
		$this->assertSame( array(), OperationSurfaceWalker::abilityViolations( $names, $registry ) );
		$this->assertSame( array(), array_filter( $names, static fn( string $name ): bool => str_starts_with( $name, 'seocart/' ) ), 'A settings operation registered an ability.' );
	}

	/**
	 * Tests that WordPress's own settings endpoint neither shows nor changes a plugin setting.
	 *
	 * The endpoint checks `manage_options` alone, so an administrator's request is the one that
	 * would reveal a registered setting.
	 *
	 * Planted violation: in SettingsStore::__construct(), add
	 * `register_setting( 'general', 'seocart_international_base_currency', array( 'show_in_rest' => true, 'type' => 'string' ) );`.
	 * The endpoint then lists it and lets the administrator change it.
	 *
	 * @since 0.1.0
	 */
	public function test_wordpress_settings_endpoint_carries_nothing_of_the_plugin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->store->writeScalars( array( InternationalSettings::BASE_CURRENCY => 'EUR' ) );

		$read = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );

		$this->assertSame( 200, $read->get_status() );
		$this->assertArrayHasKey( 'title', $read->get_data(), 'The endpoint did not answer with the site settings, so a clean result would prove nothing.' );
		$this->assertSame( array(), self::plugins( array_keys( (array) $read->get_data() ) ), 'The settings endpoint shows a plugin setting.' );
		$this->assertSame( array(), self::plugins( array_keys( get_registered_settings() ) ), 'A plugin setting is registered with WordPress.' );

		$write = new WP_REST_Request( 'POST', '/wp/v2/settings' );

		$write->set_body_params(
			array(
				'seocart_international_base_currency' => 'GBP',
				'base_currency'                       => 'GBP',
			)
		);

		rest_do_request( $write );

		$this->assertSame( 'EUR', $this->storedBaseCurrency(), 'The settings endpoint changed a plugin setting.' );
	}

	/**
	 * Returns a registry holding the two settings operations, as the production list adds them.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationRegistry The registry.
	 */
	private static function registry(): OperationRegistry {
		$registry = new OperationRegistry();

		$registry->add( SettingsOperations::GET, array( SettingsOperations::class, 'get' ) );
		$registry->add( SettingsOperations::UPDATE, array( SettingsOperations::class, 'update' ) );

		return $registry;
	}

	/**
	 * Reads the stored base currency from the options table, bypassing every cache.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The stored value, or null when none is stored.
	 */
	private function storedBaseCurrency(): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test reads what was really stored.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'seocart_international_base_currency' ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Keeps the names that belong to the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string> $names Setting names.
	 * @return list<string> The names that start with `seocart` or name the base currency.
	 */
	private static function plugins( array $names ): array {
		return array_values( array_filter( array_map( 'strval', $names ), static fn( string $name ): bool => str_starts_with( $name, 'seocart' ) || InternationalSettings::BASE_CURRENCY === $name ) );
	}

	/**
	 * Returns the actor a surface names for the current user.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor The actor.
	 */
	private static function actor(): Actor {
		return Actor::user( get_current_user_id() );
	}

	/**
	 * Creates a user holding exactly the given capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $capabilities The capabilities.
	 * @return int The user id.
	 */
	private static function user( array $capabilities ): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );

		foreach ( $capabilities as $capability ) {
			$user->add_cap( $capability );
		}

		return $user_id;
	}
}

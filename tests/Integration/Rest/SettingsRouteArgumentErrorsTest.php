<?php
/**
 * Tests that WordPress's own argument errors on the settings route take the one error shape too
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Rest;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Rest\ErrorShape;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_UnitTestCase;

/**
 * Proves the fix in RestErrorTranslator/ErrorShape is not adjust-stock's own: the settings route,
 * wired independently with its own service, gets the same flattened shape for a WordPress schema
 * refusal, with nothing named `details` nesting inside `details`.
 *
 * @since 0.1.0
 */
final class SettingsRouteArgumentErrorsTest extends WP_UnitTestCase {

	/**
	 * The two settings operations, wired the way the kernel wires them.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Wires the settings operations on the REST surface.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		$database = new Database(
			$wpdb,
			true,
			static function ( string $code ): void {
				throw new \LogicException( 'The database wrapper reported ' . $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test failure, never rendered.
			}
		);
		$store    = new SettingsStore( Settings::registry(), $database );
		$keys     = new SecretKeys( Settings::registry(), $store, $database, EncryptionKey::fromValue( null ) );
		$service  = new SettingsService( Settings::registry(), $store, new Authorizer( new CapabilityDeclaration() ), new SecretVault( Settings::registry(), $store, $keys ), $database );
		$registry = new OperationRegistry();

		$registry->add( SettingsOperations::GET, array( SettingsOperations::class, 'get' ) );
		$registry->add( SettingsOperations::UPDATE, array( SettingsOperations::class, 'update' ) );

		$this->surfaces = new OperationSurfaces( $registry, $service );
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
	 * Tests that a WordPress schema refusal on the settings route — a type mismatch, the only kind
	 * the exposed settings can trigger — is flattened the same way as on the adjust-stock route:
	 * `details.params` carries the message, `details.param_codes` carries WordPress's code, and
	 * nothing named `details` nests inside `details`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_type_mismatch_on_the_settings_route_has_the_documented_shape(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->surfaces->rest( 'PATCH', '/settings', array( 'base_currency' => 12345 ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );

		$data = $response->get_data()['data'];

		$this->assertSame( array( 'status', 'details', 'correlation_id' ), array_keys( $data ), 'The data has exactly the three members: none missing, none extra.' );
		$this->assertInstanceOf( \stdClass::class, $data['details'], 'The details are an object, never an array.' );

		$details = (array) $data['details'];

		$this->assertArrayHasKey( 'params', $details );
		$this->assertArrayHasKey( ErrorShape::PARAM_CODES, $details, 'WordPress\'s own details member was not flattened into param_codes.' );
		$this->assertArrayNotHasKey( 'details', $details, 'WordPress\'s own details member nests under our own details, on this route too.' );
		$this->assertSame( 'rest_invalid_type', ( (array) $details[ ErrorShape::PARAM_CODES ] )['base_currency'] ?? null );
	}
}

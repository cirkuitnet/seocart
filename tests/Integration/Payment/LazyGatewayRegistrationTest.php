<?php
/**
 * Tests that nothing but a need for a gateway registers the gateways: not a cart's calculation, the secrets canary or the settings read
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Cart\Application\CartService;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Platform\Secrets\Migrations\CreateSecretKeysMigration;
use SEOCart\Platform\Secrets\SecretsCanary;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

/**
 * The gateway settings documents join the settings registry only when something asks for a gateway's setting or for every setting, so the reads every store request makes, the calculation's international settings, the canary on `admin_init` and the settings operation's read, fire no registration; the vault's census, which must count every gateway's credentials, fires it once.
 *
 * Planted violation, shown red and removed: in SettingsRegistry's constructor, add the late
 * declarations at once (call compose()): the calculation then registers the gateways.
 *
 * @since 0.2.0
 */
final class LazyGatewayRegistrationTest extends PlacementTestCase {

	/**
	 * Creates the key registry the canary reads.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateSecretKeysMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Tests that a cart's calculation, the canary and the settings read register no gateway, and the vault's census registers them once.
	 *
	 * @since 0.2.0
	 */
	public function test_only_a_need_for_a_gateway_registers_the_gateways(): void {
		$cart  = $this->startCart( array( $this->sellable() => 2 ) );
		$fired = did_action( GatewayRegistry::ACTION );

		$this->kernel->get( CartService::class )->calculation( $cart );
		$this->assertSame( $fired, did_action( GatewayRegistry::ACTION ), 'A cart\'s calculation registers no gateway.' );

		// What the kernel's admin_init callback does with the canary.
		$this->kernel->get( SafeMode::class )->recordCanary( $this->kernel->get( SecretsCanary::class )->check()->ok() );
		$this->assertSame( $fired, did_action( GatewayRegistry::ACTION ), 'The canary on admin_init registers no gateway.' );

		$this->kernel->get( SettingsService::class )->get( array(), Actor::user( 0 ) );
		$this->assertSame( $fired, did_action( GatewayRegistry::ACTION ), 'The settings read registers no gateway.' );

		$this->kernel->get( SecretVault::class )->counts();
		$this->kernel->get( SecretVault::class )->counts();
		$this->assertSame( $fired + 1, did_action( GatewayRegistry::ACTION ), 'The vault\'s census registers the gateways, once.' );
	}
}

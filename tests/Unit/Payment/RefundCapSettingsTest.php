<?php
/**
 * Tests the refund caps' settings: which roles are capped, their defaults, and the amounts a cap takes
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\RefundCapSettings;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\Storage;
use SEOCart\Support\Error\CodedException;

/**
 * Each capped role is a role the plugin ships, and grants the refund capability, so a cap can never name a role that does not exist or cannot refund; each role has its two exposed scalars in `refund_caps`, 250.00 of one order and 1000.00 a day; and a cap is an amount in major units with at most six decimals, or empty for none.
 *
 * Planted violation, shown red and removed: in RefundCapSettings::CAPPED_ROLES, rename
 * `seocart_order_agent` to `seocart_order_agents`: the first test fails, where without it a
 * renamed role would leave every order agent uncapped, silently.
 *
 * @since 0.2.0
 */
final class RefundCapSettingsTest extends TestCase {

	/**
	 * Tests that every capped role is shipped and grants the refund capability.
	 *
	 * @since 0.2.0
	 */
	public function test_every_capped_role_is_a_shipped_role_that_refunds(): void {
		$declaration = new CapabilityDeclaration();

		$this->assertNotSame( array(), RefundCapSettings::CAPPED_ROLES );

		foreach ( array_keys( RefundCapSettings::CAPPED_ROLES ) as $role ) {
			$this->assertTrue( $declaration->isShippedRole( $role ), $role . ' is a role the plugin ships.' );
			$this->assertContains( RefundService::CAPABILITY, $declaration->bundle( $role ), $role . ' grants the refund capability.' );
		}
	}

	/**
	 * Tests that each capped role has its two exposed scalars in the group, with their defaults.
	 *
	 * @since 0.2.0
	 */
	public function test_each_cap_is_an_exposed_scalar_with_its_default(): void {
		$settings = array();

		foreach ( RefundCapSettings::settings() as $setting ) {
			$this->assertSame( array( RefundCapSettings::GROUP, Storage::Scalar, true ), array( $setting->group(), $setting->storage(), $setting->isExposed() ), $setting->name() );
			$this->assertSame( array( PaymentError::RefundCapInvalid ), $setting->errors(), $setting->name() );

			$settings[ $setting->name() ] = $setting->field()->defaultValue();
		}

		$this->assertSame(
			array(
				RefundCapSettings::ORDER_AGENT_PER_ORDER => '250.00',
				RefundCapSettings::ORDER_AGENT_PER_DAY   => '1000.00',
			),
			$settings
		);
		$this->assertSame( array_keys( $settings ), array_merge( ...array_values( RefundCapSettings::CAPPED_ROLES ) ), 'Each capped role names its two settings.' );
	}

	/**
	 * Tests that a cap is empty, or an amount in major units that is not negative with at most six decimals, and that anything else is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_cap_is_an_amount_or_empty(): void {
		foreach ( array( '', '0', '250.00', '1000', '0.123456', 1000 ) as $kept ) {
			$this->assertSame( (string) $kept, RefundCapSettings::amount( $kept ) );
		}

		foreach ( array( '-1', '1.1234567', 'ten', ' 250', '2.5e2', '250.', '.5', '1,000.00' ) as $refused ) {
			try {
				RefundCapSettings::amount( $refused );
				$this->fail( 'A cap of "' . $refused . '" was kept.' );
			} catch ( CodedException $error ) {
				$this->assertSame( PaymentError::RefundCapInvalid, $error->errorCode(), $refused );
			}
		}

		$setting = RefundCapSettings::settings()[0];

		$this->assertInstanceOf( Setting::class, $setting );
		$this->assertSame( '75.50', $setting->applyCheck( '75.50' ), 'The setting checks its value with the same rule.' );
	}
}

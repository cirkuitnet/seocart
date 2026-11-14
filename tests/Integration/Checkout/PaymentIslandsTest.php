<?php
/**
 * Tests the payment islands' action: a gateway plugin's island script is enqueued deferred and in the footer on a page that offers its method, and refused for an id no gateway registered
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Interfaces\Storefront\PaymentIslands;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Hooks\EnqueuePaymentIslandsAction;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Payment\TestGateways;
use WP_UnitTestCase;

/**
 * A page that shows the checkout's payment methods fires seocart_enqueue_payment_islands with the methods it offers; a gateway plugin listening to it adds its island's script, which is enqueued as a classic script, deferred and in the footer, for an offered method only; an id no gateway registered with is refused.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentIslands::add(), drop the check of what the page offers: the island of a method the
 *   page does not offer is enqueued;
 * - in PaymentIslands::add(), enqueue without the strategy: the script is not deferred;
 * - in PaymentIslands::add(), drop the check of the registered ids: the mistyped id is enqueued.
 *
 * @since 0.2.0
 */
final class PaymentIslandsTest extends WP_UnitTestCase {

	/**
	 * The handles the tests enqueue.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const HANDLES = array( 'seocart-example-island', 'seocart-stub-island' );

	/**
	 * Where the example gateway's island script is.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SRC = 'https://shop.example/wp-content/plugins/seocart-example/assets/island.js';

	/**
	 * Removes the scripts the test enqueued.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		foreach ( self::HANDLES as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}

		parent::tear_down();
	}

	/**
	 * Tests that a listener's island is enqueued deferred, in the footer, under its handle and version, for the method the page offers, and that the island of a registered gateway the page does not offer is not.
	 *
	 * @since 0.2.0
	 */
	public function test_an_island_is_enqueued_deferred_in_the_footer_for_a_method_the_page_offers(): void {
		$gateways = $this->gateways();

		add_action(
			EnqueuePaymentIslandsAction::NAME,
			static function ( PaymentIslands $islands ): void {
				$islands->add( 'example', 'seocart-example-island', self::SRC, '1.2.3' );
				$islands->add( StubGateway::ID, 'seocart-stub-island', 'https://shop.example/stub-island.js', '1.0.0' );
			}
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own action, named by its declaration.
		do_action( EnqueuePaymentIslandsAction::NAME, new PaymentIslands( $gateways, array( 'example' ) ) );

		$script = wp_scripts()->registered['seocart-example-island'] ?? self::fail( 'The island was not registered.' );

		$this->assertTrue( wp_script_is( 'seocart-example-island', 'enqueued' ) );
		$this->assertSame( array( self::SRC, '1.2.3', array() ), array( $script->src, $script->ver, $script->deps ) );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'seocart-example-island', 'strategy' ), 'Deferred: it runs once the page is parsed.' );
		$this->assertSame( 1, wp_scripts()->get_data( 'seocart-example-island', 'group' ), 'Printed in the footer.' );
		$this->assertFalse( wp_script_is( 'seocart-stub-island', 'registered' ), 'A registered gateway the page does not offer loads nothing.' );
	}

	/**
	 * Tests that an island for an id no gateway registered with is refused, naming the id, and enqueues nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_an_island_for_an_id_no_gateway_registered_is_refused(): void {
		$islands = new PaymentIslands( $this->gateways(), array( 'exmaple' ) );

		try {
			$islands->add( 'exmaple', 'seocart-example-island', self::SRC, '1.2.3' );
			self::fail( 'An island for an unregistered id was accepted.' );
		} catch ( \InvalidArgumentException $refused ) {
			$this->assertStringContainsString( 'No payment gateway "exmaple" is registered', $refused->getMessage() );
		}

		$this->assertFalse( wp_script_is( 'seocart-example-island', 'registered' ) );
	}

	/**
	 * Returns a registry of the stand-in and a gateway `example` a plugin registers through the action.
	 *
	 * @since 0.2.0
	 *
	 * @return Gateways The registry.
	 */
	private function gateways(): Gateways {
		$example = new DeclaredGateway( DeclaredGateway::descriptor( 'example', array( Mode::Test ), array() ) );

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ) use ( $example ): void {
				$registry->register( $example );
			}
		);

		return TestGateways::of( new StubGateway() );
	}
}

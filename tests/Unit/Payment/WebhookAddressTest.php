<?php
/**
 * Tests the one declaration of the webhook route's address
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Application\WebhookAddress;

/**
 * The path a provider is set up to deliver to is one the route's pattern matches, for every mode, and the pattern takes a gateway id exactly as the descriptor defines one, and a mode exactly as Mode has them.
 *
 * Planted violation, shown red and removed: in WebhookAddress::pattern(), restate the gateway
 * segment as `[a-z0-9_-]+`: a hyphenated id, which no gateway can have, is then routed.
 *
 * @since 0.2.0
 */
final class WebhookAddressTest extends TestCase {

	/**
	 * Tests the paths of every mode against the pattern, and the ids and modes it refuses.
	 *
	 * @since 0.2.0
	 */
	public function test_the_path_built_is_the_path_the_route_matches(): void {
		$pattern = '#^' . WebhookAddress::pattern() . '\z#';

		foreach ( Mode::cases() as $mode ) {
			$path = WebhookAddress::path( 'authorize_net', $mode );

			$this->assertSame( '/webhooks/authorize_net/' . $mode->value, $path );
			$this->assertSame( 1, preg_match( $pattern, $path, $route ), $path );
			$this->assertSame( array( 'authorize_net', $mode->value ), array( $route['gateway_id'], $route['mode'] ) );
		}

		foreach ( array( 'pay-pal', 'Stripe', '1stripe', 'stripe_', '' ) as $id ) {
			$this->assertSame( 0, preg_match( GatewayDescriptor::ID_PATTERN, $id ), sprintf( 'The descriptor takes "%s".', $id ) );
			$this->assertSame( 0, preg_match( $pattern, '/webhooks/' . $id . '/test' ), sprintf( 'The route takes the gateway id "%s", which no gateway can have.', $id ) );
		}

		$this->assertSame( 0, preg_match( $pattern, '/webhooks/stripe/sandbox' ), 'A mode is test or live.' );
		$this->assertSame( 'seocart/v1', WebhookAddress::NAMESPACE );
	}
}

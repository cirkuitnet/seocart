<?php
/**
 * Tests that the payment islands' action is declared, and documented on the hooks page with what its listener receives
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Hooks;

use PHPUnit\Framework\TestCase;
use SEOCart\Checkout\Interfaces\Storefront\PaymentIslands;
use SEOCart\Platform\Hooks\ActionDeclarations;
use SEOCart\Platform\Hooks\EnqueuePaymentIslandsAction;

/**
 * A gateway plugin finds the action on the hooks page, generated from its declaration: the name, when it fires and the argument its listener receives.
 *
 * @since 0.2.0
 */
final class EnqueuePaymentIslandsActionTest extends TestCase {

	/**
	 * Tests that the action is listed with the declarations and documented on the generated hooks page.
	 *
	 * @since 0.2.0
	 */
	public function test_the_hooks_page_documents_the_action_and_its_argument(): void {
		$this->assertContains( EnqueuePaymentIslandsAction::class, ActionDeclarations::ALL );

		$page = (string) file_get_contents( dirname( __DIR__, 4 ) . '/docs/reference/hooks.md' );

		$this->assertStringContainsString( "## `seocart_enqueue_payment_islands`\n", $page );
		$this->assertStringContainsString( '- Fires: ' . EnqueuePaymentIslandsAction::firesWhen() . "\n", $page );
		$this->assertStringContainsString( '- Listener arguments: `( ' . PaymentIslands::class . ' $islands )`', $page );
	}
}

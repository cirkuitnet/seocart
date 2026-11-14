<?php
/**
 * Tests that the payment islands' page documents the configuration with the return argument the store's return addresses carry
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use SEOCart\Checkout\Application\ReturnUrls;
use SEOCart\Platform\Hooks\EnqueuePaymentIslandsAction;

/**
 * The page a payment plugin's author reads names the argument a provider's return address carries, as ReturnUrls writes it, and the action that loads an island's script.
 *
 * @since 0.2.0
 */
final class PaymentIslandsPageTest extends TestCase {

	/**
	 * Tests that the configuration's example carries ReturnUrls::QUERY_ARG, and the page names the action.
	 *
	 * @since 0.2.0
	 */
	public function test_the_page_documents_the_return_argument_and_the_action(): void {
		$page = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/payment-islands.md' );

		$this->assertStringContainsString( '"returnQueryArg": "' . ReturnUrls::QUERY_ARG . '"', $page );
		$this->assertStringContainsString( "'" . EnqueuePaymentIslandsAction::NAME . "'", $page );
	}
}

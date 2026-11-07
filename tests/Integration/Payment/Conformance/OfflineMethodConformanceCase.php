<?php
/**
 * OfflineMethodConformanceCase: the payment gateway conformance suite, run against an offline payment method
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Tests\Conformance\Payment\GatewayConformanceCase;
use SEOCart\Tests\Conformance\Payment\NotProducible;
use SEOCart\Tests\Conformance\Payment\Scenario;

/**
 * Runs every rule of the suite against one of the offline payment methods SEOCart registers itself.
 *
 * An offline method calls no service, so it has no tape and any request it sent would fail. It
 * approves every promise to pay and ignores the payment token, so the one scenario it can produce
 * is the approval; every other is named with why it cannot be, and the rules that need one are
 * skipped with that reason printed. Each method has a test of its own, which names its id.
 *
 * @since 0.2.0
 */
abstract class OfflineMethodConformanceCase extends GatewayConformanceCase {

	/**
	 * Returns no tape: an offline method calls no service.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null Null.
	 */
	protected function tapes(): ?string {
		return null;
	}

	/**
	 * Returns the token of each scenario: the approval's, which the method ignores, and why it produces no other.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string|NotProducible> The tokens by scenario.
	 */
	protected function tokens(): array {
		$noAction = new NotProducible( 'an offline payment method asks the customer to take no action' );

		return array(
			Scenario::Approve->value                 => 'offline',
			Scenario::Decline->value                 => new NotProducible( 'an offline payment method refuses no promise to pay' ),
			Scenario::RequiresAction->value          => $noAction,
			Scenario::RequiresActionCompleted->value => $noAction,
			Scenario::Expires->value                 => new NotProducible( 'nothing at a provider cancels a promise to pay' ),
			Scenario::Pending->value                 => new NotProducible( 'a promise to pay is never decided later' ),
		);
	}
}

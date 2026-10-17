<?php
/**
 * NotProducible: a scenario a provider cannot produce in test mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * What a gateway's test names in place of a payment token when its provider cannot produce a scenario in test mode.
 *
 * The rules that need the scenario are then skipped with the reason given; a scenario the test
 * names nothing for fails those rules instead.
 *
 * @since 0.2.0
 */
final readonly class NotProducible {

	/**
	 * Records why.
	 *
	 * @since 0.2.0
	 *
	 * @param string $reason Why the provider cannot produce it, in one sentence.
	 */
	public function __construct( public string $reason ) {
	}
}

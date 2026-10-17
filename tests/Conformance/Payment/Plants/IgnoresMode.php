<?php
/**
 * IgnoresMode: a gateway that sends the test key whatever the payment's mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Reads the test mode's key for every request, whatever mode the payment is in.
 *
 * The plant of rule 16: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class IgnoresMode extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'a live intent\'s requests carry no live credential';

	/**
	 * Returns the test key.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The payment's mode, ignored.
	 * @return string The test mode's key.
	 */
	protected function credential( Mode $mode ): string {
		unset( $mode );

		return parent::credential( Mode::Test );
	}
}

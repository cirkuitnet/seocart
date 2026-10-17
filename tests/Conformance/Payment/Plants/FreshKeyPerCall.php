<?php
/**
 * FreshKeyPerCall: a gateway that sends a new idempotency key with every call
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Sends a fresh key with every money call, instead of the one the request derived.
 *
 * The plant of rule 6: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class FreshKeyPerCall extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'nowhere: the key travels in a header or the body';

	/**
	 * Returns a fresh key.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The derived key, ignored.
	 * @return string A new random uuid.
	 */
	protected function keyFor( string $key ): string {
		unset( $key );

		return wp_generate_uuid4();
	}
}

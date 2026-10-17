<?php
/**
 * KeyInQuery: a gateway that sends its key in the URL
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
 * Adds the API key to every request's query.
 *
 * The plant of rule 14: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class KeyInQuery extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'the URL of request 1 holds a credential';

	/**
	 * Returns the URL with the key in its query.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The path, with its query.
	 * @param Mode   $mode The mode of the payment.
	 * @return string The URL.
	 */
	protected function url( string $path, Mode $mode ): string {
		return parent::url( $path, $mode ) . ( str_contains( $path, '?' ) ? '&' : '?' ) . 'key=' . rawurlencode( $this->credential( $mode ) );
	}
}

<?php
/**
 * IdempotencyKeyInUrl: a gateway that sends each idempotency key in the URL's query
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
 * Sends the key a money call derives as a query parameter, and no header.
 *
 * A provider dedupes by a header or a field of the body; a key in the URL is a key it never
 * reads, so a request asked again is made twice. The plant of rule 6: the conformance suite must
 * fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class IdempotencyKeyInUrl extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'only in its URL';

	/**
	 * Sends the request, its key in the query.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode                 $mode   The mode of the payment the request is about.
	 * @param string               $method The method.
	 * @param string               $path   The path, with its query.
	 * @param array<string, mixed> $fields The form fields of a POST.
	 * @param string|null          $key    The idempotency key of a money call.
	 * @return array{status: int, body: array<mixed>} The answer.
	 */
	protected function send( Mode $mode, string $method, string $path, array $fields, ?string $key ): array {
		if ( null === $key ) {
			return parent::send( $mode, $method, $path, $fields, null );
		}

		return parent::send( $mode, $method, $path . ( str_contains( $path, '?' ) ? '&' : '?' ) . 'idempotency_key=' . rawurlencode( $this->keyFor( $key ) ), $fields, null );
	}
}

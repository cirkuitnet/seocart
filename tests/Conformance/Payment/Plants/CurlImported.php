<?php
/**
 * CurlImported: a planted gateway that calls cURL under names of its own, imported with `use function`
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

use function curl_exec as fetch_answer;
use function curl_init as open_connection;

/**
 * Sends each request to the provider with cURL, its functions imported under other names.
 *
 * Where it calls them, no cURL name is written, so a reading that looks only at calls passes it.
 * The plant of rule 12's reading of the source: the suite must name the import by reading this
 * file. It is never run; its request would be neither recorded nor replayed.
 *
 * @since 0.2.0
 */
final class CurlImported extends ReferenceGateway implements SourcePlant {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'the import of curl_exec()';

	/**
	 * Sends a request with cURL, under the imported names.
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
		unset( $fields, $key );

		$handle = open_connection( $this->url( $path, $mode ) );
		$body   = false === $handle ? false : fetch_answer( $handle );

		return array(
			'status' => is_string( $body ) ? 200 : 0,
			'body'   => $this->decoded( is_string( $body ) ? $body : '' ) + array( 'method' => $method ),
		);
	}
}

<?php
/**
 * CurlCall: a gateway that asks its provider through cURL
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
 * Sends each request to the provider with cURL, past the HTTP client the plugin gives it.
 *
 * The plant of rule 12's reading of the source: the suite must name the call by reading this
 * file. It is never run; its request would be neither recorded nor replayed.
 *
 * @since 0.2.0
 */
final class CurlCall extends ReferenceGateway implements SourcePlant {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'curl_init()';

	/**
	 * Sends a request with cURL.
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
		// phpcs:disable WordPress.WP.AlternativeFunctions -- The plant calls cURL itself, which the suite must find in its source.
		$handle = curl_init( $this->url( $path, $mode ) );

		if ( false === $handle ) {
			return parent::send( $mode, $method, $path, $fields, $key );
		}

		curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, $method );
		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
		$body   = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );
		// phpcs:enable

		return array(
			'status' => $status,
			'body'   => $this->decoded( is_string( $body ) ? $body : '' ),
		);
	}
}

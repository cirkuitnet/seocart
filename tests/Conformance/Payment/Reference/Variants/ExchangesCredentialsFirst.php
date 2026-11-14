<?php
/**
 * ExchangesCredentialsFirst: the reference gateway of a provider that hands out a token for the gateway's credentials
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Exchanges its credentials for a token before every request, as a gateway of an OAuth provider does before its money request.
 *
 * A correct gateway of a provider shaped otherwise than the reference's: the suite passes it when
 * its test declares the token request in credentialRequests(), and rule 6 fails it when the test
 * does not, since the token request is no read and carries no idempotency key.
 *
 * @since 0.2.0
 */
class ExchangesCredentialsFirst extends ReferenceGateway {

	/**
	 * The method of the token request.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TOKEN_METHOD = 'POST';

	/**
	 * The path of the token request.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TOKEN_PATH = '/v1/oauth2/token';

	/**
	 * Asks the provider for a token, then sends the request.
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
		parent::send( $mode, self::TOKEN_METHOD, self::TOKEN_PATH, array( 'grant_type' => 'client_credentials' ), null );

		return parent::send( $mode, $method, $path, $fields, $key );
	}
}

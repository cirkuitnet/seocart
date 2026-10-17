<?php
/**
 * KeylessSendsTheUuid: a gateway for a provider without keys that names a capture or a void by the intent's uuid
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Declares a provider that keeps no idempotency keys, and sends each money request with the intent's uuid as its reference where a capture or a void derives a key of its own.
 *
 * A capture's key is `<uuid>:capture` and a void's `<uuid>:void`; the bare uuid is the
 * authorization's. A provider searched by the uuid alone would find the authorization when asked
 * about the capture. The plant of rule 6: the conformance suite must fail on it with that rule's
 * message.
 *
 * @since 0.2.0
 */
final class KeylessSendsTheUuid extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = ':capture nowhere: the key travels in a header or the body';

	/**
	 * Declares a provider that keeps no keys.
	 *
	 * @since 0.2.0
	 *
	 * @return IdempotencyProfile The profile.
	 */
	protected function idempotency(): IdempotencyProfile {
		return new IdempotencyProfile( null, true, 60 );
	}

	/**
	 * Sends a money request with no key, and with what precedes the key's first colon as its reference.
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
		return parent::send( $mode, $method, $path, null === $key ? $fields : $fields + array( 'reference' => explode( ':', $key )[0] ), null );
	}
}

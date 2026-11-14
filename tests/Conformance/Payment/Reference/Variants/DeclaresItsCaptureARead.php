<?php
/**
 * DeclaresItsCaptureARead: a gateway that reads by POST and sends its capture to the same single endpoint, whose test declares the capture's request a read
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\Mode;

/**
 * Captures through the single endpoint, the request named `captureIntent` in its body, with the capture's idempotency key, and reads as ReadsByPost does.
 *
 * At a single endpoint, a read and a payment differ by their body's key alone, so a test can
 * declare a request that moves money a read. Rules 6 and 12 must refuse that declaration: the
 * request it covers carries the capture's key.
 *
 * @since 0.2.0
 */
final class DeclaresItsCaptureARead extends ReadsByPost {

	/**
	 * The key of the request that captures an intent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CAPTURE = 'captureIntent';

	/**
	 * Sends a capture to the single endpoint, and any other request as ReadsByPost does.
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
		if ( 'POST' === $method && 1 === preg_match( '#^/v1/intents/([a-z0-9_]+)/capture$#D', $path, $capture ) ) {
			return parent::send( $mode, 'POST', self::PATH, array( self::CAPTURE => array( 'id' => $capture[1] ) + $fields ), $key );
		}

		return parent::send( $mode, $method, $path, $fields, $key );
	}
}

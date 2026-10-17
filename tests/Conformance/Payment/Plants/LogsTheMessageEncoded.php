<?php
/**
 * LogsTheMessageEncoded: a gateway that logs the provider's words on a decline, URL-encoded
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Answers as the reference gateway does, and logs the message the provider gives a decline, URL-encoded in the line's context.
 *
 * Encoded, the words are not the bytes the provider sent, and are still the provider's words. The
 * plant of rule 13: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class LogsTheMessageEncoded extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'the provider\'s words at decline_message';

	/**
	 * Logs a decline's message, URL-encoded.
	 *
	 * @since 0.2.0
	 *
	 * @param array{status: int, body: array<mixed>} $answer The answer.
	 */
	protected function answered( array $answer ): void {
		$message = $answer['body']['decline_message'] ?? null;

		if ( is_string( $message ) ) {
			$this->context->logger()->info( 'reference.declined', 'The provider declined the payment.', array( 'why' => rawurlencode( $message ) ) );
		}
	}
}

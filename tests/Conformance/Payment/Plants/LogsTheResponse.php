<?php
/**
 * LogsTheResponse: a gateway that logs the provider's answers
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Logs every answer of the provider, decoded, as the context of a line.
 *
 * The plant of rule 13: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class LogsTheResponse extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'a response body the gateway was served';

	/**
	 * Logs the answer.
	 *
	 * @since 0.2.0
	 *
	 * @param array{status: int, body: array<mixed>} $answer The answer.
	 */
	protected function answered( array $answer ): void {
		$this->context->logger()->info( 'reference.answer', 'The provider answered.', $answer['body'] );
	}
}

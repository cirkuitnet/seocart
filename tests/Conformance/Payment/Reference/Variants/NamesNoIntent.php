<?php
/**
 * NamesNoIntent: a gateway whose delivery's result names neither the store's uuid of the intent nor the provider's id of it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Reads a delivery's result as the reference gateway does, then drops both the store's uuid and the provider's id of the intent from it.
 *
 * A result about no intent is one the store cannot apply: rule 10 must fail it.
 *
 * @since 0.2.0
 */
final class NamesNoIntent extends ReferenceGateway {

	/**
	 * Reads a verified delivery's event, its result about no intent.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $event The decoded body.
	 * @return WebhookReading What it says.
	 */
	protected function readEvent( array $event ): WebhookReading {
		$reading = parent::readEvent( $event );
		$result  = $reading->result;

		if ( null === $result ) {
			return $reading;
		}

		return WebhookReading::result( (string) $reading->eventId, (string) $reading->eventType, $reading->occurredAt, new GatewayResult( $result->provider, $result->operation, $result->outcome, '', $result->amount, $result->providerObjectId, null, $result->errorCode, $result->settlement, $result->nextAction ), $reading->refundUuid );
	}
}

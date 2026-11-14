<?php
/**
 * NamesAnotherPaymentsId: a gateway whose delivery's result names, by the provider's id alone, another object than the payment the delivery is about
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Reads a delivery's result about the provider's id alone, as NamesTheProviderIntentAlone does, and takes the id from the wrong field: the charge's, another object's.
 *
 * A result about another payment than its delivery's would settle that payment: rule 10 must
 * fail it, where the test names the payment the delivery is about.
 *
 * @since 0.2.0
 */
final class NamesAnotherPaymentsId extends ReferenceGateway {

	/**
	 * Reads a verified delivery's event without the store's uuid, about the charge its object names.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $event The decoded body.
	 * @return WebhookReading What it says.
	 */
	protected function readEvent( array $event ): WebhookReading {
		if ( is_array( $event['data']['object'] ?? null ) && isset( $event['data']['object']['charge'] ) ) {
			$event['data']['object']['intent_uuid'] = '';
			$event['data']['object']['id']          = (string) $event['data']['object']['charge'];
		}

		return parent::readEvent( $event );
	}
}

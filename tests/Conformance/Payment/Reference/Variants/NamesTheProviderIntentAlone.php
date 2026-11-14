<?php
/**
 * NamesTheProviderIntentAlone: the reference gateway of a provider whose deliveries name the payment by the provider's id alone
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
 * Reads a delivery's result about the provider's intent alone, with an empty uuid of the store's, as a gateway does whose provider's payload carries no reference of the store's.
 *
 * A correct gateway of a provider shaped otherwise than the reference's: the contract lets a
 * result name its payment by the provider's id of it (GatewayResult), and the store finds the
 * intent by that id. Rule 10 passes it.
 *
 * @since 0.2.0
 */
final class NamesTheProviderIntentAlone extends ReferenceGateway {

	/**
	 * Reads a verified delivery's event without the store's uuid its object carries.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $event The decoded body.
	 * @return WebhookReading What it says.
	 */
	protected function readEvent( array $event ): WebhookReading {
		if ( is_array( $event['data']['object'] ?? null ) ) {
			$event['data']['object']['intent_uuid'] = '';
		}

		return parent::readEvent( $event );
	}
}

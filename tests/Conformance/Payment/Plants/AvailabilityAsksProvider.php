<?php
/**
 * AvailabilityAsksProvider: a gateway that asks its provider whether it is available
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Asks the provider for its account before it answers whether it can take a payment.
 *
 * The plant of rule 3: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class AvailabilityAsksProvider extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'sent requests or ran queries';

	/**
	 * Asks the provider, then answers as the reference gateway does.
	 *
	 * @since 0.2.0
	 *
	 * @param AvailabilityContext $context The payment.
	 * @return bool Whether the gateway takes it.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		$this->send( $context->mode, 'GET', '/v1/account', array(), null );

		return parent::isAvailable( $context );
	}
}

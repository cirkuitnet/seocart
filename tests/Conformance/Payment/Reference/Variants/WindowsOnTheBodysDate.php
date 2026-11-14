<?php
/**
 * WindowsOnTheBodysDate: a gateway of undated deliveries that still refuses one whose body states a time far from now
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;

/**
 * Verifies a delivery as UndatedDeliveries does, then rejects it as stale when the time its body states, when the event happened, is more than the reference's tolerance from now.
 *
 * The time a body states is the event's, unchanged when the provider sends the delivery again, so
 * this window would refuse every late retry of a real delivery. Under the test's declaration that
 * deliveries carry no signed time, rule 10 must fail it: read an hour later, the delivery is
 * rejected, not read as on arrival.
 *
 * @since 0.2.0
 */
final class WindowsOnTheBodysDate extends UndatedDeliveries {

	/**
	 * Reads a delivery, and rejects a verified one whose body's time is far from now.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading What it says, or why it was rejected.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		$reading = parent::readWebhook( $envelope );

		if ( WebhookReadingKind::Rejected === $reading->kind ) {
			return $reading;
		}

		$created = (int) ( $this->decoded( $envelope->rawBody )['created'] ?? 0 );

		return abs( $this->context->clock()->now()->getTimestamp() - $created ) > self::TOLERANCE_SECONDS ? WebhookReading::rejected( WebhookReading::STALE ) : $reading;
	}
}

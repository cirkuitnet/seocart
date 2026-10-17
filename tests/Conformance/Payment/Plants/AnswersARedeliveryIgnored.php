<?php
/**
 * AnswersARedeliveryIgnored: a planted gateway that reads a payment event it has read before as ignored
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Verifies and reads a delivery as the reference gateway does, and answers a payment event it has read before as ignored.
 *
 * It keeps its own list of the events it has read, as if deduplicating were its job: the store's
 * receiver dedupes by receipt, and a delivery the provider sends again because the first was
 * never settled is then dropped, and the payment with it. Each read on its own looks right, the
 * event id the same every time; the store's webhook route, reading the delivery after the
 * gateway's own reads, keeps it as ignored. The plant of rule 10: the suite must fail it by the
 * route's decision.
 *
 * @since 0.2.0
 */
final class AnswersARedeliveryIgnored extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'was decided otherwise by the store\'s webhook route';

	/**
	 * The payment events read so far, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>
	 */
	private array $read = array();

	/**
	 * Reads an event, and a payment event read before as ignored.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $event The decoded body.
	 * @return WebhookReading What it says.
	 */
	protected function readEvent( array $event ): WebhookReading {
		$reading = parent::readEvent( $event );

		if ( WebhookReadingKind::Result !== $reading->kind || null === $reading->eventId ) {
			return $reading;
		}

		if ( isset( $this->read[ $reading->eventId ] ) ) {
			return WebhookReading::ignored( $reading->eventId, (string) $reading->eventType, 'already_read', $reading->occurredAt );
		}

		$this->read[ $reading->eventId ] = true;

		return $reading;
	}
}

<?php
/**
 * RecordingEventPublisher: an EventPublisher that records what application services publish
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

/**
 * The unit-test stand-in for Publisher, as application services see it.
 *
 * Owns one fact: which events a service published and that the real publisher would have
 * accepted. It checks every event as Publisher does, without a catalog or a database: the
 * class must be readonly and the payload must keep Outbox::encode()'s rules. Given a
 * transaction manager (normally FakeTransactionManager) it also keeps the transaction rules:
 * an outbox event outside a transaction is refused, and an event is recorded only once the
 * unit of work it was published in commits, so an event of a rolled-back unit never appears.
 * Without one, every event is recorded at once.
 *
 * @since 0.1.0
 */
final class RecordingEventPublisher implements EventPublisher {

	/**
	 * The unit of work, or null to record at once.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager|null
	 */
	private ?TransactionManager $tx;

	/**
	 * The events recorded, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<DomainEvent>
	 */
	private array $published = array();

	/**
	 * Creates the double.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager|null $tx Optional. The unit of work events are published in. Default null.
	 */
	public function __construct( ?TransactionManager $tx = null ) {
		$this->tx = $tx;
	}

	/**
	 * Checks the events as Publisher does, and records them.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When an event would be refused by Publisher.
	 *
	 * @param DomainEvent ...$events The events.
	 */
	public function publish( DomainEvent ...$events ): void {
		foreach ( $events as $event ) {
			if ( ! ( new \ReflectionClass( $event ) )->isReadOnly() ) {
				throw new \LogicException( sprintf( 'Event %s must be a readonly class.', get_class( $event ) ) );
			}

			Outbox::encode( $event, Publisher::DEFAULT_PAYLOAD_CAP_BYTES );

			if ( null !== $this->tx && DeliveryMode::Outbox === $event::deliveryMode() && 0 === $this->tx->depth() ) {
				throw new \LogicException( sprintf( 'Event %s: outbox events are published inside a transaction.', get_class( $event ) ) );
			}
		}

		foreach ( $events as $event ) {
			if ( null === $this->tx ) {
				$this->published[] = $event;

				continue;
			}

			$this->tx->afterCommit(
				function () use ( $event ): void {
					$this->published[] = $event;
				}
			);
		}
	}

	/**
	 * Returns the events recorded.
	 *
	 * @since 0.1.0
	 *
	 * @return list<DomainEvent> In the order they were published.
	 */
	public function published(): array {
		return $this->published;
	}

	/**
	 * Returns the events of one class recorded.
	 *
	 * @since 0.1.0
	 *
	 * @template T of DomainEvent
	 *
	 * @param string $eventClass The event class.
	 * @return list<T> In the order they were published.
	 *
	 * @phpstan-param class-string<T> $eventClass
	 */
	public function publishedOf( string $eventClass ): array {
		return array_values( array_filter( $this->published, static fn( DomainEvent $event ): bool => $event instanceof $eventClass ) );
	}
}

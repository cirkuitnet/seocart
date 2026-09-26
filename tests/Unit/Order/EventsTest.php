<?php
/**
 * Tests the order events: their payloads round-trip and name their properties, and the publisher accepts them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use SEOCart\Order\Domain\Event\OrderCreated;
use SEOCart\Order\Domain\Event\OrderPlaced;
use SEOCart\Order\Domain\Event\OrderStatusChanged;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;

/**
 * The three order events, read the way the hooks reference reads them: without a database.
 *
 * The reference is generated from each event class, so the payload keys must be exactly the
 * promoted properties in snake_case, less `occurredAt`, which travels beside the payload.
 *
 * Planted violation: in OrderPlaced, rename the property `baseGrandTotalMinor` to `baseTotalMinor`
 * and leave its key `base_grand_total_minor`: the key no longer names a property.
 *
 * @since 0.1.0
 */
final class EventsTest extends TestCase {

	/**
	 * Returns one example of each order event.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{DomainEvent}> The events, by class.
	 */
	public static function events(): array {
		$at = new \DateTimeImmutable( '2026-09-26 10:00:00.123456', new \DateTimeZone( 'UTC' ) );

		return array(
			'OrderCreated'               => array( new OrderCreated( 7, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8b', '000042', 'storefront', 'EUR', 3080, $at ) ),
			'OrderPlaced'                => array( new OrderPlaced( 7, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8b', '000042', 'storefront', 'EUR', 3080, 'USD', 2464, 12, 'user', 12, $at ) ),
			'OrderPlaced, a guest'       => array( new OrderPlaced( 7, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8b', '000042', 'storefront', 'USD', 3080, 'USD', 3080, null, 'user', null, $at ) ),
			'OrderStatusChanged'         => array( new OrderStatusChanged( 7, 'pending_payment', 'processing', 'payment_approved', 'system', 3, $at ) ),
			'OrderStatusChanged, no one' => array( new OrderStatusChanged( 7, 'processing', 'on_hold', 'stock_unavailable', 'user', null, $at ) ),
		);
	}

	/**
	 * Tests that an event rebuilt from its own payload equals the event.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider events
	 *
	 * @param DomainEvent $event The event.
	 */
	public function test_an_event_round_trips_through_its_payload( DomainEvent $event ): void {
		$rebuilt = $event::fromPayload( $event->toPayload(), $event->occurredAt(), $event::payloadVersion() );

		$this->assertEquals( $event, $rebuilt );
		$this->assertSame( $event->toPayload(), $rebuilt->toPayload() );
	}

	/**
	 * Tests that the payload keys are the promoted properties, in snake_case and in order, less `occurredAt`.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider events
	 *
	 * @param DomainEvent $event The event.
	 */
	public function test_the_payload_keys_are_the_promoted_properties( DomainEvent $event ): void {
		$constructor = ( new \ReflectionClass( $event ) )->getConstructor();
		$properties  = array();

		$this->assertNotNull( $constructor );

		foreach ( $constructor->getParameters() as $parameter ) {
			$this->assertTrue( $parameter->isPromoted(), sprintf( '%s::$%s is not a promoted property, so a reference cannot read it.', get_class( $event ), $parameter->getName() ) );

			if ( 'occurredAt' !== $parameter->getName() ) {
				$properties[] = strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $parameter->getName() ) );
			}
		}

		$this->assertSame( $properties, array_keys( $event->toPayload() ) );
	}

	/**
	 * Tests the class-level facts a reference reads: readonly, a name, a delivery, version 1 and the `order` aggregate.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider events
	 *
	 * @param DomainEvent $event The event.
	 */
	public function test_every_event_declares_what_a_reference_reads( DomainEvent $event ): void {
		$class = new \ReflectionClass( $event );

		$this->assertTrue( $class->isFinal() && $class->isReadOnly() );
		$this->assertMatchesRegularExpression( '/^order_[a-z_]+$/', $event::eventName() );
		$this->assertSame( 1, $event::payloadVersion() );
		$this->assertSame( 'order', $event->aggregateType() );
		$this->assertSame( 7, $event->aggregateId() );
		$this->assertTrue( $class->hasConstant( 'AGGREGATE' ) );
		$this->assertStringContainsString( 'Fires after', (string) $class->getDocComment() );
	}

	/**
	 * Tests that the kernel's catalog holds the three events, and that the publisher's rules accept each.
	 *
	 * @since 0.1.0
	 */
	public function test_the_catalog_lists_them_and_the_publisher_accepts_them(): void {
		$catalog = new EventCatalog( Modules::EVENT_CLASSES );

		foreach ( array( OrderCreated::class, OrderPlaced::class, OrderStatusChanged::class ) as $class ) {
			$this->assertSame( $class::eventName(), $catalog->nameOf( $class ) );
		}

		$tx        = new FakeTransactionManager();
		$publisher = new RecordingEventPublisher( $tx );
		$events    = array_map( static fn( array $example ): DomainEvent => $example[0], self::events() );

		$tx->transaction(
			static function () use ( $publisher, $events ): void {
				$publisher->publish( ...array_values( $events ) );
			}
		);

		$this->assertCount( count( $events ), $publisher->published() );
	}
}

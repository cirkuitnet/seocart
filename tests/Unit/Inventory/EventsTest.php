<?php
/**
 * Tests the stock events: their payloads round-trip and name their properties, and the publisher accepts them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;

/**
 * The four stock events, read the way a hooks reference reads them: without a database.
 *
 * A reference of the actions the plugin fires is generated from each event class: its name, its
 * delivery, its payload version, its aggregate, and the payload fields from the constructor's
 * promoted readonly properties with their types and `@param` text. That works only if the payload
 * keys are exactly those properties, in snake_case, and `occurredAt`, which travels beside the
 * payload, is the one promoted property that is not a key. These tests hold that, and that every
 * event survives a trip through its own payload.
 *
 * Planted violation: in StockAdjusted, rename the property `onHand` to `onHandNow` and leave its
 * key `on_hand`: the key no longer names a property.
 *
 * @since 0.1.0
 */
final class EventsTest extends TestCase {

	/**
	 * Returns one example of each stock event.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{DomainEvent}> The events, by class.
	 */
	public static function events(): array {
		$at = new \DateTimeImmutable( '2026-09-24 10:00:00.123456', new \DateTimeZone( 'UTC' ) );

		return array(
			'StockAdjusted'            => array( new StockAdjusted( 7, -3, 2, -1, 'damaged', 'user', 5, 41, $at ) ),
			'StockAdjusted, no actor'  => array( new StockAdjusted( 7, 0, 0, 0, 'variant_deleted', 'system', null, 42, $at ) ),
			'StockHoldExpired'         => array( new StockHoldExpired( '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b', 7, 2, '2026-09-24 09:55:00', $at ) ),
			'StockReserved'            => array( new StockReserved( '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b', 3, null, array( 7, 9 ), array( 2, 1 ), '2026-09-24 10:15:00', $at ) ),
			'StockReservationReleased' => array( new StockReservationReleased( '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b', 'payment_declined', array( 7 ), array( 2 ), $at ) ),
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
	 * Tests the class-level facts a reference reads: readonly, a name, a delivery, version 1 and the `variant` aggregate.
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
		$this->assertMatchesRegularExpression( '/^stock_[a-z_]+$/', $event::eventName() );
		$this->assertSame( 1, $event::payloadVersion() );
		$this->assertSame( 'variant', $event->aggregateType() );
		$this->assertTrue( $class->hasConstant( 'AGGREGATE' ) );
		$this->assertStringContainsString( 'Fires after', (string) $class->getDocComment() );
	}

	/**
	 * Tests that the kernel's catalog holds the four events, and that the publisher's rules accept each.
	 *
	 * @since 0.1.0
	 */
	public function test_the_catalog_lists_them_and_the_publisher_accepts_them(): void {
		$catalog = new EventCatalog( Modules::EVENT_CLASSES );

		foreach ( array( StockAdjusted::class, StockHoldExpired::class, StockReserved::class, StockReservationReleased::class ) as $class ) {
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

	/**
	 * Tests that a reservation and a release refuse lists that do not pair up.
	 *
	 * @since 0.1.0
	 */
	public function test_the_parallel_lists_must_pair_up(): void {
		$at      = new \DateTimeImmutable( '2026-09-24 10:00:00', new \DateTimeZone( 'UTC' ) );
		$refused = 0;

		foreach (
			array(
				static fn() => new StockReserved( 'h', null, null, array( 1, 2 ), array( 1 ), '2026-09-24 10:15:00', $at ),
				static fn() => new StockReserved( 'h', null, null, array(), array(), '2026-09-24 10:15:00', $at ),
				static fn() => new StockReservationReleased( 'h', 'x', array( 1 ), array(), $at ),
			) as $build
		) {
			try {
				$build();
			} catch ( \InvalidArgumentException $expected ) {
				++$refused;
			}
		}

		$this->assertSame( 3, $refused );
	}
}

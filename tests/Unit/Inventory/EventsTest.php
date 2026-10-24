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
use SEOCart\Inventory\Domain\Event\StockAllocated;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\StockLines;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;

/**
 * The stock events, read the way a hooks reference reads them: without a database.
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
			'StockAdjusted'                      => array( new StockAdjusted( 7, 1, -3, 6, 3, 2, -1, 'damaged', 'user', 5, 41, $at ) ),
			'StockAdjusted, no actor'            => array( new StockAdjusted( 7, 2, 0, 0, 0, 0, 0, 'variant_deleted', 'system', null, 42, $at ) ),
			'StockHoldExpired'                   => array( new StockHoldExpired( '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b', 7, 1, 2, '2026-09-24 09:55:00', $at ) ),
			'StockReserved'                      => array( new StockReserved( '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b', 3, null, array( 7, 9 ), array( 5, 1 ), array( 7, 7, 9 ), array( 1, 2, 1 ), array( 2, 3, 1 ), '2026-09-24 10:15:00', $at ) ),
			'StockReservationReleased'           => array( new StockReservationReleased( '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b', 'payment_declined', array( 7 ), array( 2 ), array( 7 ), array( 1 ), array( 2 ), $at ) ),
			'StockAllocated'                     => array( new StockAllocated( 31, array( 7, 9 ), array( 3, 1 ), array( 7, 7, 9 ), array( 1, 2, 1 ), array( 2, 1, 1 ), $at ) ),
			'StockAdjusted, before locations'    => array( new StockAdjusted( 7, null, -3, 2, -1, null, null, 'damaged', 'user', 5, 41, $at ) ),
			'StockHoldExpired, before locations' => array( new StockHoldExpired( '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b', 7, null, 2, '2026-09-24 09:55:00', $at ) ),
			'StockReserved, before locations'    => array( new StockReserved( '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b', 3, null, array( 7, 9 ), array( 2, 1 ), array(), array(), array(), '2026-09-24 10:15:00', $at ) ),
		);
	}

	/**
	 * Returns the payload keys a stock event has carried since stock was kept per location.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The keys.
	 */
	private static function locationKeys(): array {
		return array( 'location_id', 'location_on_hand', 'location_available', 'location_variant_ids', 'location_ids', 'location_quantities' );
	}

	/**
	 * Tests that a payload recorded before stock was kept per location, without the location keys, still reads, as an event naming no location.
	 *
	 * An event in the outbox when the store upgrades is delivered after the upgrade, under the same
	 * payload version, so its reader must not require a key the payload never had.
	 *
	 * Planted violation: in StockReserved::fromPayload(), read `$payload['location_quantities']`
	 * without its default: the earlier payload no longer reads.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payload_recorded_before_locations_reads_as_naming_no_location(): void {
		$at = new \DateTimeImmutable( '2026-09-24 10:00:00', new \DateTimeZone( 'UTC' ) );

		foreach ( self::events() as $name => $example ) {
			$event   = $example[0];
			$earlier = array_diff_key( $event->toPayload(), array_flip( self::locationKeys() ) );
			$read    = $event::fromPayload( $earlier, $at, 1 );
			$named   = array_values( array_intersect_key( $read->toPayload(), array_flip( self::locationKeys() ) ) );

			$this->assertNotSame( $earlier, $event->toPayload(), $name . ' carries its location.' );
			$this->assertNotSame( array(), $named );

			foreach ( $named as $value ) {
				$this->assertContains( $value, array( null, array() ), $name . ' read from an earlier payload names no location.' );
			}
		}
	}

	/**
	 * Tests that a listener written for the first payloads, reading `variant_ids` and `quantities` alone, reads each variant's units the same from a payload stored before locations and from one of units at two locations.
	 *
	 * The listener maps each variant to its units, as a listener of the stock events would. A
	 * reservation, an allocation and a release as the plugin stored them before stock was kept per
	 * location, and the same made now from two locations, all read as one total per variant.
	 *
	 * Planted violation: in StockAllocated::toPayload(), write the item lists as `variant_ids` and
	 * `quantities`, as a payload naming a variant once per location would: the listener reads the
	 * variant's units at its last location, 3 in place of 5.
	 *
	 * @since 0.2.0
	 */
	public function test_a_listener_of_the_first_payloads_reads_one_total_per_variant(): void {
		$at       = new \DateTimeImmutable( '2026-09-24 10:00:00', new \DateTimeZone( 'UTC' ) );
		$listener = static fn( array $payload ): array => array_combine( $payload['variant_ids'], $payload['quantities'] );
		$hold     = '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b';

		list( $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities ) = StockLines::of( array( array( 9, 1, 1 ), array( 7, 2, 3 ), array( 7, 1, 2 ) ) );

		$payloads = array(
			'a reservation stored before locations' => StockReserved::fromPayload(
				array(
					'hold_id'     => $hold,
					'cart_id'     => 3,
					'order_id'    => null,
					'variant_ids' => array( 7, 9 ),
					'quantities'  => array( 5, 1 ),
					'expires_at'  => '2026-09-24 10:15:00',
				),
				$at,
				1
			)->toPayload(),
			'a reservation now'                     => ( new StockReserved( $hold, 3, null, $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities, '2026-09-24 10:15:00', $at ) )->toPayload(),
			'an allocation stored before locations' => StockAllocated::fromPayload(
				array(
					'order_id'    => 31,
					'variant_ids' => array( 7, 9 ),
					'quantities'  => array( 5, 1 ),
				),
				$at,
				1
			)->toPayload(),
			'an allocation now'                     => ( new StockAllocated( 31, $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities, $at ) )->toPayload(),
			'a release stored before locations'     => StockReservationReleased::fromPayload(
				array(
					'hold_id'     => $hold,
					'reason'      => 'payment_declined',
					'variant_ids' => array( 7, 9 ),
					'quantities'  => array( 5, 1 ),
				),
				$at,
				1
			)->toPayload(),
			'a release now'                         => ( new StockReservationReleased( $hold, 'payment_declined', $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities, $at ) )->toPayload(),
		);

		foreach ( $payloads as $name => $payload ) {
			$this->assertSame(
				array(
					7 => 5,
					9 => 1,
				),
				$listener( $payload ),
				$name
			);
		}
	}

	/**
	 * Tests that StockLines lists each variant once with its units, and each item, in one order, with units that add up to the variant's.
	 *
	 * Planted violation: in StockLines::agree(), leave out the check of each variant's units: an
	 * allocation whose items add up to more than its variant's units is accepted.
	 *
	 * @since 0.2.0
	 */
	public function test_the_item_lists_are_as_long_as_each_other_and_add_up_to_each_variant(): void {
		$lines = StockLines::of( array( array( 9, 2, 1 ), array( 7, 2, 3 ), array( 7, 1, 2 ), array( 9, 2, 4 ) ) );

		$this->assertSame( array( array( 7, 9 ), array( 5, 5 ), array( 7, 7, 9 ), array( 1, 2, 2 ), array( 2, 3, 5 ) ), $lines, 'Ascending by variant then location, an item listed twice added up.' );
		$this->assertCount( count( $lines[2] ), $lines[3] );
		$this->assertCount( count( $lines[2] ), $lines[4] );

		$byItems = array();

		foreach ( $lines[2] as $index => $variantId ) {
			$byItems[ $variantId ] = ( $byItems[ $variantId ] ?? 0 ) + $lines[4][ $index ];
		}

		$this->assertSame( array_combine( $lines[0], $lines[1] ), $byItems, 'Each variant\'s items add up to its units.' );
		$this->assertTrue( StockLines::agree( ...$lines ) );
		$this->assertFalse( StockLines::agree( array( 7 ), array( 5 ), array( 7, 7 ), array( 1, 2 ), array( 2, 4 ) ), 'Items adding up to more than the variant\'s units.' );
		$this->assertFalse( StockLines::agree( array( 7 ), array( 5 ), array( 7, 7 ), array( 1 ), array( 2, 3 ) ), 'Item lists of two lengths.' );
		$this->assertTrue( StockLines::agree( array( 7, 9 ), array( 5, 1 ), array(), array(), array() ), 'Lines recorded before locations.' );
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
	 * Tests the class-level facts a reference reads: readonly, a name, a delivery, version 1 and the aggregate its constant names.
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
		$this->assertTrue( $class->hasConstant( 'AGGREGATE' ) );
		$this->assertSame( $class->getConstant( 'AGGREGATE' ), $event->aggregateType() );
		$this->assertContains( $event->aggregateType(), array( 'variant', 'order' ), 'A stock event happens to an item, or to the order it allocates to.' );
		$this->assertStringContainsString( 'Fires ', (string) $class->getDocComment() );
	}

	/**
	 * Tests that the kernel's catalog holds the stock events, and that the publisher's rules accept each.
	 *
	 * @since 0.1.0
	 */
	public function test_the_catalog_lists_them_and_the_publisher_accepts_them(): void {
		$catalog = new EventCatalog( Modules::EVENT_CLASSES );

		foreach ( array( StockAdjusted::class, StockAllocated::class, StockHoldExpired::class, StockReserved::class, StockReservationReleased::class ) as $class ) {
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
	 * Tests that a reservation, a release and an allocation refuse lines that do not agree: lists that do not pair up, a variant named twice, items that do not add up to their variant.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The items.
	 */
	public function test_the_parallel_lists_must_pair_up(): void {
		$at      = new \DateTimeImmutable( '2026-09-24 10:00:00', new \DateTimeZone( 'UTC' ) );
		$refused = 0;

		foreach (
			array(
				static fn() => new StockReserved( 'h', null, null, array( 1, 2 ), array( 1 ), array(), array(), array(), '2026-09-24 10:15:00', $at ),
				static fn() => new StockReserved( 'h', null, null, array(), array(), array(), array(), array(), '2026-09-24 10:15:00', $at ),
				static fn() => new StockReserved( 'h', null, null, array( 1, 2 ), array( 1, 1 ), array( 1 ), array( 1 ), array( 1 ), '2026-09-24 10:15:00', $at ),
				static fn() => new StockReserved( 'h', null, null, array( 1 ), array( 3 ), array( 1, 1 ), array( 1, 2 ), array( 1, 1 ), '2026-09-24 10:15:00', $at ),
				static fn() => new StockReservationReleased( 'h', 'x', array( 1 ), array(), array(), array(), array(), $at ),
				static fn() => new StockReservationReleased( 'h', 'x', array( 1 ), array( 1 ), array( 1, 1 ), array( 1, 2 ), array( 1 ), $at ),
				static fn() => new StockAllocated( 1, array( 1 ), array(), array(), array(), array(), $at ),
				static fn() => new StockAllocated( 1, array(), array(), array(), array(), array(), $at ),
				static fn() => new StockAllocated( 1, array( 1, 2 ), array( 1, 1 ), array( 1, 2 ), array( 1, 1 ), array( 1, 2 ), $at ),
				static fn() => new StockAllocated( 1, array( 1, 1 ), array( 1, 1 ), array(), array(), array(), $at ),
			) as $build
		) {
			try {
				$build();
			} catch ( \InvalidArgumentException $expected ) {
				++$refused;
			}
		}

		$this->assertSame( 10, $refused );
	}
}

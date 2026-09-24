<?php
/**
 * Tests the catalog's events: their names, their delivery and their payloads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\Event\ProductDeleted;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

/**
 * ProductSaved and ProductDeleted are outbox events of the product aggregate, listed in the event
 * catalog the kernel builds, whose payloads survive being stored and read back.
 *
 * The generated hooks reference documents an event's payload from its constructor's promoted
 * readonly properties and their `@param` text, so each payload key is a promoted property's name
 * in snake_case, in order, and `occurredAt`, which travels beside the payload, is the one promoted
 * property that is not a key.
 *
 * @since 0.1.0
 */
final class ProductEventsTest extends TestCase {

	/**
	 * Provides one of each event.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{DomainEvent}> Test cases.
	 */
	public static function events(): array {
		$at = new \DateTimeImmutable( '2026-09-25 10:00:00', new \DateTimeZone( 'UTC' ) );

		return array(
			'ProductSaved'   => array( new ProductSaved( 3, 40, array( 'title' ), 'A-1', 1999, 'USD', $at ) ),
			'ProductDeleted' => array( new ProductDeleted( 3, 40, array( 'A-1' ), $at ) ),
		);
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
		$class       = new \ReflectionClass( $event );
		$constructor = $class->getConstructor();
		$properties  = array();

		$this->assertTrue( $class->isReadOnly(), get_class( $event ) . ' is not a readonly class.' );
		$this->assertNotNull( $constructor );

		foreach ( $constructor->getParameters() as $parameter ) {
			$this->assertTrue( $parameter->isPromoted(), sprintf( '%s::$%s is not a promoted property, so the hooks reference cannot read it.', get_class( $event ), $parameter->getName() ) );
			$this->assertMatchesRegularExpression( '/@param\s+\S+\s+\$' . $parameter->getName() . '\s+\S/', (string) $constructor->getDocComment(), sprintf( '%s::$%s has no @param sentence.', get_class( $event ), $parameter->getName() ) );

			if ( 'occurredAt' !== $parameter->getName() ) {
				$properties[] = strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $parameter->getName() ) );
			}
		}

		$this->assertSame( $properties, array_keys( $event->toPayload() ) );
	}

	/**
	 * Tests that both events are in the kernel's catalog, under their names, delivered through the outbox.
	 *
	 * @since 0.1.0
	 */
	public function test_both_events_are_catalogued_outbox_events(): void {
		$catalog = new EventCatalog( Modules::EVENT_CLASSES );

		$this->assertSame( ProductSaved::class, $catalog->classFor( 'product_saved' ) );
		$this->assertSame( ProductDeleted::class, $catalog->classFor( 'product_deleted' ) );
		$this->assertSame( DeliveryMode::Outbox, ProductSaved::deliveryMode() );
		$this->assertSame( DeliveryMode::Outbox, ProductDeleted::deliveryMode() );
	}

	/**
	 * Tests that a saved product's payload survives the round trip, with and without a price.
	 *
	 * @since 0.1.0
	 */
	public function test_a_saved_products_payload_survives_the_round_trip(): void {
		$at = new \DateTimeImmutable( '2026-09-25 10:00:00.250000', new \DateTimeZone( 'UTC' ) );

		foreach ( array( new ProductSaved( 3, 40, array( 'title', 'price_minor' ), 'A-1', 1999, 'USD', $at ), new ProductSaved( 4, 41, array(), null, null, null, $at ) ) as $event ) {
			$payload = json_decode( (string) json_encode( $event->toPayload() ), true );
			$again   = ProductSaved::fromPayload( $payload, $at, ProductSaved::payloadVersion() );

			$this->assertEquals( $event, $again );
			$this->assertSame( 'product', $again->aggregateType() );
			$this->assertSame( $event->productId, $again->aggregateId() );
		}
	}

	/**
	 * Tests that a deleted product's payload survives the round trip.
	 *
	 * @since 0.1.0
	 */
	public function test_a_deleted_products_payload_survives_the_round_trip(): void {
		$at      = new \DateTimeImmutable( '2026-09-25 10:00:00', new \DateTimeZone( 'UTC' ) );
		$event   = new ProductDeleted( 3, 40, array( 'A-1' ), $at );
		$payload = json_decode( (string) json_encode( $event->toPayload() ), true );

		$this->assertEquals( $event, ProductDeleted::fromPayload( $payload, $at, ProductDeleted::payloadVersion() ) );
		$this->assertSame( 3, $event->aggregateId() );
	}
}

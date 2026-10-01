<?php
/**
 * Tests the payment events: their payloads round-trip and name their properties, and the publisher accepts them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Domain\Event\PaymentAuthorized;
use SEOCart\Payment\Domain\Event\PaymentCaptured;
use SEOCart\Payment\Domain\Event\PaymentFailed;
use SEOCart\Payment\Domain\Event\PaymentIntentCreated;
use SEOCart\Payment\Domain\Event\PaymentStatusChanged;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;

/**
 * The five payment events, read the way the hooks reference reads them: without a database.
 *
 * The reference is generated from each event class, so the payload keys must be exactly the
 * promoted properties in snake_case, less `occurredAt`, which travels beside the payload.
 *
 * Planted violation: in PaymentStatusChanged, rename the property `dueMinor` to `amountDueMinor`
 * and leave its key `due_minor`: the key no longer names a property.
 *
 * @since 0.1.0
 */
final class EventsTest extends TestCase {

	/**
	 * Returns one example of each payment event, by its aggregate's kind and id.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{DomainEvent, string, int}> The events, by class.
	 */
	public static function events(): array {
		$at = new \DateTimeImmutable( '2026-09-26 10:00:00.123456', new \DateTimeZone( 'UTC' ) );

		return array(
			'PaymentIntentCreated'           => array( new PaymentIntentCreated( 11, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', 7, 'stub', 3080, 'EUR', $at ), 'payment_intent', 11 ),
			'PaymentAuthorized'              => array( new PaymentAuthorized( 11, 7, 3080, 'EUR', 21, $at ), 'payment_intent', 11 ),
			'PaymentCaptured'                => array( new PaymentCaptured( 11, 7, 3080, 'EUR', 22, $at ), 'payment_intent', 11 ),
			'PaymentFailed'                  => array( new PaymentFailed( 11, 7, 3080, 'EUR', 23, 'card_declined', $at ), 'payment_intent', 11 ),
			'PaymentFailed, no machine code' => array( new PaymentFailed( 11, 7, 3080, 'EUR', 23, null, $at ), 'payment_intent', 11 ),
			'PaymentStatusChanged'           => array( new PaymentStatusChanged( 7, 'authorized', 'paid', 3080, 3080, 0, 0, 'EUR', $at ), 'order', 7 ),
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
	 * Tests the class-level facts a reference reads: readonly, a name, a delivery, version 1 and the aggregate.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider events
	 *
	 * @param DomainEvent $event     The event.
	 * @param string      $aggregate The kind of aggregate it happens to.
	 * @param int         $id        The aggregate's id in the example.
	 */
	public function test_every_event_declares_what_a_reference_reads( DomainEvent $event, string $aggregate, int $id ): void {
		$class = new \ReflectionClass( $event );

		$this->assertTrue( $class->isFinal() && $class->isReadOnly() );
		$this->assertMatchesRegularExpression( '/^payment_[a-z_]+$/', $event::eventName() );
		$this->assertSame( 1, $event::payloadVersion() );
		$this->assertSame( $aggregate, $event->aggregateType() );
		$this->assertSame( $id, $event->aggregateId() );
		$this->assertTrue( $class->hasConstant( 'AGGREGATE' ) );
		$this->assertStringContainsString( 'Fires after', (string) $class->getDocComment() );
	}

	/**
	 * Tests that the kernel's catalog holds the five events, and that the publisher's rules accept each.
	 *
	 * @since 0.1.0
	 */
	public function test_the_catalog_lists_them_and_the_publisher_accepts_them(): void {
		$catalog = new EventCatalog( Modules::EVENT_CLASSES );

		foreach ( array( PaymentIntentCreated::class, PaymentAuthorized::class, PaymentCaptured::class, PaymentFailed::class, PaymentStatusChanged::class ) as $class ) {
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

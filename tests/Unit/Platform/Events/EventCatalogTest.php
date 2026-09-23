<?php
/**
 * Tests the event catalog, built without WordPress
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Events;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Events\MutableThing;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;

/**
 * The one list of events: each name maps to one class and back, and a second entry for either fails at once.
 *
 * The unit suite never loads WordPress, so building the catalog here is the proof that the
 * event declarations are data: constructing them does no I/O and calls no WordPress function.
 *
 * Planted violation: in EventCatalog::__construct(), drop the check that a name is already taken.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class EventCatalogTest extends TestCase {

	/**
	 * The name the test's stand-in event class reports.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private static string $standInName = '';

	/**
	 * Names map to classes and back, in the order the events were listed; unknown ones map to nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_names_and_classes_map_both_ways(): void {
		$catalog = new EventCatalog( new \ArrayIterator( array( ThingHappened::class, ThingNoticed::class, MutableThing::class ) ) );

		$this->assertSame( array( 'test_thing_happened', 'test_thing_noticed', 'test_mutable_thing' ), $catalog->names() );
		$this->assertSame( 'test_thing_noticed', $catalog->nameOf( ThingNoticed::class ) );
		$this->assertSame( ThingHappened::class, $catalog->classFor( 'test_thing_happened' ) );
		$this->assertNull( $catalog->nameOf( \stdClass::class ) );
		$this->assertNull( $catalog->classFor( 'gone' ) );
		$this->assertSame( array(), ( new EventCatalog( array() ) )->names() );
	}

	/**
	 * Two classes with one name are refused, naming both.
	 *
	 * @since 0.1.0
	 */
	public function test_a_name_listed_twice_is_refused(): void {
		$impostor = self::standIn( 'test_thing_happened' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( ThingHappened::class );

		new EventCatalog( array( ThingHappened::class, $impostor ) );
	}

	/**
	 * A class listed twice is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_class_listed_twice_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new EventCatalog( array( ThingNoticed::class, ThingNoticed::class ) );
	}

	/**
	 * Returns entries that are not a listable event.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: mixed}> The entries.
	 */
	public static function notEvents(): array {
		return array(
			'a class that is not an event' => array( \stdClass::class ),
			'the interface itself'         => array( DomainEvent::class ),
			'a missing class'              => array( 'SEOCart\\Tests\\NoSuchEvent' ),
			'not a string'                 => array( 42 ),
		);
	}

	/**
	 * An entry that is not a concrete event class is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider notEvents
	 *
	 * @param mixed $entry The entry.
	 */
	public function test_an_entry_that_is_not_an_event_class_is_refused( mixed $entry ): void {
		$this->expectException( \InvalidArgumentException::class );

		new EventCatalog( array( $entry ) );
	}

	/**
	 * A name that is not lowercase snake_case of at most 64 characters is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_malformed_name_is_refused(): void {
		foreach ( array( 'Thing-Happened', '', '9lives', str_repeat( 'a', 65 ) ) as $name ) {
			try {
				new EventCatalog( array( self::standIn( $name ) ) );
				$this->fail( sprintf( 'The name "%s" must be refused.', $name ) );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( 'snake_case', $refused->getMessage() );
			}
		}

		$this->assertSame( array( str_repeat( 'a', 64 ) ), ( new EventCatalog( array( self::standIn( str_repeat( 'a', 64 ) ) ) ) )->names() );
	}

	/**
	 * Returns the class of an event that reports the given name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The name it reports.
	 * @return string The class name.
	 */
	private static function standIn( string $name ): string {
		self::$standInName = $name;

		$event = new class() implements DomainEvent {

			/**
			 * Returns the name the test chose.
			 *
			 * @return string The name.
			 */
			public static function eventName(): string {
				return EventCatalogTest::standInName();
			}

			/**
			 * Returns the delivery mode.
			 *
			 * @return DeliveryMode AfterCommit.
			 */
			public static function deliveryMode(): DeliveryMode {
				return DeliveryMode::AfterCommit;
			}

			/**
			 * Returns the payload version.
			 *
			 * @return int 1.
			 */
			public static function payloadVersion(): int {
				return 1;
			}

			/**
			 * Rebuilds the event.
			 *
			 * @param array<string, mixed> $payload    Ignored.
			 * @param \DateTimeImmutable   $occurredAt Ignored.
			 * @param int                  $version    Ignored.
			 * @return self The event.
			 */
			public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): self {
				return new self();
			}

			/**
			 * Returns the aggregate type.
			 *
			 * @return string `thing`.
			 */
			public function aggregateType(): string {
				return 'thing';
			}

			/**
			 * Returns the aggregate id.
			 *
			 * @return int 1.
			 */
			public function aggregateId(): int {
				return 1;
			}

			/**
			 * Returns when it happened.
			 *
			 * @return \DateTimeImmutable Now.
			 */
			public function occurredAt(): \DateTimeImmutable {
				return new \DateTimeImmutable( '2026-09-23 10:00:00' );
			}

			/**
			 * Returns the fields.
			 *
			 * @return array<string, mixed> None.
			 */
			public function toPayload(): array {
				return array();
			}
		};

		return get_class( $event );
	}

	/**
	 * Returns the name the stand-in event class reports now.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public static function standInName(): string {
		return self::$standInName;
	}
}

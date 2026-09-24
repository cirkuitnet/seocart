<?php
/**
 * Tests the generated hooks reference: event actions and filters, each documented from its own declaration
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Docs\FieldDocs;
use SEOCart\Tools\Docs\HooksReference;
use SEOCart\Tools\Docs\Tests\Fixtures\Events\FixtureAggregateReadsPropertyEvent;
use SEOCart\Tools\Docs\Tests\Fixtures\Events\FixtureBinRestocked;
use SEOCart\Tools\Docs\Tests\Fixtures\Events\FixtureBothBrokenEvent;
use SEOCart\Tools\Docs\Tests\Fixtures\Events\FixtureCartAbandoned;
use SEOCart\Tools\Docs\Tests\Fixtures\Events\FixtureEnumEvent;
use SEOCart\Tools\Docs\Tests\Fixtures\Events\FixtureMissingParamEvent;
use SEOCart\Tools\Docs\Tests\Fixtures\Events\FixtureNonPromotedEvent;
use SEOCart\Tools\Docs\Tests\Fixtures\Events\FixtureValidatingEvent;
use SEOCart\Tools\Docs\Tests\Fixtures\Hooks\FixtureAaaFilter;
use SEOCart\Tools\Docs\Tests\Fixtures\Hooks\FixtureFilterMissingSince;
use SEOCart\Tools\Docs\Tests\Fixtures\Hooks\FixtureToggleFilter;

/**
 * Proves the generator with fixture events and a fixture filter declaration: the expected
 * markdown for a working pair of each, sorted by hook name together; the refusals that keep an
 * event or a filter from being silently under-documented; and that a validating constructor or an
 * enum-typed property is documented without ever constructing the event.
 *
 * @since 0.1.0
 */
final class HooksReferenceTest extends TestCase {

	/**
	 * Tests that two fixture events (one outbox, one after-commit) and a fixture filter produce
	 * exactly the expected document, sections sorted by hook name.
	 *
	 * @since 0.1.0
	 */
	public function test_fixture_events_and_a_fixture_filter_produce_the_expected_markdown(): void {
		$generator = new HooksReference(
			array( FixtureCartAbandoned::class, FixtureBinRestocked::class ),
			array( FixtureToggleFilter::class )
		);

		$result = $generator->generate( '' );

		$this->assertSame( array(), $result->skipped );
		$this->assertSame( 'docs/reference/hooks.md', $generator->target() );

		$expected = implode(
			"\n",
			array(
				HooksReference::TITLE,
				'',
				FieldDocs::generatedNotice( 'the event catalog and the filter declarations' ),
				'',
				HooksReference::INTRO,
				'',
				'## `seocart_bin_restocked`',
				'',
				'A bin in the fixture warehouse was restocked.',
				'',
				'- Delivery: `outbox`. Fires from the outbox after commit, at least once.',
				'- Payload version: 1',
				'- Aggregate type: `bin`',
				'- Listener arguments: `( DomainEvent $event, EventEnvelope $envelope )`',
				'- Register with: `add_action( \'seocart_bin_restocked\', $callback, 10, 2 )`; the first argument is a `' . FixtureBinRestocked::class . '`.',
				'',
				'### Event properties',
				'',
				'- `binId` (int): The bin that was restocked.',
				'- `quantity` (int): The number of units added.',
				'- `reason` (string): Why the bin was restocked, for example `delivery`.',
				'',
				'## `seocart_cart_abandoned`',
				'',
				'A fixture cart was abandoned.',
				'',
				'- Delivery: `after_commit`. Fires after commit.',
				'- Payload version: 2',
				'- Aggregate type: `cart`',
				'- Listener arguments: `( DomainEvent $event, EventEnvelope $envelope )`',
				'- Register with: `add_action( \'seocart_cart_abandoned\', $callback, 10, 2 )`; the first argument is a `' . FixtureCartAbandoned::class . '`.',
				'',
				'### Event properties',
				'',
				'- `cartId` (int): The cart that was abandoned.',
				'',
				'## `seocart_fixture_toggle`',
				'',
				"Whether the fixture warehouse's restock notice is sent.",
				'',
				'- Value: `bool`. Whether to send the notice.',
				'- Default `false`.',
				'- Since: 0.2.0',
				'- Returning `true`: Sends the notice.',
				'- Returning `false`: Sends nothing.',
			)
		) . "\n";

		$this->assertSame( $expected, $result->content );
	}

	/**
	 * Tests that events and filters are sorted together by hook name, not filters always after
	 * events: a filter whose name sorts before an event's appears before it, and the event still
	 * appears before a filter that sorts after it.
	 *
	 * @since 0.1.0
	 */
	public function test_events_and_filters_are_sorted_together_by_hook_name(): void {
		$content = ( new HooksReference( array( FixtureBinRestocked::class ), array( FixtureToggleFilter::class, FixtureAaaFilter::class ) ) )->generate( '' )->content;

		$aaa    = strpos( $content, '## `seocart_aaa_filter`' );
		$bin    = strpos( $content, '## `seocart_bin_restocked`' );
		$toggle = strpos( $content, '## `seocart_fixture_toggle`' );

		$this->assertIsInt( $aaa );
		$this->assertIsInt( $bin );
		$this->assertIsInt( $toggle );
		$this->assertLessThan( $bin, $aaa, 'seocart_aaa_filter sorts before seocart_bin_restocked and must appear before it.' );
		$this->assertLessThan( $toggle, $bin, 'seocart_bin_restocked sorts before seocart_fixture_toggle and must appear before it.' );
	}

	/**
	 * Tests that a document with no events still documents its filter.
	 *
	 * @since 0.1.0
	 */
	public function test_no_events_still_documents_the_filter(): void {
		$content = ( new HooksReference( array(), array( FixtureToggleFilter::class ) ) )->generate( '' )->content;

		$this->assertStringNotContainsString( '## `seocart_bin_restocked`', $content );
		$this->assertStringContainsString( '## `seocart_fixture_toggle`', $content );
	}

	/**
	 * Tests that a validating constructor (one that throws on an invalid argument, like the
	 * inventory module's hold events) is documented without ever being constructed.
	 *
	 * @since 0.1.0
	 */
	public function test_a_validating_constructor_documents_without_being_constructed(): void {
		$content = ( new HooksReference( array( FixtureValidatingEvent::class ), array() ) )->generate( '' )->content;

		$this->assertStringContainsString( '## `seocart_fixture_validated`', $content );
		$this->assertStringContainsString( '- Aggregate type: `variant`', $content );
		$this->assertStringContainsString( '- `variantIds` (int[]): The variants reserved; must not be empty.', $content );
	}

	/**
	 * Tests that an enum-typed promoted property documents cleanly.
	 *
	 * @since 0.1.0
	 */
	public function test_an_enum_typed_property_documents_cleanly(): void {
		$content = ( new HooksReference( array( FixtureEnumEvent::class ), array() ) )->generate( '' )->content;

		$this->assertStringContainsString( '## `seocart_fixture_enum`', $content );
		$this->assertStringContainsString( '- Aggregate type: `bin`', $content );
		$this->assertStringContainsString( '- `reason` (FixtureReservationReason): Why it was reserved.', $content );
	}

	/**
	 * Tests that a payload property without an @param sentence is refused, naming the class and property.
	 *
	 * @since 0.1.0
	 */
	public function test_a_payload_property_without_an_at_param_sentence_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( FixtureMissingParamEvent::class . ': property $quantity has no @param sentence' );

		( new HooksReference( array( FixtureMissingParamEvent::class ), array() ) )->generate( '' );
	}

	/**
	 * Tests that an event whose payload property is not constructor-promoted is refused, naming
	 * the offending property and how to fix it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_non_promoted_payload_property_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( FixtureNonPromotedEvent::class . ': $binId is not a promoted property; declare it as `public readonly <type> $name` in the constructor signature, with an @param sentence.' );

		( new HooksReference( array( FixtureNonPromotedEvent::class ), array() ) )->generate( '' );
	}

	/**
	 * Tests that an event whose aggregateType() reads a property instead of returning a constant
	 * is refused, naming the class, instead of crashing on an uninitialized-property error.
	 *
	 * @since 0.1.0
	 */
	public function test_an_aggregate_type_that_reads_a_property_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( FixtureAggregateReadsPropertyEvent::class . '::aggregateType() must return a constant; it may not read a property.' );

		( new HooksReference( array( FixtureAggregateReadsPropertyEvent::class ), array() ) )->generate( '' );
	}

	/**
	 * Tests that when an event is broken two ways at once, event properties are read first: the
	 * missing @param sentence is reported, never masked by the aggregate-type failure that would
	 * follow it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_event_properties_failure_is_reported_before_the_aggregate_type_one(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( FixtureBothBrokenEvent::class . ': property $a has no @param sentence' );

		( new HooksReference( array( FixtureBothBrokenEvent::class ), array() ) )->generate( '' );
	}

	/**
	 * Tests that a filter declaration whose class doc comment has no @since tag is refused, naming the class.
	 *
	 * @since 0.1.0
	 */
	public function test_a_filter_without_an_at_since_tag_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( FixtureFilterMissingSince::class . ' has no @since tag' );

		( new HooksReference( array(), array( FixtureFilterMissingSince::class ) ) )->generate( '' );
	}

	/**
	 * Tests that a class that does not implement DomainEvent is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_class_that_is_not_a_domain_event_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'does not implement' );

		// @phpstan-ignore argument.type (the point of the test: a class that is not a DomainEvent)
		( new HooksReference( array( self::class ), array() ) )->generate( '' );
	}

	/**
	 * Rule 14: the hand-written preamble never grows unnoticed. Bump these numbers only as a
	 * deliberate change to the preamble text, reviewed like any other.
	 *
	 * @since 0.1.0
	 */
	public function test_the_hand_written_preamble_byte_count_is_pinned(): void {
		$this->assertSame( 7, strlen( HooksReference::TITLE ), 'HooksReference::TITLE grew or shrank; update this pin deliberately.' );
		$this->assertSame( 200, strlen( HooksReference::INTRO ), 'HooksReference::INTRO grew or shrank; update this pin deliberately.' );
	}

	/**
	 * Tests that regenerating the fixture output is idempotent: it does not depend on $current.
	 *
	 * @since 0.1.0
	 */
	public function test_generation_does_not_depend_on_current_content(): void {
		$generator = new HooksReference( array( FixtureBinRestocked::class ), array( FixtureToggleFilter::class ) );

		$this->assertSame( $generator->generate( '' )->content, $generator->generate( 'anything at all' )->content );
	}
}

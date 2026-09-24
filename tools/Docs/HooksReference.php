<?php
/**
 * HooksReference: generates docs/reference/hooks.md from the event catalog and the filter declarations
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Hooks\FilterDeclaration;
use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

/**
 * Documents every public `seocart_` hook: the action the event bridge fires for each catalogued
 * domain event, and every filter the plugin applies. One section per hook, sorted by hook name.
 *
 * This generator owns docs/reference/hooks.md whole. WordPress is never loaded: the events are
 * the classes listed in Modules::EVENT_CLASSES, which a companion test (KernelListsTest) holds
 * equal to every domain event class under src/, so this generator cannot drift from that search
 * without also failing that test — it reads the list itself rather than repeating the search.
 * The filters are the classes listed in FilterDeclarations::ALL, which FilterDeclarationsTest
 * holds equal the same way.
 *
 * An event documents itself: the class doc comment's first sentence is its description, its
 * delivery mode and payload version come from its own static methods, and its "Event properties"
 * are its constructor's promoted properties (except `occurredAt`, which the envelope and
 * fromPayload() carry outside the payload) with the type and description of each one's `@param`
 * tag. That heading names what a listener reads on the event object it is handed — `$event->sku`,
 * not a payload array — deliberately not `toPayload()`'s wire keys, which are typically
 * snake_case where the properties are camelCase: each event's own module holds a unit test that
 * keeps `toPayload()`'s keys equal to its property names (the catalog and inventory branches each
 * have one), so the two can never silently diverge even though this generator never calls
 * `toPayload()` itself.
 *
 * The aggregate type comes from calling aggregateType() on an instance built with
 * newInstanceWithoutConstructor(): the constructor never runs, so a validating constructor (one
 * that throws on an invalid payload, as some events do) is never called, and every property is
 * left uninitialized. aggregateType() must therefore be a constant of the class, never a read of
 * `$this` — PHP raises when an uninitialized typed property is read, and that failure becomes a
 * message naming the class. No method is added to DomainEvent, and nothing here does I/O.
 *
 * A filter documents itself the same way: its class doc comment's first sentence and `@since`
 * tag, and the name, value, default and effects its FilterDeclaration methods return.
 *
 * Nothing is skipped: an event or a filter that cannot be rendered fails the run.
 *
 * @since 0.1.0
 */
final class HooksReference implements Generator {

	/**
	 * The document's title. Hand-written; its byte count is pinned by a test (rule 14).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TITLE = '# Hooks';

	/**
	 * The document's introduction. Hand-written; its byte count is pinned by a test (rule 14).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INTRO = 'Every public `seocart_` hook: the action the event bridge fires for each catalogued domain event, and every filter the plugin applies. A hook not listed here is internal and may change without notice.';

	/**
	 * The listener arguments every bridged event's action is called with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LISTENER_ARGUMENTS = '( DomainEvent $event, EventEnvelope $envelope )';

	/**
	 * The event classes to document.
	 *
	 * @since 0.1.0
	 *
	 * @var list<class-string<DomainEvent>>
	 */
	private array $events;

	/**
	 * The filter declaration classes to document.
	 *
	 * @since 0.1.0
	 *
	 * @var list<class-string<FilterDeclaration>>
	 */
	private array $filters;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array $events  The event classes, typically Modules::EVENT_CLASSES.
	 * @param array $filters The filter declaration classes, typically FilterDeclarations::ALL.
	 *
	 * @phpstan-param list<class-string<DomainEvent>>       $events
	 * @phpstan-param list<class-string<FilterDeclaration>> $filters
	 */
	public function __construct( array $events, array $filters ) {
		$this->events  = $events;
		$this->filters = $filters;
	}

	/**
	 * Returns the generator's stable identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string The identifier.
	 */
	public function id(): string {
		return 'reference-hooks';
	}

	/**
	 * Returns the file this generator owns.
	 *
	 * @since 0.1.0
	 *
	 * @return string Path relative to the repository root.
	 */
	public function target(): string {
		return 'docs/reference/hooks.md';
	}

	/**
	 * Returns the source items this generator may leave out: none.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Always empty.
	 */
	public function expectedSkips(): array {
		return array();
	}

	/**
	 * Renders the document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When an event or a filter cannot be rendered.
	 *
	 * @param string $current The current content. Ignored: the file is generated whole.
	 * @return GenerationResult The document. Nothing is skipped.
	 */
	public function generate( string $current ): GenerationResult {
		$lines = array(
			self::TITLE,
			'',
			FieldDocs::generatedNotice( 'the event catalog and the filter declarations' ),
			'',
			self::INTRO,
		);

		// Events and filters share one hook-name namespace (`seocart_…`), so they are sorted
		// together into a single map, not two separately sorted groups.
		$sections = array();

		foreach ( $this->events as $eventClass ) {
			if ( ! is_subclass_of( $eventClass, DomainEvent::class ) ) {
				throw new \RuntimeException( $eventClass . ' does not implement ' . DomainEvent::class . '.' );
			}

			$sections[ EventEnvelope::hookFor( $eventClass::eventName() ) ] = $this->renderEvent( $eventClass );
		}

		foreach ( $this->filters as $filterClass ) {
			if ( ! is_subclass_of( $filterClass, FilterDeclaration::class ) ) {
				throw new \RuntimeException( $filterClass . ' does not implement ' . FilterDeclaration::class . '.' );
			}

			$sections[ $filterClass::name() ] = $this->renderFilter( $filterClass );
		}

		ksort( $sections );

		foreach ( $sections as $section ) {
			array_push( $lines, '', ...$section );
		}

		return new GenerationResult( implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Renders one event's section.
	 *
	 * Reads the event properties before the aggregate type, so a property that cannot be
	 * documented is the failure reported, rather than being masked by a later one.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When a constructor parameter is not promoted, a promoted parameter
	 *                           has no `@param` sentence, or aggregateType() reads a property.
	 *
	 * @param string $eventClass The event class. Already proven to implement DomainEvent.
	 *
	 * @phpstan-param class-string<DomainEvent> $eventClass
	 *
	 * @return list<string> The section's lines, starting with its `## ` heading.
	 */
	private function renderEvent( string $eventClass ): array {
		$reflection = new \ReflectionClass( $eventClass );
		$hook       = EventEnvelope::hookFor( $eventClass::eventName() );
		$delivery   = $eventClass::deliveryMode();
		$fields     = $this->payloadFields( $reflection, $eventClass );
		$aggregate  = $this->aggregateTypeOf( $reflection, $eventClass );

		$lines = array(
			'## `' . $hook . '`',
			'',
			DocBlockText::firstSentence( (string) $reflection->getDocComment() ),
			'',
			'- Delivery: `' . $delivery->value . '`. ' . ( DeliveryMode::Outbox === $delivery ? 'Fires from the outbox after commit, at least once.' : 'Fires after commit.' ),
			'- Payload version: ' . $eventClass::payloadVersion(),
			'- Aggregate type: `' . $aggregate . '`',
			'- Listener arguments: `' . self::LISTENER_ARGUMENTS . '`',
			'- Register with: `add_action( \'' . $hook . '\', $callback, 10, 2 )`; the first argument is a `' . $eventClass . '`.',
			'',
			'### Event properties',
			'',
		);

		foreach ( $fields as $field ) {
			$lines[] = '- `' . $field['name'] . '` (' . $field['type'] . '): ' . $field['description'];
		}

		return $lines;
	}

	/**
	 * Renders one filter's section.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the class declares no effect, or its class doc comment has no
	 *                           `@since` tag.
	 *
	 * @param string $filterClass The filter declaration class. Already proven to implement FilterDeclaration.
	 *
	 * @phpstan-param class-string<FilterDeclaration> $filterClass
	 *
	 * @return list<string> The section's lines, starting with its `## ` heading.
	 */
	private function renderFilter( string $filterClass ): array {
		$since = DocBlockText::since( (string) ( new \ReflectionClass( $filterClass ) )->getDocComment() );

		if ( null === $since ) {
			throw new \RuntimeException( $filterClass . ' has no @since tag in its class doc comment.' );
		}

		$effects = $filterClass::effects();

		if ( array() === $effects ) {
			throw new \RuntimeException( $filterClass . '::effects() lists nothing; a filter with no documented effect is not a filter worth having.' );
		}

		$lines = array(
			'## `' . $filterClass::name() . '`',
			'',
			DocBlockText::firstSentence( (string) ( new \ReflectionClass( $filterClass ) )->getDocComment() ),
			'',
			'- Value: `' . $filterClass::valueType() . '`. ' . $filterClass::valueDescription(),
			'- ' . FieldDocs::defaultValue( var_export( $filterClass::defaultValue(), true ) ),
			'- Since: ' . $since,
		);

		foreach ( $effects as $value => $effect ) {
			$lines[] = '- Returning `' . $value . '`: ' . $effect;
		}

		return $lines;
	}

	/**
	 * Returns the event's properties: its constructor's promoted properties, except `occurredAt`.
	 *
	 * Every constructor parameter but `occurredAt` must be promoted, and every one of those must
	 * carry an `@param` sentence; either failure is reported for every offending parameter of the
	 * class in one message, not one at a time.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the class has no constructor, a parameter is not promoted, or
	 *                           a promoted parameter has no `@param` sentence.
	 *
	 * @param \ReflectionClass $reflection The event's reflection.
	 * @param string           $eventClass The event class, for the exception message.
	 *
	 * @phpstan-param \ReflectionClass<DomainEvent> $reflection
	 * @phpstan-param class-string<DomainEvent>     $eventClass
	 *
	 * @return list<array{name: string, type: string, description: string}> The fields, in
	 *                                                                      constructor order.
	 */
	private function payloadFields( \ReflectionClass $reflection, string $eventClass ): array {
		$constructor = $reflection->getConstructor();

		if ( null === $constructor ) {
			throw new \RuntimeException( $eventClass . ' has no constructor to read the event properties from.' );
		}

		$notPromoted = array();

		foreach ( $constructor->getParameters() as $parameter ) {
			if ( 'occurredAt' === $parameter->getName() || $parameter->isPromoted() ) {
				continue;
			}

			$notPromoted[] = '$' . $parameter->getName();
		}

		if ( array() !== $notPromoted ) {
			$subject = implode( ', ', $notPromoted );

			throw new \RuntimeException(
				$eventClass . ': ' . $subject . ( 1 === count( $notPromoted ) ? ' is not a promoted property; declare it' : ' are not promoted properties; declare each' )
				. ' as `public readonly <type> $name` in the constructor signature, with an @param sentence.'
			);
		}

		$doc    = (string) $constructor->getDocComment();
		$fields = array();

		foreach ( $constructor->getParameters() as $parameter ) {
			$name = $parameter->getName();

			if ( 'occurredAt' === $name ) {
				continue;
			}

			$param = DocBlockText::paramSentence( $doc, $name );

			if ( null === $param ) {
				throw new \RuntimeException( $eventClass . ': property $' . $name . ' has no @param sentence in the constructor doc comment.' );
			}

			$fields[] = array(
				'name'        => $name,
				'type'        => ltrim( $param['type'], '\\' ),
				'description' => $param['description'],
			);
		}

		return $fields;
	}

	/**
	 * Returns an event's aggregate type, by calling aggregateType() on an instance built with
	 * newInstanceWithoutConstructor().
	 *
	 * The constructor never runs — a validating one is never called, and no argument is
	 * synthesized — so aggregateType() must be a constant of the class rather than a read of
	 * `$this`; reading an uninitialized property throws, which is turned into a message naming
	 * the class instead of a fatal error.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When aggregateType() reads a property instead of returning a constant.
	 *
	 * @param \ReflectionClass $reflection The event's reflection.
	 * @param string           $eventClass The event class, for the exception message.
	 *
	 * @phpstan-param \ReflectionClass<DomainEvent> $reflection
	 * @phpstan-param class-string<DomainEvent>     $eventClass
	 *
	 * @return string The aggregate type.
	 */
	private function aggregateTypeOf( \ReflectionClass $reflection, string $eventClass ): string {
		try {
			$instance = $reflection->newInstanceWithoutConstructor();

			return $instance->aggregateType();
		} catch ( \Throwable $failure ) {
			throw new \RuntimeException( $eventClass . '::aggregateType() must return a constant; it may not read a property.', 0, $failure );
		}
	}
}

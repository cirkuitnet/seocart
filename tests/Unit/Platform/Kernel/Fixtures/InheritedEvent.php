<?php
/**
 * InheritedEvent: a fixture event that implements DomainEvent only through its base class
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Kernel\Fixtures;

/**
 * A concrete event whose declaration never names DomainEvent, which the domain-event search must still find.
 *
 * @since 0.1.0
 */
final readonly class InheritedEvent extends InheritedEventBase {
}

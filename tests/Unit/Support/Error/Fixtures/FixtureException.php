<?php
/**
 * FixtureException: a module's own subclass of the typed exception base
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\Error\Fixtures;

use SEOCart\Support\Error\CodedException;

/**
 * Stands in for a module's exception class, which exists so callers can catch it by type.
 *
 * @since 0.1.0
 */
final class FixtureException extends CodedException {
}

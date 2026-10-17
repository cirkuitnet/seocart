<?php
/**
 * SourcePlant: a plant whose fault is in its source
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

/**
 * Marks a plant the suite proves by reading its source, never by running it.
 *
 * Running it would do what it plants, a request outside the plugin's client among them, so the
 * plant tests of the integration suite leave it out, and the suite's unit tests read it.
 *
 * @since 0.2.0
 */
interface SourcePlant {
}

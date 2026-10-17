<?php
/**
 * TapeRefused: a recording that may not be written
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * Raised when a recording would write a credential or card data into a tape, or could not be scrubbed safely.
 *
 * Nothing of the recording is written when it is raised.
 *
 * @since 0.2.0
 */
final class TapeRefused extends \RuntimeException {
}

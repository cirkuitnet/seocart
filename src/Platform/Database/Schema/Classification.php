<?php
/**
 * Classification: the privacy class of a stored column
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * What a column's values are, for privacy purposes.
 *
 * Owns one fact: the four classes every column is declared with. The privacy exporter and
 * eraser, log redaction, REST visibility and the support-bundle filter are generated from
 * these declarations by the data registry; none of them classifies a column again.
 *
 * @since 0.1.0
 */
enum Classification: string {

	/**
	 * Carries nothing about a person and nothing secret.
	 *
	 * @since 0.1.0
	 */
	case Public = 'public';

	/**
	 * Identifies or describes a person: exported and erased, redacted in logs.
	 *
	 * @since 0.1.0
	 */
	case Pii = 'pii';

	/**
	 * A credential or key: never serialized to REST, never logged, never exported.
	 *
	 * @since 0.1.0
	 */
	case Secret = 'secret';

	/**
	 * A monetary record kept under the financial retention policy.
	 *
	 * @since 0.1.0
	 */
	case Financial = 'financial';
}

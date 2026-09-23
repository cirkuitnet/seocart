<?php
/**
 * Privacy: the privacy class a field is declared with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * What a field's values are, for privacy purposes.
 *
 * This enum owns one fact for the wire: the four classes every field is declared with, and so
 * which surface may carry its value. ResourceSchema applies them when an output is serialized:
 * a secret is never serialized by any surface, and personal data only for a user who may see
 * it. The personal-data exporter and eraser are generated from the same declarations.
 *
 * The database layer declares the same four classes, with the same values, for the columns of
 * its tables. They are one list: when both are on the main branch, one enum replaces the other.
 *
 * @since 0.1.0
 */
enum Privacy: string {

	/**
	 * Carries nothing about a person and nothing secret. Every surface may serialize it.
	 *
	 * @since 0.1.0
	 */
	case Public = 'public';

	/**
	 * Identifies or describes a person. Serialized only for a user who may see personal data.
	 *
	 * @since 0.1.0
	 */
	case Pii = 'pii';

	/**
	 * A credential or a key. Accepted as input where an operation needs it; never serialized.
	 *
	 * @since 0.1.0
	 */
	case Secret = 'secret';

	/**
	 * A monetary record kept under the financial retention policy. Serialized like a public field.
	 *
	 * @since 0.1.0
	 */
	case Financial = 'financial';
}

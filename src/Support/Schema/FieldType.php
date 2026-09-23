<?php
/**
 * FieldType: the value types a field can be declared with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The type of a field's value on the wire.
 *
 * This enum owns one fact: which value types a field declaration may use. JsonSchemaCompiler
 * writes each case in every dialect; nothing else maps a case to a JSON-Schema type. A case is
 * added together with the first operation that needs it.
 *
 * @since 0.1.0
 */
enum FieldType {

	/**
	 * A whole number, positive or negative, such as a quantity or a signed change to one.
	 *
	 * @since 0.1.0
	 */
	case Integer;

	/**
	 * Text: free text, or one value of a closed set when the field lists its values.
	 *
	 * @since 0.1.0
	 */
	case String;

	/**
	 * A resource identifier: a lower-case RFC 4122 uuid, carried as a string.
	 *
	 * @since 0.1.0
	 */
	case Uuid;
}

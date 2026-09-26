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
 * Object and ObjectList are the two composite types: their value is made of the fields the
 * declaration lists (FieldSpec::object() and FieldSpec::objectList()), and a key it does not list
 * is refused on input and dropped on output.
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

	/**
	 * True or false, such as whether a line was added by a promotion.
	 *
	 * @since 0.1.0
	 */
	case Boolean;

	/**
	 * An object whose members are the fields the declaration lists, such as a cart's totals.
	 *
	 * @since 0.1.0
	 */
	case Object;

	/**
	 * A list of objects, each made of the fields the declaration lists, such as a cart's lines.
	 *
	 * @since 0.1.0
	 */
	case ObjectList;

	/**
	 * Tells whether a value of this type is made of declared fields.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for Object and ObjectList.
	 */
	public function isComposite(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return self::Object === $this || self::ObjectList === $this;
	}
}

<?php
/**
 * FieldSpec: the one declaration of a field of an operation's input or output
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * One field: the only place its name, type, constraints, default, example, texts and privacy
 * class are written down.
 *
 * This class owns one fact: what a field is. The REST arguments and response schema, the Ability
 * schemas, the WP-CLI synopsis, the OpenAPI document and the reference documents are all compiled
 * from it by JsonSchemaCompiler, and none of them restates any part of it.
 *
 * - The wire name is declared, never derived from a storage column: the wire contract and the
 *   storage schema change for different reasons.
 * - The machine description is an English sentence for API clients and the generated documents.
 *   It is never translated, so the documents are generated the same way everywhere.
 * - The label is for people, in forms: a closure around a literal gettext call, translated only
 *   when a screen renders it. Declaring a field translates nothing.
 * - The constraints are the ones the operations built so far need, and no more: `allowed` (a
 *   closed set of text values, the JSON-Schema `enum`), `max_length` (free text), `minimum` and
 *   `maximum` (bounded integers), the uuid format (FieldType::Uuid), `default_value` (what an
 *   absent optional input becomes) and `nullable` (an output value that may be null). A
 *   constraint is refused on a type it does not apply to, so a declaration cannot say something
 *   no dialect would enforce.
 * - Every field has an example. A contract test validates each example against the field's own
 *   compiled schemas, so the documentation never shows a value the API would refuse.
 * - `privacy` decides which surface may carry the value (see Privacy), and `translatable` marks
 *   merchant-authored text that has a value per language. The flag is carried from the start and
 *   is not read by anything yet.
 *
 * Declarations are data: constructing a field performs no I/O, calls no WordPress function and
 * translates nothing.
 *
 * @since 0.1.0
 */
final class FieldSpec {

	/**
	 * The shape of a wire name: lower-case snake_case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

	/**
	 * The wire name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The value type.
	 *
	 * @since 0.1.0
	 *
	 * @var FieldType
	 */
	private FieldType $type;

	/**
	 * The machine description: one English sentence for API clients and the generated documents.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * Returns the label, translated when called.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): string
	 */
	private \Closure $label;

	/**
	 * A value the field accepts, shown in the documentation.
	 *
	 * @since 0.1.0
	 *
	 * @var int|string
	 */
	private int|string $example;

	/**
	 * Whether the field must be present: in a request for an input, in a result for an output.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $required;

	/**
	 * Whether null is a valid value.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $nullable;

	/**
	 * The value an absent optional input takes, or null for none.
	 *
	 * @since 0.1.0
	 *
	 * @var int|string|null
	 */
	private int|string|null $defaultValue;

	/**
	 * The values a text field is limited to, or empty for any text.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $allowedValues;

	/**
	 * The most characters a text field may hold, or null for no limit.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $maxLength;

	/**
	 * The smallest value an integer field may hold, or null for no bound.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $minimum;

	/**
	 * The largest value an integer field may hold, or null for no bound.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $maximum;

	/**
	 * The privacy class.
	 *
	 * @since 0.1.0
	 *
	 * @var Privacy
	 */
	private Privacy $privacy;

	/**
	 * Whether the value is merchant-authored text with a value per language.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $translatable;

	/**
	 * Declares a field.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the name is not snake_case, the description is empty, a
	 *                         constraint does not apply to the type, the bounds are reversed, a
	 *                         required field has a default, or an enumerated field is nullable.
	 *
	 * @param string          $name          The wire name, lower-case snake_case.
	 * @param FieldType       $type          The value type.
	 * @param string          $description   The machine description: one English sentence.
	 * @param \Closure        $label         Returns the form label through a literal gettext call.
	 * @param int|string      $example       A value the field accepts.
	 * @param bool            $required      Optional. Whether the field must be present. Default false.
	 * @param bool            $nullable      Optional. Whether null is a valid value. Default false.
	 * @param int|string|null $default_value Optional. The value an absent optional input takes. Default none.
	 * @param string[]        $allowed       Optional. The values a String field is limited to. Default any.
	 * @param int|null        $max_length    Optional. The most characters a String field may hold. Default no limit.
	 * @param int|null        $minimum       Optional. The smallest value of an Integer field. Default no bound.
	 * @param int|null        $maximum       Optional. The largest value of an Integer field. Default no bound.
	 * @param Privacy         $privacy       Optional. The privacy class. Default Privacy::Public.
	 * @param bool            $translatable  Optional. Whether a String field has a value per language. Default false.
	 *
	 * @phpstan-param \Closure(): string $label
	 * @phpstan-param list<string>       $allowed
	 */
	public function __construct(
		string $name,
		FieldType $type,
		string $description,
		\Closure $label,
		int|string $example,
		bool $required = false,
		bool $nullable = false,
		int|string|null $default_value = null,
		array $allowed = array(),
		?int $max_length = null,
		?int $minimum = null,
		?int $maximum = null,
		Privacy $privacy = Privacy::Public,
		bool $translatable = false
	) {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			SchemaException::raise( 'The field name "%1$s" is not lower-case snake_case, such as item_id.', $name );
		}

		if ( '' === trim( $description ) || 1 === preg_match( '/[\r\n]/', $description ) ) {
			SchemaException::raise( 'The field %1$s needs a machine description: one non-empty English sentence on one line.', $name );
		}

		$is_text = FieldType::String === $type;

		if ( array() !== $allowed && ! $is_text ) {
			SchemaException::raise( 'The field %1$s lists values, but only a String field can be limited to a set of values.', $name );
		}

		if ( null !== $max_length && ( ! $is_text || $max_length < 1 ) ) {
			SchemaException::raise( 'The field %1$s declares a maximum length, which applies to a String field only and must be at least 1.', $name );
		}

		if ( ( null !== $minimum || null !== $maximum ) && FieldType::Integer !== $type ) {
			SchemaException::raise( 'The field %1$s declares a minimum or a maximum, which applies to an Integer field only.', $name );
		}

		if ( null !== $minimum && null !== $maximum && $minimum > $maximum ) {
			SchemaException::raise( 'The field %1$s declares a minimum greater than its maximum.', $name );
		}

		if ( $required && null !== $default_value ) {
			SchemaException::raise( 'The field %1$s is required and has a default; a default belongs to an optional field only.', $name );
		}

		if ( $nullable && array() !== $allowed ) {
			SchemaException::raise( 'The field %1$s lists values and is nullable; null would have to be one of the values in every dialect, so declare it not nullable.', $name );
		}

		if ( $translatable && ! $is_text ) {
			SchemaException::raise( 'The field %1$s is translatable, which only a String field can be.', $name );
		}

		foreach ( $allowed as $index => $value ) {
			// @phpstan-ignore function.alreadyNarrowedType (Declarations are written by hand; a stray non-string value must be refused, not compiled.)
			if ( ! is_string( $value ) || '' === $value || array_search( $value, $allowed, true ) !== $index ) {
				SchemaException::raise( 'The values of the field %1$s must be distinct, non-empty strings.', $name );
			}
		}

		$this->name          = $name;
		$this->type          = $type;
		$this->description   = $description;
		$this->label         = $label;
		$this->example       = $example;
		$this->required      = $required;
		$this->nullable      = $nullable;
		$this->defaultValue  = $default_value;
		$this->allowedValues = $allowed;
		$this->maxLength     = $max_length;
		$this->minimum       = $minimum;
		$this->maximum       = $maximum;
		$this->privacy       = $privacy;
		$this->translatable  = $translatable;
	}

	/**
	 * Returns a copy of the field that need not be present.
	 *
	 * ResourceSchema uses it for a personal-data field, which a serialized output leaves out for
	 * a user who may not see it, so no compiled output schema may call it required.
	 *
	 * @since 0.1.0
	 *
	 * @return self The copy. This field is left unchanged.
	 */
	public function asOptional(): self {
		$copy           = clone $this;
		$copy->required = false;

		return $copy;
	}

	/**
	 * Returns the wire name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the value type.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldType The type.
	 */
	public function type(): FieldType {
		return $this->type;
	}

	/**
	 * Returns the machine description.
	 *
	 * @since 0.1.0
	 *
	 * @return string One English sentence, never translated.
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * Returns the label closure. Call it only when rendering, after `init`: that is when it translates.
	 *
	 * The settings forms are its first consumer.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(): string The closure.
	 */
	public function label(): \Closure {
		return $this->label;
	}

	/**
	 * Returns the example.
	 *
	 * @since 0.1.0
	 *
	 * @return int|string A value the field accepts.
	 */
	public function example(): int|string {
		return $this->example;
	}

	/**
	 * Tells whether the field must be present.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when required.
	 */
	public function isRequired(): bool {
		return $this->required;
	}

	/**
	 * Tells whether null is a valid value.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when nullable.
	 */
	public function isNullable(): bool {
		return $this->nullable;
	}

	/**
	 * Returns the value an absent optional input takes.
	 *
	 * @since 0.1.0
	 *
	 * @return int|string|null The default, or null for none.
	 */
	public function defaultValue(): int|string|null {
		return $this->defaultValue;
	}

	/**
	 * Returns the values a text field is limited to.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The values, or an empty list for any text.
	 */
	public function allowedValues(): array {
		return $this->allowedValues;
	}

	/**
	 * Returns the most characters a text field may hold.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The limit, or null for none.
	 */
	public function maxLength(): ?int {
		return $this->maxLength;
	}

	/**
	 * Returns the smallest value an integer field may hold.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The bound, or null for none.
	 */
	public function minimum(): ?int {
		return $this->minimum;
	}

	/**
	 * Returns the largest value an integer field may hold.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The bound, or null for none.
	 */
	public function maximum(): ?int {
		return $this->maximum;
	}

	/**
	 * Returns the privacy class.
	 *
	 * @since 0.1.0
	 *
	 * @return Privacy The class.
	 */
	public function privacy(): Privacy {
		return $this->privacy;
	}

	/**
	 * Tells whether the value is merchant-authored text with a value per language.
	 *
	 * Carried from the start; the multilingual catalog is its first reader.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when translatable.
	 */
	public function isTranslatable(): bool {
		return $this->translatable;
	}
}

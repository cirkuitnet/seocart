<?php
/**
 * JsonSchemaCompiler: writes field declarations in each schema dialect a surface reads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Compiles a list of FieldSpec into one dialect, one public function per dialect.
 *
 * This class owns one fact: how a field declaration is written in each schema dialect SEOCart
 * hands to WordPress, WP-CLI or a client. It is the only place in the plugin where a
 * JSON-Schema array is spelled out, and each of its functions has exactly one call site, which
 * a contract test proves, so no surface can compile a dialect its own way.
 *
 * The dialects differ in exactly these ways, each asserted by the unit test of this class:
 *
 * - restArguments(), WordPress REST `args`: one schema per argument, `required` a boolean on each
 *   argument, and `default` applied by WordPress to an absent argument.
 * - wordPressSchema(), the WordPress REST response schema and the Ability input and output
 *   schemas: one object schema declaring draft 4, `required` a list on the object, `default`
 *   documentation only.
 * - openApiSchema(), OpenAPI 3.1, which is JSON Schema 2020-12: one object schema with no
 *   `$schema` (the document declares its dialect once), `required` a list on the object, and the
 *   example as `examples`.
 * - cliSynopsis(), WP-CLI: a list of positional and `--name=<value>` arguments, `optional` per
 *   argument, a text default, the allowed values as `options`, and the `--format` option every
 *   command prints its result with.
 * - restObjectProperty(), one object-valued property of a WordPress core-shaped REST resource,
 *   such as a post type's: `context` on the property and on each of its properties, `readonly`
 *   on the ones a client cannot write, `additionalProperties: false`, and no `required` at all,
 *   because such a resource is written by partial updates.
 *
 * In the four JSON dialects a nullable field has the type list [type, "null"]; none of them uses
 * the `nullable` keyword of OpenAPI 3.0. A uuid is a string with `format: uuid` in all four.
 *
 * Nothing here calls WordPress. The texts are the machine descriptions, in English.
 *
 * @since 0.1.0
 */
final class JsonSchemaCompiler {

	/**
	 * The draft the WordPress REST API and the Abilities API validate against.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WORDPRESS_SCHEMA_DRAFT = 'http://json-schema.org/draft-04/schema#';

	/**
	 * The contexts of a core-shaped REST resource in which a property is sent: every one but `embed`.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const REST_CONTEXTS = array( 'view', 'edit' );

	/**
	 * The context of a property sent only to a client that may edit the resource.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const REST_EDIT_CONTEXT = array( 'edit' );

	/**
	 * The option through which every operation command prints its result.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLI_FORMAT_OPTION = 'format';

	/**
	 * The formats an operation command can print its result in; the first is the default.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const CLI_FORMATS = array( 'table', 'json' );

	/**
	 * Cannot be called: the compiler is used through its static functions only, so each dialect
	 * has no call site but the one its contract test finds.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Compiles fields into WordPress REST route arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields The operation's input fields.
	 * @return array<string, array<string, mixed>> One argument schema per field, keyed by wire name.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	public static function restArguments( array $fields ): array {
		$arguments = array();

		foreach ( $fields as $field ) {
			$argument             = self::keywords( $field );
			$argument['required'] = $field->isRequired();

			$arguments[ $field->name() ] = $argument;
		}

		return $arguments;
	}

	/**
	 * Compiles fields into the object schema WordPress validates REST responses and Abilities with.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $title  The schema title: the resource name or the operation id.
	 * @param FieldSpec[] $fields The fields of the object.
	 * @return array<string, mixed> The schema.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	public static function wordPressSchema( string $title, array $fields ): array {
		return array(
			'$schema' => self::WORDPRESS_SCHEMA_DRAFT,
			'title'   => $title,
		) + self::objectSchema( $fields, false );
	}

	/**
	 * Compiles fields into an OpenAPI 3.1 object schema.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields The fields of the object.
	 * @return array<string, mixed> The schema.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	public static function openApiSchema( array $fields ): array {
		return self::objectSchema( $fields, true );
	}

	/**
	 * Compiles fields into one object-valued property of a WordPress core-shaped REST resource.
	 *
	 * WordPress filters a response by the `context` of each property, nested ones included, skips
	 * a `readonly` property when it derives a route's arguments from the schema, and validates a
	 * written object against its properties. So the property and each of its properties carry
	 * their contexts, `view` and `edit` (a property in $edit_only has `edit` alone), each name in
	 * $read_only is `readonly`, and a written object with any other key is refused. No field may
	 * be required: a client updates such a resource by sending only what it changes.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When a field is required, or a listed name is not one of the fields.
	 *
	 * @param string      $description The property's machine description.
	 * @param FieldSpec[] $fields      Its fields, in the order they are sent.
	 * @param string[]    $read_only   The names of the fields a client cannot write.
	 * @param string[]    $edit_only   The names of the fields sent in the `edit` context only.
	 * @return array<string, mixed> The property's schema.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 * @phpstan-param list<string>    $read_only
	 * @phpstan-param list<string>    $edit_only
	 */
	public static function restObjectProperty( string $description, array $fields, array $read_only, array $edit_only ): array {
		$properties = array();

		foreach ( $fields as $field ) {
			if ( $field->isRequired() ) {
				SchemaException::raise( 'The field %1$s is required, but a property of a core-shaped REST resource is written partially, so none of its fields can be.', $field->name() );
			}

			$property            = self::keywords( $field );
			$property['context'] = in_array( $field->name(), $edit_only, true ) ? self::REST_EDIT_CONTEXT : self::REST_CONTEXTS;

			if ( in_array( $field->name(), $read_only, true ) ) {
				$property['readonly'] = true;
			}

			$properties[ $field->name() ] = $property;
		}

		foreach ( array_merge( $read_only, $edit_only ) as $name ) {
			if ( ! isset( $properties[ $name ] ) ) {
				SchemaException::raise( 'The name %1$s is listed as read-only or edit-only, but it is not one of the fields.', $name );
			}
		}

		return array(
			'description'          => $description,
			'type'                 => 'object',
			'context'              => self::REST_CONTEXTS,
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Compiles fields into a WP-CLI command synopsis.
	 *
	 * The positional fields come first, in the order given; every other field becomes an
	 * `--name=<value>` option; the `--format` option comes last.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When a positional name is not one of the fields, or a field is named
	 *                         like the format option.
	 *
	 * @param FieldSpec[] $fields     The operation's input fields.
	 * @param string[]    $positional The names of the fields given as positional arguments.
	 * @return list<array<string, mixed>> The synopsis, in the array form WP_CLI::add_command() takes.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 * @phpstan-param list<string>    $positional
	 */
	public static function cliSynopsis( array $fields, array $positional ): array {
		$by_name = array();

		foreach ( $fields as $field ) {
			if ( self::CLI_FORMAT_OPTION === $field->name() ) {
				SchemaException::raise( 'The field %1$s has the name of the option every command prints its result with; rename it.', $field->name() );
			}

			$by_name[ $field->name() ] = $field;
		}

		$synopsis = array();

		foreach ( $positional as $name ) {
			if ( ! isset( $by_name[ $name ] ) ) {
				SchemaException::raise( 'The positional argument %1$s is not one of the fields.', $name );
			}

			$synopsis[] = self::cliArgument( 'positional', $by_name[ $name ] );
		}

		foreach ( $fields as $field ) {
			if ( ! in_array( $field->name(), $positional, true ) ) {
				$synopsis[] = self::cliArgument( 'assoc', $field );
			}
		}

		$synopsis[] = array(
			'type'        => 'assoc',
			'name'        => self::CLI_FORMAT_OPTION,
			'description' => 'Render the result in a particular format.',
			'optional'    => true,
			'default'     => self::CLI_FORMATS[0],
			'options'     => self::CLI_FORMATS,
		);

		return $synopsis;
	}

	/**
	 * Compiles fields into an object schema, the shape the two object dialects share.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields        The fields of the object.
	 * @param bool        $with_examples Whether to carry each example, as OpenAPI does.
	 * @return array<string, mixed> The schema: its type, its properties if any, and the required list if any.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	private static function objectSchema( array $fields, bool $with_examples ): array {
		$properties = array();
		$required   = array();

		foreach ( $fields as $field ) {
			$property = self::keywords( $field );

			if ( $with_examples ) {
				$property['examples'] = array( $field->example() );
			}

			$properties[ $field->name() ] = $property;

			if ( $field->isRequired() ) {
				$required[] = $field->name();
			}
		}

		$schema = array( 'type' => 'object' );

		if ( array() !== $properties ) {
			$schema['properties'] = $properties;
		}

		if ( array() !== $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Writes the keywords one field carries in every JSON dialect.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec $field The field.
	 * @return array<string, mixed> The type, format, constraints, default and description.
	 */
	private static function keywords( FieldSpec $field ): array {
		$type     = FieldType::Integer === $field->type() ? 'integer' : 'string';
		$keywords = array( 'type' => $field->isNullable() ? array( $type, 'null' ) : $type );

		if ( FieldType::Uuid === $field->type() ) {
			$keywords['format'] = 'uuid';
		}

		if ( array() !== $field->allowedValues() ) {
			$keywords['enum'] = $field->allowedValues();
		}

		if ( null !== $field->minimum() ) {
			$keywords['minimum'] = $field->minimum();
		}

		if ( null !== $field->maximum() ) {
			$keywords['maximum'] = $field->maximum();
		}

		if ( null !== $field->maxLength() ) {
			$keywords['maxLength'] = $field->maxLength();
		}

		if ( null !== $field->defaultValue() ) {
			$keywords['default'] = $field->defaultValue();
		}

		$keywords['description'] = $field->description();

		return $keywords;
	}

	/**
	 * Writes one field as a WP-CLI argument.
	 *
	 * @since 0.1.0
	 *
	 * @param string    $kind  'positional' or 'assoc'.
	 * @param FieldSpec $field The field.
	 * @return array<string, mixed> The synopsis entry.
	 */
	private static function cliArgument( string $kind, FieldSpec $field ): array {
		$argument = array(
			'type'        => $kind,
			'name'        => $field->name(),
			'description' => $field->description(),
			'optional'    => ! $field->isRequired(),
		);

		if ( null !== $field->defaultValue() ) {
			$argument['default'] = (string) $field->defaultValue();
		}

		if ( array() !== $field->allowedValues() ) {
			$argument['options'] = $field->allowedValues();
		}

		return $argument;
	}
}

<?php
/**
 * ResourceSchema: the output shape of a resource, and the privacy rules of its serialization
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * A named set of fields that an operation returns: what a resource looks like on the wire.
 *
 * This class owns one fact: which of a resource's fields a serialized output may carry, and to
 * whom. Every surface serializes a result through serialize(), and every compiled output schema
 * is compiled from serializedFields(), so the schema a client reads and the data it receives are
 * derived from the same rule:
 *
 * - a Privacy::Secret field is never serialized, and no output schema mentions it;
 * - a Privacy::Pii field is serialized only for a user who may see personal data, so no output
 *   schema lists it as required;
 * - a value the service returns under a name the schema does not declare is dropped.
 *
 * The name is the resource's name in the OpenAPI document, where it becomes the one component a
 * response refers to.
 *
 * @since 0.1.0
 */
final class ResourceSchema {

	/**
	 * The shape of a resource name: PascalCase, as OpenAPI component names are written.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^[A-Z][A-Za-z0-9]*\z/';

	/**
	 * The resource name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The fields, in declaration order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<FieldSpec>
	 */
	private array $fields;

	/**
	 * Declares a resource schema.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the name is not PascalCase, there is no field, an item is not a
	 *                         FieldSpec, two fields share a name, or every field is a secret.
	 *
	 * @param string      $name   The resource name, PascalCase, such as StockLevel.
	 * @param FieldSpec[] $fields The fields, in the order they are serialized.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	public function __construct( string $name, array $fields ) {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			SchemaException::raise( 'The resource name "%1$s" is not PascalCase, such as StockLevel.', $name );
		}

		$names = array();

		foreach ( $fields as $field ) {
			// @phpstan-ignore instanceof.alwaysTrue (Declarations are written by hand; a stray value must be refused, not serialized.)
			if ( ! $field instanceof FieldSpec ) {
				SchemaException::raise( 'Every field of the resource %1$s must be a FieldSpec.', $name );
			}

			if ( isset( $names[ $field->name() ] ) ) {
				SchemaException::raise( 'The resource %1$s declares the field %2$s twice.', $name, $field->name() );
			}

			$names[ $field->name() ] = true;
		}

		$this->name   = $name;
		$this->fields = $fields;

		if ( array() === $this->serializedFields() ) {
			SchemaException::raise( 'The resource %1$s has no field that could ever be serialized.', $name );
		}
	}

	/**
	 * Returns the resource name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns every declared field, secrets included.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields, in declaration order.
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Returns the fields a serialized output may carry, as every output schema must describe them.
	 *
	 * Secrets are left out, and personal-data fields are optional, because serialize() leaves them
	 * out for a user who may not see personal data.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields, in declaration order.
	 */
	public function serializedFields(): array {
		$fields = array();

		foreach ( $this->fields as $field ) {
			if ( Privacy::Secret === $field->privacy() ) {
				continue;
			}

			$fields[] = Privacy::Pii === $field->privacy() ? $field->asOptional() : $field;
		}

		return $fields;
	}

	/**
	 * Serializes a service result for one viewer.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the result lacks a required field the viewer would receive: the
	 *                         service does not return what its operation declares.
	 *
	 * @param array<string, mixed> $values          The service result, keyed by wire name.
	 * @param bool                 $may_view_people Whether the viewer may see personal data.
	 * @return array<string, mixed> The declared fields the viewer may see, in declaration order.
	 */
	public function serialize( array $values, bool $may_view_people ): array {
		$output = array();

		foreach ( $this->fields as $field ) {
			$privacy = $field->privacy();

			if ( Privacy::Secret === $privacy || ( Privacy::Pii === $privacy && ! $may_view_people ) ) {
				continue;
			}

			if ( ! array_key_exists( $field->name(), $values ) ) {
				if ( $field->isRequired() ) {
					SchemaException::raise( 'The result for the resource %1$s lacks its required field %2$s.', $this->name, $field->name() );
				}

				continue;
			}

			$output[ $field->name() ] = $values[ $field->name() ];
		}

		return $output;
	}
}

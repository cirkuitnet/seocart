<?php
/**
 * OpenApiDocument: generates docs/openapi.json from the operation registry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Platform\Rest\ErrorShape;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\JsonSchemaCompiler;

/**
 * Writes the OpenAPI 3.1 document of the plugin's REST namespace.
 *
 * This generator owns docs/openapi.json whole, and is the one call site of the compiler's
 * OpenAPI dialect. For each operation with a REST route it writes:
 *
 * - the route's parameters from the input fields the route names, and, for GET, every other input
 *   as a query parameter — otherwise a JSON request body of the other inputs;
 * - the success response, which refers to the operation's resource schema;
 * - one error response per status: the validation and permission failures every route has, and
 *   each code the operation declares, with its English message — or, for an internal code, the
 *   fact that a client gets a generic message. An operation that changes the store also lists every
 *   code any write may raise, from FieldDocs::errorCodes(). Each refers to the one `Error` component:
 *   WordPress's `{ code, message, data }`, whose data members are ErrorShape's.
 *
 * A component is written only when a response refers to it, so the document carries no schema
 * that nothing uses, and two resources with one name must be the same resource; no resource may
 * take the name of the `Error` component. Nothing is skipped: an operation that cannot be
 * documented fails the run, and so does an ErrorShape member this generator has no type for.
 *
 * @since 0.1.0
 */
final class OpenApiDocument implements Generator {

	/**
	 * The dialect every schema in the document is written in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SCHEMA_DIALECT = 'https://spec.openapis.org/oas/3.1/dialect/base';

	/**
	 * The name of the component every error response refers to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ERROR_COMPONENT = 'Error';

	/**
	 * The JSON type of each ErrorShape member, keyed by member name.
	 *
	 * The members and their descriptions are ErrorShape's; this generator adds only the type each
	 * one has on the wire. errorSchema() fails when the two lists differ.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const ERROR_MEMBER_TYPES = array(
		ErrorShape::STATUS         => array( 'type' => 'integer' ),
		ErrorShape::DETAILS        => array( 'type' => 'object' ),
		ErrorShape::CORRELATION_ID => array( 'type' => array( 'string', 'null' ) ),
	);

	/**
	 * The operations.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationRegistry
	 */
	private OperationRegistry $registry;

	/**
	 * The error table, for each declared code's status and message.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorTable
	 */
	private ErrorTable $errors;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry The operations.
	 * @param ErrorTable        $errors   The error table.
	 */
	public function __construct( OperationRegistry $registry, ErrorTable $errors ) {
		$this->registry = $registry;
		$this->errors   = $errors;
	}

	/**
	 * Returns the generator's stable identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string The identifier.
	 */
	public function id(): string {
		return 'openapi';
	}

	/**
	 * Returns the file this generator owns.
	 *
	 * @since 0.1.0
	 *
	 * @return string Path relative to the repository root.
	 */
	public function target(): string {
		return 'docs/openapi.json';
	}

	/**
	 * Returns the source items this generator may leave out: none.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Always empty.
	 */
	public function expectedSkips(): array {
		return array();
	}

	/**
	 * Renders the document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When an operation cannot be built or documented.
	 *
	 * @param string $current The current content. Ignored: the file is generated whole.
	 * @return GenerationResult The document. Nothing is skipped.
	 */
	public function generate( string $current ): GenerationResult {
		try {
			$document = $this->document( $this->registry->all() );
		} catch ( \LogicException $exception ) {
			throw new \RuntimeException( $exception->getMessage(), 0, $exception );
		}

		return new GenerationResult( json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n" );
	}

	/**
	 * Builds the document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When two different resources share a name.
	 *
	 * @param OperationDefinition[] $definitions The operations.
	 * @return array<string, mixed> The document.
	 *
	 * @phpstan-param list<OperationDefinition> $definitions
	 */
	private function document( array $definitions ): array {
		$paths      = array();
		$components = array();

		foreach ( $definitions as $definition ) {
			$rest = $definition->rest();

			if ( null === $rest ) {
				continue;
			}

			$resource = $definition->output();
			$schema   = self::schema( $resource->serializedFields() );

			if ( isset( $components[ $resource->name() ] ) && $components[ $resource->name() ] !== $schema ) {
				throw new \RuntimeException( 'Two different resources are named ' . $resource->name() . '; a resource name must stand for one schema.' );
			}

			$components[ $resource->name() ] = $schema;

			$paths[ $rest->route() ][ strtolower( (string) $definition->httpMethod() ) ] = $this->operation( $definition, $rest );
		}

		if ( array() !== $paths ) {
			if ( isset( $components[ self::ERROR_COMPONENT ] ) ) {
				throw new \RuntimeException( 'A resource is named ' . self::ERROR_COMPONENT . ', the name of the component every error response refers to; rename the resource.' );
			}

			$components[ self::ERROR_COMPONENT ] = self::errorSchema();
		}

		ksort( $paths );
		ksort( $components );

		$document = array(
			'openapi'           => '3.1.0',
			'info'              => array(
				'title'       => 'SEOCart REST API',
				'version'     => substr( RestBinding::NAMESPACE, (int) strrpos( RestBinding::NAMESPACE, '/' ) + 1 ),
				'description' => 'The routes of the `' . RestBinding::NAMESPACE . '` namespace. Each operation is generated from the same declaration as its ability and its WP-CLI command, which docs/reference/ describes. A request authenticates as a WordPress user, with a REST nonce or an application password, and each operation names the capability it requires.',
				'license'     => array(
					'name'       => 'GPL-3.0-or-later',
					'identifier' => 'GPL-3.0-or-later',
				),
			),
			'jsonSchemaDialect' => self::SCHEMA_DIALECT,
			'servers'           => array(
				array(
					'url'       => '{site}/wp-json/' . RestBinding::NAMESPACE,
					'variables' => array(
						'site' => array(
							'default'     => 'https://example.com',
							'description' => 'The address of the WordPress site.',
						),
					),
				),
			),
			'paths'             => array() === $paths ? new \stdClass() : $paths,
		);

		if ( array() !== $components ) {
			$document['components'] = array( 'schemas' => $components );
		}

		return $document;
	}

	/**
	 * Builds the operation object of one route and method.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The operation.
	 * @param RestBinding         $rest       Its route.
	 * @return array<string, mixed> The operation object.
	 */
	private function operation( OperationDefinition $definition, RestBinding $rest ): array {
		$in_path    = array();
		$in_request = array();

		foreach ( $definition->input() as $field ) {
			if ( in_array( $field->name(), $rest->pathParameters(), true ) ) {
				$in_path[] = $field;
			} else {
				$in_request[] = $field;
			}
		}

		$is_get     = 'GET' === $definition->httpMethod();
		$parameters = self::parameters( $in_path, 'path' );

		if ( $is_get ) {
			$parameters = array_merge( $parameters, self::parameters( $in_request, 'query' ) );
		}

		$operation = array(
			'operationId' => $definition->id(),
			'summary'     => $definition->summary(),
			'description' => 'Requires the capability ' . FieldDocs::capability( $definition ) . '.',
		);

		if ( array() !== $parameters ) {
			$operation['parameters'] = $parameters;
		}

		if ( ! $is_get && array() !== $in_request ) {
			$operation['requestBody'] = array(
				'required' => array() !== array_filter( $in_request, static fn( FieldSpec $field ): bool => $field->isRequired() ),
				'content'  => array(
					'application/json' => array( 'schema' => self::schema( $in_request ) ),
				),
			);
		}

		$operation['responses'] = $this->responses( $definition );

		return $operation;
	}

	/**
	 * Builds the parameter objects of some input fields.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields   The fields.
	 * @param string      $location 'path' or 'query'.
	 * @return list<array<string, mixed>> One parameter object per field.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	private static function parameters( array $fields, string $location ): array {
		$schema     = self::schema( $fields );
		$parameters = array();

		foreach ( $fields as $field ) {
			$property = $schema['properties'][ $field->name() ];

			unset( $property['description'] );

			$parameters[] = array(
				'name'        => $field->name(),
				'in'          => $location,
				'required'    => $field->isRequired(),
				'description' => $field->description(),
				'schema'      => $property,
			);
		}

		return $parameters;
	}

	/**
	 * Builds the responses of an operation, keyed by HTTP status.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The operation.
	 * @return array<int, array<string, mixed>> The responses. PHP keeps the numeric keys as integers; JSON writes them as names.
	 */
	private function responses( OperationDefinition $definition ): array {
		$capability   = FieldDocs::capability( $definition );
		$descriptions = array(
			400 => array( 'The request does not match the input schema: `rest_invalid_param` or `rest_missing_callback_param`.' ),
			401 => array( 'No user is logged in, and the operation requires the capability ' . $capability . ': `rest_forbidden`.' ),
			403 => array( 'The user does not hold the capability ' . $capability . ': `rest_forbidden`.' ),
		);

		foreach ( FieldDocs::errorCodes( $definition, $this->errors ) as $code ) {
			$row = $this->errors->definitionFor( $code );

			if ( $row->isInternal() ) {
				$descriptions[ $row->httpStatus() ][] = '`' . $code->value . '`: an internal failure, answered with a generic message and empty details. The site\'s error log has what went wrong, under the correlation id.';
				continue;
			}

			$placeholders = array();

			foreach ( $row->placeholders() as $name ) {
				$placeholders[ $name ] = '{' . $name . '}';
			}

			$descriptions[ $row->httpStatus() ][] = '`' . $code->value . '`: ' . $row->render( $placeholders );
		}

		ksort( $descriptions );

		$responses = array(
			'200' => array(
				'description' => 'The operation succeeded. The body is the ' . $definition->output()->name() . ' resource.',
				'content'     => array(
					'application/json' => array(
						'schema' => array( '$ref' => '#/components/schemas/' . $definition->output()->name() ),
					),
				),
			),
		);

		foreach ( $descriptions as $status => $lines ) {
			$responses[ (string) $status ] = array(
				'description' => implode( "\n\n", $lines ),
				'content'     => array(
					'application/json' => array(
						'schema' => array( '$ref' => '#/components/schemas/' . self::ERROR_COMPONENT ),
					),
				),
			);
		}

		return $responses;
	}

	/**
	 * Builds the `Error` component: WordPress's error body, with ErrorShape's data members.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When ErrorShape has a member this generator has no type for, or
	 *                           this generator types a member ErrorShape does not have.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private static function errorSchema(): array {
		$members = ErrorShape::members();
		$missing = array_diff_key( $members, self::ERROR_MEMBER_TYPES );
		$extra   = array_diff_key( self::ERROR_MEMBER_TYPES, $members );

		if ( array() !== $missing || array() !== $extra ) {
			throw new \RuntimeException( 'The error data members of ErrorShape and the types in the OpenAPI generator differ: without a type [' . implode( ', ', array_keys( $missing ) ) . '], typed but not a member [' . implode( ', ', array_keys( $extra ) ) . ']. Give every member exactly one type in ERROR_MEMBER_TYPES.' );
		}

		$properties = array();

		foreach ( $members as $name => $description ) {
			$properties[ $name ] = self::ERROR_MEMBER_TYPES[ $name ] + array( 'description' => $description );
		}

		return array(
			'type'        => 'object',
			'description' => 'An error. WordPress writes it as its code, its message and its data; the data members are the same for every error of these routes, including WordPress\'s own refusal of a request that does not match the input schema or of a user who lacks the capability, but not its refusal of a JSONP callback, which it sends before any route is matched.',
			'properties'  => array(
				'code'    => array(
					'type'        => 'string',
					'description' => 'The error code: one of those in docs/reference/errors.md, or a code of the WordPress REST API such as `rest_invalid_param` or `rest_forbidden`.',
				),
				'message' => array(
					'type'        => 'string',
					'description' => 'What went wrong, for people, in the language of the site. An internal error has a generic message.',
				),
				'data'    => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array_keys( $properties ),
				),
			),
			'required'    => array( 'code', 'message', 'data' ),
		);
	}

	/**
	 * Compiles fields into the OpenAPI dialect: the one call site of that dialect.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields The fields.
	 * @return array<string, mixed> The object schema.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	private static function schema( array $fields ): array {
		return JsonSchemaCompiler::openApiSchema( $fields );
	}
}

<?php
/**
 * OperationDefinition: the one declaration of a publicly reachable use case
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;
use SEOCart\Support\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

/**
 * Everything every surface needs to know about one operation, declared once.
 *
 * This class owns one fact: what an operation is — its id, its texts, its input fields, its output
 * schema, the capability it requires, the error codes it can fail with, its annotations, whether
 * agents may see it, the application service that performs it, and the surfaces it is bound to.
 * The REST route, the Ability, the WP-CLI command, the permission check, the privacy of each
 * field, the error responses and the generated documents are all derived from it, and none of
 * them restates any part of it.
 *
 * An operation earns a definition when at least two consumers reach it. A use case reachable from
 * one admin screen only stays a plain application service.
 *
 * Declarations are data. Constructing a definition performs no I/O, resolves nothing from a
 * container, calls no WordPress function and translates nothing: the label is a closure around a
 * literal gettext call, translated by the Abilities adapter when it registers the ability, and the
 * service is named by class and method and built only when a surface calls it. The registry holds
 * factories of definitions, so an idle request constructs none.
 *
 * What the constructor refuses, so that a wrong declaration cannot be registered:
 *
 * - an id that is not `module.verb_noun`, such as `inventory.adjust_stock`;
 * - a capability the plugin does not declare: a primitive for a check without a resource, or a
 *   meta capability together with the required input field that names the resource;
 * - a REST route whose parameters are not required inputs, a resource field that is not one of the
 *   route's parameters, a read-only operation with a write method, or a changing one without;
 * - a nullable input field: the REST API reads an explicit null as a missing argument while the
 *   Ability's schema accepts it, so the surfaces would disagree;
 * - a positional CLI argument that is not a required input;
 * - an operation that moves money — it requires a primitive of the plugin's money group, or a
 *   meta capability in MONEY_META_CAPABILITIES — without the `destructive` annotation;
 * - agent exposure for an operation that is destructive, moves money or reads personal data in
 *   bulk (a primitive of the money or data-sensitivity group), or that requires a meta capability,
 *   whose reach cannot be told from its declaration. Those are never exposed; the check fails
 *   closed.
 *
 * @since 0.1.0
 */
final class OperationDefinition {

	/**
	 * The shape of an id: a snake_case module, a dot, and at least two snake_case words.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*\.[a-z][a-z0-9]*(?:_[a-z0-9]+)+\z/';

	/**
	 * The shape of an ability slug, the part after `seocart/`: kebab-case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ABILITY_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

	/**
	 * The namespace of every ability the plugin registers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ABILITY_NAMESPACE = 'seocart';

	/**
	 * The plugin's meta capabilities that move money.
	 *
	 * The capability declaration gives every primitive a group but a meta capability none, so the
	 * meta capabilities that move money are named here. Every declared meta capability is either in
	 * this list or deliberately not, which the definition's unit test pins.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const MONEY_META_CAPABILITIES = array( 'seocart_refund_order' );

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Returns the label, translated when called.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): string
	 */
	private \Closure $label;

	/**
	 * The summary: one English sentence for API clients, agents and the generated documents.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $summary;

	/**
	 * The input fields.
	 *
	 * @since 0.1.0
	 *
	 * @var list<FieldSpec>
	 */
	private array $input;

	/**
	 * The output schema.
	 *
	 * @since 0.1.0
	 *
	 * @var ResourceSchema
	 */
	private ResourceSchema $output;

	/**
	 * The capability the operation requires.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $capability;

	/**
	 * The input field naming the resource a meta capability is checked on, or null for a primitive.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $resourceField;

	/**
	 * The error codes the operation can fail with.
	 *
	 * @since 0.1.0
	 *
	 * @var list<ErrorCode>
	 */
	private array $errors;

	/**
	 * The annotations.
	 *
	 * @since 0.1.0
	 *
	 * @var Annotations
	 */
	private Annotations $annotations;

	/**
	 * Whether agents may see the operation's ability.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $agentExposed;

	/**
	 * The application service: its class and the method that performs the operation.
	 *
	 * @since 0.1.0
	 *
	 * @var array{0: class-string, 1: string}
	 */
	private array $service;

	/**
	 * The REST route, or null when the operation has none.
	 *
	 * @since 0.1.0
	 *
	 * @var RestBinding|null
	 */
	private ?RestBinding $rest;

	/**
	 * The ability slug below `seocart/`, or null when the operation has no ability.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $ability;

	/**
	 * The WP-CLI command, or null when the operation has none.
	 *
	 * @since 0.1.0
	 *
	 * @var CliBinding|null
	 */
	private ?CliBinding $cli;

	/**
	 * Declares an operation.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the declaration breaks one of the rules this class lists.
	 *
	 * @param string           $id             The id, `module.verb_noun`.
	 * @param \Closure         $label          Returns the label through a literal gettext call.
	 * @param string           $summary        One English sentence saying what the operation does.
	 * @param FieldSpec[]      $input          The input fields.
	 * @param ResourceSchema   $output         The output schema.
	 * @param string           $capability     The capability the operation requires.
	 * @param string|null      $resource_field The required input field naming the resource a meta
	 *                                         capability is checked on, or null for a primitive.
	 * @param ErrorCode[]      $errors         The error codes the operation can fail with.
	 * @param Annotations      $annotations    What the operation does to the store.
	 * @param array            $service        The application service class and the method that
	 *                                         performs the operation. The method receives the input
	 *                                         values keyed by wire name and returns the output
	 *                                         values keyed by wire name.
	 * @param RestBinding|null $rest          Optional. The REST route. Default none.
	 * @param string|null      $ability        Optional. The ability slug, registered as
	 *                                         `seocart/<slug>`. Default none.
	 * @param CliBinding|null  $cli            Optional. The WP-CLI command. Default none.
	 * @param bool             $agent_exposed  Optional. Whether agents may see the ability. Default false.
	 *
	 * @phpstan-param \Closure(): string                $label
	 * @phpstan-param list<FieldSpec>                   $input
	 * @phpstan-param list<ErrorCode>                   $errors
	 * @phpstan-param array{0: class-string, 1: string} $service
	 */
	public function __construct(
		string $id,
		\Closure $label,
		string $summary,
		array $input,
		ResourceSchema $output,
		string $capability,
		?string $resource_field,
		array $errors,
		Annotations $annotations,
		array $service,
		?RestBinding $rest = null,
		?string $ability = null,
		?CliBinding $cli = null,
		bool $agent_exposed = false
	) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			SchemaException::raise( 'The operation id "%1$s" is not module.verb_noun in snake_case, such as inventory.adjust_stock.', $id );
		}

		if ( '' === trim( $summary ) || 1 === preg_match( '/[\r\n]/', $summary ) ) {
			SchemaException::raise( 'The operation %1$s needs a summary: one non-empty English sentence on one line.', $id );
		}

		$fields = self::indexFields( $id, $input );

		self::checkService( $id, $service );
		self::checkErrors( $id, $errors );
		self::checkCapability( $id, $capability, $resource_field, $fields );

		if ( self::movesMoney( $capability ) && ! $annotations->isDestructive() ) {
			SchemaException::raise( 'The operation %1$s requires %2$s: it moves money, so it must be annotated destructive.', $id, $capability );
		}

		if ( null === $rest && null === $ability && null === $cli ) {
			SchemaException::raise( 'The operation %1$s is bound to no surface: give it a REST route, an ability or a command.', $id );
		}

		if ( null !== $rest ) {
			self::checkRest( $id, $rest, $annotations, $resource_field, $fields );
		}

		if ( null !== $ability && 1 !== preg_match( self::ABILITY_PATTERN, $ability ) ) {
			SchemaException::raise( 'The ability slug "%1$s" of %2$s is not kebab-case.', $ability, $id );
		}

		if ( null !== $cli ) {
			foreach ( $cli->positional() as $name ) {
				if ( ! isset( $fields[ $name ] ) || ! $fields[ $name ]->isRequired() ) {
					SchemaException::raise( 'The positional argument %1$s of %2$s is not a required input field.', $name, $id );
				}
			}
		}

		if ( $agent_exposed ) {
			self::checkAgentExposure( $id, $capability, $annotations, $ability );
		}

		$this->id            = $id;
		$this->label         = $label;
		$this->summary       = $summary;
		$this->input         = $input;
		$this->output        = $output;
		$this->capability    = $capability;
		$this->resourceField = $resource_field;
		$this->errors        = $errors;
		$this->annotations   = $annotations;
		$this->service       = $service;
		$this->rest          = $rest;
		$this->ability       = $ability;
		$this->cli           = $cli;
		$this->agentExposed  = $agent_exposed;
	}

	/**
	 * Returns the id.
	 *
	 * @since 0.1.0
	 *
	 * @return string The id, `module.verb_noun`.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the label closure. Call it only when rendering, after `init`: that is when it translates.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(): string The closure.
	 */
	public function label(): \Closure {
		return $this->label;
	}

	/**
	 * Returns the summary.
	 *
	 * @since 0.1.0
	 *
	 * @return string One English sentence, never translated.
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * Returns the input fields.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields, in declaration order.
	 */
	public function input(): array {
		return $this->input;
	}

	/**
	 * Returns the output schema.
	 *
	 * @since 0.1.0
	 *
	 * @return ResourceSchema The schema.
	 */
	public function output(): ResourceSchema {
		return $this->output;
	}

	/**
	 * Returns the capability the operation requires.
	 *
	 * @since 0.1.0
	 *
	 * @return string A primitive, or a meta capability when resourceField() names a field.
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Returns the input field naming the resource the capability is checked on.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The field, or null when the capability is a primitive.
	 */
	public function resourceField(): ?string {
		return $this->resourceField;
	}

	/**
	 * Returns the error codes the operation can fail with.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorCode> The codes, in declaration order.
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Returns the annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return Annotations The annotations.
	 */
	public function annotations(): Annotations {
		return $this->annotations;
	}

	/**
	 * Tells whether agents may see the operation's ability.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the ability is registered as public.
	 */
	public function isAgentExposed(): bool {
		return $this->agentExposed;
	}

	/**
	 * Returns the application service that performs the operation.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: class-string, 1: string} The class and the method.
	 */
	public function service(): array {
		return $this->service;
	}

	/**
	 * Returns the REST route.
	 *
	 * @since 0.1.0
	 *
	 * @return RestBinding|null The route, or null when the operation has none.
	 */
	public function rest(): ?RestBinding {
		return $this->rest;
	}

	/**
	 * Returns the HTTP method of the REST route, derived from the annotations.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null GET for a read-only operation, the declared write method otherwise, or
	 *                     null when the operation has no REST route.
	 */
	public function httpMethod(): ?string {
		if ( null === $this->rest ) {
			return null;
		}

		$method = $this->rest->writeMethod();

		return null === $method ? 'GET' : $method->value;
	}

	/**
	 * Returns the full name of the operation's ability.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The name, `seocart/<slug>`, or null when the operation has no ability.
	 */
	public function abilityName(): ?string {
		return null === $this->ability ? null : self::ABILITY_NAMESPACE . '/' . $this->ability;
	}

	/**
	 * Returns the WP-CLI command.
	 *
	 * @since 0.1.0
	 *
	 * @return CliBinding|null The command, or null when the operation has none.
	 */
	public function cli(): ?CliBinding {
		return $this->cli;
	}

	/**
	 * Indexes the input fields by name.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When an item is not a FieldSpec or two fields share a name.
	 *
	 * @param string $id    The operation id, for messages.
	 * @param array  $input The input fields.
	 * @return array<string, FieldSpec> The fields, keyed by name.
	 *
	 * @phpstan-param list<FieldSpec> $input
	 */
	private static function indexFields( string $id, array $input ): array {
		$fields = array();

		foreach ( $input as $field ) {
			// @phpstan-ignore instanceof.alwaysTrue (Declarations are written by hand; a stray value must be refused, not compiled.)
			if ( ! $field instanceof FieldSpec ) {
				SchemaException::raise( 'Every input field of %1$s must be a FieldSpec.', $id );
			}

			if ( isset( $fields[ $field->name() ] ) ) {
				SchemaException::raise( 'The operation %1$s declares the input field %2$s twice.', $id, $field->name() );
			}

			if ( $field->isNullable() ) {
				SchemaException::raise( 'The input field %2$s of %1$s is nullable, which an input cannot be: the REST API reads an explicit null as a missing argument, while the Ability\'s schema accepts it.', $id, $field->name() );
			}

			$fields[ $field->name() ] = $field;
		}

		return $fields;
	}

	/**
	 * Checks the shape of the service reference, without loading the class.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the reference is not a class name and a method name.
	 *
	 * @param string $id      The operation id, for messages.
	 * @param array  $service The service reference.
	 */
	private static function checkService( string $id, array $service ): void {
		if ( array( 0, 1 ) !== array_keys( $service ) || ! is_string( $service[0] ) || ! is_string( $service[1] ) || '' === $service[0] || '' === $service[1] ) {
			SchemaException::raise( 'The service of %1$s must be written array( Service::class, \'method\' ).', $id );
		}
	}

	/**
	 * Checks the declared error codes.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When an item is not an ErrorCode case, or a code is declared twice.
	 *
	 * @param string $id     The operation id, for messages.
	 * @param array  $errors The declared codes.
	 *
	 * @phpstan-param list<ErrorCode> $errors
	 */
	private static function checkErrors( string $id, array $errors ): void {
		foreach ( $errors as $index => $code ) {
			// @phpstan-ignore instanceof.alwaysTrue (Declarations are written by hand; a code written as a string must be refused.)
			if ( ! $code instanceof ErrorCode ) {
				SchemaException::raise( 'The error codes of %1$s must be cases of an error catalog, never strings.', $id );
			}

			if ( array_search( $code, $errors, true ) !== $index ) {
				SchemaException::raise( 'The operation %1$s declares the error code %2$s twice.', $id, (string) $code->value );
			}
		}
	}

	/**
	 * Checks the capability against the plugin's declaration.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the capability is not declared, or the resource field does not
	 *                         fit the kind of capability.
	 *
	 * @param string                   $id             The operation id, for messages.
	 * @param string                   $capability     The capability.
	 * @param string|null              $resource_field The field naming the resource, or null.
	 * @param array<string, FieldSpec> $fields         The input fields, keyed by name.
	 */
	private static function checkCapability( string $id, string $capability, ?string $resource_field, array $fields ): void {
		$declaration = new CapabilityDeclaration();

		if ( null === $resource_field ) {
			if ( ! $declaration->isPrimitive( $capability ) ) {
				SchemaException::raise( 'The operation %1$s requires "%2$s", which is not a primitive the plugin declares. A meta capability needs the input field naming the resource.', $id, $capability );
			}

			return;
		}

		if ( ! $declaration->isMetaCapability( $capability ) ) {
			SchemaException::raise( 'The operation %1$s checks "%2$s" on a resource, but it is not a meta capability the plugin declares.', $id, $capability );
		}

		$field = $fields[ $resource_field ] ?? null;

		if ( null === $field || ! $field->isRequired() || $field->isNullable() || ! in_array( $field->type(), array( FieldType::Uuid, FieldType::Integer ), true ) ) {
			SchemaException::raise( 'The resource field %1$s of %2$s must be a required, non-nullable uuid or integer input.', $resource_field, $id );
		}
	}

	/**
	 * Checks the REST route against the inputs and the annotations.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When a route parameter is not a required input, the resource field is
	 *                         not a route parameter, or the method does not match the annotations.
	 *
	 * @param string                   $id             The operation id, for messages.
	 * @param RestBinding              $rest           The route.
	 * @param Annotations              $annotations    The annotations.
	 * @param string|null              $resource_field The field naming the resource, or null.
	 * @param array<string, FieldSpec> $fields         The input fields, keyed by name.
	 */
	private static function checkRest( string $id, RestBinding $rest, Annotations $annotations, ?string $resource_field, array $fields ): void {
		foreach ( $rest->pathParameters() as $name ) {
			if ( ! isset( $fields[ $name ] ) || ! $fields[ $name ]->isRequired() || $fields[ $name ]->isNullable() ) {
				SchemaException::raise( 'The route parameter %1$s of %2$s is not a required, non-nullable input field.', $name, $id );
			}
		}

		if ( null !== $resource_field && ! in_array( $resource_field, $rest->pathParameters(), true ) ) {
			SchemaException::raise( 'The resource field %1$s of %2$s must be a parameter of its route, so that the permission check and the service read it from the URL.', $resource_field, $id );
		}

		if ( $annotations->isReadOnly() && null !== $rest->writeMethod() ) {
			SchemaException::raise( 'The operation %1$s is read-only, so its route is served by GET; declare no write method.', $id );
		}

		if ( ! $annotations->isReadOnly() && null === $rest->writeMethod() ) {
			SchemaException::raise( 'The operation %1$s changes the store, so its route needs a write method: POST, PUT, PATCH or DELETE, never GET.', $id );
		}
	}

	/**
	 * Tells whether a capability moves money: a primitive of the money group, or a money meta capability.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability The capability.
	 * @return bool True when the operation that requires it moves money.
	 */
	private static function movesMoney( string $capability ): bool {
		return in_array( $capability, self::MONEY_META_CAPABILITIES, true )
			|| CapabilityDeclaration::GROUP_MONEY === ( new CapabilityDeclaration() )->group( $capability );
	}

	/**
	 * Checks that an operation asked to be exposed to agents may be.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the operation has no ability, requires a meta capability, requires
	 *                         a primitive of the money or data-sensitivity group, or is destructive.
	 *
	 * @param string      $id          The operation id, for messages.
	 * @param string      $capability  The capability.
	 * @param Annotations $annotations The annotations.
	 * @param string|null $ability     The ability slug, or null.
	 */
	private static function checkAgentExposure( string $id, string $capability, Annotations $annotations, ?string $ability ): void {
		$declaration = new CapabilityDeclaration();

		if ( null === $ability ) {
			SchemaException::raise( 'The operation %1$s is exposed to agents but has no ability.', $id );
		}

		if ( $declaration->isMetaCapability( $capability ) ) {
			SchemaException::raise( 'The operation %1$s requires %2$s, which is checked on one resource, so what it can reach cannot be told from its declaration, and it is never exposed to agents.', $id, $capability );
		}

		$group = $declaration->group( $capability );

		if ( CapabilityDeclaration::GROUP_MONEY === $group || CapabilityDeclaration::GROUP_SENSITIVITY === $group ) {
			SchemaException::raise( 'The operation %1$s requires %2$s: it moves money or reads personal data in bulk, and is never exposed to agents.', $id, $capability );
		}

		if ( $annotations->isDestructive() ) {
			SchemaException::raise( 'The operation %1$s is destructive, and a destructive operation is never exposed to agents.', $id );
		}
	}
}

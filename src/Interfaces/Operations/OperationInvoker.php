<?php
/**
 * OperationInvoker: how every surface prepares an operation's input, calls its service and answers
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Interfaces\Operations;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\SchemaException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares an operation's input once, calls its application service with it, and turns the outcome
 * into what a surface returns.
 *
 * This class owns one fact: what happens between a surface that has validated its input and the
 * response it sends. The REST route, the Ability and the WP-CLI command all go through it, so they
 * give the service the same input and get the same output:
 *
 * 1. prepare() keeps the operation's declared fields only, gives an absent optional field its
 *    declared default, and sanitizes every value with the operation's input schema — a number sent
 *    as text becomes an integer, a uuid loses stray white space. Its result is what the permission
 *    check reads and what the service receives, on every surface, so the two can never disagree
 *    about which resource a request is for.
 * 2. invoke() resolves the service by class name only now, through the resolver the kernel
 *    provides, and calls its method with the prepared input and the Actor the surface names: the
 *    current user for the REST route and the Ability, the user WP-CLI runs as for a command. The
 *    service authorizes that actor itself, with the Authorizer, so its check and the permission
 *    check in front of it ask the same question:
 *
 *        public function adjust( array $input, Actor $actor ): array
 *
 * 3. A CodedException becomes the WP_Error of the ErrorTranslator, after every context value whose
 *    name is a personal-data or secret field of the operation — input or output — has been replaced
 *    by REDACTED: the context is rendered into the message a client reads. A code the operation
 *    does not declare is still translated, and reported to the developer, because the documented
 *    error responses would otherwise be incomplete — except an internal code, which any
 *    operation can meet and no client reads about, and a code whose row says any write may raise
 *    it, on an operation that changes the store. The translator still reports an internal code.
 * 4. Any other Throwable is a failure no client caused and no client may read about: it goes to the
 *    reporter, and the surface gets the ErrorTranslator's generic internal error, which carries no
 *    exception message.
 * 5. The result is serialized through the output schema for the actor: secrets never, personal
 *    data only when the actor's user holds the personal-data capability.
 *
 * @since 0.1.0
 */
final class OperationInvoker {

	/**
	 * The capability that lets a user see the personal-data fields of an output.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PERSONAL_DATA_CAPABILITY = 'seocart_view_customer_pii';

	/**
	 * What an error shows in place of a personal-data or secret value.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REDACTED = '[redacted]';

	/**
	 * Returns the application service instance of a class.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(class-string): object
	 */
	private \Closure $resolve;

	/**
	 * The error seam.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorTranslator
	 */
	private ErrorTranslator $translator;

	/**
	 * Receives every unexpected failure, with the id of the operation it happened in.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(\Throwable, string): void
	 */
	private \Closure $report;

	/**
	 * Creates the invoker.
	 *
	 * @since 0.1.0
	 *
	 * @param callable        $resolve    Returns the service instance of a class: the kernel's
	 *                                    container lookup. Called only when an operation runs.
	 * @param ErrorTranslator $translator Turns a coded failure into the surface's error.
	 * @param callable|null   $report     Optional. Receives an unexpected failure and the operation's
	 *                                    id. Default: one line in PHP's error log, until the logging
	 *                                    module provides the reporter.
	 *
	 * @phpstan-param callable(class-string): object       $resolve
	 * @phpstan-param (callable(\Throwable, string): void)|null $report
	 */
	public function __construct( callable $resolve, ErrorTranslator $translator, ?callable $report = null ) {
		$this->resolve    = \Closure::fromCallable( $resolve );
		$this->translator = $translator;
		$this->report     = null === $report ? \Closure::fromCallable( array( self::class, 'logFailure' ) ) : \Closure::fromCallable( $report );
	}

	/**
	 * Prepares validated values as the input the permission check reads and the service receives.
	 *
	 * @since 0.1.0
	 *
	 * @param CompiledOperation    $operation The operation.
	 * @param array<string, mixed> $values    The validated values, keyed by wire name.
	 * @return array<string, mixed>|WP_Error The declared fields, with defaults, sanitized; or the
	 *                                       error of a value the schema cannot sanitize, with the
	 *                                       data members every error carries.
	 */
	public function prepare( CompiledOperation $operation, array $values ): array|WP_Error {
		$input = array();

		foreach ( $operation->definition()->input() as $field ) {
			if ( array_key_exists( $field->name(), $values ) ) {
				$input[ $field->name() ] = $values[ $field->name() ];
			} elseif ( null !== $field->defaultValue() ) {
				$input[ $field->name() ] = $field->defaultValue();
			}
		}

		$input = rest_sanitize_value_from_schema( $input, $operation->inputSchema(), 'input' );

		if ( $input instanceof WP_Error ) {
			return $this->translator->conform( $input );
		}

		return is_array( $input ) ? $input : array();
	}

	/**
	 * Runs an operation on an actor's authority.
	 *
	 * The caller has validated the values, prepared them with prepare() and checked the permission
	 * on the prepared input; this method does none of that. The service checks the actor again.
	 *
	 * @since 0.1.0
	 *
	 * @param CompiledOperation    $operation The operation.
	 * @param array<string, mixed> $input     The prepared input, from prepare().
	 * @param Actor                $actor     Who acts: the current user, or the user a command runs as.
	 * @return array<string, mixed>|WP_Error The serialized output, or the error the surface answers with.
	 */
	public function invoke( CompiledOperation $operation, array $input, Actor $actor ): array|WP_Error {
		$definition = $operation->definition();

		list( $class, $method ) = $definition->service();

		try {
			$result = call_user_func( array( ( $this->resolve )( $class ), $method ), $input, $actor );

			if ( ! is_array( $result ) ) {
				SchemaException::raise( 'The service of %1$s returned %2$s instead of an array keyed by wire name.', $definition->id(), get_debug_type( $result ) );
			}

			return $definition->output()->serialize( $result, user_can( $actor->userId(), self::PERSONAL_DATA_CAPABILITY ) );
		} catch ( CodedException $error ) {
			if ( self::isUndeclared( $definition, $error ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html( sprintf( 'The operation %1$s failed with the error code %2$s, which it does not declare. Add the code to its declaration.', $definition->id(), (string) $error->errorCode()->value ) ),
					'0.1.0'
				);
			}

			return $this->translator->translate( self::redacted( $definition, $error ) );
		} catch ( \Throwable $failure ) {
			( $this->report )( $failure, $definition->id() );

			return $this->translator->unexpected();
		}
	}

	/**
	 * Writes an unexpected failure to PHP's error log: the default reporter.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $failure      The failure.
	 * @param string     $operation_id The operation it happened in.
	 */
	public static function logFailure( \Throwable $failure, string $operation_id ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the default reporter of unexpected failures, until the logging module provides one; the message never reaches a client.
		error_log( sprintf( 'SEOCart: the operation %1$s failed unexpectedly: %2$s: %3$s in %4$s:%5$d', $operation_id, get_class( $failure ), $failure->getMessage(), $failure->getFile(), $failure->getLine() ) );
	}

	/**
	 * Tells whether a failure has a code its operation should have declared, and did not.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The operation.
	 * @param CodedException      $error      The failure.
	 * @return bool False for a declared code, an internal code, and, on an operation that changes
	 *              the store, a code any write may raise.
	 */
	private static function isUndeclared( OperationDefinition $definition, CodedException $error ): bool {
		if ( in_array( $error->errorCode(), $definition->errors(), true ) ) {
			return false;
		}

		$row = ErrorDefinition::of( $error->errorCode() );

		if ( $row->isInternal() ) {
			return false;
		}

		return ! ( $row->isAnyWrite() && ! $definition->annotations()->isReadOnly() );
	}

	/**
	 * Replaces every context value named after a personal-data or secret field of the operation.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The operation.
	 * @param CodedException      $error      The failure.
	 * @return CodedException The same failure, or a copy of its class with the private values replaced.
	 */
	private static function redacted( OperationDefinition $definition, CodedException $error ): CodedException {
		$private = array();

		foreach ( array_merge( $definition->input(), $definition->output()->fields() ) as $field ) {
			if ( Privacy::Pii === $field->privacy() || Privacy::Secret === $field->privacy() ) {
				$private[ $field->name() ] = true;
			}
		}

		$context = $error->context();

		if ( array() === array_intersect_key( $context, $private ) ) {
			return $error;
		}

		foreach ( array_keys( array_intersect_key( $context, $private ) ) as $name ) {
			$context[ $name ] = self::REDACTED;
		}

		return $error::because( $error->errorCode(), $context, $error );
	}
}

<?php
/**
 * OperationInvoker: how every surface calls an operation's service and serializes its result
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Interfaces\Operations;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\SchemaException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Calls an operation's application service with validated input, and turns the outcome into what a
 * surface returns.
 *
 * This class owns one fact: what happens between a surface that has validated its input and
 * checked the permission, and the response it sends. The REST route, the Ability and the WP-CLI
 * command all call invoke(), so they give the service the same input and get the same output:
 *
 * 1. The input is the operation's declared fields only; an optional field that is absent takes
 *    its declared default, and every value is sanitized by the operation's input schema, so a
 *    number sent as text reaches the service as an integer on every surface.
 * 2. The service is resolved by class name only now, through the resolver the kernel provides,
 *    and its method is called with that input.
 * 3. A CodedException becomes the WP_Error of the ErrorTranslator. No other exception is caught.
 *    A code the operation does not declare is still translated for the client, and reported to
 *    the developer, because the documented error responses would otherwise be incomplete.
 * 4. The result is serialized through the output schema for the current user: secrets never,
 *    personal data only for a user who holds the personal-data capability.
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
	 * Creates the invoker.
	 *
	 * @since 0.1.0
	 *
	 * @param callable        $resolve    Returns the service instance of a class: the kernel's
	 *                                    container lookup. Called only when an operation runs.
	 * @param ErrorTranslator $translator Turns a coded failure into the surface's error.
	 *
	 * @phpstan-param callable(class-string): object $resolve
	 */
	public function __construct( callable $resolve, ErrorTranslator $translator ) {
		$this->resolve    = \Closure::fromCallable( $resolve );
		$this->translator = $translator;
	}

	/**
	 * Runs an operation for the current user.
	 *
	 * The caller has validated the values against the operation's schema and checked the
	 * permission; this method does neither.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the service returns something other than an array, or an array
	 *                         without a required output field.
	 *
	 * @param CompiledOperation    $operation The operation.
	 * @param array<string, mixed> $values    The validated input values, keyed by wire name.
	 * @return array<string, mixed>|WP_Error The serialized output, or the translated failure.
	 */
	public function invoke( CompiledOperation $operation, array $values ): array|WP_Error {
		$definition = $operation->definition();
		$input      = array();

		foreach ( $definition->input() as $field ) {
			if ( array_key_exists( $field->name(), $values ) ) {
				$input[ $field->name() ] = $values[ $field->name() ];
			} elseif ( null !== $field->defaultValue() ) {
				$input[ $field->name() ] = $field->defaultValue();
			}
		}

		$input = rest_sanitize_value_from_schema( $input, $operation->inputSchema(), 'input' );

		if ( $input instanceof WP_Error ) {
			return $input;
		}

		list( $class, $method ) = $definition->service();

		try {
			$result = call_user_func( array( ( $this->resolve )( $class ), $method ), $input );
		} catch ( CodedException $error ) {
			if ( ! in_array( $error->errorCode(), $definition->errors(), true ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html( sprintf( 'The operation %1$s failed with the error code %2$s, which it does not declare. Add the code to its declaration.', $definition->id(), (string) $error->errorCode()->value ) ),
					'0.1.0'
				);
			}

			return $this->translator->translate( $error );
		}

		if ( ! is_array( $result ) ) {
			SchemaException::raise( 'The service of %1$s returned %2$s instead of an array keyed by wire name.', $definition->id(), get_debug_type( $result ) );
		}

		return $definition->output()->serialize( $result, current_user_can( self::PERSONAL_DATA_CAPABILITY ) );
	}
}

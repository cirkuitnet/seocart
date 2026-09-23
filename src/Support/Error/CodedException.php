<?php
/**
 * CodedException: the typed exception base of the one error model
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These messages name source declarations (enum classes, codes, placeholder names) for developers, never request data, and are never rendered as HTML.

/**
 * A failure a client can cause, carrying a code from the error table and its context.
 *
 * This class owns one fact: how domain and application code report a failure (target
 * architecture §2.1) — a stable machine code, `stock.insufficient`, and structured context,
 * `{requested, available}`. There is no Result type and no `WP_Error` outside adapters; an
 * adapter catches this exception and renders it through the ErrorTable.
 *
 * Raise it with raise(), never with `new`:
 *
 *     CodedException::raise( SupportError::UnknownCurrency, array( 'currency' => $code ) );
 *
 * because() builds the same exception without throwing it, for a caller that needs the
 * instance: to wrap it, to inspect it, or to throw it later. A module may declare its own
 * subclass so callers can catch its errors by type
 * (`final class InventoryException extends CodedException {}`); raise() and because() called on
 * the subclass then throw and return the subclass. The constructor is final, so every subclass
 * is created and checked the same way.
 *
 * The context must carry exactly the placeholders the code's row declares, and each value must
 * be an int, a string or a bool — never an object, never a secret, because the message a
 * client sees is rendered from it. The exception's own message is the code, which is never
 * translated and is safe to log. Programming errors — combining currencies, overflow, an
 * invalid value built by code — are LogicExceptions instead, and have no row.
 *
 * @since 0.1.0
 */
class CodedException extends \RuntimeException {

	/**
	 * The code, a case of a module's catalog.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorCode
	 */
	private ErrorCode $errorCode;

	/**
	 * The values the code's message contains, keyed by placeholder name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int|string|bool>
	 */
	private array $context;

	/**
	 * Creates the exception after checking its context against the code's row.
	 *
	 * @since 0.1.0
	 *
	 * @throws ErrorTableException When the context's keys are not exactly the row's
	 *                             placeholders, or a value is not an int, a string or a bool.
	 *
	 * @param ErrorCode       $error_code The code.
	 * @param array           $context    The values the message contains, keyed by placeholder name.
	 * @param \Throwable|null $previous   The exception that caused this one, if any.
	 *
	 * @phpstan-param array<array-key, mixed> $context
	 */
	final protected function __construct( ErrorCode $error_code, array $context, ?\Throwable $previous ) {
		$code     = (string) $error_code->value;
		$declared = ErrorDefinition::of( $error_code )->placeholders();
		$given    = array_map( 'strval', array_keys( $context ) );

		sort( $declared );
		sort( $given );

		if ( $declared !== $given ) {
			throw ErrorTableException::because( 'The context of %1$s must carry exactly the placeholders its row declares: [%2$s]; it carries [%3$s].', $code, implode( ', ', $declared ), implode( ', ', $given ) );
		}

		$checked = array();

		foreach ( $context as $name => $value ) {
			if ( ! is_int( $value ) && ! is_string( $value ) && ! is_bool( $value ) ) {
				throw ErrorTableException::because( 'The context value "%1$s" of %2$s must be an int, a string or a bool.', (string) $name, $code );
			}

			$checked[ (string) $name ] = $value;
		}

		parent::__construct( $code, 0, $previous );

		$this->errorCode = $error_code;
		$this->context   = $checked;
	}

	/**
	 * Creates the exception, of the class it is called on.
	 *
	 * @since 0.1.0
	 *
	 * @param ErrorCode       $error_code The code, a case of a module's catalog.
	 * @param array           $context    Optional. The values the message contains, keyed by
	 *                                    placeholder name: exactly the placeholders the code's
	 *                                    row declares. Default empty.
	 * @param \Throwable|null $previous   Optional. The exception that caused this one. Default null.
	 * @return static The exception, ready to throw.
	 *
	 * @phpstan-param array<array-key, mixed> $context
	 */
	public static function because( ErrorCode $error_code, array $context = array(), ?\Throwable $previous = null ): static {
		return new static( $error_code, $context, $previous );
	}

	/**
	 * Throws the exception, of the class it is called on. This is how code raises a coded error.
	 *
	 * It builds the exception with because() and then throws the instance it built, and that
	 * order is deliberate. The WordPress sniff WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * checks the arguments of an exception constructed inside a `throw` statement and leaves an
	 * exception built beforehand alone. Its concern does not apply to a coded exception: the
	 * message is only the code, a fixed `module.reason` string from a catalog, and the context
	 * reaches a person only through the adapter that renders it, which escapes it for its output.
	 * This method is the one place in the code base where the sniff's construction check is
	 * bypassed on purpose, so that a throw site needs no `phpcs:ignore` and every other `throw`
	 * stays checked.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException Always: the exception because() builds, of the class this is called on.
	 *
	 * @param ErrorCode $error_code The code, a case of a module's catalog.
	 * @param array     $context    Optional. The values the message contains, keyed by
	 *                              placeholder name: exactly the placeholders the code's row
	 *                              declares. Default empty.
	 * @return never
	 *
	 * @phpstan-param array<array-key, mixed> $context
	 */
	public static function raise( ErrorCode $error_code, array $context = array() ): never {
		$exception = static::because( $error_code, $context );

		throw $exception;
	}

	/**
	 * Returns the code.
	 *
	 * @since 0.1.0
	 *
	 * @return ErrorCode The code, a case of a module's catalog.
	 */
	public function errorCode(): ErrorCode {
		return $this->errorCode;
	}

	/**
	 * Returns the values the code's message contains.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string|bool> The context, keyed by placeholder name.
	 */
	public function context(): array {
		return $this->context;
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

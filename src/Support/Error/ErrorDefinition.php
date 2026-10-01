<?php
/**
 * ErrorDefinition: one row of the error table
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
 * One row of the error table: a code, its HTTP status, its message and its placeholders.
 *
 * This class owns one fact: what an error code means to a client — the status an adapter
 * answers with, and the human message with the named values it contains.
 *
 * The message is a closure around a literal gettext call, so `wp i18n make-pot` extracts it
 * and nothing is translated until an adapter renders it, after `init` (DRY rule 12):
 *
 *     new ErrorDefinition(
 *         InventoryError::Insufficient,
 *         409,
 *         static fn(): string =>
 *             // translators: %1$s: Quantity requested. %2$s: Quantity in stock.
 *             __( 'You asked for %1$s, but only %2$s are in stock.', 'seocart' ),
 *         array( 'requested', 'available' )
 *     )
 *
 * Placeholders are numbered, `%1$s` to `%N$s`, and filled from the error's context in the
 * order the row names them, so a translation may reorder them. The totality test checks that
 * a message uses exactly the placeholders its row declares.
 *
 * A row is public by default: a client reads its message and the values in it. A row declared
 * with `internal: true` describes a failure a client must never read about, such as a database
 * fault: an adapter answers with the row's status and a generic message only, and the message
 * and its values go to the site's log. The flag is on the row and nowhere else, so there is no
 * list of internal codes to keep in step with the catalogs.
 *
 * A row may also declare detail keys: structured values a client needs to act on the error, such
 * as the totals of a cart that changed, which are not part of the message. The exception may
 * carry them, and the adapter adds them to the error's details beside the placeholders' values.
 * An internal row declares none, since a client reads nothing of it.
 *
 * A row declared with `any_write: true` is a failure any operation that changes the store can
 * meet without declaring it, such as the store refusing writes while its schema is being
 * updated. An operation that changes the store is documented as possibly answering with it, and
 * it raises no undeclared-code notice there; a read-only operation must still declare it. The
 * flag says nothing about exposure: such a row is public unless it is also internal.
 *
 * @since 0.1.0
 */
final class ErrorDefinition {

	/**
	 * The shape of a code: `module.reason`, two lower-case snake_case words.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CODE_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/';

	/**
	 * The shape of a placeholder name: one lower-case snake_case word.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PLACEHOLDER_PATTERN = '/^[a-z][a-z0-9_]*\z/';

	/**
	 * The code the row defines.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorCode
	 */
	private ErrorCode $code;

	/**
	 * The HTTP status an adapter answers with, 400 to 599.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $httpStatus;

	/**
	 * Returns the message format, translated when called.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): string
	 */
	private \Closure $message;

	/**
	 * The names of the context values the message contains, in placeholder order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $placeholders;

	/**
	 * The keys of the structured details the error may carry beyond its placeholders.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $detailKeys;

	/**
	 * Whether a client must never read the message or the values: true for an internal row.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $internal;

	/**
	 * Whether any operation that changes the store may fail with the code without declaring it.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $anyWrite;

	/**
	 * Declares a row.
	 *
	 * @since 0.1.0
	 *
	 * @throws ErrorTableException When the code is not `module.reason`, the status is not a
	 *                             client or server error, a placeholder or detail key is not a
	 *                             snake_case word or is repeated, or an internal row declares
	 *                             detail keys.
	 *
	 * @param ErrorCode $code         The code the row defines.
	 * @param int       $http_status  The HTTP status, 400 to 599.
	 * @param \Closure  $message      Returns the message format through a literal gettext call.
	 * @param string[]  $placeholders Optional. The context values the message contains, in
	 *                                placeholder order: the first is `%1$s`. Default none.
	 * @param bool      $internal     Optional. Whether the failure is internal: a client gets the
	 *                                status and a generic message, and the message and its values
	 *                                go to the site's log. Default false, a public row.
	 * @param bool      $any_write    Optional. Whether any operation that changes the store may
	 *                                fail with the code without declaring it. Default false.
	 * @param string[]  $details      Optional. The keys of the structured details the error may
	 *                                carry beyond its placeholders. Default none.
	 *
	 * @phpstan-param \Closure(): string $message
	 * @phpstan-param list<string>       $placeholders
	 * @phpstan-param list<string>       $details
	 */
	public function __construct( ErrorCode $code, int $http_status, \Closure $message, array $placeholders = array(), bool $internal = false, bool $any_write = false, array $details = array() ) {
		$value = (string) $code->value;

		if ( ! is_string( $code->value ) || 1 !== preg_match( self::CODE_PATTERN, $value ) ) {
			throw ErrorTableException::because( 'The error code "%1$s" of %2$s is not written module.reason: two lower-case snake_case words joined by a dot, such as stock.insufficient.', $value, get_class( $code ) );
		}

		if ( $http_status < 400 || $http_status > 599 ) {
			throw ErrorTableException::because( 'The row of %1$s answers with HTTP status %2$d; an error row answers with a client error (4xx) or a server error (5xx).', $value, $http_status );
		}

		$names = array_merge( $placeholders, $details );

		foreach ( $names as $index => $name ) {
			if ( 1 !== preg_match( self::PLACEHOLDER_PATTERN, $name ) ) {
				throw ErrorTableException::because( 'The row of %1$s names a placeholder or a detail key that is not one lower-case snake_case word.', $value );
			}

			if ( array_search( $name, $names, true ) !== $index ) {
				throw ErrorTableException::because( 'The row of %1$s names "%2$s" twice among its placeholders and detail keys.', $value, $name );
			}
		}

		if ( $internal && array() !== $details ) {
			throw ErrorTableException::because( 'The row of %1$s is internal, so a client reads none of its details: declare no detail keys.', $value );
		}

		$this->code         = $code;
		$this->httpStatus   = $http_status;
		$this->message      = $message;
		$this->placeholders = $placeholders;
		$this->detailKeys   = $details;
		$this->internal     = $internal;
		$this->anyWrite     = $any_write;
	}

	/**
	 * Returns the row a code has in its own catalog.
	 *
	 * @since 0.1.0
	 *
	 * @throws ErrorTableException When the catalog has no row, or more than one, for the code.
	 *
	 * @param ErrorCode $code The code.
	 * @return self The row.
	 */
	public static function of( ErrorCode $code ): self {
		$found = array();

		foreach ( $code::definitions() as $definition ) {
			if ( $definition->code() === $code ) {
				$found[] = $definition;
			}
		}

		if ( 1 !== count( $found ) ) {
			throw ErrorTableException::because( 'The error code %1$s has %2$d rows in its catalog %3$s; it must have exactly one.', (string) $code->value, count( $found ), get_class( $code ) );
		}

		return $found[0];
	}

	/**
	 * Returns the code the row defines.
	 *
	 * @since 0.1.0
	 *
	 * @return ErrorCode The code.
	 */
	public function code(): ErrorCode {
		return $this->code;
	}

	/**
	 * Returns the HTTP status an adapter answers with.
	 *
	 * @since 0.1.0
	 *
	 * @return int The status, 400 to 599.
	 */
	public function httpStatus(): int {
		return $this->httpStatus;
	}

	/**
	 * Returns the names of the context values the message contains.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names, in placeholder order.
	 */
	public function placeholders(): array {
		return $this->placeholders;
	}

	/**
	 * Returns the keys of the structured details the error may carry beyond its placeholders.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The keys, in declaration order.
	 */
	public function detailKeys(): array {
		return $this->detailKeys;
	}

	/**
	 * Tells whether the row is internal: no client may read its message or its values.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for an internal row, false for a public one.
	 */
	public function isInternal(): bool {
		return $this->internal;
	}

	/**
	 * Tells whether any operation that changes the store may fail with the code without declaring it.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for a row declared with `any_write: true`.
	 */
	public function isAnyWrite(): bool {
		return $this->anyWrite;
	}

	/**
	 * Returns the message format, translated now.
	 *
	 * Call it only when rendering, after `init`: this is where the translation happens.
	 *
	 * @since 0.1.0
	 *
	 * @return string The format, with `%1$s`-style placeholders.
	 */
	public function messageFormat(): string {
		return ( $this->message )();
	}

	/**
	 * Renders the message for a context, translated now.
	 *
	 * Each `%N$s` is replaced by the context value of the N-th placeholder name and `%%` by a
	 * percent sign; nothing else in the format is interpreted. The result is plain text: the
	 * adapter that shows it escapes it for its output.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool> $context The error's context, keyed by placeholder name.
	 * @return string The message.
	 */
	public function render( array $context ): string {
		$replacements = array( '%%' => '%' );

		foreach ( $this->placeholders as $index => $name ) {
			$replacements[ '%' . ( $index + 1 ) . '$s' ] = (string) ( $context[ $name ] ?? '' );
		}

		return strtr( $this->messageFormat(), $replacements );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

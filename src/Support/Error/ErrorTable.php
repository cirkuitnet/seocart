<?php
/**
 * ErrorTable: the one error table, composed from every module's catalog
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
 * The one error table: every error code SEOCart can raise, with its status and message.
 *
 * This class owns one fact: which codes exist across all modules, each exactly once (DRY
 * rule 8). Each module declares its codes as an ErrorCode enum — its catalog — and the table
 * is composed from the catalogs. Composing fails, with an ErrorTableException, when a catalog
 * is not an ErrorCode enum, a case has no row or two, a row belongs to another catalog's code,
 * or two catalogs declare the same code. Composing is pure: no row's message is translated.
 *
 * Adapters look rows up here: the REST foundation turns a CodedException into a `WP_Error`
 * with definitionFor( $e->errorCode() )->httpStatus() and ->render( $e->context() ), and the
 * generated error reference lists definitions(). Which catalogs the plugin composes is decided
 * where the modules are wired together.
 *
 * @since 0.1.0
 */
final class ErrorTable {

	/**
	 * Every row, keyed by code, in code order.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, ErrorDefinition>
	 */
	private array $definitions;

	/**
	 * Creates the table from rows already checked by compose().
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, ErrorDefinition> $definitions Every row, keyed by code, in code order.
	 */
	private function __construct( array $definitions ) {
		$this->definitions = $definitions;
	}

	/**
	 * Composes the table from module catalogs.
	 *
	 * @since 0.1.0
	 *
	 * @throws ErrorTableException When a catalog is not an ErrorCode enum, a case has no row or
	 *                             more than one, a row defines another catalog's code, or a code
	 *                             is declared by two catalogs.
	 *
	 * @param string ...$catalogs The class names of the catalogs: enums that implement ErrorCode.
	 * @return self The table.
	 *
	 * @phpstan-param class-string ...$catalogs
	 */
	public static function compose( string ...$catalogs ): self {
		$definitions = array();
		$declared_by = array();

		foreach ( $catalogs as $catalog ) {
			if ( ! is_subclass_of( $catalog, ErrorCode::class ) ) {
				throw ErrorTableException::because( '%1$s is not an error catalog: a catalog is an enum that implements %2$s.', $catalog, ErrorCode::class );
			}

			$rows = array();

			foreach ( $catalog::definitions() as $definition ) {
				$code = $definition->code();

				if ( ! $code instanceof $catalog ) {
					throw ErrorTableException::because( 'The catalog %1$s declares a row for %2$s, a code of %3$s.', $catalog, (string) $code->value, get_class( $code ) );
				}

				if ( isset( $rows[ $code->name ] ) ) {
					throw ErrorTableException::because( 'The error code %1$s has more than one row in its catalog %2$s.', (string) $code->value, $catalog );
				}

				$rows[ $code->name ] = $definition;
			}

			foreach ( $catalog::cases() as $case ) {
				$code = (string) $case->value;

				if ( ! isset( $rows[ $case->name ] ) ) {
					throw ErrorTableException::because( 'The error code %1$s has no row in its catalog %2$s.', $code, $catalog );
				}

				if ( isset( $declared_by[ $code ] ) ) {
					throw ErrorTableException::because( 'The error code %1$s is declared twice, by %2$s and by %3$s.', $code, $declared_by[ $code ], $catalog );
				}

				$definitions[ $code ] = $rows[ $case->name ];
				$declared_by[ $code ] = $catalog;
			}
		}

		ksort( $definitions, SORT_STRING );

		return new self( $definitions );
	}

	/**
	 * Returns the row of a code.
	 *
	 * @since 0.1.0
	 *
	 * @throws ErrorTableException When the code's catalog was not composed into this table.
	 *
	 * @param ErrorCode $code The code.
	 * @return ErrorDefinition The row.
	 */
	public function definitionFor( ErrorCode $code ): ErrorDefinition {
		$definition = $this->definitions[ (string) $code->value ] ?? null;

		if ( null === $definition || $definition->code() !== $code ) {
			throw ErrorTableException::because( 'The error code %1$s is not in this error table: compose the table with its catalog, %2$s.', (string) $code->value, get_class( $code ) );
		}

		return $definition;
	}

	/**
	 * Returns every row.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> The rows, in code order.
	 */
	public function definitions(): array {
		return array_values( $this->definitions );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

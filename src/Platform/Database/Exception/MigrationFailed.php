<?php
/**
 * MigrationFailed: a migration that did not reach its declared end state, and stopped the chain
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Exception;

use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\ReportCode;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * A migration threw, or its tables do not match their declarations afterwards.
 *
 * Owns one fact: which migration stopped the chain, and why, in the same words the
 * `migrations` row records: the recorded error code and the detail, which for a
 * post-condition failure is the diff, one line per difference. Nothing after that migration
 * ran, and the store stays in degraded mode where the migration requires it until a re-run
 * gets past it.
 *
 * @since 0.1.0
 */
final class MigrationFailed extends DatabaseException {

	/**
	 * The catalog case this class raises.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseError
	 */
	public const CODE = DatabaseError::MigrationFailed;

	/**
	 * Builds the failure of a migration whose tables do not match their declarations.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $migrationId The migration's id.
	 * @param string[] $diff        One line per difference, as SchemaVerifier::diff() returns them.
	 * @return self The exception.
	 */
	public static function postconditions( string $migrationId, array $diff ): self {
		return self::because(
			self::CODE,
			array(
				'migration_id' => $migrationId,
				'error_code'   => ReportCode::PostconditionMismatch->value,
				'detail'       => implode( "\n", $diff ),
			)
		);
	}

	/**
	 * Builds the failure of a migration that threw.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $migrationId The migration's id.
	 * @param \Throwable $failure     What it threw.
	 * @return self The exception, carrying the failure as its previous exception.
	 */
	public static function threw( string $migrationId, \Throwable $failure ): self {
		if ( $failure instanceof CodedException ) {
			$code   = (string) $failure->errorCode()->value;
			$detail = $code . ' ' . (string) wp_json_encode( $failure->context() );
		} else {
			$code   = self::CODE->value;
			$detail = get_class( $failure ) . ': ' . $failure->getMessage();
		}

		return self::because(
			self::CODE,
			array(
				'migration_id' => $migrationId,
				'error_code'   => $code,
				'detail'       => $detail,
			),
			$failure
		);
	}

	/**
	 * Returns the id of the migration that failed.
	 *
	 * @since 0.1.0
	 *
	 * @return string The migration id.
	 */
	public function migrationId(): string {
		return (string) $this->context()['migration_id'];
	}

	/**
	 * Returns the error code recorded in the migrations row.
	 *
	 * @since 0.1.0
	 *
	 * @return string ReportCode::PostconditionMismatch, the code of the coded failure the
	 *                migration threw, or database.migration_failed for any other failure.
	 */
	public function recordedCode(): string {
		return (string) $this->context()['error_code'];
	}

	/**
	 * Returns what went wrong.
	 *
	 * @since 0.1.0
	 *
	 * @return string The diff lines joined by line breaks, or a description of what the migration threw.
	 */
	public function detail(): string {
		return (string) $this->context()['detail'];
	}

	/**
	 * Returns the post-condition diff.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One line per difference; empty when the migration threw.
	 */
	public function diff(): array {
		if ( ReportCode::PostconditionMismatch->value !== $this->recordedCode() || '' === $this->detail() ) {
			return array();
		}

		return explode( "\n", $this->detail() );
	}
}

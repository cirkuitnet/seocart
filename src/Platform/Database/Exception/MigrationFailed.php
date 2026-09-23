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

defined( 'ABSPATH' ) || exit;

/**
 * A migration threw, or its tables do not match their declarations afterwards.
 *
 * Owns one fact: which migration stopped the chain, and why, in the same words the
 * `migrations` row records: the error code and, for a post-condition failure, the diff.
 * Nothing after that migration ran, and the store stays in degraded mode where the migration
 * requires it until a re-run gets past it.
 *
 * @since 0.1.0
 */
final class MigrationFailed extends DatabaseException {

	/**
	 * The machine code.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CODE = 'database.migration_failed';

	/**
	 * The error code recorded when the tables do not match their declarations after the migration ran.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const POSTCONDITION_MISMATCH = 'database.postcondition_mismatch';

	/**
	 * The migration's id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $migrationId;

	/**
	 * The recorded error code: POSTCONDITION_MISMATCH, the code of a database failure, or CODE.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $errorCode;

	/**
	 * The post-condition diff, one line per deviation. Empty when the migration threw.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $diff;

	/**
	 * Describes the failed migration. Use the named constructors.
	 *
	 * @since 0.1.0
	 *
	 * @param string          $migrationId The migration's id.
	 * @param string          $errorCode   The recorded error code.
	 * @param string[]        $diff        The post-condition diff, or an empty list.
	 * @param string          $message     What happened, for a person reading a log.
	 * @param \Throwable|null $previous    Optional. The failure the migration threw. Default null.
	 */
	private function __construct( string $migrationId, string $errorCode, array $diff, string $message, ?\Throwable $previous = null ) {
		$this->migrationId = $migrationId;
		$this->errorCode   = $errorCode;
		$this->diff        = $diff;

		parent::__construct(
			$message,
			array(
				'migration_id' => $migrationId,
				'error_code'   => $errorCode,
				'diff'         => $diff,
			),
			$previous
		);
	}

	/**
	 * Describes a migration whose tables do not match their declarations.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $migrationId The migration's id.
	 * @param string[] $diff        One line per deviation, as SchemaVerifier::diff() returns them.
	 * @return self The exception.
	 */
	public static function postconditions( string $migrationId, array $diff ): self {
		return new self(
			$migrationId,
			self::POSTCONDITION_MISMATCH,
			$diff,
			sprintf( 'Migration %s ran, but %d post-condition(s) failed; first: %s', $migrationId, count( $diff ), $diff[0] ?? '(none)' )
		);
	}

	/**
	 * Describes a migration that threw.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $migrationId The migration's id.
	 * @param \Throwable $failure     What it threw.
	 * @return self The exception.
	 */
	public static function threw( string $migrationId, \Throwable $failure ): self {
		return new self(
			$migrationId,
			$failure instanceof DatabaseException ? $failure->code() : self::CODE,
			array(),
			sprintf( 'Migration %s failed: %s', $migrationId, $failure->getMessage() ),
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
		return $this->migrationId;
	}

	/**
	 * Returns the error code recorded in the migrations row.
	 *
	 * @since 0.1.0
	 *
	 * @return string POSTCONDITION_MISMATCH, the code of a database failure, or CODE for anything else.
	 */
	public function errorCode(): string {
		return $this->errorCode;
	}

	/**
	 * Returns the post-condition diff.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One line per deviation; empty when the migration threw.
	 */
	public function diff(): array {
		return $this->diff;
	}
}

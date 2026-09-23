<?php
/**
 * DatabaseException: the base of every failure the Database module reports by throwing
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * A database failure, described by a stable machine code and structured context.
 *
 * Owns one fact: how the Database module describes a failure to the code that catches it.
 * Every concrete subclass declares its code once, in its own `CODE` constant, under the
 * `database.` namespace. Callers decide on the class or on code(), never on the message,
 * which is for people reading a log. The adapters translate the code through the one error
 * table at the boundary.
 *
 * It extends \RuntimeException until the shared kernel's typed exception base exists; the
 * switch is this one `extends` line.
 *
 * @since 0.1.0
 */
abstract class DatabaseException extends \RuntimeException {

	/**
	 * The machine code. Empty here: every concrete subclass declares its own.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CODE = '';

	/**
	 * Structured facts about the failure, safe to log.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>
	 */
	private array $context;

	/**
	 * Describes a failure.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message  What happened, for a person reading a log.
	 * @param array<string, mixed> $context  Optional. Structured facts about the failure. Default empty.
	 * @param \Throwable|null      $previous Optional. The failure that caused this one. Default null.
	 */
	public function __construct( string $message, array $context = array(), ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );

		$this->context = $context;
	}

	/**
	 * Returns the machine code of this kind of failure.
	 *
	 * @since 0.1.0
	 *
	 * @return string A code such as `database.duplicate_key`.
	 */
	public function code(): string {
		return static::CODE;
	}

	/**
	 * Returns the structured facts about the failure.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The context, keyed by fact name.
	 */
	public function context(): array {
		return $this->context;
	}
}

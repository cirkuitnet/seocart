<?php
/**
 * StatementDiagnostic: the statement and the server's text behind a database error, kept out of its message
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\QueryFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostic text a coded database error carries as its previous exception, and never renders.
 *
 * Owns one fact: where the SQL and the server's error text of a failure live. A statement can
 * carry the values it writes, and the server's text for a duplicate key quotes the value, so
 * neither may enter a coded error's context, which an adapter renders for a client. They stay
 * here, for a log, `doctor` and the `migrations` table.
 *
 * @since 0.1.0
 */
final class StatementDiagnostic extends \RuntimeException {

	/**
	 * The beginning of the statement.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $statement = '';

	/**
	 * The error text the server returned, or an empty string.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $serverMessage = '';

	/**
	 * Builds the diagnostic of a statement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement     The statement. Only its first QueryFailed::STATEMENT_LENGTH characters are kept.
	 * @param string $serverMessage The error text the server or wpdb gave, or an empty string.
	 * @return self The diagnostic.
	 */
	public static function of( string $statement, string $serverMessage ): self {
		$short      = QueryFailed::shorten( $statement );
		$diagnostic = new self( '' === $serverMessage ? $short : $short . ' -- ' . $serverMessage );

		$diagnostic->statement     = $short;
		$diagnostic->serverMessage = $serverMessage;

		return $diagnostic;
	}

	/**
	 * Returns the beginning of the statement.
	 *
	 * @since 0.1.0
	 *
	 * @return string At most QueryFailed::STATEMENT_LENGTH characters.
	 */
	public function statement(): string {
		return $this->statement;
	}

	/**
	 * Returns the error text the server gave. For people only: never decide on it.
	 *
	 * @since 0.1.0
	 *
	 * @return string The text, or an empty string.
	 */
	public function serverMessage(): string {
		return $this->serverMessage;
	}
}

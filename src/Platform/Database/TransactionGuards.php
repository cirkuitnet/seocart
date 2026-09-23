<?php
/**
 * TransactionGuards: stops outbound HTTP, mail and DDL while a transaction is open
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;

defined( 'ABSPATH' ) || exit;

/**
 * Watches three WordPress seams for work that must never happen inside a transaction window.
 *
 * Owns one fact: what is forbidden inside a window and how each kind is recognized.
 *
 * - An outbound HTTP request holds row locks across a network round trip: `pre_http_request`.
 * - wp_mail() is HTTP or SMTP in disguise, and a mail failure must never fail a commerce
 *   write: `pre_wp_mail`.
 * - DDL and foreign transaction control end the transaction silently, because MySQL commits
 *   implicitly: the `query` filter, on the statement's leading keyword, before wpdb sends it.
 *   Temporary tables do not commit and pass. The wrapper's own BEGIN, COMMIT and ROLLBACK pass.
 *
 * In strict mode (development) a violation throws ForbiddenInsideTransaction at the call site;
 * otherwise it is reported and allowed to proceed. A reported statement that ends the
 * transaction also marks the unit of work aborted, so the wrapper's later statements are
 * refused instead of autocommitting, and COMMIT is never sent. Database registers the guards once, at its first
 * transaction, so an idle request pays nothing and a request without a transaction pays one
 * depth comparison per hook call.
 *
 * Not guarded here: job dispatch (the jobs adapter checks the depth itself), clean_post_cache()
 * (the product write calls it inside its window by design), long loops and money-seam callbacks,
 * which no hook can recognize.
 *
 * @since 0.1.0
 */
final class TransactionGuards {

	/**
	 * The leading keywords of statements that commit implicitly or take over transaction control.
	 *
	 * Each entry is a regular-expression fragment, matched case-insensitively at the start of the
	 * statement and followed by a word boundary. `ROLLBACK TO SAVEPOINT` does not end the
	 * transaction and passes. TransactionTest has one row of statements per entry, and a test
	 * keeps the two lists equal.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const IMPLICIT_COMMIT = array(
		'ALTER',
		'CREATE',
		'DROP',
		'RENAME',
		'TRUNCATE',
		'START\s+TRANSACTION',
		'BEGIN',
		'COMMIT',
		'ROLLBACK(?!\s+(?:WORK\s+)?TO\b)',
		'SET\s+(?:(?:SESSION|LOCAL)\s+|@@(?:SESSION\.|LOCAL\.)?)?autocommit',
		'LOCK\s+TABLES?',
		'UNLOCK\s+TABLES?',
		'ANALYZE',
		'OPTIMIZE',
		'REPAIR',
		'FLUSH',
		'LOAD\s+DATA',
	);

	/**
	 * Temporary-table DDL, which does not commit implicitly.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TEMPORARY_TABLE = '/^\s*(?:CREATE|DROP)\s+TEMPORARY\s+TABLE\b/i';

	/**
	 * True to throw, false to report.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $strict;

	/**
	 * Receives a machine code and context for a violation that is reported.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * Tells whether the statement being sent is one of Database's own transaction-control statements.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): bool
	 */
	private \Closure $isOwnStatement;

	/**
	 * Marks the unit of work aborted, when a reported statement is about to end the transaction.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): void
	 */
	private \Closure $markAborted;

	/**
	 * The pattern built from IMPLICIT_COMMIT, once needed.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $implicitCommit = null;

	/**
	 * The connection whose depth decides whether a window is open; null until registered.
	 *
	 * @since 0.1.0
	 *
	 * @var Database|null
	 */
	private ?Database $db = null;

	/**
	 * Creates the guards. Database creates them itself, at its first transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param bool     $strict         True to throw, false to report.
	 * @param callable $report         Receives a machine code (string) and its context (array).
	 * @param \Closure $isOwnStatement Returns true while Database itself is sending the statement.
	 * @param \Closure $markAborted    Marks the unit of work aborted.
	 */
	public function __construct( bool $strict, callable $report, \Closure $isOwnStatement, \Closure $markAborted ) {
		$this->strict         = $strict;
		$this->report         = $report;
		$this->isOwnStatement = $isOwnStatement;
		$this->markAborted    = $markAborted;
	}

	/**
	 * Registers the three hooks. A second call does nothing.
	 *
	 * The HTTP and mail guards run first on their filters, so no later callback can answer the
	 * request before the guard sees it. The DDL guard runs last on `query`, so it judges the
	 * statement wpdb will actually send.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection whose depth decides whether a window is open.
	 */
	public function register( Database $db ): void {
		if ( null !== $this->db ) {
			return;
		}

		$this->db = $db;

		add_filter( 'pre_http_request', array( $this, 'guardHttp' ), PHP_INT_MIN, 3 );
		add_filter( 'pre_wp_mail', array( $this, 'guardMail' ), PHP_INT_MIN, 1 );
		add_filter( 'query', array( $this, 'guardQuery' ), PHP_INT_MAX, 1 );
	}

	/**
	 * Refuses or reports an outbound HTTP request inside a window. Hooked to `pre_http_request`.
	 *
	 * @since 0.1.0
	 *
	 * @throws ForbiddenInsideTransaction In strict mode, inside a window.
	 *
	 * @param mixed $response The pre-empted response so far, normally false.
	 * @param mixed $args     The request arguments.
	 * @param mixed $url      The request URL.
	 * @return mixed The response, unchanged.
	 */
	public function guardHttp( mixed $response, mixed $args = array(), mixed $url = '' ): mixed {
		if ( $this->inside() ) {
			$this->forbid( ForbiddenInsideTransaction::KIND_HTTP, (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		}

		return $response;
	}

	/**
	 * Refuses or reports a wp_mail() call inside a window. Hooked to `pre_wp_mail`.
	 *
	 * @since 0.1.0
	 *
	 * @throws ForbiddenInsideTransaction In strict mode, inside a window.
	 *
	 * @param mixed $shortCircuit The short-circuit value so far, normally null.
	 * @return mixed The value, unchanged.
	 */
	public function guardMail( mixed $shortCircuit ): mixed {
		if ( $this->inside() ) {
			$this->forbid( ForbiddenInsideTransaction::KIND_MAIL, 'wp_mail' );
		}

		return $shortCircuit;
	}

	/**
	 * Refuses or reports a statement that would end the transaction. Hooked to `query`.
	 *
	 * @since 0.1.0
	 *
	 * @throws ForbiddenInsideTransaction In strict mode, inside a window, before wpdb sends the statement.
	 *
	 * @param mixed $query The statement wpdb is about to send.
	 * @return mixed The statement, unchanged.
	 */
	public function guardQuery( mixed $query ): mixed {
		if ( ! is_string( $query ) || ! $this->inside() || ( $this->isOwnStatement )() ) {
			return $query;
		}

		if ( null === $this->implicitCommit ) {
			$this->implicitCommit = '/^\s*(?:' . implode( '|', self::IMPLICIT_COMMIT ) . ')\b/i';
		}

		if ( 1 === preg_match( $this->implicitCommit, $query ) && 1 !== preg_match( self::TEMPORARY_TABLE, $query ) ) {
			$this->forbid( ForbiddenInsideTransaction::KIND_DDL, $query );
		}

		return $query;
	}

	/**
	 * Tells whether a transaction window is open.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True inside a window.
	 */
	private function inside(): bool {
		return null !== $this->db && 0 !== $this->db->depth();
	}

	/**
	 * Throws or reports a violation.
	 *
	 * @since 0.1.0
	 *
	 * @throws ForbiddenInsideTransaction In strict mode.
	 *
	 * @param string $kind   One of the ForbiddenInsideTransaction::KIND_* constants.
	 * @param string $detail Where it was attempted.
	 */
	private function forbid( string $kind, string $detail ): void {
		$violation = ForbiddenInsideTransaction::of( $kind, $detail );

		if ( $this->strict ) {
			throw $violation;
		}

		if ( ForbiddenInsideTransaction::KIND_DDL === $kind ) {
			// The statement is about to end the transaction; nothing after it may autocommit.
			( $this->markAborted )();
		}

		( $this->report )( (string) $violation->errorCode()->value, $violation->context() );
	}
}

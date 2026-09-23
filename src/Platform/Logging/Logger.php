<?php
/**
 * Logger: writes the plugin's log lines, redacted, with the correlation id of the work that wrote them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's one logger.
 *
 * Owns one fact: what happens between "log this" and a row in the `logs` table.
 *
 * - A line has a level, a machine code, a message and a context. A line below the minimum
 *   level is not written. The code names what happened, `events.listener_failed`; the part up
 *   to its first dot is the line's channel. A code is a static string written in the code
 *   that logs, never built from a value: segments of lowercase letters and underscores joined
 *   by dots, at most 64 characters, no digit. Any other code is stored as
 *   `logging.invalid_code`, the text given is kept nowhere, neither in the line nor in the
 *   error log, and under `WP_DEBUG` the mistake is reported with _doing_it_wrong().
 * - The message is a fixed sentence. Values go in the context, never into the message; the
 *   message is still scanned for card numbers.
 * - Everything is redacted by the Redactor before it is written: personal data replaced and
 *   secrets dropped by the plugin's declarations, card-shaped numbers removed everywhere.
 *   Nothing a caller passes can opt out.
 * - When a card number had to be removed from a line, one more line follows it, whatever the
 *   minimum level: a warning under `logging.card_number_removed` naming the offending line's
 *   channel and machine code, so the code path that handles card data can be found. It holds
 *   neither the line's content nor any digit of the card. A line that redacted a value too
 *   large or too deeply nested to be checked for a card number in full is followed by the same
 *   warning, marked `unchecked`.
 * - Every line carries the correlation id in force, so a line written while an event is
 *   delivered or a job runs carries the id of the request that caused it; and the id of the
 *   user the work runs for, once WordPress has settled who that is.
 * - A line never joins the caller's transaction. Outside a transaction it is written on the
 *   site's connection; inside one, on the logger's own LogConnection, where it commits at
 *   once, so a line written by a unit of work that is then rolled back is still recorded. If
 *   that connection cannot be opened, the logger does not try again in the same process.
 * - The logger never throws into its caller, and never logs from inside itself. When a line
 *   cannot be written, or is logged while another is being written, one line saying so goes
 *   to PHP's error log instead: its level, code and correlation id and why it failed, never
 *   its message or its context. Then it returns. That happens once per reason per process, so
 *   a database that is down cannot flood the error log; every later loss is counted, and one
 *   final line with the count is written when the process ends, only if anything was lost.
 *   That accounting is the FallbackLog's, and the reporter shares it.
 *
 * Constructing it sends nothing and registers nothing.
 *
 *     $logger->warning( 'events.listener_slow', 'A listener took longer than a second.', array( 'hook' => $hook, 'ms' => $ms ) );
 *
 * @since 0.1.0
 */
final class Logger {

	/**
	 * The most characters a message keeps: the width of the `message` column.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MESSAGE_LENGTH = 500;

	/**
	 * The channel of a code that has no dot.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DEFAULT_CHANNEL = 'plugin';

	/**
	 * A machine code: segments of lowercase letters and underscores, joined by dots.
	 *
	 * No declared code has a digit, and a code with none cannot carry a number, a token or a
	 * card, so a digit makes a code invalid.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CODE_PATTERN = '/^[a-z][a-z_]*(?:\.[a-z][a-z_]*)*$/D';

	/**
	 * The most characters a code has.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const CODE_LENGTH = 64;

	/**
	 * The most characters a channel has: the width of the `channel` column.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const CHANNEL_LENGTH = 32;

	/**
	 * The statement that writes one line. Empty context and no user are stored as NULL.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const INSERT = "INSERT INTO %i ( level, channel, machine_code, message, context_json, correlation_id, user_id, created_at ) VALUES ( %s, %s, %s, %s, NULLIF( %s, '' ), %s, NULLIF( %d, 0 ), UTC_TIMESTAMP(6) )";

	/**
	 * The site's connection, whose transaction depth decides where a line is written.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * The correlation id in force.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * Redacts every line.
	 *
	 * @since 0.1.0
	 *
	 * @var Redactor
	 */
	private Redactor $redactor;

	/**
	 * The least serious level that is written.
	 *
	 * @since 0.1.0
	 *
	 * @var Level
	 */
	private Level $minimum;

	/**
	 * Opens the logger's own connection, or returns null when it cannot.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): (Database|null)
	 */
	private \Closure $openSeparate;

	/**
	 * Accounts for lines that cannot be written, shared with the reporter.
	 *
	 * @since 0.1.0
	 *
	 * @var FallbackLog
	 */
	private FallbackLog $fallback;

	/**
	 * The logger's own connection, once opened.
	 *
	 * @since 0.1.0
	 *
	 * @var Database|null
	 */
	private ?Database $separate = null;

	/**
	 * Whether opening the logger's own connection was tried and failed.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $separateFailed = false;

	/**
	 * Whether a line is being written right now.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $writing = false;

	/**
	 * Creates the logger. Sends nothing and registers nothing.
	 *
	 * The minimum level is the kernel's to choose. The recommendation: Level::Info on a
	 * production site, and Level::Debug where `WP_DEBUG` is on.
	 *
	 * @since 0.1.0
	 *
	 * @param Database         $db           The site's connection.
	 * @param CorrelationId    $correlation  The correlation id in force.
	 * @param Redactor         $redactor     Built from the plugin's declarations.
	 * @param Level            $minimum      Optional. The least serious level written. Default Level::Info.
	 * @param callable|null    $openSeparate Optional. Opens the connection for lines written inside a
	 *                                       transaction: returns a Database, or null when it cannot.
	 *                                       Default null, which opens a LogConnection.
	 * @param FallbackLog|null $fallback     Optional. Accounts for lines that cannot be written; the
	 *                                       kernel gives the logger and the reporter the same one.
	 *                                       Default null, a FallbackLog of its own over PHP's error log.
	 *
	 * @phpstan-param (callable(): (Database|null))|null $openSeparate
	 */
	public function __construct( Database $db, CorrelationId $correlation, Redactor $redactor, Level $minimum = Level::Info, ?callable $openSeparate = null, ?FallbackLog $fallback = null ) {
		$this->db           = $db;
		$this->correlation  = $correlation;
		$this->redactor     = $redactor;
		$this->minimum      = $minimum;
		$this->openSeparate = null === $openSeparate ? fn(): ?Database => LogConnection::database( $this->db->prefix() ) : \Closure::fromCallable( $openSeparate );
		$this->fallback     = $fallback ?? new FallbackLog();
	}

	/**
	 * Writes a line, unless its level is below the minimum. Never throws.
	 *
	 * @since 0.1.0
	 *
	 * @param Level        $level   How serious it is.
	 * @param string       $code    What happened: a static machine code such as `events.listener_failed`,
	 *                              never built from a value.
	 * @param string       $message A fixed sentence. Put values in the context, never in here.
	 * @param array<mixed> $context Optional. The values, including a Throwable under `exception`. Default none.
	 */
	public function log( Level $level, string $code, string $message, array $context = array() ): void {
		if ( ! Level::reaches( $level, $this->minimum ) ) {
			return;
		}

		if ( $this->writing ) {
			$this->fail( $level, $code, 'it was logged while another line was being written, and was dropped' );

			return;
		}

		$this->writing = true;

		try {
			if ( ! self::isValidCode( $code ) ) {
				self::invalidCode();

				$code = ReportCode::InvalidCode->value;
			}

			$line = $this->line( $level, $code, $message, $context );
			$db   = 0 === $this->db->depth() ? $this->db : $this->separate();

			if ( null === $db ) {
				$this->fail( $level, $code, 'a transaction is open, and the logger\'s own connection could not be opened' );
			} else {
				$this->insert( $db, $line );

				// Written directly, still inside the writing guard: nothing it causes can log again.
				if ( $line['card_number_removed'] || $line['card_number_unchecked'] ) {
					$this->insert( $db, $this->cardNumberRemoved( $line ) );
				}
			}
		} catch ( \Throwable $failure ) {
			$this->fail( $level, $code, self::describe( $failure ) );
		} finally {
			$this->writing = false;
		}
	}

	/**
	 * Writes a debug line: detail for a developer.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $code    The machine code.
	 * @param string       $message A fixed sentence.
	 * @param array<mixed> $context Optional. The values. Default none.
	 */
	public function debug( string $code, string $message, array $context = array() ): void {
		$this->log( Level::Debug, $code, $message, $context );
	}

	/**
	 * Writes an info line: something worth knowing happened as expected.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $code    The machine code.
	 * @param string       $message A fixed sentence.
	 * @param array<mixed> $context Optional. The values. Default none.
	 */
	public function info( string $code, string $message, array $context = array() ): void {
		$this->log( Level::Info, $code, $message, $context );
	}

	/**
	 * Writes a warning line: something went wrong, and the work carried on.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $code    The machine code.
	 * @param string       $message A fixed sentence.
	 * @param array<mixed> $context Optional. The values. Default none.
	 */
	public function warning( string $code, string $message, array $context = array() ): void {
		$this->log( Level::Warning, $code, $message, $context );
	}

	/**
	 * Writes an error line: something failed, and a request or a job ended because of it.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $code    The machine code.
	 * @param string       $message A fixed sentence.
	 * @param array<mixed> $context Optional. The values. Default none.
	 */
	public function error( string $code, string $message, array $context = array() ): void {
		$this->log( Level::Error, $code, $message, $context );
	}

	/**
	 * Builds the row of a line, redacted.
	 *
	 * @since 0.1.0
	 *
	 * @param Level        $level   The level.
	 * @param string       $code    The machine code as given.
	 * @param string       $message The message as given.
	 * @param array<mixed> $context The context as given.
	 * @return array{level: string, channel: string, code: string, message: string, context: string, correlation_id: string, user_id: int, card_number_removed: bool, card_number_unchecked: bool} The row, and whether a card number was removed from it.
	 */
	private function line( Level $level, string $code, string $message, array $context ): array {
		$redacted = $this->redactor->line( $message, self::MESSAGE_LENGTH, $context );
		$json     = '';

		if ( array() !== $redacted['context'] ) {
			$encoded = wp_json_encode( $redacted['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
			$json    = false === $encoded ? '{"unencodable":true}' : $encoded;
		}

		$dot = strpos( $code, '.' );

		return array(
			'level'                 => $level->value,
			'channel'               => false === $dot ? self::DEFAULT_CHANNEL : substr( $code, 0, min( $dot, self::CHANNEL_LENGTH ) ),
			'code'                  => $code,
			'message'               => $redacted['message'],
			'context'               => $json,
			'correlation_id'        => $this->correlation->current(),
			'user_id'               => self::userId(),
			'card_number_removed'   => $redacted['card_number_removed'],
			'card_number_unchecked' => $redacted['card_number_unchecked'],
		);
	}

	/**
	 * Builds the warning that follows a line a card number was removed from, or one that redacted a value it could not check in full.
	 *
	 * The second case is reported the same way, to be safe, with `unchecked` in its context.
	 *
	 * @since 0.1.0
	 *
	 * @param array{channel: string, code: string, card_number_removed: bool} $offending The line.
	 * @return array{level: string, channel: string, code: string, message: string, context: string, correlation_id: string, user_id: int, card_number_removed: bool, card_number_unchecked: bool} The row.
	 */
	private function cardNumberRemoved( array $offending ): array {
		$names = array(
			'channel'      => $offending['channel'],
			'machine_code' => $offending['code'],
		);

		if ( $offending['card_number_removed'] ) {
			return $this->line( Level::Warning, ReportCode::CardNumberRemoved->value, 'A card number was removed from a log line before it was written. The code path that logged it handles card data.', $names );
		}

		return $this->line(
			Level::Warning,
			ReportCode::CardNumberRemoved->value,
			'A log line redacted a value too large or too deeply nested to be checked for a card number in full, so it may have held one. The code path that logged it may handle card data.',
			$names + array( 'unchecked' => true )
		);
	}

	/**
	 * Inserts a row into the current site's log table.
	 *
	 * @since 0.1.0
	 *
	 * @param Database     $db   The site's connection outside a transaction, the logger's own inside one.
	 * @param array<mixed> $line The row, as line() builds it.
	 *
	 * @phpstan-param array{level: string, channel: string, code: string, message: string, context: string, correlation_id: string, user_id: int, card_number_removed: bool, card_number_unchecked: bool} $line
	 */
	private function insert( Database $db, array $line ): void {
		$db->execute(
			self::INSERT,
			$this->db->table( LogsTable::NAME ),
			$line['level'],
			$line['channel'],
			$line['code'],
			$line['message'],
			$line['context'],
			$line['correlation_id'],
			$line['user_id']
		);
	}

	/**
	 * Returns the logger's own connection, opening it the first time; after a failure, null for good.
	 *
	 * @since 0.1.0
	 *
	 * @return Database|null The connection, or null.
	 */
	private function separate(): ?Database {
		if ( null !== $this->separate || $this->separateFailed ) {
			return $this->separate;
		}

		try {
			$this->separate = ( $this->openSeparate )();
		} catch ( \Throwable $failure ) {
			$this->separate = null;
		}

		$this->separateFailed = null === $this->separate;

		return $this->separate;
	}

	/**
	 * Accounts for a lost line in the fallback: its level, code, correlation id and why. Never throws.
	 *
	 * @since 0.1.0
	 *
	 * @param Level  $level  The lost line's level.
	 * @param string $code   The lost line's code, as given.
	 * @param string $reason Why it was lost, without any of its values.
	 */
	private function fail( Level $level, string $code, string $reason ): void {
		try {
			$correlation = $this->correlation->current();
		} catch ( \Throwable $failure ) {
			$correlation = 'none';
		}

		$this->fallback->lost(
			$reason,
			sprintf(
				'SEOCart: a log line (%1$s, %2$s, correlation id %3$s) could not be written: %4$s.',
				$level->value,
				self::isValidCode( $code ) ? $code : ReportCode::InvalidCode->value,
				$correlation,
				$reason
			)
		);
	}

	/**
	 * Tells whether a code is a well-formed machine code: lowercase letters and underscores in dotted segments, 64 characters at most.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code The code.
	 * @return bool True when it can be stored and printed as it is.
	 */
	public static function isValidCode( string $code ): bool {
		return strlen( $code ) <= self::CODE_LENGTH && 1 === preg_match( self::CODE_PATTERN, $code );
	}

	/**
	 * Reports, under `WP_DEBUG`, that a line was logged with a code that is not a static machine code.
	 *
	 * The code itself is not named: a code built from a value may hold a secret.
	 *
	 * @since 0.1.0
	 */
	private static function invalidCode(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! function_exists( '_doing_it_wrong' ) ) {
			return;
		}

		_doing_it_wrong(
			esc_html( self::class . '::log' ),
			esc_html( 'A log line was written with a code that is not a static machine code, so it was stored as logging.invalid_code and the code was not kept. A code is a fixed string of lowercase letters and underscores in dotted segments, such as events.listener_failed, never built from a value.' ),
			'0.1.0'
		);
	}

	/**
	 * Describes why a line could not be written, without any value the failure carries.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $failure The failure.
	 * @return string For a database failure its code and error number; for another coded failure
	 *                its code; otherwise its class.
	 */
	private static function describe( \Throwable $failure ): string {
		if ( $failure instanceof QueryFailed ) {
			return sprintf( '%s (MySQL error %d)', (string) $failure->errorCode()->value, $failure->errno() );
		}

		if ( $failure instanceof CodedException ) {
			return (string) $failure->errorCode()->value;
		}

		return get_class( $failure );
	}

	/**
	 * Returns the id of the user the work runs for, once WordPress has settled who that is.
	 *
	 * Asking earlier would make WordPress work out the current user from inside whatever is
	 * logging, which may itself be that work; so before then, and without a user, it is 0.
	 *
	 * @since 0.1.0
	 *
	 * @return int The user id, or 0.
	 */
	private static function userId(): int {
		return did_action( 'set_current_user' ) > 0 ? get_current_user_id() : 0;
	}
}

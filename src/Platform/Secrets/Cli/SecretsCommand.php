<?php
/**
 * SecretsCommand: `wp seocart secrets`, which reports on the data keys, rotates them and re-seals the secrets
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets\Cli;

use SEOCart\Platform\Secrets\Cipher;
use SEOCart\Platform\Secrets\EncryptionKeyState;
use SEOCart\Platform\Secrets\RekeyReport;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretsReport;
use SEOCart\Platform\Secrets\SecretsStatus;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the state of the secrets, creates a new data key, and re-seals the secrets with it.
 *
 * Owns one fact: the command's contract with an operator.
 *
 * - `status` prints the cipher, whether SEOCART_ENCRYPTION_KEY is defined, every data key with its
 *   state and the number of records it seals, the canary's outcome and the Site Health verdict. It
 *   exits 0 when the canary opens and 2 when it fails.
 * - `rotate` creates a new data key, makes it the active one and retires nothing: the old key goes
 *   on opening its records. It exits 0, or 1 with the reason it refused.
 * - `rekey` re-seals, batch after batch, every record sealed with another key than the active one,
 *   and retires the retiring key once no record names it. Each record is replaced on its own, so
 *   the command can be stopped at any point and run again. It exits 0 when every record is sealed
 *   with the active key, and 2 when some are left: records that cannot be opened, which must be
 *   entered again, or records that changed meanwhile, which the next run re-seals.
 *
 * Nothing it prints is a key or a secret: it prints key ids, counts, states, times and record names.
 * A failure exits 1 with its code and message, which carry no secret either.
 *
 * It is a maintenance command, not an application operation: it has no REST or Ability twin, so
 * it resolves to no operation definition by design.
 *
 * Output goes through a callable and run() returns the exit code, so tests call run() without
 * WP-CLI. Only __invoke(), which WP-CLI calls, touches the WP_CLI class. The kernel registers the
 * command.
 *
 * @since 0.1.0
 */
final class SecretsCommand {

	/**
	 * Exit code: done.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code: refused or failed, or the command was used wrongly.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_FAILED = 1;

	/**
	 * Exit code: the canary fails, or records are left sealed with another key than the active one.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_ATTENTION = 2;

	/**
	 * How many records one rekey batch re-seals, unless the operator says otherwise.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DEFAULT_BATCH = 50;

	/**
	 * The data keys.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretKeys
	 */
	private SecretKeys $keys;

	/**
	 * The records.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretVault
	 */
	private SecretVault $vault;

	/**
	 * The status.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretsStatus
	 */
	private SecretsStatus $status;

	/**
	 * Prints one line for the operator.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string): void
	 */
	private $output;

	/**
	 * Creates the command.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretKeys             $keys   The data keys.
	 * @param SecretVault            $vault  The records.
	 * @param SecretsStatus          $status The status.
	 * @param callable(string): void $output Prints one line, for example WP_CLI::log().
	 */
	public function __construct( SecretKeys $keys, SecretVault $vault, SecretsStatus $status, callable $output ) {
		$this->keys   = $keys;
		$this->vault  = $vault;
		$this->status = $status;
		$this->output = $output;
	}

	/**
	 * Reports on the stored secrets' keys, creates a new data key, or re-seals the secrets with it.
	 *
	 * Never prints a secret.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do. `status` prints the keys, their record counts and the canary; `rotate` creates
	 * a new data key; `rekey` re-seals every record with the active key and retires the old one.
	 * ---
	 * options:
	 *   - status
	 *   - rotate
	 *   - rekey
	 * ---
	 *
	 * [--batch=<records>]
	 * : With rekey, how many records each batch re-seals.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : With status, how to print it.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXIT STATUS
	 *
	 * 0 when done; 1 when refused or on a failure; 2 when the canary fails (status) or records are
	 * left sealed with another key (rekey).
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart secrets status
	 *     wp seocart secrets status --format=json
	 *     wp seocart secrets rotate
	 *     wp seocart secrets rekey --batch=100
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args      The action.
	 * @param array<string, string|bool> $assocArgs The options.
	 */
	public function __invoke( array $args, array $assocArgs ): void {
		\WP_CLI::halt( $this->run( $args, $assocArgs ) );
	}

	/**
	 * Runs the command and returns its exit code.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args      The action: status, rotate or rekey.
	 * @param array<string, string|bool> $assocArgs The options: batch, format.
	 * @return int One of the EXIT_* constants.
	 */
	public function run( array $args, array $assocArgs ): int {
		try {
			switch ( $args[0] ?? '' ) {
				case 'status':
					return $this->status( 'json' === ( $assocArgs['format'] ?? 'text' ) );

				case 'rotate':
					return $this->rotate();

				case 'rekey':
					return $this->rekey( self::intOption( $assocArgs, 'batch', self::DEFAULT_BATCH ) );

				default:
					$this->say( 'Usage: wp seocart secrets <status|rotate|rekey> [--batch=<records>] [--format=<text|json>]' );

					return self::EXIT_FAILED;
			}
		} catch ( CodedException $failure ) {
			$this->say( (string) $failure->errorCode()->value . ': ' . ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() ) );

			return self::EXIT_FAILED;
		}
	}

	/**
	 * Prints the state of the secrets.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $json Whether to print it as JSON.
	 * @return int EXIT_OK when the canary opens, EXIT_ATTENTION when it fails.
	 */
	private function status( bool $json ): int {
		$report   = $this->status->report();
		$severity = SecretsStatus::severity( $report );

		if ( $json ) {
			$this->say( (string) wp_json_encode( $report->toArray() + array( 'site_health' => $severity ) ) );
		} else {
			$this->describe( $report, $severity );
		}

		return $report->canary->ok() ? self::EXIT_OK : self::EXIT_ATTENTION;
	}

	/**
	 * Prints the state of the secrets as lines of text.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretsReport $report   The state.
	 * @param string        $severity The Site Health verdict.
	 */
	private function describe( SecretsReport $report, string $severity ): void {
		$libraries = array(
			Cipher::EXTENSION => 'PHP\'s sodium extension',
			Cipher::POLYFILL  => 'the sodium_compat library WordPress ships',
		);
		$constant  = array(
			EncryptionKeyState::Absent->value  => 'not defined: the data keys are stored unprotected in the database',
			EncryptionKeyState::Invalid->value => 'defined, but not the base64 encoding of 32 bytes',
			EncryptionKeyState::Present->value => 'defined',
		);

		$this->say( 'Cipher: ' . ( null === $report->library ? 'none available' : Cipher::ALGORITHM . ', from ' . $libraries[ $report->library ] ) );
		$this->say( 'SEOCART_ENCRYPTION_KEY: ' . $constant[ $report->encryptionKey->value ] );

		if ( array() === $report->keys ) {
			$this->say( 'Data keys: none yet' );
		}

		foreach ( $report->keys as $key ) {
			$this->say(
				sprintf(
					'Data key %1$s: %2$s%3$s, %4$d %5$s, created %6$s UTC%7$s',
					$key['key_id'],
					$key['state'],
					null === $key['wrapped'] ? '' : ( $key['wrapped'] ? ', protected by SEOCART_ENCRYPTION_KEY' : ', stored unprotected' ),
					$key['records'],
					1 === $key['records'] ? 'record' : 'records',
					$key['created_at'],
					null === $key['retired_at'] ? '' : ', retired ' . $key['retired_at'] . ' UTC'
				)
			);
		}

		if ( $report->orphaned > 0 ) {
			$this->say( sprintf( 'Records sealed with no key the site has: %d. Enter them again.', $report->orphaned ) );
		}

		if ( $report->damaged > 0 ) {
			$this->say( sprintf( 'Records that cannot be read as stored: %d. Repair them; until then no data key is retired.', $report->damaged ) );
		}

		$failure = $report->canary->failure;

		$this->say( 'Canary: ' . ( null === $failure ? 'opens with the active key' : 'fails (' . $failure->value . '): ' . $report->canary->cause() ) );
		$this->say( 'Site Health: ' . $severity );
	}

	/**
	 * Creates a new data key.
	 *
	 * @since 0.1.0
	 *
	 * @return int EXIT_OK.
	 */
	private function rotate(): int {
		$new      = $this->keys->rotate();
		$retiring = (string) $this->keys->retiringKeyId();
		$records  = $this->vault->counts()[ $retiring ] ?? 0;

		$this->say( sprintf( 'Created the data key %1$s; new secrets are sealed with it. The data key %2$s is retiring and still seals %3$d %4$s.', $new, $retiring, $records, 1 === $records ? 'record' : 'records' ) );
		$this->say( 'Run wp seocart secrets rekey to re-seal them with the new key and retire the old one.' );

		return self::EXIT_OK;
	}

	/**
	 * Re-seals, batch after batch, every record sealed with another key than the active one.
	 *
	 * Stops when a batch re-seals nothing, so records that cannot be opened do not keep it running.
	 *
	 * @since 0.1.0
	 *
	 * @param int $batch How many records each batch re-seals.
	 * @return int EXIT_OK when every record is sealed with the active key, EXIT_ATTENTION otherwise.
	 */
	private function rekey( int $batch ): int {
		$batch    = max( 1, $batch );
		$resealed = 0;
		$retired  = null;

		do {
			$report    = $this->vault->rekey( $batch );
			$resealed += $report->resealed;
			$retired ??= $report->retired;
		} while ( $report->resealed > 0 && $report->pending > 0 );

		$this->say( sprintf( 'Re-sealed %1$d %2$s with the data key %3$s.', $resealed, 1 === $resealed ? 'record' : 'records', $report->activeKeyId ) );

		if ( null !== $retired ) {
			$this->say( sprintf( 'Retired the data key %s: no record names it any more.', $retired ) );
		}

		return $this->left( $report );
	}

	/**
	 * Prints what a rekey left, and returns the exit code it deserves.
	 *
	 * @since 0.1.0
	 *
	 * @param RekeyReport $report The last batch's report.
	 * @return int EXIT_OK when nothing is left, EXIT_ATTENTION otherwise.
	 */
	private function left( RekeyReport $report ): int {
		if ( $report->settled() ) {
			$this->say( 'Every stored secret is sealed with the active key.' );

			return self::EXIT_OK;
		}

		if ( array() !== $report->unreadable ) {
			$this->say( sprintf( 'These records cannot be opened and must be entered again: %s.', implode( ', ', $report->unreadable ) ) );
		}

		if ( array() !== $report->damaged ) {
			$this->say( sprintf( 'These records cannot be read as stored, because their option or another setting stored beside them is damaged; no data key is retired until they are repaired: %s.', implode( ', ', $report->damaged ) ) );
		}

		if ( $report->pending > 0 ) {
			$this->say( sprintf( '%d %s changed while being re-sealed. Run the command again.', $report->pending, 1 === $report->pending ? 'record' : 'records' ) );
		}

		return self::EXIT_ATTENTION;
	}

	/**
	 * Prints one line.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The line.
	 */
	private function say( string $line ): void {
		( $this->output )( $line );
	}

	/**
	 * Reads a whole-number option.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string|bool> $assocArgs The options.
	 * @param string                     $name      The option name.
	 * @param int                        $fallback  The value when the option is absent or not a whole number.
	 * @return int The value.
	 */
	private static function intOption( array $assocArgs, string $name, int $fallback ): int {
		$value = $assocArgs[ $name ] ?? null;

		return is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ? (int) $value : $fallback;
	}
}

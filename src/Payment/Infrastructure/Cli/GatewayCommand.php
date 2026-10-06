<?php
/**
 * GatewayCommand: `wp seocart gateway`, which shows the payment gateways' state and switches one off or on
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Cli;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions tell an operator on the command line why a file or a value was refused; they are printed as text, never HTML, and name files and settings, never values.

use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Application\CredentialState;
use SEOCart\Payment\Application\GatewayConfiguration;
use SEOCart\Payment\Application\GatewayConfigured;
use SEOCart\Payment\Application\GatewayStatus;
use SEOCart\Payment\Application\GatewayStatuses;
use SEOCart\Payment\Application\GatewaySwitches;
use SEOCart\Payment\Application\RefusedRegistration;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;

/**
 * Lets an operator see and control the payment gateways from the server.
 *
 * Owns one fact: the command's contract with an operator.
 *
 * - `status [<gateway>]` prints each registered gateway's state (GatewayStatus), a table each, then the
 *   registrations refused in this request with their reasons, and the gateways that open payments
 *   name but that are not registered. It never prints a setting's value. Exits 0.
 * - `disable <gateway>` switches a registered gateway off: it takes no new payment, and its open
 *   payments are still captured, voided, refunded and reconciled. `enable <gateway>` switches it
 *   on again; a switch left by a gateway since removed can be cleared too. Each is one conditional
 *   write of the boot record. Exits 0, or 1 when the switch could not be recorded.
 * - `mode <gateway> <test|live>` sets the mode new payments through a gateway are created in, as
 *   the user given with `--user`, who needs `seocart_manage_settings`; the gateway must be set up
 *   for that mode. Payments already made keep their mode. Exits 0, or 1 when refused.
 * - `configure <gateway> --mode=<test|live> --from-file=<path>` saves a gateway's settings for a
 *   mode from a JSON object in a file only its owner can read (never from the command line, which
 *   other users of the server can see), as a user who holds `seocart_manage_secrets`; then, for a
 *   gateway that sets up its own webhook endpoints, it has the provider set up this site's
 *   endpoint and saves its signing secret, unless `--no-webhooks` is given. The capability, the
 *   gateway and Safe Mode are checked before the file is opened. Exits 0; 1 when refused, with
 *   nothing saved; 2 when the settings were saved but the webhook step failed.
 *
 * A failure exits 1 with its code and message, which carry no secret.
 *
 * It is a maintenance command, not an application operation: it has no REST or Ability twin.
 * Output goes through callables and run() returns the exit code, so tests call run() without
 * WP-CLI. Only __invoke(), which WP-CLI calls, touches the WP_CLI class.
 *
 * @since 0.2.0
 */
final class GatewayCommand {

	/**
	 * Exit code: done.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code: refused or failed, or the command was used wrongly.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EXIT_FAILED = 1;

	/**
	 * The largest settings file configure reads, in bytes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_FILE_BYTES = 65536;

	/**
	 * Exit code: done, but a step after it failed and needs the operator.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EXIT_ATTENTION = 2;

	/**
	 * The bits of a file's mode that give its type, as lstat() and fstat() report it.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const FILE_TYPE = 0170000;

	/**
	 * The type of a regular file.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const REGULAR_FILE = 0100000;

	/**
	 * The type of a symbolic link.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const SYMBOLIC_LINK = 0120000;

	/**
	 * Prints one line for the operator.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(string): void
	 */
	private \Closure $output;

	/**
	 * Prints one item in a format.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(array<string, string|int>, string): void
	 */
	private \Closure $display;

	/**
	 * Creates the command.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayStatuses      $statuses      The gateways' statuses.
	 * @param GatewaySwitches      $switches      The kill switches.
	 * @param GatewayConfiguration $configuration The writer of the gateways' settings.
	 * @param callable             $output        Prints one line (string), for example WP_CLI::log().
	 * @param callable             $display       Prints one item (array<string, string|int>) in a format (string), as WP-CLI's formatter does.
	 *
	 * @phpstan-param callable(string): void                            $output
	 * @phpstan-param callable(array<string, string|int>, string): void $display
	 */
	public function __construct( private GatewayStatuses $statuses, private GatewaySwitches $switches, private GatewayConfiguration $configuration, callable $output, callable $display ) {
		$this->output  = \Closure::fromCallable( $output );
		$this->display = \Closure::fromCallable( $display );
	}

	/**
	 * Shows the payment gateways' state, switches one off or on, or sets the mode it takes new payments in.
	 *
	 * A gateway switched off takes no new payment; the payments it already holds are still
	 * captured, voided, refunded and reconciled. A payment keeps the mode it was created in.
	 * Never prints a credential.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - status
	 *   - disable
	 *   - enable
	 *   - mode
	 *   - configure
	 * ---
	 *
	 * [<gateway>]
	 * : The gateway's id, such as stripe. status shows every gateway without it; the other actions need it.
	 *
	 * [<mode>]
	 * : With mode: test or live. Run it with --user, as a user who may manage the store's settings.
	 *
	 * [--mode=<mode>]
	 * : With configure: the mode whose settings the file holds, test or live.
	 *
	 * [--from-file=<path>]
	 * : With configure: a file holding one JSON object of the gateway's settings for the mode, by
	 * name, such as {"secret_key": "…"}. Only its owner may read it (chmod 600). Run configure with
	 * --user, as a user who may manage the store's secrets.
	 *
	 * [--[no-]webhooks]
	 * : With configure: whether to have the provider set up this site's webhook endpoint for the
	 * mode. With --no-webhooks the file may give the signing secret itself, as webhook_secret.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--format=<format>]
	 * : With status, how to print it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXIT STATUS
	 *
	 * 0 when done; 1 when refused, on a failure, or when the command was used wrongly; 2 when
	 * configure saved the settings but the webhook step failed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart gateway status
	 *     wp seocart gateway status stripe --format=json
	 *     wp seocart gateway disable stripe
	 *     wp seocart gateway enable stripe
	 *     wp seocart gateway mode stripe live --user=admin
	 *     wp seocart gateway configure stripe --mode=test --from-file=stripe-test.json --user=admin
	 *
	 * @since 0.2.0
	 *
	 * @param string[]                   $args      The action, the gateway and the mode.
	 * @param array<string, string|bool> $assocArgs The options.
	 */
	public function __invoke( array $args, array $assocArgs ): void {
		\WP_CLI::halt( $this->run( $args, $assocArgs ) );
	}

	/**
	 * Runs the command and returns its exit code.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]                   $args      The action, and the gateway.
	 * @param array<string, string|bool> $assocArgs The options.
	 * @return int One of the EXIT_* constants.
	 */
	public function run( array $args, array $assocArgs ): int {
		$gateway = (string) ( $args[1] ?? '' );

		try {
			switch ( $args[0] ?? '' ) {
				case 'status':
					return $this->status( '' === $gateway ? null : $gateway, (string) ( $assocArgs['format'] ?? 'table' ) );

				case 'disable':
					return '' === $gateway ? $this->usage() : $this->disable( $gateway );

				case 'enable':
					return '' === $gateway ? $this->usage() : $this->enable( $gateway );

				case 'mode':
					$mode = Mode::tryFrom( (string) ( $args[2] ?? '' ) );

					return '' === $gateway || null === $mode ? $this->usage() : $this->switchMode( $gateway, $mode );

				case 'configure':
					$mode      = Mode::tryFrom( (string) ( $assocArgs['mode'] ?? '' ) );
					$path      = (string) ( $assocArgs['from-file'] ?? '' );
					$provision = (bool) ( $assocArgs['webhooks'] ?? true );

					return '' === $gateway || null === $mode || '' === $path ? $this->usage() : $this->configure( $gateway, $mode, $path, $provision );

				default:
					return $this->usage();
			}
		} catch ( CodedException $failure ) {
			$this->say( (string) $failure->errorCode()->value . ': ' . ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() ) );

			if ( AuthorizationError::Denied === $failure->errorCode() && 0 === get_current_user_id() ) {
				$this->say( 'This action changes the store, so it runs only as a user: run it with --user=<login>.' );
			}
		} catch ( \InvalidArgumentException $invalid ) {
			$this->say( $invalid->getMessage() );
		}

		return self::EXIT_FAILED;
	}

	/**
	 * Prints the state of one gateway or of all, the refused registrations and the gateways open payments name that are not registered.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $gatewayId The gateway, or null for all.
	 * @param string      $format    `table` or `json`.
	 * @return int EXIT_OK, or EXIT_FAILED for another format.
	 */
	private function status( ?string $gatewayId, string $format ): int {
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			return $this->usage();
		}

		$statuses = null === $gatewayId ? $this->statuses->all() : array( $this->statuses->of( $gatewayId ) );
		$refused  = null === $gatewayId ? $this->statuses->refused() : array();
		$gone     = null === $gatewayId ? $this->statuses->unregistered() : array();
		$reasons  = array() === $gone ? array() : $this->statuses->refusalReasons();

		if ( 'json' === $format ) {
			$this->say(
				(string) wp_json_encode(
					array(
						'gateways'     => array_map( static fn( GatewayStatus $status ): array => $status->toArray(), $statuses ),
						'refused'      => array_map( static fn( RefusedRegistration $refusal ): array => $refusal->toArray(), $refused ),
						'unregistered' => (object) $gone,
					)
				)
			);

			return self::EXIT_OK;
		}

		foreach ( $statuses as $status ) {
			( $this->display )( self::row( $status ), 'table' );
		}

		foreach ( $refused as $refusal ) {
			$this->say( sprintf( 'Refused: %1$s, from %2$s (%3$s): %4$s', $refusal->gateway, $refusal->plugin ?? 'no plugin', $refusal->reason, $refusal->detail ) );
		}

		foreach ( $gone as $id => $open ) {
			$this->say( self::goneLine( $id, $open, $reasons[ $id ] ?? null ) );
		}

		return self::EXIT_OK;
	}

	/**
	 * Switches a registered gateway off.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway.
	 * @return int EXIT_OK, or EXIT_FAILED when the switch could not be recorded.
	 */
	private function disable( string $gatewayId ): int {
		$status = $this->statuses->of( $gatewayId );

		if ( ! $this->switches->disable( $gatewayId ) ) {
			return $this->unrecorded( $gatewayId );
		}

		$this->say( sprintf( 'Disabled %1$s: it takes no new payment. Its %2$d open %3$s still captured, voided, refunded and reconciled through it.', $gatewayId, $status->openIntents, 1 === $status->openIntents ? 'payment is' : 'payments are' ) );

		return self::EXIT_OK;
	}

	/**
	 * Switches a gateway on again, registered or not.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway.
	 * @return int EXIT_OK, or EXIT_FAILED when the switch could not be recorded.
	 */
	private function enable( string $gatewayId ): int {
		if ( ! $this->switches->enable( $gatewayId ) ) {
			return $this->unrecorded( $gatewayId );
		}

		$this->say( sprintf( 'Enabled %s: it takes new payments again.', $gatewayId ) );

		return self::EXIT_OK;
	}

	/**
	 * Sets the mode new payments through a gateway are created in.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway.
	 * @param Mode   $mode      The mode.
	 * @return int EXIT_OK.
	 */
	private function switchMode( string $gatewayId, Mode $mode ): int {
		$version = $this->configuration->switchMode( $gatewayId, $mode, $this->actor() );
		$status  = $this->statuses->of( $gatewayId );

		$this->say( sprintf( 'New payments through %1$s are created in %2$s mode (its settings are at version %3$d).', $gatewayId, $mode->value, $version ) );

		if ( $status->effectiveMode !== $status->mode ) {
			$this->say( 'Safe Mode is on: until it ends, new payments are created in test mode.' );
		}

		$this->say( sprintf( 'Its %1$d open %2$s the mode each was created in.', $status->openIntents, 1 === $status->openIntents ? 'payment keeps' : 'payments keep' ) );

		return self::EXIT_OK;
	}

	/**
	 * Saves a gateway's settings for a mode from a file, then has its webhook endpoint set up, and prints what was done.
	 *
	 * Who may, the gateway and Safe Mode are checked before the file is opened; the file must
	 * name every setting of the mode without a default (but the webhook signing secret), so a
	 * configure from the command line states the whole mode.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the file leaves out a setting the mode needs, or as
	 *                                   readSettings() and the configuration refuse.
	 *
	 * @param string $gatewayId The gateway.
	 * @param Mode   $mode      The mode.
	 * @param string $path      The file.
	 * @param bool   $provision Whether to set up the webhook endpoint.
	 * @return int EXIT_OK, or EXIT_ATTENTION when the webhook step failed.
	 */
	private function configure( string $gatewayId, Mode $mode, string $path, bool $provision ): int {
		$actor = $this->actor();

		$this->configuration->requireConfigurable( $gatewayId, $mode, $provision, $actor );

		$values  = self::readSettings( $path );
		$missing = $this->configuration->missing( $gatewayId, $values );

		if ( array() !== $missing ) {
			throw new \InvalidArgumentException( sprintf( 'The file does not give every setting of %1$s\'s %2$s mode: %3$s missing. Nothing was saved.', $gatewayId, $mode->value, implode( ', ', $missing ) ) );
		}

		$result = $this->configuration->configure( $gatewayId, $mode, $values, $provision, $actor );

		$this->say( sprintf( 'Saved the %1$s settings of %2$s (its settings are at version %3$d).', $mode->value, $gatewayId, $result->version ) );
		$this->say( self::webhookLine( $result ) );

		if ( $result->elsewhere > 0 ) {
			$this->say( sprintf( '%1$d webhook %2$s of this site for %3$s mode at another address %4$s left alone: if the site has moved, delete %5$s in the provider\'s dashboard.', $result->elsewhere, 1 === $result->elsewhere ? 'endpoint' : 'endpoints', $mode->value, 1 === $result->elsewhere ? 'was' : 'were', 1 === $result->elsewhere ? 'it' : 'them' ) );
		}

		$state = $this->statuses->of( $gatewayId )->credentials[ $mode->value ];

		if ( CredentialState::Configured !== $state ) {
			$this->say( sprintf( '%1$s takes no payment in %2$s mode until its %2$s settings are complete (they are %3$s).', $gatewayId, $mode->value, $state->value ) );
		}

		return GatewayConfigured::FAILED === $result->webhooks ? self::EXIT_ATTENTION : self::EXIT_OK;
	}

	/**
	 * Returns who acts: the user WP-CLI runs as (`--user`), as the command line, or no one.
	 *
	 * @since 0.2.0
	 *
	 * @return Actor The actor; a visitor, who may change nothing, without `--user`.
	 */
	private function actor(): Actor {
		$user = get_current_user_id();

		return 0 === $user ? Actor::user( 0 ) : Actor::system( 'cli', $user );
	}

	/**
	 * Says that a switch could not be recorded.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway.
	 * @return int EXIT_FAILED.
	 */
	private function unrecorded( string $gatewayId ): int {
		$this->say( sprintf( 'The switch of %s was not recorded: SEOCart is not installed on this site, or a newer version of it wrote the site\'s boot record.', $gatewayId ) );

		return self::EXIT_FAILED;
	}

	/**
	 * Prints how the command is used.
	 *
	 * @since 0.2.0
	 *
	 * @return int EXIT_FAILED.
	 */
	private function usage(): int {
		$this->say( 'Usage: wp seocart gateway status [<gateway>] [--format=<table|json>] | disable <gateway> | enable <gateway> | mode <gateway> <test|live> | configure <gateway> --mode=<test|live> --from-file=<path> [--no-webhooks]' );

		return self::EXIT_FAILED;
	}

	/**
	 * Reads the settings file: a regular file only its owner can read, holding one JSON object of single values.
	 *
	 * The path is looked at without following a symbolic link, then opened once, and the file opened
	 * must be the file looked at: the same device and inode. So a link or another file put in its
	 * place in between is refused, and the file read is the file checked. Its type, permissions and
	 * size are checked again on the open file, and no more than MAX_FILE_BYTES and one byte are read
	 * from it, so a file that grows once it is open is refused too. On a filesystem without owner
	 * and group permissions, PHP reports every file readable by all, and the file is refused: the
	 * check fails closed. No message quotes the file's content.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the file is missing, a link, not a regular file, readable or
	 *                                   writable by others than its owner, too large, replaced between
	 *                                   its check and its opening, or not one JSON object of single values.
	 *
	 * @param string $path The file.
	 * @return array<string, mixed> The values, by name.
	 */
	private static function readSettings( string $path ): array {
		clearstatcache( true, $path );

		// lstat() only when there is something to look at, so a missing file is refused without a warning.
		$checked = is_link( $path ) || file_exists( $path ) ? lstat( $path ) : false;

		if ( false !== $checked && self::SYMBOLIC_LINK === ( $checked['mode'] & self::FILE_TYPE ) ) {
			throw new \InvalidArgumentException( sprintf( 'The file %s is a symbolic link: give the file itself.', $path ) );
		}

		if ( false === $checked || ! is_readable( $path ) ) {
			self::refuseUnreadable( $path );
		}

		self::requireOwnersFile( $path, $checked );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A local file the operator named, opened once and checked through its handle; WP_Filesystem is for writing the site's files.
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			self::refuseUnreadable( $path );
		}

		try {
			$opened = fstat( $handle );

			if ( false === $opened || $opened['dev'] !== $checked['dev'] || $opened['ino'] !== $checked['ino'] ) {
				throw new \InvalidArgumentException( sprintf( 'The file %s was replaced between its check and its opening: nothing was read from it. Run the command again.', $path ) );
			}

			self::requireOwnersFile( $path, $opened );

			$text = (string) stream_get_contents( $handle, self::MAX_FILE_BYTES + 1 );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the handle opened above.
			fclose( $handle );
		}

		if ( strlen( $text ) > self::MAX_FILE_BYTES ) {
			self::refuseTooLarge( $path );
		}

		try {
			$values = json_decode( $text, true, 2, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			$values = null;
		}

		if ( ! is_array( $values ) || '{' !== substr( ltrim( $text ), 0, 1 ) ) {
			throw new \InvalidArgumentException( sprintf( 'The file %s does not hold one JSON object of settings, by name.', $path ) );
		}

		return $values;
	}

	/**
	 * Refuses a file that is not a regular file, that others than its owner can read or write, or that is larger than MAX_FILE_BYTES.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When it is one of those.
	 *
	 * @param string                 $path   The file, for the message.
	 * @param array<int|string, int> $status What lstat() or fstat() reports of it.
	 */
	private static function requireOwnersFile( string $path, array $status ): void {
		if ( self::REGULAR_FILE !== ( $status['mode'] & self::FILE_TYPE ) ) {
			self::refuseUnreadable( $path );
		}

		if ( 0 !== ( $status['mode'] & 0077 ) ) {
			throw new \InvalidArgumentException( sprintf( 'The file %1$s can be read or written by other users than its owner (mode %2$04o): make it its owner\'s alone, with chmod 600.', $path, $status['mode'] & 0777 ) );
		}

		if ( $status['size'] > self::MAX_FILE_BYTES ) {
			self::refuseTooLarge( $path );
		}
	}

	/**
	 * Refuses a file that is not there, not a regular file, or not readable.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException Always.
	 *
	 * @param string $path The file.
	 * @return never
	 */
	private static function refuseUnreadable( string $path ): never {
		throw new \InvalidArgumentException( sprintf( 'The file %s does not exist, is not a regular file, or cannot be read.', $path ) );
	}

	/**
	 * Refuses a file larger than MAX_FILE_BYTES.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException Always.
	 *
	 * @param string $path The file.
	 * @return never
	 */
	private static function refuseTooLarge( string $path ): never {
		throw new \InvalidArgumentException( sprintf( 'The file %1$s is larger than %2$d bytes: it holds one JSON object of settings.', $path, self::MAX_FILE_BYTES ) );
	}

	/**
	 * Says that a gateway open payments name is not registered, and what it takes to settle them.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $gatewayId The gateway.
	 * @param int         $open      How many of its payments are open.
	 * @param string|null $refusal   Why SEOCart refused its registration in this request, if it did.
	 * @return string One line.
	 */
	private static function goneLine( string $gatewayId, int $open, ?string $refusal ): string {
		$payments = 1 === $open ? 'payment' : 'payments';

		if ( null === $refusal ) {
			return sprintf( 'Not registered: %1$s, with %2$d open %3$s, which cannot be captured, voided, refunded or reconciled until its plugin is active again.', $gatewayId, $open, $payments );
		}

		return sprintf( 'Not registered: %1$s, with %2$d open %3$s, which cannot be captured, voided, refunded or reconciled until SEOCart accepts its registration: it was refused (%4$s), as its Refused line above says.', $gatewayId, $open, $payments, $refusal );
	}

	/**
	 * Says what became of the webhook endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayConfigured $result What configure did.
	 * @return string One line.
	 */
	private static function webhookLine( GatewayConfigured $result ): string {
		if ( GatewayConfigured::SKIPPED === $result->webhooks ) {
			return 'Webhook endpoint: skipped.';
		}

		if ( GatewayConfigured::FAILED === $result->webhooks ) {
			return 'Webhook endpoint: failed. ' . $result->failure;
		}

		return sprintf( 'Webhook endpoint %1$s: %2$s.%3$s', $result->endpointId, $result->webhooks, 'reused' === $result->webhooks ? ' The signing secret saved before still verifies its deliveries.' : ' Its signing secret is saved.' );
	}

	/**
	 * Returns a gateway's status as a row of the table: words and counts only.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayStatus $status The status.
	 * @return array<string, string|int> The row, by column.
	 */
	private static function row( GatewayStatus $status ): array {
		$credentials = array();

		foreach ( $status->credentials as $mode => $state ) {
			$credentials[] = $mode . ': ' . $state->value;
		}

		return array(
			'id'             => $status->id,
			'label'          => $status->label,
			'plugin'         => $status->plugin ?? '-',
			'enabled'        => $status->enabled ? 'yes' : 'no',
			'mode'           => $status->mode->value ?? CredentialState::Unreadable->value,
			'effective_mode' => null === $status->effectiveMode ? CredentialState::Unreadable->value : $status->effectiveMode->value . ( $status->effectiveMode !== $status->mode ? ' (Safe Mode)' : '' ),
			'credentials'    => implode( ', ', $credentials ),
			'currencies'     => implode( ', ', $status->currencies ),
			'hosts'          => implode( ', ', array_map( static fn( OutboundHost $host ): string => $host->host, $status->hosts ) ),
			'open_intents'   => $status->openIntents,
		);
	}

	/**
	 * Prints one line.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line.
	 */
	private function say( string $line ): void {
		( $this->output )( $line );
	}
}

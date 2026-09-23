<?php
/**
 * Notices: the admin notices for degraded mode and Safe Mode, and the actions they offer
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Platform\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the people who can act that the store refuses changes, or that it is in Safe Mode, and lets them act.
 *
 * Owns one fact: what the merchant is told about the two kernel states, and the admin actions
 * that answer it. Each notice goes only to users who can act on it: the degraded-mode notice to
 * users who can activate plugins, the Safe Mode notice to users who can manage options (on a
 * network, super admins). Nothing is shown to shoppers, and nothing loads a script or a style.
 *
 * Each notice can be dismissed per user with a nonce-checked link. The dismissal remembers a
 * fingerprint of the condition it dismissed — for degraded mode the state, the code's and the
 * recorded schema head and the failed migration; for Safe Mode the reason and the recorded
 * address — so the notice comes back when the condition changes, and only then.
 *
 * The Safe Mode notice for a changed address or a rebuilt record asks exactly one question with
 * two answers: this is a copy (Safe Mode stays on and the notice stops asking) or this is the same
 * store at a new address (the address is adopted). The degraded-mode notice offers Retry when a
 * migration has failed. Every action is a nonce-checked link to `admin-post.php` that checks the
 * capability again and returns to the page it came from.
 *
 * @since 0.1.0
 */
final class Notices {

	/**
	 * The `admin-post.php` action, and nonce action, that dismisses a notice.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DISMISS_ACTION = 'seocart_dismiss_notice';

	/**
	 * The `admin-post.php` action, and nonce action, that answers the Safe Mode question.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SAFE_MODE_ACTION = 'seocart_safe_mode';

	/**
	 * The `admin-post.php` action, and nonce action, that tries a failed migration again.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETRY_ACTION = 'seocart_retry_migration';

	/**
	 * The degraded-mode notice's key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DEGRADED = 'degraded';

	/**
	 * The Safe Mode notice's key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SAFE_MODE = 'safe_mode';

	/**
	 * The user option a dismissal is remembered in, before the notice's key. Per site.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DISMISSED_OPTION = 'seocart_dismissed_';

	/**
	 * The capability that sees the degraded-mode notice and may retry a migration.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DEGRADED_CAPABILITY = 'activate_plugins';

	/**
	 * The schema gate.
	 *
	 * @since 0.1.0
	 *
	 * @var SchemaGate
	 */
	private SchemaGate $gate;

	/**
	 * Safe Mode.
	 *
	 * @since 0.1.0
	 *
	 * @var SafeMode
	 */
	private SafeMode $safeMode;

	/**
	 * The boot record.
	 *
	 * @since 0.1.0
	 *
	 * @var BootOption
	 */
	private BootOption $bootOption;

	/**
	 * Returns the migrator.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): Migrator
	 */
	private \Closure $migrator;

	/**
	 * Returns the lifecycle.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): Lifecycle
	 */
	private \Closure $lifecycle;

	/**
	 * Creates the notices. Reads nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaGate           $gate       The schema gate.
	 * @param SafeMode             $safeMode   Safe Mode.
	 * @param BootOption           $bootOption The boot record.
	 * @param callable():Migrator  $migrator   Returns the migrator.
	 * @param callable():Lifecycle $lifecycle  Returns the lifecycle.
	 */
	public function __construct( SchemaGate $gate, SafeMode $safeMode, BootOption $bootOption, callable $migrator, callable $lifecycle ) {
		$this->gate       = $gate;
		$this->safeMode   = $safeMode;
		$this->bootOption = $bootOption;
		$this->migrator   = \Closure::fromCallable( $migrator );
		$this->lifecycle  = \Closure::fromCallable( $lifecycle );
	}

	/**
	 * Prints the notices the current user should see. Runs on `admin_notices` and `network_admin_notices`.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		$this->renderDegraded();
		$this->renderSafeMode();
	}

	/**
	 * Remembers that a user dismissed a notice in its present condition.
	 *
	 * @since 0.1.0
	 *
	 * @param string $notice DEGRADED or SAFE_MODE.
	 * @param int    $userId The user.
	 */
	public function dismiss( string $notice, int $userId ): void {
		$fingerprint = self::DEGRADED === $notice ? $this->degradedFingerprint() : $this->safeModeFingerprint();

		if ( null !== $fingerprint ) {
			update_user_option( $userId, self::DISMISSED_OPTION . $notice, $fingerprint );
		}
	}

	/**
	 * Handles the dismiss link. Runs on `admin_post_seocart_dismiss_notice`.
	 *
	 * @since 0.1.0
	 */
	public function handleDismiss(): void {
		check_admin_referer( self::DISMISS_ACTION );

		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		if ( self::DEGRADED !== $notice && self::SAFE_MODE !== $notice ) {
			self::refuse( 400 );
		}

		if ( ! current_user_can( self::DEGRADED === $notice ? self::DEGRADED_CAPABILITY : self::safeModeCapability() ) ) {
			self::refuse( 403 );
		}

		$this->dismiss( $notice, get_current_user_id() );
		self::returnToReferrer();
	}

	/**
	 * Handles the answer to the Safe Mode question. Runs on `admin_post_seocart_safe_mode`.
	 *
	 * @since 0.1.0
	 */
	public function handleSafeModeChoice(): void {
		check_admin_referer( self::SAFE_MODE_ACTION );

		if ( ! current_user_can( self::safeModeCapability() ) ) {
			self::refuse( 403 );
		}

		$choice = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';

		if ( 'adopt' === $choice ) {
			$this->safeMode->adopt();
		} elseif ( 'copy' === $choice ) {
			$this->safeMode->markCopy();
		} else {
			self::refuse( 400 );
		}

		self::returnToReferrer();
	}

	/**
	 * Handles the Retry link. Runs on `admin_post_seocart_retry_migration`.
	 *
	 * @since 0.1.0
	 */
	public function handleRetry(): void {
		check_admin_referer( self::RETRY_ACTION );

		if ( ! current_user_can( self::DEGRADED_CAPABILITY ) ) {
			self::refuse( 403 );
		}

		( $this->lifecycle )()->retryMigration();
		self::returnToReferrer();
	}

	/**
	 * Prints the degraded-mode notice, when the gate is closed and the user has not dismissed this condition.
	 *
	 * @since 0.1.0
	 */
	private function renderDegraded(): void {
		if ( ! current_user_can( self::DEGRADED_CAPABILITY ) ) {
			return;
		}

		$fingerprint = $this->degradedFingerprint();

		if ( null === $fingerprint || self::isDismissed( self::DEGRADED, $fingerprint ) ) {
			return;
		}

		$state = $this->gate->state();

		if ( GateState::NotInstalled === $state ) {
			$message = esc_html__( 'SEOCart is finishing its installation; reload this page.', 'seocart' );
		} elseif ( GateState::SchemaNewer === $state ) {
			$message = sprintf(
				/* translators: 1: The migration id the database was updated to. 2: The newest migration id this version of SEOCart knows. */
				esc_html__( 'This database was updated by a newer version of SEOCart (schema %1$s; this version knows up to %2$s). Install that version or restore the backup taken with it; downgrades are not supported.', 'seocart' ),
				'<code>' . esc_html( (string) $this->bootOption->read()->schemaHead() ) . '</code>',
				'<code>' . esc_html( ( $this->migrator )()->codeHead() ) . '</code>'
			);
		} else {
			$message = sprintf(
				/* translators: %s: The command that applies the pending migrations. */
				esc_html__( 'SEOCart is updating its database; the store refuses changes until it finishes. Run %s to do it now.', 'seocart' ),
				'<code>wp seocart migrate</code>'
			);

			$failed = ( $this->migrator )()->status()->failed();

			if ( null !== $failed ) {
				$message .= ' ' . sprintf(
					/* translators: %s: The id of the migration that failed. */
					esc_html__( 'The migration %s failed.', 'seocart' ),
					'<code>' . esc_html( $failed ) . '</code>'
				) . ' ' . self::link( self::actionUrl( self::RETRY_ACTION, array() ), __( 'Retry', 'seocart' ) );
			}
		}

		wp_admin_notice(
			$message . ' ' . self::link( self::actionUrl( self::DISMISS_ACTION, array( 'notice' => self::DEGRADED ) ), __( 'Dismiss', 'seocart' ) ),
			array(
				'type' => 'error',
			)
		);
	}

	/**
	 * Prints the Safe Mode notice, when Safe Mode is on and the user has not dismissed this condition.
	 *
	 * @since 0.1.0
	 */
	private function renderSafeMode(): void {
		if ( ! current_user_can( self::safeModeCapability() ) ) {
			return;
		}

		$fingerprint = $this->safeModeFingerprint();

		if ( null === $fingerprint || self::isDismissed( self::SAFE_MODE, $fingerprint ) ) {
			return;
		}

		$status = $this->safeMode->status();
		$ask    = false;

		switch ( $status ) {
			case SafeModeStatus::UrlChanged:
				$message = sprintf(
					/* translators: 1: The site address recorded when SEOCart was installed. 2: The site's current address. */
					esc_html__( 'SEOCart is in Safe Mode: this site\'s address changed from %1$s to %2$s, so it may be a copy of another store. No live payments, mail or background jobs run.', 'seocart' ),
					'<code>' . esc_html( (string) $this->safeMode->recordedUrl() ) . '</code>',
					'<code>' . esc_html( home_url() ) . '</code>'
				);

				$ask = true;
				break;

			case SafeModeStatus::Rebuilt:
				$message = esc_html__( 'SEOCart is in Safe Mode: its installation record was missing and has been rebuilt, so this site may be a copy of another store. No live payments, mail or background jobs run.', 'seocart' );
				$ask     = true;
				break;

			case SafeModeStatus::Canary:
				$message = sprintf(
					/* translators: 1: The name of the encryption key constant. 2: The command that shows the state of the stored credentials. */
					esc_html__( 'SEOCart cannot decrypt its stored credentials: %1$s or the database restore changed. Safe Mode is on; see %2$s.', 'seocart' ),
					'<code>SEOCART_ENCRYPTION_KEY</code>',
					'<code>wp seocart secrets status</code>'
				);
				break;

			case SafeModeStatus::Constant:
				$message = sprintf(
					/* translators: %s: The name of the constant that switches Safe Mode on. */
					esc_html__( 'SEOCart is in Safe Mode because %s is set to true in wp-config.php.', 'seocart' ),
					'<code>' . SafeMode::CONSTANT . '</code>'
				);
				break;

			case SafeModeStatus::Copy:
				$message = esc_html__( 'SEOCart is in Safe Mode because this site was marked as a copy of another store. No live payments, mail or background jobs run.', 'seocart' );
				break;

			default:
				$message = sprintf(
					/* translators: 1: The command that switched Safe Mode on. 2: The command that switches it off. */
					esc_html__( 'SEOCart is in Safe Mode because it was switched on with %1$s. Switch it off with %2$s.', 'seocart' ),
					'<code>wp seocart safe-mode on</code>',
					'<code>wp seocart safe-mode off</code>'
				);
		}

		if ( $ask ) {
			$message .= ' ' . self::link( self::actionUrl( self::SAFE_MODE_ACTION, array( 'choice' => 'copy' ) ), __( 'This is a copy: keep Safe Mode', 'seocart' ) )
				. ' | ' . self::link( self::actionUrl( self::SAFE_MODE_ACTION, array( 'choice' => 'adopt' ) ), __( 'Same store, the address changed: adopt it', 'seocart' ) );
		}

		wp_admin_notice(
			$message . ' ' . self::link( self::actionUrl( self::DISMISS_ACTION, array( 'notice' => self::SAFE_MODE ) ), __( 'Dismiss', 'seocart' ) ),
			array(
				'type' => 'warning',
			)
		);
	}

	/**
	 * Fingerprints the degraded-mode condition.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The fingerprint, or null when the gate is open.
	 */
	private function degradedFingerprint(): ?string {
		if ( ! $this->gate->writesBlocked() ) {
			return null;
		}

		$state    = $this->gate->state();
		$migrator = ( $this->migrator )();
		$failed   = GateState::CodeNewer === $state ? $migrator->status()->failed() : null;

		return implode( '|', array( $state->value, $migrator->codeHead(), (string) $this->bootOption->read()->schemaHead(), (string) $failed ) );
	}

	/**
	 * Fingerprints the Safe Mode condition.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The fingerprint, or null when Safe Mode is off.
	 */
	private function safeModeFingerprint(): ?string {
		$status = $this->safeMode->status();

		return SafeModeStatus::Off === $status ? null : $status->value . '|' . (string) $this->safeMode->recordedUrl();
	}

	/**
	 * Tells whether the current user dismissed a notice in this very condition.
	 *
	 * @since 0.1.0
	 *
	 * @param string $notice      DEGRADED or SAFE_MODE.
	 * @param string $fingerprint The present condition's fingerprint.
	 * @return bool True when the remembered dismissal is of this condition.
	 */
	private static function isDismissed( string $notice, string $fingerprint ): bool {
		return get_user_option( self::DISMISSED_OPTION . $notice ) === $fingerprint;
	}

	/**
	 * Returns the capability that sees the Safe Mode notice and may answer it.
	 *
	 * @since 0.1.0
	 *
	 * @return string `manage_options`, or on a network `manage_network_options`, which only super admins hold.
	 */
	private static function safeModeCapability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Builds the nonce-checked `admin-post.php` address of an action.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $action The action, which is also its nonce action.
	 * @param array<string, string> $args   The action's arguments.
	 * @return string The address, not yet escaped.
	 */
	private static function actionUrl( string $action, array $args ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => $action ) + $args, admin_url( 'admin-post.php' ) ), $action );
	}

	/**
	 * Builds a link.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url  The address, not yet escaped.
	 * @param string $text The text, not yet escaped.
	 * @return string The escaped link.
	 */
	private static function link( string $url, string $text ): string {
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Returns to the page the action was started from.
	 *
	 * @since 0.1.0
	 *
	 * @return never
	 */
	private static function returnToReferrer(): never {
		$referrer = wp_get_referer();

		wp_safe_redirect( false === $referrer ? admin_url() : $referrer );
		exit;
	}

	/**
	 * Ends a request that may not do what it asked.
	 *
	 * @since 0.1.0
	 *
	 * @param int $status The HTTP status: 400 for a malformed request, 403 for a missing capability.
	 * @return never
	 */
	private static function refuse( int $status ): never {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'seocart' ), '', array( 'response' => (int) $status ) );
	}
}

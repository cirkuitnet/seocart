<?php
/**
 * SettingsService: the application service behind the settings operations
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes the exposed settings, for the settings operations.
 *
 * This class owns one fact: what the settings operations do. Reading returns every exposed
 * setting but the secrets, each with its stored value or its default: a secret is written, never
 * read back. Changing writes the exposed settings the input names — an absent one is left as it
 * is — and answers like a read, so the client sees the settings as they are now.
 *
 * The operation's permission check has already required the settings capability, and the input
 * has been validated against the schema compiled from the same fields. A change that names a
 * secret also requires the secrets capability of the actor the surface names; the secret is then
 * sealed before the store sees it, inside the transaction that writes it, so the key it is sealed
 * with cannot be retired before the write commits. Every value is checked, and every secret
 * sealed, before anything is written.
 *
 * @since 0.1.0
 */
final class SettingsService {

	/**
	 * The capability a change needs, besides the settings capability, when it names a secret.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SECRETS_CAPABILITY = 'seocart_manage_secrets';

	/**
	 * The settings.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsRegistry
	 */
	private SettingsRegistry $registry;

	/**
	 * The store.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * The capability check.
	 *
	 * @since 0.1.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

	/**
	 * Seals a secret before it is written.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretSealer
	 */
	private SecretSealer $sealer;

	/**
	 * Runs the sealing and the writing of secrets as one unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Creates the service.
	 *
	 * @since 0.1.0
	 *
	 * @param SettingsRegistry   $registry     The settings.
	 * @param SettingsStore      $store        The store of the current site.
	 * @param Authorizer         $authorizer   The capability check.
	 * @param SecretSealer       $sealer       Seals a secret before it is written.
	 * @param TransactionManager $transactions Runs units of work: the database the store writes to.
	 */
	public function __construct( SettingsRegistry $registry, SettingsStore $store, Authorizer $authorizer, SecretSealer $sealer, TransactionManager $transactions ) {
		$this->registry     = $registry;
		$this->store        = $store;
		$this->authorizer   = $authorizer;
		$this->sealer       = $sealer;
		$this->transactions = $transactions;
	}

	/**
	 * Returns every exposed setting but the secrets.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SettingsError::StoredValueInvalid when an option holds a value its
	 *                        setting cannot hold.
	 *
	 * @param array<string, mixed> $input The prepared input: the operation declares none.
	 * @param Actor                $actor Who reads.
	 * @return array<string, int|string|null> Each exposed setting's value, keyed by name.
	 */
	public function get( array $input, Actor $actor ): array {
		unset( $input, $actor );

		return $this->store->values( array_values( array_filter( $this->registry->exposed(), static fn( Setting $setting ): bool => ! $setting->isSecret() ) ) );
	}

	/**
	 * Changes the exposed settings the input names, then returns every exposed setting but the secrets.
	 *
	 * Every value is checked, and every secret sealed, before any is written, so a change refused
	 * for one value saves none.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With AuthorizationError::Denied when the input names a secret and the
	 *                        actor may not manage secrets; with a setting's own code when its check
	 *                        refuses a value; with a secrets code when a secret cannot be sealed; or
	 *                        with SettingsError::StoredValueInvalid when a setting cannot be read back.
	 *
	 * @param array<string, mixed> $input The prepared input: new values, keyed by setting name.
	 * @param Actor                $actor Who changes the settings.
	 * @return array<string, int|string|null> Each exposed setting's value, keyed by name.
	 */
	public function update( array $input, Actor $actor ): array {
		$changes = array();
		$secrets = array();

		foreach ( $this->registry->exposed() as $setting ) {
			if ( ! array_key_exists( $setting->name(), $input ) ) {
				continue;
			}

			if ( $setting->isSecret() ) {
				$secrets[] = $setting;
			} else {
				$changes[ $setting->name() ] = $input[ $setting->name() ];
			}
		}

		if ( array() === $secrets ) {
			$this->store->writeScalars( $changes );

			return $this->get( array(), $actor );
		}

		$this->authorizer->authorize( $actor, self::SECRETS_CAPABILITY );

		$this->transactions->transaction(
			function () use ( $changes, $secrets, $input ): void {
				foreach ( $secrets as $secret ) {
					$changes[ $secret->name() ] = $this->sealer->seal( $secret, (string) $input[ $secret->name() ] );
				}

				$this->store->writeScalars( $changes );
			}
		);

		return $this->get( array(), $actor );
	}
}

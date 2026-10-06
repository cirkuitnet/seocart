<?php
/**
 * GatewayConfiguration: the one writer of a payment gateway's settings document
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\ProvisionsWebhooks;
use SEOCart\Contracts\Payment\WebhookProvisioning;
use SEOCart\Contracts\Payment\WebhookTarget;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\TransactionManager;

use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsDocument;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Platform\Settings\SettingValues;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions tell an operator why a change was refused; they are never HTML, and they name settings, never values.

/**
 * Changes a gateway's settings: the mode it takes new payments in, and its settings and credentials for a mode, with its provider's webhook endpoint.
 *
 * Owns one fact: how a gateway's settings document is written. Every change is one transaction
 * that reads the document as stored, replaces the values the change names, keeps every other value
 * as it is stored (a credential stays sealed as it was), seals each new credential inside the
 * transaction, and replaces the document by compare-and-swap; a writer that lost a race gets
 * `settings.version_conflict` and changes nothing. The command and the settings screen are its
 * callers.
 *
 * Switching the mode changes only the mode new payments are created in: a payment keeps the mode
 * it was created in, and every later call about it uses that mode's credentials.
 *
 * Saving a mode's settings (configure()) is two steps. The settings are written first; then, for
 * a gateway that sets up its own webhook endpoints (ProvisionsWebhooks), its provider is asked,
 * outside any transaction, to set up this site's endpoint for the mode, and the signing secret it
 * answers with is written in a second write of its own. A provider that fails leaves the settings
 * saved and the webhook step reported failed, never a half-written document.
 *
 * @since 0.2.0
 */
final class GatewayConfiguration {

	/**
	 * The code a webhook step that failed is reported with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PROVISIONING_FAILED = 'payment.webhook_provisioning_failed';

	/**
	 * Returns the site's installation uuid, the owner tag of its webhook endpoints.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): (string|null)
	 */
	private \Closure $installUuid;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(string, array<string, mixed>): void
	 */
	private \Closure $report;

	/**
	 * Creates the writer. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Gateways           $gateways   The registry.
	 * @param SettingsStore      $store      The settings store, which holds each gateway's document.
	 * @param SecretVault        $vault      Seals the credentials.
	 * @param TransactionManager $tx         Runs the writes.
	 * @param Authorizer         $authorizer  Checks the capability each change needs.
	 * @param callable           $installUuid Returns the site's installation uuid (string), or null before it has one.
	 * @param callable           $report      Receives a report code (string) and its context (array).
	 *
	 * @phpstan-param callable(): (string|null)                     $installUuid
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public function __construct(
		private Gateways $gateways,
		private SettingsStore $store,
		private SecretVault $vault,
		private TransactionManager $tx,
		private Authorizer $authorizer,
		callable $installUuid,
		callable $report
	) {
		$this->installUuid = \Closure::fromCallable( $installUuid );
		$this->report      = \Closure::fromCallable( $report );
	}

	/**
	 * Switches the mode a gateway takes new payments in.
	 *
	 * Refused for a gateway with one mode, which has nothing to switch, and for a mode the gateway
	 * is not set up for, which would take it off the checkout: its credentials for that mode must
	 * be saved first.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the gateway has one mode, or does not declare the mode,
	 *                                   or is not set up for it. Nothing is written.
	 * @phpstan-throws \InvalidArgumentException|CodedException `authorization.denied` without
	 *                 `seocart_manage_settings`; `payment.gateway_unavailable` / `not_registered`;
	 *                 `settings.version_conflict` when another writer won.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode new payments are to be created in.
	 * @param Actor  $actor     Who switches it.
	 * @return int The document's new version.
	 */
	public function switchMode( string $gatewayId, Mode $mode, Actor $actor ): int {
		$this->authorizer->authorize( $actor, SettingsOperations::CAPABILITY );

		$descriptor = $this->gateways->descriptor( $gatewayId );
		$setting    = GatewaySettingsDeclaration::modeSetting( $descriptor );

		if ( null === $setting ) {
			throw new \InvalidArgumentException( sprintf( 'The gateway %1$s has one mode, %2$s: there is nothing to switch.', $gatewayId, $descriptor->modes[0]->value ) );
		}

		if ( ! in_array( $mode, $descriptor->modes, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'The gateway %1$s has no %2$s mode.', $gatewayId, $mode->value ) );
		}

		if ( CredentialState::Configured !== $this->gateways->credentials( $gatewayId, $mode ) ) {
			throw new \InvalidArgumentException( sprintf( 'The gateway %1$s is not set up for %2$s mode: save its %2$s credentials with configure first. New payments stay in %3$s mode.', $gatewayId, $mode->value, $this->gateways->mode( $gatewayId )->value ) );
		}

		return $this->write( $gatewayId, array( $setting->name() => $mode->value ) )->version();
	}

	/**
	 * Checks, before anything is read or written, that an actor may save a gateway's settings for a mode, and that the gateway takes them.
	 *
	 * The command checks this before it opens the file the settings are in, so a refused run reads
	 * nothing. When the webhook step is asked for, the gateway must be one that may be asked in the
	 * mode now: a live mode is refused while Safe Mode is on, since setting up the endpoint is a
	 * live call.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the gateway has no settings, or does not declare the mode.
	 * @phpstan-throws \InvalidArgumentException|CodedException `authorization.denied` without
	 *                 `seocart_manage_secrets`; `payment.gateway_unavailable` / `not_registered`, and,
	 *                 when the webhook step is asked for, `safe_mode` for a live mode.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @param bool   $provision Whether the webhook endpoint is to be set up.
	 * @param Actor  $actor     Who saves them.
	 * @return ProvisionsWebhooks|null The gateway, when the webhook step is asked for and it sets up its own endpoints; null otherwise.
	 */
	public function requireConfigurable( string $gatewayId, Mode $mode, bool $provision, Actor $actor ): ?ProvisionsWebhooks {
		$this->authorizer->authorize( $actor, SettingsService::SECRETS_CAPABILITY );

		$descriptor = $this->gateways->descriptor( $gatewayId );

		if ( array() === $descriptor->settings ) {
			throw new \InvalidArgumentException( sprintf( 'The gateway %s has no settings to save.', $gatewayId ) );
		}

		if ( ! in_array( $mode, $descriptor->modes, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'The gateway %1$s has no %2$s mode.', $gatewayId, $mode->value ) );
		}

		return $provision ? $this->gateways->provisioner( $gatewayId, $mode ) : null;
	}

	/**
	 * Returns the settings of a gateway that have no default and that values do not name: what a whole statement of a mode must give, but the webhook signing secret, which is set up with the endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @phpstan-throws CodedException `payment.gateway_unavailable` / `not_registered`.
	 *
	 * @param string               $gatewayId The gateway's id.
	 * @param array<string, mixed> $values    The values given, by the names the gateway declared them with.
	 * @return list<string> The names missing, as declared.
	 */
	public function missing( string $gatewayId, #[\SensitiveParameter] array $values ): array {
		$missing = array();

		foreach ( $this->gateways->descriptor( $gatewayId )->settings as $field ) {
			if ( GatewayDescriptor::WEBHOOK_SECRET !== $field->name() && null === $field->defaultValue() && null === ( $values[ $field->name() ] ?? null ) ) {
				$missing[] = $field->name();
			}
		}

		return $missing;
	}

	/**
	 * Saves a gateway's settings for a mode, then sets up its provider's webhook endpoint when asked, and saves the endpoint's signing secret.
	 *
	 * The values given replace the mode's; a null clears one; a setting not given keeps its stored
	 * value, so a credential left out stays as it was. Every value is checked before anything is
	 * written, and every credential is sealed in the write's transaction. The webhook step runs
	 * outside any transaction, and its secret is written in a second write of its own: a provider
	 * that fails leaves the settings saved, and the result says the step failed and why.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the webhook step is asked for inside a transaction: the provider
	 *                         is called outside any. Nothing is written.
	 * @phpstan-throws \LogicException|\InvalidArgumentException|CodedException As requireConfigurable();
	 *                 \InvalidArgumentException when a name is not a setting the gateway declares, a
	 *                 value does not fit its setting, or the webhook signing secret is given while the
	 *                 webhook step sets it up (the message names the setting, never the value);
	 *                 `settings.version_conflict` when another writer won; a secrets code when a
	 *                 credential cannot be sealed. Nothing is written then.
	 *
	 * @param string               $gatewayId The gateway's id.
	 * @param Mode                 $mode      The mode.
	 * @param array<string, mixed> $values    The plain values, by the names the gateway declared them with.
	 * @param bool                 $provision Whether to set up the webhook endpoint.
	 * @param Actor                $actor     Who saves them.
	 * @return GatewayConfigured What was saved, and what became of the webhook endpoint.
	 */
	public function configure( string $gatewayId, Mode $mode, #[\SensitiveParameter] array $values, bool $provision, Actor $actor ): GatewayConfigured {
		$provisioner = $this->requireConfigurable( $gatewayId, $mode, $provision, $actor );

		if ( null !== $provisioner && 0 !== $this->tx->depth() ) {
			throw new \LogicException( 'A webhook endpoint is set up outside any transaction: the provider is called.' );
		}

		$written = $this->write( $gatewayId, $this->changes( $gatewayId, $mode, $values, null !== $provisioner ) );

		if ( null === $provisioner ) {
			return new GatewayConfigured( $written->version(), GatewayConfigured::SKIPPED );
		}

		return $this->provision( $gatewayId, $mode, $provisioner, $written );
	}

	/**
	 * Replaces values of a gateway's document, keeping every other value as stored, by compare-and-swap in one transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `settings.version_conflict` when another writer won; a secrets code when
	 *                        a credential cannot be sealed. Nothing is written then.
	 *
	 * @param string                         $gatewayId The id of a registered gateway with settings.
	 * @param array<string, int|string|null> $changes   The plain values, by stored name; null removes one.
	 * @return SettingsDocument The document written.
	 */
	private function write( string $gatewayId, #[\SensitiveParameter] array $changes ): SettingsDocument {
		$group    = GatewaySettingsDeclaration::group( $gatewayId );
		$settings = $this->gateways->settingsOf( $gatewayId );

		return $this->tx->transaction(
			function () use ( $group, $settings, $changes ): SettingsDocument {
				$document = $this->store->documentAsStored( $group );
				$values   = $document->values();

				foreach ( $changes as $name => $value ) {
					if ( null === $value ) {
						unset( $values[ $name ] );
					} else {
						$values[ $name ] = $this->stored( $settings[ $name ], $value );
					}
				}

				return $this->store->replaceDocument( $group, $document->version(), $values );
			}
		);
	}

	/**
	 * Checks the values given for a mode against their settings, before anything is written, and returns them by the names they are stored under.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When a name is not a setting the gateway declares, a value does
	 *                                   not fit its setting, or the webhook signing secret is given
	 *                                   while the webhook step sets it up.
	 *
	 * @param string               $gatewayId    The gateway's id.
	 * @param Mode                 $mode         The mode.
	 * @param array<string, mixed> $values       The plain values, by declared name.
	 * @param bool                 $provisioning Whether the webhook step sets the signing secret up.
	 * @return array<string, int|string|null> The values, by stored name; null clears one.
	 */
	private function changes( string $gatewayId, Mode $mode, #[\SensitiveParameter] array $values, bool $provisioning ): array {
		$descriptor = $this->gateways->descriptor( $gatewayId );
		$declared   = array_combine( array_map( static fn( FieldSpec $field ): string => $field->name(), $descriptor->settings ), GatewaySettingsDeclaration::forMode( $descriptor, $mode ) );
		$changes    = array();

		foreach ( $values as $name => $value ) {
			$setting = $declared[ (string) $name ] ?? throw new \InvalidArgumentException( sprintf( 'The gateway %1$s declares no setting %2$s.', $gatewayId, $name ) );

			if ( $provisioning && GatewayDescriptor::WEBHOOK_SECRET === (string) $name ) {
				throw new \InvalidArgumentException( sprintf( 'The setting %s is set up with the webhook endpoint: give it only when the webhook step is skipped.', $name ) );
			}

			$changes[ $setting->name() ] = null === $value ? null : SettingValues::check( $setting, $value );
		}

		$endpoint = GatewaySettingsDeclaration::storedName( $gatewayId, $mode, GatewayDescriptor::WEBHOOK_ENDPOINT );

		// A signing secret entered by hand is no endpoint's this site set up: the id kept beside the
		// secret before is forgotten, so the next set-up replaces that endpoint instead of taking the
		// hand-entered secret for its own. No gateway declares a setting of that name: it is only ever
		// the plugin's id.
		if ( array_key_exists( GatewayDescriptor::WEBHOOK_SECRET, $values ) && isset( $this->gateways->settingsOf( $gatewayId )[ $endpoint ] ) ) {
			$changes[ $endpoint ] = null;
		}

		return $changes;
	}

	/**
	 * Asks the gateway to set up the mode's webhook endpoint, and saves the signing secret it answers with.
	 *
	 * The gateway is told which endpoint's signing secret the site holds: the id kept beside the
	 * secret, when the secret opens. A secret that no longer opens verifies nothing, so its endpoint
	 * is then replaced and a fresh secret saved. A new endpoint's id is saved with its secret, in
	 * the same write, so a later set-up reuses that endpoint only, and an endpoint the gateway
	 * says it reused must be that one.
	 *
	 * Whatever happens here, the settings written before stay written. A failure is reported by
	 * what was thrown's class, never by its message: a provider's or a plugin's message may quote a
	 * credential, one stored before included.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $gatewayId   The gateway's id.
	 * @param Mode               $mode        The mode.
	 * @param ProvisionsWebhooks $provisioner The gateway.
	 * @param SettingsDocument   $written     The document as the settings' write left it.
	 * @return GatewayConfigured What became of the endpoint.
	 */
	private function provision( string $gatewayId, Mode $mode, ProvisionsWebhooks $provisioner, SettingsDocument $written ): GatewayConfigured {
		$installUuid = ( $this->installUuid )();

		if ( null === $installUuid || '' === $installUuid ) {
			return $this->failed( $gatewayId, $mode, $written->version(), 'This site has no installation identity yet: activate SEOCart on it, then run configure again.' );
		}

		$secret   = GatewaySettingsDeclaration::storedName( $gatewayId, $mode, GatewayDescriptor::WEBHOOK_SECRET );
		$endpoint = GatewaySettingsDeclaration::storedName( $gatewayId, $mode, GatewayDescriptor::WEBHOOK_ENDPOINT );
		$held     = $this->heldEndpoint( $secret, $written->values()[ $endpoint ] ?? null );

		try {
			$target = new WebhookTarget( WebhookAddress::url( $gatewayId, $mode ), $mode, $installUuid, $this->gateways->webhookEvents( $gatewayId ), null !== $held, $held );
			$answer = $provisioner->provisionWebhooks( $target );
		} catch ( \Throwable $failure ) {
			return $this->failed( $gatewayId, $mode, $written->version(), sprintf( 'The provider did not set up the endpoint (%s). The settings are saved; run configure again to retry.', self::thrown( $failure ) ) );
		}

		if ( WebhookProvisioning::REUSED === $answer->outcome && $answer->endpointId !== $held ) {
			return $this->failed( $gatewayId, $mode, $written->version(), sprintf( 'The gateway kept the endpoint %s, whose signing secret this site does not hold, so its deliveries could not be verified. Run configure again.', $answer->endpointId ) );
		}

		if ( null === $answer->signingSecret ) {
			return new GatewayConfigured( $written->version(), $answer->outcome, $answer->endpointId, $answer->elsewhere );
		}

		try {
			$version = $this->write(
				$gatewayId,
				array(
					$secret   => $answer->signingSecret,
					$endpoint => $answer->endpointId,
				)
			)->version();
		} catch ( CodedException | \InvalidArgumentException $unsaved ) {
			return $this->failed( $gatewayId, $mode, $written->version(), sprintf( 'The endpoint %1$s was set up, but its signing secret could not be saved (%2$s). Run configure again, which replaces the endpoint.', $answer->endpointId, self::thrown( $unsaved ) ) );
		}

		return new GatewayConfigured( $version, $answer->outcome, $answer->endpointId, $answer->elsewhere );
	}

	/**
	 * Reports a webhook step that failed, and returns its outcome.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @param int    $version   The settings document's version.
	 * @param string $failure   Why, in a sentence that carries no secret.
	 * @return GatewayConfigured The outcome: settings saved, webhook step failed.
	 */
	private function failed( string $gatewayId, Mode $mode, int $version, string $failure ): GatewayConfigured {
		( $this->report )(
			self::PROVISIONING_FAILED,
			array(
				'gateway_id' => $gatewayId,
				'mode'       => $mode->value,
				'failure'    => $failure,
			)
		);

		return new GatewayConfigured( $version, GatewayConfigured::FAILED, null, 0, $failure );
	}

	/**
	 * Returns the id of the endpoint whose signing secret the site holds for a mode: the id kept beside a secret that opens.
	 *
	 * The secret is opened, not judged by its header: a sealed value altered after its header
	 * still names a key the site holds, yet verifies no delivery, and its endpoint must be replaced
	 * with a fresh secret. The opened value is dropped at once, never kept, printed or logged. It is
	 * opened only once the Safe Mode refusal has passed, outside any transaction, as configure()
	 * runs: an operator's command.
	 *
	 * A secret entered by hand has no id beside it: it is no endpoint's this site set up.
	 *
	 * @since 0.2.0
	 *
	 * @param string          $secret   The name the mode's signing secret is stored under.
	 * @param int|string|null $endpoint The endpoint id kept beside it, as stored.
	 * @return string|null The id; null when the site holds no endpoint's signing secret that opens.
	 */
	private function heldEndpoint( string $secret, int|string|null $endpoint ): ?string {
		if ( ! is_string( $endpoint ) || '' === $endpoint ) {
			return null;
		}

		try {
			return null === $this->vault->reveal( $secret ) ? null : $endpoint;
		} catch ( CodedException ) {
			return null;
		}
	}

	/**
	 * Names what was thrown by its class, with its code when it carries one, and never by its message.
	 *
	 * @since 0.2.0
	 *
	 * @param \Throwable $thrown What was thrown.
	 * @return string Its class, such as `SEOCart\Contracts\Payment\GatewayUnavailable`, then its code after a comma, such as `settings.version_conflict`.
	 */
	private static function thrown( \Throwable $thrown ): string {
		return $thrown instanceof CodedException ? get_class( $thrown ) . ', ' . (string) $thrown->errorCode()->value : get_class( $thrown );
	}

	/**
	 * Returns what a value is stored as: a credential sealed, any other value as it is.
	 *
	 * @since 0.2.0
	 *
	 * @param Setting    $setting The setting.
	 * @param int|string $value   The plain value.
	 * @return int|string The value to store.
	 */
	private function stored( Setting $setting, #[\SensitiveParameter] int|string $value ): int|string {
		return $setting->isSecret() ? $this->vault->seal( $setting, (string) $value ) : $value;
	}
}

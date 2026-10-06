<?php
/**
 * Gateways: the payment gateways the store has, registered on first use
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CredentialUnavailable;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\ProvisionsWebhooks;

use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Support\Schema\FieldSpec;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a programming error to the developer; they are never HTML. Coded errors go through CodedException.

/**
 * The registry of the store's payment gateways, and the one place a gateway is found.
 *
 * Owns one fact: which gateways the store has, and which of them may be used now. The list is built
 * the first time something asks for a gateway, once per request, and never on a request that asks
 * for none: the stand-in gateway is registered first, where the site allows it (stubAllowed()),
 * then the action GatewayRegistry::ACTION is fired with this registry, so each gateway plugin
 * registers its own. A registration is checked (register()) and a refused one is logged, never
 * fatal, and kept for the request with its reason (refused()); a gateway's settings fields join
 * the log's redaction as it is accepted. Every registration, accepted or refused, is matched to
 * the plugin it was loaded from (pluginOf()), so a screen can name the plugin of a gateway, and of
 * a refusal.
 *
 * - get() resolves the gateway of an intent: registered, still declaring the intent's mode, not
 *   asked for a live call while Safe Mode is on, and its credentials for the mode open; or
 *   `payment.gateway_unavailable` before anything is written or sent. accountCountry() refuses
 *   the same way, so a call refused for its mode is refused for that before its capability
 *   matrix is consulted.
 * - configuredMode() and availableMode() say whether a gateway can take a new payment, and in
 *   which mode: registered, configured for its effective mode (effectiveMode(): test while Safe
 *   Mode is on), and, with the payment's amount, allowed by its capability matrix for the
 *   account's country and by the gateway itself.
 * - settings() gives the settings registry the gateways' settings documents, which join it only
 *   when something reads or counts a gateway's settings.
 *
 * A gateway with one mode and no settings, such as the stand-in, has no settings document, so
 * none of this reads anything for it.
 *
 * @since 0.2.0
 */
final class Gateways implements GatewayRegistry {

	/**
	 * The code a refused registration is reported with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REJECTED = 'payment.gateway_rejected';

	/**
	 * The code a gateway written against a contract the plugin does not support is reported with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INCOMPATIBLE = 'payment.gateway_incompatible';

	/**
	 * The stand-in gateway, registered first where the site allows it; null where it does not.
	 *
	 * @since 0.2.0
	 *
	 * @var PaymentGateway|null
	 */
	private ?PaymentGateway $stub;

	/**
	 * Mints the context of a gateway id.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(string): GatewayContext
	 */
	private \Closure $contexts;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(string, array<string, mixed>): void
	 */
	private \Closure $report;

	/**
	 * Hands an accepted gateway's settings fields to the log's redaction.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(FieldSpec ...): void
	 */
	private \Closure $redact;

	/**
	 * The operators' kill switches and Safe Mode.
	 *
	 * @since 0.2.0
	 *
	 * @var GatewaySwitches
	 */
	private GatewaySwitches $switches;

	/**
	 * The registered gateways, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, PaymentGateway>
	 */
	private array $gateways = array();

	/**
	 * The registered gateways' descriptors, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, GatewayDescriptor>
	 */
	private array $descriptors = array();

	/**
	 * The registered gateways' settings, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<Setting>>
	 */
	private array $settings = array();

	/**
	 * The slug of the plugin each registered gateway was loaded from, by id; null for one loaded from no plugin.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string|null>
	 */
	private array $plugins = array();

	/**
	 * The event types each registered gateway that sets up its own webhook endpoints subscribes to, by id; empty for one that sets up none.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<string>>
	 */
	private array $events = array();

	/**
	 * The registrations refused in this request, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<RefusedRegistration>
	 */
	private array $refused = array();

	/**
	 * The contexts minted so far, by gateway id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, GatewayContext>
	 */
	private array $minted = array();

	/**
	 * Whether the list was built.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $built = false;

	/**
	 * Whether the list is being built, while registrations are accepted.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $building = false;

	/**
	 * Creates the registry. Builds nothing and fires nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway|null $stub     The stand-in gateway, or null where the site does not allow it.
	 * @param callable            $contexts Mints the context of a gateway id (string): a GatewayContext.
	 * @param callable            $report   Receives a report code (string) and its context (array).
	 * @param callable            $redact   Receives an accepted gateway's settings fields (FieldSpec ...), for the log's redaction.
	 * @param GatewaySwitches     $switches The operators' kill switches and Safe Mode.
	 *
	 * @phpstan-param callable(string): GatewayContext              $contexts
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 * @phpstan-param callable(FieldSpec ...): void                $redact
	 */
	public function __construct( ?PaymentGateway $stub, callable $contexts, callable $report, callable $redact, GatewaySwitches $switches ) {
		$this->stub     = $stub;
		$this->contexts = \Closure::fromCallable( $contexts );
		$this->report   = \Closure::fromCallable( $report );
		$this->redact   = \Closure::fromCallable( $redact );
		$this->switches = $switches;
	}

	/**
	 * Tells whether the stand-in gateway may be registered: not on a production site, unless the site says so.
	 *
	 * The stand-in approves every payment, so a live store must never offer it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $environmentType The site's environment type, as wp_get_environment_type() says it.
	 * @param mixed  $declared        SEOCART_STUB_GATEWAY's value, or null when it is not defined. Only `true` allows it.
	 * @return bool True when it may.
	 */
	public static function stubAllowed( string $environmentType, mixed $declared ): bool {
		return 'production' !== $environmentType || true === $declared;
	}

	/**
	 * Tells whether this plugin supports a gateway written against a version of the payment contract.
	 *
	 * @since 0.2.0
	 *
	 * @param string $writtenAgainst The version, `major.minor` or `major.minor.patch`.
	 * @return bool True when the versions agree: the same major and minor before 1.0; from 1.0, the same major and a minor no later than the plugin's.
	 */
	public function supportsContract( string $writtenAgainst ): bool {
		$theirs = self::version( $writtenAgainst );
		$ours   = self::version( PaymentGateway::CONTRACT_VERSION );

		if ( null === $theirs || null === $ours || $theirs[0] !== $ours[0] ) {
			return false;
		}

		return 0 === $ours[0] ? $theirs[1] === $ours[1] : $theirs[1] <= $ours[1];
	}

	/**
	 * Returns the context a gateway is built with, one per id.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is not a gateway id.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return ExtensionContext The context.
	 */
	public function context( string $gatewayId ): ExtensionContext {
		return $this->contextOf( $gatewayId );
	}

	/**
	 * Registers a gateway while the list is being built; anything else is refused with a log line.
	 *
	 * Refused: a registration after the list was built; a descriptor that cannot be built, or whose
	 * settings cannot be kept as settings; a contract version the plugin does not support; an id
	 * registered already; settings stored under a name the plugin's own settings, or a gateway
	 * registered before, already hold (heldName()), which one settings registry could not hold.
	 * Each refusal is kept for the request (refused()), with the plugin the gateway's class was
	 * loaded from.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway The gateway.
	 */
	public function register( PaymentGateway $gateway ): void {
		$plugin = self::pluginOfFile( (string) ( new \ReflectionClass( $gateway ) )->getFileName() );

		if ( ! $this->building ) {
			$this->refuse( get_class( $gateway ), $plugin, 'late', 'A gateway registers while the registration action runs.' );

			return;
		}

		try {
			$descriptor = $gateway->describe();
			$settings   = GatewaySettingsDeclaration::of( $descriptor, $gateway instanceof ProvisionsWebhooks );
		} catch ( \Throwable $invalid ) {
			$this->refuse( get_class( $gateway ), $plugin, 'invalid_descriptor', sprintf( 'Its descriptor could not be built: describe(), or the settings it declares, threw %s.', get_class( $invalid ) ) );

			return;
		}

		if ( ! $this->supportsContract( $descriptor->contract ) ) {
			( $this->report )(
				self::INCOMPATIBLE,
				array(
					'gateway_id'      => $descriptor->id,
					'written_against' => $descriptor->contract,
					'contract'        => PaymentGateway::CONTRACT_VERSION,
				)
			);
			$this->refused[] = new RefusedRegistration( $descriptor->id, $plugin, 'incompatible', sprintf( 'It was written against the payment contract %1$s, and this version of SEOCart implements %2$s.', $descriptor->contract, PaymentGateway::CONTRACT_VERSION ) );

			return;
		}

		if ( isset( $this->gateways[ $descriptor->id ] ) ) {
			$this->refuse( $descriptor->id, $plugin, 'duplicate', 'A gateway of this id is registered already.' );

			return;
		}

		$held = $this->heldName( $settings );

		if ( null !== $held ) {
			$this->refuse( $descriptor->id, $plugin, 'settings_clash', sprintf( 'Its settings would be stored as %s, which is held already.', $held ) );

			return;
		}

		$events = self::webhookEventsOf( $gateway, $descriptor );

		if ( null === $events ) {
			$this->refuse( $descriptor->id, $plugin, 'webhooks_undeclared', sprintf( 'It sets up its own webhook endpoints, so it declares the credential %s and at least one event type.', GatewayDescriptor::WEBHOOK_SECRET ) );

			return;
		}

		$this->events[ $descriptor->id ]      = $events;
		$this->plugins[ $descriptor->id ]     = $plugin;
		$this->gateways[ $descriptor->id ]    = $gateway;
		$this->descriptors[ $descriptor->id ] = $descriptor;
		$this->settings[ $descriptor->id ]    = $settings;

		if ( array() !== $settings ) {
			( $this->redact )( ...$descriptor->settings, ...array_map( static fn( Setting $setting ): FieldSpec => $setting->field(), $settings ) );
		}
	}

	/**
	 * Returns the gateway an intent is paid through, with the intent's mode and its credentials for it checked.
	 *
	 * The gateway must still declare the mode the intent was created in: a gateway that dropped a
	 * mode while intents of it remain is refused for them, whether or not it has settings. A live
	 * intent is refused while Safe Mode is on, so the site makes no live call. Every secret the
	 * gateway declares is then opened for the mode, so a gateway whose credentials cannot be used
	 * is refused before a request is built, a claim is taken or anything is written. A gateway
	 * with no secrets reads nothing.
	 *
	 * An operator's kill switch is not consulted: it stops new payments, and the payments a
	 * gateway already holds are still captured, voided, refunded and asked about through it.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable`, with the gateway's id and the reason:
	 *                        `not_registered`, `{mode}_mode_not_declared` (such as
	 *                        `live_mode_not_declared`), `safe_mode`, `credentials_missing` or
	 *                        `credentials_unreadable`, in that order.
	 *
	 * @param string $gatewayId The gateway's id, as the intent records it.
	 * @param Mode   $mode      The intent's mode.
	 * @return PaymentGateway The gateway.
	 */
	public function get( string $gatewayId, Mode $mode ): PaymentGateway {
		$gateway = $this->registered( $gatewayId );

		$this->requireMode( $gatewayId, $mode );

		if ( ! $this->hasDocument( $gatewayId ) ) {
			return $gateway;
		}

		try {
			$this->contextOf( $gatewayId )->modeSettings( $mode )->openAll();
		} catch ( CredentialUnavailable $unavailable ) {
			throw CodedException::because( PaymentError::GatewayUnavailable, self::reason( $gatewayId, 'credentials_' . $unavailable->reason ), $unavailable );
		}

		return $gateway;
	}

	/**
	 * Returns the ids of the registered gateways, in the order they registered.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The ids.
	 */
	public function ids(): array {
		$this->build();

		return array_keys( $this->gateways );
	}

	/**
	 * Returns the registrations refused in this request, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<RefusedRegistration> The refusals.
	 */
	public function refused(): array {
		$this->build();

		return $this->refused;
	}

	/**
	 * Returns the gateway that sets up its own webhook endpoints, for a mode it may be asked in now.
	 *
	 * Setting up an endpoint is a call to the provider: a live one is refused while Safe Mode is on.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`,
	 *                        `{mode}_mode_not_declared` or `safe_mode`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @return ProvisionsWebhooks|null The gateway; null when it sets up no endpoint itself.
	 */
	public function provisioner( string $gatewayId, Mode $mode ): ?ProvisionsWebhooks {
		$gateway = $this->registered( $gatewayId );

		$this->requireMode( $gatewayId, $mode );

		return $gateway instanceof ProvisionsWebhooks ? $gateway : null;
	}

	/**
	 * Returns the event types a registered gateway's webhook endpoints subscribe to, as it declared them when it registered.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return list<string> The event types; empty for a gateway that sets up no endpoint itself.
	 */
	public function webhookEvents( string $gatewayId ): array {
		$this->registered( $gatewayId );

		return $this->events[ $gatewayId ];
	}

	/**
	 * Returns the slug of the plugin a registered gateway's class was loaded from.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return string|null The slug; null when the class comes from no plugin.
	 */
	public function pluginOf( string $gatewayId ): ?string {
		$this->registered( $gatewayId );

		return $this->plugins[ $gatewayId ];
	}

	/**
	 * Returns a registered gateway's descriptor.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return GatewayDescriptor The descriptor it registered with.
	 */
	public function descriptor( string $gatewayId ): GatewayDescriptor {
		$this->registered( $gatewayId );

		return $this->descriptors[ $gatewayId ];
	}

	/**
	 * Returns the settings a registered gateway is kept as (GatewaySettingsDeclaration::of()), by the name each is stored under.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return array<string, Setting> The settings; none for a gateway with one mode and no settings.
	 */
	public function settingsOf( string $gatewayId ): array {
		$this->registered( $gatewayId );

		$settings = array();

		foreach ( $this->settings[ $gatewayId ] as $setting ) {
			$settings[ $setting->name() ] = $setting;
		}

		return $settings;
	}

	/**
	 * Returns the mode a gateway would take a new payment in: when it is registered and configured for its effective mode.
	 *
	 * What the checkout session write checks: what does not depend on the payment's amount. A
	 * gateway an operator switched off takes no new payment, and is refused before its settings
	 * document is read; otherwise the document is read, when the gateway has one.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id, as the shopper chose it.
	 * @return Mode|null The mode; null when the gateway is not registered, is switched off, or does
	 *                   not declare its effective mode, or is not configured for it.
	 */
	public function configuredMode( string $gatewayId ): ?Mode {
		$this->build();

		if ( ! isset( $this->gateways[ $gatewayId ] ) || ! $this->switches->isEnabled( $gatewayId ) ) {
			return null;
		}

		$mode = $this->effectiveMode( $gatewayId );

		if ( ! in_array( $mode, $this->descriptors[ $gatewayId ]->modes, true ) ) {
			return null;
		}

		if ( ! $this->hasDocument( $gatewayId ) ) {
			return $mode;
		}

		return $this->contextOf( $gatewayId )->modeSettings( $mode )->isConfigured() ? $mode : null;
	}

	/**
	 * Tells whether a gateway may take new payments: no operator switched it off.
	 *
	 * Reads the boot record, which the site has loaded already. Registered or not: a payment
	 * method refused for its switch says so.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return bool False while its kill switch is on.
	 */
	public function isEnabled( string $gatewayId ): bool {
		return $this->switches->isEnabled( $gatewayId );
	}

	/**
	 * Returns the mode a gateway takes new payments in now: test while Safe Mode is on, otherwise the mode its settings name, or its only mode.
	 *
	 * A copied or rebuilt site, or one an operator put in Safe Mode, takes no live payment: a
	 * gateway set to live takes new payments in its test mode then, or none, when it has none or
	 * it is not configured for it. Payments created live keep their mode, and are refused until
	 * Safe Mode ends (get()). A gateway with one mode and no settings reads nothing.
	 *
	 * Safe Mode is read from the boot record, which a request reads once, on first use: turned on
	 * by another request, it takes effect for the requests that read the record after that write,
	 * and a placement already past its read completes in the mode it read.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return Mode The mode.
	 */
	public function effectiveMode( string $gatewayId ): Mode {
		$mode = $this->mode( $gatewayId );

		return Mode::Live === $mode && $this->switches->safeMode() ? Mode::Test : $mode;
	}

	/**
	 * Returns the mode a gateway is set to take new payments in: the mode its settings name, or its only mode.
	 *
	 * What an operator chose; effectiveMode() is what new payments are created in now.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return Mode The mode.
	 */
	public function mode( string $gatewayId ): Mode {
		$this->registered( $gatewayId );

		return $this->hasDocument( $gatewayId ) ? $this->contextOf( $gatewayId )->mode() : $this->descriptors[ $gatewayId ]->modes[0];
	}

	/**
	 * Judges a registered gateway's settings for a mode from its stored document, opening no credential.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @return CredentialState The state; configured for a gateway with no settings document, in a mode it declares.
	 */
	public function credentials( string $gatewayId, Mode $mode ): CredentialState {
		$this->registered( $gatewayId );

		if ( ! $this->hasDocument( $gatewayId ) ) {
			return in_array( $mode, $this->descriptors[ $gatewayId ]->modes, true ) ? CredentialState::Configured : CredentialState::Missing;
		}

		return $this->contextOf( $gatewayId )->modeSettings( $mode )->credentials();
	}

	/**
	 * Tells why the gateway of a payment in a mode cannot be asked about it now, as get() would refuse it, without opening a credential.
	 *
	 * For the reports of doctor and Site Health, which must decrypt nothing. A credential altered
	 * after its header is not seen here (CredentialState::Configured); get() refuses it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The payment's mode.
	 * @return string|null The reason get() would give: `not_registered`, `{mode}_mode_not_declared`,
	 *                     `safe_mode`, `credentials_missing` or `credentials_unreadable`; null when
	 *                     the gateway can be asked.
	 */
	public function unavailableFor( string $gatewayId, Mode $mode ): ?string {
		$this->build();

		if ( ! isset( $this->gateways[ $gatewayId ] ) ) {
			return 'not_registered';
		}

		$refusal = $this->modeRefusal( $gatewayId, $mode );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$state = $this->credentials( $gatewayId, $mode );

		// The reason get() gives a credential that cannot be used: CredentialUnavailable's reason, prefixed.
		return CredentialState::Configured === $state ? null : 'credentials_' . $state->value;
	}

	/**
	 * Returns the mode a gateway takes a new payment of an amount in, when it can take it.
	 *
	 * The gateway must be registered and configured for its effective mode, its capability matrix
	 * must allow the amount's currency for the account's country, and the gateway itself must
	 * agree (PaymentGateway::isAvailable()), which may only narrow what the matrix allows.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $gatewayId      The gateway's id, as the shopper chose it.
	 * @param Money       $amount         The amount to authorize, in the order's currency.
	 * @param string|null $billingCountry The billing address's country, or null.
	 * @param string      $channel        Where the order comes from, for example `storefront`.
	 * @return Mode|null The mode; null when the gateway cannot take the payment.
	 */
	public function availableMode( string $gatewayId, Money $amount, ?string $billingCountry, string $channel ): ?Mode {
		$mode = $this->configuredMode( $gatewayId );

		if ( null === $mode ) {
			return null;
		}

		$account = $this->hasDocument( $gatewayId ) ? $this->contextOf( $gatewayId )->modeSettings( $mode )->account() : array();
		$context = new AvailabilityContext( $amount->currency(), $amount, $billingCountry, $channel, $mode, $account );

		return $this->descriptors[ $gatewayId ]->matrix->available( $context ) && $this->gateways[ $gatewayId ]->isAvailable( $context ) ? $mode : null;
	}

	/**
	 * Returns the country of a gateway's provider account for a mode, which selects its capability matrix's row.
	 *
	 * Read from the gateway's `account_country` setting, only when the gateway declares one. The
	 * mode is checked as get() checks it, first: a call about a payment in a mode the gateway no
	 * longer declares, or a live payment while Safe Mode is on, is refused for that, before the
	 * matrix could refuse it for a row the mode's account no longer selects.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`,
	 *                        `{mode}_mode_not_declared` or `safe_mode`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @return string|null The country; null when the gateway declares none, or none is set.
	 */
	public function accountCountry( string $gatewayId, Mode $mode ): ?string {
		$this->registered( $gatewayId );

		$this->requireMode( $gatewayId, $mode );

		if ( ! $this->hasDocument( $gatewayId ) ) {
			return null;
		}

		$country = $this->contextOf( $gatewayId )->modeSettings( $mode )->account()[ GatewayDescriptor::ACCOUNT_COUNTRY ] ?? null;

		return is_string( $country ) && '' !== $country ? $country : null;
	}

	/**
	 * Returns the settings of every registered gateway, for the settings registry: one document per gateway that has settings.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException While the gateways are being registered: a gateway reads its settings only once it is registered.
	 *
	 * @return array{settings: list<Setting>, documents: array<string, string>} The settings, and each document's purpose by group.
	 */
	public function settings(): array {
		if ( $this->building ) {
			throw new \LogicException( 'A gateway reads its settings once it is registered, never while the gateways register.' );
		}

		$this->build();

		$settings  = array();
		$documents = array();

		foreach ( $this->settings as $gatewayId => $declared ) {
			if ( array() === $declared ) {
				continue;
			}

			array_push( $settings, ...$declared );
			$documents[ GatewaySettingsDeclaration::group( $gatewayId ) ] = GatewaySettingsDeclaration::purpose( $gatewayId );
		}

		return array(
			'settings'  => $settings,
			'documents' => $documents,
		);
	}

	/**
	 * Builds the list, once: the stand-in first, where the site allows it, then whatever the registration action registers.
	 *
	 * A gateway plugin whose listener throws is reported, and the gateways registered so far stay.
	 *
	 * @since 0.2.0
	 */
	private function build(): void {
		if ( $this->built ) {
			return;
		}

		$this->built    = true;
		$this->building = true;

		try {
			if ( null !== $this->stub ) {
				$this->register( $this->stub );
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Always seocart_register_payment_gateways, the contract's GatewayRegistry::ACTION.
			do_action( GatewayRegistry::ACTION, $this );
		} catch ( \Throwable $failed ) {
			$this->refuse( get_class( $failed ), self::pluginOfFile( $failed->getFile() ), 'registration_failed', sprintf( 'A listener of the registration action threw %s.', get_class( $failed ) ) );
		} finally {
			$this->building = false;
		}
	}

	/**
	 * Returns the first name of a gateway's settings, or of their group, that the plugin's own settings or a gateway accepted before already hold.
	 *
	 * Every gateway's settings join the one settings registry, which refuses two settings of one
	 * name: `acme` declaring `test_key` and `acme_test` declaring `key` are both stored as
	 * `acme_test_test_key`, and a two-mode gateway `tax_rounding` would keep its mode as the
	 * plugin's own `tax_rounding_mode`. The plugin's own settings are read from their declaration.
	 * A gateway's own settings never meet: each is stored under its mode and its declared name, and
	 * no gateway declares the names the plugin keeps beside them, `mode` and `webhook_endpoint`
	 * (GatewayDescriptor).
	 *
	 * @since 0.2.0
	 *
	 * @param Setting[] $settings The settings a gateway would be kept as.
	 * @return string|null The name held already; null when none is.
	 *
	 * @phpstan-param list<Setting> $settings
	 */
	private function heldName( array $settings ): ?string {
		$names  = array();
		$groups = array();

		foreach ( Settings::registry()->all() as $own ) {
			$names[ $own->name() ]   = true;
			$groups[ $own->group() ] = true;
		}

		foreach ( $this->settings as $accepted ) {
			foreach ( $accepted as $setting ) {
				$names[ $setting->name() ] = true;
			}
		}

		foreach ( $settings as $setting ) {
			if ( isset( $names[ $setting->name() ] ) ) {
				return $setting->name();
			}

			if ( isset( $groups[ $setting->group() ] ) ) {
				return $setting->group();
			}
		}

		return null;
	}

	/**
	 * Refuses a call about a payment in a mode a registered gateway cannot be asked in now.
	 *
	 * The gateway must still declare the mode, and a live call is never made while Safe Mode is on.
	 * Reads nothing: the descriptor is the registry's, and Safe Mode is read from the boot record
	 * the site has loaded.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `{mode}_mode_not_declared`
	 *                        (such as `live_mode_not_declared`) or `safe_mode`.
	 *
	 * @param string $gatewayId The id of a registered gateway.
	 * @param Mode   $mode      The payment's mode.
	 */
	private function requireMode( string $gatewayId, Mode $mode ): void {
		$refusal = $this->modeRefusal( $gatewayId, $mode );

		if ( null !== $refusal ) {
			CodedException::raise( PaymentError::GatewayUnavailable, self::reason( $gatewayId, $refusal ) );
		}
	}

	/**
	 * Says why a registered gateway cannot be asked about a payment in a mode now, if it cannot: the mode is not declared, or it is live while Safe Mode is on.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The id of a registered gateway.
	 * @param Mode   $mode      The payment's mode.
	 * @return string|null `{mode}_mode_not_declared` or `safe_mode`; null when it can be asked in the mode.
	 */
	private function modeRefusal( string $gatewayId, Mode $mode ): ?string {
		if ( ! in_array( $mode, $this->descriptors[ $gatewayId ]->modes, true ) ) {
			return $mode->value . '_mode_not_declared';
		}

		return Mode::Live === $mode && $this->switches->safeMode() ? 'safe_mode' : null;
	}

	/**
	 * Returns a registered gateway, or refuses.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return PaymentGateway The gateway.
	 */
	private function registered( string $gatewayId ): PaymentGateway {
		$this->build();

		return $this->gateways[ $gatewayId ] ?? CodedException::raise( PaymentError::GatewayUnavailable, self::reason( $gatewayId, 'not_registered' ) );
	}

	/**
	 * Tells whether a registered gateway has a settings document: a gateway with one mode and no settings has none, and nothing is read for it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The id of a registered gateway.
	 * @return bool True when it has one.
	 */
	private function hasDocument( string $gatewayId ): bool {
		return array() !== $this->settings[ $gatewayId ];
	}

	/**
	 * Returns the context of a gateway id, minting it on first use.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is not a gateway id.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return GatewayContext The context.
	 */
	private function contextOf( string $gatewayId ): GatewayContext {
		if ( strlen( $gatewayId ) > GatewayDescriptor::ID_MAX_LENGTH || 1 !== preg_match( GatewayDescriptor::ID_PATTERN, $gatewayId ) ) {
			throw new \InvalidArgumentException( sprintf( 'A gateway id is lower-case snake_case of at most %d characters.', GatewayDescriptor::ID_MAX_LENGTH ) );
		}

		return $this->minted[ $gatewayId ] ??= ( $this->contexts )( $gatewayId );
	}

	/**
	 * Reports a refused registration, and keeps it for the request.
	 *
	 * The detail is the registry's own sentence, for the log line and the refusal kept alike: an
	 * exception a plugin threw is named by its class, never by its message, which may quote a
	 * credential. What else it names (a contract version, a setting's name) the descriptor checked.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $what   The gateway's id, or its class when it has no id yet.
	 * @param string|null $plugin The slug of the plugin it was loaded from, or null.
	 * @param string      $reason Why, a word.
	 * @param string      $detail What the registry found, in English, quoting no message a plugin wrote.
	 */
	private function refuse( string $what, ?string $plugin, string $reason, string $detail ): void {
		( $this->report )(
			self::REJECTED,
			array(
				'gateway' => $what,
				'reason'  => $reason,
				'detail'  => $detail,
			)
		);

		$this->refused[] = new RefusedRegistration( $what, $plugin, $reason, $detail );
	}

	/**
	 * Returns the event types a gateway that sets up its own webhook endpoints subscribes to, once checked.
	 *
	 * Such a gateway must declare the credential GatewayDescriptor::WEBHOOK_SECRET, which the
	 * plugin stores the signing secret in, and at least one event type.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway    $gateway    The gateway.
	 * @param GatewayDescriptor $descriptor Its descriptor.
	 * @return list<string>|null The event types; empty for a gateway that sets up none; null when it sets them up without declaring what that needs.
	 */
	private static function webhookEventsOf( PaymentGateway $gateway, GatewayDescriptor $descriptor ): ?array {
		if ( ! $gateway instanceof ProvisionsWebhooks ) {
			return array();
		}

		$secrets = array_map( static fn( FieldSpec $field ): string => $field->name(), $descriptor->secrets() );

		try {
			$events = $gateway->webhookEvents();
		} catch ( \Throwable ) {
			return null;
		}

		// Each event type is checked when the target is built: a malformed one fails the webhook step.
		return in_array( GatewayDescriptor::WEBHOOK_SECRET, $secrets, true ) && array() !== $events ? $events : null;
	}

	/**
	 * Returns the slug of the plugin a file was loaded from: its directory under the plugins directory.
	 *
	 * WordPress's plugin_basename() decides, so a plugin directory linked into the plugins
	 * directory from elsewhere is known by the name it has there. A file outside the plugins
	 * directories belongs to no plugin.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The file's path.
	 * @return string|null The slug, such as `seocart-gateway-for-stripe`; null for no plugin.
	 */
	private static function pluginOfFile( string $file ): ?string {
		$path     = wp_normalize_path( $file );
		$relative = plugin_basename( $path );

		if ( '' === $path || trim( $path, '/' ) === $relative ) {
			return null;
		}

		$slug = strstr( $relative, '/', true );

		return false === $slug ? basename( $relative, '.php' ) : $slug;
	}

	/**
	 * Returns the context of `payment.gateway_unavailable`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param string $reason    Why it is unavailable.
	 * @return array{gateway_id: string, reason: string} The context.
	 */
	private static function reason( string $gatewayId, string $reason ): array {
		return array(
			'gateway_id' => $gatewayId,
			'reason'     => $reason,
		);
	}

	/**
	 * Reads a contract version's major and minor numbers.
	 *
	 * @since 0.2.0
	 *
	 * @param string $version `major.minor` or `major.minor.patch`.
	 * @return array{0: int, 1: int}|null The two numbers; null when the version is not one.
	 */
	private static function version( string $version ): ?array {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)(?:\.\d+)?\z/', $version, $parts ) ) {
			return null;
		}

		return array( (int) $parts[1], (int) $parts[2] );
	}
}

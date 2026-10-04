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
 * fatal; a gateway's settings fields join the log's redaction as it is accepted.
 *
 * - get() resolves the gateway of an intent: registered, and its credentials for the intent's mode
 *   open, or `payment.gateway_unavailable` before anything is written or sent.
 * - configuredMode() and availableMode() say whether a gateway can take a new payment, and in
 *   which mode: registered, configured for its effective mode, and, with the payment's amount,
 *   allowed by its capability matrix for the account's country and by the gateway itself.
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
	 *
	 * @phpstan-param callable(string): GatewayContext              $contexts
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 * @phpstan-param callable(FieldSpec ...): void                $redact
	 */
	public function __construct( ?PaymentGateway $stub, callable $contexts, callable $report, callable $redact ) {
		$this->stub     = $stub;
		$this->contexts = \Closure::fromCallable( $contexts );
		$this->report   = \Closure::fromCallable( $report );
		$this->redact   = \Closure::fromCallable( $redact );
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
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway The gateway.
	 */
	public function register( PaymentGateway $gateway ): void {
		if ( ! $this->building ) {
			$this->refuse( get_class( $gateway ), 'late', 'A gateway registers while the registration action runs.' );

			return;
		}

		try {
			$descriptor = $gateway->describe();
			$settings   = GatewaySettingsDeclaration::of( $descriptor );
		} catch ( \Throwable $invalid ) {
			$this->refuse( get_class( $gateway ), 'invalid_descriptor', $invalid->getMessage() );

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

			return;
		}

		if ( isset( $this->gateways[ $descriptor->id ] ) ) {
			$this->refuse( $descriptor->id, 'duplicate', 'A gateway of this id is registered already.' );

			return;
		}

		$held = $this->heldName( $settings );

		if ( null !== $held ) {
			$this->refuse( $descriptor->id, 'settings_clash', sprintf( 'Its settings would be stored as %s, which is held already.', $held ) );

			return;
		}

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
	 * mode while intents of it remain is refused for them, whether or not it has settings. Every
	 * secret the gateway declares is then opened for the mode, so a gateway whose credentials
	 * cannot be used is refused before a request is built, a claim is taken or anything is written.
	 * A gateway with no secrets reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable`, with the gateway's id and the reason:
	 *                        `not_registered`, `{mode}_mode_not_declared` (such as
	 *                        `live_mode_not_declared`), `credentials_missing` or `credentials_unreadable`.
	 *
	 * @param string $gatewayId The gateway's id, as the intent records it.
	 * @param Mode   $mode      The intent's mode.
	 * @return PaymentGateway The gateway.
	 */
	public function get( string $gatewayId, Mode $mode ): PaymentGateway {
		$gateway = $this->registered( $gatewayId );

		if ( ! in_array( $mode, $this->descriptor( $gatewayId )->modes, true ) ) {
			CodedException::raise( PaymentError::GatewayUnavailable, self::reason( $gatewayId, $mode->value . '_mode_not_declared' ) );
		}

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
	 * Returns the mode a gateway would take a new payment in: when it is registered and configured for its effective mode.
	 *
	 * What the checkout session write checks: what does not depend on the payment's amount. Reads
	 * the gateway's settings document, when it has one.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id, as the shopper chose it.
	 * @return Mode|null The mode; null when the gateway is not registered or not configured for it.
	 */
	public function configuredMode( string $gatewayId ): ?Mode {
		$this->build();

		if ( ! isset( $this->gateways[ $gatewayId ] ) ) {
			return null;
		}

		if ( ! $this->hasDocument( $gatewayId ) ) {
			return $this->descriptors[ $gatewayId ]->modes[0];
		}

		$context = $this->contextOf( $gatewayId );
		$mode    = $context->effectiveMode();

		return $context->modeSettings( $mode )->isConfigured() ? $mode : null;
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
	 * Read from the gateway's `account_country` setting, only when the gateway declares one.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @return string|null The country; null when the gateway declares none, or none is set.
	 */
	public function accountCountry( string $gatewayId, Mode $mode ): ?string {
		$this->registered( $gatewayId );

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
			$this->refuse( get_class( $failed ), 'registration_failed', $failed->getMessage() );
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
	 * Reports a refused registration.
	 *
	 * @since 0.2.0
	 *
	 * @param string $what   The gateway's id, or its class when it has no id yet.
	 * @param string $reason Why, a word.
	 * @param string $detail What the registry found, in English.
	 */
	private function refuse( string $what, string $reason, string $detail ): void {
		( $this->report )(
			self::REJECTED,
			array(
				'gateway' => $what,
				'reason'  => $reason,
				'detail'  => $detail,
			)
		);
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

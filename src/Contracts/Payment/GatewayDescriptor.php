<?php
/**
 * GatewayDescriptor: a gateway's one declaration of what it is and what it can do
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Contracts\OutboundHost;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's programming error to its developer; they are never HTML.

/**
 * What a gateway declares about itself: its id and label, the modes it has, its settings, its capability matrix, its idempotency profile and the hosts it calls.
 *
 * Owns one fact: a gateway's declaration, read once, when the gateway is registered. The plugin
 * keeps one settings document per gateway built from the settings declared here, refuses before
 * any call an operation the matrix does not declare, and judges a provider's "not found" by the
 * profile. Building one does no I/O, calls no WordPress function and translates nothing: the
 * label is a closure around a literal translation call, run only when a screen shows it.
 *
 * A setting is a FieldSpec, the same declaration an operation's fields are made of. Its name is
 * the name the gateway reads it by (GatewaySettings), and it is kept for each declared mode; a
 * field of the privacy class Privacy::Secret is a credential, stored sealed and never shown. A
 * setting named `account_country` is the country of the merchant's provider account, which
 * selects the capability matrix's row. No setting is named `mode` or `webhook_endpoint`: the
 * plugin keeps a gateway's mode, and the id of the webhook endpoint it set up for a mode, under
 * those names.
 *
 * The hosts are every external service the gateway calls, each declared once as an OutboundHost.
 * The gateway's HTTP client (ExtensionContext::http()) sends to these and to no other, and the
 * gateway plugin's readme discloses them from the same declarations. A gateway that calls no
 * service, such as the stand-in, declares none.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class GatewayDescriptor {

	/**
	 * The shape of a gateway id, without anchors or delimiters: lower-case snake_case, as a settings group and a route segment can hold it.
	 *
	 * The webhook route reads its gateway segment from it, so the route and the registry agree on
	 * what an id is.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID_SEGMENT = '[a-z][a-z0-9]*(?:_[a-z0-9]+)*';

	/**
	 * The shape of a gateway id: ID_SEGMENT, the whole text.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID_PATTERN = '/^' . self::ID_SEGMENT . '\z/';

	/**
	 * The longest gateway id: what an intent's `gateway_id` and the ledger's `provider` hold.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const ID_MAX_LENGTH = 32;

	/**
	 * The type of every payment gateway, by which an extension is grouped among the store's integrations.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TYPE_PAYMENTS = 'payments';

	/**
	 * The name of the setting that holds the country of the merchant's provider account, which selects the capability matrix's row.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ACCOUNT_COUNTRY = 'account_country';

	/**
	 * The name of the credential that holds a mode's webhook signing secret: a gateway that sets up its own webhook endpoints (ProvisionsWebhooks) declares it, and the plugin stores the secret its provider gives under it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WEBHOOK_SECRET = 'webhook_secret';

	/**
	 * A name no setting may take: the plugin keeps the gateway's mode under it. WEBHOOK_ENDPOINT is the other.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RESERVED_SETTING = 'mode';

	/**
	 * A name no setting may take: the plugin keeps under it, for each mode of a gateway that sets up its own webhook endpoints (ProvisionsWebhooks), the id of the endpoint whose signing secret WEBHOOK_SECRET holds.
	 *
	 * Reserved for every gateway, so the value stored under it is always the plugin's: a signing
	 * secret entered by hand clears it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WEBHOOK_ENDPOINT = 'webhook_endpoint';

	/**
	 * A setting name that would hold card or bank account data, which no setting may hold: one of these words, as a word of the snake_case name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CARD_NAME = '/(?:^|_)(?:card|cvv|cvc|csc|pan|exp|expiry|iban|routing|track)(?:_|$)|(?:^|_)account_(?:number|num|no)(?:_|$)/';

	/**
	 * Records the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is not snake_case of at most ID_MAX_LENGTH characters, the type is
	 *                                   not snake_case, the contract version is not major.minor.patch, the modes are
	 *                                   none or repeat one, or a setting is not a FieldSpec, repeats a name, is named
	 *                                   `mode` or `webhook_endpoint`, has a name that would hold card data, or is an
	 *                                   `account_country` that is not plain text; or a host is not an OutboundHost, or
	 *                                   two hosts share an id.
	 *
	 * @param string             $id          The gateway's id: what an intent and the ledger record, for example `stripe`.
	 * @param \Closure           $label       Returns the gateway's name for people, through a literal translation call.
	 * @param string             $type        What kind of integration it is: TYPE_PAYMENTS.
	 * @param string             $contract    The PaymentGateway::CONTRACT_VERSION the gateway was written against.
	 * @param array              $modes       The modes the gateway has: test and live for a provider, test alone for a stand-in.
	 * @param array              $settings    The settings the gateway reads, for each of its modes.
	 * @param CapabilityMatrix   $matrix      What the gateway can do, by currency and account country.
	 * @param IdempotencyProfile $idempotency How the provider keeps keys, and whether it can be searched.
	 * @param array              $hosts       The external services the gateway calls; none for one that calls none.
	 *
	 * @phpstan-param \Closure(): string  $label
	 * @phpstan-param list<Mode>          $modes
	 * @phpstan-param list<FieldSpec>     $settings
	 * @phpstan-param list<OutboundHost>  $hosts
	 */
	public function __construct(
		public string $id,
		public \Closure $label,
		public string $type,
		public string $contract,
		public array $modes,
		public array $settings,
		public CapabilityMatrix $matrix,
		public IdempotencyProfile $idempotency,
		public array $hosts
	) {
		if ( strlen( $id ) > self::ID_MAX_LENGTH || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new \InvalidArgumentException( sprintf( 'A gateway id is lower-case snake_case of at most %1$d characters, not "%2$s".', self::ID_MAX_LENGTH, $id ) );
		}

		if ( 1 !== preg_match( self::ID_PATTERN, $type ) ) {
			throw new \InvalidArgumentException( sprintf( 'A gateway\'s type is lower-case snake_case, such as %s.', self::TYPE_PAYMENTS ) );
		}

		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+\z/', $contract ) ) {
			throw new \InvalidArgumentException( 'A gateway states the contract version it was written against as major.minor.patch.' );
		}

		self::checkModes( $modes );
		self::checkSettings( $settings );
		self::checkHosts( $hosts );
	}

	/**
	 * Returns the gateway's name for people, translated now.
	 *
	 * @since 0.2.0
	 *
	 * @return string The label.
	 */
	public function label(): string {
		return (string) ( $this->label )();
	}

	/**
	 * Returns the settings that are credentials: those of the privacy class Privacy::Secret.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The secret settings, in declaration order.
	 */
	public function secrets(): array {
		return array_values( array_filter( $this->settings, static fn( FieldSpec $field ): bool => Privacy::Secret === $field->privacy() ) );
	}

	/**
	 * Refuses modes that are none, are not modes, or repeat one.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When they are.
	 *
	 * @param array<mixed> $modes The modes declared.
	 */
	private static function checkModes( array $modes ): void {
		$values = array();

		foreach ( $modes as $mode ) {
			if ( ! $mode instanceof Mode ) {
				throw new \InvalidArgumentException( 'A gateway\'s modes are Mode cases.' );
			}

			$values[] = $mode->value;
		}

		if ( array() === $values || count( array_unique( $values ) ) !== count( $values ) ) {
			throw new \InvalidArgumentException( 'A gateway declares at least one mode, each once.' );
		}
	}

	/**
	 * Refuses hosts that are not declarations of a service, or two that share an id: a request names its host by id.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When one is not an OutboundHost, or an id repeats.
	 *
	 * @param array<mixed> $hosts The hosts declared.
	 */
	private static function checkHosts( array $hosts ): void {
		$ids = array();

		foreach ( $hosts as $host ) {
			if ( ! $host instanceof OutboundHost ) {
				throw new \InvalidArgumentException( 'A gateway\'s hosts are OutboundHost declarations.' );
			}

			if ( isset( $ids[ $host->id ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'A gateway declares each host once: %s.', $host->id ) );
			}

			$ids[ $host->id ] = true;
		}
	}

	/**
	 * Refuses settings a gateway may not declare.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When one is not a FieldSpec, repeats a name, is named `mode` or
	 *                                   `webhook_endpoint`, would hold card data, or is an `account_country` that is
	 *                                   not plain text.
	 *
	 * @param array<mixed> $settings The settings declared.
	 */
	private static function checkSettings( array $settings ): void {
		$names = array();

		foreach ( $settings as $field ) {
			if ( ! $field instanceof FieldSpec ) {
				throw new \InvalidArgumentException( 'A gateway\'s settings are FieldSpecs.' );
			}

			$name = $field->name();

			if ( isset( $names[ $name ] ) || self::RESERVED_SETTING === $name || self::WEBHOOK_ENDPOINT === $name ) {
				throw new \InvalidArgumentException( sprintf( 'A gateway declares each setting once, and none named %1$s or %2$s, which the plugin keeps itself: %3$s.', self::RESERVED_SETTING, self::WEBHOOK_ENDPOINT, $name ) );
			}

			if ( 1 === preg_match( self::CARD_NAME, $name ) ) {
				throw new \InvalidArgumentException( sprintf( 'The setting %s reads as card data, which never reaches the plugin.', $name ) );
			}

			if ( self::ACCOUNT_COUNTRY === $name && ( FieldType::String !== $field->type() || Privacy::Secret === $field->privacy() ) ) {
				throw new \InvalidArgumentException( sprintf( 'The setting %s is plain text: a country code.', self::ACCOUNT_COUNTRY ) );
			}

			$names[ $name ] = true;
		}
	}
}

<?php
/**
 * ConformanceHarness: what the payment gateway conformance suite runs a gateway in, and the body of each rule
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Checkout\Application\ReturnUrls;
use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Checkout\Interfaces\Rest\WebhookRoute;
use SEOCart\Contracts\HttpClient;
use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Cart\Application\CartService;
use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\DeliveredValues;
use SEOCart\Payment\Application\GatewayContext;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\GatewaySettingsDeclaration;
use SEOCart\Payment\Application\GatewaySwitches;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Application\WebhookAddress;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Http\OutboundClient;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\Migrations\CreateSecretKeysMigration;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\Money;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Tests\Support\Checkout\CurrencySwitchTestCase;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\AssertingLogger;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\SeededIdGenerator;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\SecretsHarness;
use SEOCart\Tools\Docs\GatewayMatrixPage;

/**
 * The plugin, built as it runs on a site with the conformance suite's doubles under a gateway, and the body of each rule of the suite.
 *
 * Owns one fact: how each rule is checked. GatewayConformanceCase turns each rule into a test; the
 * plant tests check one rule against a planted gateway (check()). The plugin is built with three
 * things swapped where it hands them to a gateway: the outbound transport under the gateway's HTTP
 * client (a RecordingTransport), the gateway's logger (an AssertingLogger) and its clock (frozen,
 * moved by the webhook rule). The plugin registers the gateway through its own registration
 * listener, so the gateway checked is the one the plugin's payment service resolves. Ids are seeded
 * per scenario, with the recording's nonce when it has one, and amounts are fixed, so a scenario
 * sends the same bytes every run.
 *
 * @since 0.2.0
 */
abstract class ConformanceHarness extends CurrencySwitchTestCase {

	use CreatesUsers;
	use GrantsCapabilities;

	/**
	 * The unit price of the product every scenario sells, in minor units.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	protected const PRICE = 1000;

	/**
	 * The units of it every scenario sells: two, so one can be refunded alone.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	protected const UNITS = 2;

	/**
	 * The currencies of the store's launch markets: the US, the UK and the EU.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	protected const LAUNCH_CURRENCIES = array( 'USD', 'GBP', 'EUR' );

	/**
	 * The currencies a cart can be placed in on the test store.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const SELLABLE = array( 'USD', 'GBP', 'EUR' );

	/**
	 * A currency no launch market uses, for a gateway whose matrix declares every launch currency.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OUTSIDE = 'JPY';

	/**
	 * The account country a `*` row is tested with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ANY_ACCOUNT = 'US';

	/**
	 * Where the gateway's clock stands until a rule moves it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CLOCK = '2026-06-01T12:00:00Z';

	/**
	 * The environment variable naming the settings file a recording reads its credentials from.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTINGS_FILE = 'SEOCART_TAPE_SETTINGS';

	/**
	 * The environment variable naming a recording's nonce, which every scenario's ids are seeded with beside its name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NONCE = 'SEOCART_TAPE_NONCE';

	/**
	 * The shape of a recording's nonce: plain, and shorter than an opaque token, as the tape's settings hold it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NONCE_SHAPE = '/^[A-Za-z0-9][A-Za-z0-9.:_\-]{0,' . ( TapeScrubber::TOKEN_LENGTH - 2 ) . '}$/D';

	/**
	 * The transport under the gateway's HTTP client.
	 *
	 * @since 0.2.0
	 *
	 * @var RecordingTransport
	 */
	protected RecordingTransport $transport;

	/**
	 * The watch on WordPress's HTTP API.
	 *
	 * @since 0.2.0
	 *
	 * @var HttpWatch
	 */
	protected HttpWatch $http;

	/**
	 * The gateway's logger.
	 *
	 * @since 0.2.0
	 *
	 * @var AssertingLogger
	 */
	protected AssertingLogger $logger;

	/**
	 * The gateway's clock.
	 *
	 * @since 0.2.0
	 *
	 * @var FrozenClock
	 */
	protected FrozenClock $gatewayClock;

	/**
	 * The plugin's id port.
	 *
	 * @since 0.2.0
	 *
	 * @var SeededIdGenerator
	 */
	protected SeededIdGenerator $seeded;

	/**
	 * The test site's encryption key.
	 *
	 * @since 0.2.0
	 *
	 * @var EncryptionKey
	 */
	private EncryptionKey $key;

	/**
	 * Takes the run's credentials out of what the gateway sends.
	 *
	 * @since 0.2.0
	 *
	 * @var TapeScrubber
	 */
	protected TapeScrubber $scrubber;

	/**
	 * The credential values of the run, by mode and setting.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $secrets = array();

	/**
	 * The nonce every scenario's ids are seeded with beside its name: the recording's, or the one the replayed tape holds; empty for none.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $nonce = '';

	/**
	 * The person who captures, voids and refunds.
	 *
	 * @since 0.2.0
	 *
	 * @var Actor|null
	 */
	private ?Actor $agent = null;

	/**
	 * Returns the id the gateway's plugin registers it under.
	 *
	 * @since 0.2.0
	 *
	 * @return string The gateway's id.
	 */
	abstract protected function gatewayId(): string;

	/**
	 * Returns the directory of the gateway's tapes.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The directory; null for a gateway that calls no service, whose every request then fails.
	 */
	abstract protected function tapes(): ?string;

	/**
	 * Returns the payment token of each scenario.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string|NotProducible> By Scenario value: the token, or why the provider cannot produce it.
	 */
	protected function tokens(): array {
		return array();
	}

	/**
	 * Signs a webhook delivery as the provider does; a gateway that declares `webhooks` implements it.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The delivery's headers.
	 * @param string                $body    The raw body.
	 * @param string                $secret  The signing secret.
	 * @param \DateTimeImmutable    $at      When it is signed.
	 * @return array<string, string> The headers, with the signature.
	 *
	 * @throws \LogicException Unless the test implements it.
	 */
	protected function signDelivery( array $headers, string $body, #[\SensitiveParameter] string $secret, \DateTimeImmutable $at ): array {
		unset( $headers, $body, $secret, $at );

		return self::signerMissing();
	}

	/**
	 * Refuses to sign: the gateway's test has no signer.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @return never
	 */
	private static function signerMissing(): never {
		throw new \LogicException( 'The gateway declares webhooks: its test implements signDelivery(), signing as the provider does.' );
	}

	/**
	 * Returns fresh webhook deliveries to record, by name; called only while recording.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>> Each delivery (mode, signed_at, headers, body) by name; none keeps the recorded ones.
	 */
	protected function freshDeliveries(): array {
		return array();
	}

	/**
	 * Returns the stand-in value of a credential when nothing is recorded.
	 *
	 * @since 0.2.0
	 *
	 * @param string $field The setting's name.
	 * @param Mode   $mode  The mode.
	 * @return string The value; a gateway whose keys have a shape it checks returns one of that shape.
	 */
	protected function placeholder( string $field, Mode $mode ): string {
		return 'conformance_' . $mode->value . '_' . $field;
	}

	/**
	 * Registers a gateway the test provides; an extension's plugin registers its own, so its test leaves this alone.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The plugin's registry.
	 */
	protected function register( GatewayRegistry $registry ): void {
		unset( $registry );
	}

	/**
	 * Returns the settings a recording uses: the JSON file SEOCART_TAPE_SETTINGS names, of the test mode.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|string> The settings by name.
	 */
	protected function recordingSettings(): array {
		$file = (string) getenv( self::SETTINGS_FILE );

		if ( '' === $file || ! is_readable( $file ) ) {
			self::fail( sprintf( 'Recording needs the gateway\'s test credentials: name a JSON file of them, readable by you alone, in %s.', self::SETTINGS_FILE ) );
		}

		if ( 0 !== ( fileperms( $file ) & 0077 ) ) {
			self::fail( sprintf( 'The settings file %s is readable by others: make it readable by you alone (chmod 600).', $file ) );
		}

		$settings = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $settings ) || Mode::Test->value !== ( $settings['mode'] ?? Mode::Test->value ) ) {
			self::fail( sprintf( 'The settings file %s must be a JSON object of the test mode\'s settings: a recording is made in test mode only.', $file ) );
		}

		unset( $settings['mode'] );

		return $settings;
	}

	/**
	 * Returns the nonce a recording seeds every scenario's ids with beside its name: SEOCART_TAPE_NONCE, or none.
	 *
	 * A provider that keeps each idempotency key for a while answers a key it has seen with its
	 * first answer, so a recording made again within that while, sending the same keys, would get
	 * the first recording's answers back. A fresh nonce draws every seeded id anew, and with them
	 * every idempotency key and store uuid the requests carry. The nonce is written to the tape's
	 * settings, and a replay seeds its ids with it again; without one, the ids are those of the
	 * scenario's name alone.
	 *
	 * @since 0.2.0
	 *
	 * @return string The nonce; empty for none.
	 */
	protected function recordingNonce(): string {
		return (string) getenv( self::NONCE );
	}

	/**
	 * Returns the nonce of this run: the recording's, refused unless it is of the shape the tape's settings can hold, or the one the replayed tape holds.
	 *
	 * @since 0.2.0
	 *
	 * @return string The nonce; empty for none.
	 */
	private function nonceOfRun(): string {
		if ( ! $this->transport->records() ) {
			$taped = null === $this->tapes() ? '' : ( ( new Tape( $this->tapes() ) )->settings()[ Tape::NONCE ] ?? '' );

			return is_string( $taped ) ? $taped : '';
		}

		$nonce = $this->recordingNonce();

		if ( '' !== $nonce && 1 !== preg_match( self::NONCE_SHAPE, $nonce ) ) {
			self::fail( sprintf( 'A recording\'s nonce, in %1$s, is 1 to %2$d letters, digits, `.`, `:`, `_` and `-`, beginning with a letter or a digit: the tape\'s settings hold it.', self::NONCE, TapeScrubber::TOKEN_LENGTH - 1 ) );
		}

		return $nonce;
	}

	/**
	 * Builds the transport under the gateway's HTTP client.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx The transaction manager.
	 * @return RecordingTransport A refusing transport for a gateway without tapes, else the one the environment asks for.
	 */
	protected function transportOver( TransactionManager $tx ): RecordingTransport {
		$tapes = $this->tapes();

		return null === $tapes ? RecordingTransport::refusing( $tx, $this->http ) : RecordingTransport::fromEnvironment( new Tape( $tapes ), $tx, $this->http );
	}

	/**
	 * Builds the plugin with the suite's doubles under the gateway, registers the gateway, and configures it in test mode.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		$this->seeded       = new SeededIdGenerator( static::class );
		$this->key          = SecretsHarness::newEncryptionKey();
		$this->http         = new HttpWatch();
		$this->gatewayClock = FrozenClock::at( self::CLOCK );
		$this->logger       = new AssertingLogger( fn(): array => isset( $this->transport ) ? $this->transport->servedBodies() : array(), $this->publicCodes() );
		$this->scrubber     = new TapeScrubber( array() );

		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );
		( new CreateLogsMigration() )->up( $operations );
		( new CreateSecretKeysMigration() )->up( $operations );
		$this->kernel->get( SecretKeys::class )->initialize();

		$this->transport = $this->transportOver( $this->db );
		$this->nonce     = $this->nonceOfRun();
		$this->http->install();

		add_action(
			GatewayRegistry::ACTION,
			function ( GatewayRegistry $registry ): void {
				$this->register( $registry );
			}
		);

		// Asked once here, so a gateway the plugin refused fails every test with the refusal as its cause.
		$this->descriptor();
		$this->configure( Mode::Test );
	}

	/**
	 * Removes the users and the gateway's settings the test made.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Checks one rule against the gateway; a failure begins with the rule's message.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException For a rule proved elsewhere: 4 and 15 in the unit suite, 18 by the extension kit.
	 *
	 * @param Rule $rule The rule.
	 *
	 * @phpstan-throws \LogicException|\PHPUnit\Framework\AssertionFailedError
	 */
	public function check( Rule $rule ): void {
		match ( $rule ) {
			Rule::LedgerShape               => $this->checkLedgerShape(),
			Rule::MatrixMatchesBehaviour    => $this->checkMatrixMatchesBehaviour(),
			Rule::AvailabilityNeverLooser   => $this->checkAvailabilityNeverLooser(),
			Rule::AmountsEchoed             => $this->checkAmountsEchoed(),
			Rule::KeysAsRuled               => $this->checkKeysAsRuled(),
			Rule::AlreadyDoneAnswersTheSame => $this->checkAlreadyDoneAnswersTheSame(),
			Rule::NotFoundAuthority         => $this->checkNotFoundAuthority(),
			Rule::Expiry                    => $this->checkExpiry(),
			Rule::WebhooksVerifiedFirst     => $this->checkWebhooksVerifiedFirst(),
			Rule::NextActionShape           => $this->checkNextActionShape(),
			Rule::EveryCallThroughTheClient => $this->checkEveryCallThroughTheClient(),
			Rule::NoRawResponseLogged       => $this->checkNoRawResponseLogged(),
			Rule::CredentialsStay           => $this->checkCredentialsStay(),
			Rule::ModeCredentials           => $this->checkModeCredentials(),
			Rule::VoidAnswersTheApproval    => $this->checkVoidAnswersTheApproval(),
			Rule::VoidReportsTheAmount      => $this->checkVoidReportsTheAmount(),
			Rule::NoCardFields, Rule::DescribeIsData, Rule::IdleBudget => throw new \LogicException( sprintf( 'Rule %d is proved outside the integration suite.', $rule->value ) ),
		};
	}

	/**
	 * Rule 1: the ledger an authorization, a capture, a partial and a full refund leave.
	 *
	 * @since 0.2.0
	 */
	protected function checkLedgerShape(): void {
		$this->rule(
			Rule::LedgerShape,
			function ( Rule $rule ): void {
				$cell     = $this->firstCell( $rule );
				$currency = $cell->currency->code();
				$this->begin( 'rule01-money-path-' . self::cellName( $cell ) );

				$placed     = $this->place( $rule, Scenario::Approve, $cell );
				$total      = $this->intentRow( $placed['intent_uuid'] )['amount_minor'];
				$authorized = $this->transport->exchanges();
				$captured   = $this->during( fn() => $this->capture( $placed['intent_uuid'] ) );

				$expected = array(
					array( 'authorize', 'approved', 1, $total, $currency ),
					array( 'capture', 'approved', 1, $total, $currency ),
				);
				$this->assertSame( $expected, $this->ledgerShape( $placed['order_id'] ), $rule->message() . 'after the capture, the payment ledger is not the one an authorization and a capture of the whole amount make.' );
				$this->assertOwnObject( $rule, 'the authorization', $placed, 'authorize', $authorized );
				$this->assertOwnObject( $rule, 'the capture', $placed, 'capture', $captured );

				$refunds = array();
				$objects = array();

				foreach ( $this->refundSteps( $placed, $cell ) as $step => list( $units, $shipping ) ) {
					list( $refunds[], $exchanges ) = $this->refundWatched( $placed, $units, $shipping );
					$objects[]                     = $this->assertOwnObject( $rule, $step, $placed, 'refund', $exchanges );
				}

				$this->assertSame( count( $objects ), count( array_unique( $objects ) ), $rule->message() . 'two refunds named one provider object: each refund is an object of its own.' );

				$this->end();

				foreach ( $refunds as $refund ) {
					$expected[] = array( 'refund', 'approved', 1, $refund->total->minorUnits(), $currency );
				}

				$this->assertSame( $expected, $this->ledgerShape( $placed['order_id'] ), $rule->message() . 'the payment ledger is not the one an authorization, a capture and the refunds of the whole amount make.' );

				$intent = $this->intentRow( $placed['intent_uuid'] );
				$this->assertSame( array( 'refunded', $total, $total, $total ), array( $intent['status'], $intent['authorized_minor'], $intent['captured_minor'], $intent['refunded_minor'] ), $rule->message() . 'the intent does not end refunded, with its whole amount authorized, captured and refunded.' );

				$order = $this->orderRow( $placed['order_id'] );
				$this->assertSame( array( 'refunded', $total, $total ), array( $order['payment_status'], $order['paid_minor'], $order['refunded_minor'] ), $rule->message() . 'the order does not end refunded, with its whole amount paid and refunded.' );
			}
		);
	}

	/**
	 * Rule 2: every declared operation succeeds; every undeclared one is refused before any request.
	 *
	 * @since 0.2.0
	 */
	protected function checkMatrixMatchesBehaviour(): void {
		$this->rule(
			Rule::MatrixMatchesBehaviour,
			function ( Rule $rule ): void {
				foreach ( $this->cells() as $cell ) {
					$this->configure( Mode::Test, self::accountOf( $cell ) );
					$this->begin( 'rule02-cell-' . self::cellName( $cell ) );
					$this->exerciseCell( $rule, $cell );
					$this->end();
				}

				$this->begin( 'rule02-outside-the-matrix', false );
				$this->refusesOutsideTheMatrix( $rule );
				$this->end();
			},
			false
		);
	}

	/**
	 * Rule 3: never offered where the cell lacks `authorize`; isAvailable() pure.
	 *
	 * @since 0.2.0
	 */
	protected function checkAvailabilityNeverLooser(): void {
		$this->rule(
			Rule::AvailabilityNeverLooser,
			function ( Rule $rule ): void {
				global $wpdb;

				$this->begin( 'rule03-availability', false );
				$gateway = $this->gateway();

				foreach ( $this->cells() as $cell ) {
					$context = new AvailabilityContext( $cell->currency, Money::of( self::PRICE * self::UNITS, $cell->currency ), 'US', 'storefront', Mode::Test, $this->accountSettings( $cell ) );
					$sent    = count( $this->transport->exchanges() );
					$seen    = $this->http->seen();
					$queries = $wpdb->num_queries;
					$first   = $gateway->isAvailable( $context );
					$second  = $gateway->isAvailable( $context );

					$this->assertSame( array( 0, 0, 0 ), array( count( $this->transport->exchanges() ) - $sent, $this->http->seen() - $seen, $wpdb->num_queries - $queries ), $rule->message() . sprintf( 'isAvailable() for %s sent requests or ran queries (requests, HTTP calls, queries): it answers from its context alone.', self::cellName( $cell ) ) );
					$this->assertSame( $first, $second, $rule->message() . sprintf( 'isAvailable() for %s answered differently when asked twice.', self::cellName( $cell ) ) );
				}

				$descriptor = $this->descriptor();
				$countries  = array_values( array_unique( array_merge( array( self::ANY_ACCOUNT ), array_values( array_diff( array_map( static fn( MatrixRow $row ): string => $row->accountCountry, $descriptor->matrix->rows ), array( MatrixRow::ANY_COUNTRY ) ) ) ) ) );

				foreach ( array_values( array_unique( array_merge( self::LAUNCH_CURRENCIES, $descriptor->matrix->currencies() ) ) ) as $code ) {
					foreach ( $countries as $country ) {
						$this->configure( Mode::Test, $country );

						$currency = Currency::of( $code );
						$offered  = $this->gateways()->availableMode( $this->gatewayId(), Money::of( self::PRICE * self::UNITS, $currency ), 'US', 'storefront' );

						if ( ! $descriptor->matrix->allows( Operations::AUTHORIZE, $currency, $this->declares( GatewayDescriptor::ACCOUNT_COUNTRY ) ? $country : null ) ) {
							$this->assertNull( $offered, $rule->message() . sprintf( 'the gateway is offered in %1$s for an account in %2$s, where its matrix declares no authorize.', $code, $country ) );
						}
					}
				}

				$this->end();
			},
			false
		);
	}

	/**
	 * Rule 5: every answer reports the amount and currency asked.
	 *
	 * @since 0.2.0
	 */
	protected function checkAmountsEchoed(): void {
		$this->rule(
			Rule::AmountsEchoed,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule );
				$this->begin( 'rule05-amounts-' . self::cellName( $cell ) );

				$expected = array();
				$paid     = $this->place( $rule, Scenario::Approve, $cell );
				$total    = $this->intentRow( $paid['intent_uuid'] )['amount_minor'];
				$this->capture( $paid['intent_uuid'] );
				$expected[ $paid['order_id'] ] = array( array( 'authorize', $total ), array( 'capture', $total ) );
				$this->assertAmountsAsked( $rule, $cell, $paid['order_id'], $expected[ $paid['order_id'] ] );

				foreach ( $this->refundAll( $paid, $cell ) as $refund ) {
					$expected[ $paid['order_id'] ][] = array( 'refund', $refund->total->minorUnits() );
				}

				$voided = $this->place( $rule, Scenario::Approve, $cell );
				$this->void( $voided['intent_uuid'] );
				$expected[ $voided['order_id'] ] = array( array( 'authorize', $total ), array( 'void', $total ) );

				if ( $cell->supports( Operations::PARTIAL_CAPTURE ) ) {
					$partly = $this->place( $rule, Scenario::Approve, $cell );
					$this->capture( $partly['intent_uuid'], $total - 1 );
					$expected[ $partly['order_id'] ] = array( array( 'authorize', $total ), array( 'capture', $total - 1 ) );
				}

				$declined                          = $this->place( $rule, Scenario::Decline, $cell );
				$expected[ $declined['order_id'] ] = array( array( 'authorize', $total ) );

				$this->end();

				foreach ( $expected as $orderId => $rows ) {
					$this->assertAmountsAsked( $rule, $cell, $orderId, $rows );
				}
			}
		);
	}

	/**
	 * Asserts an order's ledger reports the operations asked, each of the amount asked, in the cell's currency.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule                           $rule    The rule.
	 * @param MatrixRow                      $cell    The cell, whose currency every answer reports.
	 * @param int                            $orderId The order.
	 * @param array<int, array{string, int}> $rows    Each operation asked, with its amount, in order.
	 */
	private function assertAmountsAsked( Rule $rule, MatrixRow $cell, int $orderId, array $rows ): void {
		$answered = array_map( static fn( array $row ): array => array( $row[0], $row[3], $row[4] ), $this->ledgerShape( $orderId ) );
		$asked    = array_map( static fn( array $row ): array => array( $row[0], $row[1], $cell->currency->code() ), $rows );

		$this->assertSame( $asked, $answered, $rule->message() . 'an answer reported another amount or currency than it was asked for (operation, amount, currency).' );
	}

	/**
	 * Rule 6: every money request carries the key derived for it, and a repeated one the same.
	 *
	 * @since 0.2.0
	 */
	protected function checkKeysAsRuled(): void {
		$this->rule(
			Rule::KeysAsRuled,
			function ( Rule $rule ): void {
				if ( array() === $this->descriptor()->hosts ) {
					self::markTestSkipped( $rule->message() . 'skipped: the gateway declares no host, so it sends no request with a key to see; the keys are derived in the requests themselves.' );
				}

				$cell = $this->firstCell( $rule );
				$this->begin( 'rule06-keys-' . self::cellName( $cell ) );

				$this->eachMoneyOperation( $rule, $cell, fn( string $what, array $exchanges, string $key ) => $this->assertKeyed( $what, $exchanges, $key ) );

				$again   = $this->place( $rule, Scenario::Approve, $cell );
				$request = $this->captureRequestOf( $again['intent_uuid'] );
				$first   = $this->during( fn() => $this->gateway()->capture( $request ) );
				$second  = $this->during( fn() => $this->gateway()->capture( $request ) );
				$this->assertKeyed( 'a capture', $first, $request->idempotencyKey() );
				$this->assertKeyed( 'the same capture asked again', $second, $request->idempotencyKey() );

				$this->end();
			}
		);
	}

	/**
	 * Rule 7: a capture or void asked again after its answer was lost answers the same.
	 *
	 * @since 0.2.0
	 */
	protected function checkAlreadyDoneAnswersTheSame(): void {
		$this->rule(
			Rule::AlreadyDoneAnswersTheSame,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule );
				$this->begin( 'rule07-asked-again-' . self::cellName( $cell ) );

				$captured = $this->place( $rule, Scenario::Approve, $cell );
				$lost     = $this->gateway()->capture( $this->captureRequestOf( $captured['intent_uuid'] ) );
				$this->capture( $captured['intent_uuid'] );
				$this->assertSame( array( $lost->providerObjectId, $lost->outcome->value ), $this->ledgerRowOf( $captured['order_id'], 'capture' ), $rule->message() . 'a capture asked again after its answer was lost did not answer the same provider object and outcome.' );

				$voided   = $this->place( $rule, Scenario::Approve, $cell );
				$intent   = $this->intentRow( $voided['intent_uuid'] );
				$lostVoid = $this->gateway()->void( new VoidRequest( $voided['intent_uuid'], $intent['provider_intent_id'], Money::of( $intent['amount_minor'], $cell->currency ), Mode::Test, VoidReason::CustomerRequest->value ) );
				$this->void( $voided['intent_uuid'] );
				$this->assertSame( array( $lostVoid->providerObjectId, $lostVoid->outcome->value ), $this->ledgerRowOf( $voided['order_id'], 'void' ), $rule->message() . 'a void asked again after its answer was lost did not answer the same provider object and outcome.' );

				$this->end();
			}
		);
	}

	/**
	 * Rule 8: who may say "not found", and when the plugin believes it.
	 *
	 * @since 0.2.0
	 */
	protected function checkNotFoundAuthority(): void {
		$this->rule(
			Rule::NotFoundAuthority,
			function ( Rule $rule ): void {
				$cell    = $this->firstCell( $rule );
				$profile = $this->descriptor()->idempotency;
				$this->begin( 'rule08-not-found-' . self::cellName( $cell ) );

				$unseen = $this->openIntent( $cell->currency->code() );
				$ref    = $this->ref( $unseen );
				$raw    = $this->gateway()->query( new PaymentQuery( $ref->uuid, null, $ref->amount, Mode::Test ) );

				if ( null !== $raw ) {
					$this->assertTrue( self::saysNotFound( $raw ), $rule->message() . sprintf( 'query() of an intent the provider never saw answered %s: only nothing or a declined authorization `not_found` is true of it.', self::describeResult( $raw ) ) );
					$this->assertTrue( $profile->searchable, $rule->message() . 'query() answered not_found though the gateway\'s profile says its provider cannot be searched by the store\'s uuid.' );
					$this->assertNull( $this->payments()->queryGateway( $ref ), $rule->message() . 'the plugin acted on a not_found before the search delay and the reconciliation\'s threshold had passed.' );

					$this->ageIntent( $unseen, max( PaymentService::STALE_SECONDS, $profile->searchDelaySeconds ) + 1 );
					$late = $this->payments()->queryGateway( $this->ref( $unseen ) );
					$this->assertTrue( null !== $late && self::saysNotFound( $late ), $rule->message() . 'once the search delay had passed, the plugin did not take the not_found the gateway answers.' );
				}

				$placed = $this->place( $rule, Scenario::Approve, $cell );
				$intent = $this->intentRow( $placed['intent_uuid'] );
				$known  = $this->gateway()->query( new PaymentQuery( $placed['intent_uuid'], $intent['provider_intent_id'], Money::of( $intent['amount_minor'], $cell->currency ), Mode::Test ) );
				$this->assertFalse( null !== $known && self::saysNotFound( $known ), $rule->message() . 'query() answered not_found for an intent the provider authorized.' );

				$this->end();

				if ( $cell->supports( Operations::QUERY_REFUND ) ) {
					$this->refundsNeverMade( $rule, $cell, $profile->searchable );
				}

				// A declined authorization is answered declined; one awaiting the customer, nothing or pending.
				$this->begin( 'rule08-query-answers-' . self::cellName( $cell ) );

				$declined = $this->place( $rule, Scenario::Decline, $cell );
				$answer   = $this->queried( $declined['intent_uuid'], $cell );
				$this->assertTrue( null !== $answer && Operation::Authorize === $answer->operation && Outcome::Declined === $answer->outcome && 'expired' !== $answer->errorCode, $rule->message() . sprintf( 'query() of an authorization the provider declined answered %s, not that decline.', null === $answer ? 'nothing' : self::describeResult( $answer ) ) );

				if ( is_string( $this->tokens()[ Scenario::Pending->value ] ?? null ) ) {
					$deciding = $this->place( $rule, Scenario::Pending, $cell );
					$answer   = $this->queried( $deciding['intent_uuid'], $cell );
					$this->assertTrue( null === $answer || ( Operation::Authorize === $answer->operation && Outcome::Pending === $answer->outcome ), $rule->message() . sprintf( 'query() of a payment the provider is still deciding answered %s: that is nothing, or a pending answer.', null === $answer ? 'nothing' : self::describeResult( $answer ) ) );
				}

				$this->end();
			}
		);
	}

	/**
	 * Rule 9: an intent the provider cancelled is answered expired, and the placement ends as a decline does.
	 *
	 * @since 0.2.0
	 */
	protected function checkExpiry(): void {
		$this->rule(
			Rule::Expiry,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule );
				$this->begin( 'rule09-expiry-' . self::cellName( $cell ) );

				$placed = $this->place( $rule, Scenario::Expires, $cell );
				$this->assertContains( $placed['outcome'], array( 'processing', 'requires_action' ), $rule->message() . sprintf( 'the payment of the `expires` scenario answered %s, not a wait.', $placed['outcome'] ) );

				$this->endWait( $placed['intent_uuid'] );
				$answer = $this->payments()->queryGateway( $this->ref( $placed['intent_uuid'] ) );
				$this->assertTrue( null !== $answer && Operation::Authorize === $answer->operation && Outcome::Declined === $answer->outcome && 'expired' === $answer->errorCode, $rule->message() . sprintf( 'query() of an intent the provider cancelled once its wait ended answered %s, not a declined authorization `expired`.', null === $answer ? 'nothing' : self::describeResult( $answer ) ) );

				$this->kernel->get( SettlePlacement::class )->apply( $answer, Actor::system( 'reconciliation', 0 ) );
				$this->end();

				$intent = $this->intentRow( $placed['intent_uuid'] );
				$order  = $this->orderRow( $placed['order_id'] );
				$this->assertSame( array( 'failed', 'failed' ), array( $intent['status'], $order['status'] ), $rule->message() . 'the placement did not end as a decline does: the intent and the order are not failed.' );
			}
		);
	}

	/**
	 * Rule 10: a webhook delivery is verified before it is read.
	 *
	 * @since 0.2.0
	 */
	protected function checkWebhooksVerifiedFirst(): void {
		$this->rule(
			Rule::WebhooksVerifiedFirst,
			function ( Rule $rule ): void {
				$declared = array_filter( $this->descriptor()->matrix->rows, static fn( MatrixRow $row ): bool => $row->supports( Operations::WEBHOOKS ) );

				if ( array() === $declared ) {
					self::markTestSkipped( $rule->message() . 'skipped: the gateway declares no webhooks.' );
				}

				$tapes = $this->tapes();

				if ( null === $tapes ) {
					// A gateway that calls no service, the stand-in, has no provider to record: its test makes the deliveries, signed as it signs them.
					$deliveries = $this->freshDeliveries();
					$this->assertNotSame( array(), $deliveries, $rule->message() . 'the gateway declares webhooks and has no tape, and its test makes no delivery to read (freshDeliveries()).' );
				} else {
					$tape = new Tape( $tapes );

					if ( $this->transport->records() ) {
						foreach ( $this->freshDeliveries() as $name => $delivery ) {
							$this->recordDelivery( $tape, $name, $delivery );
						}
					}

					$deliveries = $tape->deliveries();
					$this->assertNotSame( array(), $deliveries, $rule->message() . sprintf( 'the gateway declares webhooks, and there is no recorded delivery in %s/%s to read.', $tape->directory(), Tape::WEBHOOKS ) );
				}

				$this->configure( Mode::Test, null, true );

				$kinds = array();

				foreach ( $deliveries as $name => $delivery ) {
					$kinds[] = $this->readsDeliveryRightly( $rule, (string) $name, $delivery );
				}

				$this->assertContains( WebhookReadingKind::Result, $kinds, $rule->message() . 'no recorded delivery reads as a payment result: record one event the gateway applies, such as an authorization.' );
				$this->assertContains( WebhookReadingKind::Ignored, $kinds, $rule->message() . 'no recorded delivery reads as ignored: record one event the gateway does not apply, which it answers ignored.' );
			},
			false
		);
	}

	/**
	 * Rule 11: the shape of what a customer is asked to do.
	 *
	 * @since 0.2.0
	 */
	protected function checkNextActionShape(): void {
		$this->rule(
			Rule::NextActionShape,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule, Operations::SCA );
				$this->begin( 'rule11-next-action-' . self::cellName( $cell ) );

				$placed = $this->place( $rule, Scenario::RequiresAction, $cell );
				$action = $placed['next_action'];
				$this->end();

				$this->assertSame( 'requires_action', $placed['outcome'], $rule->message() . sprintf( 'the payment of the `requires_action` scenario answered %s.', $placed['outcome'] ) );
				$this->assertIsArray( $action, $rule->message() . 'a payment that asks the customer to act carries no next action.' );

				if ( 'sdk' === $action['type'] ) {
					$this->assertNotEmpty( $action['client_token'] ?? null, $rule->message() . 'a step of the provider\'s script carries no handle for it.' );
					return;
				}

				$url = (string) ( $action['url'] ?? '' );
				$this->assertTrue( str_starts_with( $url, 'https://' ) || ReturnUrls::for( $placed['intent_uuid'] ) === $url, $rule->message() . sprintf( 'the next action\'s URL %s is neither https nor the return URL the store sent.', $url ) );
				$this->assertFalse( $this->scrubber->holdsCredential( $url ), $rule->message() . 'the next action\'s URL carries a credential of the gateway.' );
				$this->assertFalse( CardNumbers::contains( $url ), $rule->message() . 'the next action\'s URL carries a card number.' );
			}
		);
	}

	/**
	 * Rule 12: every request goes through the gateway's client, outside any transaction.
	 *
	 * @since 0.2.0
	 */
	protected function checkEveryCallThroughTheClient(): void {
		$this->rule(
			Rule::EveryCallThroughTheClient,
			function ( Rule $rule ): void {
				$cell  = $this->firstCell( $rule );
				$calls = array() !== $this->descriptor()->hosts;
				$this->begin( 'rule12-money-path-' . self::cellName( $cell ) );

				// Each operation's own request, found by its key, not a count: another operation's request through the client would hide one sent another way.
				$this->eachMoneyOperation(
					$rule,
					$cell,
					static function ( string $what, array $exchanges, string $key ) use ( $calls ): void {
						$failure = $calls ? RequestRules::sentFailure( $what, $exchanges, $key ) : null;

						if ( null !== $failure ) {
							self::fail( $failure );
						}
					}
				);
				$sent = count( $this->transport->exchanges() );

				$this->end();

				$this->assertSame( array(), $this->http->foreign(), $rule->message() . sprintf( '%d requests reached WordPress\'s HTTP API outside the gateway\'s client.', count( $this->http->foreign() ) ) );
				$this->assertSame( $sent, $this->http->seen(), $rule->message() . 'WordPress\'s HTTP API saw another number of requests than the gateway\'s client sent.' );
				$this->assertSame( array(), $this->transport->sentInsideTransactions(), $rule->message() . 'a request was sent inside a transaction.' );
			}
		);
	}

	/**
	 * Rule 13: no provider answer, card field or card number in a log line.
	 *
	 * @since 0.2.0
	 */
	protected function checkNoRawResponseLogged(): void {
		$this->rule(
			Rule::NoRawResponseLogged,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule );
				$this->begin( 'rule13-logs-' . self::cellName( $cell ) );

				$placed = $this->place( $rule, Scenario::Approve, $cell );
				$this->capture( $placed['intent_uuid'] );
				$this->refundAll( $placed, $cell, false );
				$this->place( $rule, Scenario::Decline, $cell );

				$this->end();

				$this->assertSame( array(), $this->logger->violations(), $rule->message() . 'a line the gateway logged holds what no log may: ' . implode( '; ', $this->logger->violations() ) . '.' );
			}
		);
	}

	/**
	 * Rule 14: no credential in a URL, a header, a log line or a tape.
	 *
	 * @since 0.2.0
	 */
	protected function checkCredentialsStay(): void {
		$this->rule(
			Rule::CredentialsStay,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule );
				$this->begin( 'rule14-credentials-' . self::cellName( $cell ) );

				$placed = $this->place( $rule, Scenario::Approve, $cell );
				$this->capture( $placed['intent_uuid'] );
				$this->refundAll( $placed, $cell, false );
				$exchanges = $this->transport->exchanges();

				$this->end();

				foreach ( $exchanges as $index => $exchange ) {
					$this->assertFalse( $this->scrubber->holdsCredential( $exchange->url ), $rule->message() . sprintf( 'the URL of request %d holds a credential.', $index + 1 ) );

					foreach ( $exchange->headers as $name => $value ) {
						if ( 'authorization' !== $name ) {
							$this->assertFalse( $this->scrubber->holdsCredential( $value ), $rule->message() . sprintf( 'the header %1$s of request %2$d holds a credential.', $name, $index + 1 ) );
						}
					}
				}

				foreach ( $this->logger->lines() as $line ) {
					$this->assertFalse( $this->scrubber->holdsCredential( $line['message'] . ' ' . (string) wp_json_encode( $line['context'] ) ), $rule->message() . sprintf( 'the log line %s holds a credential.', $line['code'] ) );
				}

				foreach ( null === $this->tapes() ? array() : ( new Tape( $this->tapes() ) )->files() as $file ) {
					try {
						$this->scrubber->scanFile( $file );
					} catch ( TapeRefused $refused ) {
						self::fail( $rule->message() . $refused->getMessage() );
					}

					$this->assertSame( 0, preg_match( '/"authorization"\s*:/i', (string) file_get_contents( $file ) ), $rule->message() . sprintf( 'the tape %s keeps an Authorization header.', $file ) );
				}
			}
		);
	}

	/**
	 * Rule 16: each mode's payments carry that mode's credential.
	 *
	 * @since 0.2.0
	 */
	protected function checkModeCredentials(): void {
		$this->rule(
			Rule::ModeCredentials,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule );

				if ( count( $this->descriptor()->modes ) < 2 || array() === $this->descriptor()->hosts ) {
					self::markTestSkipped( $rule->message() . 'skipped: the gateway declares one mode, or calls no service.' );
				}

				$this->transport->refuseFromNowOn();
				$sent = array();

				foreach ( array( Mode::Test, Mode::Live ) as $mode ) {
					$this->configure( $mode, self::accountOf( $cell ) );
					$this->begin( 'rule16-' . $mode->value );
					$this->attemptPlacement( $rule, $cell );
					$sent[ $mode->value ] = $this->transport->exchanges();
					$this->end();
				}

				foreach ( array( Mode::Test, Mode::Live ) as $mode ) {
					$other = Mode::Test === $mode ? Mode::Live : Mode::Test;
					$this->assertNotSame( array(), $sent[ $mode->value ], $rule->message() . sprintf( 'a %s-mode payment sent no request.', $mode->value ) );
					$this->assertTrue( $this->carriesSecretOf( $sent[ $mode->value ], $mode ), $rule->message() . sprintf( 'a %1$s intent\'s requests carry no %1$s credential.', $mode->value ) );
					$this->assertFalse( $this->carriesSecretOf( $sent[ $mode->value ], $other ), $rule->message() . sprintf( 'a %1$s intent\'s request carries the %2$s credential.', $mode->value, $other->value ) );
				}
			}
		);
	}

	/**
	 * Rule 17: a void at the end of the customer's time answers an approval the provider already gave.
	 *
	 * @since 0.2.0
	 */
	protected function checkVoidAnswersTheApproval(): void {
		$this->rule(
			Rule::VoidAnswersTheApproval,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule, Operations::SCA );
				$this->begin( 'rule17-void-after-approval-' . self::cellName( $cell ) );

				$placed = $this->place( $rule, Scenario::RequiresActionCompleted, $cell );
				$this->assertSame( 'requires_action', $placed['outcome'], $rule->message() . sprintf( 'the payment of the `requires_action_completed` scenario answered %s, not a request to act.', $placed['outcome'] ) );

				$this->endWait( $placed['intent_uuid'], ReconcileStalePlacements::STALE_SECONDS + 1 );
				$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

				$this->end();

				$intent = $this->intentRow( $placed['intent_uuid'] );
				$charge = $this->ledgerRowOf( $placed['order_id'], 'authorize' );
				$this->assertSame( 'authorized', $intent['status'], $rule->message() . sprintf( 'the void after the provider approved left the intent %s, not authorized by the approval it answers.', $intent['status'] ) );
				$this->assertSame( 'approved', $charge[1], $rule->message() . 'the void after the provider approved did not answer the authorization\'s approval.' );
				$this->assertNotNull( $charge[0], $rule->message() . 'the approval the void answers names no charge.' );
			}
		);
	}

	/**
	 * Rule 19: an approved void reports the request's amount, what the cancellation released.
	 *
	 * The gateway is asked directly, as the plugin asks it, so the rule reads the answer before the
	 * plugin applies it: an answer of another amount would only park the order.
	 *
	 * @since 0.2.0
	 */
	protected function checkVoidReportsTheAmount(): void {
		$this->rule(
			Rule::VoidReportsTheAmount,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule, Operations::VOID );
				$this->begin( 'rule19-void-amount-' . self::cellName( $cell ) );

				$placed  = $this->place( $rule, Scenario::Approve, $cell );
				$request = $this->voidRequestOf( $placed['intent_uuid'] );
				$before  = count( $this->transport->exchanges() );
				$answer  = $this->gateway()->void( $request );
				$asked   = array_slice( $this->transport->exchanges(), $before );

				$this->end();

				$this->assertTrue( Operation::Void === $answer->operation && Outcome::Approved === $answer->outcome, $rule->message() . sprintf( 'void is declared for %1$s, and voiding an authorized intent answered %2$s, not an approved void.', self::cellName( $cell ), self::describeResult( $answer ) ) );

				$failure = AnswerRules::voidAmountFailure( $request, $answer ) ?? AnswerRules::objectFailure( $rule, 'the void', $answer->providerObjectId, $request->providerIntentId, array_map( static fn( Exchange $exchange ): string => $exchange->responseBody, $asked ), true );

				if ( null !== $failure ) {
					self::fail( $failure );
				}
			}
		);
	}

	/**
	 * The extension's capabilities page is what its gateway declares.
	 *
	 * @since 0.2.0
	 */
	protected function checkCapabilityPage(): void {
		$root = rtrim( (string) getenv( 'SEOCART_EXTENSION_PATH' ), '/' );

		// The run's extension is the gateway's: SEOCart's own gateways are run with none. The gateway is the one the
		// registry gives, as every caller has it, so its class is the registry's wrapper and says nothing of its plugin.
		if ( '' === $root ) {
			self::markTestSkipped( 'The page of a gateway SEOCart itself registers is docs/reference/gateways.md, which composer docs:check checks.' );
		}

		$gateway = $this->gateway();

		$page    = $root . '/' . GatewayMatrixPage::PLUGIN_TARGET;
		$current = is_file( $page ) ? (string) file_get_contents( $page ) : '';

		$this->assertSame( GatewayMatrixPage::forPlugin( array( $gateway ) )->render(), $current, sprintf( '%s is not what the gateway declares: regenerate it with `php ../seocart/bin/generate-docs.php --plugin=.`.', GatewayMatrixPage::PLUGIN_TARGET ) );
	}

	/**
	 * Builds the plugin's kernel with the suite's id port, encryption key and gateway registry.
	 *
	 * @since 0.2.0
	 *
	 * @param Database       $db     The database.
	 * @param FakeCartTokens $tokens The cart tokens.
	 * @return Container The kernel.
	 */
	protected function kernelOver( Database $db, FakeCartTokens $tokens ): Container {
		return PlacementKernel::over(
			$db,
			$tokens,
			$this->identities,
			$this->wake,
			$this->reporter(),
			array(
				IdGenerator::class   => fn(): IdGenerator => $this->seeded,
				EncryptionKey::class => fn(): EncryptionKey => $this->key,
				Gateways::class      => fn( Container $c ): Gateways => $this->registryOver( $c ),
			)
		);
	}

	/**
	 * Builds the plugin's gateway registry as the plugin does, with the suite's doubles in each gateway's context.
	 *
	 * The registry, the contexts and the HTTP client are the plugin's own; only the transport under
	 * the client, the gateway's logger and its clock are the suite's.
	 *
	 * @since 0.2.0
	 *
	 * @param Container $c The kernel.
	 * @return Gateways The registry.
	 */
	private function registryOver( Container $c ): Gateways {
		return new Gateways(
			Gateways::stubAllowed( wp_get_environment_type(), defined( 'SEOCART_STUB_GATEWAY' ) ? constant( 'SEOCART_STUB_GATEWAY' ) : null ) ? $c->get( PaymentGateway::class ) : null,
			fn( string $gatewayId ): GatewayContext => new GatewayContext(
				$gatewayId,
				$c->get( Gateways::class ),
				$this->logger,
				$this->gatewayClock,
				$c->get( SettingsStore::class ),
				$c->get( SecretVault::class ),
				fn( array $hosts ): HttpClient => new OutboundClient( $hosts, $this->transport, array( $c->get( Logger::class ), 'log' ) )
			),
			$c->get( Reporter::class ),
			static function ( FieldSpec ...$fields ) use ( $c ): void {
				$c->get( Redactor::class )->declareFields( ...$fields );
			},
			$c->get( GatewaySwitches::class )
		);
	}

	/**
	 * Runs a rule's body: skipped with a reason when the gateway declares nothing, and any exception a failure of the rule.
	 *
	 * Every failure inside the body is the rule's (attributedMessage()), and what the tape noted
	 * comes first in it.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule     $rule              The rule.
	 * @param \Closure $body              The body, given the rule.
	 * @param bool     $needsDeclarations Whether the rule is skipped for a gateway whose matrix has no rows.
	 *
	 * @throws \PHPUnit\Framework\AssertionFailedError When the rule fails or is skipped.
	 * @throws \PHPUnit\Framework\Exception            When PHPUnit ends the test another way.
	 */
	protected function rule( Rule $rule, \Closure $body, bool $needsDeclarations = true ): void {
		if ( $needsDeclarations && array() === $this->descriptor()->matrix->rows ) {
			self::markTestSkipped( $rule->message() . 'skipped: the gateway declares nothing yet; its capability matrix has no rows.' );
		}

		try {
			$body( $rule );
		} catch ( \PHPUnit\Framework\AssertionFailedError $failed ) {
			throw $failed instanceof \PHPUnit\Framework\SkippedTest || $failed instanceof \PHPUnit\Framework\IncompleteTest ? $failed : $this->attributed( $rule, $failed );
		} catch ( \PHPUnit\Framework\Exception $outcome ) {
			throw $outcome;
		} catch ( \Throwable $failure ) {
			self::fail( $this->attributedMessage( $rule, sprintf( '%1$s: %2$s', get_class( $failure ), $failure->getMessage() ) . self::causes( $failure ) ) );
		}
	}

	/**
	 * Returns an assertion's failure as the rule's: its message begins with the rule's, and what the tape noted comes first; the comparison is kept.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule                                    $rule   The rule.
	 * @param \PHPUnit\Framework\AssertionFailedError $failed The failure.
	 * @return \PHPUnit\Framework\AssertionFailedError The failure to throw.
	 */
	private function attributed( Rule $rule, \PHPUnit\Framework\AssertionFailedError $failed ): \PHPUnit\Framework\AssertionFailedError {
		$message = $this->attributedMessage( $rule, $failed->getMessage() );

		if ( $message === $failed->getMessage() ) {
			return $failed;
		}

		return $failed instanceof \PHPUnit\Framework\ExpectationFailedException
			? new \PHPUnit\Framework\ExpectationFailedException( $message, $failed->getComparisonFailure(), $failed )
			: new \PHPUnit\Framework\AssertionFailedError( $message, 0, $failed );
	}

	/**
	 * Returns a failure's message as the rule's: the rule's message first, then what the tape noted, then the failure.
	 *
	 * A request the tape could not answer fails inside the gateway, and the plugin turns that into
	 * an answer or an exception of its own, so an assertion about the outcome can fail before the
	 * scenario's end names the cause; the tape's notes, a missing or a stale tape among them, come
	 * first so the cause is read first.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule   $rule    The rule.
	 * @param string $message The failure's message.
	 * @return string The message.
	 */
	private function attributedMessage( Rule $rule, string $message ): string {
		$own   = str_starts_with( $message, $rule->message() ) ? substr( $message, strlen( $rule->message() ) ) : $message;
		$noted = implode( '; ', $this->transport->violations() );

		return $rule->message() . ( '' === $noted || str_contains( $own, $noted ) ? '' : 'the tape: ' . $noted . '. That surfaced as: ' ) . $own;
	}

	/**
	 * Configures the gateway's settings document: stand-in or recorded credentials, the account's country, the mode.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode        $mode             The mode new payments are created in.
	 * @param string|null $accountCountry   The account's country; null for the recorded or example one.
	 * @param bool        $publishedWebhook Whether the webhook secret is the published one, even while recording.
	 */
	protected function configure( Mode $mode, ?string $accountCountry = null, bool $publishedWebhook = false ): void {
		$descriptor = $this->descriptor();

		if ( array() === $descriptor->settings ) {
			if ( '' !== $this->nonce ) {
				$this->writeTapeSettings( array() );
			}

			return;
		}

		$recording = $this->transport->records();
		$recorded  = $recording ? $this->recordingSettings() : array();
		$taped     = null === $this->tapes() ? array() : ( new Tape( $this->tapes() ) )->settings();
		$values    = array();
		$plain     = array();

		$this->secrets = array();

		foreach ( $descriptor->modes as $declared ) {
			foreach ( $descriptor->settings as $field ) {
				$name  = $field->name();
				$value = $this->settingValue( $field, $declared, $recorded, $taped, $accountCountry, $publishedWebhook );

				$values[ GatewaySettingsDeclaration::storedName( $descriptor->id, $declared, $name ) ] = $value;

				if ( Privacy::Secret === $field->privacy() ) {
					$this->secrets[ $declared->value ][ $name ] = (string) $value;
				} elseif ( ModeSettings::RECORDED === $declared ) {
					$plain[ $name ] = $value;
				}
			}
		}

		$modeSetting = GatewaySettingsDeclaration::modeSetting( $descriptor );

		if ( null !== $modeSetting ) {
			$values[ $modeSetting->name() ] = $mode->value;
		}

		GatewayKernel::writeDocument( $this->kernel, $descriptor, $values );

		$this->scrubber = new TapeScrubber( $this->credentialsToScrub(), $this->providerSecrets(), $this->publicIdShapes(), $this->publicDigestFields(), $this->publicCodes(), $this->publicHeaders() );
		$this->transport->scrubWith( $this->scrubber );
		$this->writeTapeSettings( $plain );
	}

	/**
	 * Writes a recording's settings file, scanned first: the test mode's non-secret settings, and the run's nonce when it has one; nothing while replaying, or for a gateway without tapes.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, int|string> $plain The test mode's non-secret settings, by name.
	 */
	private function writeTapeSettings( array $plain ): void {
		if ( ! $this->transport->records() || null === $this->tapes() ) {
			return;
		}

		$settings = $plain + ( '' === $this->nonce ? array() : array( Tape::NONCE => $this->nonce ) );
		$tape     = new Tape( $this->tapes() );

		$this->scrubber->scanSettings( $settings, $tape->directory() . '/' . Tape::SETTINGS );
		$tape->writeSettings( $settings );
	}

	/**
	 * Returns the secrets the provider issues in its answers, which a recording replaces with a placeholder: none unless the test names them.
	 *
	 * A client secret the provider returns for the customer's browser is one: a recording keeps
	 * `{{secret:<name>}}` in its place, and a replay serves the placeholder. Anything else a
	 * recording finds shaped like a secret refuses it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each secret's regular expression, by the name of its placeholder.
	 */
	protected function providerSecrets(): array {
		return array();
	}

	/**
	 * Returns the shapes of the provider's public object ids: none unless the test names them.
	 *
	 * A shape is an id's prefix with the number of letters and digits after it, such as
	 * `'pi_' => 24`, or the least and the most, such as `'cus_' => array( 14, 24 )`. A recording
	 * refuses an opaque token, a run of TapeScrubber::TOKEN_LENGTH or more letters, digits, `_` and
	 * `-` in any case; one of exactly a shape named here passes, unless it also has a secret's prefix
	 * (TapeScrubber::SECRET_PREFIX), sits in a field named like a credential, or sits in a
	 * redirect's query.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|array{int, int}> The shapes, by prefix.
	 */
	protected function publicIdShapes(): array {
		return array();
	}

	/**
	 * Returns the fields, by name, whose 64-hex digests are public, such as a delivery's signature header: none unless the test names them.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The fields' names: a header's, a body field's or a query field's.
	 */
	protected function publicDigestFields(): array {
		return array();
	}

	/**
	 * Returns the provider's enumerated codes, as exact strings: none unless the test names them.
	 *
	 * One declaration serves both places a provider's own words are refused: a tape holds a code
	 * long enough to look like a token, such as `requires_payment_method`, only when it is named
	 * here; and a log line may carry a one-word value of the provider's answers, such as a status,
	 * only when it is named here (rule 13).
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The codes.
	 */
	protected function publicCodes(): array {
		return array();
	}

	/**
	 * Returns the response headers a tape keeps beside `content-type`, by lower-case name: none unless the test names them.
	 *
	 * A tape keeps only the headers a reader of the answer needs: `content-type`, and each header
	 * the gateway reads, such as a request id it logs, named here. Every other response header is
	 * dropped before the recording is scanned, so a provider's security and tracing headers never
	 * refuse it. A header named here is scanned as every other part is: a request id of
	 * TapeScrubber::TOKEN_LENGTH characters or more passes in a shape publicIdShapes() declares.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The headers' names.
	 */
	protected function publicHeaders(): array {
		return array();
	}

	/**
	 * Returns the value of one setting for one mode.
	 *
	 * A non-secret setting is the recorded one, which a mode no recording used takes as
	 * ModeSettings gives it; a secret is the recording's while a recording is made in its mode,
	 * and otherwise the published webhook secret or the test's placeholder().
	 *
	 * @since 0.2.0
	 *
	 * @param FieldSpec                 $field            The setting.
	 * @param Mode                      $mode             The mode.
	 * @param array<string, int|string> $recorded         The recording's settings.
	 * @param array<mixed>              $taped            The tape's settings.
	 * @param string|null               $accountCountry   The account's country, when given.
	 * @param bool                      $publishedWebhook Whether the webhook secret is the published one.
	 * @return int|string The value.
	 */
	private function settingValue( FieldSpec $field, Mode $mode, array $recorded, array $taped, ?string $accountCountry, bool $publishedWebhook ): int|string {
		$name = $field->name();

		if ( GatewayDescriptor::ACCOUNT_COUNTRY === $name && null !== $accountCountry ) {
			return $accountCountry;
		}

		if ( Privacy::Secret !== $field->privacy() ) {
			$value = $recorded[ $name ] ?? $taped[ $name ] ?? $field->example();

			return ModeSettings::valueFor( is_int( $value ) ? $value : (string) $value, $mode );
		}

		if ( GatewayDescriptor::WEBHOOK_SECRET === $name && ( $publishedWebhook || ! $this->transport->records() ) ) {
			return TapeScrubber::PUBLISHED_WEBHOOK_SECRET;
		}

		if ( $this->transport->records() && ModeSettings::RECORDED === $mode ) {
			if ( ! isset( $recorded[ $name ] ) ) {
				self::fail( sprintf( 'The settings file %1$s has no %2$s, a credential the gateway declares.', getenv( self::SETTINGS_FILE ), $name ) );
			}

			return (string) $recorded[ $name ];
		}

		return $this->placeholder( $name, $mode );
	}

	/**
	 * Returns the credentials a recording must not keep, by setting: every secret but the published webhook secret.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, list<string>> The values by setting name.
	 */
	private function credentialsToScrub(): array {
		$credentials = array();

		foreach ( $this->secrets as $secrets ) {
			foreach ( $secrets as $name => $value ) {
				if ( TapeScrubber::PUBLISHED_WEBHOOK_SECRET !== $value ) {
					$credentials[ $name ][] = $value;
				}
			}
		}

		return $credentials;
	}

	/**
	 * Starts a scenario: its ids seeded by its name and the run's nonce, its tape the transport's.
	 *
	 * @since 0.2.0
	 *
	 * @param string $scenario         The scenario's name.
	 * @param bool   $expectsRequests  Whether it sends requests at all; one that must not needs no tape.
	 */
	protected function begin( string $scenario, bool $expectsRequests = true ): void {
		$this->seeded->reseed( $this->gatewayId() . ' ' . $scenario . ( '' === $this->nonce ? '' : ' ' . $this->nonce ) );
		$this->transport->scenario( $scenario, $expectsRequests );
	}

	/**
	 * Ends a scenario: the tape asked for whole, or written; nothing wrong with the exchanges.
	 *
	 * @since 0.2.0
	 */
	protected function end(): void {
		$this->transport->finish();

		$this->assertSame( array(), $this->transport->violations(), 'The tape: ' . implode( '; ', $this->transport->violations() ) . '.' );

		if ( $this->transport->expectsRequests() && array() !== $this->descriptor()->hosts && array() === $this->transport->exchanges() ) {
			self::fail( Rule::EveryCallThroughTheClient->message() . sprintf( 'the scenario %s completed without one request through the HTTP client the plugin gives the gateway, though the gateway declares the services it calls: a request made another way is neither recorded nor replayed.', $this->transport->scenarioName() ) );
		}
	}

	/**
	 * Returns the exchanges sent while a piece of work ran.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $work The work.
	 * @return list<Exchange> The exchanges.
	 */
	protected function during( \Closure $work ): array {
		$before = count( $this->transport->exchanges() );
		$work();

		return array_slice( $this->transport->exchanges(), $before );
	}

	/**
	 * Returns the plugin's gateway registry.
	 *
	 * @since 0.2.0
	 *
	 * @return Gateways The registry.
	 */
	protected function gateways(): Gateways {
		return $this->kernel->get( Gateways::class );
	}

	/**
	 * Returns the gateway's descriptor, as the plugin registered it.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	protected function descriptor(): GatewayDescriptor {
		return $this->gateways()->descriptor( $this->gatewayId() );
	}

	/**
	 * Returns the gateway, as the plugin resolves it for a test-mode payment.
	 *
	 * @since 0.2.0
	 *
	 * @return PaymentGateway The gateway.
	 */
	protected function gateway(): PaymentGateway {
		return $this->gateways()->get( $this->gatewayId(), Mode::Test );
	}

	/**
	 * Returns the plugin's payment service.
	 *
	 * @since 0.2.0
	 *
	 * @return PaymentService The service.
	 */
	protected function payments(): PaymentService {
		return $this->kernel->get( PaymentService::class );
	}

	/**
	 * Returns the matrix's rows, the store's currency first, then by currency and country.
	 *
	 * @since 0.2.0
	 *
	 * @return list<MatrixRow> The rows.
	 */
	protected function cells(): array {
		$rows = $this->descriptor()->matrix->rows;

		usort(
			$rows,
			static fn( MatrixRow $one, MatrixRow $other ): int => array( self::CURRENCY !== $one->currency->code(), $one->currency->code(), $one->accountCountry ) <=> array( self::CURRENCY !== $other->currency->code(), $other->currency->code(), $other->accountCountry )
		);

		return $rows;
	}

	/**
	 * Returns the first cell a rule runs in, which must declare an operation; the rule is skipped when none does.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule   $rule      The rule.
	 * @param string $operation The operation the rule needs.
	 * @return MatrixRow The cell.
	 */
	protected function firstCell( Rule $rule, string $operation = Operations::AUTHORIZE ): MatrixRow {
		foreach ( $this->cells() as $cell ) {
			if ( $cell->supports( $operation ) && in_array( $cell->currency->code(), self::SELLABLE, true ) ) {
				$this->configure( Mode::Test, self::accountOf( $cell ) );

				return $cell;
			}
		}

		self::markTestSkipped( $rule->message() . sprintf( 'skipped: no cell of the matrix in a currency the test store sells (%1$s) declares %2$s.', implode( ', ', self::SELLABLE ), $operation ) );
	}

	/**
	 * Returns the token of a scenario, or skips or fails the rule when the test names none.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule     $rule     The rule.
	 * @param Scenario $scenario The scenario.
	 * @return string The token.
	 */
	protected function token( Rule $rule, Scenario $scenario ): string {
		$token = $this->tokens()[ $scenario->value ] ?? null;

		if ( $token instanceof NotProducible ) {
			self::markTestSkipped( $rule->message() . sprintf( 'skipped: the provider cannot produce the scenario %1$s in test mode: %2$s', $scenario->value, $token->reason ) );
		}

		if ( ! is_string( $token ) || '' === $token ) {
			self::fail( $rule->message() . sprintf( 'the rule places a payment of the scenario %1$s: name its payment token in %2$s::tokens(), or NotProducible with the reason.', $scenario->value, static::class ) );
		}

		return $token;
	}

	/**
	 * Places an order paid through the gateway, in a cell's currency.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule      $rule     The rule placing it.
	 * @param Scenario  $scenario The payment's scenario.
	 * @param MatrixRow $cell     The cell.
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, outcome: string, next_action: array<string, mixed>|null} The placement.
	 */
	protected function place( Rule $rule, Scenario $scenario, MatrixRow $cell ): array {
		$token   = $this->token( $rule, $scenario );
		$answer  = $this->placeIn( $cell->currency->code(), $token );
		$orderId = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), (string) $answer['order_uuid'] );

		return array(
			'order_uuid'  => (string) $answer['order_uuid'],
			'order_id'    => $orderId,
			'intent_uuid' => (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 1', $this->table( PaymentTables::INTENTS ), $orderId ),
			'outcome'     => (string) $answer['outcome'],
			'next_action' => is_array( $answer['next_action'] ?? null ) ? $answer['next_action'] : null,
		);
	}

	/**
	 * Places a cart of the test product in a currency, paid with a token, through the plugin's placement.
	 *
	 * @since 0.2.0
	 *
	 * @param string $currency The currency.
	 * @param string $token    The payment token.
	 * @return array<string, mixed> The placement's answer; for a declined payment, its order and the outcome `declined`.
	 *
	 * @throws CodedException When the placement is refused for another reason than a decline.
	 */
	private function placeIn( string $currency, string $token ): array {
		$variant = $this->sellable( 10, self::PRICE );

		if ( self::CURRENCY !== $currency ) {
			$this->priceIn( $currency, array( $variant => self::PRICE ) );
		}

		$cart = $this->startCart( array( $variant => self::UNITS ) );

		if ( self::CURRENCY !== $currency ) {
			$this->switchTo( $currency, $cart->version );
			$cart = $this->kernel->get( CartService::class )->current() ?? $cart;
		}

		$this->writeCheckout( $cart->version, $this->gatewayId() );

		try {
			return $this->freshPlacement()->place( $this->placeInput( 'conformance-' . $variant, $token ), self::guest() );
		} catch ( CodedException $declined ) {
			// A declined payment is answered as a refusal that names its order.
			if ( CheckoutError::PaymentDeclined !== $declined->errorCode() ) {
				throw $declined;
			}

			return array(
				'order_uuid'  => (string) ( $declined->details()['order_uuid'] ?? '' ),
				'outcome'     => 'declined',
				'next_action' => null,
			);
		}
	}

	/**
	 * Places an order whose authorization may fail, keeping only what was sent.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule      $rule The rule.
	 * @param MatrixRow $cell The cell.
	 */
	private function attemptPlacement( Rule $rule, MatrixRow $cell ): void {
		try {
			$this->placeIn( $cell->currency->code(), $this->token( $rule, Scenario::Approve ) );
		} catch ( CodedException $refused ) {
			unset( $refused );
		}
	}

	/**
	 * Captures an intent through the plugin, all or part.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $intentUuid The intent.
	 * @param int|null $amount     The amount in minor units; null for all of it.
	 */
	protected function capture( string $intentUuid, ?int $amount = null ): void {
		$this->payments()->capture( $intentUuid, $this->agent(), $amount );
	}

	/**
	 * Voids an intent through the plugin, as a person would.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 */
	protected function void( string $intentUuid ): void {
		$this->payments()->void( $intentUuid, $this->agent(), VoidReason::CustomerRequest );
	}

	/**
	 * Refunds units of an order's line through the plugin.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @param int    $units     The units of its line.
	 * @param bool   $shipping  Whether the shipping is refunded too.
	 * @return Refund The refund.
	 */
	protected function refund( string $orderUuid, int $units, bool $shipping ): Refund {
		$line = (string) $this->db->fetchValue( 'SELECT l.line_uuid FROM %i l JOIN %i o ON o.id = l.order_id WHERE o.uuid = %s ORDER BY l.sort_order LIMIT 1', $this->table( OrderTables::LINES ), $this->table( OrderTables::ORDERS ), $orderUuid );

		return $this->kernel->get( RefundService::class )->refund( new RefundRequest( $orderUuid, array( new RefundLineRequest( $line, $units ) ), $shipping, 'customer_return' ), $this->agent() );
	}

	/**
	 * Refunds a captured order whole: one unit first, then the rest, where partial refunds are declared; at once otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param array{order_uuid: string, order_id: int, intent_uuid: string} $placed     The placement.
	 * @param MatrixRow                                                     $cell       The cell.
	 * @param bool                                                          $loseFirst  Whether the first refund's answer is lost and the refund asked again.
	 * @return list<Refund> The refunds, in order.
	 */
	protected function refundAll( array $placed, MatrixRow $cell, bool $loseFirst = false ): array {
		$shipping = $this->hasShipping( $placed['order_id'] );

		if ( ! $loseFirst || ! $cell->supports( Operations::PARTIAL_REFUND ) ) {
			$refunds = array();

			foreach ( $this->refundSteps( $placed, $cell ) as list( $units, $withShipping ) ) {
				$refunds[] = $this->refund( $placed['order_uuid'], $units, $withShipping );
			}

			return $refunds;
		}

		// The provider makes the first refund, and its answer is lost on the way back. Asked again
		// with the same request, the plugin finds its claim open and asks the gateway what became of
		// it (queryRefund) instead of refunding twice. A gateway that sends no request is answered.
		$this->transport->loseNextAnswer();

		try {
			$first = $this->refund( $placed['order_uuid'], 1, false );
		} catch ( \Throwable $lost ) {
			unset( $lost );
			$first = $this->refund( $placed['order_uuid'], 1, false );
		} finally {
			$this->transport->loseNextAnswer( false );
		}

		return array( $first, $this->refund( $placed['order_uuid'], self::UNITS - 1, $shipping ) );
	}

	/**
	 * Returns the refunds that give a captured order back whole: one unit, then the rest, where partial refunds are declared; all at once otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param array{order_uuid: string, order_id: int, intent_uuid: string} $placed The placement.
	 * @param MatrixRow                                                     $cell   The cell.
	 * @return array<string, array{int, bool}> Each refund's units and whether it gives the shipping back, by its name in a message.
	 */
	protected function refundSteps( array $placed, MatrixRow $cell ): array {
		$shipping = $this->hasShipping( $placed['order_id'] );

		if ( ! $cell->supports( Operations::PARTIAL_REFUND ) ) {
			return array( 'the refund' => array( self::UNITS, $shipping ) );
		}

		return array(
			'the first refund'  => array( 1, false ),
			'the second refund' => array( self::UNITS - 1, $shipping ),
		);
	}

	/**
	 * Walks the money path one operation at a time, an authorization, its capture, each refund, a second authorization and its void, and hands each operation's requests and key to a check.
	 *
	 * Each key is the one its request derives (idempotencyKey()), whether or not the provider keeps
	 * keys: one that does not is searched by it. The walk begins the scenario's requests, so the
	 * authorization's are every request sent so far.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule      $rule  The rule.
	 * @param MatrixRow $cell  The cell.
	 * @param \Closure  $check Called as (what, requests, key) for each operation.
	 *
	 * @phpstan-param \Closure(string, list<Exchange>, string): void $check
	 */
	protected function eachMoneyOperation( Rule $rule, MatrixRow $cell, \Closure $check ): void {
		$placed = $this->place( $rule, Scenario::Approve, $cell );
		$uuid   = $placed['intent_uuid'];
		$check( 'the authorization', $this->transport->exchanges(), $this->authorizationKey( $placed, $cell ) );

		$key = $this->captureRequestOf( $uuid )->idempotencyKey();
		$check( 'the capture', $this->during( fn() => $this->capture( $uuid ) ), $key );

		foreach ( $this->refundSteps( $placed, $cell ) as $step => list( $units, $shipping ) ) {
			list( $refund, $exchanges ) = $this->refundWatched( $placed, $units, $shipping );
			$intent                     = $this->intentRow( $uuid );
			$check( $step, $exchanges, ( new GatewayRefund( $uuid, $intent['provider_intent_id'], $refund->total, $refund->uuid, Mode::Test ) )->idempotencyKey() );
		}

		$voided = $this->place( $rule, Scenario::Approve, $cell );
		$key    = $this->voidRequestOf( $voided['intent_uuid'] )->idempotencyKey();
		$check( 'the void', $this->during( fn() => $this->void( $voided['intent_uuid'] ) ), $key );
	}

	/**
	 * Refunds units of a placed order and returns the refund with the requests it was asked with.
	 *
	 * @since 0.2.0
	 *
	 * @param array{order_uuid: string, order_id: int, intent_uuid: string} $placed   The placement.
	 * @param int                                                           $units    The units of its line.
	 * @param bool                                                          $shipping Whether the shipping is refunded too.
	 * @return array{Refund, list<Exchange>} The refund, and the requests.
	 */
	protected function refundWatched( array $placed, int $units, bool $shipping ): array {
		$before = count( $this->transport->exchanges() );
		$refund = $this->refund( $placed['order_uuid'], $units, $shipping );

		return array( $refund, array_slice( $this->transport->exchanges(), $before ) );
	}

	/**
	 * Exercises one cell: every declared operation succeeds, every undeclared one core can ask is refused before a request.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule      $rule The rule.
	 * @param MatrixRow $cell The cell.
	 */
	private function exerciseCell( Rule $rule, MatrixRow $cell ): void {
		$name   = self::cellName( $cell );
		$placed = $this->place( $rule, Scenario::Approve, $cell );
		$this->assertSame( 'approved', $placed['outcome'], $rule->message() . sprintf( 'authorize is declared for %1$s, and an approved payment answered %2$s.', $name, $placed['outcome'] ) );

		$asked = $this->payments()->queryGateway( $this->ref( $placed['intent_uuid'] ) );
		$this->assertTrue( null !== $asked && Operation::Authorize === $asked->operation && Outcome::Approved === $asked->outcome, $rule->message() . sprintf( 'query is declared for %1$s, and asking about an authorized intent answered %2$s.', $name, null === $asked ? 'nothing' : self::describeResult( $asked ) ) );

		$total = $this->intentRow( $placed['intent_uuid'] )['amount_minor'];

		if ( ! $cell->supports( Operations::PARTIAL_CAPTURE ) ) {
			$this->refusedBeforeAnyRequest( $rule, sprintf( 'partial_capture is not declared for %s', $name ), PaymentError::OperationUnsupported, fn() => $this->capture( $placed['intent_uuid'], $total - 1 ) );
		}

		$this->capture( $placed['intent_uuid'] );
		$this->assertSame( 'captured', $this->intentRow( $placed['intent_uuid'] )['status'], $rule->message() . sprintf( 'capture is declared for %s, and the intent is not captured.', $name ) );

		if ( ! $cell->supports( Operations::PARTIAL_REFUND ) ) {
			$this->refusedBeforeAnyRequest( $rule, sprintf( 'partial_refund is not declared for %s', $name ), PaymentError::OperationUnsupported, fn() => $this->refund( $placed['order_uuid'], 1, false ) );
		}

		$refunds = $cell->supports( Operations::PARTIAL_REFUND ) ? 'refund, partial_refund and query_refund are' : 'refund is';

		try {
			$this->refundAll( $placed, $cell, $cell->supports( Operations::QUERY_REFUND ) );
		} catch ( CodedException $refused ) {
			self::fail( $rule->message() . sprintf( '%1$s declared for %2$s, and refunding the captured order%3$s was refused: %4$s.', $refunds, $name, $cell->supports( Operations::PARTIAL_REFUND ) ? ' one unit, then the rest,' : '', $refused->getMessage() ) . self::causes( $refused ) );
		}
		$this->assertSame( 'refunded', $this->intentRow( $placed['intent_uuid'] )['status'], $rule->message() . sprintf( '%1$s declared for %2$s, and the intent is not refunded.', $refunds, $name ) );

		$voided = $this->place( $rule, Scenario::Approve, $cell );
		$this->void( $voided['intent_uuid'] );
		$this->assertSame( 'voided', $this->intentRow( $voided['intent_uuid'] )['status'], $rule->message() . sprintf( 'void is declared for %s, and the intent is not voided.', $name ) );

		if ( $cell->supports( Operations::PARTIAL_CAPTURE ) ) {
			$partly = $this->place( $rule, Scenario::Approve, $cell );
			$this->capture( $partly['intent_uuid'], $total - 1 );
			$this->assertSame( $total - 1, $this->intentRow( $partly['intent_uuid'] )['captured_minor'], $rule->message() . sprintf( 'partial_capture is declared for %s, and capturing part did not capture that part.', $name ) );
		}

		if ( is_string( $this->tokens()[ Scenario::RequiresAction->value ] ?? null ) ) {
			$acting = $this->place( $rule, Scenario::RequiresAction, $cell );

			if ( $cell->supports( Operations::SCA ) ) {
				$this->assertSame( 'requires_action', $acting['outcome'], $rule->message() . sprintf( 'sca is declared for %1$s, and the `requires_action` scenario answered %2$s.', $name, $acting['outcome'] ) );
			} else {
				$this->assertNotSame( 'requires_action', $acting['outcome'], $rule->message() . sprintf( 'the gateway asked the customer to act in %s, where it declares no sca.', $name ) );
			}
		}
	}

	/**
	 * Proves the operations of a currency the matrix has no row for are refused before any request.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule $rule The rule.
	 */
	private function refusesOutsideTheMatrix( Rule $rule ): void {
		$declared = $this->descriptor()->matrix->currencies();
		$outside  = self::OUTSIDE;

		foreach ( array_merge( self::LAUNCH_CURRENCIES, array( self::OUTSIDE ) ) as $code ) {
			if ( ! in_array( $code, $declared, true ) ) {
				$outside = $code;
				break;
			}
		}

		if ( in_array( $outside, self::SELLABLE, true ) ) {
			$this->refusedBeforeAnyRequest( $rule, sprintf( 'the matrix has no row for %s', $outside ), CheckoutError::PaymentMethodUnavailable, fn() => $this->placeIn( $outside, 'conformance_any_token' ) );
		}

		$fresh = $this->openIntent( $outside );
		$this->refusedBeforeAnyRequest( $rule, sprintf( 'the matrix has no row for %s, so authorize', $outside ), PaymentError::OperationUnsupported, fn() => $this->payments()->authorize( $fresh, array(), $this->orderUuidOf( $fresh ), '' ) );

		$captured = $this->openIntent( $outside, true, true );
		$this->refusedBeforeAnyRequest( $rule, sprintf( 'the matrix has no row for %s, so refund', $outside ), PaymentError::OperationUnsupported, fn() => $this->refund( $this->orderUuidOf( $captured ), 1, false ) );

		$intent = $this->openIntent( $outside, true );

		foreach ( array( 'capture', 'void', 'query' ) as $operation ) {
			$this->refusedBeforeAnyRequest(
				$rule,
				sprintf( 'the matrix has no row for %1$s, so %2$s', $outside, $operation ),
				PaymentError::OperationUnsupported,
				match ( $operation ) {
					'capture' => fn() => $this->capture( $intent ),
					'void'    => fn() => $this->void( $intent ),
					default   => fn() => $this->payments()->queryGateway( $this->ref( $intent ) ),
				}
			);
		}
	}

	/**
	 * Asserts a piece of work is refused with a code, before the gateway sent anything.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule      $rule The rule.
	 * @param string    $what What is refused, for the message.
	 * @param ErrorCode $code The code it must be refused with.
	 * @param \Closure  $work The work.
	 */
	private function refusedBeforeAnyRequest( Rule $rule, string $what, ErrorCode $code, \Closure $work ): void {
		$sent = count( $this->transport->exchanges() );

		try {
			$work();
		} catch ( CodedException $refused ) {
			$this->assertSame( $code, $refused->errorCode(), $rule->message() . sprintf( '%1$s, and it was refused with %2$s, not %3$s.', $what, $refused->errorCode()->value, $code->value ) );
			$this->assertSame( $sent, count( $this->transport->exchanges() ), $rule->message() . sprintf( '%s, and a request was sent before it was refused.', $what ) );

			return;
		}

		self::fail( $rule->message() . sprintf( '%1$s, and it was not refused with %2$s.', $what, $code->value ) );
	}

	/**
	 * Reads one recorded delivery as the rule asks, and its tampered copies.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule                 $rule     The rule.
	 * @param string               $name     The delivery's name.
	 * @param array<string, mixed> $delivery The delivery.
	 */
	private function readsDeliveryRightly( Rule $rule, string $name, array $delivery ): WebhookReadingKind {
		$at      = new \DateTimeImmutable( (string) $delivery['signed_at'] );
		$headers = (array) $delivery['headers'];
		$body    = (string) $delivery['body'];
		$wrong   = 'not_' . TapeScrubber::PUBLISHED_WEBHOOK_SECRET;
		$cases   = array(
			'a bad signature'                       => array( $this->signDelivery( $headers, $body, $wrong, $at ), $body, $at->modify( '+1 second' ) ),
			'a signed time an hour old'             => array( $headers, $body, $at->modify( '+1 hour' ) ),
			'a signed time an hour ahead'           => array( $headers, $body, $at->modify( '-1 hour' ) ),
			'a malformed body with a bad signature' => array( $this->signDelivery( $headers, '{"truncated":', $wrong, $at ), '{"truncated":', $at->modify( '+1 second' ) ),
		);

		$rejected = array();

		foreach ( $cases as $case => list( $caseHeaders, $caseBody, $now ) ) {
			$reading = $this->read( $rule, $name, $case, $caseHeaders, $caseBody, $now );
			$this->assertSame( WebhookReadingKind::Rejected, $reading->kind, $rule->message() . sprintf( 'the delivery %1$s with %2$s was answered %3$s, not rejected.', $name, $case, $reading->kind->value ) );
			$rejected[ $case ] = $reading;
		}

		$first  = $this->read( $rule, $name, 'its signature', $headers, $body, $at->modify( '+1 second' ) );
		$second = $this->read( $rule, $name, 'its signature, read again', $headers, $body, $at->modify( '+1 second' ) );
		$this->assertNotSame( WebhookReadingKind::Rejected, $first->kind, $rule->message() . sprintf( 'the recorded delivery %1$s was rejected (%2$s) with the published secret.', $name, (string) $first->reason ) );
		$this->assertSame( $first->eventId, $second->eventId, $rule->message() . sprintf( 'the delivery %s read twice gave two event ids.', $name ) );
		$this->assertNotNull( $first->eventId, $rule->message() . sprintf( 'the delivery %s was read without its event id.', $name ) );

		if ( WebhookReadingKind::Result === $first->kind ) {
			$this->assertTrue( null !== $first->result && $this->gatewayId() === $first->result->provider && '' !== $first->result->intentUuid, $rule->message() . sprintf( 'the delivery %s reads as a payment result that is not one of this gateway\'s, about an intent.', $name ) );
		} else {
			$this->assertNull( $first->result, $rule->message() . sprintf( 'the delivery %s reads as ignored, and carries a result.', $name ) );
		}

		// The same deliveries through the store's route: the receiver decides as the gateway reads, and a redelivery changes nothing.
		list( $badHeaders, $badBody, $badAt ) = $cases['a bad signature'];
		$this->assertReceivedAsRead( $name . ' with a bad signature', $badHeaders, $badBody, $badAt, $rejected['a bad signature'], $first->eventId );
		$this->assertReceivedAsRead( $name, $headers, $body, $at->modify( '+1 second' ), $first, $first->eventId );
		$this->assertReceivedAsRead( $name . ' delivered again', $headers, $body, $at->modify( '+2 seconds' ), $first, $first->eventId );

		return $first->kind;
	}

	/**
	 * Replays a delivery through the store's webhook route, as WordPress dispatches it, and fails rule 10 when the receiver decides otherwise than the gateway read it.
	 *
	 * The route is built as the kernel builds it, from the kernel's receiver, with its clock frozen at
	 * the delivery's arrival, and is the only route served; the receiver reads the delivery with
	 * the gateway's readWebhook() through the registry and keeps its receipt. The receipt of the
	 * event is read by the gateway, the mode and the event's id before the dispatch and after it,
	 * so what is asserted is what this delivery did to it: an earlier receipt of the event cannot
	 * stand in for this delivery's. ReceiptRules says which change matches which reading.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $name    The delivery, with the case, for the message.
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The raw body.
	 * @param \DateTimeImmutable    $arrives When it arrives: the route's clock and the gateway's.
	 * @param WebhookReading        $reading The gateway's own reading of it.
	 * @param string|null           $eventId The event's id, as the gateway reads it from the delivery verified: its receipt is looked for by it.
	 */
	private function assertReceivedAsRead( string $name, array $headers, string $body, \DateTimeImmutable $arrives, WebhookReading $reading, ?string $eventId ): void {
		$route   = new WebhookRoute( fn(): ReceiveWebhook => $this->kernel->get( ReceiveWebhook::class ), $this->kernel->get( ErrorTranslator::class ), FrozenClock::at( $arrives->format( \DateTimeInterface::ATOM ) ), $this->kernel->get( Reporter::class ) );
		$server  = $_SERVER;
		$request = new \WP_REST_Request( 'POST', '/' . WebhookAddress::NAMESPACE . WebhookAddress::path( $this->gatewayId(), Mode::Test ) );

		$request->set_body( $body );

		foreach ( $headers as $header => $value ) {
			$request->set_header( (string) $header, (string) $value );
		}

		$before = $this->receiptOf( $eventId );

		$this->gatewayClock->setTo( $arrives );
		KernelHooks::detach( 'rest_api_init' );
		add_action( 'rest_api_init', array( $route, 'register' ) );
		self::discardRestServer();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		try {
			$response = rest_do_request( $request );
		} finally {
			remove_action( 'rest_api_init', array( $route, 'register' ) );
			self::discardRestServer();
			$_SERVER = $server; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the request globals put back as they were.
		}

		$data    = (array) $response->get_data();
		$failure = ReceiptRules::failure( $name, $reading, $response->get_status(), isset( $data['code'] ) ? (string) $data['code'] : null, $before, $this->receiptOf( $eventId ), $body );

		if ( null !== $failure ) {
			self::fail( $failure );
		}
	}

	/**
	 * Reads the receipt the store's receiver keeps of an event of the gateway in test mode.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $eventId The event's id, as the gateway reads it; null for none.
	 * @return array{id: int, result: string, result_code: string, payload_hash: string}|null The receipt; null when there is none.
	 */
	private function receiptOf( ?string $eventId ): ?array {
		$row = null === $eventId ? null : $this->db->fetchRow( 'SELECT id, result, result_code, payload_hash FROM %i WHERE provider = %s AND mode = %s AND event_id = %s', $this->table( WebhookReceiptTables::RECEIPTS ), $this->gatewayId(), Mode::Test->value, DeliveredValues::eventId( $eventId ) );

		return null === $row ? null : array(
			'id'           => (int) $row['id'],
			'result'       => (string) $row['result'],
			'result_code'  => (string) $row['result_code'],
			'payload_hash' => (string) $row['payload_hash'],
		);
	}

	/**
	 * Discards the REST server, so the next dispatch builds one with the routes registered afresh.
	 *
	 * @since 0.2.0
	 */
	private static function discardRestServer(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- resets core's REST server global: the next dispatch builds a new server and fires rest_api_init again.
		$wp_rest_server = null;
	}

	/**
	 * Reads a delivery through the gateway with the clock at a time; an exception fails the rule.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule                  $rule    The rule.
	 * @param string                $name    The delivery's name.
	 * @param string                $what    What is being read, for the message.
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The raw body.
	 * @param \DateTimeImmutable    $now     Where the gateway's clock stands.
	 * @return WebhookReading The reading.
	 */
	private function read( Rule $rule, string $name, string $what, array $headers, string $body, \DateTimeImmutable $now ): WebhookReading {
		$this->gatewayClock->setTo( $now );

		try {
			return $this->gateway()->readWebhook( new WebhookEnvelope( $this->gatewayId(), Mode::Test, $headers, $body, $now ) );
		} catch ( \Throwable $thrown ) {
			self::fail( $rule->message() . sprintf( 'readWebhook() threw %1$s on the delivery %2$s with %3$s: it answers rejected, never throws.', get_class( $thrown ), $name, $what ) );
		}
	}

	/**
	 * Scrubs, re-signs and writes a fresh delivery to the tape.
	 *
	 * @since 0.2.0
	 *
	 * @param Tape                 $tape     The tape.
	 * @param string               $name     The delivery's name.
	 * @param array<string, mixed> $delivery The delivery as the provider sent it.
	 */
	private function recordDelivery( Tape $tape, string $name, array $delivery ): void {
		$scrubbed = $this->scrubber->delivery( $delivery, fn( array $headers, string $body, string $secret, \DateTimeImmutable $at ): array => $this->signDelivery( $headers, $body, $secret, $at ) );
		$this->scrubber->scanDelivery( $scrubbed, 'The delivery ' . $name );
		$tape->writeDelivery( $name, $scrubbed );
	}

	/**
	 * Returns the key the contract derives for a placed payment's authorization (PaymentRequest::idempotencyKey()).
	 *
	 * @since 0.2.0
	 *
	 * @param array{order_uuid: string, order_id: int, intent_uuid: string} $placed The placement.
	 * @param MatrixRow                                                     $cell   The cell it was placed in.
	 * @return string The key.
	 */
	private function authorizationKey( array $placed, MatrixRow $cell ): string {
		$intent = $this->intentRow( $placed['intent_uuid'] );

		return ( new PaymentRequest( $placed['intent_uuid'], Money::of( $intent['amount_minor'], $cell->currency ), null, Mode::Test, $placed['order_uuid'], '', null ) )->idempotencyKey();
	}

	/**
	 * Fails rule 6 when a request of an operation that moves money does not carry the operation's key in a header or its body.
	 *
	 * @since 0.2.0
	 *
	 * @param string     $what      The operation, for the message.
	 * @param Exchange[] $exchanges The requests it sent.
	 * @param string     $key       The key.
	 *
	 * @phpstan-param list<Exchange> $exchanges
	 */
	private function assertKeyed( string $what, array $exchanges, string $key ): void {
		$failure = RequestRules::keyFailure( $what, $exchanges, $key );

		if ( null !== $failure ) {
			self::fail( $failure );
		}
	}

	/**
	 * Fails rule 1 when an outcome does not name its own provider object, and returns the object.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule                                                          $rule      The rule.
	 * @param string                                                        $what      The outcome, for the message.
	 * @param array{order_uuid: string, order_id: int, intent_uuid: string} $placed    The placement.
	 * @param string                                                        $operation The ledger's operation of the outcome.
	 * @param Exchange[]                                                    $exchanges The requests the outcome was asked with.
	 * @return string The object.
	 *
	 * @phpstan-param list<Exchange> $exchanges
	 */
	private function assertOwnObject( Rule $rule, string $what, array $placed, string $operation, array $exchanges ): string {
		list( $object ) = $this->ledgerRowOf( $placed['order_id'], $operation );
		$failure        = AnswerRules::objectFailure( $rule, $what, $object, $this->intentRow( $placed['intent_uuid'] )['provider_intent_id'], array_map( static fn( Exchange $exchange ): string => $exchange->responseBody, $exchanges ), false );

		if ( null !== $failure ) {
			self::fail( $failure );
		}

		return (string) $object;
	}

	/**
	 * Tells whether any of a set of exchanges carries a mode's credential: as it is, URL-encoded, base64-encoded, or in an HTTP Basic header.
	 *
	 * @since 0.2.0
	 *
	 * @param Exchange[] $exchanges The exchanges.
	 * @param Mode       $mode      The mode.
	 * @return bool True when one does.
	 */
	private function carriesSecretOf( array $exchanges, Mode $mode ): bool {
		foreach ( $exchanges as $exchange ) {
			$text = $exchange->url . "\n" . implode( "\n", $exchange->headers ) . "\n" . (string) $exchange->body;

			if ( 1 === preg_match( '/^Basic\s+(\S+)$/i', $exchange->headers['authorization'] ?? '', $basic ) ) {
				$text .= "\n" . (string) base64_decode( $basic[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- An HTTP Basic credential is base64 by definition.
			}

			foreach ( $this->secrets[ $mode->value ] ?? array() as $value ) {
				if ( TapeScrubber::PUBLISHED_WEBHOOK_SECRET !== $value && ( new TapeScrubber( array( 'secret' => array( $value ) ) ) )->holdsCredential( $text ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Opens an intent of the gateway for a new order, without asking the gateway anything.
	 *
	 * @since 0.2.0
	 *
	 * @param string $currency   The order's currency.
	 * @param bool   $authorized Whether the intent is then written authorized, as if the provider had approved it.
	 * @param bool   $captured   Whether it is captured too, its whole amount.
	 * @return string The intent's uuid.
	 */
	protected function openIntent( string $currency, bool $authorized = false, bool $captured = false ): string {
		$document = NewOrders::forTwoLines( $currency, self::CURRENCY );
		$orders   = $this->kernel->get( Orders::class );
		$payments = $this->payments();
		$gateway  = $this->gatewayId();
		$uuid     = (string) $this->db->transaction(
			static function () use ( $orders, $payments, $document, $gateway ): string {
				$order = $orders->insert( $document, Actor::user( 0 ) );

				return $payments->createIntent( $order->id, $gateway, Mode::Test, $document->totals->grandTotal, $document->totals->baseGrandTotal, $order->conversionContextId )->uuid;
			}
		);

		if ( $authorized || $captured ) {
			$this->db->execute( "UPDATE %i SET status = 'authorized', authorized_minor = amount_minor, base_authorized_minor = base_amount_minor, provider_intent_id = %s WHERE uuid = %s", $this->table( PaymentTables::INTENTS ), 'conformance_' . $uuid, $uuid );
		}

		if ( $captured ) {
			$this->db->execute( "UPDATE %i SET status = 'captured', captured_minor = amount_minor, base_captured_minor = base_amount_minor WHERE uuid = %s", $this->table( PaymentTables::INTENTS ), $uuid );
		}

		return $uuid;
	}

	/**
	 * Returns the uuid of an intent's order.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return string The order's uuid.
	 */
	protected function orderUuidOf( string $intentUuid ): string {
		return (string) $this->db->fetchValue( 'SELECT o.uuid FROM %i o JOIN %i i ON i.order_id = o.id WHERE i.uuid = %s', $this->table( OrderTables::ORDERS ), $this->table( PaymentTables::INTENTS ), $intentUuid );
	}

	/**
	 * Returns an intent as the plugin's payment service reads it for a query.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return IntentRef The intent.
	 */
	protected function ref( string $intentUuid ): IntentRef {
		return $this->payments()->intentRef( $intentUuid ) ?? self::fail( sprintf( 'The intent %s is not there.', $intentUuid ) );
	}

	/**
	 * Builds the capture request the plugin would send for an authorized intent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return CaptureRequest The request.
	 */
	protected function captureRequestOf( string $intentUuid ): CaptureRequest {
		$intent = $this->intentRow( $intentUuid );

		return new CaptureRequest( $intentUuid, $intent['provider_intent_id'], Money::of( $intent['authorized_minor'], Currency::of( $intent['currency'] ) ), Mode::Test );
	}

	/**
	 * Rule 8: a refund the provider never made, of an intent it knows and of one it never saw, is answered nothing or a declined not_found.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule      $rule       The rule.
	 * @param MatrixRow $cell       A cell that declares query_refund.
	 * @param bool      $searchable Whether the gateway's profile says its provider can be searched by the store's uuid.
	 */
	private function refundsNeverMade( Rule $rule, MatrixRow $cell, bool $searchable ): void {
		$this->begin( 'rule08-refund-not-found-' . self::cellName( $cell ) );

		$captured = $this->place( $rule, Scenario::Approve, $cell );
		$this->capture( $captured['intent_uuid'] );
		$ofIt   = $this->intentRow( $captured['intent_uuid'] );
		$unseen = $this->ref( $this->openIntent( $cell->currency->code() ) );
		$asked  = array(
			'an intent the provider knows' => new GatewayRefund( $captured['intent_uuid'], $ofIt['provider_intent_id'], Money::of( 1, $cell->currency ), $this->seeded->generate(), Mode::Test ),
			'an intent it never saw'       => new GatewayRefund( $unseen->uuid, null, Money::of( 1, $cell->currency ), $this->seeded->generate(), Mode::Test ),
		);

		foreach ( $asked as $of => $never ) {
			$answer = $this->gateway()->queryRefund( $never );
			$this->assertTrue( null === $answer || ( Operation::Refund === $answer->operation && Outcome::Declined === $answer->outcome && PaymentGateway::NOT_FOUND === $answer->errorCode ), $rule->message() . sprintf( 'queryRefund() of a refund of %1$s that the provider never made answered %2$s: only nothing or a declined refund `not_found` is true of it.', $of, null === $answer ? 'nothing' : self::describeResult( $answer ) ) );
			$this->assertTrue( null === $answer || $searchable, $rule->message() . 'queryRefund() answered not_found though the gateway\'s profile says its provider cannot be searched by the store\'s uuid.' );
		}

		$this->end();
	}

	/**
	 * Asks the gateway where a placed intent stands, as the plugin asks it.
	 *
	 * @since 0.2.0
	 *
	 * @param string    $intentUuid The intent.
	 * @param MatrixRow $cell       The cell it was placed in.
	 * @return GatewayResult|null The gateway's answer.
	 */
	protected function queried( string $intentUuid, MatrixRow $cell ): ?GatewayResult {
		$intent = $this->intentRow( $intentUuid );

		return $this->gateway()->query( new PaymentQuery( $intentUuid, $intent['provider_intent_id'], Money::of( $intent['amount_minor'], $cell->currency ), Mode::Test ) );
	}

	/**
	 * Returns the void the plugin would send for an authorized intent, as a person voids it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return VoidRequest The request.
	 */
	protected function voidRequestOf( string $intentUuid ): VoidRequest {
		$intent = $this->intentRow( $intentUuid );

		return new VoidRequest( $intentUuid, $intent['provider_intent_id'], Money::of( $intent['authorized_minor'], Currency::of( $intent['currency'] ) ), Mode::Test, VoidReason::CustomerRequest->value );
	}

	/**
	 * Moves an intent's creation back by the database's clock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @param int    $seconds    How far.
	 */
	protected function ageIntent( string $intentUuid, int $seconds ): void {
		$this->db->execute( 'UPDATE %i SET created_at = created_at - INTERVAL %d SECOND, updated_at = updated_at - INTERVAL %d SECOND WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $seconds, $seconds, $intentUuid );
	}

	/**
	 * Ends an intent's wait by the database's clock, and ages it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @param int    $age        How far its creation and last change are moved back.
	 */
	protected function endWait( string $intentUuid, int $age = 0 ): void {
		$this->db->execute( 'UPDATE %i SET customer_action_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intentUuid );

		if ( $age > 0 ) {
			$this->ageIntent( $intentUuid, $age );
		}
	}

	/**
	 * Reads an intent's row.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return array{status: string, provider_intent_id: string|null, currency: string, amount_minor: int, authorized_minor: int, captured_minor: int, refunded_minor: int} The row.
	 */
	protected function intentRow( string $intentUuid ): array {
		$row = (array) $this->db->fetchRow( 'SELECT status, provider_intent_id, currency, amount_minor, authorized_minor, captured_minor, refunded_minor FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intentUuid );

		return array(
			'status'             => (string) $row['status'],
			'provider_intent_id' => null === $row['provider_intent_id'] ? null : (string) $row['provider_intent_id'],
			'currency'           => (string) $row['currency'],
			'amount_minor'       => (int) $row['amount_minor'],
			'authorized_minor'   => (int) $row['authorized_minor'],
			'captured_minor'     => (int) $row['captured_minor'],
			'refunded_minor'     => (int) $row['refunded_minor'],
		);
	}

	/**
	 * Reads an order's statuses and payment amounts.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return array{status: string, payment_status: string, paid_minor: int, refunded_minor: int} The row.
	 */
	protected function orderRow( int $orderId ): array {
		$row = (array) $this->db->fetchRow( 'SELECT status, payment_status, paid_minor, refunded_minor FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId );

		return array(
			'status'         => (string) $row['status'],
			'payment_status' => (string) $row['payment_status'],
			'paid_minor'     => (int) $row['paid_minor'],
			'refunded_minor' => (int) $row['refunded_minor'],
		);
	}

	/**
	 * Reads an order's payment ledger, provider ids aside.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<array{0: string, 1: string, 2: int, 3: int, 4: string}> Each row's operation, result, applied, amount and currency.
	 */
	protected function ledgerShape( int $orderId ): array {
		return array_map(
			static fn( array $row ): array => array( (string) $row['operation'], (string) $row['result'], (int) $row['applied'], (int) $row['amount_minor'], (string) $row['currency'] ),
			$this->db->fetchAll( 'SELECT operation, result, applied, amount_minor, currency FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::TRANSACTIONS ), $orderId )
		);
	}

	/**
	 * Reads the provider object and result of an order's ledger row of an operation.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $orderId   The order.
	 * @param string $operation The operation.
	 * @return array{0: string|null, 1: string|null} The provider object id and the result; nulls when there is no such row.
	 */
	protected function ledgerRowOf( int $orderId, string $operation ): array {
		$row = $this->db->fetchRow( 'SELECT provider_object_id, result FROM %i WHERE order_id = %d AND operation = %s ORDER BY id DESC LIMIT 1', $this->table( PaymentTables::TRANSACTIONS ), $orderId, $operation );

		return null === $row ? array( null, null ) : array( null === $row['provider_object_id'] ? null : (string) $row['provider_object_id'], (string) $row['result'] );
	}

	/**
	 * Tells whether an order charged shipping.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return bool True when it has a shipping charge.
	 */
	private function hasShipping( int $orderId ): bool {
		return '0' !== (string) $this->db->fetchValue( "SELECT COUNT(*) FROM %i WHERE order_id = %d AND type = 'shipping'", $this->table( OrderTables::ADJUSTMENTS ), $orderId );
	}

	/**
	 * Returns the non-secret settings of the account a cell is tested with.
	 *
	 * @since 0.2.0
	 *
	 * @param MatrixRow $cell The cell.
	 * @return array<string, int|string|null> The settings, as the plugin passes them to isAvailable().
	 */
	private function accountSettings( MatrixRow $cell ): array {
		return $this->declares( GatewayDescriptor::ACCOUNT_COUNTRY ) ? array( GatewayDescriptor::ACCOUNT_COUNTRY => self::accountOf( $cell ) ) : array();
	}

	/**
	 * Tells whether the gateway declares a setting.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The setting's name.
	 * @return bool True when it does.
	 */
	private function declares( string $name ): bool {
		foreach ( $this->descriptor()->settings as $field ) {
			if ( $field->name() === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the person who captures, voids and refunds.
	 *
	 * @since 0.2.0
	 *
	 * @return Actor The person.
	 */
	private function agent(): Actor {
		$this->agent ??= $this->userGranted( PaymentService::CAPTURE_CAPABILITY, PaymentService::VOID_CAPABILITY, RefundService::CAPABILITY );

		return $this->agent;
	}

	/**
	 * Returns the account country a cell is tested with.
	 *
	 * @since 0.2.0
	 *
	 * @param MatrixRow $cell The cell.
	 * @return string The row's country, or ANY_ACCOUNT for a `*` row.
	 */
	private static function accountOf( MatrixRow $cell ): string {
		return MatrixRow::ANY_COUNTRY === $cell->accountCountry ? self::ANY_ACCOUNT : $cell->accountCountry;
	}

	/**
	 * Names a cell for a scenario and a message: its currency and country, lower case.
	 *
	 * @since 0.2.0
	 *
	 * @param MatrixRow $cell The cell.
	 * @return string For example `usd-any`.
	 */
	private static function cellName( MatrixRow $cell ): string {
		return strtolower( $cell->currency->code() . '-' . ( MatrixRow::ANY_COUNTRY === $cell->accountCountry ? 'any' : $cell->accountCountry ) );
	}

	/**
	 * Tells whether a result says the provider has no record of an intent.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The result.
	 * @return bool True for a declined authorization `not_found`.
	 */
	private static function saysNotFound( GatewayResult $result ): bool {
		return Operation::Authorize === $result->operation && Outcome::Declined === $result->outcome && PaymentGateway::NOT_FOUND === $result->errorCode;
	}

	/**
	 * Describes a result in a few words, for a message.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The result.
	 * @return string For example "a declined authorize (card_declined)".
	 */
	private static function describeResult( GatewayResult $result ): string {
		return sprintf( 'a %1$s %2$s%3$s', $result->outcome->value, $result->operation->value, null === $result->errorCode ? '' : ' (' . $result->errorCode . ')' );
	}

	/**
	 * Describes an exception's causes, for a message.
	 *
	 * @since 0.2.0
	 *
	 * @param \Throwable $failure The exception.
	 * @return string Each cause's class and message, after "Caused by"; empty when it has none.
	 */
	private static function causes( \Throwable $failure ): string {
		$causes = '';

		for ( $cause = $failure->getPrevious(); null !== $cause; $cause = $cause->getPrevious() ) {
			$causes .= sprintf( ' Caused by %1$s: %2$s', get_class( $cause ), $cause->getMessage() );
		}

		return $causes;
	}
}

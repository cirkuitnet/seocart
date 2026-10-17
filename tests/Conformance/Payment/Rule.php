<?php
/**
 * Rule: the rules a payment gateway is held to
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * The rules of the payment gateway conformance suite.
 *
 * Owns one fact: the list of rules, each with its title, what the suite asserts, where that is
 * proved and the planted gateway that proves the assertion fails when the rule is broken. The
 * suite's failure messages, the plant tests and the generated capabilities page read it; nothing
 * else lists the rules.
 *
 * @since 0.2.0
 */
enum Rule: int {

	case LedgerShape               = 1;
	case MatrixMatchesBehaviour    = 2;
	case AvailabilityNeverLooser   = 3;
	case NoCardFields              = 4;
	case AmountsEchoed             = 5;
	case KeysAsRuled               = 6;
	case AlreadyDoneAnswersTheSame = 7;
	case NotFoundAuthority         = 8;
	case Expiry                    = 9;
	case WebhooksVerifiedFirst     = 10;
	case NextActionShape           = 11;
	case EveryCallThroughTheClient = 12;
	case NoRawResponseLogged       = 13;
	case CredentialsStay           = 14;
	case DescribeIsData            = 15;
	case ModeCredentials           = 16;
	case VoidAnswersTheApproval    = 17;
	case IdleBudget                = 18;
	case VoidReportsTheAmount      = 19;

	/**
	 * Where a rule is proved on a tape the gateway's provider answered.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ON_THE_TAPE = 'the suite, on the tape';

	/**
	 * Where a rule is proved without a tape.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WITHOUT_A_TAPE = 'the suite, without a tape';

	/**
	 * Where a rule is proved without WordPress.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNIT_GROUP = 'the suite\'s unit group';

	/**
	 * Returns the rule's title.
	 *
	 * @since 0.2.0
	 *
	 * @return string The title, in lower case.
	 */
	public function title(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::LedgerShape               => 'ledger shape',
			self::MatrixMatchesBehaviour    => 'matrix matches behaviour',
			self::AvailabilityNeverLooser   => 'availability never looser',
			self::NoCardFields              => 'no card fields',
			self::AmountsEchoed             => 'amount and currency echoed',
			self::KeysAsRuled               => 'idempotency keys as ruled',
			self::AlreadyDoneAnswersTheSame => 'already done answers the same',
			self::NotFoundAuthority         => 'not-found authority',
			self::Expiry                    => 'expiry',
			self::WebhooksVerifiedFirst     => 'webhooks verified before parsed',
			self::NextActionShape           => 'next action shape',
			self::EveryCallThroughTheClient => 'every call through the client at depth 0',
			self::NoRawResponseLogged       => 'no raw response or card data in a log',
			self::CredentialsStay           => 'credentials never leave',
			self::DescribeIsData            => 'describe() is data',
			self::ModeCredentials           => 'mode',
			self::VoidAnswersTheApproval    => 'void answers an approval already given',
			self::IdleBudget                => 'idle budget of the extension',
			self::VoidReportsTheAmount      => 'void reports the amount it released',
		};
	}

	/**
	 * Returns what the suite asserts, in one sentence.
	 *
	 * @since 0.2.0
	 *
	 * @return string The assertion.
	 */
	public function asserts(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::LedgerShape               => 'After an authorization, a capture, a partial refund and a full refund, the payment ledger, the intent and the order hold exactly the rows and amounts those operations make, and each outcome names its own object, one the provider\'s answer holds and never the long-lived intent.',
			self::MatrixMatchesBehaviour    => 'Every operation a cell of the matrix declares succeeds; every optional operation it does not declare, and every operation the plugin can ask in a currency it has no row for (authorize, capture, void, query and refund), is refused before any request; a gateway that declares no `sca` never asks the customer to act.',
			self::AvailabilityNeverLooser   => 'The gateway is never offered where its cell does not declare `authorize`, and `isAvailable()` answers from its context alone: no request, no query, the same answer twice.',
			self::NoCardFields              => 'No property or parameter of the contract\'s classes or of the gateway\'s own is named like card or bank account data.',
			self::AmountsEchoed             => 'Every answer reports the amount and currency it was asked for.',
			self::KeysAsRuled               => 'Every request that moves money carries the idempotency key the plugin derived for its operation, its request\'s own (a capture\'s and a void\'s are not the intent\'s), in a header or its body, never only in its URL, for a provider without keys too, and a request asked again carries the same.',
			self::AlreadyDoneAnswersTheSame => 'A capture or void asked again after its answer was lost answers the same provider object and outcome.',
			self::NotFoundAuthority         => 'A provider that never saw an intent or made a refund answers nothing or a not-found, the latter only when it can be searched; the plugin acts on a not-found only after the search delay; an intent the provider authorized is never not found, one it declined is answered declined, and one it is still deciding nothing or pending.',
			self::Expiry                    => 'An intent the provider cancelled once its wait ended is answered `expired`, and the placement ends as a decline does.',
			self::WebhooksVerifiedFirst     => 'A delivery with a bad signature, an old or future timestamp, or a malformed body with a bad signature is rejected without an exception; the same delivery read twice gives the same event id; a payment event reads as its result, and any other event as ignored; and the store\'s webhook route, given the same deliveries, decides each as the gateway read it, by what each does to its event\'s receipt: a rejected one refused with no receipt changed, an ignored one kept in a receipt of its own as ignored for its type, a result settled, and the same delivery sent again changes nothing.',
			self::NextActionShape           => 'A next action is a redirect to an https page (or straight back to the store\'s return URL) that holds no credential and no card number, or a step of the provider\'s script with its handle.',
			self::EveryCallThroughTheClient => 'Every request goes through the HTTP client the plugin gives the gateway, outside any transaction: the gateway\'s source calls no other network API, and each payment operation (an authorization, a capture, each refund, a void) sends a request of its own through the client.',
			self::NoRawResponseLogged       => 'No line the gateway logs holds a provider\'s answer, the provider\'s words in one (such as an error\'s message or a one-word status, found however the line encodes, cases or splits them) unless the gateway\'s test declares them one of the provider\'s codes, a card field or a card number.',
			self::CredentialsStay           => 'No request URL, header but `Authorization`, or log line holds a credential, and no tape holds a credential, a value shaped like a secret, an opaque token in no public shape the gateway\'s test declares, or card data.',
			self::DescribeIsData            => 'describe() calls no WordPress function, does no I/O and translates nothing.',
			self::ModeCredentials           => 'A test-mode payment\'s requests carry the test credential and a live-mode payment\'s the live one, never the other.',
			self::VoidAnswersTheApproval    => 'A void the plugin sends when the customer\'s time to act ran out, after the provider had already approved, answers that authorization\'s approval with its charge.',
			self::IdleBudget                => 'With the extension active, a request that needs no gateway costs at most one more hook, one more file and no query.',
			self::VoidReportsTheAmount      => 'An approved void reports the request\'s amount as the amount it moved: what the cancellation released; it names the provider\'s cancellation, or the intent where the provider makes none.',
		};
	}

	/**
	 * Returns where the rule is proved.
	 *
	 * @since 0.2.0
	 *
	 * @return string The proof.
	 */
	public function proof(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::NoCardFields, self::DescribeIsData                 => self::UNIT_GROUP,
			self::AvailabilityNeverLooser, self::ModeCredentials     => self::WITHOUT_A_TAPE,
			self::IdleBudget                                         => 'the extension kit: `sh bin/ci/extension.sh idle <extension root>`',
			self::EveryCallThroughTheClient                          => self::ON_THE_TAPE . ', and ' . self::UNIT_GROUP . ' for the source',
			default                                                  => self::ON_THE_TAPE,
		};
	}

	/**
	 * Returns the planted gateways that break the rule, each of which the suite must fail.
	 *
	 * A plant that implements Plants\SourcePlant breaks the rule in its source, which the suite
	 * reads; the others break it at run time.
	 *
	 * @since 0.2.0
	 *
	 * @return list<class-string> The plants' classes; none for the idle budget, whose plants are the kit's.
	 */
	public function plants(): array {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::LedgerShape               => array( Plants\CaptureReportedAsAuthorization::class, Plants\ObjectIsTheIntent::class ),
			self::MatrixMatchesBehaviour    => array( Plants\PartialRefundRefundsAll::class ),
			self::AvailabilityNeverLooser   => array( Plants\AvailabilityAsksProvider::class ),
			self::NoCardFields              => array( Plants\CardNumberProperty::class ),
			self::AmountsEchoed             => array( Plants\AmountInAnotherCurrency::class ),
			self::KeysAsRuled               => array( Plants\FreshKeyPerCall::class, Plants\IdempotencyKeyInUrl::class, Plants\KeylessSendsTheUuid::class ),
			self::AlreadyDoneAnswersTheSame => array( Plants\AlreadyCapturedThrows::class ),
			self::NotFoundAuthority         => array( Plants\NotFoundAtOnce::class ),
			self::Expiry                    => array( Plants\NeverExpires::class ),
			self::WebhooksVerifiedFirst     => array( Plants\DecodesBeforeVerifying::class, Plants\AnswersARedeliveryIgnored::class ),
			self::NextActionShape           => array( Plants\SecretInActionUrl::class ),
			self::EveryCallThroughTheClient => array( Plants\DirectWordPressCall::class, Plants\AnswersWithoutTheClient::class, Plants\CapturesPastTheClient::class, Plants\CurlCall::class, Plants\CurlByName::class, Plants\CurlImported::class ),
			self::NoRawResponseLogged       => array( Plants\LogsTheResponse::class, Plants\LogsTheErrorMessage::class, Plants\LogsTheMessageEncoded::class, Plants\LogsTheMessageInWords::class ),
			self::CredentialsStay           => array( Plants\KeyInQuery::class ),
			self::DescribeIsData            => array( Plants\ReadsAnOption::class ),
			self::ModeCredentials           => array( Plants\IgnoresMode::class ),
			self::VoidAnswersTheApproval    => array( Plants\VoidAfterApprovalFails::class ),
			self::IdleBudget                => array(),
			self::VoidReportsTheAmount      => array( Plants\VoidReportsZero::class ),
		};
	}

	/**
	 * Returns the test method of GatewayConformanceCase that proves the rule.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The method; null for the rules proved elsewhere (the unit group, the kit).
	 */
	public function test(): ?string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::NoCardFields, self::DescribeIsData, self::IdleBudget => null,
			// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
			default => sprintf( 'test_rule_%02d_%s', $this->value, $this->snakeName() ),
		};
	}

	/**
	 * Returns how a failure of the rule begins.
	 *
	 * @since 0.2.0
	 *
	 * @return string "Conformance rule N (title): ".
	 */
	public function message(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return sprintf( 'Conformance rule %1$d (%2$s): ', $this->value, $this->title() );
	}

	/**
	 * Returns the case's name in snake form, the end of its test method's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string For example "ledger_shape".
	 */
	private function snakeName(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $this->name ) );
	}
}

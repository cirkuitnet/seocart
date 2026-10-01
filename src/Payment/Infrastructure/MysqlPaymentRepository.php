<?php
/**
 * MysqlPaymentRepository: every statement of the payment tables, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure;

use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\PaymentIntent;
use SEOCart\Payment\Domain\PaymentRepository;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Support\Currency;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The payment repository on MySQL: the one class that sends SQL to the payment tables.
 *
 * Owns one fact: the text of every payment statement, as public constants, so a concurrency test
 * sends exactly the statement this class sends, and a test can read from the constants alone that
 * no statement changes a ledger row or names another module's table.
 *
 * An intent's state changes only through the APPLY_ statements and the two that make it wait,
 * each a conditional update whose `{list}` is IntentTransitions::allowedFrom() for its target, so
 * the database refuses a transition the table does not allow. An approval's statement also
 * requires the intent's currency to be the result's and its base currency to be the order's, and
 * a capture or a refund carries its amount cap, in the same WHERE clause: the database checks
 * again what the service checked before it. `updated_at`
 * always moves forward, so one affected row means the WHERE clause matched. The ledger insert is
 * the claim that applies a result once: its unique key is the provider, the provider object and
 * the operation, and a duplicate is reported, never raised.
 *
 * The reads for `doctor` live here too, because they are payment SQL; they are not part of the
 * port the service sees.
 *
 * @since 0.1.0
 */
final class MysqlPaymentRepository implements PaymentRepository {

	/**
	 * An intent, in its first state.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_INTENT = "INSERT INTO {payment_intents} SET uuid = %s, order_id = %d, gateway_id = %s, status = 'created', amount_minor = %d, currency = %s, "
		. 'conversion_context_id = %d, base_currency = %s, base_amount_minor = %d, created_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)';

	/**
	 * The intent's lock: a locking read of what applying a result decides from, and the first lock of the transaction that applies it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCK_INTENT = 'SELECT id, uuid, order_id, gateway_id, status, amount_minor, currency, base_amount_minor, base_currency, conversion_context_id, '
		. 'authorized_minor, captured_minor, refunded_minor, provider_intent_id FROM {payment_intents} WHERE uuid = %s FOR UPDATE';

	/**
	 * The intent, read without a lock before a gateway call.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_INTENT = 'SELECT id, uuid, order_id, gateway_id, status, amount_minor, currency, base_amount_minor, base_currency, conversion_context_id, '
		. 'authorized_minor, captured_minor, refunded_minor, provider_intent_id FROM {payment_intents} WHERE uuid = %s';

	/**
	 * One ledger row of an intent that the projection refused, if it has any, on the `intent_created` key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNAPPLIED_OF_INTENT = 'SELECT id FROM {payment_transactions} WHERE intent_id = %d AND applied = 0 LIMIT 1';

	/**
	 * A ledger row: the claim that applies a result once. 0 and '' stand for no settlement, no object, no error, no actor and no correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_TRANSACTION = 'INSERT INTO {payment_transactions} SET uuid = %s, intent_id = %d, order_id = %d, operation = %s, amount_minor = %d, currency = %s, '
		. 'conversion_context_id = %d, base_currency = %s, base_amount_minor = %d, '
		. "settlement_currency = NULLIF( %s, '' ), settlement_amount_minor = IF( %d = 1, %d, NULL ), settlement_rate = NULLIF( %s, '' ), "
		. "settlement_fee_minor = IF( %d = 1, %d, NULL ), settlement_source = NULLIF( %s, '' ), "
		. "provider = %s, provider_object_id = NULLIF( %s, '' ), result = %s, applied = %d, error_code = NULLIF( %s, '' ), "
		. "actor_type = %s, actor_id = NULLIF( %d, 0 ), correlation_id = NULLIF( %s, '' ), created_at = UTC_TIMESTAMP(6)";

	/**
	 * The row a result was first recorded in, by the key that claims it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_TRANSACTION = 'SELECT id FROM {payment_transactions} WHERE provider = %s AND provider_object_id = %s AND operation = %s';

	/**
	 * An authorization: the intent authorized for the amount, with the provider's reference recorded if none was.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPLY_AUTHORIZE = "UPDATE {payment_intents} SET status = 'authorized', authorized_minor = authorized_minor + %d, base_authorized_minor = base_authorized_minor + %d, "
		. "provider_intent_id = COALESCE( provider_intent_id, NULLIF( %s, '' ) ), updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) "
		. 'WHERE id = %d AND currency = %s AND base_currency = %s AND status IN ({list})';

	/**
	 * A capture: never more than was authorized, or, for an intent the gateway captured while processing, than the intent's amount.
	 *
	 * An intent the gateway authorized and captured at once goes from processing to captured with
	 * nothing authorized before, so its cap is its frozen amount; the WHERE clause reads the state
	 * the row is in before the update.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPLY_CAPTURE = "UPDATE {payment_intents} SET status = 'captured', captured_minor = captured_minor + %d, base_captured_minor = base_captured_minor + %d, "
		. 'updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) '
		. "WHERE id = %d AND currency = %s AND base_currency = %s AND status IN ({list}) AND captured_minor + %d <= IF( status = 'processing', amount_minor, authorized_minor )";

	/**
	 * A refund: of more than nothing, and never more than was captured, in either currency; the intent is refunded once everything captured is.
	 *
	 * The status is assigned first, so it reads refunded_minor before the refund is added: MySQL
	 * evaluates a single-table UPDATE's assignments from left to right.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPLY_REFUND = "UPDATE {payment_intents} SET status = IF( refunded_minor + %d >= captured_minor, 'refunded', 'partially_refunded' ), "
		. 'refunded_minor = refunded_minor + %d, base_refunded_minor = base_refunded_minor + %d, updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) '
		. 'WHERE id = %d AND currency = %s AND base_currency = %s AND status IN ({list}) AND %d > 0 AND captured_minor - refunded_minor >= %d AND base_captured_minor - base_refunded_minor >= %d';

	/**
	 * A decline: the intent failed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPLY_DECLINE = "UPDATE {payment_intents} SET status = 'failed', updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE id = %d AND status IN ({list})";

	/**
	 * The customer must act: the intent waits for them until a time set by the database clock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REQUIRE_ACTION = "UPDATE {payment_intents} SET status = 'requires_action', provider_intent_id = COALESCE( provider_intent_id, NULLIF( %s, '' ) ), "
		. 'customer_action_expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND, updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) '
		. 'WHERE id = %d AND status IN ({list})';

	/**
	 * The gateway is still deciding: the intent waits for it until a time set by the database clock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MARK_PROCESSING = "UPDATE {payment_intents} SET status = 'processing', provider_intent_id = COALESCE( provider_intent_id, NULLIF( %s, '' ) ), "
		. 'customer_action_expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND, updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) '
		. 'WHERE id = %d AND status IN ({list})';

	/**
	 * A page of the intents in some states that have not changed for a while by the database clock, in the order they were created, after the uuid of the page before, each with when its wait runs out and whether it has.
	 *
	 * Uuids are time-ordered, so the uuid order is the order of creation, and a caller that pages
	 * from the last uuid it read reaches every waiting intent, however many the gateway never answers.
	 * Whether a wait has run out is judged by the database clock, the one that set it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STALE_INTENTS = 'SELECT uuid, order_id, status, provider_intent_id, amount_minor, currency, customer_action_expires_at, customer_action_expires_at <= UTC_TIMESTAMP() AS expired '
		. 'FROM {payment_intents} WHERE status IN ({list}) AND updated_at < UTC_TIMESTAMP(6) - INTERVAL %d SECOND AND uuid > %s ORDER BY uuid LIMIT %d';

	/**
	 * A page of intents by the primary key, after the last id of the page before, each with what its applied, approved ledger rows add up to by operation, in both currencies.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INTENT_LEDGER = 'SELECT i.id, i.uuid, i.authorized_minor, i.captured_minor, i.refunded_minor, i.base_authorized_minor, i.base_captured_minor, i.base_refunded_minor, '
		. "COALESCE( SUM( IF( t.operation = 'authorize', t.amount_minor, 0 ) ), 0 ) AS authorized_sum, COALESCE( SUM( IF( t.operation = 'capture', t.amount_minor, 0 ) ), 0 ) AS captured_sum, "
		. "COALESCE( SUM( IF( t.operation = 'refund', t.amount_minor, 0 ) ), 0 ) AS refunded_sum, COALESCE( SUM( IF( t.operation = 'authorize', t.base_amount_minor, 0 ) ), 0 ) AS base_authorized_sum, "
		. "COALESCE( SUM( IF( t.operation = 'capture', t.base_amount_minor, 0 ) ), 0 ) AS base_captured_sum, COALESCE( SUM( IF( t.operation = 'refund', t.base_amount_minor, 0 ) ), 0 ) AS base_refunded_sum "
		. "FROM {payment_intents} i LEFT JOIN {payment_transactions} t ON t.intent_id = i.id AND t.result = 'approved' AND t.applied = 1 WHERE i.id > %d GROUP BY i.id ORDER BY i.id LIMIT %d";

	/**
	 * Ledger rows the projection refused, with their intents: money a person must reconcile.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNAPPLIED_RESULTS = 'SELECT t.uuid, t.operation, i.uuid AS intent_uuid FROM {payment_transactions} t JOIN {payment_intents} i ON i.id = t.intent_id WHERE t.applied = 0 ORDER BY t.id LIMIT %d';

	/**
	 * What some orders' intents add up to, in both currencies.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ORDER_SUMS = 'SELECT order_id, SUM( authorized_minor ) AS authorized_sum, SUM( captured_minor ) AS captured_sum, SUM( refunded_minor ) AS refunded_sum, '
		. 'SUM( base_authorized_minor ) AS base_authorized_sum, SUM( base_captured_minor ) AS base_captured_sum, SUM( base_refunded_minor ) AS base_refunded_sum '
		. 'FROM {payment_intents} WHERE order_id IN ({list}) GROUP BY order_id';

	/**
	 * A page of refunds by the primary key, after the last id of the page before: the tax each states beside what its components returned, and the net it states its lines returned beside what they did, in both currencies.
	 *
	 * A refund's lines returned its total less its shipping, fees and tax, before tax; the database
	 * works that out from the header. Each sum is one read of the refund's own rows, on the
	 * `refund_line` and `refund_component` keys, which begin with the refund.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REFUND_SUMS = 'SELECT r.id, r.uuid, r.tax_minor AS tax, r.base_tax_minor AS base_tax, '
		. 'r.total_minor - r.shipping_minor - r.fee_minor - r.tax_minor AS lines_net, r.base_total_minor - r.base_shipping_minor - r.base_fee_minor - r.base_tax_minor AS base_lines_net, '
		. '( SELECT COALESCE( SUM( refund_component.tax_minor ), 0 ) FROM {refund_components} refund_component WHERE refund_component.refund_id = r.id ) AS tax_sum, '
		. '( SELECT COALESCE( SUM( refund_component.base_tax_minor ), 0 ) FROM {refund_components} refund_component WHERE refund_component.refund_id = r.id ) AS base_tax_sum, '
		. '( SELECT COALESCE( SUM( refund_line.net_minor ), 0 ) FROM {refund_lines} refund_line WHERE refund_line.refund_id = r.id ) AS lines_net_sum, '
		. '( SELECT COALESCE( SUM( refund_line.base_net_minor ), 0 ) FROM {refund_lines} refund_line WHERE refund_line.refund_id = r.id ) AS base_lines_net_sum '
		. 'FROM {refunds} r WHERE r.id > %d ORDER BY r.id LIMIT %d';

	/**
	 * The units the refunds returned of some order lines, on the `order_line_id` key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REFUNDED_UNITS = 'SELECT order_line_id, SUM( quantity ) AS quantity FROM {refund_lines} WHERE order_line_id IN ({list}) GROUP BY order_line_id';

	/**
	 * The module's name, in the messages of a statement that names another module's table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'payment';

	/**
	 * Sends the statements, over the payment tables only.
	 *
	 * @since 0.1.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Mints the uuids of the ledger rows.
	 *
	 * @since 0.1.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database    $db  The connection.
	 * @param IdGenerator $ids Mints the uuids of the ledger rows.
	 */
	public function __construct( Database $db, IdGenerator $ids ) {
		$this->statements = new ModuleStatements( $db, self::MODULE, PaymentTables::moduleNames() );
		$this->ids        = $ids;
	}

	/**
	 * Turns a statement's tokens into wpdb placeholders and its values into arguments, in order, over the payment module's tables.
	 *
	 * The plugin's one token expansion (ModuleStatements::expand()); a concurrency test prepares
	 * the statement connection B sends with it, from the same constant.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a token names no payment table, or the values do not match the placeholders.
	 *
	 * @param string   $statement One of this class's constants.
	 * @param array    $values    The values, in placeholder order.
	 * @param callable $tableName Returns a table's full name from its unprefixed name (string).
	 * @return array{0: string, 1: list<mixed>} The statement with wpdb placeholders, and its arguments.
	 *
	 * @phpstan-param list<mixed>              $values
	 * @phpstan-param callable(string): string $tableName
	 */
	public static function expand( string $statement, array $values, callable $tableName ): array {
		return ModuleStatements::expand( $statement, $values, PaymentTables::moduleNames(), $tableName, self::MODULE );
	}

	/**
	 * Inserts an intent in its first state, with its amounts frozen.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $orderId             The order it pays for.
	 * @param string $uuid                Its public identifier.
	 * @param string $gatewayId           The gateway it is paid through.
	 * @param Money  $amount              Its amount, in the order's currency.
	 * @param Money  $baseAmount          Its amount in the base currency, at the order's rate.
	 * @param int    $conversionContextId The rate it is frozen at.
	 * @return int The intent's id.
	 */
	public function insertIntent( int $orderId, string $uuid, string $gatewayId, Money $amount, Money $baseAmount, int $conversionContextId ): int {
		$this->statements->requireTransaction( __METHOD__ );
		$this->statements->execute( self::INSERT_INTENT, $uuid, $orderId, $gatewayId, $amount->minorUnits(), $amount->currency()->code(), $conversionContextId, $baseAmount->currency()->code(), $baseAmount->minorUnits() );

		return $this->statements->lastInsertId();
	}

	/**
	 * Reads an intent with a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The intent's public identifier.
	 * @return PaymentIntent|null The intent, or null when there is none.
	 */
	public function lock( string $uuid ): ?PaymentIntent {
		$this->statements->requireTransaction( __METHOD__ );

		$row = $this->statements->rows( self::LOCK_INTENT, $uuid )[0] ?? null;

		return null === $row ? null : self::intent( $row );
	}

	/**
	 * Reads an intent without locking it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The intent's public identifier.
	 * @return PaymentIntent|null The intent, or null when there is none.
	 */
	public function find( string $uuid ): ?PaymentIntent {
		$row = $this->statements->rows( self::FIND_INTENT, $uuid )[0] ?? null;

		return null === $row ? null : self::intent( $row );
	}

	/**
	 * Tells whether an intent has a ledger row the projection refused.
	 *
	 * @since 0.1.0
	 *
	 * @param int $intentId The intent.
	 * @return bool True when it has one.
	 */
	public function hasUnappliedResult( int $intentId ): bool {
		return array() !== $this->statements->rows( self::UNAPPLIED_OF_INTENT, $intentId );
	}

	/**
	 * Appends a gateway result to the ledger; a result already recorded is reported, not raised.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentIntent $intent        The intent the result is about, locked.
	 * @param GatewayResult $result        The result.
	 * @param Money         $baseAmount    The result's amount in the order's base currency; zero when it has none.
	 * @param bool          $applied       Whether the result moves the projection.
	 * @param string        $actorType     `user` or `system`.
	 * @param int|null      $actorId       The user on whose authority, or null.
	 * @param string        $correlationId The request's correlation id.
	 * @return int|null The row's id; null when the result's provider, object and operation were recorded before.
	 */
	public function appendResult( PaymentIntent $intent, GatewayResult $result, Money $baseAmount, bool $applied, string $actorType, ?int $actorId, string $correlationId ): ?int {
		$this->statements->requireTransaction( __METHOD__ );

		$settlement = $result->settlement;

		try {
			$this->statements->execute(
				self::INSERT_TRANSACTION,
				$this->ids->generate(),
				$intent->id,
				$intent->orderId,
				$result->operation->value,
				$result->amount->minorUnits(),
				$result->amount->currency()->code(),
				$intent->conversionContextId,
				$baseAmount->currency()->code(),
				$baseAmount->minorUnits(),
				// The provider's settlement, when it reported one: the flag makes the amounts NULL otherwise.
				null === $settlement ? '' : $settlement->amount->currency()->code(),
				null === $settlement ? 0 : 1,
				null === $settlement ? 0 : $settlement->amount->minorUnits(),
				null === $settlement ? '' : $settlement->rate->toString(),
				null === $settlement ? 0 : 1,
				null === $settlement ? 0 : $settlement->fee->minorUnits(),
				null === $settlement ? '' : $settlement->source,
				$result->provider,
				$result->providerObjectId ?? '',
				$result->outcome->value,
				$applied ? 1 : 0,
				$result->errorCode ?? '',
				$actorType,
				$actorId ?? 0,
				$correlationId
			);
		} catch ( DuplicateKey $recorded ) {
			// The claim: this provider object's outcome for this operation has a row already.
			return null;
		}

		return $this->statements->lastInsertId();
	}

	/**
	 * Reads the row a result was first recorded in.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayResult $result The result.
	 * @return int|null The row's id, or null when the result has no row.
	 */
	public function findResult( GatewayResult $result ): ?int {
		if ( null === $result->providerObjectId ) {
			return null;
		}

		$row = $this->statements->rows( self::FIND_TRANSACTION, $result->provider, $result->providerObjectId, $result->operation->value )[0] ?? null;

		return null === $row ? null : (int) $row['id'];
	}

	/**
	 * Applies an approval to its intent, by its operation.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException For a void, which no statement applies yet.
	 *
	 * @param PaymentIntent $intent     The intent, locked.
	 * @param GatewayResult $result     The approval.
	 * @param Money         $baseAmount The amount in the order's base currency, which the intent's base currency must be.
	 * @return bool True when the intent changed; false when its state or its amounts refused the operation.
	 */
	public function applyApproval( PaymentIntent $intent, GatewayResult $result, Money $baseAmount ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		// The result's currency and the base amount's, the order's base currency: the WHERE clause holds the intent's row to them.
		$amount       = $result->amount->minorUnits();
		$base         = $baseAmount->minorUnits();
		$currency     = $result->amount->currency()->code();
		$baseCurrency = $baseAmount->currency()->code();

		return 1 === match ( $result->operation ) {
			Operation::Authorize => $this->statements->execute( self::APPLY_AUTHORIZE, $amount, $base, $result->providerIntentId ?? '', $intent->id, $currency, $baseCurrency, self::from( IntentStatus::Authorized ) ),
			Operation::Capture   => $this->statements->execute( self::APPLY_CAPTURE, $amount, $base, $intent->id, $currency, $baseCurrency, self::from( IntentStatus::Captured ), $amount ),
			Operation::Refund    => $this->statements->execute( self::APPLY_REFUND, $amount, $amount, $base, $intent->id, $currency, $baseCurrency, self::refundableFrom(), $amount, $amount, $base ),
			Operation::Void      => throw new \LogicException( 'No statement applies a void yet: voiding an intent arrives with the gateway call that makes one.' ),
		};
	}

	/**
	 * Fails an intent the gateway declined.
	 *
	 * @since 0.1.0
	 *
	 * @param int $intentId The intent.
	 * @return bool True when the intent changed; false when its state refused it.
	 */
	public function applyDecline( int $intentId ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		return 1 === $this->statements->execute( self::APPLY_DECLINE, $intentId, self::from( IntentStatus::Failed ) );
	}

	/**
	 * Moves an intent to a state that waits for the customer or the gateway.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException For a state that is not one that waits.
	 *
	 * @param int          $intentId         The intent.
	 * @param IntentStatus $to               IntentStatus::RequiresAction or IntentStatus::Processing.
	 * @param string|null  $providerIntentId The provider's reference to the intent.
	 * @param int          $actionSeconds    How long the wait may last, for the customer to act or the gateway to decide.
	 * @return bool True when the intent changed; false when its state refused it.
	 */
	public function await( int $intentId, IntentStatus $to, ?string $providerIntentId, int $actionSeconds ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		return 1 === match ( $to ) {
			IntentStatus::RequiresAction => $this->statements->execute( self::REQUIRE_ACTION, $providerIntentId ?? '', $actionSeconds, $intentId, self::from( $to ) ),
			IntentStatus::Processing     => $this->statements->execute( self::MARK_PROCESSING, $providerIntentId ?? '', $actionSeconds, $intentId, self::from( $to ) ),
			default                      => throw new \InvalidArgumentException( 'An intent waits only for the customer to act or for the gateway to decide.' ),
		};
	}

	/**
	 * Lists a page of the intents in some states that have not changed for a while, in the order they were created.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentStatus[] $states           The states.
	 * @param int            $olderThanSeconds How long they have not changed, at least, by the database's clock.
	 * @param string         $afterUuid        The uuid of the last intent of the page before, or '' for the first page.
	 * @param int            $limit            The most to list.
	 * @return list<IntentRef> The intents, each with when its wait runs out and whether it had by the database's clock.
	 *
	 * @phpstan-param list<IntentStatus> $states
	 */
	public function stale( array $states, int $olderThanSeconds, string $afterUuid, int $limit ): array {
		return array_map(
			static fn( array $row ): IntentRef => new IntentRef(
				(string) $row['uuid'],
				(int) $row['order_id'],
				IntentStatus::from( (string) $row['status'] ),
				null === $row['provider_intent_id'] ? null : (string) $row['provider_intent_id'],
				Money::of( (int) $row['amount_minor'], Currency::of( (string) $row['currency'] ) ),
				null === $row['customer_action_expires_at'] ? null : new \DateTimeImmutable( (string) $row['customer_action_expires_at'], new \DateTimeZone( 'UTC' ) ),
				'1' === (string) $row['expired']
			),
			$this->statements->rows( self::STALE_INTENTS, IntentTransitions::values( $states ), $olderThanSeconds, $afterUuid, $limit )
		);
	}

	/**
	 * Reads a page of intents, by id, each with its amounts and what its applied, approved ledger rows add up to.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId The last id of the page before, or 0 for the first page.
	 * @param int $limit   The most intents to read.
	 * @return list<array{id: int, uuid: string, amounts: array<string, int>, sums: array<string, int>}> The intents: each amount and its ledger sum, by column without `_minor`.
	 */
	public function intentLedger( int $afterId, int $limit ): array {
		$columns = array( 'authorized', 'captured', 'refunded', 'base_authorized', 'base_captured', 'base_refunded' );

		return array_map(
			static function ( array $row ) use ( $columns ): array {
				$amounts = array();
				$sums    = array();

				foreach ( $columns as $column ) {
					$amounts[ $column ] = (int) $row[ $column . '_minor' ];
					$sums[ $column ]    = (int) $row[ $column . '_sum' ];
				}

				return array(
					'id'      => (int) $row['id'],
					'uuid'    => (string) $row['uuid'],
					'amounts' => $amounts,
					'sums'    => $sums,
				);
			},
			$this->statements->rows( self::INTENT_LEDGER, $afterId, $limit )
		);
	}

	/**
	 * Lists the ledger rows the projection refused.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most rows to list.
	 * @return list<array{uuid: string, operation: string, intent_uuid: string}> The rows, oldest first.
	 */
	public function unappliedResults( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'uuid'        => (string) $row['uuid'],
				'operation'   => (string) $row['operation'],
				'intent_uuid' => (string) $row['intent_uuid'],
			),
			$this->statements->rows( self::UNAPPLIED_RESULTS, $limit )
		);
	}

	/**
	 * Adds up some orders' intents, in both currencies.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $orderIds The orders.
	 * @return array<int, array<string, int>> By order id, the sums by column: authorized, captured, refunded and their base twins. An order with no intent is absent.
	 *
	 * @phpstan-param list<int> $orderIds
	 */
	public function orderSums( array $orderIds ): array {
		$sums = array();

		foreach ( $this->statements->rows( self::ORDER_SUMS, $orderIds ) as $row ) {
			$sums[ (int) $row['order_id'] ] = array(
				'authorized'      => (int) $row['authorized_sum'],
				'captured'        => (int) $row['captured_sum'],
				'refunded'        => (int) $row['refunded_sum'],
				'base_authorized' => (int) $row['base_authorized_sum'],
				'base_captured'   => (int) $row['base_captured_sum'],
				'base_refunded'   => (int) $row['base_refunded_sum'],
			);
		}

		return $sums;
	}

	/**
	 * Reads a page of refunds, by id, each with the tax and the lines' net it states and what its components and its lines add up to.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId The last id of the page before, or 0 for the first page.
	 * @param int $limit   The most refunds to read.
	 * @return list<array{id: int, uuid: string, amounts: array<string, int>, sums: array<string, int>}> The refunds: by figure, `tax`, `lines_net` and their base twins, as stated and as added up.
	 */
	public function refundSums( int $afterId, int $limit ): array {
		$figures = array( 'tax', 'base_tax', 'lines_net', 'base_lines_net' );

		return array_map(
			static function ( array $row ) use ( $figures ): array {
				$amounts = array();
				$sums    = array();

				foreach ( $figures as $figure ) {
					$amounts[ $figure ] = (int) $row[ $figure ];
					$sums[ $figure ]    = (int) $row[ $figure . '_sum' ];
				}

				return array(
					'id'      => (int) $row['id'],
					'uuid'    => (string) $row['uuid'],
					'amounts' => $amounts,
					'sums'    => $sums,
				);
			},
			$this->statements->rows( self::REFUND_SUMS, $afterId, $limit )
		);
	}

	/**
	 * Adds up the units the refunds returned of some order lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $lineIds The order lines.
	 * @return array<int, int> The units, by order line id; a line with none is absent.
	 *
	 * @phpstan-param list<int> $lineIds
	 */
	public function refundedUnits( array $lineIds ): array {
		$units = array();

		foreach ( array() === $lineIds ? array() : $this->statements->rows( self::REFUNDED_UNITS, $lineIds ) as $row ) {
			$units[ (int) $row['order_line_id'] ] = (int) $row['quantity'];
		}

		return $units;
	}

	/**
	 * Returns the IN list of the states a target may be entered from.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentStatus $to The target.
	 * @return list<string> The states, as values.
	 */
	private static function from( IntentStatus $to ): array {
		return IntentTransitions::values( IntentTransitions::allowedFrom( $to ) );
	}

	/**
	 * Returns the IN list of the states a refund may apply to: those both of its targets may be entered from.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The states, as values.
	 */
	private static function refundableFrom(): array {
		return array_values( array_intersect( self::from( IntentStatus::PartiallyRefunded ), self::from( IntentStatus::Refunded ) ) );
	}

	/**
	 * Builds an intent from its row.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row The row.
	 * @return PaymentIntent The intent.
	 */
	private static function intent( array $row ): PaymentIntent {
		$currency = Currency::of( (string) $row['currency'] );
		$money    = static fn( mixed $minor ): Money => Money::of( (int) $minor, $currency );

		return new PaymentIntent(
			(int) $row['id'],
			(string) $row['uuid'],
			(int) $row['order_id'],
			(string) $row['gateway_id'],
			IntentStatus::from( (string) $row['status'] ),
			$money( $row['amount_minor'] ),
			Money::of( (int) $row['base_amount_minor'], Currency::of( (string) $row['base_currency'] ) ),
			(int) $row['conversion_context_id'],
			$money( $row['authorized_minor'] ),
			$money( $row['captured_minor'] ),
			$money( $row['refunded_minor'] ),
			null === $row['provider_intent_id'] ? null : (string) $row['provider_intent_id']
		);
	}
}

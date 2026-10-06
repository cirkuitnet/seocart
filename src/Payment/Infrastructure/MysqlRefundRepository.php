<?php
/**
 * MysqlRefundRepository: every statement a refund sends, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Domain\Refund\ClaimState;
use SEOCart\Payment\Domain\Refund\ComponentPortion;
use SEOCart\Payment\Domain\Refund\LinePortion;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundableIntent;
use SEOCart\Payment\Domain\Refund\RefundClaim;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundPlan;
use SEOCart\Payment\Domain\Refund\RefundRepository;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Refund\RequestKey;
use SEOCart\Payment\Domain\Refund\Share;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a caller's programming error to the developer; it is never HTML.

/**
 * The refund repository on MySQL: the one class that sends a refund's SQL.
 *
 * Owns one fact: the text of every refund statement, as public constants, so a concurrency test
 * sends exactly the statement this class sends, and a test can read from the constants alone
 * that no statement changes a refund row or names another module's table.
 *
 * The reads add up what earlier refunds returned, one read per kind of row whatever the number
 * of lines. The document's writes are inserts, each guarded by the cap it carries:
 *
 * - the document only while the shipping the order's refunds returned, with this one's, stays
 *   within the stored shipping, in both currencies;
 * - each tax component's share only while it keeps to the sign of the stored component and
 *   leaves no less than nothing of it, in gross and in base gross. The stored figures travel as
 *   values: `order_tax_components` is the order module's and is never changed once written, so
 *   the figures read from it hold; what earlier refunds returned of it is added up by the
 *   statement itself.
 *
 * A statement written for several rows joins one derived table of values, `( SELECT %d AS a, … )
 * AS name`, whose row ModuleStatements::forDerivedRows() repeats with UNION ALL once per row: one
 * statement whatever the number of lines or components.
 *
 * The claim is a plain insert, sent in a short transaction of its own under the intent's lock, so
 * that it commits before the gateway is asked; the unique key on its uuid refuses a second claim of
 * the same refund, and the one on its key hash a second claim with the same idempotency key. What
 * it asked of each line follows it, in one statement. It ends by one conditional update, inside the
 * transaction that records the gateway's answer, whose WHERE clause carries the state it leaves.
 * A user whose refunds are capped by the day has one lock row, which the claim's transaction takes
 * before anything else, then adds up what the user asked in the last 24 hours.
 *
 * @since 0.1.0
 * @since 0.2.0 The claim's request and key, its lines, and the user's lock row.
 */
final class MysqlRefundRepository implements RefundRepository {

	/**
	 * Whether the ledger holds a result of the intent `intent` applied to nothing, on the `intent_created` key: money a person must reconcile first.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UNAPPLIED_RESULT = 'EXISTS ( SELECT 1 FROM {payment_transactions} unapplied WHERE unapplied.intent_id = intent.id AND unapplied.applied = 0 ) AS has_unapplied_result';

	/**
	 * How many refunds of the intent `intent` the ledger holds declined, on the `intent_created` key: one of what a refund's uuid is named by.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DECLINED_REFUNDS = "( SELECT COUNT(*) FROM {payment_transactions} declined WHERE declined.intent_id = intent.id AND declined.operation = 'refund' AND declined.result = 'declined' ) AS declined_refunds";

	/**
	 * The uuid of the oldest refund claim of the intent `intent` still claimed, on the claims' `intent_state` key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const OPEN_CLAIM = "( SELECT open_claim.uuid FROM {refund_claims} open_claim WHERE open_claim.intent_id = intent.id AND open_claim.state = 'claimed' ORDER BY open_claim.id LIMIT 1 ) AS open_claim";

	/**
	 * An order's first intent in a state a refund applies to; whether the ledger holds a result of it applied to nothing; how many of its refunds were declined; and its oldest refund claim still claimed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REFUNDABLE_INTENT = 'SELECT intent.id, intent.uuid, intent.gateway_id, intent.mode, intent.provider_intent_id, intent.currency, intent.base_currency, intent.captured_minor, intent.refunded_minor, '
		. 'intent.base_captured_minor, intent.base_refunded_minor, '
		. self::UNAPPLIED_RESULT . ', ' . self::DECLINED_REFUNDS . ', ' . self::OPEN_CLAIM . ' '
		. 'FROM {payment_intents} intent WHERE intent.order_id = %d AND intent.status IN ({list}) ORDER BY intent.id LIMIT 1';

	/**
	 * An intent locked for a refund's claim, by the primary key: what it refunded, in both currencies, whether the ledger holds a result of it applied to nothing, how many of its refunds were declined, and its oldest refund claim still claimed, all read as they now stand.
	 *
	 * A locking read: it waits for any transaction that holds the intent, such as another refund's
	 * claim or the recording of a refund, and holds the intent until the claim's transaction ends.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCK_FOR_CLAIM = 'SELECT intent.refunded_minor, intent.base_refunded_minor, ' . self::UNAPPLIED_RESULT . ', ' . self::DECLINED_REFUNDS . ', ' . self::OPEN_CLAIM . ' FROM {payment_intents} intent WHERE intent.id = %d FOR UPDATE';

	/**
	 * A refund's claim, made before the gateway is asked: what is asked, of which intent, its base share, the shipping, the reason and the note, the caller's key and the request's fingerprint, by whom, and when, by the database clock.
	 *
	 * A plain insert, refused by the `uuid` unique key when the refund was claimed before, and by the
	 * `key_hash` one when a claim was made with the key before. 0 stands for no actor; an empty
	 * note, key or fingerprint for none.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The base share, the request and the key.
	 *
	 * @var string
	 */
	public const CLAIM = 'INSERT INTO {refund_claims} ( uuid, intent_id, order_id, state, amount_minor, currency, base_amount_minor, base_currency, shipping, reason_code, note, key_hash, request_fingerprint, actor_type, actor_id, created_at ) '
		. "VALUES ( %s, %d, %d, 'claimed', %d, %s, %d, %s, %d, %s, NULLIF( %s, '' ), NULLIF( %s, '' ), NULLIF( %s, '' ), %s, NULLIF( %d, 0 ), UTC_TIMESTAMP(6) )";

	/**
	 * What a claim asked of each line, from one derived row repeated once per line.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_CLAIM_LINES = 'INSERT INTO {refund_claim_lines} ( claim_id, line_uuid, quantity, restock ) SELECT portion.claim_id, portion.line_uuid, portion.quantity, portion.restock '
		. 'FROM ( SELECT %d AS claim_id, %s AS line_uuid, %d AS quantity, %d AS restock ) AS portion';

	/**
	 * The claim an idempotency key names, by the `key_hash` unique key: where it stands, the ledger row that ended it, and the fingerprint of the request it was made for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FIND_CLAIM_BY_KEY = 'SELECT uuid, state, transaction_id, request_fingerprint FROM {refund_claims} WHERE key_hash = %s';

	/**
	 * A user's lock row for the refunds capped by the day, by the `user_id` unique key: inserted the first time, and otherwise found.
	 *
	 * Either way InnoDB holds an exclusive lock on the row until the transaction ends, so a second
	 * capped refund of the user waits here.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_ACTOR = 'INSERT INTO {refund_actor_locks} ( user_id, created_at ) VALUES ( %d, UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE id = id';

	/**
	 * What a user asked of the gateway in the last 24 hours, by the database clock, in the base currency, on the `actor_created` key: every claim but a declined one.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ASKED_TODAY = "SELECT COALESCE( SUM( base_amount_minor ), 0 ) AS asked FROM {refund_claims} WHERE actor_id = %d AND base_currency = %s AND state <> 'declined' AND created_at > UTC_TIMESTAMP(6) - INTERVAL 1 DAY";

	/**
	 * A refund's claim, by the `uuid` key: where it stands, and the ledger row that ended it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_CLAIM = 'SELECT uuid, state, transaction_id FROM {refund_claims} WHERE uuid = %s';

	/**
	 * Ends a refund's claim with the ledger row that ended it, or none (0), by the `uuid` key: only while it is still claimed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SETTLE_CLAIM = "UPDATE {refund_claims} SET state = %s, transaction_id = NULLIF( %d, 0 ), settled_at = UTC_TIMESTAMP(6) WHERE uuid = %s AND state = 'claimed'";

	/**
	 * What earlier refunds returned of some order lines, on the `order_line_id` key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETURNED_OF_LINES = 'SELECT order_line_id AS id, SUM( net_minor ) AS net_minor, SUM( tax_minor ) AS tax_minor, SUM( gross_minor ) AS gross_minor, '
		. 'SUM( base_net_minor ) AS base_net_minor, SUM( base_tax_minor ) AS base_tax_minor, SUM( base_gross_minor ) AS base_gross_minor '
		. 'FROM {refund_lines} WHERE order_line_id IN ({list}) GROUP BY order_line_id';

	/**
	 * What earlier refunds returned of some tax components, on the `order_tax_component_id` key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETURNED_OF_COMPONENTS = 'SELECT order_tax_component_id AS id, SUM( net_minor ) AS net_minor, SUM( tax_minor ) AS tax_minor, SUM( gross_minor ) AS gross_minor, '
		. 'SUM( base_net_minor ) AS base_net_minor, SUM( base_tax_minor ) AS base_tax_minor, SUM( base_gross_minor ) AS base_gross_minor '
		. 'FROM {refund_components} WHERE order_tax_component_id IN ({list}) GROUP BY order_tax_component_id';

	/**
	 * The shipping an order's refunds returned, before tax, on the `order_created` key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETURNED_SHIPPING = 'SELECT COALESCE( SUM( shipping_minor ), 0 ) AS shipping_minor, COALESCE( SUM( base_shipping_minor ), 0 ) AS base_shipping_minor FROM {refunds} WHERE order_id = %d';

	/**
	 * The refund's document, while the shipping the order's refunds returned, with this one's, stays within the stored shipping, in both currencies.
	 *
	 * A refund that returns no shipping in a currency passes that currency's cap, `%d = 0 OR …`:
	 * it adds nothing to what was returned, and the stored shipping is read only for a refund that
	 * asks for it. Nothing refunds fees yet, so fee_minor is 0. 0 stands for no actor.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_REFUND = 'INSERT INTO {refunds} ( uuid, order_id, intent_id, transaction_id, conversion_context_id, total_minor, shipping_minor, tax_minor, fee_minor, '
		. 'currency, base_currency, base_total_minor, base_shipping_minor, base_tax_minor, base_fee_minor, reason_code, is_offline, actor_type, actor_id, created_at ) SELECT '
		. '%s, %d, %d, %d, %d, %d, %d, %d, 0, %s, %s, %d, %d, %d, 0, %s, 0, %s, NULLIF( %d, 0 ), UTC_TIMESTAMP(6) FROM DUAL '
		. 'WHERE ( %d = 0 OR COALESCE( ( SELECT SUM( returned.shipping_minor ) FROM {refunds} returned WHERE returned.order_id = %d ), 0 ) + %d <= %d ) '
		. 'AND ( %d = 0 OR COALESCE( ( SELECT SUM( returned.base_shipping_minor ) FROM {refunds} returned WHERE returned.order_id = %d ), 0 ) + %d <= %d )';

	/**
	 * The refund's lines, from one derived row repeated once per line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_LINES = 'INSERT INTO {refund_lines} ( refund_id, order_line_id, quantity, amount_minor, net_minor, tax_minor, gross_minor, currency, base_net_minor, base_tax_minor, base_gross_minor, restock, created_at ) SELECT '
		. 'portion.refund_id, portion.order_line_id, portion.quantity, portion.gross, portion.net, portion.tax, portion.gross, portion.currency, portion.base_net, portion.base_tax, portion.base_gross, portion.restock, UTC_TIMESTAMP(6) '
		. 'FROM ( SELECT %d AS refund_id, %d AS order_line_id, %d AS quantity, %d AS net, %d AS tax, %d AS gross, %s AS currency, %d AS base_net, %d AS base_tax, %d AS base_gross, %d AS restock ) AS portion';

	/**
	 * The shares of the tax components a refund returns, from one derived row repeated once per component, each only while it fits what is left of its component.
	 *
	 * A share keeps to the sign of its stored component, and leaves no less than nothing of it: in
	 * gross, `sign × ( stored − share − Σ returned ) ≥ 0`, and the same in base gross, the sign
	 * being the stored figure's, so a negative component, such as a free-shipping discount's, is
	 * capped too. A component of the shipping names line 0, which no refund line has, so its
	 * refund_line_id is NULL.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_COMPONENTS = 'INSERT INTO {refund_components} ( refund_id, refund_line_id, order_tax_component_id, net_minor, tax_minor, gross_minor, currency, base_net_minor, base_tax_minor, base_gross_minor, created_at ) SELECT '
		. 'portion.refund_id, refund_line.id, portion.component_id, portion.net, portion.tax, portion.gross, portion.currency, portion.base_net, portion.base_tax, portion.base_gross, UTC_TIMESTAMP(6) '
		. 'FROM ( SELECT %d AS refund_id, %d AS order_line_id, %d AS component_id, %d AS net, %d AS tax, %d AS gross, %s AS currency, %d AS base_net, %d AS base_tax, %d AS base_gross, '
		. '%d AS stored_gross, %d AS stored_base_gross ) AS portion '
		. 'LEFT JOIN {refund_lines} refund_line ON refund_line.refund_id = portion.refund_id AND refund_line.order_line_id = portion.order_line_id '
		. 'WHERE IF( portion.stored_gross < 0, -1, 1 ) * portion.gross >= 0 '
		. 'AND IF( portion.stored_gross < 0, -1, 1 ) * ( portion.stored_gross - portion.gross - COALESCE( ( SELECT SUM( returned.gross_minor ) FROM {refund_components} returned WHERE returned.order_tax_component_id = portion.component_id ), 0 ) ) >= 0 '
		. 'AND IF( portion.stored_base_gross < 0, -1, 1 ) * portion.base_gross >= 0 '
		. 'AND IF( portion.stored_base_gross < 0, -1, 1 ) * ( portion.stored_base_gross - portion.base_gross - COALESCE( ( SELECT SUM( returned.base_gross_minor ) FROM {refund_components} returned WHERE returned.order_tax_component_id = portion.component_id ), 0 ) ) >= 0';

	/**
	 * The refund document that states a ledger row, on the `transaction_id` key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_BY_TRANSACTION = 'SELECT id, uuid, order_id, transaction_id, conversion_context_id, total_minor, tax_minor, shipping_minor, currency, base_currency, '
		. 'base_total_minor, base_tax_minor, base_shipping_minor, reason_code FROM {refunds} WHERE transaction_id = %d';

	/**
	 * The module's name, in the messages of a statement that names another module's table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'payment';

	/**
	 * Sends the statements, over the payment module's tables only.
	 *
	 * @since 0.1.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->statements = new ModuleStatements( $db, self::MODULE, PaymentTables::moduleNames() );
	}

	/**
	 * Turns a statement's tokens into wpdb placeholders and its values into arguments, in order, over the payment module's tables.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a token names no table of the payment module, or the values do not match the placeholders.
	 *
	 * @param string   $statement One of this class's constants, its derived row repeated as needed.
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
	 * Reads an order's captured intent, whether the ledger holds a result of it applied to nothing, and how many of its refunds were declined, without a lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return RefundableIntent|null The first captured or partly refunded intent, or null when there is none.
	 */
	public function refundableIntent( int $orderId ): ?RefundableIntent {
		$row = $this->statements->rows( self::REFUNDABLE_INTENT, $orderId, IntentTransitions::values( IntentTransitions::allowedFrom( IntentStatus::PartiallyRefunded ) ) )[0] ?? null;

		if ( null === $row ) {
			return null;
		}

		$currency = Currency::of( (string) $row['currency'] );
		$base     = Currency::of( (string) $row['base_currency'] );

		return new RefundableIntent(
			(int) $row['id'],
			(string) $row['uuid'],
			(string) $row['gateway_id'],
			Mode::from( (string) $row['mode'] ),
			null === $row['provider_intent_id'] ? null : (string) $row['provider_intent_id'],
			Money::of( (int) $row['captured_minor'], $currency ),
			Money::of( (int) $row['refunded_minor'], $currency ),
			Money::of( (int) $row['base_captured_minor'], $base ),
			Money::of( (int) $row['base_refunded_minor'], $base ),
			1 === (int) $row['has_unapplied_result'],
			(int) $row['declined_refunds'],
			null === $row['open_claim'] ? null : (string) $row['open_claim']
		);
	}

	/**
	 * Locks an intent for a refund's claim, inside the caller's transaction, and reads, as they now stand, whether it has money a person must reconcile, what refund uuids are named by, and its open claim.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, or when the intent does not exist.
	 *
	 * @param int $intentId The intent.
	 * @return array{has_unapplied_result: bool, refunded_minor: int, base_refunded_minor: int, declined_refunds: int, open_claim: string|null} Whether the ledger holds a result of it applied to nothing; what it refunded, in minor units, and the same in the base currency; how many of its refunds were declined; its oldest refund claim still claimed.
	 */
	public function lockForClaim( int $intentId ): array {
		$this->statements->requireTransaction( __METHOD__ );

		$row = $this->statements->rows( self::LOCK_FOR_CLAIM, $intentId )[0] ?? null;

		if ( null === $row ) {
			throw new \LogicException( sprintf( 'Intent %d, which the refund was worked out from, does not exist.', $intentId ) );
		}

		return array(
			'has_unapplied_result' => 1 === (int) $row['has_unapplied_result'],
			'refunded_minor'       => (int) $row['refunded_minor'],
			'base_refunded_minor'  => (int) $row['base_refunded_minor'],
			'declined_refunds'     => (int) $row['declined_refunds'],
			'open_claim'           => null === $row['open_claim'] ? null : (string) $row['open_claim'],
		);
	}

	/**
	 * Locks a user's row for the refunds capped by the day, inside the caller's transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int $userId The user.
	 */
	public function lockActor( int $userId ): void {
		$this->statements->requireTransaction( __METHOD__ );

		$this->statements->execute( self::LOCK_ACTOR, $userId );
	}

	/**
	 * Adds up what a user asked of the gateway in the last 24 hours, in the base currency, inside the caller's transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int      $userId The user.
	 * @param Currency $base   The base currency.
	 * @return Money The sum.
	 */
	public function askedToday( int $userId, Currency $base ): Money {
		$this->statements->requireTransaction( __METHOD__ );

		return Money::of( (int) ( $this->statements->rows( self::ASKED_TODAY, $userId, $base->code() )[0]['asked'] ?? 0 ), $base );
	}

	/**
	 * Claims a refund before the gateway is asked for it, with what was asked: the claim, then its lines, inside the claim's own transaction, under the intent's lock.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The request and the caller's key.
	 *
	 * @throws \LogicException Outside a transaction; or when a line was not written, which the unique key and the values rule out.
	 *
	 * @param RefundPlan      $plan      The refund.
	 * @param RefundRequest   $request   What was asked.
	 * @param string          $actorType `user` or `system`.
	 * @param int|null        $actorId   The user who asks for it, or null.
	 * @param RequestKey|null $key       The caller's key, or null.
	 * @return bool True when this request claimed the refund; false when the `uuid` or the `key_hash` key refused it, claimed before.
	 */
	public function claim( RefundPlan $plan, RefundRequest $request, string $actorType, ?int $actorId, ?RequestKey $key ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		$amount = $plan->total->amount->gross();
		$base   = $plan->total->base->gross();

		try {
			$this->statements->execute(
				self::CLAIM,
				$plan->uuid,
				$plan->intent->id,
				$plan->order->id,
				$amount->minorUnits(),
				$amount->currency()->code(),
				$base->minorUnits(),
				$base->currency()->code(),
				$request->shipping ? 1 : 0,
				$plan->reasonCode,
				(string) $request->note,
				$key->keyHash ?? '',
				$key->fingerprint ?? '',
				$actorType,
				$actorId ?? 0
			);
		} catch ( DuplicateKey $claimed ) {
			return false;
		}

		$this->insertClaimLines( $this->statements->lastInsertId(), $request );

		return true;
	}

	/**
	 * Reads a refund's claim, without a lock.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The refund's uuid.
	 * @return RefundClaim|null The claim, or null when the refund was never claimed.
	 */
	public function findClaim( string $uuid ): ?RefundClaim {
		$row = $this->statements->rows( self::FIND_CLAIM, $uuid )[0] ?? null;

		return null === $row ? null : self::claimOf( $row );
	}

	/**
	 * Reads the claim a caller's idempotency key names, without a lock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $keyHash The key's hash.
	 * @return RefundClaim|null The claim, with its request's fingerprint; null when the key names none.
	 */
	public function findClaimByKey( string $keyHash ): ?RefundClaim {
		$row = $this->statements->rows( self::FIND_CLAIM_BY_KEY, $keyHash )[0] ?? null;

		return null === $row ? null : self::claimOf( $row );
	}

	/**
	 * Ends a refund's claim with the ledger row that ended it, or none, inside the caller's transaction, only while it is still claimed.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, or for `claimed`, which ends nothing.
	 *
	 * @param string     $uuid          The refund's uuid.
	 * @param ClaimState $to            Recorded, declined or unreconciled.
	 * @param int|null   $transactionId The ledger row this refund's answer wrote, or null for none.
	 * @return bool True when the claim ended here; false when it had ended already.
	 */
	public function settleClaim( string $uuid, ClaimState $to, ?int $transactionId ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		if ( ! in_array( $to, ClaimState::allowedFrom( ClaimState::Claimed ), true ) ) {
			throw new \LogicException( sprintf( 'A claim ends recorded, declined or unreconciled, never %s.', $to->value ) );
		}

		return 1 === $this->statements->execute( self::SETTLE_CLAIM, $to->value, $transactionId ?? 0, $uuid );
	}

	/**
	 * Adds up what earlier refunds returned of some order lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $lineIds      The order lines.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array<int, Share> What was returned, by order line id.
	 *
	 * @phpstan-param list<int> $lineIds
	 */
	public function returnedOfLines( array $lineIds, Currency $currency, Currency $baseCurrency ): array {
		return array() === $lineIds ? array() : self::shares( $this->statements->rows( self::RETURNED_OF_LINES, $lineIds ), $currency, $baseCurrency );
	}

	/**
	 * Adds up what earlier refunds returned of some tax components.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $componentIds The order's tax components.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array<int, Share> What was returned, by component id.
	 *
	 * @phpstan-param list<int> $componentIds
	 */
	public function returnedOfComponents( array $componentIds, Currency $currency, Currency $baseCurrency ): array {
		return array() === $componentIds ? array() : self::shares( $this->statements->rows( self::RETURNED_OF_COMPONENTS, $componentIds ), $currency, $baseCurrency );
	}

	/**
	 * Adds up the shipping earlier refunds of an order returned, before tax.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId      The order.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array{0: Money, 1: Money} The shipping returned, and the same in the base currency.
	 */
	public function returnedShipping( int $orderId, Currency $currency, Currency $baseCurrency ): array {
		$row = $this->statements->rows( self::RETURNED_SHIPPING, $orderId )[0] ?? array();

		return array( Money::of( (int) ( $row['shipping_minor'] ?? 0 ), $currency ), Money::of( (int) ( $row['base_shipping_minor'] ?? 0 ), $baseCurrency ) );
	}

	/**
	 * Inserts the refund's document, while the shipping stays within what the order stored.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan $plan          The refund.
	 * @param int        $transactionId The ledger row of the gateway's refund.
	 * @param string     $actorType     `user` or `system`.
	 * @param int|null   $actorId       The user who refunds, or null.
	 * @return int|null The refund's id; null when the shipping's cap refused it.
	 */
	public function insertRefund( RefundPlan $plan, int $transactionId, string $actorType, ?int $actorId ): ?int {
		$this->statements->requireTransaction( __METHOD__ );

		$order      = $plan->order;
		$total      = $plan->total;
		$shipping   = $plan->shipping;
		$net        = null === $shipping ? 0 : $shipping->share->amount->net()->minorUnits();
		$baseNet    = null === $shipping ? 0 : $shipping->share->base->net()->minorUnits();
		$stored     = null === $shipping ? 0 : $shipping->stored->net()->minorUnits();
		$storedBase = null === $shipping ? 0 : $shipping->storedBase->net()->minorUnits();

		$inserted = $this->statements->execute(
			self::INSERT_REFUND,
			$plan->uuid,
			$order->id,
			$plan->intent->id,
			$transactionId,
			$order->conversionContextId,
			$total->amount->gross()->minorUnits(),
			$net,
			$total->amount->tax()->minorUnits(),
			$order->currency->code(),
			$order->baseCurrency->code(),
			$total->base->gross()->minorUnits(),
			$baseNet,
			$total->base->tax()->minorUnits(),
			$plan->reasonCode,
			$actorType,
			$actorId ?? 0,
			// WHERE: no shipping, or the shipping returned before, with this refund's, within the stored shipping, in each currency.
			$net,
			$order->id,
			$net,
			$stored,
			$baseNet,
			$order->id,
			$baseNet,
			$storedBase
		);

		return 1 === $inserted ? $this->statements->lastInsertId() : null;
	}

	/**
	 * Inserts the refund's lines, in one statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a line was not written; the unique key and the values rule that out.
	 *
	 * @param int        $refundId The refund.
	 * @param RefundPlan $plan     The refund.
	 */
	public function insertLines( int $refundId, RefundPlan $plan ): void {
		$this->statements->requireTransaction( __METHOD__ );

		if ( array() === $plan->lines ) {
			return;
		}

		$rows = array_map(
			static fn( LinePortion $line ): array => array(
				$refundId,
				$line->line->id,
				$line->quantity,
				...self::minorUnits( $line->share->amount ),
				...array( $line->share->amount->currency()->code() ),
				...self::minorUnits( $line->share->base ),
				...array( $line->restock ? 1 : 0 ),
			),
			$plan->lines
		);

		if ( count( $rows ) !== $this->executeForRows( self::INSERT_LINES, $rows ) ) {
			throw new \LogicException( 'A refund line was not written.' );
		}
	}

	/**
	 * Inserts the shares of the tax components the refund returns, each while it fits what is left of its component.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $refundId The refund, whose lines are written.
	 * @param RefundPlan $plan     The refund.
	 * @return bool True when every share was written.
	 */
	public function insertComponents( int $refundId, RefundPlan $plan ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		$components = $plan->components();

		if ( array() === $components ) {
			return true;
		}

		$rows = array_map(
			static fn( ComponentPortion $portion ): array => array(
				$refundId,
				$portion->component->lineId ?? 0,
				$portion->component->id,
				...self::minorUnits( $portion->share->amount ),
				...array( $portion->share->amount->currency()->code() ),
				...self::minorUnits( $portion->share->base ),
				...array( $portion->component->stored->amount->gross()->minorUnits(), $portion->component->stored->base->gross()->minorUnits() ),
			),
			$components
		);

		return count( $rows ) === $this->executeForRows( self::INSERT_COMPONENTS, $rows );
	}

	/**
	 * Reads the refund document that states a ledger row.
	 *
	 * @since 0.1.0
	 *
	 * @param int $transactionId The ledger row.
	 * @return Refund|null The refund, or null when the row has no document.
	 */
	public function findByTransaction( int $transactionId ): ?Refund {
		$row = $this->statements->rows( self::FIND_BY_TRANSACTION, $transactionId )[0] ?? null;

		if ( null === $row ) {
			return null;
		}

		$money = static fn( string $column, string $currency ): Money => Money::of( (int) $row[ $column ], Currency::of( (string) $row[ $currency ] ) );

		return new Refund(
			(int) $row['id'],
			(string) $row['uuid'],
			(int) $row['order_id'],
			(int) $row['transaction_id'],
			(int) $row['conversion_context_id'],
			$money( 'total_minor', 'currency' ),
			$money( 'tax_minor', 'currency' ),
			$money( 'shipping_minor', 'currency' ),
			$money( 'base_total_minor', 'base_currency' ),
			$money( 'base_tax_minor', 'base_currency' ),
			$money( 'base_shipping_minor', 'base_currency' ),
			(string) $row['reason_code']
		);
	}

	/**
	 * Inserts what a claim asked of each line, in one statement; sends nothing when it asked for the shipping alone.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When a line was not written.
	 *
	 * @param int           $claimId The claim.
	 * @param RefundRequest $request What was asked.
	 */
	private function insertClaimLines( int $claimId, RefundRequest $request ): void {
		if ( array() === $request->lines ) {
			return;
		}

		$rows = array_map( static fn( RefundLineRequest $line ): array => array( $claimId, $line->lineUuid, $line->quantity, $line->restock ? 1 : 0 ), $request->lines );

		if ( count( $rows ) !== $this->executeForRows( self::INSERT_CLAIM_LINES, $rows ) ) {
			throw new \LogicException( 'A line of a refund claim was not written.' );
		}
	}

	/**
	 * Builds a claim from its row.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $row The row: the uuid, the state, the ledger row, and the request's fingerprint when it was read.
	 * @return RefundClaim The claim.
	 */
	private static function claimOf( array $row ): RefundClaim {
		$fingerprint = $row['request_fingerprint'] ?? null;

		return new RefundClaim(
			(string) $row['uuid'],
			ClaimState::from( (string) $row['state'] ),
			null === $row['transaction_id'] ? null : (int) $row['transaction_id'],
			null === $fingerprint ? null : (string) $fingerprint
		);
	}

	/**
	 * Sends a statement for several rows of its derived table of values, as one statement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant with one derived row, before any other placeholder.
	 * @param array  $rows      Each row's values, in placeholder order; at least one row.
	 * @return int The rows affected.
	 *
	 * @phpstan-param non-empty-list<list<mixed>> $rows
	 */
	private function executeForRows( string $statement, array $rows ): int {
		return $this->statements->execute( ModuleStatements::forDerivedRows( $statement, count( $rows ) ), ...array_merge( ...$rows ) );
	}

	/**
	 * Builds shares from summed rows, by their id.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array<string, mixed>> $rows         The rows: an id and the six sums.
	 * @param Currency                   $currency     The order's currency.
	 * @param Currency                   $baseCurrency The base currency.
	 * @return array<int, Share> The shares, by id.
	 */
	private static function shares( array $rows, Currency $currency, Currency $baseCurrency ): array {
		$shares = array();

		foreach ( $rows as $row ) {
			$shares[ (int) $row['id'] ] = new Share( self::taxed( $row, '', $currency ), self::taxed( $row, 'base_', $baseCurrency ) );
		}

		return $shares;
	}

	/**
	 * Builds net, tax and gross from a row's three columns of one prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row      The row.
	 * @param string               $prefix   What the columns' names begin with: '' or `base_`.
	 * @param Currency             $currency Their currency.
	 * @return TaxedMoney The figures.
	 */
	private static function taxed( array $row, string $prefix, Currency $currency ): TaxedMoney {
		return new TaxedMoney( Money::of( (int) $row[ $prefix . 'net_minor' ], $currency ), Money::of( (int) $row[ $prefix . 'tax_minor' ], $currency ), Money::of( (int) $row[ $prefix . 'gross_minor' ], $currency ) );
	}

	/**
	 * Returns net, tax and gross in minor units, in that order.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $amount The figures.
	 * @return list<int> Their minor units.
	 */
	private static function minorUnits( TaxedMoney $amount ): array {
		return array( $amount->net()->minorUnits(), $amount->tax()->minorUnits(), $amount->gross()->minorUnits() );
	}
}

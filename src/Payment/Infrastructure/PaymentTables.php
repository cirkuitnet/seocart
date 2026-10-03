<?php
/**
 * PaymentTables: the declarations of the payment tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the two tables of the payment module: the intents, and the ledger of what gateways reported.
 *
 * Owns one fact: the shape of the payment tables. The migration that creates them and the data
 * registry that lists them call the same factories.
 *
 * The ledger is the truth about money; an intent's authorized, captured and refunded amounts are
 * its projection, written in the same transaction as each row that moves them. Every amount is a
 * signed `bigint` of minor units with its currency beside it, and has a `base_` twin in the
 * store's base currency at the order's frozen rate. A provider's own settlement figures are kept
 * beside a ledger row as the provider's facts, never used for the plugin's amounts.
 *
 * @since 0.1.0
 */
final class PaymentTables {

	/**
	 * The unprefixed name of the payment intent table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INTENTS = 'payment_intents';

	/**
	 * The unprefixed name of the payment ledger.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TRANSACTIONS = 'payment_transactions';

	/**
	 * The module every table belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'Payment';

	/**
	 * The retention policy of an order's money.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FINANCIAL = 'financial';

	/**
	 * Returns the two declarations.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The intents and the ledger.
	 */
	public static function all(): array {
		return array( self::intents(), self::transactions() );
	}

	/**
	 * Returns the unprefixed names of the two tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names, in the order of all().
	 */
	public static function names(): array {
		return array( self::INTENTS, self::TRANSACTIONS );
	}

	/**
	 * Returns the unprefixed names of every table of the payment module: these two, the refund tables and the refund claims.
	 *
	 * The tables the module's statements may name; each migration still creates only its own.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names.
	 */
	public static function moduleNames(): array {
		return array_merge( self::names(), RefundTables::names(), RefundClaimTables::names() );
	}

	/**
	 * Declares `payment_intents`: each attempt to pay for an order, with its frozen amount and what its ledger has moved.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function intents(): TableDefinition {
		return new TableDefinition(
			self::INTENTS,
			self::MODULE,
			'Records each attempt to pay for an order: the gateway it goes through, its state, its amount frozen from the order\'s totals, and what its ledger rows have authorized, captured and refunded so far.',
			MutationPattern::MutableTransactional,
			array(
				self::id( 'Surrogate key: the intent id, internal only.' ),
				self::uuid( 'The intent\'s public identifier, and the idempotency key its gateway receives: stable across retries of the one attempt.' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order the intent pays for; an intent is created with its order and never without one.' ),
				new ColumnSpec( 'gateway_id', 'varchar(32)', Classification::Public, 'The gateway the intent is paid through, for example stub.', collation: 'ascii_bin' ),
				new ColumnSpec( 'status', 'varchar(24)', Classification::Public, 'The intent\'s state; changed only by the conditional updates the intent state machine compiles to.', defaultValue: 'created', collation: 'ascii_bin' ),
				self::money( 'amount_minor', 'The amount the intent is for, frozen from the order\'s totals when it was created and never changed.' ),
				self::currency( 'currency', 'The currency of every amount of the intent: the order\'s, ISO 4217.' ),
				new ColumnSpec( 'conversion_context_id', 'bigint unsigned', Classification::Public, 'The frozen exchange rate of the intent\'s order, which every base amount is at.' ),
				self::currency( 'base_currency', 'The store\'s base currency when the order was placed.' ),
				self::money( 'base_amount_minor', 'amount_minor in the base currency, frozen from the order\'s base totals.' ),
				self::money( 'authorized_minor', 'Authorized so far: the sum of the intent\'s applied, approved authorize rows.', '0' ),
				self::money( 'captured_minor', 'Captured so far: the sum of the intent\'s applied, approved capture rows.', '0' ),
				self::money( 'refunded_minor', 'Refunded so far: the sum of the intent\'s applied, approved refund rows; never more than captured_minor.', '0' ),
				self::money( 'base_authorized_minor', 'authorized_minor in the base currency.', '0' ),
				self::money( 'base_captured_minor', 'captured_minor in the base currency.', '0' ),
				self::money( 'base_refunded_minor', 'refunded_minor in the base currency; never more than base_captured_minor.', '0' ),
				new ColumnSpec( 'provider_intent_id', 'varchar(191)', Classification::Public, 'The gateway\'s reference to the intent, recorded from its first answer that carries one; NULL until then.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'payment_schedule_id', 'bigint unsigned', Classification::Public, 'The payment schedule the intent pays an instalment of; NULL for an order paid at once.', nullable: true ),
				new ColumnSpec( 'voided_reason', 'varchar(32)', Classification::Public, 'Why the intent was voided; NULL unless it was.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'customer_action_expires_at', 'datetime', Classification::Public, 'When the intent\'s wait runs out, for the customer to act (for example to confirm with their bank) or for the gateway to decide, UTC, from the database clock; NULL while it waits for neither. Reconciliation sends it to the gateway, which answers an intent waiting past it as expired.', nullable: true ),
				self::createdAt( 'When the intent was created, UTC, from the database clock.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the intent last changed, UTC, from the database clock; what reconciliation measures an intent\'s wait by.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'One intent per public identifier: the lookup of every gateway result.' ),
				IndexSpec::unique( 'gateway_provider_intent', array( 'gateway_id', 'provider_intent_id' ), 'A gateway\'s intent maps to one of ours; many NULLs until the gateway gives its reference.' ),
			),
			array(
				IndexSpec::key( 'order_id', array( 'order_id' ), 'An order\'s intents, which doctor sums against the order\'s payment amounts.' ),
				IndexSpec::key( 'status_updated', array( 'status', 'updated_at' ), 'The intents still waiting for a result, oldest first, for reconciliation.' ),
			),
			self::FINANCIAL,
			array(
				'orders -> payment_intents' => 'Written only with their order, in the transaction that places it; kept for as long as the order is.',
			)
		);
	}

	/**
	 * Declares `payment_transactions`: every money fact a gateway reported, appended and never changed.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function transactions(): TableDefinition {
		return new TableDefinition(
			self::TRANSACTIONS,
			self::MODULE,
			'Records every approval and decline a gateway reported for an intent, once each, with who applied it and whether it moved the intent\'s and the order\'s amounts; the truth their payment amounts are derived from.',
			MutationPattern::AppendOnly,
			array(
				self::id( 'Surrogate key: the transaction id events and results name.' ),
				self::uuid( 'The row\'s public identifier.' ),
				new ColumnSpec( 'intent_id', 'bigint unsigned', Classification::Public, 'The intent the result is about.' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The intent\'s order.' ),
				new ColumnSpec( 'operation', 'varchar(12)', Classification::Public, 'What the gateway was asked: authorize, capture, void or refund.', collation: 'ascii_bin' ),
				self::money( 'amount_minor', 'The amount the gateway reported, in the currency it reported.' ),
				self::currency( 'currency', 'The currency the gateway reported, which a mismatch leaves different from the order\'s.' ),
				new ColumnSpec( 'conversion_context_id', 'bigint unsigned', Classification::Public, 'The frozen exchange rate of the intent\'s order.' ),
				self::currency( 'base_currency', 'The store\'s base currency when the order was placed.' ),
				self::money( 'base_amount_minor', 'amount_minor in the base currency, at the order\'s frozen rate; 0 on a row whose amount has no base equivalent, such as one the projection refused.' ),
				new ColumnSpec( 'settlement_currency', 'char(3)', Classification::Public, 'The currency the gateway says it settles with the merchant in; NULL when it did not say.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'settlement_amount_minor', 'bigint', Classification::Financial, 'The amount the gateway says it settles, in settlement_currency: its fact, never used for the plugin\'s amounts; NULL when it did not say.', nullable: true ),
				new ColumnSpec( 'settlement_rate', 'decimal(24,12)', Classification::Financial, 'The gateway\'s rate from currency to settlement_currency; NULL when it did not say.', nullable: true ),
				new ColumnSpec( 'settlement_fee_minor', 'bigint', Classification::Financial, 'The gateway\'s fee, in settlement_currency; NULL when it did not say.', nullable: true ),
				new ColumnSpec( 'settlement_source', 'varchar(32)', Classification::Public, 'Who reported the settlement; NULL when no one did.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'provider', 'varchar(32)', Classification::Public, 'The gateway that reported the result.', collation: 'ascii_bin' ),
				new ColumnSpec( 'provider_object_id', 'varchar(191)', Classification::Public, 'The gateway\'s object for this outcome, a charge, a capture or a refund, never its long-lived intent; NULL when the gateway made none.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'result', 'varchar(16)', Classification::Public, 'What the gateway answered: approved or declined.', collation: 'ascii_bin' ),
				new ColumnSpec( 'applied', 'tinyint(1)', Classification::Public, '1 when the row moved the intent\'s and the order\'s amounts; 0 when its amount or currency did not match them, so it moved nothing and a person must reconcile it.', defaultValue: '1' ),
				new ColumnSpec( 'error_code', 'varchar(64)', Classification::Public, 'The gateway\'s machine code for a decline, for example card_declined; NULL otherwise.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'actor_type', 'varchar(16)', Classification::Public, 'user for a person acting in person, system for a process acting on a user\'s authority.', collation: 'ascii_bin' ),
				new ColumnSpec(
					'actor_id',
					'bigint unsigned',
					Classification::Pii,
					'The WordPress user on whose authority the result was applied; NULL for a visitor.',
					nullable: true,
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'The ledger keeps who applied each money fact for as long as the order is kept; the user id identifies no one once the user is erased.'
				),
				new ColumnSpec( 'correlation_id', 'char(36)', Classification::Public, 'The correlation id of the request that applied the result, shared with its events.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'payload_hash', 'char(64)', Classification::Public, 'The SHA-256 of the provider\'s payload the result came in; NULL for a result with no payload of its own.', nullable: true, collation: 'ascii_bin' ),
				self::createdAt( 'When the result was recorded, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'One row per public identifier.' ),
				IndexSpec::unique( 'provider_object_operation', array( 'provider', 'provider_object_id', 'operation' ), 'A gateway result is applied at most once: a second delivery of the same outcome meets this key.' ),
			),
			array(
				IndexSpec::key( 'intent_created', array( 'intent_id', 'created_at' ), 'An intent\'s rows, which doctor sums against its amounts, and its unreconciled rows before a capture or a refund.' ),
				IndexSpec::key( 'order_created', array( 'order_id', 'created_at' ), 'An order\'s ledger, in time order.' ),
			),
			self::FINANCIAL,
			array(
				'payment_intents -> payment_transactions' => 'Appended in the transaction that applies each result, and never changed or deleted apart from the intent\'s order.',
			)
		);
	}

	/**
	 * Declares an amount in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $name         The column name, ending in `_minor`.
	 * @param string      $note         What the amount is.
	 * @param string|null $defaultValue Optional. The default. Default null, none.
	 * @return ColumnSpec The column.
	 */
	private static function money( string $name, string $note, ?string $defaultValue = null ): ColumnSpec {
		return new ColumnSpec( $name, 'bigint', Classification::Financial, $note, defaultValue: $defaultValue );
	}

	/**
	 * Declares an ISO 4217 currency code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The column name.
	 * @param string $note What the currency is of.
	 * @return ColumnSpec The column.
	 */
	private static function currency( string $name, string $note ): ColumnSpec {
		return new ColumnSpec( $name, 'char(3)', Classification::Public, $note, collation: 'ascii_bin' );
	}

	/**
	 * Declares the surrogate key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $note What the id names.
	 * @return ColumnSpec The column.
	 */
	private static function id( string $note ): ColumnSpec {
		return new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, $note, autoIncrement: true );
	}

	/**
	 * Declares the public identifier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $note What the UUID identifies.
	 * @return ColumnSpec The column.
	 */
	private static function uuid( string $note ): ColumnSpec {
		return new ColumnSpec( 'uuid', 'char(36)', Classification::Public, $note, collation: 'ascii_bin' );
	}

	/**
	 * Declares when a row was written.
	 *
	 * @since 0.1.0
	 *
	 * @param string $note The note.
	 * @return ColumnSpec The column.
	 */
	private static function createdAt( string $note ): ColumnSpec {
		return new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, $note );
	}
}

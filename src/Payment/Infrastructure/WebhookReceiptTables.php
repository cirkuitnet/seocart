<?php
/**
 * WebhookReceiptTables: the declaration of the webhook receipts, one per verified delivery of a provider's event
 *
 * @package SEOCart
 * @since   0.2.0
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
 * Declares `webhook_receipts`: one row for each provider event a verified delivery reported, and what was decided about it.
 *
 * Owns one fact: the shape of the receipts. The migration that creates the table and the data
 * registry that lists it call the same factory.
 *
 * A receipt is written only after the gateway verified the delivery's signature, so a rejected
 * delivery leaves no row. It is written before the result is applied, in a statement of its own,
 * and settled after, in another: while its result is NULL the event is received but not decided,
 * and a delivery of it again is processed again. Once settled, a delivery of it again is answered
 * from the receipt. The body is never kept: its SHA-256 is the only trace of it. The ledger's own
 * key decides whether money moved once; the receipt rejects a replay of an event before anything
 * of the ledger is read, for as long as the retention policy keeps it.
 *
 * @since 0.2.0
 */
final class WebhookReceiptTables {

	/**
	 * The unprefixed name of the receipts.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECEIPTS = 'webhook_receipts';

	/**
	 * The retention policy of the receipts: deleted the period after they were received.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RETENTION = 'webhook_receipts';

	/**
	 * Returns the declarations.
	 *
	 * @since 0.2.0
	 *
	 * @return list<TableDefinition> The receipts.
	 */
	public static function all(): array {
		return array( self::receipts() );
	}

	/**
	 * Returns the unprefixed names of the tables.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The names.
	 */
	public static function names(): array {
		return array( self::RECEIPTS );
	}

	/**
	 * Declares `webhook_receipts`.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function receipts(): TableDefinition {
		return new TableDefinition(
			self::RECEIPTS,
			'Payment',
			'Records each provider event a verified webhook delivery reported, once by the gateway, the mode and the event id, before its result is applied; then what was decided about it, applied, a duplicate, stale, kept for a person or ignored, with the intent and the ledger row. The body is kept only as its hash.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key, internal only.', autoIncrement: true ),
				new ColumnSpec( 'provider', 'varchar(32)', Classification::Public, 'The gateway the delivery was addressed to, from the route, as payment_intents.gateway_id holds it.', collation: 'ascii_bin' ),
				new ColumnSpec( 'mode', 'char(4)', Classification::Public, 'test or live: the mode of the address the delivery was sent to, never one the body claims.', collation: 'ascii_bin' ),
				new ColumnSpec( 'event_id', 'varchar(191)', Classification::Public, 'The provider\'s id of the event, such as Stripe\'s evt_ id.', collation: 'ascii_bin' ),
				new ColumnSpec( 'event_type', 'varchar(64)', Classification::Public, 'The provider\'s type of the event, such as payment_intent.succeeded.', collation: 'ascii_bin' ),
				new ColumnSpec( 'occurred_at', 'datetime', Classification::Public, 'When the provider says the event happened, UTC; information only, never checked. NULL when the provider says nothing.', nullable: true ),
				new ColumnSpec( 'received_at', 'datetime(6)', Classification::Public, 'When the first delivery of the event was recorded, UTC, from the database clock.' ),
				new ColumnSpec( 'processed_at', 'datetime(6)', Classification::Public, 'When what was decided about the event was recorded, UTC, from the database clock; NULL while it is undecided.', nullable: true ),
				new ColumnSpec( 'result', 'varchar(16)', Classification::Public, 'What was decided: applied, duplicate, stale, unapplied or ignored; NULL while undecided, when the next delivery of the event is processed again.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'result_code', 'varchar(64)', Classification::Public, 'The word that says more: what an applied result did, the state a stale one found, why a result was kept for a person or why the event was ignored; NULL when there is none.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'intent_uuid', 'char(36)', Classification::Public, 'The payment intent the event was about, once known; NULL for an event about none of the store\'s.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'transaction_id', 'bigint unsigned', Classification::Public, 'The ledger row the delivery wrote, or met for a duplicate; NULL when it wrote none.', nullable: true ),
				new ColumnSpec( 'payload_hash', 'char(64)', Classification::Public, 'The SHA-256 of the delivery\'s body, in hex: the only trace of the body anywhere.', collation: 'ascii_bin' ),
				new ColumnSpec( 'correlation_id', 'char(36)', Classification::Public, 'The correlation id of the request that recorded the receipt.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'expires_at', 'datetime', Classification::Public, 'When the receipt is deleted: received_at plus the retention period, UTC, written with it.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'provider_mode_event', array( 'provider', 'mode', 'event_id' ), 'One receipt per event of a gateway\'s address: a second delivery of the event finds the first one\'s receipt by it.' ),
			),
			array(
				IndexSpec::key( 'expires_at', array( 'expires_at' ), 'The receipts past their retention, which the prune deletes in batches.' ),
				IndexSpec::key( 'result_received', array( 'result', 'received_at' ), 'The receipts still undecided long after they were received, and the counts of each decision, for doctor.' ),
			),
			self::RETENTION,
			array()
		);
	}
}

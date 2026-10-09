<?php
/**
 * RefundClaimTables: the declaration of the refund claims, what each asked for, and the lock rows of who asks
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
 * Declares `refund_claims`, one row for each refund the gateway was asked for, written before it was asked; `refund_claim_lines`, what each claim asked of each line; and `refund_actor_locks`, the row each user's capped refunds wait on.
 *
 * Owns one fact: the shape of the refund claims. The migrations that create and extend the tables
 * and the data registry that lists them call the same factories.
 *
 * A claim is committed before the gateway is called, so a refund the gateway made is never one
 * nothing knows was asked for. It records what was asked (the refund's uuid, which the gateway
 * received as its key, the intent, the amount and its base share, the lines and the shipping, the
 * reason and the note), by whom and when, the caller's idempotency key when it sent one, and then
 * how it ended: `claimed` until the gateway's answer is recorded, then `recorded`, `declined` or
 * `unreconciled`, with the ledger row its own answer wrote, when there is one. A settled claim is
 * kept with the refund it asked for, for as long as the order is: it is the one record of an
 * attempt that left no refund document, and its key names the refund for good.
 *
 * @since 0.1.0
 * @since 0.2.0 The request the claim was made for, its key, and the two tables beside it.
 */
final class RefundClaimTables {

	/**
	 * The unprefixed name of the refund claims.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIMS = 'refund_claims';

	/**
	 * The unprefixed name of what each claim asked of each order line.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLAIM_LINES = 'refund_claim_lines';

	/**
	 * The unprefixed name of the lock rows of the users whose refunds are capped.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ACTOR_LOCKS = 'refund_actor_locks';

	/**
	 * Returns the declarations.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The claims' lines and the actor locks.
	 *
	 * @return list<TableDefinition> The claims, their lines and the actor locks.
	 */
	public static function all(): array {
		return array( self::claims(), self::claimLines(), self::actorLocks() );
	}

	/**
	 * Returns the unprefixed names of the tables.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The claims' lines and the actor locks.
	 *
	 * @return list<string> The names.
	 */
	public static function names(): array {
		return array( self::CLAIMS, self::CLAIM_LINES, self::ACTOR_LOCKS );
	}

	/**
	 * Declares `refund_claims`.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The request, its base share, its key and its fingerprint; and how a person settled the claim.
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function claims(): TableDefinition {
		return new TableDefinition(
			self::CLAIMS,
			'Payment',
			'Records each refund the gateway was asked for, before it was asked: the refund\'s uuid, the intent, the amount asked and its share in the base currency, the shipping, the reason and the note, who asked and when, and the idempotency key the caller sent; and then how it ended, recorded, declined or left for a person, with the ledger row that ended it; and, for a claim a person settled, what they stated, what the gateway said then, who settled it and why.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key, internal only.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', Classification::Public, 'The refund\'s uuid: the idempotency key the gateway received, and the uuid of its document once it is recorded.', collation: 'ascii_bin' ),
				new ColumnSpec( 'intent_id', 'bigint unsigned', Classification::Public, 'The payment intent the money is asked back through.' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order refunded.' ),
				new ColumnSpec( 'state', 'varchar(16)', Classification::Public, 'claimed until the gateway\'s answer is recorded; then recorded, declined or unreconciled.', collation: 'ascii_bin' ),
				new ColumnSpec( 'amount_minor', 'bigint', Classification::Financial, 'What the gateway was asked to give back, tax included, in minor units of the order\'s currency.' ),
				new ColumnSpec( 'currency', 'char(3)', Classification::Public, 'The order\'s currency, ISO 4217.', collation: 'ascii_bin' ),
				new ColumnSpec( 'base_amount_minor', 'bigint', Classification::Financial, 'The same amount in minor units of the base currency, at the order\'s frozen rate: what a user\'s refund caps count.' ),
				new ColumnSpec( 'base_currency', 'char(3)', Classification::Public, 'The base currency the order was placed in, ISO 4217.', collation: 'ascii_bin' ),
				new ColumnSpec( 'shipping', 'tinyint(1)', Classification::Public, '1 when the refund asked for what was left of the shipping.', defaultValue: '0' ),
				new ColumnSpec( 'reason_code', 'varchar(64)', Classification::Public, 'Why the refund was asked for, such as customer_return.', collation: 'ascii_bin' ),
				new ColumnSpec( 'note', 'text', Classification::Pii, 'What the person who asked wrote about the refund; NULL when nothing. Refused when it holds a card number.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'key_hash', 'char(64)', Classification::Public, 'The SHA-256 of the idempotency key the caller sent, scoped to the user who asked, so a retry of the same request finds this refund; NULL for a refund asked without a key.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'request_fingerprint', 'char(64)', Classification::Public, 'The SHA-256 of the whole request the key was sent with, so the key is refused with another request; NULL without a key.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'actor_type', 'varchar(16)', Classification::Public, 'user for a person acting in person, system for a process acting on a user\'s authority.', collation: 'ascii_bin' ),
				new ColumnSpec(
					'actor_id',
					'bigint unsigned',
					Classification::Pii,
					'The WordPress user who asked for the refund.',
					nullable: true,
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'A claim keeps who asked for a refund for as long as the order is kept; the user id identifies no one once the user is erased.'
				),
				new ColumnSpec( 'transaction_id', 'bigint unsigned', Classification::Public, 'The ledger row the claim\'s own answer wrote, which ended it: the refund applied, the decline, or the money kept for a person; NULL while claimed, and for a claim the gateway answered with another refund\'s result.', nullable: true ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the gateway was about to be asked, UTC, from the database clock.' ),
				new ColumnSpec( 'settled_at', 'datetime(6)', Classification::Public, 'When the claim ended, UTC, from the database clock; NULL while claimed.', nullable: true ),
				new ColumnSpec( 'statement', 'varchar(16)', Classification::Public, 'What the person who settled the claim stated: refunded or not_refunded; NULL for a claim no person settled.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'gateway_reading', 'varchar(64)', Classification::Public, 'What the gateway said of the refund when a person settled the claim: approved, declined, not_found, cannot_say, or unavailable with why; NULL for a claim no person settled.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec(
					'settled_by',
					'bigint unsigned',
					Classification::Pii,
					'The WordPress user who settled the claim; NULL for a claim no person settled.',
					nullable: true,
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'A claim keeps who settled it for as long as the order is kept; the user id identifies no one once the user is erased.'
				),
				new ColumnSpec( 'settlement_note', 'text', Classification::Pii, 'What the person who settled the claim wrote about why; NULL for a claim no person settled. Refused when it holds a card number.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'One claim per refund: a second request for the same refund finds the first one\'s claim by it.' ),
				IndexSpec::unique( 'key_hash', array( 'key_hash' ), 'One refund per idempotency key: a retry finds its refund by it, and a second claim with the key is refused.' ),
			),
			array(
				IndexSpec::key( 'state_created', array( 'state', 'created_at' ), 'The claims still claimed long after they were made, oldest first, and those left for a person with no ledger row, for doctor.' ),
				IndexSpec::key( 'intent_state', array( 'intent_id', 'state' ), 'An intent\'s claim still claimed, which every other refund of the intent waits for.' ),
				IndexSpec::key( 'actor_created', array( 'actor_id', 'created_at' ), 'What a user asked of the gateway in the last 24 hours, which a capped user\'s refund is checked against.' ),
			),
			'financial',
			array(
				'orders -> refund_claims' => 'Written before the gateway is asked for a refund of the order, and ended in the transaction that records its answer; kept for as long as the order is.',
			)
		);
	}

	/**
	 * Declares `refund_claim_lines`: what a claim asked of each order line, by the line's public identifier.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function claimLines(): TableDefinition {
		return new TableDefinition(
			self::CLAIM_LINES,
			'Payment',
			'Records what each refund claim asked of each order line: the line, the units and whether they go back into stock, so the refund can be worked out again from its claim. What a refund returned is its document\'s lines.',
			MutationPattern::AppendOnly,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key, internal only.', autoIncrement: true ),
				new ColumnSpec( 'claim_id', 'bigint unsigned', Classification::Public, 'The claim.' ),
				new ColumnSpec( 'line_uuid', 'char(36)', Classification::Public, 'The order line\'s public identifier.', collation: 'ascii_bin' ),
				new ColumnSpec( 'quantity', 'int unsigned', Classification::Public, 'How many of the line\'s units the refund asked for.' ),
				new ColumnSpec( 'restock', 'tinyint(1)', Classification::Public, '1 when the units are to go back into stock.', defaultValue: '0' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'claim_line', array( 'claim_id', 'line_uuid' ), 'A claim asks for units of a line once; its lines are found by it.' ),
			),
			array(),
			'financial',
			array(
				'refund_claims -> refund_claim_lines' => 'Written with their claim, in the same statement for every line, and never changed; kept for as long as the claim is.',
			)
		);
	}

	/**
	 * Declares `refund_actor_locks`: one row for each user whose refunds have been capped, which each of the user's capped refunds locks while it adds up what the user asked that day.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function actorLocks(): TableDefinition {
		return new TableDefinition(
			self::ACTOR_LOCKS,
			'Payment',
			'Holds one row for each user whose refunds are capped by the day. Each such refund locks the user\'s row while it adds up what the user asked in the last 24 hours, so two of the user\'s refunds are checked one after the other; kept permanently because the row is the lock itself, one small row per user, which the user\'s next capped refund takes again.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key, internal only.', autoIncrement: true ),
				new ColumnSpec(
					'user_id',
					'bigint unsigned',
					Classification::Pii,
					'The WordPress user whose refunds wait on the row.',
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'The row only serialises the user\'s refunds, and holds nothing else; the user id identifies no one once the user is erased.'
				),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the user\'s first capped refund was asked for, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'user_id', array( 'user_id' ), 'One lock row per user, taken whether inserted or found.' ),
			),
			array(),
			'permanent',
			array()
		);
	}
}

<?php
/**
 * RefundClaimTables: the declaration of the refund claims
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
 * Declares `refund_claims`: one row for each refund the gateway was asked for, written before it was asked.
 *
 * Owns one fact: the shape of the refund claims. The migration that creates the table and the data
 * registry that lists it call the same factory.
 *
 * A claim is committed before the gateway is called, so a refund the gateway made is never one
 * nothing knows was asked for. It records what was asked (the refund's uuid, which the gateway
 * received as its key, the intent and the amount), by whom and when, and then how it ended:
 * `claimed` until the gateway's answer is recorded, then `recorded`, `declined` or `unreconciled`,
 * with the ledger row its own answer wrote, when there is one. A settled claim is kept with the
 * refund it asked for, for as long as the order is: it is the one record of an attempt that left
 * no refund document.
 *
 * @since 0.1.0
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
	 * Returns the declaration.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The claims.
	 */
	public static function all(): array {
		return array( self::claims() );
	}

	/**
	 * Returns the unprefixed name of the table.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The name.
	 */
	public static function names(): array {
		return array( self::CLAIMS );
	}

	/**
	 * Declares `refund_claims`.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function claims(): TableDefinition {
		return new TableDefinition(
			self::CLAIMS,
			'Payment',
			'Records each refund the gateway was asked for, before it was asked: the refund\'s uuid, the intent, the amount asked, who asked and when; and then how it ended, recorded, declined or left for a person, with the ledger row that ended it.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key, internal only.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', Classification::Public, 'The refund\'s uuid: the idempotency key the gateway received, and the uuid of its document once it is recorded.', collation: 'ascii_bin' ),
				new ColumnSpec( 'intent_id', 'bigint unsigned', Classification::Public, 'The payment intent the money is asked back through.' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order refunded.' ),
				new ColumnSpec( 'state', 'varchar(16)', Classification::Public, 'claimed until the gateway\'s answer is recorded; then recorded, declined or unreconciled.', collation: 'ascii_bin' ),
				new ColumnSpec( 'amount_minor', 'bigint', Classification::Financial, 'What the gateway was asked to give back, tax included, in minor units of the order\'s currency.' ),
				new ColumnSpec( 'currency', 'char(3)', Classification::Public, 'The order\'s currency, ISO 4217.', collation: 'ascii_bin' ),
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
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'One claim per refund: a second request for the same refund finds the first one\'s claim by it.' ),
			),
			array(
				IndexSpec::key( 'state_created', array( 'state', 'created_at' ), 'The claims still claimed long after they were made, oldest first, and those left for a person with no ledger row, for doctor.' ),
				IndexSpec::key( 'intent_state', array( 'intent_id', 'state' ), 'An intent\'s claim still claimed, which every other refund of the intent waits for.' ),
			),
			'financial',
			array(
				'orders -> refund_claims' => 'Written before the gateway is asked for a refund of the order, and ended in the transaction that records its answer; kept for as long as the order is.',
			)
		);
	}
}

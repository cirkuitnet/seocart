<?php
/**
 * RefundTables: the declarations of the refund tables
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
 * Declares the three tables of a refund: the document, its lines, and the tax components it returns.
 *
 * Owns one fact: the shape of the refund tables. The migration that creates them and the data
 * registry that lists them call the same factories.
 *
 * A refund row exists only for money a gateway returned and the ledger applied, so the tables
 * have no status and are appended to only. Every amount is a signed `bigint` of minor units in
 * the order's currency, with a `base_` twin at the order's own frozen rate: a refund is never
 * converted again, however many rate versions were published since the order was placed.
 *
 * @since 0.1.0
 */
final class RefundTables {

	/**
	 * The unprefixed name of the refund documents.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REFUNDS = 'refunds';

	/**
	 * The unprefixed name of the refunds' lines.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINES = 'refund_lines';

	/**
	 * The unprefixed name of the tax components the refunds return.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMPONENTS = 'refund_components';

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
	 * Returns the three declarations.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The documents, their lines and their components.
	 */
	public static function all(): array {
		return array( self::refunds(), self::lines(), self::components() );
	}

	/**
	 * Returns the unprefixed names of the three tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names, in the order of all().
	 */
	public static function names(): array {
		return array( self::REFUNDS, self::LINES, self::COMPONENTS );
	}

	/**
	 * Declares `refunds`: one refund decision, the money it returned and the rate it is stated at.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function refunds(): TableDefinition {
		return new TableDefinition(
			self::REFUNDS,
			self::MODULE,
			'Records each refund of an order a gateway made and the ledger applied: what it returned in total, of which tax, shipping and fees, in the order\'s currency and at the order\'s own frozen rate, why, and on whose authority.',
			MutationPattern::AppendOnly,
			array(
				self::id( 'Surrogate key: the refund id, internal only.' ),
				new ColumnSpec( 'uuid', 'char(36)', Classification::Public, 'The refund\'s public identifier, and the idempotency key its gateway received.', collation: 'ascii_bin' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order refunded.' ),
				new ColumnSpec( 'intent_id', 'bigint unsigned', Classification::Public, 'The payment intent the money went back through.' ),
				new ColumnSpec( 'transaction_id', 'bigint unsigned', Classification::Public, 'The ledger row of the gateway\'s refund: the money fact this document states.' ),
				new ColumnSpec( 'conversion_context_id', 'bigint unsigned', Classification::Public, 'The order\'s frozen exchange rate, copied from the order: every base amount of the refund is at it.' ),
				self::money( 'total_minor', 'What the refund returned, tax included: the sum of its lines\' gross and of the shipping it returned.' ),
				self::money( 'shipping_minor', 'The shipping it returned, before tax.' ),
				self::money( 'tax_minor', 'The tax it returned: the sum of its tax components\' tax.' ),
				self::money( 'fee_minor', 'The fees it returned, before tax.' ),
				self::currency( 'currency', 'The order\'s currency, which every amount of the refund is in, ISO 4217.' ),
				self::currency( 'base_currency', 'The store\'s base currency when the order was placed.' ),
				self::money( 'base_total_minor', 'total_minor in the base currency, at the order\'s frozen rate.' ),
				self::money( 'base_shipping_minor', 'shipping_minor in the base currency.' ),
				self::money( 'base_tax_minor', 'tax_minor in the base currency.' ),
				self::money( 'base_fee_minor', 'fee_minor in the base currency.' ),
				new ColumnSpec( 'reason_code', 'varchar(64)', Classification::Public, 'Why the order was refunded, a lowercase snake_case word such as customer_return.', collation: 'ascii_bin' ),
				new ColumnSpec( 'is_offline', 'tinyint(1)', Classification::Public, '1 when the money went back outside the gateway, such as in cash; 0 when the gateway returned it.', defaultValue: '0' ),
				new ColumnSpec( 'actor_type', 'varchar(16)', Classification::Public, 'user for a person acting in person, system for a process acting on a user\'s authority.', collation: 'ascii_bin' ),
				new ColumnSpec(
					'actor_id',
					'bigint unsigned',
					Classification::Pii,
					'The WordPress user who refunded the order.',
					nullable: true,
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'A refund keeps who made it for as long as the order is kept; the user id identifies no one once the user is erased.'
				),
				self::createdAt( 'When the refund was recorded, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'One refund per public identifier.' ),
				IndexSpec::unique( 'transaction_id', array( 'transaction_id' ), 'One document per ledger row: a second delivery of the gateway\'s refund finds the first document by it.' ),
			),
			array(
				IndexSpec::key( 'order_created', array( 'order_id', 'created_at' ), 'An order\'s refunds, and the shipping they already returned.' ),
			),
			self::FINANCIAL,
			array(
				'orders -> refunds' => 'Written only in the transaction that applies the gateway\'s refund to the ledger; kept for as long as the order is.',
			)
		);
	}

	/**
	 * Declares `refund_lines`: the units of each order line a refund returned, and their money.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function lines(): TableDefinition {
		return new TableDefinition(
			self::LINES,
			self::MODULE,
			'Records, for each order line a refund returned units of, how many, and their net, tax and gross in both currencies, allocated from what the line had left; and whether the units are to go back into stock.',
			MutationPattern::AppendOnly,
			array(
				self::id( 'Surrogate key, internal only.' ),
				new ColumnSpec( 'refund_id', 'bigint unsigned', Classification::Public, 'The refund.' ),
				new ColumnSpec( 'order_line_id', 'bigint unsigned', Classification::Public, 'The order line whose units were returned.' ),
				new ColumnSpec( 'quantity', 'int', Classification::Public, 'How many units were returned.' ),
				self::money( 'amount_minor', 'What the units returned, tax included: gross_minor, the line\'s part of the refund\'s total.' ),
				self::money( 'net_minor', 'The units\' net amount.' ),
				self::money( 'tax_minor', 'Their tax: the sum of the tax their tax components returned.' ),
				self::money( 'gross_minor', 'Their gross amount: net_minor plus tax_minor.' ),
				self::currency( 'currency', 'The order\'s currency, ISO 4217.' ),
				self::money( 'base_net_minor', 'net_minor in the base currency, at the order\'s frozen rate.' ),
				self::money( 'base_tax_minor', 'tax_minor in the base currency.' ),
				self::money( 'base_gross_minor', 'gross_minor in the base currency: base_net_minor plus base_tax_minor.' ),
				new ColumnSpec( 'restock', 'tinyint(1)', Classification::Public, '1 when the units are to go back into stock; recorded, and not yet acted on.', defaultValue: '0' ),
				self::createdAt( 'When the line was recorded, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'refund_line', array( 'refund_id', 'order_line_id' ), 'A refund returns units of an order line once; its lines are found by it.' ),
			),
			array(
				IndexSpec::key( 'order_line_id', array( 'order_line_id' ), 'What earlier refunds returned of an order line, and how many of its units.' ),
			),
			self::FINANCIAL,
			array(
				'refunds -> refund_lines' => 'Written only with their refund, in the same transaction; kept for as long as the refund is.',
			)
		);
	}

	/**
	 * Declares `refund_components`: the part of each persisted tax component a refund returned.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function components(): TableDefinition {
		return new TableDefinition(
			self::COMPONENTS,
			self::MODULE,
			'Records, for each tax component of an order a refund returned part of, the net it was charged on, the tax and the gross returned, in both currencies: the original allocation, returned in part, never recalculated at a later rate.',
			MutationPattern::AppendOnly,
			array(
				self::id( 'Surrogate key, internal only.' ),
				new ColumnSpec( 'refund_id', 'bigint unsigned', Classification::Public, 'The refund.' ),
				new ColumnSpec( 'refund_line_id', 'bigint unsigned', Classification::Public, 'The refund line the component belongs to; NULL for a component of the shipping.', nullable: true ),
				new ColumnSpec( 'order_tax_component_id', 'bigint unsigned', Classification::Public, 'The order\'s tax component, of the order\'s current totals, that this row returns part of.' ),
				self::money( 'net_minor', 'The part of the component\'s net returned: what its rate was charged on.' ),
				self::money( 'tax_minor', 'The part of the component\'s tax returned.' ),
				self::money( 'gross_minor', 'net_minor plus tax_minor; what the cumulative cap on the component adds up.' ),
				self::currency( 'currency', 'The order\'s currency, ISO 4217.' ),
				self::money( 'base_net_minor', 'net_minor in the base currency, at the order\'s frozen rate.' ),
				self::money( 'base_tax_minor', 'tax_minor in the base currency.' ),
				self::money( 'base_gross_minor', 'gross_minor in the base currency.' ),
				self::createdAt( 'When the row was recorded, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'refund_component', array( 'refund_id', 'order_tax_component_id' ), 'A refund returns part of a component once; its components are found by it.' ),
			),
			array(
				IndexSpec::key( 'order_tax_component_id', array( 'order_tax_component_id' ), 'What earlier refunds returned of a component: the cumulative cap.' ),
			),
			self::FINANCIAL,
			array(
				'refunds -> refund_components' => 'Written only with their refund, in the same transaction; kept for as long as the refund is.',
			)
		);
	}

	/**
	 * Declares an amount in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The column name, ending in `_minor`.
	 * @param string $note What the amount is.
	 * @return ColumnSpec The column.
	 */
	private static function money( string $name, string $note ): ColumnSpec {
		return new ColumnSpec( $name, 'bigint', Classification::Financial, $note );
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

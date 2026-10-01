<?php
/**
 * PromotionTables: the declarations of the three promotion tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Infrastructure;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `promotions`, `promotion_conditions` and `promotion_usage`.
 *
 * Owns one fact: the shape of the promotion tables. The migration that creates them and the
 * data registry that lists them call the same factories.
 *
 * A promotion's `used` is the count of its reserved and committed usage rows, maintained in the
 * same transaction as the rows by conditional updates that set `updated_at` from
 * `UTC_TIMESTAMP(6)`: `datetime(6)`, so a statement's affected-row count says whether its WHERE
 * matched, never whether a value happened to change. A fixed amount off carries its currency and
 * basis; a percentage carries neither. Conditions, thresholds, markets, per-customer limits and
 * stacking policies are stored in their final shape and not evaluated yet.
 *
 * @since 0.1.0
 */
final class PromotionTables {

	/**
	 * The unprefixed name of the promotion table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PROMOTIONS = 'promotions';

	/**
	 * The unprefixed name of the condition table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONDITIONS = 'promotion_conditions';

	/**
	 * The unprefixed name of the usage ledger.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const USAGE = 'promotion_usage';

	/**
	 * The module every table belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'Promotion';

	/**
	 * Returns the three declarations.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The promotion, condition and usage tables, in that order.
	 */
	public static function all(): array {
		return array( self::promotions(), self::conditions(), self::usage() );
	}

	/**
	 * Declares `promotions`: one row per promotion, the contended row every claimed use updates.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function promotions(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::PROMOTIONS,
			self::MODULE,
			'Holds each promotion: how it is triggered, its status, what it takes off, its window and its limits, with the count of its uses. Kept permanently because the orders and usage rows that used a promotion name it.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', $public, 'The promotion\'s stable public id, which the source of its adjustments names.', collation: 'ascii_bin' ),
				new ColumnSpec( 'code', 'varchar(64)', $public, 'The code a customer enters, compared exactly as stored; NULL for a promotion applied without a code.', nullable: true, collation: 'utf8mb4_bin' ),
				new ColumnSpec( 'trigger_kind', 'varchar(12)', $public, 'code for a promotion a code applies; automatic for one applied without a code.', defaultValue: 'code', collation: 'ascii_bin' ),
				new ColumnSpec( 'status', 'varchar(12)', $public, 'active, or draft, paused or archived; only an active promotion applies.', defaultValue: 'draft', collation: 'ascii_bin' ),
				new ColumnSpec( 'effect_kind', 'varchar(16)', $public, 'What the promotion takes off: percent of every line, a fixed amount off the order, or free_shipping.', collation: 'ascii_bin' ),
				new ColumnSpec( 'effect_percent_micropercent', 'int unsigned', $public, 'The percentage of a percent effect, in millionths of a percent; NULL otherwise.', nullable: true ),
				new ColumnSpec( 'effect_amount_minor', 'bigint', Classification::Financial, 'The amount of a fixed effect, in minor units of effect_currency; NULL otherwise.', nullable: true ),
				new ColumnSpec( 'effect_currency', 'char(3)', $public, 'ISO 4217 code of a fixed effect\'s amount; the promotion applies only to a cart in it. NULL otherwise.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'effect_amount_basis', 'varchar(8)', $public, 'net or gross: whether a fixed effect\'s amount is before tax or includes it. NULL otherwise.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'effect_tax_class_id', 'bigint unsigned', $public, 'The tax class of a fixed effect\'s amount, when it differs from the lines\'; NULL for theirs.', nullable: true ),
				new ColumnSpec( 'threshold_amount_minor', 'bigint', Classification::Financial, 'The subtotal a cart must reach for the promotion to apply, in minor units of threshold_currency; NULL for none.', nullable: true ),
				new ColumnSpec( 'threshold_currency', 'char(3)', $public, 'ISO 4217 code of the threshold; NULL for none.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'threshold_amount_basis', 'varchar(8)', $public, 'net or gross: whether the threshold is before tax or includes it; NULL for none.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'market_id', 'bigint unsigned', $public, 'The market the promotion is limited to; NULL for every market.', nullable: true ),
				new ColumnSpec( 'starts_at', 'datetime', $public, 'When the promotion starts applying, UTC; NULL for no start.', nullable: true ),
				new ColumnSpec( 'ends_at', 'datetime', $public, 'When the promotion stops applying, UTC; NULL for no end.', nullable: true ),
				new ColumnSpec( 'usage_limit', 'int unsigned', $public, 'How many orders may use the promotion; NULL for no limit.', nullable: true ),
				new ColumnSpec( 'used', 'int', Classification::Financial, 'How many orders hold a use of the promotion: its reserved and committed usage rows.', defaultValue: '0' ),
				new ColumnSpec( 'per_customer_limit', 'int unsigned', $public, 'How many orders one customer may use the promotion on; NULL for no limit.', nullable: true ),
				new ColumnSpec( 'stacking_policy', 'varchar(16)', $public, 'How the promotion combines with others: stack applies it beside every other one.', defaultValue: 'stack', collation: 'ascii_bin' ),
				new ColumnSpec( 'priority', 'int', $public, 'The order promotions apply in, lowest first; promotions of one priority apply in the order their codes were applied.', defaultValue: '0' ),
				new ColumnSpec( 'created_at', 'datetime(6)', $public, 'When the promotion was created, UTC, from the database clock.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', $public, 'When the row last changed, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'Finds a promotion by the id its adjustments name.' ),
				IndexSpec::unique( 'code', array( 'code' ), 'Finds the promotions of a cart\'s codes in one read; no two promotions share a code.' ),
			),
			array(
				IndexSpec::key( 'trigger_status_window', array( 'trigger_kind', 'status', 'starts_at', 'ends_at' ), 'The automatic promotions that apply now, in one range scan.' ),
				IndexSpec::key( 'status_priority', array( 'status', 'priority' ), 'The active promotions in the order they apply.' ),
				IndexSpec::key( 'market_id', array( 'market_id' ), 'The promotions limited to a market.' ),
			),
			'permanent',
			array()
		);
	}

	/**
	 * Declares `promotion_conditions`: the declarative rules that decide whether a promotion applies to a cart.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function conditions(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::CONDITIONS,
			self::MODULE,
			'Holds the conditions a cart must meet for a promotion to apply, in the order they are checked; read with their promotion.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'promotion_id', 'bigint unsigned', $public, 'The promotion the condition belongs to.' ),
				new ColumnSpec( 'condition_key', 'varchar(64)', $public, 'The kind of condition, such as a minimum quantity of a product.', collation: 'ascii_bin' ),
				new ColumnSpec( 'config_json', 'text', $public, 'The condition\'s settings, as a JSON object its kind declares.' ),
				new ColumnSpec( 'sort_order', 'int', $public, 'The order the promotion\'s conditions are checked in, from 1.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'promotion_order', array( 'promotion_id', 'sort_order' ), 'A promotion\'s conditions in order, one per position; also finds every condition of a promotion.' ),
			),
			array(),
			'entity_lifetime',
			array(
				'promotions -> promotion_conditions' => 'Cascade: a promotion\'s conditions are deleted with it.',
			)
		);
	}

	/**
	 * Declares `promotion_usage`: one row per order that uses a promotion, counted in the promotion's `used` while reserved or committed.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function usage(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::USAGE,
			self::MODULE,
			'Records each use of a promotion by an order and the net discount it gave: reserved when the order is placed, then committed when it is accepted or released when it is declined.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'promotion_id', 'bigint unsigned', $public, 'The promotion used.' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', $public, 'The order that uses it.' ),
				new ColumnSpec(
					'customer_id',
					'bigint unsigned',
					Classification::Pii,
					'The customer whose order uses it, which a per-customer limit counts; NULL for a guest.',
					nullable: true,
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'The use is kept with its order for the order\'s retention period, and the customer id is what a per-customer limit counts; it identifies no one once the user is erased.'
				),
				new ColumnSpec( 'cart_token_hash', 'char(64)', Classification::Secret, 'The SHA-256 of the token of the cart the order was placed from, in hexadecimal, which a per-customer limit counts for a guest; NULL for none.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'amount_minor', 'bigint', Classification::Financial, 'The discount the promotion gave the order before tax: the nets of its adjustments, signed as they are, zero or negative, in minor units of currency.' ),
				new ColumnSpec( 'currency', 'char(3)', $public, 'ISO 4217 code of the order\'s currency.', collation: 'ascii_bin' ),
				new ColumnSpec( 'base_amount_minor', 'bigint', Classification::Financial, 'The same net discount in the store\'s base currency, in its minor units.' ),
				new ColumnSpec( 'base_currency', 'char(3)', $public, 'ISO 4217 code of the store\'s base currency.', collation: 'ascii_bin' ),
				new ColumnSpec( 'state', 'varchar(12)', $public, 'reserved while the order awaits its payment, committed once it is accepted, released once it is declined.', collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime(6)', $public, 'When the use was reserved, UTC, from the database clock.' ),
				new ColumnSpec( 'released_at', 'datetime', $public, 'When the use was released, UTC; NULL until then.', nullable: true ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'promotion_order', array( 'promotion_id', 'order_id' ), 'One use of a promotion per order; also counts a promotion\'s uses for doctor.' ),
			),
			array(
				IndexSpec::key( 'promotion_customer', array( 'promotion_id', 'customer_id' ), 'A customer\'s uses of a promotion, for a per-customer limit.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'Commits or releases every use of an order.' ),
				IndexSpec::key( 'state_created', array( 'state', 'created_at' ), 'Finds uses left reserved too long.' ),
			),
			'financial',
			array(
				'promotions -> promotion_usage' => 'Block: a promotion with uses is never deleted, since its uses and their orders name it.',
			)
		);
	}
}

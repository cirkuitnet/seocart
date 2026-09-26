<?php
/**
 * OrderTables: the declarations of the order tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Infrastructure;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the ten tables of the order module.
 *
 * Owns one fact: the shape of the order tables. The migration that creates them and the data
 * registry that lists them call the same factories.
 *
 * An order is stored as what was sold, at the prices and in the words of the moment it was
 * placed: every line, label and address is a snapshot, and no column is ever read back from a
 * catalog or customer row to display it. Every amount is a signed `bigint` of minor units with
 * its currency beside it, and every amount an order owns has a `base_` twin in the store's base
 * currency, written from the first row, so no report branches on a missing value. Times other
 * code compares with the database clock are written by it.
 *
 * `conversion_contexts` belongs here because an order cannot be written without one: each order
 * references the exchange rate it was placed at, frozen as an immutable row.
 *
 * @since 0.1.0
 */
final class OrderTables {

	/**
	 * The unprefixed name of the order table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ORDERS = 'orders';

	/**
	 * The unprefixed name of the order line table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINES = 'order_lines';

	/**
	 * The unprefixed name of the order line option table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINE_OPTIONS = 'order_line_options';

	/**
	 * The unprefixed name of the order tax component table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TAX_COMPONENTS = 'order_tax_components';

	/**
	 * The unprefixed name of the order adjustment table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADJUSTMENTS = 'order_adjustments';

	/**
	 * The unprefixed name of the order address table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADDRESSES = 'order_addresses';

	/**
	 * The unprefixed name of the totals snapshot table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TOTALS = 'order_totals';

	/**
	 * The unprefixed name of the order event table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const EVENTS = 'order_events';

	/**
	 * The unprefixed name of the order number counter table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NUMBER_SEQUENCE = 'order_number_seq';

	/**
	 * The unprefixed name of the frozen exchange rate table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONVERSION_CONTEXTS = 'conversion_contexts';

	/**
	 * The module every table belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'Order';

	/**
	 * The retention policy of an order and its children.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FINANCIAL = 'financial';

	/**
	 * The retention policy of the rows that are never removed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PERMANENT = 'permanent';

	/**
	 * Why the eraser keeps who acted on an order.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ACTOR_RETAINED = 'The order record keeps who acted on the order for as long as the order is kept; the user id identifies no one once the user is erased.';

	/**
	 * Returns the ten declarations.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The order tables, the counter and the conversion contexts.
	 */
	public static function all(): array {
		return array(
			self::orders(),
			self::lines(),
			self::lineOptions(),
			self::taxComponents(),
			self::adjustments(),
			self::addresses(),
			self::totals(),
			self::events(),
			self::numberSequence(),
			self::conversionContexts(),
		);
	}

	/**
	 * Returns the unprefixed names of the ten tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names, in the order of all().
	 */
	public static function names(): array {
		return array( self::ORDERS, self::LINES, self::LINE_OPTIONS, self::TAX_COMPONENTS, self::ADJUSTMENTS, self::ADDRESSES, self::TOTALS, self::EVENTS, self::NUMBER_SEQUENCE, self::CONVERSION_CONTEXTS );
	}

	/**
	 * Declares `orders`: the financial root, with its status, its totals and the payment projection.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function orders(): TableDefinition {
		return new TableDefinition(
			self::ORDERS,
			self::MODULE,
			'Records each order: who placed it, in which currency, locale and rate, its status, the totals the calculation produced, and what its payments have moved so far.',
			MutationPattern::MutableTransactional,
			array_merge(
				array(
					self::id( 'Surrogate key: the order id, internal only; a storefront names an order by its uuid.' ),
					self::uuid( 'uuid', 'The order\'s public identifier: the only way a storefront request names an order.' ),
					new ColumnSpec( 'order_number', 'varchar(32)', Classification::Public, 'The number shown to people, allocated when the order is placed; not gapless, and never a lookup key on a public route.', collation: 'ascii_bin' ),
					new ColumnSpec( 'kind', 'varchar(16)', Classification::Public, 'What kind of order this is; standard for every order placed at checkout.', defaultValue: 'standard', collation: 'ascii_bin' ),
					new ColumnSpec( 'parent_order_id', 'bigint unsigned', Classification::Public, 'The order this one was derived from; NULL for an order placed on its own.', nullable: true ),
					new ColumnSpec( 'channel', 'varchar(12)', Classification::Public, 'Where the order came from: storefront, admin, api or import.', collation: 'ascii_bin' ),
					self::actorType( 'user for a person placing the order in person, system for a process placing it on a user\'s authority.' ),
					self::actorId( 'The WordPress user who placed the order; NULL for a visitor who is not logged in.' ),
					new ColumnSpec( 'status', 'varchar(32)', Classification::Public, 'The order\'s status in the order status registry; changed only by the conditional update the registry\'s transitions compile to.', defaultValue: 'pending_payment', collation: 'ascii_bin' ),
					new ColumnSpec( 'payment_status', 'varchar(24)', Classification::Public, 'What the order\'s payments amount to so far, derived from the payment ledger in the same transaction as each ledger row.', defaultValue: 'unpaid', collation: 'ascii_bin' ),
					new ColumnSpec( 'fulfillment_status', 'varchar(24)', Classification::Public, 'How much of the order has been shipped or returned.', defaultValue: 'unfulfilled', collation: 'ascii_bin' ),
					new ColumnSpec(
						'customer_id',
						'bigint unsigned',
						Classification::Pii,
						'The WordPress user the order belongs to; NULL for a guest order.',
						nullable: true,
						erasure: ColumnSpec::ERASE_RETAIN,
						retainedBecause: 'The order is kept for its retention period, and the user id is what its "my orders" list and access check read; it identifies no one once the user is erased.'
					),
					new ColumnSpec( 'email', 'varchar(255)', Classification::Pii, 'The e-mail address the order\'s messages go to, as given at checkout.', erasure: ColumnSpec::ERASE_ANONYMIZE ),
					self::currency( 'currency', 'The one currency of every amount of the order, ISO 4217.' ),
					self::currency( 'base_currency', 'The store\'s base currency when the order was placed: the currency of every base_ amount.' ),
					new ColumnSpec( 'conversion_context_id', 'bigint unsigned', Classification::Public, 'The frozen exchange rate the order was placed at; a refund and a report convert at it, never at today\'s rate.' ),
					self::locale( 'locale', 'The WordPress locale the order was placed in, which its snapshots and messages are written in.' ),
					new ColumnSpec( 'market_id', 'bigint unsigned', Classification::Public, 'The market the order was placed in; NULL when the store has none.', nullable: true ),
					new ColumnSpec( 'tax_display_mode_snapshot', 'varchar(8)', Classification::Public, 'Whether prices were shown with or without tax when the order was placed; NULL when not recorded.', nullable: true, collation: 'ascii_bin' ),
					new ColumnSpec( 'cross_zone_policy_snapshot', 'varchar(12)', Classification::Public, 'The cross-zone tax policy the totals were calculated under.', collation: 'ascii_bin' ),
					new ColumnSpec( 'tax_rounding_mode_snapshot', 'varchar(16)', Classification::Public, 'The tax rounding mode the totals were calculated under.', collation: 'ascii_bin' ),
					new ColumnSpec( 'applied_exemption_record_id', 'bigint unsigned', Classification::Public, 'The tax exemption applied to the order; NULL when none was.', nullable: true ),
					new ColumnSpec( 'applied_exemption_kind', 'varchar(16)', Classification::Public, 'What kind of tax exemption was applied; NULL when none was.', nullable: true, collation: 'ascii_bin' ),
					new ColumnSpec( 'applied_exemption_evidence_id', 'bigint unsigned', Classification::Public, 'The evidence the tax exemption was granted on; NULL when none was.', nullable: true ),
					new ColumnSpec( 'presented_tax_id', 'varchar(64)', Classification::Pii, 'The tax identifier the customer presented at checkout; NULL when none was.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				),
				self::totalColumns( '' ),
				array(
					self::money( 'authorized_minor', 'Authorized so far and not yet reversed: the sum of the applied, approved authorize rows of the order\'s payment ledger.', '0' ),
					self::money( 'paid_minor', 'Captured so far: the sum of the applied, approved capture rows of the order\'s payment ledger.', '0' ),
					self::money( 'refunded_minor', 'Refunded so far: the sum of the applied, approved refund rows of the order\'s payment ledger.', '0' ),
					self::money( 'due_minor', 'Still to be paid: the amount due the calculation produced, less what was captured, plus what was refunded.', '0' ),
				),
				self::totalColumns( 'base_' ),
				array(
					self::money( 'base_authorized_minor', 'authorized_minor in the base currency, from the payment intents\' frozen base amounts.', '0' ),
					self::money( 'base_paid_minor', 'paid_minor in the base currency, from the payment intents\' frozen base amounts.', '0' ),
					self::money( 'base_refunded_minor', 'refunded_minor in the base currency, from the payment intents\' frozen base amounts.', '0' ),
					new ColumnSpec( 'current_totals_id', 'bigint unsigned', Classification::Public, 'The order_totals row the order\'s totals were copied from: the one whose is_current is 1. NULL only inside the transaction that places the order.', nullable: true ),
					new ColumnSpec( 'access_key_hash', 'varchar(255)', Classification::Secret, 'The hash of the order\'s access key, with which a guest reads the order; the key itself is never stored.', nullable: true, collation: 'ascii_bin' ),
					new ColumnSpec( 'access_key_expires_at', 'datetime', Classification::Public, 'When the access key stops working, UTC, from the database clock; NULL when the order has no key.', nullable: true ),
					new ColumnSpec( 'has_unreconciled_money', 'tinyint(1)', Classification::Public, '1 when a payment result could not be reconciled with the order, so a person must look at it.', defaultValue: '0' ),
					new ColumnSpec( 'payment_due_date', 'date', Classification::Public, 'When payment on terms is due; NULL for an order paid at checkout.', nullable: true ),
					new ColumnSpec( 'payment_schedule_id', 'bigint unsigned', Classification::Public, 'The payment schedule the order is paid on; NULL for an order paid at once.', nullable: true ),
					new ColumnSpec( 'hold_group', 'char(36)', Classification::Public, 'The stock hold placement took for the order, which settling the payment converts or releases; NULL when nothing was held. Read only with the order\'s own row.', nullable: true, collation: 'ascii_bin' ),
					new ColumnSpec( 'client_ip', 'varchar(45)', Classification::Pii, 'The IP address the order was placed from, for fraud review; NULL when unknown.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
					new ColumnSpec( 'user_agent', 'varchar(255)', Classification::Pii, 'The browser the order was placed from, for fraud review; NULL when unknown.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
					self::correlationId( 'The correlation id of the request that placed the order, shared with its events.' ),
					new ColumnSpec( 'placed_at', 'datetime', Classification::Public, 'When the order was placed, UTC, from the database clock.' ),
					new ColumnSpec( 'anonymized_at', 'datetime', Classification::Public, 'When the order\'s personal data was anonymized; NULL until it is.', nullable: true ),
					self::createdAt( 'When the row was written, UTC, from the database clock.' ),
					new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the row last changed, UTC, from the database clock.' ),
				)
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'One order per public identifier: the storefront\'s only lookup.' ),
				IndexSpec::unique( 'order_number', array( 'order_number' ), 'Two orders never share a number, whatever two placements do at once.' ),
			),
			array(
				IndexSpec::key( 'unreconciled', array( 'has_unreconciled_money' ), 'The orders a person must reconcile, which doctor reports.' ),
			),
			self::FINANCIAL,
			array(
				'orders -> order children' => 'Cascade: an order\'s lines, options, tax components, adjustments, addresses, totals and events are written with it by the order service and never deleted apart from it; erasure anonymizes the order instead of deleting it.',
				'users -> orders'          => 'Retain: an order outlives its customer; erasure anonymizes the order\'s personal columns and keeps the rest.',
			)
		);
	}

	/**
	 * Declares `order_lines`: what was sold, as it was described and priced when the order was placed.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function lines(): TableDefinition {
		return new TableDefinition(
			self::LINES,
			self::MODULE,
			'Records each line of an order as it was sold: the words it was sold in and its amounts in the order currency and the base currency, never read back from the catalog.',
			MutationPattern::MutableTransactional,
			array(
				self::id( 'Surrogate key.' ),
				self::orderId( 'The order the line belongs to.' ),
				self::uuid( 'line_uuid', 'The line\'s public identifier.' ),
				new ColumnSpec( 'variant_id', 'bigint unsigned', Classification::Public, 'The variant sold, for reporting and restocking; never joined to display the line.' ),
				new ColumnSpec( 'product_id', 'bigint unsigned', Classification::Public, 'The product sold, for reporting; never joined to display the line.' ),
				new ColumnSpec( 'sku_snapshot', 'varchar(64)', Classification::Public, 'The SKU when the order was placed.' ),
				new ColumnSpec( 'title_snapshot', 'varchar(255)', Classification::Public, 'The product title when the order was placed, in the order\'s locale.' ),
				new ColumnSpec( 'variant_label_snapshot', 'varchar(255)', Classification::Public, 'The variant\'s label when the order was placed, in the order\'s locale; empty for a product with one variant.' ),
				self::locale( 'locale_snapshot', 'The locale the snapshots are written in.' ),
				new ColumnSpec( 'quantity', 'int', Classification::Financial, 'Units sold.' ),
				self::basis( 'unit_amount_basis', 'Whether the unit price was authored net or gross of tax.' ),
				self::money( 'unit_price_minor', 'The unit price as authored, in unit_amount_basis.' ),
				self::money( 'unit_price_gross_minor', 'The unit price with tax, for display.' ),
				new ColumnSpec( 'unit_compare_at_minor', 'bigint', Classification::Financial, 'The compare-at unit price shown beside the price; NULL when there was none.', nullable: true ),
				self::money( 'line_subtotal_minor', 'The line before discounts, as authored.' ),
				self::money( 'line_discount_minor', 'The discounts applied to the line: the sum of its line-scoped discount adjustments.' ),
				self::money( 'line_net_minor', 'The line after discounts, without tax.' ),
				self::money( 'line_tax_minor', 'The tax of the line after discounts: the sum of its tax components.' ),
				self::money( 'line_gross_minor', 'The line after discounts, with tax: net plus tax.' ),
				self::money( 'line_total_minor', 'The line total shown on the order.' ),
				self::currency( 'currency', 'The order\'s currency.' ),
				self::currency( 'base_currency', 'The order\'s base currency.' ),
				self::money( 'base_line_net_minor', 'line_net_minor in the base currency, allocated so the lines sum to the order\'s.' ),
				self::money( 'base_line_discount_minor', 'line_discount_minor in the base currency.' ),
				self::money( 'base_line_tax_minor', 'line_tax_minor in the base currency, allocated so the lines sum to the order\'s.' ),
				self::money( 'base_line_gross_minor', 'line_gross_minor in the base currency: base net plus base tax.' ),
				new ColumnSpec( 'tax_class_snapshot', 'varchar(64)', Classification::Public, 'The tax class the line was taxed under; NULL for the standard class.', nullable: true ),
				new ColumnSpec( 'is_taxable', 'tinyint(1)', Classification::Public, '1 when the line was taxed.', defaultValue: '0' ),
				new ColumnSpec( 'price_source', 'varchar(16)', Classification::Public, 'Where the unit price came from, for example explicit or converted.', collation: 'ascii_bin' ),
				new ColumnSpec( 'price_locked', 'tinyint(1)', Classification::Public, '1 when an edit of the order may not reprice the line.', defaultValue: '0' ),
				new ColumnSpec( 'shipped_quantity', 'int', Classification::Financial, 'Units of the line shipped so far.', defaultValue: '0' ),
				new ColumnSpec( 'refunded_quantity', 'int', Classification::Financial, 'Units of the line refunded so far.', defaultValue: '0' ),
				new ColumnSpec( 'restock_policy', 'varchar(12)', Classification::Public, 'What a return or cancellation does to the line\'s stock.', defaultValue: 'restock', collation: 'ascii_bin' ),
				self::sortOrder( 'Where the line is shown among the order\'s lines, from 0.' ),
				self::createdAt( 'When the line was written, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'order_line', array( 'order_id', 'line_uuid' ), 'One line per identifier within an order.' ),
			),
			array(
				IndexSpec::key( 'order_sort', array( 'order_id', 'sort_order' ), 'An order\'s lines in their order: the read after the insert, and the order\'s read.' ),
			),
			self::FINANCIAL,
			array(
				'orders -> order_lines'   => 'Written only with their order, in the transaction that places it.',
				'variants -> order_lines' => 'Retain: a line keeps its variant id and its snapshots after the variant or product is deleted.',
			)
		);
	}

	/**
	 * Declares `order_line_options`: the option labels a line was sold with, in the order's words.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function lineOptions(): TableDefinition {
		return new TableDefinition(
			self::LINE_OPTIONS,
			self::MODULE,
			'Records the options each order line was sold with, one row per option axis, with the labels as the shopper saw them.',
			MutationPattern::MutableTransactional,
			array(
				self::id( 'Surrogate key.' ),
				new ColumnSpec( 'order_line_id', 'bigint unsigned', Classification::Public, 'The order line the option belongs to.' ),
				new ColumnSpec( 'axis_key_snapshot', 'varchar(64)', Classification::Public, 'The option axis, for example size.' ),
				new ColumnSpec( 'axis_label_snapshot', 'varchar(255)', Classification::Public, 'The axis label the shopper saw.' ),
				new ColumnSpec( 'value_key_snapshot', 'varchar(64)', Classification::Public, 'The option value chosen, for example m.' ),
				new ColumnSpec( 'value_label_snapshot', 'varchar(255)', Classification::Public, 'The value label the shopper saw.' ),
				self::locale( 'locale_snapshot', 'The locale the labels are written in.' ),
				new ColumnSpec( 'position', 'int', Classification::Public, 'Where the option is shown among the line\'s options, from 0.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'line_axis', array( 'order_line_id', 'axis_key_snapshot' ), 'One value per option axis of a line.' ),
			),
			array(
				IndexSpec::key( 'line_position', array( 'order_line_id', 'position' ), 'A line\'s options in their order, for the order\'s read.' ),
			),
			self::FINANCIAL,
			array(
				'order_lines -> order_line_options' => 'Written only with their line, in the transaction that places the order.',
			)
		);
	}

	/**
	 * Declares `order_tax_components`: the tax of each line and adjustment per jurisdiction, as allocated.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function taxComponents(): TableDefinition {
		return new TableDefinition(
			self::TAX_COMPONENTS,
			self::MODULE,
			'Records the tax of each order line and adjustment per jurisdiction as the calculation allocated it, with the residual minor unit, so a refund reverses the original allocation and never recomputes it.',
			MutationPattern::AppendOnly,
			array(
				self::id( 'Surrogate key.' ),
				self::uuid( 'component_uuid', 'The component\'s identifier.' ),
				self::orderId( 'The order the component belongs to.' ),
				new ColumnSpec( 'order_line_id', 'bigint unsigned', Classification::Public, 'The line taxed; NULL for an adjustment\'s component.', nullable: true ),
				new ColumnSpec( 'order_adjustment_id', 'bigint unsigned', Classification::Public, 'The adjustment taxed; NULL for a line\'s component.', nullable: true ),
				new ColumnSpec( 'totals_version', 'int', Classification::Public, 'The order_totals version the component belongs to.' ),
				new ColumnSpec( 'scope', 'varchar(12)', Classification::Public, 'What is taxed: line or adjustment.', collation: 'ascii_bin' ),
				new ColumnSpec( 'jurisdiction_code', 'varchar(32)', Classification::Public, 'The jurisdiction the tax is owed to.', collation: 'ascii_bin' ),
				new ColumnSpec( 'tax_rate_id_snapshot', 'bigint unsigned', Classification::Public, 'The tax rate applied; NULL when the rate came from a quote.', nullable: true ),
				new ColumnSpec( 'rate_name_snapshot', 'varchar(100)', Classification::Public, 'The rate\'s name when the order was placed.' ),
				new ColumnSpec( 'rate_micropercent', 'int', Classification::Financial, 'The rate, in millionths of a percent.' ),
				new ColumnSpec( 'is_compound', 'tinyint(1)', Classification::Public, '1 when the rate is applied on top of the rates before it.', defaultValue: '0' ),
				new ColumnSpec( 'priority', 'int', Classification::Public, 'The order the rate was applied in among compound rates.' ),
				self::basis( 'authored_amount_basis', 'Whether the taxed amount was authored net or gross of tax.' ),
				self::money( 'net_minor', 'The taxed amount without tax.' ),
				self::money( 'tax_minor', 'The tax, as allocated.' ),
				self::money( 'gross_minor', 'Net plus tax.' ),
				self::currency( 'currency', 'The order\'s currency.' ),
				self::money( 'base_net_minor', 'net_minor in the base currency.' ),
				self::money( 'base_tax_minor', 'tax_minor in the base currency.' ),
				self::money( 'base_gross_minor', 'gross_minor in the base currency.' ),
				new ColumnSpec( 'residual_minor', 'int', Classification::Financial, 'What the allocation added to or took from the component\'s exact share, 0 or 1 minor unit.' ),
				self::createdAt( 'When the component was written, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'order_component', array( 'order_id', 'component_uuid' ), 'One row per component within an order.' ),
			),
			array(
				IndexSpec::key( 'order_version', array( 'order_id', 'totals_version' ), 'The order\'s current allocation in one read, for a refund and doctor.' ),
				IndexSpec::key( 'line_version', array( 'order_line_id', 'totals_version' ), 'A line\'s components, for the arithmetic of a refund of that line.' ),
			),
			self::FINANCIAL,
			array(
				'order_lines -> order_tax_components' => 'Written only with their line, in the transaction that places the order.',
				'order_adjustments -> order_tax_components' => 'Written only with their adjustment, in the transaction that places the order.',
			)
		);
	}

	/**
	 * Declares `order_adjustments`: every signed change to a total, each with its source.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function adjustments(): TableDefinition {
		return new TableDefinition(
			self::ADJUSTMENTS,
			self::MODULE,
			'Records every signed change to an order\'s totals, a discount, a shipping charge or a fee, each with the source that made it and its amounts in the order and base currencies.',
			MutationPattern::MutableTransactional,
			array(
				self::id( 'Surrogate key.' ),
				self::orderId( 'The order the adjustment belongs to.' ),
				new ColumnSpec( 'order_line_id', 'bigint unsigned', Classification::Public, 'The line a line-scoped adjustment changes; NULL otherwise.', nullable: true ),
				new ColumnSpec( 'scope', 'varchar(12)', Classification::Public, 'What the adjustment changes: line, shipping or order.', collation: 'ascii_bin' ),
				new ColumnSpec( 'type', 'varchar(24)', Classification::Public, 'What the adjustment is: discount, shipping or fee.', collation: 'ascii_bin' ),
				new ColumnSpec( 'source', 'varchar(100)', Classification::Public, 'What made the adjustment, for example promotion:<uuid> or shipping:<method>; every adjustment has one.', collation: 'ascii_bin' ),
				new ColumnSpec( 'label', 'varchar(255)', Classification::Public, 'The label shown on the order, in label_locale.' ),
				self::locale( 'label_locale', 'The locale the label is written in.' ),
				self::basis( 'authored_amount_basis', 'Whether the amount was authored net or gross of tax.' ),
				self::money( 'amount_minor', 'The amount as authored, in authored_amount_basis, signed.' ),
				self::money( 'net_minor', 'The adjustment without tax, signed.' ),
				self::money( 'tax_minor', 'The adjustment\'s tax, signed.' ),
				self::money( 'gross_minor', 'The adjustment with tax, signed: net plus tax.' ),
				self::currency( 'currency', 'The order\'s currency.' ),
				self::currency( 'base_currency', 'The order\'s base currency.' ),
				self::money( 'base_amount_minor', 'amount_minor in the base currency.' ),
				self::money( 'base_net_minor', 'net_minor in the base currency.' ),
				self::money( 'base_tax_minor', 'tax_minor in the base currency.' ),
				self::money( 'base_gross_minor', 'gross_minor in the base currency.' ),
				new ColumnSpec( 'calculation_base', 'varchar(24)', Classification::Public, 'What a percentage adjustment was calculated on; NULL for a fixed amount.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'tax_class_snapshot', 'varchar(64)', Classification::Public, 'The tax class the adjustment was taxed under; NULL for the standard class.', nullable: true ),
				new ColumnSpec( 'is_taxable', 'tinyint(1)', Classification::Public, '1 when the adjustment was taxed.', defaultValue: '0' ),
				new ColumnSpec( 'actor_type', 'varchar(16)', Classification::Public, 'Who made an adjustment by hand: user or system; NULL for one the calculation made.', nullable: true, collation: 'ascii_bin' ),
				self::actorId( 'The WordPress user who made the adjustment by hand; NULL for one the calculation made.' ),
				self::sortOrder( 'Where the adjustment is shown among the order\'s adjustments, from 0.' ),
				self::createdAt( 'When the adjustment was written, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(),
			array(
				IndexSpec::key( 'order_scope_sort', array( 'order_id', 'scope', 'sort_order' ), 'An order\'s adjustments in their order: the read after the insert, and the order\'s read.' ),
			),
			self::FINANCIAL,
			array(
				'orders -> order_adjustments' => 'Written only with their order, in the transaction that places it.',
			)
		);
	}

	/**
	 * Declares `order_addresses`: the billing and shipping addresses as given at checkout.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function addresses(): TableDefinition {
		$anonymize = static fn( string $name, string $type, string $note ): ColumnSpec => new ColumnSpec( $name, $type, Classification::Pii, $note, erasure: ColumnSpec::ERASE_ANONYMIZE );
		$retained  = 'The country and region stay after erasure as the record of where the sale was taxed.';

		return new TableDefinition(
			self::ADDRESSES,
			self::MODULE,
			'Records the billing and shipping addresses of an order as they were given at checkout, never read back from a customer\'s saved addresses.',
			MutationPattern::MutableTransactional,
			array(
				self::id( 'Surrogate key.' ),
				self::orderId( 'The order the address belongs to.' ),
				new ColumnSpec( 'role', 'varchar(8)', Classification::Public, 'billing or shipping.', collation: 'ascii_bin' ),
				$anonymize( 'first_name', 'varchar(100)', 'The given name.' ),
				$anonymize( 'last_name', 'varchar(100)', 'The family name.' ),
				$anonymize( 'company', 'varchar(255)', 'The company name; empty when not given.' ),
				$anonymize( 'line1', 'varchar(255)', 'The first address line.' ),
				$anonymize( 'line2', 'varchar(255)', 'The second address line; empty when not given.' ),
				$anonymize( 'city', 'varchar(100)', 'The city or town.' ),
				new ColumnSpec( 'region', 'varchar(64)', Classification::Pii, 'The state, province, county or other subdivision; empty when not given.', erasure: ColumnSpec::ERASE_RETAIN, retainedBecause: $retained ),
				$anonymize( 'postcode', 'varchar(32)', 'The postal code.' ),
				new ColumnSpec( 'country', 'char(2)', Classification::Pii, 'The ISO 3166-1 alpha-2 country code.', collation: 'ascii_bin', erasure: ColumnSpec::ERASE_RETAIN, retainedBecause: $retained ),
				$anonymize( 'phone', 'varchar(32)', 'The phone number; empty when not given.' ),
				$anonymize( 'email', 'varchar(255)', 'The e-mail address; empty when not given.' ),
				$anonymize( 'tax_id', 'varchar(64)', 'The tax identifier, such as a VAT number; empty when not given.' ),
				new ColumnSpec( 'validated_at', 'datetime', Classification::Public, 'When a provider validated the address; NULL when none did.', nullable: true ),
				new ColumnSpec( 'validation_provider', 'varchar(32)', Classification::Public, 'The provider that validated the address; NULL when none did.', nullable: true, collation: 'ascii_bin' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'order_role', array( 'order_id', 'role' ), 'One billing and one shipping address per order.' ),
			),
			array(),
			self::FINANCIAL,
			array(
				'orders -> order_addresses' => 'Written only with their order, in the transaction that places it; anonymized with it on erasure, except the country and region.',
			)
		);
	}

	/**
	 * Declares `order_totals`: each totals snapshot the calculation produced for an order, with its trace.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function totals(): TableDefinition {
		return new TableDefinition(
			self::TOTALS,
			self::MODULE,
			'Records every totals snapshot calculated for an order, with the settings it was calculated under and the calculation trace that explains it; a recalculation adds a version, and exactly one version is current.',
			MutationPattern::AppendOnly,
			array_merge(
				array(
					self::id( 'Surrogate key.' ),
					self::orderId( 'The order the snapshot belongs to.' ),
					new ColumnSpec( 'version', 'int', Classification::Public, 'The snapshot\'s version within the order, from 1.' ),
					new ColumnSpec( 'is_current', 'tinyint(1)', Classification::Public, '1 for the order\'s current snapshot, NULL for every older one, so a unique key allows exactly one current row.', nullable: true ),
					self::currency( 'currency', 'The order\'s currency.' ),
					self::currency( 'base_currency', 'The order\'s base currency.' ),
					new ColumnSpec( 'conversion_context_id', 'bigint unsigned', Classification::Public, 'The frozen exchange rate the snapshot was calculated at.' ),
				),
				self::totalColumns( '' ),
				self::totalColumns( 'base_' ),
				array(
					new ColumnSpec( 'tax_rounding_mode', 'varchar(16)', Classification::Public, 'The tax rounding mode the snapshot was calculated under.', collation: 'ascii_bin' ),
					new ColumnSpec( 'price_entry_mode', 'varchar(8)', Classification::Public, 'Whether the store\'s prices were entered net or gross of tax.', collation: 'ascii_bin' ),
					new ColumnSpec( 'cross_zone_policy', 'varchar(12)', Classification::Public, 'The cross-zone tax policy the snapshot was calculated under.', collation: 'ascii_bin' ),
					new ColumnSpec( 'tax_display_mode', 'varchar(8)', Classification::Public, 'Whether prices were shown with or without tax; NULL when not recorded.', nullable: true, collation: 'ascii_bin' ),
					new ColumnSpec( 'market_id', 'bigint unsigned', Classification::Public, 'The market the snapshot was calculated for; NULL when the store has none.', nullable: true ),
					self::locale( 'locale', 'The locale the snapshot was calculated in.' ),
					new ColumnSpec( 'rate_version', 'bigint unsigned', Classification::Public, 'The version of the exchange rates the calculation read; NULL when it converted nothing.', nullable: true ),
					new ColumnSpec( 'tax_input_fingerprint', 'char(64)', Classification::Public, 'A fingerprint of the tax inputs, for telling whether an edit changes the tax; NULL when not recorded.', nullable: true, collation: 'ascii_bin' ),
					new ColumnSpec( 'quote_expiry', 'datetime', Classification::Public, 'When the shipping and tax quotes the snapshot used expire; NULL when not recorded.', nullable: true ),
					new ColumnSpec( 'trace_json', 'mediumtext', Classification::Public, 'The calculation trace: every step, input, rounding and selection that produced the totals, as JSON. Public, because it holds no personal data: its entries are scalars, a destination appears only as its country, and a tax exemption only as an opaque reference to its evidence.' ),
					self::createdAt( 'When the snapshot was written, UTC, from the database clock.' ),
				)
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'order_version', array( 'order_id', 'version' ), 'One snapshot per version of an order.' ),
				IndexSpec::unique( 'order_current', array( 'order_id', 'is_current' ), 'At most one current snapshot per order: many NULLs, one 1.' ),
			),
			array(),
			self::FINANCIAL,
			array(
				'orders -> order_totals' => 'Written with their order; the order\'s current_totals_id names the current one.',
			)
		);
	}

	/**
	 * Declares `order_events`: every change of an order's status and payment status, appended and never changed.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function events(): TableDefinition {
		return new TableDefinition(
			self::EVENTS,
			self::MODULE,
			'Records every change of an order\'s status and of its payment status, from what to what, why and on whose authority, in the transaction that made it.',
			MutationPattern::AppendOnly,
			array(
				self::id( 'Surrogate key.' ),
				self::orderId( 'The order that changed.' ),
				new ColumnSpec( 'machine', 'varchar(12)', Classification::Public, 'Which status changed: order, payment or fulfillment.', collation: 'ascii_bin' ),
				new ColumnSpec( 'from_status', 'varchar(32)', Classification::Public, 'The status before the change; empty for the order\'s first event.', collation: 'ascii_bin' ),
				new ColumnSpec( 'to_status', 'varchar(32)', Classification::Public, 'The status after the change.', collation: 'ascii_bin' ),
				new ColumnSpec( 'reason', 'varchar(64)', Classification::Public, 'Why it changed, for example placed or payment_approved.', collation: 'ascii_bin' ),
				self::actorType( 'user for a person acting in person, system for a process acting on a user\'s authority.' ),
				self::actorId( 'The WordPress user on whose authority the status changed; NULL for a visitor.' ),
				self::correlationId( 'The correlation id of the request that changed the status, shared with its events.' ),
				self::createdAt( 'When the status changed, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(),
			array(
				IndexSpec::key( 'order_created', array( 'order_id', 'created_at' ), 'An order\'s timeline.' ),
				IndexSpec::key( 'to_created', array( 'to_status', 'created_at' ), 'The orders that entered a status in a period, such as on_hold today, for doctor.' ),
			),
			self::FINANCIAL,
			array(
				'orders -> order_events' => 'Appended in the transaction that changes the order, and never changed or deleted apart from it.',
			)
		);
	}

	/**
	 * Declares `order_number_seq`: one counter row per numbering scope.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function numberSequence(): TableDefinition {
		return new TableDefinition(
			self::NUMBER_SEQUENCE,
			self::MODULE,
			'Holds the next order number of each numbering scope; one statement by primary key allocates a number, and the row is created by the first allocation.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'scope_key', 'varchar(32)', Classification::Public, 'The numbering scope, for example default; its key.', collation: 'ascii_bin' ),
				new ColumnSpec( 'pattern', 'varchar(64)', Classification::Public, 'How a number of the scope is written, for example {number}.' ),
				new ColumnSpec( 'period_key', 'varchar(16)', Classification::Public, 'The period a scope that restarts each period counts in; NULL for a scope that never restarts.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'next_value', 'bigint unsigned', Classification::Public, 'The last number allocated in the scope; the next allocation adds one.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the last number was allocated, UTC, from the database clock.' ),
			),
			array( 'scope_key' ),
			array(),
			array(),
			self::PERMANENT,
			array()
		);
	}

	/**
	 * Declares `conversion_contexts`: each exchange rate an order was placed at, frozen once.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function conversionContexts(): TableDefinition {
		return new TableDefinition(
			self::CONVERSION_CONTEXTS,
			self::MODULE,
			'Freezes each exchange rate an order was placed at, one row per distinct rate fact; kept permanently because orders, refunds and reports convert at the rate they reference for as long as they exist.',
			MutationPattern::AppendOnly,
			array(
				self::id( 'Surrogate key: what an order\'s conversion_context_id names.' ),
				self::uuid( 'uuid', 'The context\'s public identifier.' ),
				new ColumnSpec( 'fingerprint', 'char(64)', Classification::Public, 'The SHA-256 of the context\'s canonical form: an identical rate fact is one row.', collation: 'ascii_bin' ),
				self::currency( 'base_currency', 'The currency one unit of which the rate prices.' ),
				self::currency( 'quote_currency', 'The currency the rate is expressed in.' ),
				new ColumnSpec( 'direction', 'varchar(16)', Classification::Public, 'Which way the rate reads: always base_to_quote.', defaultValue: 'base_to_quote', collation: 'ascii_bin' ),
				new ColumnSpec( 'rate', 'decimal(24,12)', Classification::Financial, 'One base unit is this many quote units, stored at twelve places.' ),
				new ColumnSpec( 'rate_scale', 'tinyint unsigned', Classification::Public, 'The scale the rate was quoted at, which reading it back restores.' ),
				new ColumnSpec( 'source', 'varchar(32)', Classification::Public, 'Where the rate came from, for example manual or identity.', collation: 'ascii_bin' ),
				new ColumnSpec( 'source_version', 'bigint unsigned', Classification::Public, 'The version of the source\'s rate set the rate was taken from.' ),
				new ColumnSpec( 'quoted_at', 'datetime', Classification::Public, 'When the rate was quoted, UTC, to the second.' ),
				self::createdAt( 'When the context was frozen, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'One context per public identifier.' ),
				IndexSpec::unique( 'fingerprint', array( 'fingerprint' ), 'An identical rate fact is frozen once, so equal contexts share one row.' ),
			),
			array(
				IndexSpec::key( 'pair_quoted', array( 'base_currency', 'quote_currency', 'quoted_at' ), 'The rates a currency pair was frozen at, in time order, for an audit.' ),
			),
			self::PERMANENT,
			array(
				'orders -> conversion_contexts' => 'Retain: a context is never deleted while anything could reference it, which is always.',
			)
		);
	}

	/**
	 * The six totals of an order, as `orders` and `order_totals` both declare them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix '' for the order currency, 'base_' for the base currency.
	 * @return list<ColumnSpec> subtotal, discount, shipping, fee, tax and grand total.
	 */
	private static function totalColumns( string $prefix ): array {
		$in = '' === $prefix ? '' : ', in the base currency';

		return array(
			self::money( $prefix . 'subtotal_minor', 'The lines before discounts, as the calculation produced them' . $in . '.' ),
			self::money( $prefix . 'discount_total_minor', 'Every discount, as the calculation produced them' . $in . '.' ),
			self::money( $prefix . 'shipping_total_minor', 'Shipping, as the calculation produced it' . $in . '.' ),
			self::money( $prefix . 'fee_total_minor', 'Every fee, as the calculation produced them' . $in . '.' ),
			self::money( $prefix . 'tax_total_minor', 'Every tax, as the calculation produced it' . $in . '.' ),
			self::money( $prefix . 'grand_total_minor', 'The grand total, as the calculation produced it' . $in . '.' ),
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
	 * Declares a WordPress locale.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The column name.
	 * @param string $note What the locale is of.
	 * @return ColumnSpec The column.
	 */
	private static function locale( string $name, string $note ): ColumnSpec {
		return new ColumnSpec( $name, 'varchar(20)', Classification::Public, $note, collation: 'ascii_bin' );
	}

	/**
	 * Declares an amount basis: net or gross.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The column name.
	 * @param string $note What the basis is of.
	 * @return ColumnSpec The column.
	 */
	private static function basis( string $name, string $note ): ColumnSpec {
		return new ColumnSpec( $name, 'varchar(5)', Classification::Public, $note, collation: 'ascii_bin' );
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
	 * Declares a UUID.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The column name.
	 * @param string $note What the UUID identifies.
	 * @return ColumnSpec The column.
	 */
	private static function uuid( string $name, string $note ): ColumnSpec {
		return new ColumnSpec( $name, 'char(36)', Classification::Public, $note, collation: 'ascii_bin' );
	}

	/**
	 * Declares the reference to the order a child row belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param string $note What the reference means.
	 * @return ColumnSpec The column.
	 */
	private static function orderId( string $note ): ColumnSpec {
		return new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, $note );
	}

	/**
	 * Declares the kind of actor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $note What the value means here.
	 * @return ColumnSpec The column.
	 */
	private static function actorType( string $note ): ColumnSpec {
		return new ColumnSpec( 'actor_type', 'varchar(16)', Classification::Public, $note, collation: 'ascii_bin' );
	}

	/**
	 * Declares the WordPress user who acted, kept for the order's record.
	 *
	 * @since 0.1.0
	 *
	 * @param string $note What the value means here.
	 * @return ColumnSpec The column.
	 */
	private static function actorId( string $note ): ColumnSpec {
		return new ColumnSpec( 'actor_id', 'bigint unsigned', Classification::Pii, $note, nullable: true, erasure: ColumnSpec::ERASE_RETAIN, retainedBecause: self::ACTOR_RETAINED );
	}

	/**
	 * Declares a correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @param string $note What the id is of.
	 * @return ColumnSpec The column.
	 */
	private static function correlationId( string $note ): ColumnSpec {
		return new ColumnSpec( 'correlation_id', 'char(36)', Classification::Public, $note, nullable: true, collation: 'ascii_bin' );
	}

	/**
	 * Declares a render position.
	 *
	 * @since 0.1.0
	 *
	 * @param string $note What the position orders.
	 * @return ColumnSpec The column.
	 */
	private static function sortOrder( string $note ): ColumnSpec {
		return new ColumnSpec( 'sort_order', 'int', Classification::Public, $note );
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

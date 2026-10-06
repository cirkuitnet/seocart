<?php
/**
 * MysqlOrderRepository: every statement of the order tables, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Infrastructure;

use SEOCart\Order\Domain\AccessKeys;
use SEOCart\Order\Domain\AmountBasis;
use SEOCart\Order\Domain\FulfillmentStatus;
use SEOCart\Order\Domain\LineOption;
use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Domain\Machine;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Domain\NewTaxComponent;
use SEOCart\Order\Domain\OrderAccess;
use SEOCart\Order\Domain\OrderChannel;
use SEOCart\Order\Domain\OrderLineView;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\OrderView;
use SEOCart\Order\Domain\PaymentDelta;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Order\Domain\RefundableLine;
use SEOCart\Order\Domain\RefundableOrder;
use SEOCart\Order\Domain\RefundedUnits;
use SEOCart\Order\Domain\StoredAmount;
use SEOCart\Order\Domain\StoredTaxComponent;
use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\Locale;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a broken invariant to the developer; they are never HTML.

/**
 * The order repository on MySQL: the one class that sends SQL to the order tables.
 *
 * Owns one fact: the text of every order statement, as public constants, so a concurrency test
 * sends exactly the statement this class sends, and a test can read from the constants alone that
 * no statement changes an append-only table or names another module's table.
 *
 * Every figure it writes is copied from the document; it adds nothing up. A single-row insert is
 * written in the `INSERT ... SET` form, each column beside its value. A multi-row insert is one
 * statement whatever the number of rows, followed, for lines and adjustments, by one read of the
 * ids they were given; so placing an order costs the same number of statements for one line as
 * for a hundred. Nullable values travel as 0 or '' and become NULL in the statement, since wpdb
 * has no placeholder for NULL; no id is 0, and no nullable text is empty.
 *
 * A status changes only through TRANSITION, whose WHERE clause lists the statuses the registry
 * allows the target to be entered from; the payment projection changes only through
 * RECORD_PAYMENT. No other statement writes either. MARK_UNRECONCILED raises the flag that brings
 * a person to an order a payment did not match; nothing here lowers it. A line's refunded
 * quantity moves only through ADD_REFUNDED_QUANTITIES, which a refund sends for all its lines
 * at once.
 *
 * @since 0.1.0
 */
final class MysqlOrderRepository implements OrderRepository {

	/**
	 * The order row, in its first status; the access key expires the given seconds after placed_at, both by the database clock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_ORDER = 'INSERT INTO {orders} SET uuid = %s, order_number = %s, channel = %s, actor_type = %s, actor_id = NULLIF( %d, 0 ), '
		. 'status = %s, customer_id = NULLIF( %d, 0 ), email = %s, currency = %s, base_currency = %s, conversion_context_id = %d, '
		. "locale = %s, market_id = NULLIF( %d, 0 ), tax_display_mode_snapshot = NULLIF( %s, '' ), cross_zone_policy_snapshot = %s, tax_rounding_mode_snapshot = %s, "
		. 'subtotal_minor = %d, discount_total_minor = %d, shipping_total_minor = %d, fee_total_minor = %d, tax_total_minor = %d, grand_total_minor = %d, due_minor = %d, '
		. 'base_subtotal_minor = %d, base_discount_total_minor = %d, base_shipping_total_minor = %d, base_fee_total_minor = %d, base_tax_total_minor = %d, base_grand_total_minor = %d, '
		. "hold_group = NULLIF( %s, '' ), access_key_hash = %s, access_key_expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND, "
		. "client_ip = NULLIF( %s, '' ), user_agent = NULLIF( %s, '' ), correlation_id = NULLIF( %s, '' ), "
		. 'placed_at = UTC_TIMESTAMP(), created_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)';

	/**
	 * One line; INSERT_LINES sends it once per line as one statement.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_LINE = 'INSERT INTO {order_lines} ( order_id, line_uuid, variant_id, product_id, sku_snapshot, title_snapshot, variant_label_snapshot, locale_snapshot, '
		. 'quantity, unit_amount_basis, unit_price_minor, unit_price_gross_minor, unit_compare_at_minor, '
		. 'line_subtotal_minor, line_discount_minor, line_net_minor, line_tax_minor, line_gross_minor, line_total_minor, currency, base_currency, '
		. 'base_line_net_minor, base_line_discount_minor, base_line_tax_minor, base_line_gross_minor, tax_class_snapshot, is_taxable, price_source, sort_order, created_at ) '
		. "VALUES ( %d, %s, %d, %d, %s, %s, %s, %s, %d, %s, %d, %d, NULLIF( %d, 0 ), %d, %d, %d, %d, %d, %d, %s, %s, %d, %d, %d, %d, NULLIF( %s, '' ), %d, %s, %d, UTC_TIMESTAMP(6) )";

	/**
	 * The ids the order's lines were given, by their uuids: the one read after the lines' insert.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINE_IDS = 'SELECT id, line_uuid FROM {order_lines} WHERE order_id = %d ORDER BY sort_order';

	/**
	 * One option of a line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_LINE_OPTION = 'INSERT INTO {order_line_options} ( order_line_id, axis_key_snapshot, axis_label_snapshot, value_key_snapshot, value_label_snapshot, locale_snapshot, position ) VALUES ( %d, %s, %s, %s, %s, %s, %d )';

	/**
	 * One adjustment; 0 stands for no line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_ADJUSTMENT = 'INSERT INTO {order_adjustments} ( order_id, order_line_id, scope, type, source, label, label_locale, authored_amount_basis, '
		. 'amount_minor, net_minor, tax_minor, gross_minor, currency, base_currency, base_amount_minor, base_net_minor, base_tax_minor, base_gross_minor, '
		. 'calculation_base, tax_class_snapshot, is_taxable, sort_order, created_at ) '
		. "VALUES ( %d, NULLIF( %d, 0 ), %s, %s, %s, %s, %s, %s, %d, %d, %d, %d, %s, %s, %d, %d, %d, %d, NULLIF( %s, '' ), NULLIF( %s, '' ), %d, %d, UTC_TIMESTAMP(6) )";

	/**
	 * The ids the order's adjustments were given, by their positions: the one read after the adjustments' insert.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADJUSTMENT_IDS = 'SELECT id, sort_order FROM {order_adjustments} WHERE order_id = %d ORDER BY sort_order';

	/**
	 * One tax component of a line or an adjustment; 0 stands for no line, no adjustment and no tax rate.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_TAX_COMPONENT = 'INSERT INTO {order_tax_components} ( component_uuid, order_id, order_line_id, order_adjustment_id, totals_version, scope, jurisdiction_code, '
		. 'tax_rate_id_snapshot, rate_name_snapshot, rate_micropercent, is_compound, priority, authored_amount_basis, net_minor, tax_minor, gross_minor, currency, '
		. 'base_net_minor, base_tax_minor, base_gross_minor, residual_minor, created_at ) '
		. 'VALUES ( %s, %d, NULLIF( %d, 0 ), NULLIF( %d, 0 ), %d, %s, %s, NULLIF( %d, 0 ), %s, %d, %d, %d, %s, %d, %d, %d, %s, %d, %d, %d, %d, UTC_TIMESTAMP(6) )';

	/**
	 * One address of the order.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_ADDRESS = 'INSERT INTO {order_addresses} ( order_id, role, first_name, last_name, company, line1, line2, city, region, postcode, country, phone, email, tax_id ) '
		. 'VALUES ( %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s )';

	/**
	 * A totals snapshot, as the order's current one.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_TOTALS = 'INSERT INTO {order_totals} SET order_id = %d, version = %d, is_current = 1, currency = %s, base_currency = %s, conversion_context_id = %d, '
		. 'subtotal_minor = %d, discount_total_minor = %d, shipping_total_minor = %d, fee_total_minor = %d, tax_total_minor = %d, grand_total_minor = %d, '
		. 'base_subtotal_minor = %d, base_discount_total_minor = %d, base_shipping_total_minor = %d, base_fee_total_minor = %d, base_tax_total_minor = %d, base_grand_total_minor = %d, '
		. "tax_rounding_mode = %s, price_entry_mode = %s, cross_zone_policy = %s, tax_display_mode = NULLIF( %s, '' ), market_id = NULLIF( %d, 0 ), "
		. 'locale = %s, rate_version = NULLIF( %d, 0 ), trace_json = %s, created_at = UTC_TIMESTAMP(6)';

	/**
	 * Points the order at its current totals snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SET_CURRENT_TOTALS = 'UPDATE {orders} SET current_totals_id = %d WHERE id = %d';

	/**
	 * An order event; 0 stands for no actor, and an empty string for no correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPEND_EVENT = "INSERT INTO {order_events} SET order_id = %d, machine = %s, from_status = %s, to_status = %s, reason = %s, actor_type = %s, actor_id = NULLIF( %d, 0 ), correlation_id = NULLIF( %s, '' ), created_at = UTC_TIMESTAMP(6)";

	/**
	 * An event of the order's payment that changes no status, with what it records by its uuid: from and to are the payment status the order row has, by the primary key.
	 *
	 * The order is locked by the caller's transaction, so the status copied is current. 0 stands for
	 * no actor, and an empty string for no correlation id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const APPEND_AUDIT = 'INSERT INTO {order_events} ( order_id, machine, from_status, to_status, reason, reference, actor_type, actor_id, correlation_id, created_at ) '
		. "SELECT id, %s, payment_status, payment_status, %s, %s, %s, NULLIF( %d, 0 ), NULLIF( %s, '' ), UTC_TIMESTAMP(6) FROM {orders} WHERE id = %d";

	/**
	 * The order's lock: a locking read of what a transition, an acceptance and a payment decide from.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCK = 'SELECT id, uuid, order_number, channel, status, payment_status, currency, base_currency, grand_total_minor, authorized_minor, paid_minor, refunded_minor, due_minor, '
		. 'base_grand_total_minor, base_authorized_minor, base_paid_minor, base_refunded_minor, customer_id, actor_type, actor_id, hold_group FROM {orders} WHERE id = %d FOR UPDATE';

	/**
	 * The transition: only from a status the registry lists for the target, and, for a status that claims payment, only while the payment status is settled.
	 *
	 * The first list is the registry's allowedFrom( to ), the %d is 1 when the target claims
	 * payment, and the second list is PaymentStatus::settled().
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TRANSITION = 'UPDATE {orders} SET status = %s, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND status IN ({list}) AND ( %d = 0 OR payment_status IN ({list}) )';

	/**
	 * The payment projection: moves the order's amounts by a payment's, and writes the payment status.
	 *
	 * Every amount moves by its delta, the amount due included: it starts as the amount due the
	 * calculation produced, which another tender may have made less than the grand total, so it is
	 * never recomputed from the total. The WHERE clause refuses an order in other currencies, and
	 * any change that would authorize or capture more than the grand total, or refund more than was
	 * captured, in either currency, or leave less than nothing due. MySQL evaluates a single-table
	 * UPDATE's assignments from left to right and a later one reads the new value, so each
	 * assignment reads only its own column. `updated_at` always moves forward, so one affected row
	 * means the WHERE clause matched even when the amounts and the status stay as they were.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RECORD_PAYMENT = 'UPDATE {orders} SET due_minor = due_minor - %d + %d, '
		. 'authorized_minor = authorized_minor + %d, paid_minor = paid_minor + %d, refunded_minor = refunded_minor + %d, '
		. 'base_authorized_minor = base_authorized_minor + %d, base_paid_minor = base_paid_minor + %d, base_refunded_minor = base_refunded_minor + %d, '
		. 'payment_status = %s, updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) '
		. 'WHERE id = %d AND currency = %s AND base_currency = %s '
		. 'AND authorized_minor + %d <= grand_total_minor AND paid_minor + %d <= grand_total_minor AND refunded_minor + %d <= paid_minor + %d '
		. 'AND base_authorized_minor + %d <= base_grand_total_minor AND base_paid_minor + %d <= base_grand_total_minor AND base_refunded_minor + %d <= base_paid_minor + %d '
		. 'AND due_minor - %d + %d >= 0';

	/**
	 * Flags the order as holding money a person must reconcile; `updated_at` moves forward, so a found order is one affected row even when it was flagged before.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MARK_UNRECONCILED = 'UPDATE {orders} SET has_unreconciled_money = 1, updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE id = %d';

	/**
	 * A page of orders' payment amounts, with each order's grand total and age by the database clock, by the primary key, after the last id of the page before.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAYMENT_AMOUNTS = 'SELECT id, uuid, grand_total_minor, TIMESTAMPDIFF( SECOND, created_at, UTC_TIMESTAMP() ) AS age_seconds, authorized_minor, paid_minor, refunded_minor, '
		. 'base_authorized_minor, base_paid_minor, base_refunded_minor FROM {orders} WHERE id > %d ORDER BY id LIMIT %d';

	/**
	 * A page of the orders in a status, placed before a time by the database clock, whose grand total is zero, by the primary key after the last id of the page before.
	 *
	 * For the orders still pending payment with nothing to pay: on the `status_created` key, which
	 * holds only the few orders pending payment, each read once, filtered by its total and put in id
	 * order. The `%s` is the status.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NOTHING_DUE_IN_STATUS = 'SELECT id FROM {orders} WHERE status = %s AND created_at <= UTC_TIMESTAMP(6) - INTERVAL %d SECOND AND grand_total_minor = 0 AND id > %d ORDER BY id LIMIT %d';

	/**
	 * The first orders flagged as holding money a person must reconcile, in id order, on the `unreconciled` key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNRECONCILED = 'SELECT id, uuid FROM {orders} WHERE has_unreconciled_money = 1 ORDER BY id LIMIT %d';

	/**
	 * The first orders, in id order, that point at no totals snapshot, or at another than the one marked current.
	 *
	 * The current snapshot is found on the `order_current` key, one per order at most.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CURRENT_TOTALS_DRIFT = 'SELECT o.id, o.uuid, o.current_totals_id, t.id AS current_id FROM {orders} o LEFT JOIN {order_totals} t ON t.order_id = o.id AND t.is_current = 1 '
		. 'WHERE o.current_totals_id IS NULL OR t.id IS NULL OR o.current_totals_id <> t.id ORDER BY o.id LIMIT %d';

	/**
	 * The order row a storefront shows, by uuid.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_ORDER = 'SELECT id, uuid, order_number, status, payment_status, fulfillment_status, channel, currency, locale, email, customer_id, '
		. 'subtotal_minor, discount_total_minor, shipping_total_minor, fee_total_minor, tax_total_minor, grand_total_minor, paid_minor, refunded_minor, due_minor, placed_at '
		. 'FROM {orders} WHERE uuid = %s';

	/**
	 * The order's lines, from their snapshots, in their order.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_LINES = 'SELECT id, line_uuid, sku_snapshot, title_snapshot, variant_label_snapshot, quantity, unit_amount_basis, unit_price_minor, '
		. 'line_subtotal_minor, line_discount_minor, line_net_minor, line_tax_minor, line_gross_minor, line_total_minor FROM {order_lines} WHERE order_id = %d ORDER BY sort_order';

	/**
	 * Every option of the order's lines, in the lines' order and then the options'.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_LINE_OPTIONS = 'SELECT o.order_line_id, o.axis_key_snapshot, o.axis_label_snapshot, o.value_key_snapshot, o.value_label_snapshot '
		. 'FROM {order_lines} l JOIN {order_line_options} o ON o.order_line_id = l.id WHERE l.order_id = %d ORDER BY l.sort_order, o.position';

	/**
	 * The order's addresses.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_ADDRESSES = 'SELECT role, first_name, last_name, company, line1, line2, city, region, postcode, country, phone, email, tax_id FROM {order_addresses} WHERE order_id = %d';

	/**
	 * What an access check compares, by uuid; whether the key expired is decided by the database clock, and an order without a key counts as expired.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_FOR_ACCESS = 'SELECT uuid, customer_id, access_key_hash, COALESCE( access_key_expires_at <= UTC_TIMESTAMP(), 1 ) AS key_expired FROM {orders} WHERE uuid = %s';

	/**
	 * The order a refund is worked out for, by uuid, with the version of its current totals: what its tax components are read at.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_REFUNDABLE_ORDER = 'SELECT o.id, o.uuid, o.currency, o.base_currency, o.conversion_context_id, t.version AS totals_version '
		. 'FROM {orders} o JOIN {order_totals} t ON t.id = o.current_totals_id WHERE o.uuid = %s';

	/**
	 * The lines a refund asks for, by uuid, on the `(order_id, line_uuid)` key: units sold and refunded, and the stored amount after discounts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_REFUNDABLE_LINES = 'SELECT id, line_uuid, quantity, refunded_quantity, unit_amount_basis, line_net_minor, line_tax_minor, line_gross_minor, '
		. 'base_line_net_minor, base_line_tax_minor, base_line_gross_minor FROM {order_lines} WHERE order_id = %d AND line_uuid IN ({list})';

	/**
	 * The order's shipping adjustments, added up by the database: the shipping and any discount of it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SHIPPING_TOTALS = 'SELECT COUNT(*) AS adjustments, COALESCE( SUM( net_minor ), 0 ) AS net_minor, COALESCE( SUM( tax_minor ), 0 ) AS tax_minor, '
		. 'COALESCE( SUM( gross_minor ), 0 ) AS gross_minor, COALESCE( SUM( base_net_minor ), 0 ) AS base_net_minor, COALESCE( SUM( base_tax_minor ), 0 ) AS base_tax_minor, '
		. 'COALESCE( SUM( base_gross_minor ), 0 ) AS base_gross_minor FROM {order_adjustments} WHERE order_id = %d AND scope = %s';

	/**
	 * The tax components of some lines, and of the shipping when its scope is given, at one totals version, on the `order_version` key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_REFUNDABLE_COMPONENTS = 'SELECT c.id, c.order_line_id, c.authored_amount_basis, c.net_minor, c.tax_minor, c.gross_minor, c.base_net_minor, c.base_tax_minor, c.base_gross_minor '
		. 'FROM {order_tax_components} c LEFT JOIN {order_adjustments} a ON a.id = c.order_adjustment_id '
		. 'WHERE c.order_id = %d AND c.totals_version = %d AND ( c.order_line_id IN ({list}) OR a.scope = %s ) ORDER BY c.id';

	/**
	 * A refund's units, added to its lines' refunded quantities: each line only while it has the refunded quantity the refund was worked out from, and never past the units sold.
	 *
	 * The derived row is one line's; it is repeated once per line, so the whole refund is one
	 * statement, and the caller compares the rows affected with the lines it sent.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADD_REFUNDED_QUANTITIES = 'UPDATE {order_lines} order_line JOIN ( SELECT %d AS id, %d AS quantity, %d AS refunded_before ) AS asked ON asked.id = order_line.id '
		. 'SET order_line.refunded_quantity = order_line.refunded_quantity + asked.quantity '
		. 'WHERE order_line.order_id = %d AND order_line.refunded_quantity = asked.refunded_before AND order_line.refunded_quantity + asked.quantity <= order_line.quantity';

	/**
	 * A page of order lines' refunded quantities, by the primary key, after the last id of the page before.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REFUNDED_QUANTITIES = 'SELECT id, line_uuid, refunded_quantity FROM {order_lines} WHERE id > %d ORDER BY id LIMIT %d';

	/**
	 * What each of an order's lines sells: its id, its variant and its units, by variant, for the stock it is allocated.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STOCK_LINES = 'SELECT id, variant_id, quantity FROM {order_lines} WHERE order_id = %d ORDER BY variant_id, id';

	/**
	 * An order's public identifier and status, by its internal id: a plain read, which locks nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATUS_OF = 'SELECT uuid, status FROM {orders} WHERE id = %d';

	/**
	 * The scope of a line's tax component.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LINE_SCOPE = 'line';

	/**
	 * The scope of an adjustment's tax component.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ADJUSTMENT_SCOPE = 'adjustment';

	/**
	 * The scope of a shipping adjustment, the shipping's discounts included.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SHIPPING_ADJUSTMENTS = 'shipping';

	/**
	 * The role of the billing address.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const BILLING = 'billing';

	/**
	 * The role of the shipping address.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SHIPPING = 'shipping';

	/**
	 * A UUID in its canonical, lower-case form: nothing else names an order.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

	/**
	 * Sends the statements.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderStatements
	 */
	private OrderStatements $statements;

	/**
	 * Mints the uuids of the lines and tax components it writes.
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
	 * @param OrderStatements $statements Sends the statements.
	 * @param IdGenerator     $ids        Mints the uuids of the lines and tax components it writes.
	 */
	public function __construct( OrderStatements $statements, IdGenerator $ids ) {
		$this->statements = $statements;
		$this->ids        = $ids;
	}

	/**
	 * Inserts the order row, with its totals copied from the document.
	 *
	 * @since 0.1.0
	 *
	 * @param NewOrder    $order               The document.
	 * @param string      $uuid                The order's public identifier.
	 * @param string      $orderNumber         Its number.
	 * @param OrderStatus $status              The status it starts in.
	 * @param int         $conversionContextId The frozen rate it is placed at.
	 * @param string      $accessKeyHash       The hash of its access key.
	 * @param string      $actorType           `user` or `system`.
	 * @param int|null    $actorId             The user who places it, or null.
	 * @param string      $correlationId       The request's correlation id.
	 * @return int The order's id.
	 */
	public function insertOrder( NewOrder $order, string $uuid, string $orderNumber, OrderStatus $status, int $conversionContextId, string $accessKeyHash, string $actorType, ?int $actorId, string $correlationId ): int {
		$this->statements->requireTransaction( __METHOD__ );

		$totals = $order->totals;

		$this->statements->execute(
			self::INSERT_ORDER,
			$uuid,
			$orderNumber,
			$order->channel->value,
			$actorType,
			$actorId ?? 0,
			$status->value,
			$order->customerId ?? 0,
			$order->email,
			$order->currency()->code(),
			$order->baseCurrency()->code(),
			$conversionContextId,
			$order->locale->toString(),
			$order->marketId ?? 0,
			$totals->taxDisplayMode ?? '',
			$totals->crossZonePolicy,
			$totals->taxRoundingMode,
			...self::minorUnits( ...$totals->amounts() ),
			...self::minorUnits( ...$totals->baseAmounts() ),
			...array(
				$order->holdGroup ?? '',
				$accessKeyHash,
				AccessKeys::LIFETIME_SECONDS,
				$order->clientIp ?? '',
				$order->userAgent ?? '',
				$correlationId,
			)
		);

		return $this->statements->lastInsertId();
	}

	/**
	 * Inserts the order's lines in one statement, then reads their ids back.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a line written is not read back.
	 *
	 * @param int      $orderId The order.
	 * @param NewOrder $order   The document.
	 * @return array<string, int> Each line's id, by its key.
	 */
	public function insertLines( int $orderId, NewOrder $order ): array {
		$this->statements->requireTransaction( __METHOD__ );

		$uuids = array();
		$rows  = array();

		foreach ( $order->lines as $position => $line ) {
			$uuids[ $line->key ] = $this->ids->generate();

			$rows[] = array(
				$orderId,
				$uuids[ $line->key ],
				$line->variantId,
				$line->productId,
				$line->sku,
				$line->title,
				$line->variantLabel,
				$order->locale->toString(),
				$line->quantity,
				$line->unitAmountBasis->value,
				$line->unitPrice->minorUnits(),
				$line->unitPriceGross->minorUnits(),
				null === $line->unitCompareAt ? 0 : $line->unitCompareAt->minorUnits(),
				$line->lineSubtotal->minorUnits(),
				$line->lineDiscount->minorUnits(),
				$line->amount->net()->minorUnits(),
				$line->amount->tax()->minorUnits(),
				$line->amount->gross()->minorUnits(),
				$line->lineTotal->minorUnits(),
				$order->currency()->code(),
				$order->baseCurrency()->code(),
				$line->baseAmount->net()->minorUnits(),
				$line->baseLineDiscount->minorUnits(),
				$line->baseAmount->tax()->minorUnits(),
				$line->baseAmount->gross()->minorUnits(),
				$line->taxClass ?? '',
				$line->isTaxable ? 1 : 0,
				$line->priceSource,
				$position,
			);
		}

		$this->statements->insertRows( self::INSERT_LINE, $rows );

		$ids = array();

		foreach ( $this->statements->rows( self::LINE_IDS, $orderId ) as $row ) {
			$ids[ (string) $row['line_uuid'] ] = (int) $row['id'];
		}

		return array_map(
			static function ( string $uuid ) use ( $ids ): int {
				if ( ! isset( $ids[ $uuid ] ) ) {
					throw new \LogicException( sprintf( 'The line %s was written but not read back.', $uuid ) );
				}

				return $ids[ $uuid ];
			},
			$uuids
		);
	}

	/**
	 * Inserts every option of every line in one statement; sends nothing when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @param NewOrder           $order   The document.
	 * @param array<string, int> $lineIds Each line's id, by its key.
	 */
	public function insertLineOptions( NewOrder $order, array $lineIds ): void {
		$this->statements->requireTransaction( __METHOD__ );

		$rows = array();

		foreach ( $order->lines as $line ) {
			foreach ( $line->options as $position => $option ) {
				$rows[] = array( $lineIds[ $line->key ], $option->axisKey, $option->axisLabel, $option->valueKey, $option->valueLabel, $order->locale->toString(), $position );
			}
		}

		if ( array() !== $rows ) {
			$this->statements->insertRows( self::INSERT_LINE_OPTION, $rows );
		}
	}

	/**
	 * Inserts the order's adjustments in one statement, then reads their ids back; sends nothing when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the adjustments read back are not those written.
	 *
	 * @param int                $orderId The order.
	 * @param NewOrder           $order   The document.
	 * @param array<string, int> $lineIds Each line's id, by its key.
	 * @return list<int> Each adjustment's id, by its position.
	 */
	public function insertAdjustments( int $orderId, NewOrder $order, array $lineIds ): array {
		$this->statements->requireTransaction( __METHOD__ );

		if ( array() === $order->adjustments ) {
			return array();
		}

		$rows = array();

		foreach ( $order->adjustments as $position => $adjustment ) {
			$rows[] = array(
				$orderId,
				null === $adjustment->lineKey ? 0 : $lineIds[ $adjustment->lineKey ],
				$adjustment->scope,
				$adjustment->type,
				$adjustment->source,
				$adjustment->label,
				$order->locale->toString(),
				$adjustment->authoredAmountBasis->value,
				...self::minorUnits( $adjustment->amount, $adjustment->taxed->net(), $adjustment->taxed->tax(), $adjustment->taxed->gross() ),
				...array( $order->currency()->code(), $order->baseCurrency()->code() ),
				...self::minorUnits( $adjustment->baseAmount, $adjustment->baseTaxed->net(), $adjustment->baseTaxed->tax(), $adjustment->baseTaxed->gross() ),
				...array( $adjustment->calculationBase ?? '', $adjustment->taxClass ?? '', $adjustment->isTaxable ? 1 : 0, $position ),
			);
		}

		$this->statements->insertRows( self::INSERT_ADJUSTMENT, $rows );

		$ids = array();

		foreach ( $this->statements->rows( self::ADJUSTMENT_IDS, $orderId ) as $row ) {
			$ids[] = (int) $row['id'];
		}

		if ( count( $ids ) !== count( $rows ) ) {
			throw new \LogicException( sprintf( '%d adjustments were written and %d read back.', count( $rows ), count( $ids ) ) );
		}

		return $ids;
	}

	/**
	 * Inserts every tax component of every line and adjustment in one statement; sends nothing when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $orderId       The order.
	 * @param NewOrder           $order         The document.
	 * @param array<string, int> $lineIds       Each line's id, by its key.
	 * @param int[]              $adjustmentIds Each adjustment's id, by its position.
	 * @param int                $totalsVersion The totals version the components belong to.
	 *
	 * @phpstan-param list<int> $adjustmentIds
	 */
	public function insertTaxComponents( int $orderId, NewOrder $order, array $lineIds, array $adjustmentIds, int $totalsVersion ): void {
		$this->statements->requireTransaction( __METHOD__ );

		$rows = array();

		foreach ( $order->lines as $line ) {
			foreach ( $line->taxComponents as $component ) {
				$rows[] = $this->componentRow( $orderId, $lineIds[ $line->key ], 0, self::LINE_SCOPE, $component, $order->currency(), $totalsVersion );
			}
		}

		foreach ( $order->adjustments as $position => $adjustment ) {
			foreach ( $adjustment->taxComponents as $component ) {
				$rows[] = $this->componentRow( $orderId, 0, $adjustmentIds[ $position ], self::ADJUSTMENT_SCOPE, $component, $order->currency(), $totalsVersion );
			}
		}

		if ( array() !== $rows ) {
			$this->statements->insertRows( self::INSERT_TAX_COMPONENT, $rows );
		}
	}

	/**
	 * Inserts the billing address, and the shipping address when there is one, in one statement.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId The order.
	 * @param NewOrder $order   The document.
	 */
	public function insertAddresses( int $orderId, NewOrder $order ): void {
		$this->statements->requireTransaction( __METHOD__ );

		$rows = array( self::addressRow( $orderId, self::BILLING, $order->billingAddress ) );

		if ( null !== $order->shippingAddress ) {
			$rows[] = self::addressRow( $orderId, self::SHIPPING, $order->shippingAddress );
		}

		$this->statements->insertRows( self::INSERT_ADDRESS, $rows );
	}

	/**
	 * Inserts a totals snapshot as the order's current one.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the trace cannot be written as JSON.
	 *
	 * @param int      $orderId             The order.
	 * @param NewOrder $order               The document.
	 * @param int      $conversionContextId The frozen rate the snapshot was calculated at.
	 * @param int      $version             The snapshot's version.
	 * @return int The snapshot's id.
	 */
	public function insertTotals( int $orderId, NewOrder $order, int $conversionContextId, int $version ): int {
		$this->statements->requireTransaction( __METHOD__ );

		$totals = $order->totals;
		$trace  = wp_json_encode( $totals->trace );

		if ( false === $trace ) {
			throw new \LogicException( 'The calculation trace cannot be written as JSON.' );
		}

		$this->statements->execute(
			self::INSERT_TOTALS,
			$orderId,
			$version,
			$order->currency()->code(),
			$order->baseCurrency()->code(),
			$conversionContextId,
			...self::minorUnits( $totals->subtotal, $totals->discountTotal, $totals->shippingTotal, $totals->feeTotal, $totals->taxTotal, $totals->grandTotal ),
			...self::minorUnits( ...$totals->baseAmounts() ),
			...array(
				$totals->taxRoundingMode,
				$totals->priceEntryMode,
				$totals->crossZonePolicy,
				$totals->taxDisplayMode ?? '',
				$order->marketId ?? 0,
				$order->locale->toString(),
				$totals->rateVersion ?? 0,
				$trace,
			)
		);

		return $this->statements->lastInsertId();
	}

	/**
	 * Points the order at the totals snapshot its totals were copied from.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId  The order.
	 * @param int $totalsId The snapshot.
	 * @return bool True when the order was found.
	 */
	public function setCurrentTotals( int $orderId, int $totalsId ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		return 1 === $this->statements->execute( self::SET_CURRENT_TOTALS, $totalsId, $orderId );
	}

	/**
	 * Appends an order event.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId       The order.
	 * @param Machine  $machine       Which status changed.
	 * @param string   $from          The status before; empty for the order's first event.
	 * @param string   $to            The status after.
	 * @param string   $reason        Why.
	 * @param string   $actorType     `user` or `system`.
	 * @param int|null $actorId       The user on whose authority, or null.
	 * @param string   $correlationId The request's correlation id.
	 * @return int The event row's id.
	 */
	public function appendEvent( int $orderId, Machine $machine, string $from, string $to, string $reason, string $actorType, ?int $actorId, string $correlationId ): int {
		$this->statements->requireTransaction( __METHOD__ );

		$this->statements->execute( self::APPEND_EVENT, $orderId, $machine->value, $from, $to, $reason, $actorType, $actorId ?? 0, $correlationId );

		return $this->statements->lastInsertId();
	}

	/**
	 * Appends an event of the order's payment that changes no status, naming what it records.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction, or when no order has the id.
	 *
	 * @param int      $orderId       The order.
	 * @param string   $reason        What happened.
	 * @param string   $reference     The uuid of what it records.
	 * @param string   $actorType     `user` or `system`.
	 * @param int|null $actorId       The user on whose authority, or null.
	 * @param string   $correlationId The request's correlation id.
	 * @return int The event row's id.
	 */
	public function appendAudit( int $orderId, string $reason, string $reference, string $actorType, ?int $actorId, string $correlationId ): int {
		$this->statements->requireTransaction( __METHOD__ );

		if ( 1 !== $this->statements->execute( self::APPEND_AUDIT, Machine::Payment->value, $reason, $reference, $actorType, $actorId ?? 0, $correlationId, $orderId ) ) {
			throw new \LogicException( sprintf( 'Order %d has no row to record an event of: the caller\'s lock rules that out.', $orderId ) );
		}

		return $this->statements->lastInsertId();
	}

	/**
	 * Reads the order with a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order's internal id.
	 * @return LockedOrder|null The order, or null when there is none.
	 */
	public function lock( int $orderId ): ?LockedOrder {
		$this->statements->requireTransaction( __METHOD__ );

		$row = $this->statements->rows( self::LOCK, $orderId )[0] ?? null;

		if ( null === $row ) {
			return null;
		}

		$money = self::money( Currency::of( (string) $row['currency'] ) );
		$base  = self::money( Currency::of( (string) $row['base_currency'] ) );

		return new LockedOrder(
			(int) $row['id'],
			(string) $row['uuid'],
			(string) $row['order_number'],
			OrderChannel::from( (string) $row['channel'] ),
			OrderStatus::from( (string) $row['status'] ),
			PaymentStatus::from( (string) $row['payment_status'] ),
			$money( $row['grand_total_minor'] ),
			$money( $row['authorized_minor'] ),
			$money( $row['paid_minor'] ),
			$money( $row['refunded_minor'] ),
			$money( $row['due_minor'] ),
			$base( $row['base_grand_total_minor'] ),
			$base( $row['base_authorized_minor'] ),
			$base( $row['base_paid_minor'] ),
			$base( $row['base_refunded_minor'] ),
			self::nullableId( $row['customer_id'] ),
			(string) $row['actor_type'],
			self::nullableId( $row['actor_id'] ),
			null === $row['hold_group'] ? null : (string) $row['hold_group']
		);
	}

	/**
	 * Changes the order's status, when the registry allows it from the status the order is in.
	 *
	 * @since 0.1.0
	 *
	 * @param int           $orderId                The order.
	 * @param OrderStatus   $to                     The status entered.
	 * @param OrderStatus[] $allowedFrom            The statuses it may be entered from.
	 * @param bool          $requiresSettledPayment Whether the order's payment status must be settled.
	 * @return bool True when the order changed.
	 *
	 * @phpstan-param list<OrderStatus> $allowedFrom
	 */
	public function transition( int $orderId, OrderStatus $to, array $allowedFrom, bool $requiresSettledPayment ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		return 1 === $this->statements->execute(
			self::TRANSITION,
			$to->value,
			$orderId,
			array_map( static fn( OrderStatus $status ): string => $status->value, $allowedFrom ),
			$requiresSettledPayment ? 1 : 0,
			array_map( static fn( PaymentStatus $status ): string => $status->value, PaymentStatus::settled() )
		);
	}

	/**
	 * Adds a payment's amounts to the order's payment projection, and writes its payment status.
	 *
	 * @since 0.1.0
	 *
	 * @param int           $orderId The order.
	 * @param PaymentDelta  $delta   What the payment adds.
	 * @param PaymentStatus $status  The payment status once the amounts are added.
	 * @return bool True when the order changed; false when a condition did not hold.
	 */
	public function recordPayment( int $orderId, PaymentDelta $delta, PaymentStatus $status ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		list( $authorized, $captured, $refunded, $baseAuthorized, $baseCaptured, $baseRefunded ) = self::minorUnits( $delta->authorized, $delta->captured, $delta->refunded, $delta->baseAuthorized, $delta->baseCaptured, $delta->baseRefunded );

		return 1 === $this->statements->execute(
			self::RECORD_PAYMENT,
			// SET: the amount due, the three amounts, their base twins and the payment status.
			$captured,
			$refunded,
			$authorized,
			$captured,
			$refunded,
			$baseAuthorized,
			$baseCaptured,
			$baseRefunded,
			$status->value,
			// WHERE: the order and its currencies, then the caps.
			$orderId,
			$delta->currency()->code(),
			$delta->baseCurrency()->code(),
			$authorized,
			$captured,
			$refunded,
			$captured,
			$baseAuthorized,
			$baseCaptured,
			$baseRefunded,
			$baseCaptured,
			$captured,
			$refunded
		);
	}

	/**
	 * Flags the order as holding money a person must reconcile.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return bool True when the order was found.
	 */
	public function markUnreconciled( int $orderId ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		return 1 === $this->statements->execute( self::MARK_UNRECONCILED, $orderId );
	}

	/**
	 * Reads a page of orders' payment amounts, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId The last id of the page before, or 0 for the first page.
	 * @param int $limit   The most orders to read.
	 * @return list<array{id: int, uuid: string, grand_total: int, age_seconds: int, authorized: int, paid: int, refunded: int, base_authorized: int, base_paid: int, base_refunded: int}> The orders, in minor
	 *         units, each with its grand total and how long ago it was placed.
	 */
	public function paymentAmounts( int $afterId, int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'              => (int) $row['id'],
				'uuid'            => (string) $row['uuid'],
				'grand_total'     => (int) $row['grand_total_minor'],
				'age_seconds'     => (int) $row['age_seconds'],
				'authorized'      => (int) $row['authorized_minor'],
				'paid'            => (int) $row['paid_minor'],
				'refunded'        => (int) $row['refunded_minor'],
				'base_authorized' => (int) $row['base_authorized_minor'],
				'base_paid'       => (int) $row['base_paid_minor'],
				'base_refunded'   => (int) $row['base_refunded_minor'],
			),
			$this->statements->rows( self::PAYMENT_AMOUNTS, $afterId, $limit )
		);
	}

	/**
	 * Reads the first orders flagged as holding money a person must reconcile, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most orders to read.
	 * @return list<array{id: int, uuid: string}> The orders.
	 */
	public function unreconciled( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'   => (int) $row['id'],
				'uuid' => (string) $row['uuid'],
			),
			$this->statements->rows( self::UNRECONCILED, $limit )
		);
	}

	/**
	 * Reads the first orders whose current totals snapshot is not the one they point at, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most orders to read.
	 * @return list<array{id: int, uuid: string, points_at: int|null, current: int|null}> The orders, with the snapshot each points at and the one marked current.
	 */
	public function currentTotalsDrift( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'        => (int) $row['id'],
				'uuid'      => (string) $row['uuid'],
				'points_at' => self::nullableId( $row['current_totals_id'] ),
				'current'   => self::nullableId( $row['current_id'] ),
			),
			$this->statements->rows( self::CURRENT_TOTALS_DRIFT, $limit )
		);
	}

	/**
	 * Reads an order for showing it: four reads, whatever the number of lines.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The public identifier.
	 * @return OrderView|null The order, or null when there is none; a string that is not a UUID names none.
	 */
	public function findByUuid( string $uuid ): ?OrderView {
		$row = self::isUuid( $uuid ) ? ( $this->statements->rows( self::FIND_ORDER, $uuid )[0] ?? null ) : null;

		if ( null === $row ) {
			return null;
		}

		$orderId   = (int) $row['id'];
		$money     = self::money( Currency::of( (string) $row['currency'] ) );
		$addresses = array();

		foreach ( $this->statements->rows( self::FIND_ADDRESSES, $orderId ) as $address ) {
			$addresses[ (string) $address['role'] ] = self::address( $address );
		}

		return new OrderView(
			(string) $row['uuid'],
			(string) $row['order_number'],
			OrderStatus::from( (string) $row['status'] ),
			PaymentStatus::from( (string) $row['payment_status'] ),
			FulfillmentStatus::from( (string) $row['fulfillment_status'] ),
			OrderChannel::from( (string) $row['channel'] ),
			Currency::of( (string) $row['currency'] ),
			Locale::of( (string) $row['locale'] ),
			(string) $row['email'],
			self::nullableId( $row['customer_id'] ),
			$money( $row['subtotal_minor'] ),
			$money( $row['discount_total_minor'] ),
			$money( $row['shipping_total_minor'] ),
			$money( $row['fee_total_minor'] ),
			$money( $row['tax_total_minor'] ),
			$money( $row['grand_total_minor'] ),
			$money( $row['paid_minor'] ),
			$money( $row['refunded_minor'] ),
			$money( $row['due_minor'] ),
			new \DateTimeImmutable( (string) $row['placed_at'], new \DateTimeZone( 'UTC' ) ),
			$this->lineViews( $orderId, $money ),
			$addresses[ self::BILLING ],
			$addresses[ self::SHIPPING ] ?? null
		);
	}

	/**
	 * Reads what an order's access check needs, by its public identifier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The public identifier.
	 * @return OrderAccess|null The facts, or null when there is no such order; a string that is not a UUID names none.
	 */
	public function findForAccess( string $uuid ): ?OrderAccess {
		$row = self::isUuid( $uuid ) ? ( $this->statements->rows( self::FIND_FOR_ACCESS, $uuid )[0] ?? null ) : null;

		if ( null === $row ) {
			return null;
		}

		return new OrderAccess(
			(string) $row['uuid'],
			self::nullableId( $row['customer_id'] ),
			null === $row['access_key_hash'] ? null : (string) $row['access_key_hash'],
			'1' === (string) $row['key_expired']
		);
	}

	/**
	 * Reads what a refund of an order allocates its shares from: four reads, three without the shipping, whatever the number of lines.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $orderUuid    The order's public identifier.
	 * @param string[] $lineUuids    The public identifiers of the lines asked for.
	 * @param bool     $withShipping Whether the shipping is asked for.
	 * @return RefundableOrder|null The order, or null when there is none; a string that is not a UUID names none.
	 *
	 * @phpstan-param list<string> $lineUuids
	 */
	public function findRefundable( string $orderUuid, array $lineUuids, bool $withShipping ): ?RefundableOrder {
		$row = self::isUuid( $orderUuid ) ? ( $this->statements->rows( self::FIND_REFUNDABLE_ORDER, $orderUuid )[0] ?? null ) : null;

		if ( null === $row ) {
			return null;
		}

		$orderId  = (int) $row['id'];
		$version  = (int) $row['totals_version'];
		$currency = Currency::of( (string) $row['currency'] );
		$base     = Currency::of( (string) $row['base_currency'] );
		$lines    = array();

		foreach ( array() === $lineUuids ? array() : $this->statements->rows( self::FIND_REFUNDABLE_LINES, $orderId, $lineUuids ) as $line ) {
			$lines[ (string) $line['line_uuid'] ] = new RefundableLine(
				(int) $line['id'],
				(string) $line['line_uuid'],
				(int) $line['quantity'],
				(int) $line['refunded_quantity'],
				self::stored( AmountBasis::from( (string) $line['unit_amount_basis'] ), $line, 'line_', $currency, $base )
			);
		}

		$shipping = $withShipping ? ( $this->statements->rows( self::SHIPPING_TOTALS, $orderId, self::SHIPPING_ADJUSTMENTS )[0] ?? null ) : null;
		$shipped  = null !== $shipping && (int) $shipping['adjustments'] > 0;
		$scope    = $shipped ? self::SHIPPING_ADJUSTMENTS : '';
		$lineIds  = array_values( array_map( static fn( RefundableLine $line ): int => $line->id, $lines ) );

		return new RefundableOrder(
			$orderId,
			(string) $row['uuid'],
			$currency,
			$base,
			(int) $row['conversion_context_id'],
			$version,
			$lines,
			$shipped ? self::taxed( $shipping, '', $currency ) : null,
			$shipped ? self::taxed( $shipping, 'base_', $base ) : null,
			array() === $lineIds && ! $shipped ? array() : $this->refundableComponents( $orderId, $version, $lineIds, $scope, $currency, $base )
		);
	}

	/**
	 * Adds a refund's units to its lines' refunded quantities, in one conditional update.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $orderId The order the lines belong to.
	 * @param RefundedUnits[] $units   The units returned, one entry per line; at least one.
	 * @return bool True when every line moved.
	 *
	 * @phpstan-param non-empty-list<RefundedUnits> $units
	 */
	public function addRefundedQuantities( int $orderId, array $units ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		$rows = array_map( static fn( RefundedUnits $line ): array => array( $line->lineId, $line->quantity, $line->refundedBefore ), $units );

		return count( $units ) === $this->statements->executeForRows( self::ADD_REFUNDED_QUANTITIES, $rows, $orderId );
	}

	/**
	 * Reads a page of order lines' refunded quantities, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId The last id of the page before, or 0 for the first page.
	 * @param int $limit   The most lines to read.
	 * @return list<array{id: int, uuid: string, refunded: int}> The lines, each with its public identifier.
	 */
	public function refundedQuantities( int $afterId, int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'       => (int) $row['id'],
				'uuid'     => (string) $row['line_uuid'],
				'refunded' => (int) $row['refunded_quantity'],
			),
			$this->statements->rows( self::REFUNDED_QUANTITIES, $afterId, $limit )
		);
	}

	/**
	 * Reads the tax components of some lines, and of the shipping when its scope is given, at one totals version.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId  The order.
	 * @param int      $version  Its current totals version.
	 * @param int[]    $lineIds  The lines.
	 * @param string   $scope    The shipping adjustments' scope, or '' for no shipping.
	 * @param Currency $currency The order's currency.
	 * @param Currency $base     The base currency.
	 * @return list<StoredTaxComponent> The components, in id order.
	 *
	 * @phpstan-param list<int> $lineIds
	 */
	private function refundableComponents( int $orderId, int $version, array $lineIds, string $scope, Currency $currency, Currency $base ): array {
		return array_map(
			static fn( array $row ): StoredTaxComponent => new StoredTaxComponent(
				(int) $row['id'],
				self::nullableId( $row['order_line_id'] ),
				self::stored( AmountBasis::from( (string) $row['authored_amount_basis'] ), $row, '', $currency, $base )
			),
			$this->statements->rows( self::FIND_REFUNDABLE_COMPONENTS, $orderId, $version, $lineIds, $scope )
		);
	}

	/**
	 * Builds a stored amount from a row's net, tax and gross columns and their base twins.
	 *
	 * @since 0.1.0
	 *
	 * @param AmountBasis          $basis    The basis it was authored in.
	 * @param array<string, mixed> $row      The row.
	 * @param string               $prefix   What the columns' names begin with after `base_`, such as `line_`.
	 * @param Currency             $currency The order's currency.
	 * @param Currency             $base     The base currency.
	 * @return StoredAmount The amount.
	 */
	private static function stored( AmountBasis $basis, array $row, string $prefix, Currency $currency, Currency $base ): StoredAmount {
		return new StoredAmount( $basis, self::taxed( $row, $prefix, $currency ), self::taxed( $row, 'base_' . $prefix, $base ) );
	}

	/**
	 * Builds net, tax and gross from a row's three columns of one prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row      The row.
	 * @param string               $prefix   What the columns' names begin with, such as `base_line_`.
	 * @param Currency             $currency Their currency.
	 * @return TaxedMoney The figures.
	 */
	private static function taxed( array $row, string $prefix, Currency $currency ): TaxedMoney {
		$money = self::money( $currency );

		return new TaxedMoney( $money( $row[ $prefix . 'net_minor' ] ), $money( $row[ $prefix . 'tax_minor' ] ), $money( $row[ $prefix . 'gross_minor' ] ) );
	}

	/**
	 * Reads what each of an order's lines sells, by variant.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<array{orderLineId: int, variantId: int, quantity: int}> The lines, by variant and then by id.
	 */
	public function stockLines( int $orderId ): array {
		return array_map(
			static fn( array $row ): array => array(
				'orderLineId' => (int) $row['id'],
				'variantId'   => (int) $row['variant_id'],
				'quantity'    => (int) $row['quantity'],
			),
			$this->statements->rows( self::STOCK_LINES, $orderId )
		);
	}

	/**
	 * Reads an order's public identifier and status, without a lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order's internal id.
	 * @return array{uuid: string, status: OrderStatus}|null The two, or null when there is no such order.
	 */
	public function statusOf( int $orderId ): ?array {
		$row = $this->statements->rows( self::STATUS_OF, $orderId )[0] ?? null;

		return null === $row ? null : array(
			'uuid'   => (string) $row['uuid'],
			'status' => OrderStatus::from( (string) $row['status'] ),
		);
	}

	/**
	 * Reads a page of the orders still pending payment whose grand total is zero, placed at least a while ago, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $olderThanSeconds How long ago they were placed, at least, by the database's clock.
	 * @param int $afterId          The last id of the page before, or 0 for the first page.
	 * @param int $limit            The most orders to read.
	 * @return list<int> The orders' internal ids.
	 */
	public function pendingNothingDue( int $olderThanSeconds, int $afterId, int $limit ): array {
		return array_map(
			static fn( array $row ): int => (int) $row['id'],
			$this->statements->rows( self::NOTHING_DUE_IN_STATUS, OrderStatus::PendingPayment->value, $olderThanSeconds, $afterId, $limit )
		);
	}

	/**
	 * Reads an order's lines and their options: two reads.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId The order.
	 * @param \Closure $money   Builds an amount in the order's currency from a column's value.
	 * @return list<OrderLineView> The lines, in their order.
	 *
	 * @phpstan-param \Closure(mixed): Money $money
	 */
	private function lineViews( int $orderId, \Closure $money ): array {
		$options = array();

		foreach ( $this->statements->rows( self::FIND_LINE_OPTIONS, $orderId ) as $row ) {
			$options[ (int) $row['order_line_id'] ][] = new LineOption( (string) $row['axis_key_snapshot'], (string) $row['axis_label_snapshot'], (string) $row['value_key_snapshot'], (string) $row['value_label_snapshot'] );
		}

		return array_map(
			static fn( array $row ): OrderLineView => new OrderLineView(
				(string) $row['line_uuid'],
				(string) $row['sku_snapshot'],
				(string) $row['title_snapshot'],
				(string) $row['variant_label_snapshot'],
				(int) $row['quantity'],
				AmountBasis::from( (string) $row['unit_amount_basis'] ),
				$money( $row['unit_price_minor'] ),
				$money( $row['line_subtotal_minor'] ),
				$money( $row['line_discount_minor'] ),
				new TaxedMoney( $money( $row['line_net_minor'] ), $money( $row['line_tax_minor'] ), $money( $row['line_gross_minor'] ) ),
				$money( $row['line_total_minor'] ),
				$options[ (int) $row['id'] ] ?? array()
			),
			$this->statements->rows( self::FIND_LINES, $orderId )
		);
	}

	/**
	 * Builds one tax component's row.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $orderId       The order.
	 * @param int             $lineId        The line taxed, or 0.
	 * @param int             $adjustmentId  The adjustment taxed, or 0.
	 * @param string          $scope         LINE_SCOPE or ADJUSTMENT_SCOPE.
	 * @param NewTaxComponent $component     The component.
	 * @param Currency        $currency      The order's currency.
	 * @param int             $totalsVersion The totals version.
	 * @return list<mixed> The row's values, in INSERT_TAX_COMPONENT's order.
	 */
	private function componentRow( int $orderId, int $lineId, int $adjustmentId, string $scope, NewTaxComponent $component, Currency $currency, int $totalsVersion ): array {
		return array(
			$this->ids->generate(),
			$orderId,
			$lineId,
			$adjustmentId,
			$totalsVersion,
			$scope,
			$component->jurisdictionCode,
			$component->taxRateId ?? 0,
			$component->rateName,
			$component->rateMicropercent,
			$component->isCompound ? 1 : 0,
			$component->priority,
			$component->authoredAmountBasis->value,
			...self::minorUnits( $component->amount->net(), $component->amount->tax(), $component->amount->gross() ),
			...array( $currency->code() ),
			...self::minorUnits( $component->base->net(), $component->base->tax(), $component->base->gross() ),
			...array( $component->residualMinor ),
		);
	}

	/**
	 * Builds one address's row.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $orderId The order.
	 * @param string  $role    BILLING or SHIPPING.
	 * @param Address $address The address.
	 * @return list<mixed> The row's values, in INSERT_ADDRESS's order.
	 */
	private static function addressRow( int $orderId, string $role, Address $address ): array {
		return array( $orderId, $role, $address->firstName(), $address->lastName(), $address->company(), $address->line1(), $address->line2(), $address->city(), $address->region(), $address->postcode(), $address->country(), $address->phone(), $address->email(), $address->taxId() );
	}

	/**
	 * Builds an address from its row.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row The row.
	 * @return Address The address.
	 */
	private static function address( array $row ): Address {
		return new Address(
			(string) $row['country'],
			first_name: (string) $row['first_name'],
			last_name: (string) $row['last_name'],
			company: (string) $row['company'],
			line1: (string) $row['line1'],
			line2: (string) $row['line2'],
			city: (string) $row['city'],
			region: (string) $row['region'],
			postcode: (string) $row['postcode'],
			phone: (string) $row['phone'],
			email: (string) $row['email'],
			tax_id: (string) $row['tax_id']
		);
	}

	/**
	 * Returns the minor units of amounts, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param Money ...$amounts The amounts.
	 * @return list<int> Their minor units.
	 */
	private static function minorUnits( Money ...$amounts ): array {
		return array_map( static fn( Money $amount ): int => $amount->minorUnits(), array_values( $amounts ) );
	}

	/**
	 * Returns a builder of amounts in one currency from column values.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $currency The currency.
	 * @return \Closure(mixed): Money The builder.
	 */
	private static function money( Currency $currency ): \Closure {
		return static fn( mixed $minor ): Money => Money::of( (int) $minor, $currency );
	}

	/**
	 * Reads a nullable id column.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The column's value.
	 * @return int|null The id, or null.
	 */
	private static function nullableId( mixed $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * Tells whether a string is a UUID in its canonical form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The string.
	 * @return bool True when it is.
	 */
	private static function isUuid( string $uuid ): bool {
		return 1 === preg_match( self::UUID_PATTERN, $uuid );
	}
}

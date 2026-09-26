<?php
/**
 * CartTables: the declarations of the cart and cart line tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Infrastructure;

use SEOCart\Cart\Domain\Cart;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `carts` and `cart_lines`.
 *
 * Owns one fact: the shape of the cart tables. The migration that creates them and the data
 * registry that lists them call the same factories.
 *
 * A cart row exists only once its first line is added. `version` is the optimistic-concurrency
 * token every write's compare-and-swap moves on by one; it is never reset. `expires_at` is
 * compared with the database clock by every read and every compare-and-swap, so an expired cart
 * is gone to them before the sweep deletes it. `updated_at` is `datetime(6)` and every
 * conditional UPDATE sets it from the database clock, so a statement's affected-row count says
 * whether its WHERE matched, never whether a value happened to change.
 *
 * Some columns are declared in their final shape and written by nothing yet: the customer, the
 * market, the captured contact and its consent, the client's address and browser, and the times
 * of a currency change, an abandonment and a recovery; and on a line, the currency and rate
 * version it was last priced at, since a cart stores selections and is priced by every
 * calculation.
 *
 * @since 0.1.0
 */
final class CartTables {

	/**
	 * The unprefixed name of the cart table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CARTS = 'carts';

	/**
	 * The unprefixed name of the cart line table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINES = 'cart_lines';

	/**
	 * The module both tables belong to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'Cart';

	/**
	 * Returns the two declarations.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The cart table, then the line table.
	 */
	public static function all(): array {
		return array( self::carts(), self::lines() );
	}

	/**
	 * Declares `carts`: one row per shopper's cart, from its first line until it expires.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function carts(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::CARTS,
			self::MODULE,
			'Holds a shopper\'s cart from its first line until it expires: the hash of its token, its version, its currency and locale, and whether an order is being or was placed from it.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'token_hash', 'char(64)', Classification::Secret, 'The SHA-256 of the cart token the client holds, in hexadecimal; the token itself is never stored.', collation: 'ascii_bin' ),
				new ColumnSpec( 'version', 'bigint unsigned', $public, 'The optimistic-concurrency token: 1 when the cart is created, one more after every accepted write, never reset.', defaultValue: '1' ),
				new ColumnSpec( 'status', 'varchar(12)', $public, 'open, placing while an order placed from the cart awaits its payment result, or converted once that order was accepted.', defaultValue: 'open', collation: 'ascii_bin' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', $public, 'The order the cart last produced; NULL until one is placed.', nullable: true ),
				new ColumnSpec( 'currency', 'char(3)', $public, 'ISO 4217 code of the one currency the cart is in, in upper case.', collation: 'ascii_bin' ),
				new ColumnSpec( 'locale', 'varchar(20)', $public, 'The WordPress locale the cart was started in, such as en_US.', collation: 'ascii_bin' ),
				new ColumnSpec( 'market_id', 'bigint unsigned', $public, 'The market the cart is priced in; NULL until markets are built.', nullable: true ),
				new ColumnSpec( 'channel', 'varchar(16)', $public, 'Where the cart was started: storefront.', defaultValue: 'storefront', collation: 'ascii_bin' ),
				new ColumnSpec( 'customer_id', 'bigint unsigned', Classification::Pii, 'The customer the cart belongs to; NULL for a guest\'s cart.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'user_id', 'bigint unsigned', Classification::Pii, 'The WordPress user the cart belongs to; NULL for a guest\'s cart.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'line_count', 'int unsigned', $public, 'How many lines the cart holds, recounted in the transaction of every write of its lines.', defaultValue: '0' ),
				new ColumnSpec( 'item_count', 'int unsigned', $public, 'How many units its lines hold together, recounted with line_count.', defaultValue: '0' ),
				new ColumnSpec( 'promotion_codes', 'text', $public, 'The promotion codes applied to the cart, as a JSON list in the order they were applied.' ),
				new ColumnSpec( 'captured_email', 'varchar(254)', Classification::Pii, 'The email address a shopper left before checking out, for an abandoned-cart reminder; NULL when none.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'captured_consent_id', 'bigint unsigned', $public, 'The consent record the captured email address was given with; NULL when none.', nullable: true ),
				new ColumnSpec( 'client_ip', 'varchar(45)', Classification::Pii, 'The address the cart was started from; NULL when not recorded.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'user_agent', 'varchar(255)', Classification::Pii, 'The browser the cart was started from; NULL when not recorded.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'currency_changed_at', 'datetime', $public, 'When the shopper last changed the cart\'s currency, UTC; NULL when never.', nullable: true ),
				new ColumnSpec( 'abandoned_at', 'datetime', $public, 'When the cart was recorded as abandoned, UTC; NULL when it was not.', nullable: true ),
				new ColumnSpec( 'recovered_at', 'datetime', $public, 'When an abandoned cart was recovered, UTC; NULL when it was not.', nullable: true ),
				new ColumnSpec( 'expires_at', 'datetime', $public, 'When the cart expires, UTC, from the database clock: its retention period after its last accepted write.' ),
				new ColumnSpec( 'created_at', 'datetime(6)', $public, 'When the cart was created, UTC, from the database clock.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', $public, 'When the cart row last changed, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'token_hash', array( 'token_hash' ), 'Finds the cart a request\'s token names; no two carts share a token.' ),
			),
			array(
				IndexSpec::key( 'expires_at', array( 'expires_at' ), 'The sweep\'s search for expired carts, oldest first.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'Finds the cart an order was placed from.' ),
			),
			Cart::RETENTION,
			array(
				'carts -> cart_lines' => 'Cascade: a cart\'s lines are deleted with it, by the sweep, lines first.',
				'orders -> carts'     => 'Retain: order_id keeps naming a deleted order until the cart expires; the order never depends on its cart.',
			)
		);
	}

	/**
	 * Declares `cart_lines`: one row per line of a cart, a variant and how many of it.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function lines(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::LINES,
			self::MODULE,
			'Holds the lines of each cart: one row per line identity, with its variant and quantity; a line is a selection, priced by every calculation, never a stored price.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key; the order lines were added in, which the calculation takes them in.', autoIncrement: true ),
				new ColumnSpec( 'cart_id', 'bigint unsigned', $public, 'The cart the line belongs to.' ),
				new ColumnSpec( 'line_identity', 'char(64)', $public, 'The line\'s identity, a SHA-256 in hexadecimal of its variant and, when built, its add-ons and personalization: lines of one identity merge.', collation: 'ascii_bin' ),
				new ColumnSpec( 'variant_id', 'bigint unsigned', $public, 'The variant the line holds.' ),
				new ColumnSpec( 'quantity', 'int', $public, 'Units, 1 or more.' ),
				new ColumnSpec( 'unavailable_reason', 'varchar(32)', $public, 'Why the line cannot be bought now, such as no_price_in_currency; NULL when it can.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'priced_currency', 'char(3)', $public, 'The currency the line was last priced in; NULL until prices are stored with lines.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'priced_at_rate_version', 'bigint unsigned', $public, 'The exchange-rate version the line was last priced at; NULL until prices are stored with lines.', nullable: true ),
				new ColumnSpec( 'created_at', 'datetime(6)', $public, 'When the line was added, UTC, from the database clock.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', $public, 'When the line last changed, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'cart_line', array( 'cart_id', 'line_identity' ), 'One line per identity in a cart, which the merge of an added line relies on; also reads a cart\'s lines.' ),
			),
			array(
				IndexSpec::key( 'variant_id', array( 'variant_id' ), 'Finds the carts that hold a variant.' ),
			),
			Cart::RETENTION,
			array(
				'variants -> cart_lines' => 'Retain: a line of a deleted variant stays until its cart expires; the calculation reports it as unpriced.',
			)
		);
	}
}

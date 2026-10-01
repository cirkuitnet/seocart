<?php
/**
 * CheckoutTables: the declarations of the checkout's tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Infrastructure;

use SEOCart\Cart\Domain\Cart;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `checkout_sessions` and `idempotency_keys`.
 *
 * Owns one fact: the shape of the checkout's tables. The migration that creates them and the
 * data registry that lists them call the same factories.
 *
 * A cart has at most one checkout session, which lives exactly as long as the cart: the cart's
 * sweep deletes it in the statement that deletes the cart, so it has no expiry of its own, and
 * nothing finds it but its cart. Its two addresses are personal data,
 * kept as JSON documents of the address's fields. An idempotency key is claimed by an order
 * placement in its own transaction and completed in the same one; `response_json` holds the
 * answer the placement sent, kept until the key expires, with the order's access key sealed by
 * the cart's token and the request's idempotency key, neither of which is stored; it stays
 * classified secret.
 *
 * `updated_at` and `created_at` are `datetime(6)` and set from the database clock, so a
 * conditional UPDATE's affected-row count says whether its WHERE matched.
 *
 * @since 0.1.0
 */
final class CheckoutTables {

	/**
	 * The unprefixed name of the checkout session table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SESSIONS = 'checkout_sessions';

	/**
	 * The unprefixed name of the idempotency key table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const IDEMPOTENCY_KEYS = 'idempotency_keys';

	/**
	 * The retention policy of the idempotency keys, in RetentionCatalog.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEYS_RETENTION = 'idempotency_keys';

	/**
	 * The module both tables belong to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'Checkout';

	/**
	 * Returns the two declarations.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The session table, then the key table.
	 */
	public static function all(): array {
		return array( self::sessions(), self::idempotencyKeys() );
	}

	/**
	 * Declares `checkout_sessions`: one row per cart in checkout, from the first write of its details until the cart is swept.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function sessions(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::SESSIONS,
			self::MODULE,
			'Holds the checkout of a cart: the addresses and the shipping and payment methods the shopper gave, and the shipping and tax quotes the cart\'s totals were last worked out with, with the cart version they belong to.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'cart_id', 'bigint unsigned', $public, 'The cart in checkout; a cart has one session at most.' ),
				new ColumnSpec( 'quoted_at_cart_version', 'bigint unsigned', $public, 'The cart version the frozen quotes were taken at; 0 when there are none. Quotes of any other version than the cart\'s are stale.', defaultValue: '0' ),
				new ColumnSpec( 'billing_address_json', 'text', Classification::Pii, 'The billing address the shopper gave, as a JSON document of its fields; NULL until given.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'shipping_address_json', 'text', Classification::Pii, 'The shipping address the shopper gave, as a JSON document of its fields; NULL until given. The cart\'s totals are worked out for it.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'shipping_method_key', 'varchar(64)', $public, 'The shipping method the shopper chose, a preference that survives a re-quote; NULL for the cheapest one quoted.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'shipping_quote_json', 'text', $public, 'The shipping rate the frozen totals charged, as the calculation recorded it; NULL when there are no frozen quotes or no shipping was charged.', nullable: true ),
				new ColumnSpec( 'tax_quote_fingerprint', 'char(64)', $public, 'The SHA-256 of the tax quote the frozen totals used, in hexadecimal; NULL when there are no frozen quotes.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'payment_method_key', 'varchar(32)', $public, 'The payment method the shopper chose; NULL until chosen.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime(6)', $public, 'When the session was created, UTC, from the database clock.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', $public, 'When the session last changed, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'cart_id', array( 'cart_id' ), 'Finds a cart\'s session; a cart has one at most, which the write of its details relies on.' ),
			),
			array(),
			Cart::RETENTION,
			array(
				'carts -> checkout_sessions' => 'Cascade: a cart\'s session is deleted with it, by the cart sweep, in the statement that deletes the cart.',
			)
		);
	}

	/**
	 * Declares `idempotency_keys`: one row per key an order placement claimed, until the key expires.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function idempotencyKeys(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::IDEMPOTENCY_KEYS,
			self::MODULE,
			'Holds each idempotency key an order placement claimed: its hash, the fingerprint of the request, whether the order was placed, and the answer sent, so that a retry of the same request gets the same answer and never a second order.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'scope', 'varchar(32)', $public, 'What the key was claimed for: the operation id of the order placement.', collation: 'ascii_bin' ),
				new ColumnSpec( 'key_hash', 'char(64)', $public, 'The SHA-256 of the cart token\'s hash and the key the client sent, in hexadecimal; neither the key nor the token is stored.', collation: 'ascii_bin' ),
				new ColumnSpec( 'request_fingerprint', 'char(64)', $public, 'The SHA-256 of the canonical form of the request that claimed the key, in hexadecimal: a retry must send the same request.', collation: 'ascii_bin' ),
				new ColumnSpec( 'state', 'varchar(12)', $public, 'claimed while the request that owns the key places its order, placed once the order is placed, in the same transaction.', collation: 'ascii_bin' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', $public, 'The order placed with the key; NULL while claimed.', nullable: true ),
				new ColumnSpec( 'response_json', 'text', Classification::Secret, 'The answer the placement sent, as its settlement left it, sent again to a retry; the order\'s access key in it is sealed with the cart token and the idempotency key the retry presents, which are never stored. NULL while claimed.', nullable: true ),
				new ColumnSpec( 'expires_at', 'datetime', $public, 'When the key expires, UTC, from the database clock: an expired key is ignored when claimed, and the retention job deletes it.' ),
				new ColumnSpec( 'created_at', 'datetime(6)', $public, 'When the key was claimed, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'scope_key', array( 'scope', 'key_hash' ), 'One row per key: a second claim of a key waits on this index for the first one\'s transaction, then fails as a duplicate.' ),
			),
			array(
				IndexSpec::key( 'expires_at', array( 'expires_at' ), 'The retention job\'s search for expired keys, oldest first.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'Finds the key an order was placed with.' ),
			),
			self::KEYS_RETENTION,
			array(
				'orders -> idempotency_keys' => 'Retain: order_id keeps naming its order until the key expires; the order never depends on its key.',
			)
		);
	}
}

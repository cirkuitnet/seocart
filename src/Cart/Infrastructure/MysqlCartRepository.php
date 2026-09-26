<?php
/**
 * MysqlCartRepository: every cart statement, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Infrastructure;

use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Domain\CartRepository;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Cart\Domain\LineIdentity;
use SEOCart\Platform\Database\Database;
use SEOCart\Support\Currency;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The cart repository on MySQL: the one class that sends SQL to the cart tables.
 *
 * Owns one fact: the text of every cart statement. Each is a public constant, so a concurrency
 * test sends exactly the statement this class sends. The tables are `%i` placeholders; a
 * statement of a variable number of rows or ids holds `{rows}` or `{ids}`, which forRows() and
 * forIds() expand into placeholders.
 *
 * Every read and every compare-and-swap carries `expires_at > UTC_TIMESTAMP()`, so an expired
 * cart is invisible to them without a sweep. Every conditional UPDATE sets `updated_at` from the
 * database clock, and the ones that might otherwise leave a row as it was move it forward by at
 * least a microsecond, so one affected row always means the WHERE clause matched. The quantity
 * cap of a line is applied in the statement that adds to it, never read and then written.
 *
 * The sweep's statements live here too, because they are cart SQL; they are not part of the port
 * the service sees.
 *
 * @since 0.1.0
 */
final class MysqlCartRepository implements CartRepository {

	/**
	 * The live cart a token hash names: the read every request about a cart begins with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_BY_TOKEN_HASH = 'SELECT id, version, status, order_id, currency, locale, promotion_codes FROM %i WHERE token_hash = %s AND expires_at > UTC_TIMESTAMP()';

	/**
	 * A cart's lines, in the order they were added.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINES = 'SELECT line_identity, variant_id, quantity FROM %i WHERE cart_id = %d ORDER BY id';

	/**
	 * A new open cart at version 1, with no lines and no promotion code, living a TTL from now.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_CART = "INSERT INTO %i ( token_hash, version, status, currency, locale, channel, promotion_codes, expires_at, created_at, updated_at ) VALUES ( %s, 1, 'open', %s, %s, 'storefront', '[]', UTC_TIMESTAMP() + INTERVAL %d SECOND, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )";

	/**
	 * The compare-and-swap every write begins with: the next version, and a TTL from now, only for an open, live cart at the expected version.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMPARE_AND_SWAP = 'UPDATE %i SET version = version + 1, updated_at = UTC_TIMESTAMP(6), expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND ' . self::OPEN_AT_VERSION;

	/**
	 * The compare-and-swap of an order placement: as COMPARE_AND_SWAP, and the cart becomes placing.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM_FOR_PLACEMENT = "UPDATE %i SET version = version + 1, status = 'placing', updated_at = UTC_TIMESTAMP(6), expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND " . self::OPEN_AT_VERSION;

	/**
	 * Why a compare-and-swap changed nothing: a locking read that classifies and decides nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DIAGNOSE = 'SELECT version, status, order_id, expires_at > UTC_TIMESTAMP() AS live FROM %i WHERE id = %d FOR UPDATE';

	/**
	 * Adds lines: a new identity is inserted, a known one gains the units, up to a cap. `{rows}` is LINE_ROW once per line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADD_LINES = 'INSERT INTO %i ( cart_id, line_identity, variant_id, quantity, created_at, updated_at ) VALUES {rows} ON DUPLICATE KEY UPDATE quantity = LEAST( quantity + VALUES( quantity ), %d ), updated_at = UTC_TIMESTAMP(6)';

	/**
	 * One row of ADD_LINES: the cart, the identity, the variant and the units.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINE_ROW = '( %d, %s, %d, %d, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )';

	/**
	 * Sets one line's quantity.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SET_QUANTITY = 'UPDATE %i SET quantity = %d, updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE cart_id = %d AND line_identity = %s';

	/**
	 * Removes one line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REMOVE_LINE = 'DELETE FROM %i WHERE cart_id = %d AND line_identity = %s';

	/**
	 * Recounts a cart's lines and units into its row, only when it holds no more than a number of lines.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PROJECT = 'UPDATE %i c SET c.line_count = ( SELECT COUNT(*) FROM %i l WHERE l.cart_id = c.id ), c.item_count = ( SELECT COALESCE( SUM( l.quantity ), 0 ) FROM %i l WHERE l.cart_id = c.id ), c.updated_at = GREATEST( UTC_TIMESTAMP(6), c.updated_at + INTERVAL 1 MICROSECOND ) WHERE c.id = %d AND ( SELECT COUNT(*) FROM %i n WHERE n.cart_id = c.id ) <= %d';

	/**
	 * Records the order a placing cart produced.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BIND_ORDER = "UPDATE %i SET order_id = %d, updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE id = %d AND status = 'placing'";

	/**
	 * Moves a cart placing a given order to converted, or back to open.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SETTLE = "UPDATE %i SET status = %s, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND order_id = %d AND status = 'placing'";

	/**
	 * A page of expired carts, oldest expiry first: the sweep's search.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const EXPIRED = 'SELECT id FROM %i WHERE expires_at <= UTC_TIMESTAMP() ORDER BY expires_at LIMIT %d';

	/**
	 * Deletes the lines of the carts listed in `{ids}`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DELETE_LINES_OF = 'DELETE FROM %i WHERE cart_id IN ({ids})';

	/**
	 * Deletes the carts listed in `{ids}` that are expired.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DELETE_EXPIRED = 'DELETE FROM %i WHERE id IN ({ids}) AND expires_at <= UTC_TIMESTAMP()';

	/**
	 * The condition of both compare-and-swaps: this cart, at this version, open and live.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const OPEN_AT_VERSION = "WHERE id = %d AND version = %d AND status = 'open' AND expires_at > UTC_TIMESTAMP()";

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Returns ADD_LINES for a number of lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int $rows The lines, 1 or more.
	 * @return string The statement, with LINE_ROW once per line.
	 */
	public static function forRows( int $rows ): string {
		return str_replace( '{rows}', implode( ', ', array_fill( 0, max( 1, $rows ), self::LINE_ROW ) ), self::ADD_LINES );
	}

	/**
	 * Returns a statement that lists ids, for a number of them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement DELETE_LINES_OF or DELETE_EXPIRED.
	 * @param int    $ids       How many ids, 1 or more.
	 * @return string The statement, with one `%d` per id.
	 */
	public static function forIds( string $statement, int $ids ): string {
		return str_replace( '{ids}', implode( ', ', array_fill( 0, max( 1, $ids ), '%d' ) ), $statement );
	}

	/**
	 * Reads the live cart whose token has this hash, and its lines: two queries.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tokenHash The SHA-256 of the cart token.
	 * @return Cart|null The cart, or null when there is none or it has expired.
	 */
	public function findByTokenHash( string $tokenHash ): ?Cart {
		$cart = $this->findRowByTokenHash( $tokenHash );

		return null === $cart ? null : $cart->after( $cart->version, $this->lines( $cart->id ) );
	}

	/**
	 * Reads the live cart whose token has this hash without its lines: one query.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tokenHash The SHA-256 of the cart token.
	 * @return Cart|null The cart, its lines left empty, or null when there is none or it has expired.
	 */
	public function findRowByTokenHash( string $tokenHash ): ?Cart {
		$row = $this->db->fetchRow( self::FIND_BY_TOKEN_HASH, $this->carts(), $tokenHash );

		return null === $row ? null : new Cart(
			(int) $row['id'],
			(int) $row['version'],
			CartStatus::from( (string) $row['status'] ),
			null === $row['order_id'] ? null : (int) $row['order_id'],
			Currency::of( (string) $row['currency'] ),
			Locale::of( (string) $row['locale'] ),
			self::codes( (string) $row['promotion_codes'] ),
			array()
		);
	}

	/**
	 * Reads a cart's lines, in the order they were added.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When a stored identity is not a SHA-256 digest.
	 *
	 * @param int $cartId The cart.
	 * @return list<CartLine> The lines.
	 */
	public function lines( int $cartId ): array {
		$lines = array();

		foreach ( $this->db->fetchAll( self::LINES, $this->lineTable(), $cartId ) as $row ) {
			$identity = LineIdentity::fromString( (string) $row['line_identity'] );

			if ( null === $identity ) {
				throw new \UnexpectedValueException( sprintf( 'A line of cart %d has an identity that is not a SHA-256 digest.', $cartId ) );
			}

			$lines[] = new CartLine( $identity, (int) $row['variant_id'], (int) $row['quantity'] );
		}

		return $lines;
	}

	/**
	 * Inserts an open cart at version 1, without lines.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $tokenHash  The SHA-256 of the cart's new token.
	 * @param Currency $currency   The cart's currency.
	 * @param Locale   $locale     The cart's locale.
	 * @param int      $ttlSeconds How long the cart lives without a write.
	 * @return int The cart's id.
	 */
	public function insert( string $tokenHash, Currency $currency, Locale $locale, int $ttlSeconds ): int {
		$this->requireTransaction( __FUNCTION__ );

		$this->db->execute( self::INSERT_CART, $this->carts(), $tokenHash, $currency->code(), $locale->toString(), $ttlSeconds );

		return $this->db->lastInsertId();
	}

	/**
	 * The compare-and-swap: the next version and a TTL from now, for an open, live cart at the expected version.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId          The cart.
	 * @param int $expectedVersion The version the write was based on.
	 * @param int $ttlSeconds      How long the cart lives from now.
	 * @return bool True when the statement matched.
	 */
	public function compareAndSwap( int $cartId, int $expectedVersion, int $ttlSeconds ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->db->execute( self::COMPARE_AND_SWAP, $this->carts(), $ttlSeconds, $cartId, $expectedVersion );
	}

	/**
	 * The compare-and-swap of an order placement, which also moves the cart to placing.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId          The cart.
	 * @param int $expectedVersion The version the placement was based on.
	 * @param int $ttlSeconds      How long the cart lives from now.
	 * @return bool True when the statement matched.
	 */
	public function claimForPlacement( int $cartId, int $expectedVersion, int $ttlSeconds ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->db->execute( self::CLAIM_FOR_PLACEMENT, $this->carts(), $ttlSeconds, $cartId, $expectedVersion );
	}

	/**
	 * Reads why a compare-and-swap changed nothing, with a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId The cart.
	 * @return array{version: int, status: CartStatus, order_id: int|null, live: bool}|null The cart's state, or null when it does not exist.
	 */
	public function diagnose( int $cartId ): ?array {
		$this->requireTransaction( __FUNCTION__ );

		$row = $this->db->fetchRow( self::DIAGNOSE, $this->carts(), $cartId );

		return null === $row ? null : array(
			'version'  => (int) $row['version'],
			'status'   => CartStatus::from( (string) $row['status'] ),
			'order_id' => null === $row['order_id'] ? null : (int) $row['order_id'],
			'live'     => '1' === (string) $row['live'],
		);
	}

	/**
	 * Adds lines to a cart in one statement, whatever their number.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $cartId The cart.
	 * @param CartLine[] $lines  The lines, at most one per identity.
	 *
	 * @phpstan-param non-empty-list<CartLine> $lines
	 */
	public function addLines( int $cartId, array $lines ): void {
		$this->requireTransaction( __FUNCTION__ );

		$values = array( $this->lineTable() );

		foreach ( $lines as $line ) {
			array_push( $values, $cartId, $line->identity->value(), $line->variantId, $line->quantity );
		}

		$values[] = CartLine::MAX_QUANTITY;

		$this->db->execute( self::forRows( count( $lines ) ), ...$values );
	}

	/**
	 * Sets the quantity of one of a cart's lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $cartId   The cart.
	 * @param LineIdentity $identity The line.
	 * @param int          $quantity The units.
	 * @return bool True when the cart has the line.
	 */
	public function setQuantity( int $cartId, LineIdentity $identity, int $quantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->db->execute( self::SET_QUANTITY, $this->lineTable(), $quantity, $cartId, $identity->value() );
	}

	/**
	 * Removes one of a cart's lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $cartId   The cart.
	 * @param LineIdentity $identity The line.
	 * @return bool True when the cart had the line.
	 */
	public function removeLine( int $cartId, LineIdentity $identity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->db->execute( self::REMOVE_LINE, $this->lineTable(), $cartId, $identity->value() );
	}

	/**
	 * Recounts a cart's lines and units into its row, if it holds no more than a number of lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId   The cart.
	 * @param int $maxLines The most lines a cart may hold.
	 * @return bool True when the counts were written.
	 */
	public function project( int $cartId, int $maxLines ): bool {
		$this->requireTransaction( __FUNCTION__ );

		$lines = $this->lineTable();

		return 1 === $this->db->execute( self::PROJECT, $this->carts(), $lines, $lines, $cartId, $lines, $maxLines );
	}

	/**
	 * Records the order a placing cart produced.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId  The cart.
	 * @param int $orderId The order.
	 * @return bool True when the cart was placing.
	 */
	public function bindOrder( int $cartId, int $orderId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->db->execute( self::BIND_ORDER, $this->carts(), $orderId, $cartId );
	}

	/**
	 * Moves a cart placing a given order to converted, or back to open.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the status is placing, which a settlement never sets.
	 *
	 * @param int        $cartId  The cart.
	 * @param int        $orderId The order the cart is placing.
	 * @param CartStatus $status  CartStatus::Converted or CartStatus::Open.
	 * @return bool True when the cart was placing that order.
	 */
	public function settle( int $cartId, int $orderId, CartStatus $status ): bool {
		$this->requireTransaction( __FUNCTION__ );

		if ( CartStatus::Placing === $status ) {
			throw new \InvalidArgumentException( 'A settlement moves a placing cart to converted or back to open, never to placing.' );
		}

		return 1 === $this->db->execute( self::SETTLE, $this->carts(), $status->value, $cartId, $orderId );
	}

	/**
	 * Deletes one page of expired carts, their lines first, and returns how many expired carts the page held.
	 *
	 * The lines go first, so a failure between the two statements leaves an expired cart with no
	 * lines, which the next page deletes, and never a line without its cart. Each statement runs on
	 * its own: nothing live is touched, since an expired cart never becomes live again.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most carts to delete.
	 * @return int The expired carts found, all of which are now deleted: fewer than $limit when
	 *             there were no more.
	 */
	public function deleteExpired( int $limit ): int {
		$ids = array_map( static fn( array $row ): int => (int) $row['id'], $this->db->fetchAll( self::EXPIRED, $this->carts(), $limit ) );

		if ( array() === $ids ) {
			return 0;
		}

		$this->db->execute( self::forIds( self::DELETE_LINES_OF, count( $ids ) ), $this->lineTable(), ...$ids );
		$this->db->execute( self::forIds( self::DELETE_EXPIRED, count( $ids ) ), $this->carts(), ...$ids );

		return count( $ids );
	}

	/**
	 * Reads the stored list of promotion codes.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the column does not hold a JSON list of strings.
	 *
	 * @param string $json The column's value.
	 * @return list<string> The codes.
	 */
	private static function codes( string $json ): array {
		$codes = json_decode( $json, true );

		if ( ! is_array( $codes ) || ! array_is_list( $codes ) || array() !== array_filter( $codes, static fn( $code ): bool => ! is_string( $code ) ) ) {
			throw new \UnexpectedValueException( 'A cart\'s promotion_codes column must hold a JSON list of strings.' );
		}

		return $codes;
	}

	/**
	 * Refuses to change a cart outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method The method called.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->db->depth() ) {
			throw new \LogicException( sprintf( 'MysqlCartRepository::%s() runs only inside a transaction: every cart change begins with the compare-and-swap in the same transaction.', $method ) );
		}
	}

	/**
	 * Returns the cart table's full name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name, with the site's prefix.
	 */
	private function carts(): string {
		return $this->db->table( CartTables::CARTS );
	}

	/**
	 * Returns the cart line table's full name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name, with the site's prefix.
	 */
	private function lineTable(): string {
		return $this->db->table( CartTables::LINES );
	}
}

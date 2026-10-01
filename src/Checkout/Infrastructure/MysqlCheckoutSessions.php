<?php
/**
 * MysqlCheckoutSessions: every checkout session statement, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Infrastructure;

use SEOCart\Checkout\Application\CheckoutSessions;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutSession;
use SEOCart\Checkout\Domain\FrozenQuotes;
use SEOCart\Platform\Database\Database;
use SEOCart\Support\Address;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a programming error or a damaged row to the developer; they are never HTML.

/**
 * The checkout sessions on MySQL: the one class that sends SQL to `checkout_sessions`.
 *
 * Owns one fact: the text of every session statement. Each is a public constant, so a test sends
 * exactly the statement this class sends. An absent value is sent as an empty string and stored
 * as NULL (`NULLIF( %s, '' )`): no address document, method key or quote document is ever empty.
 *
 * The cart's sweep deletes a session with its cart; nothing here deletes one.
 *
 * @since 0.1.0
 */
final class MysqlCheckoutSessions implements CheckoutSessions {

	/**
	 * A cart's session.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND = 'SELECT quoted_at_cart_version, billing_address_json, shipping_address_json, shipping_method_key, payment_method_key, shipping_quote_json, tax_quote_fingerprint FROM %i WHERE cart_id = %d';

	/**
	 * Creates a cart's session with its details, or replaces the details of the one it has; either way without quotes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SAVE = "INSERT INTO %i ( cart_id, quoted_at_cart_version, billing_address_json, shipping_address_json, shipping_method_key, payment_method_key, created_at, updated_at ) VALUES ( %d, 0, NULLIF( %s, '' ), NULLIF( %s, '' ), NULLIF( %s, '' ), NULLIF( %s, '' ), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE quoted_at_cart_version = 0, billing_address_json = VALUES( billing_address_json ), shipping_address_json = VALUES( shipping_address_json ), shipping_method_key = VALUES( shipping_method_key ), payment_method_key = VALUES( payment_method_key ), shipping_quote_json = NULL, tax_quote_fingerprint = NULL, updated_at = UTC_TIMESTAMP(6)";

	/**
	 * Keeps the quotes of a cart version, unless the session holds quotes of a later one.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STORE_QUOTES = "UPDATE %i SET quoted_at_cart_version = %d, shipping_quote_json = NULLIF( %s, '' ), tax_quote_fingerprint = %s, updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE cart_id = %d AND quoted_at_cart_version <= %d";

	/**
	 * Drops a session's quotes; the shipping method chosen stays.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INVALIDATE_QUOTES = 'UPDATE %i SET quoted_at_cart_version = 0, shipping_quote_json = NULL, tax_quote_fingerprint = NULL, updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE cart_id = %d';

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
	 * Reads a cart's session: one query.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When a stored document is not what this class writes.
	 *
	 * @param int $cartId The cart.
	 * @return CheckoutSession|null The session, or null when the cart has none.
	 */
	public function find( int $cartId ): ?CheckoutSession {
		$row = $this->db->fetchRow( self::FIND, $this->sessions(), $cartId );

		if ( null === $row ) {
			return null;
		}

		$details = new CheckoutDetails(
			self::address( $row['billing_address_json'] ),
			self::address( $row['shipping_address_json'] ),
			null === $row['shipping_method_key'] ? null : (string) $row['shipping_method_key'],
			null === $row['payment_method_key'] ? null : (string) $row['payment_method_key']
		);

		$version = (int) $row['quoted_at_cart_version'];
		$quotes  = 0 === $version || null === $row['tax_quote_fingerprint']
			? null
			: new FrozenQuotes( null === $row['shipping_quote_json'] ? null : self::document( (string) $row['shipping_quote_json'] ), (string) $row['tax_quote_fingerprint'] );

		return new CheckoutSession( $cartId, $details, $version, $quotes );
	}

	/**
	 * Writes a cart's details, and drops its quotes. Runs only inside the caller's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int             $cartId  The cart.
	 * @param CheckoutDetails $details The details.
	 */
	public function save( int $cartId, CheckoutDetails $details ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->db->execute(
			self::SAVE,
			$this->sessions(),
			$cartId,
			self::json( $details->billingAddress ),
			self::json( $details->shippingAddress ),
			(string) $details->shippingMethodKey,
			(string) $details->paymentMethodKey
		);
	}

	/**
	 * Keeps the quotes of a cart version, unless the session holds quotes of a later one.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the version is below 1.
	 *
	 * @param int          $cartId      The cart.
	 * @param int          $cartVersion The cart's version the quotes were taken at.
	 * @param FrozenQuotes $quotes      The quotes.
	 * @return bool True when the quotes were kept.
	 */
	public function storeQuotes( int $cartId, int $cartVersion, FrozenQuotes $quotes ): bool {
		if ( $cartVersion < 1 ) {
			throw new \InvalidArgumentException( 'Quotes belong to a cart version of 1 or more; 0 means the session has none.' );
		}

		$rate = null === $quotes->shippingRate ? '' : (string) wp_json_encode( $quotes->shippingRate );

		return 1 === $this->db->execute( self::STORE_QUOTES, $this->sessions(), $cartVersion, $rate, $quotes->taxFingerprint, $cartId, $cartVersion );
	}

	/**
	 * Drops a session's quotes. Runs only inside the caller's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int $cartId The cart.
	 * @return bool True when the cart has a session.
	 */
	public function invalidateQuotes( int $cartId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->db->execute( self::INVALIDATE_QUOTES, $this->sessions(), $cartId );
	}

	/**
	 * Writes an address as its JSON document, or an empty string for none.
	 *
	 * @since 0.1.0
	 *
	 * @param Address|null $address The address.
	 * @return string The document, or an empty string, which the statement stores as NULL.
	 */
	private static function json( ?Address $address ): string {
		return null === $address ? '' : (string) wp_json_encode( AddressDocument::of( $address ) );
	}

	/**
	 * Reads an address from its stored document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the document is not an address this class wrote.
	 *
	 * @param mixed $stored The column's value: a JSON document, or null.
	 * @return Address|null The address, or null when none is stored.
	 */
	private static function address( mixed $stored ): ?Address {
		if ( null === $stored ) {
			return null;
		}

		try {
			return AddressDocument::toAddress( self::document( (string) $stored ) );
		} catch ( \InvalidArgumentException $damaged ) {
			throw new \UnexpectedValueException( 'A checkout session holds an address document that is not an address.', 0, $damaged );
		}
	}

	/**
	 * Decodes a stored JSON object.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the text is not a JSON object.
	 *
	 * @param string $json The column's value.
	 * @return array<string, mixed> The object's members.
	 */
	private static function document( string $json ): array {
		$document = json_decode( $json, true );

		if ( ! is_array( $document ) || array_is_list( $document ) ) {
			throw new \UnexpectedValueException( 'A checkout session column that holds a JSON object holds something else.' );
		}

		return $document;
	}

	/**
	 * Refuses to change a session outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method The method called.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->db->depth() ) {
			throw new \LogicException( sprintf( 'MysqlCheckoutSessions::%s() runs only inside a transaction that began with the cart\'s compare-and-swap: a session changes together with its cart\'s version.', $method ) );
		}
	}

	/**
	 * Returns the session table's full name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name, with the site's prefix.
	 */
	private function sessions(): string {
		return $this->db->table( CheckoutTables::SESSIONS );
	}
}

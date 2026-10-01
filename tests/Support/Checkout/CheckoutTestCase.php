<?php
/**
 * CheckoutTestCase: the base of every checkout test that needs real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\MysqlCheckoutSessions;
use SEOCart\Checkout\Infrastructure\MysqlIdempotencyKeys;
use SEOCart\Support\Address;
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The base plants key rows directly and reads them back through a second connection.

/**
 * A CartTestCase with the checkout's repositories and its session write over the same connection.
 *
 * Owns one fact: how a checkout test gets a cart, a session and keys to work on. The checkout's
 * tables come from CartTestCase, whose cart sweep deletes a cart's session; the cart service it
 * builds already prices a cart for its session's shipping address. No test reads a clock: a key's
 * age and expiry are set and compared by the database clock alone.
 *
 * @since 0.1.0
 */
abstract class CheckoutTestCase extends CartTestCase {

	/**
	 * The scope every key of a test is claimed in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const SCOPE = 'checkout.place_order';

	/**
	 * How long a key lives in a test: the retention period of the keys, thirty days.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	protected const KEY_TTL = 2592000;

	/**
	 * The sessions over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlCheckoutSessions
	 */
	protected MysqlCheckoutSessions $sessions;

	/**
	 * The keys over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlIdempotencyKeys
	 */
	protected MysqlIdempotencyKeys $keys;

	/**
	 * The session write over `$this->service`.
	 *
	 * @since 0.1.0
	 *
	 * @var UpdateCheckoutSession
	 */
	protected UpdateCheckoutSession $checkout;

	/**
	 * Builds the repositories and the session write.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->sessions = new MysqlCheckoutSessions( $this->db );
		$this->keys     = new MysqlIdempotencyKeys( $this->db );
		$this->checkout = new UpdateCheckoutSession( $this->service, $this->sessions );
	}

	/**
	 * Builds an address in a country, with a name and a street.
	 *
	 * @since 0.1.0
	 *
	 * @param string $country The country code.
	 * @param string $email   Optional. The e-mail address. Default empty.
	 * @return Address The address.
	 */
	protected static function address( string $country, string $email = '' ): Address {
		return new Address( $country, first_name: 'Ada', last_name: 'Lovelace', line1: '12 St James\'s Square', city: 'London', postcode: 'SW1Y 4JH', email: $email );
	}

	/**
	 * Returns a SHA-256 digest of a text, as a key hash or a fingerprint.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text.
	 * @return string The digest.
	 */
	protected static function digest( string $text ): string {
		return hash( 'sha256', $text );
	}

	/**
	 * Returns the hash of a key on a made-up cart.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The key.
	 * @return string The hash.
	 */
	protected static function keyHash( string $key ): string {
		return IdempotencyClaim::keyHash( self::digest( 'a cart token' ), $key );
	}

	/**
	 * Claims a key over `$this->db` in a transaction of its own, which commits.
	 *
	 * @since 0.1.0
	 *
	 * @param string $keyHash     The key's hash.
	 * @param string $fingerprint The request's fingerprint.
	 * @return IdempotencyClaim The claim.
	 */
	protected function claimCommitted( string $keyHash, string $fingerprint ): IdempotencyClaim {
		return $this->db->transaction( fn(): IdempotencyClaim => $this->keys->claim( self::SCOPE, $keyHash, $fingerprint, self::KEY_TTL ) );
	}

	/**
	 * Reads a key's row as connection B sees it, which is what is committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b       Connection B.
	 * @param string           $keyHash The key's hash.
	 * @return array{id: int, state: string, request_fingerprint: string, order_id: int|null, response_json: string|null}|null The row, or null.
	 *
	 * @phpstan-impure
	 */
	protected function committedKey( SecondConnection $b, string $keyHash ): ?array {
		$row = $b->fetchRow( $this->raw( 'SELECT id, state, request_fingerprint, order_id, response_json FROM %i WHERE scope = %s AND key_hash = %s', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), self::SCOPE, $keyHash ) );

		return null === $row ? null : array(
			'id'                  => (int) $row['id'],
			'state'               => (string) $row['state'],
			'request_fingerprint' => (string) $row['request_fingerprint'],
			'order_id'            => null === $row['order_id'] ? null : (int) $row['order_id'],
			'response_json'       => $row['response_json'],
		);
	}

	/**
	 * Counts the rows of a checkout table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table CheckoutTables::SESSIONS or CheckoutTables::IDEMPOTENCY_KEYS.
	 * @return int The rows.
	 */
	protected function checkoutRows( string $table ): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( $table ) );
	}

	/**
	 * Moves a key's expiry, by the database clock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id      The key's row.
	 * @param int $seconds How long it has left; below 0, how long ago it expired.
	 */
	protected function keyExpiresIn( int $id, int $seconds ): void {
		$this->db->execute( 'UPDATE %i SET expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND WHERE id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $seconds, $id );
	}

	/**
	 * Moves when a key was claimed into the past, by the database clock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id      The key's row.
	 * @param int $seconds How long ago.
	 */
	protected function keyClaimedAgo( int $id, int $seconds ): void {
		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL %d SECOND WHERE id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $seconds, $id );
	}
}

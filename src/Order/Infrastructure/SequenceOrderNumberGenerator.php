<?php
/**
 * SequenceOrderNumberGenerator: allocates order numbers from a counter row per scope
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Infrastructure;

use SEOCart\Order\Domain\OrderNumberGenerator;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * Allocates an order number with one statement on `order_number_seq`, by primary key.
 *
 * Owns one fact: the statement that allocates a number, and how a number is written. The
 * statement creates the scope's row on its first use and increments it on every later one, and
 * in both branches it hands the new value to the connection through LAST_INSERT_ID( expr ), so
 * there is no first-use branch here and no second read. The row stays locked until the caller's
 * transaction ends: two placements get different numbers, and a placement that rolls back returns
 * its increment. That lock serialises placements, so a placement allocates its number last,
 * right before it writes the order.
 *
 * @since 0.1.0
 */
final class SequenceOrderNumberGenerator implements OrderNumberGenerator {

	/**
	 * The allocation: creates the scope's counter at 1, or adds one to it, and makes the new value the connection's insert id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ALLOCATE = 'INSERT INTO {order_number_seq} ( scope_key, pattern, period_key, next_value, updated_at ) VALUES ( %s, %s, NULL, LAST_INSERT_ID( 1 ), UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID( next_value + 1 ), updated_at = UTC_TIMESTAMP(6)';

	/**
	 * How a number is written: the counter, zero-padded to DIGITS.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PATTERN = '{number}';

	/**
	 * The fewest digits a number is written with.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const DIGITS = 6;

	/**
	 * A scope key: a lowercase snake_case word that fits the column.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SCOPE_PATTERN = '/^[a-z][a-z0-9_]{0,31}\z/';

	/**
	 * Sends the statement.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderStatements
	 */
	private OrderStatements $statements;

	/**
	 * Creates the generator. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderStatements $statements Sends the statement.
	 */
	public function __construct( OrderStatements $statements ) {
		$this->statements = $statements;
	}

	/**
	 * Allocates the next number of a scope, inside the caller's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException           Outside a transaction.
	 * @throws \InvalidArgumentException When the scope is not a lowercase snake_case word of at most 32 characters.
	 *
	 * @param string $scope The numbering scope.
	 * @return string The number, for example `000042`.
	 */
	public function next( string $scope ): string {
		self::checkScope( $scope );

		$this->statements->requireTransaction( __METHOD__ );
		$this->statements->execute( self::ALLOCATE, $scope, self::PATTERN );

		return str_replace( '{number}', str_pad( (string) $this->statements->lastInsertId(), self::DIGITS, '0', STR_PAD_LEFT ), self::PATTERN );
	}

	/**
	 * Refuses a scope key the counter table cannot hold.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the scope is not a lowercase snake_case word of at most 32 characters.
	 *
	 * @param string $scope The numbering scope.
	 */
	private static function checkScope( string $scope ): void {
		if ( 1 !== preg_match( self::SCOPE_PATTERN, $scope ) ) {
			throw new \InvalidArgumentException( sprintf( 'A numbering scope is a lowercase snake_case word of at most 32 characters; "%s" was given.', $scope ) );
		}
	}
}

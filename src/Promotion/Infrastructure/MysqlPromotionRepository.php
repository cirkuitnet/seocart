<?php
/**
 * MysqlPromotionRepository: every promotion statement, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Infrastructure;

use SEOCart\Platform\Database\Database;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Promotion\Application\PromotionRepository;
use SEOCart\Promotion\Application\UsageClaim;
use SEOCart\Promotion\Domain\Promotion;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\Percentage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error or a malformed row to the developer; they are never HTML.

/**
 * The promotion repository on MySQL: the one class that sends SQL to the promotion tables.
 *
 * Owns one fact: the text of every promotion statement. Each is a public constant whose first
 * placeholder is the table, so a concurrency test sends exactly the statement this class sends.
 * The two statements that count a promotion's uses are the only ones that take its row lock,
 * and each sets `updated_at` from the database clock, so one affected row always means its
 * WHERE clause matched. The read for `doctor` lives here too, because it is promotion SQL; it is
 * not part of the port the services see.
 *
 * @since 0.1.0
 */
final class MysqlPromotionRepository implements PromotionRepository {

	/**
	 * The promotions with any of a list of codes, with their uses and limit; the list is appended as `( %s, … )`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND_BY_CODES = 'SELECT id, uuid, code, status, effect_kind, effect_percent_micropercent, effect_amount_minor, effect_currency, effect_amount_basis, priority, starts_at, ends_at, used, usage_limit FROM %i WHERE code IN ';

	/**
	 * Counts one use of an active promotion, if its limit allows one more: the invariant is the WHERE clause.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM = "UPDATE %i SET used = used + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND status = '" . Promotion::ACTIVE . "' AND ( usage_limit IS NULL OR used < usage_limit )";

	/**
	 * Records a claimed use, reserved; a customer id of 0 and an empty hash are stored as NULL.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_USAGE = "INSERT INTO %i ( promotion_id, order_id, customer_id, cart_token_hash, amount_minor, currency, base_amount_minor, base_currency, state, created_at ) VALUES ( %d, %d, NULLIF( %d, 0 ), NULLIF( %s, '' ), %d, %s, %d, %s, 'reserved', UTC_TIMESTAMP(6) )";

	/**
	 * Commits an order's reserved uses.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMIT_USAGE = "UPDATE %i SET state = 'committed' WHERE order_id = %d AND state = 'reserved'";

	/**
	 * Locks an order's reserved uses, and reads their promotions in the order they are given back.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RESERVED_FOR_ORDER = "SELECT promotion_id FROM %i WHERE order_id = %d AND state = 'reserved' ORDER BY promotion_id FOR UPDATE";

	/**
	 * Gives one use of a promotion back; a count already at zero is left alone.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GIVE_BACK = 'UPDATE %i SET used = used - 1, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND used > 0';

	/**
	 * Releases an order's reserved uses.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RELEASE_USAGE = "UPDATE %i SET state = 'released', released_at = UTC_TIMESTAMP() WHERE order_id = %d AND state = 'reserved'";

	/**
	 * A page of promotions by the primary key, after the last id of the page before, each with its `used` and the count of its reserved and committed uses; the promotion table, then the usage table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const USAGE_COUNTS = "SELECT p.id, p.used, COUNT( u.id ) AS counted FROM %i p LEFT JOIN %i u ON u.promotion_id = p.id AND u.state IN ( 'reserved', 'committed' ) WHERE p.id > %d GROUP BY p.id ORDER BY p.id LIMIT %d";

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( private Database $db ) {
	}

	/**
	 * Reads the promotions that have any of the codes, with one query.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When a row's effect is malformed.
	 *
	 * @param array $codes The codes, at least one, each once.
	 * @return list<Promotion> The promotions found, in no particular order.
	 *
	 * @phpstan-param non-empty-list<string> $codes
	 */
	public function findByCodes( array $codes ): array {
		$rows = $this->db->fetchAll(
			self::FIND_BY_CODES . '( ' . implode( ', ', array_fill( 0, count( $codes ), '%s' ) ) . ' )',
			$this->db->table( PromotionTables::PROMOTIONS ),
			...$codes
		);

		return array_map( static fn( array $row ): Promotion => self::promotion( $row ), $rows );
	}

	/**
	 * Counts one use of an active promotion, if its limit allows one more.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @return bool True when the use was counted.
	 */
	public function claim( int $promotionId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->db->execute( self::CLAIM, $this->db->table( PromotionTables::PROMOTIONS ), $promotionId );
	}

	/**
	 * Records a claimed use, reserved.
	 *
	 * @since 0.1.0
	 *
	 * @param UsageClaim $claim The use.
	 */
	public function insertUsage( UsageClaim $claim ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->db->execute(
			self::INSERT_USAGE,
			$this->db->table( PromotionTables::USAGE ),
			$claim->promotionId,
			$claim->orderId,
			$claim->customerId ?? 0,
			$claim->cartTokenHash ?? '',
			$claim->amount->minorUnits(),
			$claim->amount->currency()->code(),
			$claim->base->minorUnits(),
			$claim->base->currency()->code()
		);
	}

	/**
	 * Marks an order's reserved uses committed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 */
	public function commitUsage( int $orderId ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->db->execute( self::COMMIT_USAGE, $this->db->table( PromotionTables::USAGE ), $orderId );
	}

	/**
	 * Locks an order's reserved uses, and returns their promotions.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<int> The promotions' ids, ascending.
	 */
	public function reservedPromotionIds( int $orderId ): array {
		$this->requireTransaction( __FUNCTION__ );

		return array_map( static fn( array $row ): int => (int) $row['promotion_id'], $this->db->fetchAll( self::RESERVED_FOR_ORDER, $this->db->table( PromotionTables::USAGE ), $orderId ) );
	}

	/**
	 * Gives one use of a promotion back, if it has one counted.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @return bool True when a use was given back.
	 */
	public function giveBack( int $promotionId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->db->execute( self::GIVE_BACK, $this->db->table( PromotionTables::PROMOTIONS ), $promotionId );
	}

	/**
	 * Marks an order's reserved uses released.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 */
	public function releaseUsage( int $orderId ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->db->execute( self::RELEASE_USAGE, $this->db->table( PromotionTables::USAGE ), $orderId );
	}

	/**
	 * Returns a page of promotions, each with its `used` and the count of its reserved and committed uses, for doctor.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId The last promotion of the page before; 0 for the first page.
	 * @param int $limit   The most promotions the page holds.
	 * @return list<array{promotion_id: int, used: int, counted: int}> The promotions, by id.
	 */
	public function usageCounts( int $afterId, int $limit ): array {
		$rows = $this->db->fetchAll( self::USAGE_COUNTS, $this->db->table( PromotionTables::PROMOTIONS ), $this->db->table( PromotionTables::USAGE ), $afterId, $limit );

		return array_map(
			static fn( array $row ): array => array(
				'promotion_id' => (int) $row['id'],
				'used'         => (int) $row['used'],
				'counted'      => (int) $row['counted'],
			),
			$rows
		);
	}

	/**
	 * Builds a promotion from its row.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row The row.
	 * @return Promotion The promotion.
	 */
	private static function promotion( array $row ): Promotion {
		return new Promotion(
			(int) $row['id'],
			(string) $row['uuid'],
			(string) $row['code'],
			(string) $row['status'],
			self::effect( $row ),
			(int) $row['priority'],
			self::instant( $row['starts_at'] ),
			self::instant( $row['ends_at'] ),
			(int) $row['used'],
			null === $row['usage_limit'] ? null : (int) $row['usage_limit']
		);
	}

	/**
	 * Builds a promotion's effect from its row.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the kind is unknown or the kind's figures are missing.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return PromotionEffect The effect.
	 */
	private static function effect( array $row ): PromotionEffect {
		$kind = (string) $row['effect_kind'];

		return match ( $kind ) {
			PromotionEffect::PERCENT       => PromotionEffect::percent( Percentage::fromMicropercent( (int) self::required( $row, 'effect_percent_micropercent' ) ) ),
			PromotionEffect::FIXED         => PromotionEffect::fixed(
				new AuthoredAmount(
					Money::of( (int) self::required( $row, 'effect_amount_minor' ), Currency::of( (string) self::required( $row, 'effect_currency' ) ) ),
					AmountBasis::from( (string) self::required( $row, 'effect_amount_basis' ) )
				)
			),
			PromotionEffect::FREE_SHIPPING => PromotionEffect::freeShipping(),
			default                        => throw new \UnexpectedValueException( sprintf( 'Promotion %d has the effect "%s", which is none of percent, fixed and free_shipping.', (int) $row['id'], $kind ) ),
		};
	}

	/**
	 * Returns a column the promotion's effect needs.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the column is NULL.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param string               $column The column.
	 * @return mixed The value.
	 */
	private static function required( array $row, string $column ): mixed {
		if ( null === $row[ $column ] ) {
			throw new \UnexpectedValueException( sprintf( 'Promotion %d has a %s effect without its %s.', (int) $row['id'], (string) $row['effect_kind'], $column ) );
		}

		return $row[ $column ];
	}

	/**
	 * Reads a UTC datetime column.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value, or NULL.
	 * @return \DateTimeImmutable|null The instant, or null.
	 */
	private static function instant( mixed $value ): ?\DateTimeImmutable {
		return null === $value ? null : new \DateTimeImmutable( (string) $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Refuses to change a use outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method The method called.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->db->depth() ) {
			throw new \LogicException( sprintf( 'MysqlPromotionRepository::%s() runs only inside a transaction: its statement is one of a group that must commit together.', $method ) );
		}
	}
}

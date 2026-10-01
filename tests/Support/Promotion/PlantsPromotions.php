<?php
/**
 * PlantsPromotions: creates the promotion tables and plants promotions and uses in them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Promotion;

use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Promotion\Infrastructure\Migrations\CreatePromotionTables;
use SEOCart\Promotion\Infrastructure\PromotionTables;

/**
 * How an integration test gets promotions: the tables from their own migration, and rows written directly.
 *
 * Owns one fact: the promotion a test plants when it says nothing else, an active code worth
 * 10 % off with no window and no limit. A test names only the columns it is about. A planted
 * row's uuid is derived from a counter kept across the run, so no two rows of a run share one.
 * The using class is a DatabaseTestCase: rows go through its `$this->db`, and its tear-down
 * drops the tables.
 *
 * @since 0.1.0
 */
trait PlantsPromotions {

	/**
	 * How many promotions the run has planted.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private static int $plantedPromotions = 0;

	/**
	 * Creates the three promotion tables through their migration.
	 *
	 * @since 0.1.0
	 */
	protected function createPromotionTables(): void {
		( new CreatePromotionTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Plants a promotion.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code    The code.
	 * @param array  $columns Optional. Columns to set, which replace the defaults; a null value is stored as NULL. Default none.
	 * @return int The promotion's id.
	 *
	 * @phpstan-param array<string, int|string|null> $columns
	 */
	protected function plantPromotion( string $code, array $columns = array() ): int {
		$row = array_replace(
			array(
				'uuid'                        => Promotions::uuid( 900000 + ++self::$plantedPromotions ),
				'code'                        => $code,
				'trigger_kind'                => 'code',
				'status'                      => 'active',
				'effect_kind'                 => 'percent',
				'effect_percent_micropercent' => 10000000,
				'priority'                    => 10,
				'used'                        => 0,
			),
			$columns
		);

		$values = array();
		$args   = array( $this->db->table( PromotionTables::PROMOTIONS ) );

		foreach ( $row as $value ) {
			if ( null === $value ) {
				$values[] = 'NULL';
			} else {
				$values[] = is_int( $value ) ? '%d' : '%s';
				$args[]   = $value;
			}
		}

		$this->db->execute(
			'INSERT INTO %i ( ' . implode( ', ', array_keys( $row ) ) . ', created_at, updated_at ) VALUES ( ' . implode( ', ', $values ) . ', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )',
			...$args
		);

		return $this->db->lastInsertId();
	}

	/**
	 * Plants a use of a promotion by an order, without counting it.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $promotionId The promotion.
	 * @param int    $orderId     The order.
	 * @param string $state       reserved, committed or released.
	 */
	protected function plantUsage( int $promotionId, int $orderId, string $state ): void {
		$this->db->execute(
			"INSERT INTO %i ( promotion_id, order_id, amount_minor, currency, base_amount_minor, base_currency, state, created_at ) VALUES ( %d, %d, -100, 'USD', -100, 'USD', %s, UTC_TIMESTAMP(6) )",
			$this->db->table( PromotionTables::USAGE ),
			$promotionId,
			$orderId,
			$state
		);
	}

	/**
	 * Sets a promotion's count directly, as a corrupting bug would.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @param int $used        The count.
	 */
	protected function setUsed( int $promotionId, int $used ): void {
		$this->db->execute( 'UPDATE %i SET used = %d WHERE id = %d', $this->db->table( PromotionTables::PROMOTIONS ), $used, $promotionId );
	}
}

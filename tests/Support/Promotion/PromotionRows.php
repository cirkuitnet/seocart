<?php
/**
 * PromotionRows: writes a promotion row
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Promotion;

use SEOCart\Platform\Database\Database;
use SEOCart\Promotion\Infrastructure\PromotionTables;

/**
 * The one statement that writes a promotion row for test support.
 *
 * Owns one fact: the promotion a caller gets when it says nothing else, an active code worth
 * 10 % off with no window and no limit. The integration tests plant through it (PlantsPromotions)
 * and so does the end-to-end seed, so the two cannot disagree about what a planted promotion is.
 * Nothing in the plugin creates a promotion yet, which is why test support writes the row itself.
 *
 * @since 0.1.0
 */
final class PromotionRows {

	/**
	 * Writes a promotion.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db      The connection.
	 * @param string   $uuid    The promotion's uuid.
	 * @param string   $code    The code.
	 * @param array    $columns Optional. Columns to set, which replace the defaults; a null value is stored as NULL. Default none.
	 * @return int The promotion's id.
	 *
	 * @phpstan-param array<string, int|string|null> $columns
	 */
	public static function plant( Database $db, string $uuid, string $code, array $columns = array() ): int {
		$row = array_replace(
			array(
				'uuid'                        => $uuid,
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
		$args   = array( $db->table( PromotionTables::PROMOTIONS ) );

		foreach ( $row as $value ) {
			if ( null === $value ) {
				$values[] = 'NULL';
			} else {
				$values[] = is_int( $value ) ? '%d' : '%s';
				$args[]   = $value;
			}
		}

		$db->execute(
			'INSERT INTO %i ( ' . implode( ', ', array_keys( $row ) ) . ', created_at, updated_at ) VALUES ( ' . implode( ', ', $values ) . ', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )',
			...$args
		);

		return $db->lastInsertId();
	}
}

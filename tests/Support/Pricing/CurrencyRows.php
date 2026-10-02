<?php
/**
 * CurrencyRows: writes a currency row
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Platform\Database\Database;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Support\RoundingMode;

/**
 * The one statement that writes a currency row for test support.
 *
 * Owns one fact: how a currency a store sells in besides its base currency is stored. The
 * integration tests enable currencies through it (PricesInCurrencies) and so does the end-to-end
 * seed. Nothing in the plugin writes the row yet, which is why test support writes it itself.
 *
 * @since 0.1.0
 */
final class CurrencyRows {

	/**
	 * Writes a currency's terms.
	 *
	 * @since 0.1.0
	 *
	 * @param Database     $db            The connection.
	 * @param string       $code          The ISO code.
	 * @param bool         $fallback      Optional. Whether base prices may be converted into it. Default true.
	 * @param RoundingMode $mode          Optional. How its amounts are rounded. Default half up.
	 * @param int          $cashStepMinor Optional. Its cash rounding step, in minor units. Default 0, none.
	 * @param bool         $enabled       Optional. Whether prices are offered in it. Default true.
	 */
	public static function enable( Database $db, string $code, bool $fallback = true, RoundingMode $mode = RoundingMode::HalfUp, int $cashStepMinor = 0, bool $enabled = true ): void {
		$db->execute(
			'INSERT INTO %i ( code, is_enabled, rounding_mode, cash_rounding_step_minor, conversion_fallback_allowed ) VALUES ( %s, %d, %s, %d, %d )',
			$db->table( PricingTables::CURRENCIES ),
			$code,
			(int) $enabled,
			$mode->value,
			$cashStepMinor,
			(int) $fallback
		);
	}
}

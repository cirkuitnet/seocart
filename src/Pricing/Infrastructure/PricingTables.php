<?php
/**
 * PricingTables: the declarations of the currencies and exchange-rate tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Infrastructure;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `currencies` and `exchange_rates`.
 *
 * Owns one fact: the shape of the pricing module's storage. The migration that creates the tables
 * and the data registry that lists them call these factories, so each table is declared once.
 *
 * `currencies` is the merchant's configuration of every currency the store sells in besides its
 * base currency. A currency's number of decimal places is never stored: it is the ISO 4217
 * standard's, held in code, so it cannot be mistyped. `exchange_rates` keeps every version of the
 * rates, each saved as a whole and never changed; its unique key holds one rate per currency pair
 * per version. Both are kept for as long as the store is.
 *
 * @since 0.1.0
 */
final class PricingTables {

	/**
	 * The unprefixed name of the currencies table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CURRENCIES = 'currencies';

	/**
	 * The unprefixed name of the exchange-rate table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const EXCHANGE_RATES = 'exchange_rates';

	/**
	 * The module every table belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'Pricing';

	/**
	 * The retention policy of both tables: never removed by age.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PERMANENT = 'permanent';

	/**
	 * Returns the two declarations.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The currencies, then the exchange rates.
	 */
	public static function all(): array {
		return array( self::currencies(), self::exchangeRates() );
	}

	/**
	 * Returns the unprefixed names of the two tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names, in the order of all().
	 */
	public static function names(): array {
		return array( self::CURRENCIES, self::EXCHANGE_RATES );
	}

	/**
	 * Declares `currencies`: each currency the store may sell in besides its base currency, and the terms it is offered on.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function currencies(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::CURRENCIES,
			self::MODULE,
			'Lists the currencies the store may sell in besides its base currency: whether each is offered, how its amounts are rounded, and whether a variant priced only in the base currency may be sold in it at a converted price.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'code', 'char(3)', $public, 'The currency, ISO 4217; its number of decimal places is the standard\'s, never stored.', collation: 'ascii_bin' ),
				new ColumnSpec( 'is_enabled', 'tinyint(1)', $public, '1 when prices are offered in the currency; 0 keeps its terms without offering it.' ),
				new ColumnSpec( 'rounding_mode', 'varchar(16)', $public, 'How amounts in the currency are rounded: half_up or toward_zero.', defaultValue: 'half_up', collation: 'ascii_bin' ),
				new ColumnSpec( 'cash_rounding_step_minor', 'int unsigned', $public, 'The lawful cash rounding step, in minor units, a converted price is rounded to; 0 or 1 for none.', defaultValue: '0' ),
				new ColumnSpec( 'conversion_fallback_allowed', 'tinyint(1)', $public, '1 when a variant without a price in the currency may be sold at its base price converted at the current rate; 0 when such a variant is not priced in it.' ),
			),
			array( 'code' ),
			array(),
			array(),
			self::PERMANENT,
			array()
		);
	}

	/**
	 * Declares `exchange_rates`: every version of the store's rates, appended and never changed.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function exchangeRates(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::EXCHANGE_RATES,
			self::MODULE,
			'Keeps every version of the store\'s exchange rates, each a complete set saved at once and never changed, the one the installation record names being current; kept permanently because every order placed in another currency froze its rate from one of them.',
			MutationPattern::AppendOnly,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'base_currency', 'char(3)', $public, 'The currency one unit of which the rate prices: the store\'s base currency when the rate was saved.', collation: 'ascii_bin' ),
				new ColumnSpec( 'quote_currency', 'char(3)', $public, 'The currency the rate is expressed in.', collation: 'ascii_bin' ),
				new ColumnSpec( 'rate', 'decimal(24,12)', Classification::Financial, 'One base unit is this many quote units, stored at twelve places.' ),
				new ColumnSpec( 'rate_scale', 'tinyint unsigned', $public, 'The scale the rate was quoted at, which reading it back restores.' ),
				new ColumnSpec( 'version', 'bigint unsigned', $public, 'The version of the rate set: every rate saved together has the same one, and each save takes the next.' ),
				new ColumnSpec( 'source', 'varchar(32)', $public, 'Where the rate came from, for example manual.', collation: 'ascii_bin' ),
				new ColumnSpec(
					'created_by',
					'bigint unsigned',
					Classification::Pii,
					'The WordPress user who saved the rate set; NULL when no user did.',
					nullable: true,
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'The rates are the permanent, append-only record every order\'s conversion was taken from; who saved each set is part of that record.'
				),
				new ColumnSpec( 'created_at', 'datetime(6)', $public, 'When the rate set was saved, UTC, from the database clock: the time a rate frozen from it was quoted at.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'pair_version', array( 'base_currency', 'quote_currency', 'version' ), 'A version holds one rate per currency pair, so the current rate of a pair is one row.' ),
			),
			array(
				IndexSpec::key( 'version', array( 'version' ), 'The newest version, which the next save follows.' ),
			),
			self::PERMANENT,
			array()
		);
	}
}

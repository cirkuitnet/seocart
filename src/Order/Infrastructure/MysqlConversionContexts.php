<?php
/**
 * MysqlConversionContexts: the frozen exchange rates, on MySQL
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Infrastructure;

use SEOCart\Order\Domain\ConversionContexts;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report stored data that cannot be a frozen rate, for the developer; they are never HTML.

/**
 * Freezes a conversion context into `conversion_contexts`, once per fingerprint, and reads it back exactly.
 *
 * Owns one fact: the statements of the conversion contexts. Freezing is one insert that, when an
 * equal context already has a row, finds that row by the fingerprint's unique key instead, and
 * makes its id the connection's insert id either way. Reading back restores the scale the rate was
 * quoted at: the column stores twelve places, and a rate quoted at fewer keeps its own scale, so a
 * context read back has the fingerprint it was frozen with.
 *
 * @since 0.1.0
 */
final class MysqlConversionContexts implements ConversionContexts {

	/**
	 * The freeze: inserts the context, or, when its fingerprint has a row, makes that row's id the connection's insert id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FREEZE = 'INSERT INTO {conversion_contexts} ( uuid, fingerprint, base_currency, quote_currency, direction, rate, rate_scale, source, source_version, quoted_at, created_at ) VALUES ( %s, %s, %s, %s, %s, %s, %d, %s, %d, %s, UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID( id )';

	/**
	 * One context, by id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIND = 'SELECT base_currency, quote_currency, direction, rate, rate_scale, source, source_version, quoted_at FROM {conversion_contexts} WHERE id = %d';

	/**
	 * How `quoted_at` is stored: UTC, to the second.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DATETIME = 'Y-m-d H:i:s';

	/**
	 * Sends the statements.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderStatements
	 */
	private OrderStatements $statements;

	/**
	 * Mints the uuid of a new row.
	 *
	 * @since 0.1.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderStatements $statements Sends the statements.
	 * @param IdGenerator     $ids        Mints the uuid of a new row.
	 */
	public function __construct( OrderStatements $statements, IdGenerator $ids ) {
		$this->statements = $statements;
		$this->ids        = $ids;
	}

	/**
	 * Stores a context, or finds the row an equal context was stored in, inside the caller's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param ConversionContext $context The context.
	 * @return int The row's id.
	 */
	public function freeze( ConversionContext $context ): int {
		$this->statements->requireTransaction( __METHOD__ );

		$this->statements->execute(
			self::FREEZE,
			$this->ids->generate(),
			$context->fingerprint(),
			$context->baseCurrency()->code(),
			$context->quoteCurrency()->code(),
			$context->direction(),
			$context->rate()->toString(),
			$context->rateScale(),
			$context->source(),
			$context->sourceVersion(),
			$context->quotedAt()->format( self::DATETIME )
		);

		return $this->statements->lastInsertId();
	}

	/**
	 * Reads a context back at the scale it was quoted at.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id The row's id.
	 * @return ConversionContext|null The context, or null when there is no such row.
	 */
	public function find( int $id ): ?ConversionContext {
		$row = $this->statements->rows( self::FIND, $id )[0] ?? null;

		if ( null === $row ) {
			return null;
		}

		$scale = (int) $row['rate_scale'];

		return new ConversionContext(
			Currency::of( (string) $row['base_currency'] ),
			Currency::of( (string) $row['quote_currency'] ),
			(string) $row['direction'],
			self::quotedRate( (string) $row['rate'], $scale ),
			$scale,
			(string) $row['source'],
			(int) $row['source_version'],
			new \DateTimeImmutable( (string) $row['quoted_at'], new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Returns a stored rate at the scale it was quoted at.
	 *
	 * The column keeps twelve places, and a rate quoted at fewer was padded with zeros when it was
	 * stored; those zeros are dropped here. Any other digit beyond the quoted scale cannot come from
	 * a frozen context, and is refused rather than rounded away.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When a digit beyond the quoted scale is not zero.
	 *
	 * @param string $stored The column's value, for example `1.100000000000`.
	 * @param int    $scale  The quoted scale, for example 2.
	 * @return Decimal The rate, for example 1.10.
	 */
	private static function quotedRate( string $stored, int $scale ): Decimal {
		list( $whole, $fraction ) = array_pad( explode( '.', $stored, 2 ), 2, '' );

		if ( '' !== trim( substr( $fraction, $scale ), '0' ) ) {
			throw new \UnexpectedValueException( sprintf( 'The stored rate %1$s has digits beyond its quoted scale of %2$d.', $stored, $scale ) );
		}

		return Decimal::of( 0 === $scale ? $whole : $whole . '.' . substr( $fraction, 0, $scale ) );
	}
}

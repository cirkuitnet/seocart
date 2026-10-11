<?php
/**
 * CanonicalizeProviderReferences: rewrites the provider references and event ids kept before they were canonical
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Migrations;

use SEOCart\Payment\Application\DeliveredValues;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\DataMigration;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\ModuleStatements;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a cursor the migrator stored, to the developer; it is never HTML.

/**
 * Rewrites every provider reference the ledger and the intents keep, and every event id a receipt keeps, that is neither an identifier nor a shape the store makes to its stand-in, as DeliveredValues keeps one now, so a delivery after the upgrade meets the row an earlier one wrote.
 *
 * Owns one fact: the values kept before references were canonical that a delivery now keeps
 * otherwise. Before, the request's answer kept a provider's reference exactly as given; now one
 * that is not an identifier, such as `cap/1 2` or `~charge/1`, is kept as `~sha256:` and its digest,
 * in the request and by webhook alike. Only such values are rewritten. An identifier is kept as it
 * is now as before. A value in a shape the store makes in place of a provider's, a stand-in or an
 * event's identity (DeliveredValues::madeReference(), and for a receipt madeStandIn()), is the
 * store's own and is left; a provider's value kept before in exactly such a shape cannot be told
 * from it and is left too. Any other value that begins with the mark was a provider's, kept
 * verbatim, and is rewritten. A value shaped like an earlier synthetic identity, `event:{mode}:…` or
 * `sha256:…`, is an identifier and is left as it is: those forms were written only by development
 * builds of this version, whose sites are made again, never by a release.
 *
 * The ledger, the intents and then the receipts are read a page at a time by id, the cursor naming
 * the table and the last id read; a row is rewritten only from the value read, so a batch run again
 * changes nothing. A rewrite that would give a row the identity another row holds fails the
 * migration: the batch rolls back, both rows stay as they were, and the store stays held, for a
 * person to look, as two rows would otherwise share one identity.
 *
 * The store waits for it: a delivery before it ran would miss the row an earlier one wrote.
 *
 * @since 0.2.0
 */
final class CanonicalizeProviderReferences implements DataMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261010_0003_payment_provider_references';

	/**
	 * How many rows one batch reads.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const BATCH = 500;

	/**
	 * A page of the ledger's provider objects, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LEDGER_PAGE = 'SELECT id, provider_object_id AS kept FROM {payment_transactions} WHERE id > %d AND provider_object_id IS NOT NULL ORDER BY id LIMIT %d';

	/**
	 * Rewrites a ledger row's provider object to its stand-in, only from the value read: the migration's one change to the ledger, which no amount, outcome or state is part of.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REWRITE_LEDGER = 'UPDATE {payment_transactions} SET provider_object_id = %s WHERE id = %d AND provider_object_id = %s';

	/**
	 * A page of the intents' provider references, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INTENT_PAGE = 'SELECT id, provider_intent_id AS kept FROM {payment_intents} WHERE id > %d AND provider_intent_id IS NOT NULL ORDER BY id LIMIT %d';

	/**
	 * Rewrites an intent's provider reference to its canonical form, only from the value read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REWRITE_INTENT = 'UPDATE {payment_intents} SET provider_intent_id = %s WHERE id = %d AND provider_intent_id = %s';

	/**
	 * A page of the receipts' event ids, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECEIPT_PAGE = 'SELECT id, event_id AS kept FROM {webhook_receipts} WHERE id > %d ORDER BY id LIMIT %d';

	/**
	 * Rewrites a receipt's event id to its canonical form, only from the value read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REWRITE_RECEIPT = 'UPDATE {webhook_receipts} SET event_id = %s WHERE id = %d AND event_id = %s';

	/**
	 * The module whose tables the statements name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MODULE = 'Payment';

	/**
	 * The tables, in the order they are read: each its page, its rewrite and the form it keeps.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	private const TABLES = array(
		'ledger'   => array( self::LEDGER_PAGE, self::REWRITE_LEDGER, 'reference' ),
		'intents'  => array( self::INTENT_PAGE, self::REWRITE_INTENT, 'reference' ),
		'receipts' => array( self::RECEIPT_PAGE, self::REWRITE_RECEIPT, 'eventId' ),
	);

	/**
	 * Returns the id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Tells whether the store may trade while this runs: no, a delivery would miss the row an earlier one wrote.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Rewrites one page of one table, and names where the next batch goes on.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When a rewrite would give a row the identity another row holds: the batch rolls back.
	 *
	 * @param string|null $cursor Where to continue: null to begin, or `{table}:{last id read}`.
	 * @param Database    $db     The connection, in the batch's transaction.
	 * @return string|null The next batch's cursor, or null when every table was read.
	 */
	public function runBatch( ?string $cursor, Database $db ): ?string {
		list( $table, $after )         = null === $cursor ? array( (string) array_key_first( self::TABLES ), 0 ) : self::position( $cursor );
		list( $page, $rewrite, $form ) = self::TABLES[ $table ];

		$statements = new ModuleStatements( $db, self::MODULE, PaymentTables::moduleNames() );
		$rows       = $statements->rows( $page, $after, self::BATCH );

		foreach ( $rows as $row ) {
			$kept      = (string) $row['kept'];
			$canonical = 'eventId' === $form ? self::eventId( $kept ) : self::reference( $kept );

			if ( $canonical === $kept ) {
				continue;
			}

			try {
				$statements->execute( $rewrite, $canonical, (int) $row['id'], $kept );
			} catch ( DuplicateKey $met ) {
				// Two rows would share one identity: the batch rolls back and the store stays held for a person.
				throw new \RuntimeException( sprintf( 'The %1$s row %2$d would take the identity %3$s that another row holds: two rows would share an identity, so a person must look before this migration can finish.', $table, (int) $row['id'], $canonical ), 0, $met );
			}
		}

		if ( count( $rows ) === self::BATCH ) {
			return $table . ':' . (int) $rows[ count( $rows ) - 1 ]['id'];
		}

		$tables = array_keys( self::TABLES );
		$next   = $tables[ (int) array_search( $table, $tables, true ) + 1 ] ?? null;

		return null === $next ? null : $next . ':0';
	}

	/**
	 * Returns the form a delivery keeps a provider reference the ledger or an intent kept in: itself when it is an identifier or a reference the store made, and its stand-in otherwise, `~charge/1` among them.
	 *
	 * @since 0.2.0
	 *
	 * @param string $kept The reference as kept.
	 * @return string The form a delivery keeps it in.
	 */
	public static function reference( string $kept ): string {
		return DeliveredValues::madeReference( $kept ) ?? (string) DeliveredValues::reference( $kept );
	}

	/**
	 * Returns the form a delivery keeps an event id a receipt kept in: itself when it is an identifier or a stand-in the store made, and its stand-in otherwise, `~evt/1` among them.
	 *
	 * @since 0.2.0
	 *
	 * @param string $kept The event id as kept.
	 * @return string The form a delivery keeps it in.
	 */
	public static function eventId( string $kept ): string {
		return DeliveredValues::madeStandIn( $kept ) ?? DeliveredValues::eventId( $kept );
	}

	/**
	 * Reads a cursor.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the cursor names no table this migration reads.
	 *
	 * @param string $cursor `{table}:{last id read}`.
	 * @return array{0: string, 1: int} The table and the last id read.
	 */
	private static function position( string $cursor ): array {
		list( $table, $after ) = array_pad( explode( ':', $cursor, 2 ), 2, '0' );

		if ( ! isset( self::TABLES[ $table ] ) ) {
			throw new \UnexpectedValueException( sprintf( 'The cursor %s names no table this migration reads.', $cursor ) );
		}

		return array( $table, (int) $after );
	}
}

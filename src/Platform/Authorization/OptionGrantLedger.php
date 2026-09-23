<?php
/**
 * OptionGrantLedger: the installer's grant record, kept in a settings document of the site
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * The production GrantLedger: one internal settings document per site, `seocart_capability_grants`.
 *
 * This class owns one fact: how the record of settled role and capability pairs is stored. The
 * document has one setting per role of the capability declaration, holding the capabilities
 * settled on that role, in alphabetical order, separated by single spaces. A role the document
 * does not hold has never been settled, so the installer can tell a shipped role a merchant
 * deleted from one it has never created. The settings are internal: no operation exposes them.
 *
 * The document lives in the options table of the current site, so on a network each site has its
 * own record, as the installer requires. record() merges: it reads the document, adds the new
 * pairs and writes it back by compare-and-swap. When another installer run on the same site wrote
 * the document in between, the write changes nothing and is retried on the new version, a few
 * times, so neither run's pairs are lost; the union of two records does not depend on their
 * order. If the last attempt loses too, the record is read once more: a concurrent installer
 * computes its pairs from the same declaration, so the winner has usually recorded this run's
 * pairs already, and then there is nothing left to do. Otherwise the conflict reaches the caller.
 *
 * record() refuses to run inside a database transaction: at REPEATABLE READ a retry would read
 * the transaction's own snapshot again and could never succeed. The installer runs outside one.
 *
 * @since 0.1.0
 */
final class OptionGrantLedger implements GrantLedger {

	/**
	 * The settings group, and so the document's option name after the plugin prefix.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP = 'capability_grants';

	/**
	 * What the document holds, as the data registry lists it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PURPOSE = 'The role and capability pairs the capability installer has settled on this site, so that it never grants again a capability a merchant removed.';

	/**
	 * How many times record() writes before it gives up to concurrent writers.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const ATTEMPTS = 3;

	/**
	 * The shape of a stored list: capability names separated by single spaces, or nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LIST_PATTERN = '/^(?:[a-z0-9_]+(?: [a-z0-9_]+)*)?\z/';

	/**
	 * The kind of work record() names when it refuses to run inside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FORBIDDEN_KIND = 'settings_retry';

	/**
	 * The store of the current site's settings.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * Tells whether a transaction is open.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Creates the ledger. Reads nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param SettingsStore      $store        The settings store.
	 * @param TransactionManager $transactions The transaction manager of the same connection.
	 */
	public function __construct( SettingsStore $store, TransactionManager $transactions ) {
		$this->store        = $store;
		$this->transactions = $transactions;
	}

	/**
	 * Declares the document: one internal setting per role of the capability declaration.
	 *
	 * The labels are plain text: no screen shows an internal setting, so they are not translated.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Setting> The settings.
	 */
	public static function settings(): array {
		$settings = array();

		foreach ( ( new CapabilityDeclaration() )->roles() as $role ) {
			$settings[] = Setting::inDocument(
				group: self::GROUP,
				field: new FieldSpec(
					name: $role,
					type: FieldType::String,
					description: 'Capabilities the installer has settled on the ' . $role . ' role, in alphabetical order, separated by spaces.',
					label: static fn(): string => 'Settled capabilities of ' . $role,
					example: 'read seocart_view_orders'
				),
				exposed: false,
				check: static function ( int|string $capabilities ): string {
					$capabilities = (string) $capabilities;

					if ( 1 !== preg_match( self::LIST_PATTERN, $capabilities ) ) {
						throw new \InvalidArgumentException( 'A settled list is capability names separated by single spaces.' );
					}

					return $capabilities;
				}
			);
		}

		return $settings;
	}

	/**
	 * Returns every pair recorded for the current site.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SettingsError::StoredValueInvalid when the stored record is unreadable.
	 *
	 * @return array<string, list<string>> Capabilities, keyed by role name.
	 */
	public function granted(): array {
		return self::decode( $this->store->document( self::GROUP )->values() );
	}

	/**
	 * Adds pairs to the record of the current site. Pairs already recorded stay recorded once.
	 *
	 * A role the capability declaration does not declare is refused by the store with an
	 * \InvalidArgumentException, an unreadable stored record with SettingsError::StoredValueInvalid,
	 * and a call inside a database transaction with the database layer's
	 * ForbiddenInsideTransaction, of the kind FORBIDDEN_KIND.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SettingsError::VersionConflict when concurrent writers won every
	 *                        attempt and the record read afterwards still lacks a pair.
	 *
	 * @param array<string, list<string>> $grants Capabilities, keyed by role name.
	 */
	public function record( array $grants ): void {
		if ( 0 !== $this->transactions->depth() ) {
			ForbiddenInsideTransaction::raise(
				ForbiddenInsideTransaction::CODE,
				array(
					'kind'   => self::FORBIDDEN_KIND,
					'detail' => self::GROUP,
				)
			);
		}

		$attempt = 0;

		while ( true ) {
			++$attempt;

			$document = $this->store->document( self::GROUP );
			$recorded = self::decode( $document->values() );

			if ( self::holds( $recorded, $grants ) ) {
				return;
			}

			try {
				$this->store->replaceDocument( self::GROUP, $document->version(), self::encode( self::merge( $recorded, $grants ) ) );

				return;
			} catch ( CodedException $failure ) {
				if ( SettingsError::VersionConflict !== $failure->errorCode() ) {
					throw $failure;
				}

				if ( $attempt >= self::ATTEMPTS ) {
					// The winner of the last race was most likely another installer run, which records the same pairs. Read what is committed, not what a cache holds.
					if ( self::holds( self::decode( $this->store->documentAsStored( self::GROUP )->values() ), $grants ) ) {
						return;
					}

					throw $failure;
				}
			}
		}
	}

	/**
	 * Tells whether a record already holds every pair.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $recorded The record, keyed by role name.
	 * @param array<string, list<string>> $grants   The pairs, keyed by role name.
	 * @return bool True when adding the pairs would change nothing.
	 */
	private static function holds( array $recorded, array $grants ): bool {
		return self::encode( self::merge( $recorded, $grants ) ) === self::encode( $recorded );
	}

	/**
	 * Adds pairs to a record.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $recorded The record, keyed by role name.
	 * @param array<string, list<string>> $grants   The pairs, keyed by role name.
	 * @return array<string, list<string>> The record with the pairs; duplicates are removed by encode().
	 */
	private static function merge( array $recorded, array $grants ): array {
		foreach ( $grants as $role => $capabilities ) {
			$recorded[ $role ] = array_merge( $recorded[ $role ] ?? array(), $capabilities );
		}

		return $recorded;
	}

	/**
	 * Reads the stored lists.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string> $values The document's values, keyed by role name.
	 * @return array<string, list<string>> Capabilities, keyed by role name.
	 */
	private static function decode( array $values ): array {
		$granted = array();

		foreach ( $values as $role => $capabilities ) {
			$granted[ $role ] = '' === $capabilities ? array() : explode( ' ', (string) $capabilities );
		}

		return $granted;
	}

	/**
	 * Writes lists the way the document stores them: sorted, each capability once.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $granted Capabilities, keyed by role name.
	 * @return array<string, string> The lists, keyed by role name.
	 */
	private static function encode( array $granted ): array {
		$values = array();

		foreach ( $granted as $role => $capabilities ) {
			$capabilities = array_values( array_unique( $capabilities ) );

			sort( $capabilities );

			$values[ (string) $role ] = implode( ' ', $capabilities );
		}

		ksort( $values );

		return $values;
	}
}

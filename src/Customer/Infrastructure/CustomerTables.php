<?php
/**
 * CustomerTables: the declaration of the customer records
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Customer\Infrastructure;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `customers`: one row for each buyer the store keeps a record of.
 *
 * Owns one fact: the shape of the customer records. The migration that creates the table and the
 * data registry that lists it call the same factory.
 *
 * A record is the store's name for one buyer, which an order's `customer_id` points at. It copies
 * nothing from the WordPress user it is linked to: the user's name and address stay WordPress's,
 * and an order keeps its own snapshot of them. The link is a pair, the user's id and the time the
 * user registered, so a link to a user that was deleted, and whose id came back for someone else,
 * matches nobody. A record is created at its user's first order placed while logged in; it stays
 * when the user is deleted, unlinked, so its orders keep naming it.
 *
 * @since 0.2.0
 */
final class CustomerTables {

	/**
	 * The unprefixed name of the customer records.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CUSTOMERS = 'customers';

	/**
	 * Returns the declarations.
	 *
	 * @since 0.2.0
	 *
	 * @return list<TableDefinition> The customer records.
	 */
	public static function all(): array {
		return array( self::customers() );
	}

	/**
	 * Returns the unprefixed names of the tables.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The names, in the order of all().
	 */
	public static function names(): array {
		return array( self::CUSTOMERS );
	}

	/**
	 * Declares `customers`.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function customers(): TableDefinition {
		return new TableDefinition(
			self::CUSTOMERS,
			'Customer',
			'Records each buyer the store keeps a record of, which orders point at: its public identifier and its link to the WordPress user it was created for, the user\'s id and the time the user registered. Created at the user\'s first order placed while logged in; kept, unlinked, when the user is deleted or erased.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key, internal only: the customer an order\'s customer_id names; never a WordPress user\'s id.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', Classification::Public, 'The record\'s public identifier, for staff surfaces; no storefront answer names a customer.', collation: 'ascii_bin' ),
				new ColumnSpec( 'user_id', 'bigint unsigned', Classification::Pii, 'The WordPress user the record is linked to; NULL once unlinked. A user is the record\'s customer only while their registration time equals user_registered_at too.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'user_registered_at', 'datetime', Classification::Pii, 'When the linked user registered, UTC, copied from WordPress when the link was made; NULL once unlinked.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the record was created, UTC, from the database clock.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the record last changed, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'One record per public identifier.' ),
				IndexSpec::unique( 'user', array( 'user_id' ), 'One record per linked user: two first orders of one user at once end with one record, the second waiting on the key; many unlinked records hold NULL.' ),
			),
			array(),
			'entity_lifetime',
			array(
				'users -> customers'  => 'Unlink: a deleted user\'s record stays, and its link matches nobody whatever ran; doctor unlinks it, as erasure does. No hook follows the user.',
				'customers -> orders' => 'Retain: an order keeps naming its customer record for as long as the order is kept, linked or not.',
			)
		);
	}
}

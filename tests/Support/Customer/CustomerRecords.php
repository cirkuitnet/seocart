<?php
/**
 * CustomerRecords: the customer records of real WordPress users, for the tests that need them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Customer;

use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Customer\Infrastructure\Migrations\CreateCustomers;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;

/**
 * Creates the customer table, and plants records as the store makes them or as no code would.
 *
 * Owns one fact for the tests: how a test gets a customer record. A user's record is made the way
 * a first order makes it, linked at the user's own registration time; a planted record holds
 * whatever link the test names, such as user 0 or another registration time.
 *
 * @since 0.2.0
 */
trait CustomerRecords {

	/**
	 * Creates the customer table on the test's connection; the test's tear_down() drops it.
	 *
	 * @since 0.2.0
	 */
	protected function createCustomerTable(): void {
		( new CreateCustomers() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Plants a customer record with any link, directly.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null    $userId       The linked user's id, or null for an unlinked record.
	 * @param string|null $registeredAt The registration time the link holds, or null.
	 * @return int The record's id.
	 */
	protected function plantCustomer( ?int $userId, ?string $registeredAt ): int {
		$values = array_filter( array( $userId, $registeredAt ), static fn( int|string|null $value ): bool => null !== $value );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Each placeholder is the test's own literal: NULL, or %d or %s for a value passed below.
		$this->db->execute( 'INSERT INTO %i ( uuid, user_id, user_registered_at, created_at, updated_at ) VALUES ( %s, ' . ( null === $userId ? 'NULL' : '%d' ) . ', ' . ( null === $registeredAt ? 'NULL' : '%s' ) . ', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )', $this->db->table( CustomerTables::CUSTOMERS ), wp_generate_uuid4(), ...array_values( $values ) );

		return $this->db->lastInsertId();
	}

	/**
	 * Returns when a user registered, as WordPress holds it.
	 *
	 * @since 0.2.0
	 *
	 * @param int $userId The user.
	 * @return string The time, `Y-m-d H:i:s`, UTC.
	 */
	protected static function registeredAt( int $userId ): string {
		$user = get_userdata( $userId );

		self::assertInstanceOf( \WP_User::class, $user );

		return (string) $user->user_registered;
	}

	/**
	 * Returns every customer record, by id: its user's id and registration time, as committed or as this connection sees them.
	 *
	 * @since 0.2.0
	 *
	 * @param Database|null $db Optional. The connection to read on. Default the test's.
	 * @return array<int, array{user_id: int|null, user_registered_at: string|null}> The records.
	 */
	protected function customerRows( ?Database $db = null ): array {
		$rows = array();

		foreach ( ( $db ?? $this->db )->fetchAll( 'SELECT id, user_id, user_registered_at FROM %i ORDER BY id', $this->db->table( CustomerTables::CUSTOMERS ) ) as $row ) {
			$rows[ (int) $row['id'] ] = array(
				'user_id'            => null === $row['user_id'] ? null : (int) $row['user_id'],
				'user_registered_at' => null === $row['user_registered_at'] ? null : (string) $row['user_registered_at'],
			);
		}

		return $rows;
	}
}

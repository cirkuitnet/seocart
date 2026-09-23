<?php
/**
 * DuplicateKey: a statement that would have broken a unique key
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Exception;

use SEOCart\Platform\Database\DatabaseError;

defined( 'ABSPATH' ) || exit;

/**
 * The database refused a row because a unique key already holds its value (MySQL error 1062).
 *
 * Owns one fact: that a uniqueness invariant, not a fault, stopped the write. An idempotency
 * claim or a SKU check catches this class to learn that another writer got there first.
 *
 * @since 0.1.0
 */
final class DuplicateKey extends QueryFailed {

	/**
	 * The catalog case this class raises.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseError
	 */
	public const CODE = DatabaseError::DuplicateKey;
}

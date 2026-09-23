<?php
/**
 * IdGenerator: the port through which SEOCart mints public identifiers
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Mints the public identifier stored in a row's `uuid` column.
 *
 * Every identifier is a version 7 UUID in its canonical textual form: 36 characters,
 * lowercase hexadecimal, grouped 8-4-4-4-12, with the RFC 4122 variant bits. Version 7 is
 * time-ordered, so identifiers minted later sort after identifiers minted earlier.
 *
 * The Support module owns this port and supplies the production adapter. The
 * interface exists ahead of that module only because the deterministic test double in
 * tests/Support/Doubles/ needs a contract to implement.
 *
 * @since 0.1.0
 */
interface IdGenerator {

	/**
	 * Mints a new identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string A lowercase version 7 UUID, for example `018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b`.
	 */
	public function generate(): string;
}

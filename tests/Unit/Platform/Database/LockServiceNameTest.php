<?php
/**
 * Tests the names locks have on the database server
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\LockService;

/**
 * GET_LOCK names are global to the server and limited to 64 characters.
 *
 * So a name carries the database and the table prefix, to keep sites and installations
 * apart, and a name that would be too long is cut and ends with a hash of the whole.
 *
 * @since 0.1.0
 */
final class LockServiceNameTest extends TestCase {

	/**
	 * Tests the shape of a short name.
	 *
	 * @since 0.1.0
	 */
	public function test_a_short_name_is_namespaced_by_database_and_prefix(): void {
		$this->assertSame( 'seocart:shop:wp_3_:schema', LockService::serverLockName( 'shop', 'wp_3_', 'schema' ) );
	}

	/**
	 * Tests that a long name is cut to 64 characters and stays distinct.
	 *
	 * @since 0.1.0
	 */
	public function test_a_long_name_is_cut_to_64_characters_with_a_hash(): void {
		$database = str_repeat( 'd', 60 );
		$first    = LockService::serverLockName( $database, 'wp_', 'schema' );
		$second   = LockService::serverLockName( $database, 'wp_', 'jobs_tick' );

		$this->assertSame( 64, strlen( $first ) );
		$this->assertMatchesRegularExpression( '/^seocart:d+#[0-9a-f]{16}$/', $first );
		$this->assertNotSame( $first, $second, 'Two long names that differ only after the cut must still differ.' );
		$this->assertSame( $first, LockService::serverLockName( $database, 'wp_', 'schema' ) );
	}
}

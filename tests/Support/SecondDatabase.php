<?php
/**
 * SecondDatabase: a second Database over a second wpdb connection, for tests where two runners run our own code
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Database\Database;

/**
 * Another runner of the plugin's own code: a Database over its own wpdb and its own MySQL thread.
 *
 * Owns one fact: how a test plays a second process running the same plugin, such as a second
 * drainer. SecondConnection plays the other side with raw SQL; this one runs our classes over
 * a real second connection, built from the same DB_USER, DB_PASSWORD, DB_NAME and DB_HOST as
 * the global wpdb and set to the same site, so it has the same table prefix. Its wrapper has strict guards and reports
 * to the callable the test gives. Close it in tear_down().
 *
 * @since 0.1.0
 */
final class SecondDatabase {

	/**
	 * The second connection.
	 *
	 * @since 0.1.0
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * The wrapper over it.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Opens the connection.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the connection cannot be opened.
	 *
	 * @param callable $report Receives what the wrapper reports: a code (string) and its context (array).
	 */
	public function __construct( callable $report ) {
		global $wpdb;

		$second = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		if ( ! $second->ready ) {
			throw new \RuntimeException( 'The second wpdb connection could not be opened.' );
		}

		// On multisite set_prefix() leaves the site prefix unset until a blog id is known.
		$second->set_prefix( $wpdb->base_prefix );
		$second->set_blog_id( get_current_blog_id() );

		$this->wpdb = $second;
		$this->db   = new Database( $second, true, $report );
	}

	/**
	 * Returns the wrapper.
	 *
	 * @since 0.1.0
	 *
	 * @return Database The Database over the second connection.
	 */
	public function db(): Database {
		return $this->db;
	}

	/**
	 * Closes the connection; the server rolls back what it left open and frees its locks.
	 *
	 * @since 0.1.0
	 */
	public function close(): void {
		$this->wpdb->close();
	}
}

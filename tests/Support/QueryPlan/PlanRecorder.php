<?php
/**
 * PlanRecorder: a wpdb that records every plugin SELECT sent through it, once per query
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\QueryPlan;

/**
 * A wpdb decorator for the query-plan run: it sends every statement as wpdb does, and keeps the plugin SELECTs.
 *
 * Owns one fact: which plugin SELECTs a run sent. It is a connection of its own, to the same
 * database and site as the global wpdb, so a Database built over it runs the plugin's real
 * statements, and nothing else in the process is wrapped. Each plugin SELECT (Statement) is
 * kept the first time its key is sent, with the values it was sent with: its query and the
 * length of each IN list. A later statement of the same key adds nothing, so no plan is
 * explained twice, and a list of another length is explained as the plan it is. It explains nothing itself:
 * that is QueryPlan's, afterwards, on another connection, so the statements under test run
 * exactly as they would without it.
 *
 * Close it when the run ends.
 *
 * @since 0.1.0
 */
final class PlanRecorder extends \wpdb {

	/**
	 * The plugin SELECTs sent, one per key: per query and IN-list length.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, Statement>
	 */
	private array $recorded = array();

	/**
	 * Opens a recording connection to the global wpdb's database, on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the connection cannot be opened.
	 *
	 * @return self The recorder.
	 */
	public static function open(): self {
		global $wpdb;

		$recorder = new self( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		if ( ! $recorder->ready ) {
			throw new \RuntimeException( 'The recording connection could not be opened.' );
		}

		// On multisite set_prefix() leaves the site prefix unset until a blog id is known.
		$recorder->set_prefix( $wpdb->base_prefix );
		$recorder->set_blog_id( get_current_blog_id() );

		return $recorder;
	}

	/**
	 * Keeps the statement when it is the first plugin SELECT of its key, then sends it as wpdb does.
	 *
	 * @since 0.1.0
	 *
	 * @param string $query The statement.
	 * @return int|bool What wpdb::query() returns.
	 */
	public function query( $query ) {
		// The prefix is empty while the constructor connects; nothing sent then is the plugin's.
		if ( '' !== (string) $this->prefix ) {
			$statement = new Statement( $query, (string) $this->prefix );

			if ( $statement->isPluginSelect() && ! isset( $this->recorded[ $statement->key() ] ) ) {
				$this->recorded[ $statement->key() ] = $statement;
			}
		}

		return parent::query( $query );
	}

	/**
	 * Returns the plugin SELECTs sent so far, one per key, in the order they were first sent.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Statement> The statements.
	 */
	public function statements(): array {
		return array_values( $this->recorded );
	}
}

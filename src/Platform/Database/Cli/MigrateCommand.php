<?php
/**
 * MigrateCommand: `wp seocart migrate`, which applies pending migrations from the command line
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Cli;

use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Database\Exception\MigrationFailed;
use SEOCart\Platform\Database\MigrationReport;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the migrator for the current site, or for a network in bounded batches, and turns the outcome into an exit code.
 *
 * Owns one fact: the command's contract with an operator. Exit 0 when migrations were applied
 * or nothing was pending, 1 when a migration failed (with its id, code and diff printed) or any
 * other database error stopped the run (with its code and message), 2 when another runner
 * holds the schema lock and nothing was changed.
 *
 * It is a maintenance command, not an application operation: it has no REST or Ability
 * twin, so it resolves to no operation definition by design.
 *
 * Output goes through a callable and run() returns the exit code, so tests call run() without
 * WP-CLI. Only __invoke(), which WP-CLI calls, touches the WP_CLI class. The kernel registers
 * the command.
 *
 * @since 0.1.0
 */
final class MigrateCommand {

	/**
	 * Exit code: applied, or nothing pending.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code: a migration failed, or the command was used wrongly.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_FAILED = 1;

	/**
	 * Exit code: another runner holds the schema lock.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_BLOCKED = 2;

	/**
	 * How many sites a network run migrates by default.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const DEFAULT_BATCH = 50;

	/**
	 * The migrator. Its table names follow switch_to_blog(), so one instance serves every site.
	 *
	 * @since 0.1.0
	 *
	 * @var Migrator
	 */
	private Migrator $migrator;

	/**
	 * Prints one line for the operator.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string): void
	 */
	private $output;

	/**
	 * Creates the command.
	 *
	 * @since 0.1.0
	 *
	 * @param Migrator               $migrator The migrator.
	 * @param callable(string): void $output   Prints one line, for example WP_CLI::log().
	 */
	public function __construct( Migrator $migrator, callable $output ) {
		$this->migrator = $migrator;
		$this->output   = $output;
	}

	/**
	 * Applies the pending schema and data migrations.
	 *
	 * ## OPTIONS
	 *
	 * [--wait=<seconds>]
	 * : How long to wait for another runner's schema lock before giving up.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--network]
	 * : Migrate the sites of the network, in batches, instead of the current site.
	 *
	 * [--from-site=<id>]
	 * : With --network, the lowest site id to migrate.
	 *
	 * [--batch=<number>]
	 * : With --network, how many sites to migrate in this run.
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXIT STATUS
	 *
	 * 0 when migrations were applied or nothing was pending, 1 when a migration failed, 2 when
	 * another runner holds the schema lock.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart migrate
	 *     wp seocart migrate --network --batch=100 --from-site=201
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args      Positional arguments. None are accepted.
	 * @param array<string, string|bool> $assocArgs The options.
	 */
	public function __invoke( array $args, array $assocArgs ): void {
		\WP_CLI::halt( $this->run( $assocArgs ) );
	}

	/**
	 * Runs the command and returns its exit code.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string|bool> $assocArgs The options: wait, network, from-site, batch.
	 * @return int One of the EXIT_* constants.
	 */
	public function run( array $assocArgs ): int {
		$options = new MigrationRunOptions( self::intOption( $assocArgs, 'wait', MigrationRunOptions::DEFAULT_WAIT_SECONDS ) );

		if ( ! isset( $assocArgs['network'] ) ) {
			return $this->migrateSite( $options );
		}

		if ( ! is_multisite() ) {
			$this->say( '--network needs a multisite installation. Run the command without it to migrate this site.' );

			return self::EXIT_FAILED;
		}

		return $this->migrateNetwork( $options, self::intOption( $assocArgs, 'from-site', 1 ), max( 1, self::intOption( $assocArgs, 'batch', self::DEFAULT_BATCH ) ) );
	}

	/**
	 * Migrates the current site.
	 *
	 * @since 0.1.0
	 *
	 * @param MigrationRunOptions $options The run's bounds.
	 * @return int One of the EXIT_* constants.
	 */
	private function migrateSite( MigrationRunOptions $options ): int {
		try {
			$report = $this->migrator->migrate( $options );
		} catch ( MigrationFailed $failed ) {
			$this->say( sprintf( 'Migration %s failed: %s', $failed->migrationId(), $failed->recordedCode() ) );

			foreach ( explode( "\n", $failed->detail() ) as $line ) {
				$this->say( '  ' . $line );
			}

			$this->say( 'Nothing after it ran. Fix the cause and run the command again; it resumes at this migration.' );

			return self::EXIT_FAILED;
		} catch ( DatabaseException $failure ) {
			$this->say( (string) $failure->errorCode()->value . ': ' . ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() ) );

			return self::EXIT_FAILED;
		}

		foreach ( $report->appliedElsewhere() as $id ) {
			$this->say( sprintf( '%s was applied by another runner while this one waited.', $id ) );
		}

		foreach ( $report->applied() as $applied ) {
			$this->say( sprintf( 'Applied %s in %d ms.', $applied['id'], $applied['duration_ms'] ) );
		}

		switch ( $report->outcome() ) {
			case MigrationReport::BLOCKED:
				$this->say( 'Another runner holds the schema lock, so nothing was changed. Run the command again later.' );

				return self::EXIT_BLOCKED;

			case MigrationReport::INCOMPLETE:
				$this->say( sprintf( 'Stopped inside %s when the time budget ran out; the next run resumes it.', (string) $report->incompleteMigration() ) );

				return self::EXIT_OK;

			case MigrationReport::UP_TO_DATE:
				$this->say( 'Nothing to migrate: the schema is up to date.' );

				return self::EXIT_OK;

			default:
				$this->say( 'The schema is up to date.' );

				return self::EXIT_OK;
		}
	}

	/**
	 * Migrates one batch of the network's sites, from the given site id up.
	 *
	 * @since 0.1.0
	 *
	 * @param MigrationRunOptions $options  The run's bounds.
	 * @param int                 $fromSite The lowest site id to migrate.
	 * @param int                 $batch    How many sites to migrate.
	 * @return int One of the EXIT_* constants: the first site that fails or is blocked ends the run.
	 */
	private function migrateNetwork( MigrationRunOptions $options, int $fromSite, int $batch ): int {
		$ids = get_sites(
			array(
				'fields'     => 'ids',
				'orderby'    => 'id',
				'order'      => 'ASC',
				'number'     => 0,
				'network_id' => get_current_network_id(),
			)
		);

		$ids  = array_slice( array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( int $id ): bool => $id >= $fromSite ) ), 0, $batch );
		$last = null;

		foreach ( $ids as $id ) {
			$this->say( sprintf( 'Site %d:', $id ) );

			switch_to_blog( $id );

			try {
				$code = $this->migrateSite( $options );
			} finally {
				restore_current_blog();
			}

			if ( self::EXIT_OK !== $code ) {
				$this->say( sprintf( 'Stopped at site %d. Continue with --from-site=%d.', $id, $id ) );

				return $code;
			}

			$last = $id;
		}

		$this->say( null === $last ? sprintf( 'No site with an id of %d or more.', $fromSite ) : sprintf( 'Last site migrated: %d. Continue with --from-site=%d.', $last, $last + 1 ) );

		return self::EXIT_OK;
	}

	/**
	 * Prints one line.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The line.
	 */
	private function say( string $line ): void {
		( $this->output )( $line );
	}

	/**
	 * Reads a whole-number option.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string|bool> $assocArgs The options.
	 * @param string                     $name      The option name.
	 * @param int                        $fallback  The value when the option is absent or not a whole number.
	 * @return int The value, never negative.
	 */
	private static function intOption( array $assocArgs, string $name, int $fallback ): int {
		$value = $assocArgs[ $name ] ?? null;

		return is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ? (int) $value : $fallback;
	}
}

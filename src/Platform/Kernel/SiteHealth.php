<?php
/**
 * SiteHealth: the tests SEOCart adds to WordPress's Site Health screen
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobsReport;
use SEOCart\Platform\Secrets\SecretsStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Adds SEOCart's three direct tests to Site Health: the database schema, the stored secrets and the background jobs.
 *
 * Owns one fact: which Site Health tests the plugin has, and how the schema and the jobs are
 * judged there. Each test says what its module already knows, in the module's words:
 *
 * - the schema test is the schema gate's state, described as the degraded-mode notice describes
 *   it: critical whenever the store refuses changes;
 * - the secrets test is SecretsStatus::siteHealthTest(), the verdict the secrets status command
 *   gives too;
 * - the jobs test reads the jobs report `wp seocart jobs status` prints: critical when the queue
 *   library in control is missing or older than the plugin supports; recommended when no runner
 *   has started one of the plugin's jobs for an hour, when jobs failed for good, and when another
 *   plugin keeps the library's actions where the report cannot count them.
 *
 * Every test id starts with `seocart_`, so the tests are told apart from other plugins'. Nothing
 * is built until Site Health runs the tests: the filter adds callbacks, and each resolves what it
 * reads when it runs.
 *
 * @since 0.1.0
 */
final class SiteHealth {

	/**
	 * The id of the schema test.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SCHEMA_TEST = 'seocart_schema';

	/**
	 * The id of the jobs test.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const JOBS_TEST = 'seocart_jobs';

	/**
	 * A good result.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const GOOD = 'good';

	/**
	 * A result the site should act on.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RECOMMENDED = 'recommended';

	/**
	 * A result the site must act on.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CRITICAL = 'critical';

	/**
	 * The schema gate.
	 *
	 * @since 0.1.0
	 *
	 * @var SchemaGate
	 */
	private SchemaGate $gate;

	/**
	 * Returns the notices, whose words describe the gate.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): Notices
	 */
	private \Closure $notices;

	/**
	 * Returns the secrets status.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): SecretsStatus
	 */
	private \Closure $secrets;

	/**
	 * Returns the job queue, which reports on the jobs.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): JobQueue
	 */
	private \Closure $jobs;

	/**
	 * Creates the tests. Builds nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaGate $gate    The schema gate.
	 * @param callable   $notices Returns the notices.
	 * @param callable   $secrets Returns the secrets status.
	 * @param callable   $jobs    Returns the job queue.
	 *
	 * @phpstan-param callable(): Notices       $notices
	 * @phpstan-param callable(): SecretsStatus $secrets
	 * @phpstan-param callable(): JobQueue      $jobs
	 */
	public function __construct( SchemaGate $gate, callable $notices, callable $secrets, callable $jobs ) {
		$this->gate    = $gate;
		$this->notices = \Closure::fromCallable( $notices );
		$this->secrets = \Closure::fromCallable( $secrets );
		$this->jobs    = \Closure::fromCallable( $jobs );
	}

	/**
	 * Adds the direct tests. Filters `site_status_tests`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $tests The tests, as WordPress lists them: `direct` and `async`, each keyed by id.
	 * @return mixed The tests, SEOCart's among the direct ones; anything else, unchanged.
	 */
	public function addTests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$direct = isset( $tests['direct'] ) && is_array( $tests['direct'] ) ? $tests['direct'] : array();

		$direct[ self::SCHEMA_TEST ]   = array(
			'label' => __( 'SEOCart database schema', 'seocart' ),
			'test'  => array( $this, 'schemaTest' ),
		);
		$direct[ SecretsStatus::TEST ] = array(
			'label' => __( 'SEOCart stored secrets', 'seocart' ),
			'test'  => array( $this, 'secretsTest' ),
		);
		$direct[ self::JOBS_TEST ]     = array(
			'label' => __( 'SEOCart background jobs', 'seocart' ),
			'test'  => array( $this, 'jobsTest' ),
		);

		$tests['direct'] = $direct;

		return $tests;
	}

	/**
	 * Runs the schema test.
	 *
	 * @since 0.1.0
	 *
	 * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string} The result.
	 */
	public function schemaTest(): array {
		$blocked = ( $this->notices )()->describeGate();

		if ( null === $blocked ) {
			return self::result(
				self::SCHEMA_TEST,
				self::GOOD,
				__( 'SEOCart\'s database is up to date', 'seocart' ),
				'<p>' . esc_html__( 'SEOCart\'s tables match this version of the plugin, and the store takes changes.', 'seocart' ) . '</p>'
			);
		}

		return self::result(
			self::SCHEMA_TEST,
			self::CRITICAL,
			GateState::SchemaNewer === $this->gate->state() ? __( 'SEOCart\'s database belongs to a newer version of the plugin', 'seocart' ) : __( 'SEOCart refuses changes until its database is updated', 'seocart' ),
			'<p>' . $blocked . '</p>'
		);
	}

	/**
	 * Runs the secrets test.
	 *
	 * @since 0.1.0
	 *
	 * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string} The result.
	 */
	public function secretsTest(): array {
		return ( $this->secrets )()->siteHealthTest();
	}

	/**
	 * Runs the jobs test.
	 *
	 * @since 0.1.0
	 *
	 * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string} The result.
	 */
	public function jobsTest(): array {
		$findings = self::jobFindings( ( $this->jobs )()->report() );

		if ( array() === $findings ) {
			return self::result(
				self::JOBS_TEST,
				self::GOOD,
				__( 'SEOCart\'s background jobs are running', 'seocart' ),
				'<p>' . esc_html__( 'A runner started one of SEOCart\'s background jobs within the last hour, and none has failed for good.', 'seocart' ) . '</p>'
			);
		}

		$description = '';

		foreach ( $findings as $finding ) {
			$description .= '<p>' . esc_html( $finding['detail'] ) . '</p>';
		}

		return self::result( self::JOBS_TEST, $findings[0]['status'], $findings[0]['label'], $description );
	}

	/**
	 * Lists what is wrong with the background jobs, the most severe first.
	 *
	 * @since 0.1.0
	 *
	 * @param JobsReport $report The jobs report.
	 * @return list<array{status: string, label: string, detail: string}> The findings; none when all is well.
	 */
	private static function jobFindings( JobsReport $report ): array {
		$findings = array();

		if ( ! $report->runtimeSupported ) {
			$findings[] = array(
				'status' => self::CRITICAL,
				'label'  => __( 'SEOCart\'s background jobs cannot run reliably', 'seocart' ),
				'detail' => '' === $report->runtimeVersion
					? __( 'No copy of Action Scheduler, the library that runs SEOCart\'s background jobs, is loaded.', 'seocart' )
					: sprintf(
						/* translators: 1: A version of Action Scheduler. 2: Where that copy comes from, for example a plugin's name. 3: The oldest version SEOCart supports. */
						__( 'Action Scheduler %1$s, from %2$s, runs the background jobs on this site, and SEOCart needs %3$s or newer. Update the plugin that ships it.', 'seocart' ),
						$report->runtimeVersion,
						$report->runtimeSource,
						JobsReport::MINIMUM_VERSION
					),
			);
		}

		if ( $report->runnerStale() ) {
			$findings[] = array(
				'status' => self::RECOMMENDED,
				'label'  => __( 'SEOCart\'s background jobs are not running', 'seocart' ),
				'detail' => __( 'No runner has started one of SEOCart\'s background jobs in the last hour. WP-Cron runs them when the site has visitors; where it does not run reliably, have the server\'s cron run wp seocart jobs run every minute.', 'seocart' ),
			);
		}

		if ( $report->failed > 0 ) {
			$findings[] = array(
				'status' => self::RECOMMENDED,
				'label'  => __( 'Some of SEOCart\'s background jobs failed', 'seocart' ),
				'detail' => sprintf(
					/* translators: %d: A number of background jobs. */
					_n( '%d background job failed on its last attempt. Run wp seocart jobs status to see which kind, and why.', '%d background jobs failed on their last attempt. Run wp seocart jobs status to see which kinds, and why.', $report->failed, 'seocart' ),
					$report->failed
				),
			);
		}

		if ( null !== $report->customStore ) {
			$findings[] = array(
				'status' => self::RECOMMENDED,
				'label'  => __( 'SEOCart cannot see its background jobs', 'seocart' ),
				'detail' => __( 'Another plugin keeps Action Scheduler\'s jobs in a store of its own, so SEOCart can neither count its jobs nor run them from its own triggers. WP-Cron still runs them.', 'seocart' ),
			);
		}

		return $findings;
	}

	/**
	 * Builds a result in the shape Site Health expects of a direct test.
	 *
	 * @since 0.1.0
	 *
	 * @param string $test        The test's id.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       The headline, translated and not escaped.
	 * @param string $description Escaped HTML.
	 * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string} The result.
	 */
	private static function result( string $test, string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'SEOCart', 'seocart' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => $test,
		);
	}
}

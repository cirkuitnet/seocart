<?php
/**
 * Tests the Site Health tests the kernel adds: the schema, the stored secrets and the background jobs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobsReport;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Kernel\Notices;
use SEOCart\Platform\Kernel\SchemaGate;
use SEOCart\Platform\Kernel\SiteHealth;
use SEOCart\Platform\Secrets\SecretsStatus;
use SEOCart\Tests\Support\KernelTestCase;

/**
 * Site Health shows SEOCart's three tests, each built only when it runs, each saying what its module
 * already knows.
 *
 * The filter is the one the kernel adds on an admin request; the jobs test is judged on reports
 * of every kind, given through a queue that returns them.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In Modules::kernelSubscribe(), add the `site_status_tests` filter only on cron requests:
 *   test_the_kernel_adds_its_three_tests_to_site_health finds none of them.
 * - In SiteHealth::schemaTest(), return the good result whatever the gate says: a site that
 *   refuses changes passes the schema test.
 * - In SiteHealth::jobFindings(), leave out the stale runner: jobs that nothing runs pass.
 *
 * @since 0.1.0
 */
final class SiteHealthTest extends KernelTestCase {

	/**
	 * Leaves the admin screen.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Tests that the filter the kernel adds on an admin request lists SEOCart's three direct tests, and that each runs.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernel_adds_its_three_tests_to_site_health(): void {
		$this->container()->get( Lifecycle::class )->activate();

		wp_set_current_user( 1 );
		set_current_screen( 'site-health' );
		Modules::subscribe( $this->container() );

		$empty = array(
			'direct' => array(),
			'async'  => array(),
		);
		$tests = apply_filters( 'site_status_tests', $empty ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, applied as the Site Health screen applies it.

		$ours = array( SiteHealth::SCHEMA_TEST, SecretsStatus::TEST, SiteHealth::JOBS_TEST );

		$this->assertSame( $ours, array_values( array_intersect( array_keys( $tests['direct'] ), $ours ) ), 'Site Health does not list SEOCart\'s three tests.' );

		foreach ( $ours as $id ) {
			$result = call_user_func( $tests['direct'][ $id ]['test'] );

			$this->assertSame( $id, $result['test'] );
			$this->assertContains( $result['status'], array( 'good', 'recommended', 'critical' ) );
			$this->assertNotSame( '', $result['label'] );
		}
	}

	/**
	 * Tests the schema test: critical while the store refuses changes, in the notice's words, and good once it takes them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_schema_test_is_critical_while_the_store_refuses_changes(): void {
		$refusing = $this->container()->get( SiteHealth::class )->schemaTest();

		$this->assertSame( 'critical', $refusing['status'], 'A site that refuses changes passed the schema test.' );
		$this->assertStringContainsString( 'finishing its installation', $refusing['description'] );

		$this->container()->get( Lifecycle::class )->activate();

		$this->assertSame( 'good', $this->container()->get( SiteHealth::class )->schemaTest()['status'] );
	}

	/**
	 * Tests that the secrets test is the verdict of the secrets status, as the status command gives it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_secrets_test_is_the_secrets_status_verdict(): void {
		$container = $this->container();

		$container->get( Lifecycle::class )->activate();

		$this->assertSame( $container->get( SecretsStatus::class )->siteHealthTest(), $container->get( SiteHealth::class )->secretsTest() );
	}

	/**
	 * Returns jobs reports and the status the jobs test must give each.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: JobsReport, 1: string, 2: string}> The report, the status and the start of the label.
	 */
	public static function reports(): array {
		return array(
			'a runner checked in, nothing failed' => array( new JobsReport( 0, 3, 0, 0, null, 60, array(), '4.2.0', 'SEOCart', array( '4.2.0' ) ), 'good', 'SEOCart\'s background jobs are running' ),
			'no runner ever checked in'           => array( new JobsReport( 1, 3, 0, 0, 30, null, array(), '4.2.0', 'SEOCart', array( '4.2.0' ) ), 'recommended', 'SEOCart\'s background jobs are not running' ),
			'jobs failed for good'                => array( new JobsReport( 0, 3, 0, 2, null, 60, array(), '4.2.0', 'SEOCart', array( '4.2.0' ) ), 'recommended', 'Some of SEOCart\'s background jobs failed' ),
			'the library in control is too old'   => array( new JobsReport( 0, 3, 0, 0, null, null, array(), '3.5.0', 'plugin old-shop', array( '4.2.0', '3.5.0' ) ), 'critical', 'SEOCart\'s background jobs cannot run reliably' ),
		);
	}

	/**
	 * Tests how the jobs test judges each report.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider reports
	 *
	 * @param JobsReport $report The jobs report.
	 * @param string     $status The status the test must give.
	 * @param string     $label  The label the test must give.
	 */
	public function test_the_jobs_test_judges_the_jobs_report( JobsReport $report, string $status, string $label ): void {
		$container = $this->container();
		$health    = new SiteHealth(
			$container->get( SchemaGate::class ),
			static fn(): Notices => $container->get( Notices::class ),
			static fn(): SecretsStatus => $container->get( SecretsStatus::class ),
			static fn(): JobQueue => self::queueReporting( $report )
		);

		$result = $health->jobsTest();

		$this->assertSame( $status, $result['status'] );
		$this->assertSame( $label, $result['label'] );
		$this->assertSame( SiteHealth::JOBS_TEST, $result['test'] );
	}

	/**
	 * Returns a queue whose report is the given one, and which queues nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param JobsReport $report The report.
	 * @return JobQueue The queue.
	 */
	private static function queueReporting( JobsReport $report ): JobQueue {
		return new class( $report ) implements JobQueue {

			/**
			 * The report.
			 *
			 * @var JobsReport
			 */
			private JobsReport $report;

			/**
			 * Keeps the report.
			 *
			 * @param JobsReport $report The report.
			 */
			public function __construct( JobsReport $report ) {
				$this->report = $report;
			}

			/**
			 * Queues nothing.
			 *
			 * @param Job $job The job.
			 * @return bool False.
			 */
			public function enqueue( Job $job ): bool {
				unset( $job );

				return false;
			}

			/**
			 * Schedules nothing.
			 *
			 * @param Job                $job   The job.
			 * @param \DateTimeImmutable $runAt When.
			 * @return bool False.
			 */
			public function schedule( Job $job, \DateTimeImmutable $runAt ): bool {
				unset( $job, $runAt );

				return false;
			}

			/**
			 * Cancels nothing.
			 *
			 * @param string|null $handler   The handler.
			 * @param string|null $uniqueKey The key.
			 * @return int 0.
			 */
			public function cancel( ?string $handler = null, ?string $uniqueKey = null ): int {
				unset( $handler, $uniqueKey );

				return 0;
			}

			/**
			 * Deletes nothing.
			 *
			 * @param int $limit The limit.
			 * @return int 0.
			 */
			public function cleanup( int $limit ): int {
				unset( $limit );

				return 0;
			}

			/**
			 * Schedules nothing.
			 *
			 * @return list<string> None.
			 */
			public function ensureRecurring(): array {
				return array();
			}

			/**
			 * Returns the report.
			 *
			 * @return JobsReport The report.
			 */
			public function report(): JobsReport {
				return $this->report;
			}
		};
	}
}

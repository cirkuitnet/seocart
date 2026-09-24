<?php
/**
 * Tests activation, reactivation, deactivation, uninstallation and reconciliation on one site
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Authorization\GrantLedger;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Platform\Kernel\SafeModeStatus;
use SEOCart\Platform\Kernel\SchemaGate;
use SEOCart\Platform\Jobs\Handlers\MigrationAttempt;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Jobs\RunnerTriggers;
use SEOCart\Platform\Secrets\SecretsCanary;
use SEOCart\Tests\Support\Jobs\PluginActions;
use SEOCart\Tests\Support\Migrations\MarksRowsInBatches;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Migrations\CreatesTestTable;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests compare the stored rows before and after, as the database holds them.

/**
 * Installing is idempotent and per site, and nothing the plugin does on deactivation or uninstall removes store data.
 *
 * The grant ledger is the production one: a settings document of the site.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In Lifecycle::deactivate(), plant `delete_option( 'seocart_boot' );`:
 *   test_deactivation_and_uninstallation_remove_nothing loses the identity and fails.
 * - In uninstall.php, after the guard, plant `remove_role( 'seocart_manager' );`: the same test fails.
 * - In OptionGrantLedger::record(), plant `return;` as the first line:
 *   test_a_capability_the_merchant_removed_is_never_granted_again sees it come back and fails.
 * - In BootRecord::fromJson(), refuse every shape version but its own, as it once did:
 *   test_an_older_version_never_rewrites_a_record_of_a_newer_shape sees the record replaced.
 * - In Lifecycle::installSite(), remove the `isNewerShape()` return: the same test counts the queries
 *   of the installation run a reconciliation starts on the newer version's site.
 * - In BootOption::mutate(), remove the `isNewerShape()` return: the same test's adoption fails.
 * - In Lifecycle::installSite(), record the version whatever the recorded one is:
 *   test_an_older_version_activated_after_a_newer_one_keeps_the_newer_version fails.
 * - In Lifecycle::installSite(), leave out initializeSecrets(): test_activation_gives_the_secrets_a_key_and_schedules_the_recurring_jobs
 *   finds a canary that does not open.
 * - In Lifecycle::installSite(), leave out ensureRecurring(): the same test finds a recurring job without a run.
 * - In Lifecycle::deactivate(), leave out cancelJobs(): test_deactivation_cancels_the_plugins_jobs finds them waiting.
 * - In Lifecycle::queueIfIncomplete(), return at once: test_an_incomplete_migration_queues_the_migration_job_once
 *   finds no job; queue `new \SEOCart\Platform\Jobs\Job( MigrationAttempt::name() )`, without its key, instead
 *   of MigrationAttempt::job(): the same test finds two.
 * - In Lifecycle::identity(), leave out `->withLockMode( ... )`: the first write is refused, and
 *   test_the_first_write_records_the_lock_mode finds no record. With BootRecord::toJson()'s lock-mode
 *   check removed as well, the record is written without one, and the same test fails on it.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class LifecycleTest extends KernelTestCase {

	/**
	 * The id of the fixture migration a newer version brings.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NEWER = '20990101_0001_kernel_newer';

	/**
	 * Tests that activating a fresh site creates the tables, the roles, the ledger record and a complete boot record.
	 *
	 * @since 0.1.0
	 */
	public function test_activating_a_fresh_site_installs_it(): void {
		$this->assertFalse( $this->pluginTableExists( 'migrations' ), 'The site is not fresh.' );
		$this->assertNull( $this->storedRecord(), 'The site is not fresh.' );

		$container = $this->container();
		$container->get( Lifecycle::class )->activate();

		self::reloadRoles();

		foreach ( OwnedData::registry()->tableNames() as $table ) {
			$this->assertTrue( $this->pluginTableExists( $table ), "The installation did not create the registered table {$table}." );
		}

		$this->assertNotNull( get_role( 'seocart_manager' ), 'The shipped roles were not created.' );
		$this->assertArrayHasKey( 'seocart_manager', $container->get( GrantLedger::class )->granted(), 'The ledger did not record the grants.' );

		$record = BootRecord::fromJson( $this->storedRecord() );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $record->installUuid() );
		$this->assertSame( home_url(), $record->homeUrl() );
		$this->assertSame( self::codeHead(), $record->schemaHead() );
		$this->assertNotNull( $record->lockMode() );
		$this->assertSame( SEOCART_VERSION, $record->pluginVersion() );
		$this->assertNotNull( $record->installedAt() );
		$this->assertNull( $record->safeModeReason() );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that activating an installed site again changes no role, no migration row and at most the revision.
	 *
	 * @since 0.1.0
	 */
	public function test_activating_again_changes_nothing(): void {
		$lifecycle = $this->container()->get( Lifecycle::class );
		$lifecycle->activate();

		$roles      = $this->storedOption( $this->rolesOptionName() );
		$migrations = $this->migrationRows();
		$record     = BootRecord::fromJson( $this->storedRecord() );

		$lifecycle->activate();

		$again = BootRecord::fromJson( $this->storedRecord() );

		$this->assertSame( $roles, $this->storedOption( $this->rolesOptionName() ), 'Reactivation changed the roles.' );
		$this->assertSame( $migrations, $this->migrationRows(), 'Reactivation changed the migrations table.' );
		$this->assertTrue( $again->sameAs( $record ), 'Reactivation changed the boot record.' );
		$this->assertLessThanOrEqual( $record->rev() + 1, $again->rev() );
	}

	/**
	 * Tests that a capability a merchant removes from a shipped role stays removed when the plugin is activated again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_capability_the_merchant_removed_is_never_granted_again(): void {
		$lifecycle  = $this->container()->get( Lifecycle::class );
		$capability = 'seocart_view_orders';

		$this->assertContains( $capability, ( new CapabilityDeclaration() )->bundle( 'seocart_manager' ), 'The capability the merchant removes must be one the role is granted.' );

		$lifecycle->activate();
		self::reloadRoles();

		get_role( 'seocart_manager' )->remove_cap( $capability );
		self::reloadRoles();

		$lifecycle->activate();
		self::reloadRoles();

		$this->assertFalse( get_role( 'seocart_manager' )->has_cap( $capability ), "The merchant removed {$capability}, and reactivation granted it again." );
	}

	/**
	 * Tests a rolling deployment: a newer version wrote the record in a newer shape, with a field this
	 * version cannot read and keys it does not know. This version reads what it understands, and
	 * neither a reconciliation, nor an activation, nor an adoption rewrites the record: it survives
	 * byte for byte, with the installation's identity. A newer shape whose schema head this version
	 * cannot read closes the gate as a newer schema.
	 *
	 * @since 0.1.0
	 */
	public function test_an_older_version_never_rewrites_a_record_of_a_newer_shape(): void {
		global $wpdb;

		$this->container()->get( Lifecycle::class )->activate();

		$installed = BootRecord::fromJson( $this->storedRecord() );
		$newer     = sprintf(
			'{"v":%1$d,"rev":%2$d,"plugin_version":{"major":2,"minor":0},"schema_head":"%3$s","lock_mode":"get_lock","install_uuid":"%4$s","regions":{"eu":true},"installed_at":"%5$s"}',
			BootRecord::VERSION + 1,
			$installed->rev() + 1,
			self::codeHead(),
			(string) $installed->installUuid(),
			(string) $installed->installedAt()
		);

		$wpdb->update( $wpdb->options, array( 'option_value' => $newer ), array( 'option_name' => BootOption::NAME ) );
		wp_cache_flush();
		wp_load_alloptions();

		$lifecycle = $this->container()->get( Lifecycle::class );
		$log       = $this->captureQueries( static fn() => $lifecycle->reconcile() );

		$this->assertSame( $newer, $this->storedRecord(), 'A reconciliation rewrote the newer version\'s record.' );
		$this->assertQueryCount( 0, $log, 'Reconciling a site a newer version installed' );

		$this->container()->get( Lifecycle::class )->activate();

		$this->assertSame( $newer, $this->storedRecord(), 'An activation rewrote the newer version\'s record.' );

		$this->container()->get( SafeMode::class )->adopt();

		$this->assertSame( $newer, $this->storedRecord(), 'An adoption rewrote the newer version\'s record.' );
		$this->assertSame( $installed->installUuid(), $this->container()->get( BootOption::class )->read()->installUuid(), 'This version did not read the identity it understands.' );
		$this->assertNotContains( BootOption::CORRUPT, array_column( $this->reports, 'code' ), 'A record of a newer shape was reported as corrupt.' );
		$this->assertSame( GateState::Ready, $this->container()->get( SchemaGate::class )->state(), 'The schema head this version understands was not read.' );

		$unreadableHead = str_replace( '"schema_head":"' . self::codeHead() . '"', '"schema_head":{"id":"' . self::codeHead() . '"}', $newer );

		$wpdb->update( $wpdb->options, array( 'option_value' => $unreadableHead ), array( 'option_name' => BootOption::NAME ) );
		wp_cache_flush();

		$this->assertSame( GateState::SchemaNewer, $this->container()->get( SchemaGate::class )->state(), 'A schema head this version cannot read opened the gate.' );
	}

	/**
	 * Tests the installation's last two steps: the secrets get a data key whose canary opens, and
	 * every recurring job has a run waiting.
	 *
	 * @since 0.1.0
	 */
	public function test_activation_gives_the_secrets_a_key_and_schedules_the_recurring_jobs(): void {
		$container = $this->container();

		$container->get( Lifecycle::class )->activate();

		$this->assertTrue( $container->get( SecretsCanary::class )->check()->ok(), 'The secrets have no key whose canary opens.' );

		$pending   = implode( "\n", PluginActions::pending() );
		$recurring = array_keys( $container->get( JobHandlers::class )->recurring() );

		$this->assertNotSame( array(), $recurring, 'No handler recurs, so the check would prove nothing.' );

		foreach ( $recurring as $handler ) {
			$this->assertStringContainsString( '"' . $handler . '"', $pending, "The recurring job {$handler} has no run waiting." );
		}
	}

	/**
	 * Tests an activation that runs before Action Scheduler has started, as the first activation on
	 * a site where no other plugin runs a copy of the library does: it schedules no job, and the
	 * library's first queue run, which WP-Cron fires, has the kernel's repair schedule every
	 * recurring job.
	 *
	 * The library schedules that queue run on the next request of any kind, so a site that serves
	 * only REST or cron requests gets its recurring jobs from the next WP-Cron run, without an
	 * admin request.
	 *
	 * Planted violation: in Modules::subscribe(), pass `$ajax || $cli` to jobsSubscribe(), leaving
	 * WP-Cron out. The queue run schedules nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_an_activation_before_the_library_starts_leaves_the_recurring_jobs_to_its_first_queue_run(): void {
		global $wp_actions;

		$started = $wp_actions['action_scheduler_init'] ?? null;

		unset( $wp_actions['action_scheduler_init'] );

		try {
			$this->container()->get( Lifecycle::class )->activate();
		} finally {
			$wp_actions['action_scheduler_init'] = $started; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Puts back the counter the test took away.
		}

		$this->assertSame( array(), PluginActions::pending(), 'An activation before the library started scheduled a job, so the queue run would prove nothing.' );

		add_filter( 'wp_doing_cron', '__return_true' );
		KernelHooks::detach( ...array_keys( RunnerTriggers::CRON_REPAIR_HOOKS ) );
		Modules::subscribe( $this->container() );

		$repairs = KernelHooks::callbacks( 'action_scheduler_run_queue' );

		$this->assertNotSame( array(), $repairs, 'The kernel added nothing to the library\'s queue run on a WP-Cron request.' );

		foreach ( $repairs as $repair ) {
			$repair( 'WP Cron' );
		}

		$pending = implode( "\n", PluginActions::pending() );

		foreach ( array_keys( $this->container()->get( JobHandlers::class )->recurring() ) as $handler ) {
			$this->assertStringContainsString( '"' . $handler . '"', $pending, "The first queue run did not schedule the recurring job {$handler}." );
		}
	}

	/**
	 * Tests that deactivation cancels the plugin's waiting jobs, which activation schedules again.
	 *
	 * @since 0.1.0
	 */
	public function test_deactivation_cancels_the_plugins_jobs(): void {
		$lifecycle = $this->container()->get( Lifecycle::class );

		$lifecycle->activate();

		$this->assertNotSame( array(), PluginActions::pending(), 'Activation scheduled nothing, so the check would prove nothing.' );

		$lifecycle->deactivate();

		$this->assertSame( array(), PluginActions::pending(), 'Deactivation left the plugin\'s jobs waiting.' );

		$lifecycle->activate();

		$this->assertNotSame( array(), PluginActions::pending(), 'Reactivation did not schedule the recurring jobs again.' );
	}

	/**
	 * Tests the scheduled half of a migration attempt: when a request's attempt stops at its time
	 * budget with data left to migrate, the migration job is queued, and a later attempt at the same
	 * gap queues nothing more.
	 *
	 * The newer code brings a schema migration the store cannot trade without, which closes the
	 * gate and makes a page load attempt the migrations, and a data migration after it, which the
	 * attempt's budget stops after one batch. The second attempt is the Retry link's.
	 *
	 * @since 0.1.0
	 */
	public function test_an_incomplete_migration_queues_the_migration_job_once(): void {
		$this->container()->get( Lifecycle::class )->activate();

		for ( $id = 0; $id < 10; $id++ ) {
			$this->insertRow( $id );
		}

		$marks  = new MarksRowsInBatches( '20990101_0009_kernel_marks', self::ROWS, 10, 3 );
		$report = $this->reporter();
		$newer  = $this->container(
			array(
				Migrator::class  => static fn( Container $c ): Migrator => KernelContainer::migrator( $c, array( new PlatformBootstrapMigration(), new CreatesTestTable( '20990101_0008_kernel_gate', 'kernel_gate', false ), $marks ), $report ),
				Lifecycle::class => static fn( Container $c ): Lifecycle => new Lifecycle( $c, $report, 0 ),
			)
		);

		$newer->get( Lifecycle::class )->reconcile();
		$newer->get( Lifecycle::class )->retryMigration();

		$this->assertSame( 2, $marks->batches, 'Each attempt should have run one batch before its budget ran out.' );

		$queued = array_values( array_filter( PluginActions::pending(), static fn( string $args ): bool => str_contains( $args, '"' . MigrationAttempt::name() . '"' ) ) );

		$this->assertCount( 1, $queued, 'The migration job must be queued once, however many requests see the gap.' );
	}

	/**
	 * Tests a site whose gate is open while migrations that let the store trade are outstanding, as
	 * after an activation that ran before Action Scheduler started and so could queue nothing: a
	 * request that reconciles queues the migration job, once however many do, and runs no batch
	 * itself.
	 *
	 * Planted violation: in Lifecycle::reconcile(), drop the branch for an open gate. Nothing is queued.
	 *
	 * @since 0.1.0
	 */
	public function test_an_open_gate_with_migrations_outstanding_queues_the_migration_job_once(): void {
		$this->container()->get( Lifecycle::class )->activate();

		$marks  = new MarksRowsInBatches( '20990101_0009_kernel_marks', self::ROWS, 10, 3 );
		$report = $this->reporter();
		$newer  = $this->container(
			array(
				Migrator::class => static fn( Container $c ): Migrator => KernelContainer::migrator( $c, array( new PlatformBootstrapMigration(), $marks ), $report ),
			)
		);

		$this->assertSame( GateState::Ready, $newer->get( SchemaGate::class )->state(), 'The gate is closed, so the open-gate case would not be tested.' );

		$newer->get( Lifecycle::class )->reconcile();
		$newer->get( Lifecycle::class )->reconcile();

		$this->assertSame( 0, $marks->batches, 'A request with the gate open ran a migration batch itself.' );

		$queued = array_values( array_filter( PluginActions::pending(), static fn( string $args ): bool => str_contains( $args, '"' . MigrationAttempt::name() . '"' ) ) );

		$this->assertCount( 1, $queued, 'The migration job must be queued once, however many requests see the outstanding migrations.' );
	}

	/**
	 * Tests that the first write already records the lock mode: an installation that dies right
	 * after it leaves a record that says how locks are held, so nothing that reads the record,
	 * the schema gate least of all, ever has to probe the host or write the record first.
	 *
	 * @since 0.1.0
	 */
	public function test_the_first_write_records_the_lock_mode(): void {
		$failure   = new \RuntimeException( 'The capability installer fails on purpose.' );
		$container = $this->container(
			array(
				CapabilityInstaller::class => static function () use ( $failure ): never {
					throw $failure;
				},
			)
		);

		try {
			$container->get( Lifecycle::class )->installSite();
			$this->fail( 'The installation did not stop at the capability installer.' );
		} catch ( \RuntimeException $stopped ) {
			$this->assertSame( $failure, $stopped );
		}

		$this->assertNotNull( $this->storedRecord(), 'The identity was not written before the capability installer ran.' );

		$record = BootRecord::fromJson( $this->storedRecord() );

		$this->assertNotNull( $record->lockMode(), 'The first write left the lock mode out.' );
		$this->assertNull( $record->pluginVersion(), 'An installation that died part-way recorded its version.' );
	}

	/**
	 * Tests that deactivation, and then the uninstall file, leave the tables, the boot record and the roles as they were.
	 *
	 * @since 0.1.0
	 */
	public function test_deactivation_and_uninstallation_remove_nothing(): void {
		$lifecycle = $this->container()->get( Lifecycle::class );
		$lifecycle->activate();

		$record = $this->storedRecord();
		$roles  = $this->storedOption( $this->rolesOptionName() );
		$tables = $this->pluginTables();

		$lifecycle->deactivate();

		$this->assertSame( $record, $this->storedRecord(), 'Deactivation changed the boot record, and with it the site\'s identity.' );
		$this->assertSame( $roles, $this->storedOption( $this->rolesOptionName() ), 'Deactivation changed the roles.' );
		$this->assertEqualsCanonicalizing( $tables, $this->pluginTables(), 'Deactivation dropped a table.' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant, defined as core defines it before it includes the file.
			define( 'WP_UNINSTALL_PLUGIN', 'seocart/seocart.php' );
		}

		include dirname( __DIR__, 3 ) . '/uninstall.php';
		wp_cache_flush();

		$this->assertSame( $record, $this->storedRecord(), 'Uninstalling removed or changed the boot record.' );
		$this->assertSame( $roles, $this->storedOption( $this->rolesOptionName() ), 'Uninstalling changed the roles.' );
		$this->assertEqualsCanonicalizing( $tables, $this->pluginTables(), 'Uninstalling dropped a table.' );
	}

	/**
	 * Tests that reconciling a site installed by an older version runs the installation: it applies
	 * the newer version's migration and records the version.
	 *
	 * @since 0.1.0
	 */
	public function test_reconciling_after_an_upgrade_migrates_and_records_the_version(): void {
		$this->container()->get( Lifecycle::class )->activate();
		$this->changeRecord( static fn( BootRecord $record ): BootRecord => $record->withPluginVersion( '0.0.1' ) );

		$newer = new CreatesTestTable( self::NEWER, 'kernel_newer', false );

		$this->newerVersion( $newer )->get( Lifecycle::class )->reconcile();

		$record = BootRecord::fromJson( $this->storedRecord() );

		$this->assertSame( 1, $newer->runs, 'The newer version\'s migration did not run exactly once.' );
		$this->assertTrue( $this->pluginTableExists( 'test_kernel_newer' ) );
		$this->assertSame( self::NEWER, $record->schemaHead() );
		$this->assertSame( SEOCART_VERSION, $record->pluginVersion() );
	}

	/**
	 * Tests a rolling deployment the other way round: a newer version installed the site, then an
	 * older node handles an activation. The older node must not record its own version, or the newer
	 * one would take the site for one to upgrade and install again.
	 *
	 * @since 0.1.0
	 */
	public function test_an_older_version_activated_after_a_newer_one_keeps_the_newer_version(): void {
		$this->container()->get( Lifecycle::class )->activate();
		$this->changeRecord( static fn( BootRecord $record ): BootRecord => $record->withPluginVersion( '999.0.0' ) );

		$this->container()->get( Lifecycle::class )->activate();

		$this->assertSame( '999.0.0', BootRecord::fromJson( $this->storedRecord() )->pluginVersion(), 'An older version recorded its version over a newer one.' );
	}

	/**
	 * Tests that reconciling a site on which nothing is due sends no query.
	 *
	 * @since 0.1.0
	 */
	public function test_reconciling_when_nothing_is_due_sends_no_query(): void {
		$this->container()->get( Lifecycle::class )->activate();

		$lifecycle = $this->container()->get( Lifecycle::class );
		$log       = $this->captureQueries( static fn() => $lifecycle->reconcile() );

		$this->assertQueryCount( 0, $log, 'Reconciling an up-to-date site' );
	}

	/**
	 * Tests that a site whose record was lost gets a new identity and enters Safe Mode, so the merchant is asked.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_whose_record_was_lost_is_rebuilt_into_safe_mode(): void {
		$this->container()->get( Lifecycle::class )->activate();

		$lost = BootRecord::fromJson( $this->storedRecord() );

		$this->deleteRecord();
		$this->container()->get( Lifecycle::class )->reconcile();

		$rebuilt = BootRecord::fromJson( $this->storedRecord() );

		$this->assertNotSame( $lost->installUuid(), $rebuilt->installUuid(), 'A lost identity cannot be recovered; the rebuilt record must not pretend to.' );
		$this->assertSame( SafeModeStatus::Rebuilt, $rebuilt->safeModeReason() );
		$this->assertSame( self::codeHead(), $rebuilt->schemaHead() );
		$this->assertSame( SEOCART_VERSION, $rebuilt->pluginVersion() );
	}

	/**
	 * Returns a container whose migration chain holds one more migration, as a newer version's would.
	 *
	 * @since 0.1.0
	 *
	 * @param CreatesTestTable $newer The newer version's migration.
	 * @return Container The container.
	 */
	private function newerVersion( CreatesTestTable $newer ): Container {
		$report = $this->reporter();

		return $this->container(
			array(
				Migrator::class => static fn( Container $c ): Migrator => KernelContainer::migrator( $c, array( new PlatformBootstrapMigration(), $newer ), $report ),
			)
		);
	}

	/**
	 * Returns every row of the migrations table.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array<string, mixed>> The rows, in id order.
	 */
	private function migrationRows(): array {
		return $this->db->fetchAll( 'SELECT * FROM %i ORDER BY migration_id', $this->db->table( 'migrations' ) );
	}

	/**
	 * Returns the name of the current site's roles option.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	private function rolesOptionName(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'user_roles';
	}
}

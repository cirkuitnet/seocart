<?php
/**
 * Modules: every module's bindings and hook subscriptions, in one file
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Catalog\Application\PostGateway;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Event\ProductDeleted;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Catalog\Infrastructure\MysqlProductRepository;
use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Catalog\Infrastructure\WordPressPostGateway;
use SEOCart\Catalog\Interfaces\Admin\ProductEditorPanel;
use SEOCart\Catalog\Interfaces\Rest\ProductPostsController;
use SEOCart\Interfaces\Operations\AbilitiesAdapter;
use SEOCart\Interfaces\Operations\CliAdapter;
use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Interfaces\Operations\RestAdapter;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Inventory\Infrastructure\Doctor\StockProjectionCheck;
use SEOCart\Inventory\Infrastructure\Jobs\SweepHolds;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Authorization\CapabilityMapper;
use SEOCart\Platform\Authorization\GrantLedger;
use SEOCart\Platform\Authorization\OptionGrantLedger;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Authorization\RoleNames;
use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\Cli\DoctorCommand;
use SEOCart\Platform\Database\Cli\MigrateCommand;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\DatabaseState;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Cli\OutboxCommand;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\Cli\JobsCommand;
use SEOCart\Platform\Jobs\EventWake;
use SEOCart\Platform\Jobs\Handlers\JobHistoryCleanup;
use SEOCart\Platform\Jobs\Handlers\MigrationAttempt;
use SEOCart\Platform\Jobs\Handlers\OutboxCatchUp;
use SEOCart\Platform\Jobs\Handlers\OutboxRetention;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobRunner;
use SEOCart\Platform\Jobs\RunnerTriggers;
use SEOCart\Platform\Kernel\Cli\SafeModeCommand;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Platform\Localization\SiteLocale;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Logging\FallbackLog;
use SEOCart\Platform\Logging\Level;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\LogRetention;
use SEOCart\Platform\Logging\LogRetentionJob;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Platform\Rest\RestErrorTranslator;
use SEOCart\Platform\Secrets\Cli\SecretsCommand;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretsCanary;
use SEOCart\Platform\Secrets\SecretsError;
use SEOCart\Platform\Secrets\SecretsStatus;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\InternationalSettings;
use SEOCart\Platform\Settings\SecretSealer;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\SupportError;
use SEOCart\Support\SystemClock;
use SEOCart\Support\SystemIdGenerator;

/**
 * Binds every module's services into the container, and hooks every module into WordPress.
 *
 * Owns one fact: how the modules are wired. Each module has one pair of private methods here,
 * `{module}Register()` and `{module}Subscribe()` (a module that adds no hook has only the first),
 * so a module's wiring is in one place and a change to it is one hunk. One file rather than a
 * provider class per module, because every file the plugin loads on an idle request counts
 * against its budget.
 *
 * The rule that keeps an idle request cheap: register() only binds factories, and subscribe() only
 * adds hooks whose callbacks resolve a service when the hook fires. Neither builds a service,
 * reads an option, translates or computes an address. Hooks that only matter in the admin, on the
 * command line, in a cron run or on a network are added only there. An idle front-end request
 * gets these hooks, and loads no file but the main file, the kernel, the container and this one,
 * and the two the product post type's registration loads on `init`:
 *
 * - `plugins_loaded` and the activation and deactivation hooks, from the main file;
 * - `map_meta_cap`, the one capability mapper, which returns before it resolves anything for a
 *   capability that is not the plugin's;
 * - `rest_api_init`, which installs the product controller and registers the operations'
 *   routes when a REST server is built;
 * - `wp_abilities_api_categories_init` and `wp_abilities_api_init`, which register the
 *   operations' abilities when the Abilities API initialises;
 * - JOB_HOOK, which Action Scheduler fires to run one of the plugin's jobs, wherever its queue
 *   runs — another plugin's runner included;
 * - `init`, which registers the product post type.
 *
 * Every service that reports what it does not throw is given the one Reporter, which resolves
 * the logger on its first report; the operation invoker and the error translator get its
 * `unexpected` and `internal` shapes.
 *
 * It also keeps the lists the kernel cannot derive at run time without loading every class: the
 * error catalogs, the domain events and the maintenance commands. Each has a test that holds it to
 * what exists. The migration chain is not one of them: the migrator runs the data registry's
 * migrations, the one production list of what the plugin owns in a site.
 *
 * @since 0.1.0
 */
final class Modules {

	/**
	 * Every error catalog, from which the one error table is composed.
	 *
	 * @since 0.1.0
	 *
	 * @var list<class-string<\SEOCart\Support\Error\ErrorCode>>
	 */
	public const ERROR_CATALOGS = array(
		AuthorizationError::class,
		CatalogError::class,
		DatabaseError::class,
		InventoryError::class,
		KernelError::class,
		SecretsError::class,
		SettingsError::class,
		SupportError::class,
	);

	/**
	 * Every domain event class, from which the event catalog is built.
	 *
	 * @since 0.1.0
	 *
	 * @var list<class-string>
	 */
	public const EVENT_CLASSES = array(
		ProductDeleted::class,
		ProductSaved::class,
		StockAdjusted::class,
		StockHoldExpired::class,
		StockReserved::class,
		StockReservationReleased::class,
	);

	/**
	 * What every plugin capability contains, which the capability mapper's filter checks first.
	 *
	 * It is CapabilityDeclaration::PREFIX, spelled out: naming the declaration's constant would load
	 * the declaration on every request that checks any capability, idle ones included. A test holds
	 * the two equal.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CAPABILITY_PREFIX = 'seocart_';

	/**
	 * The hook Action Scheduler fires to run one of the plugin's jobs.
	 *
	 * It is JobRunner::HOOK, spelled out for the same reason as CAPABILITY_PREFIX: the hook is added
	 * on every request, and naming the runner's constant would load the runner. A test holds the two
	 * equal.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const JOB_HOOK = 'seocart_job';

	/**
	 * The maintenance commands: operational tooling that has no REST route or ability, so no
	 * operation definition, each with the class that implements it.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, class-string>
	 */
	public const MAINTENANCE_COMMANDS = array(
		'seocart migrate'   => MigrateCommand::class,
		'seocart safe-mode' => SafeModeCommand::class,
		'seocart outbox'    => OutboxCommand::class,
		'seocart jobs'      => JobsCommand::class,
		'seocart doctor'    => DoctorCommand::class,
		'seocart secrets'   => SecretsCommand::class,
	);

	/**
	 * Binds every module's factories. Builds nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	public static function register( Container $container ): void {
		self::databaseRegister( $container );
		self::registryRegister( $container );
		self::loggingRegister( $container );
		self::authorizationRegister( $container );
		self::operationsRegister( $container );
		self::settingsRegister( $container );
		self::secretsRegister( $container );
		self::eventsRegister( $container );
		self::jobsRegister( $container );
		self::catalogRegister( $container );
		self::inventoryRegister( $container );
		self::kernelRegister( $container );
	}

	/**
	 * Adds every module's hooks for the kind of request being served. Builds nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container the callbacks resolve from.
	 */
	public static function subscribe( Container $container ): void {
		$admin = is_admin();
		$cli   = defined( 'WP_CLI' ) && WP_CLI; // @phpstan-ignore booleanAnd.rightAlwaysTrue (PHPStan takes the value from the test probe that plays a WP-CLI run; a site may define it false.)
		$cron  = wp_doing_cron();
		$ajax  = wp_doing_ajax();

		self::authorizationSubscribe( $container, $admin || $cli );
		self::operationsSubscribe( $container );
		self::jobsSubscribe( $container, $cron || $ajax || $cli );
		self::catalogSubscribe( $container, $admin );
		self::kernelSubscribe( $container, $admin, $ajax, $cli, $cron, is_multisite() );
	}

	/**
	 * Registers every command: each operation's, through the command adapter, and every maintenance command.
	 *
	 * Runs on `cli_init` with WP-CLI's functions, and in the tests with recorders, so the command
	 * walk sees exactly what WP-CLI is given. Each maintenance command is built here, with what it
	 * holds; on an installed site that sends no query.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container the commands are built from.
	 * @param callable  $add       Registers a command: WP_CLI::add_command().
	 * @param callable  $display   Prints an operation's result in the format asked for.
	 * @param callable  $fail      Reports a failure and ends the command with a non-zero exit status: WP_CLI::error().
	 *
	 * @phpstan-param callable(string, callable, array<string, mixed>=): mixed $add
	 * @phpstan-param callable(array<string, mixed>, string): void            $display
	 * @phpstan-param callable(string): void                                  $fail
	 */
	public static function registerCommands( Container $container, callable $add, callable $display, callable $fail ): void {
		$container->get( CliAdapter::class )->register( $add, $display, $fail );

		foreach ( self::MAINTENANCE_COMMANDS as $name => $class ) {
			$add( $name, $container->get( $class ) );
		}
	}

	/**
	 * Returns every field the plugin declares outside the data registry: each operation's input and
	 * output fields, and each setting's field. The logger's redactor is built from them.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $operations The operations.
	 * @return list<FieldSpec> The fields, the operations' first, in declaration order.
	 */
	public static function declaredFields( OperationRegistry $operations ): array {
		$fields = array();

		foreach ( $operations->all() as $definition ) {
			$fields = array_merge( $fields, $definition->input(), $definition->output()->fields() );
		}

		return array_merge( $fields, array_map( static fn( Setting $setting ): FieldSpec => $setting->field(), Settings::registry()->all() ) );
	}

	/**
	 * The database module: the connection, the lock service, the migrator and the gated transaction manager.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function databaseRegister( Container $container ): void {
		$container->bind(
			Database::class,
			static function ( Container $c ): Database {
				global $wpdb;

				// The transaction guards throw where a developer will see it, and report everywhere else.
				return new Database( $wpdb, in_array( wp_get_environment_type(), array( 'local', 'development' ), true ), $c->get( Reporter::class ) );
			}
		);
		$container->bind( MigrationsTableState::class, static fn( Container $c ): MigrationsTableState => new MigrationsTableState( $c->get( Database::class ) ) );
		$container->bind( BootOptionState::class, static fn( Container $c ): BootOptionState => new BootOptionState( $c->get( BootOption::class ), $c->get( MigrationsTableState::class ) ) );
		$container->bind( DatabaseState::class, static fn( Container $c ): DatabaseState => $c->get( BootOptionState::class ) );
		$container->bind( LockService::class, static fn( Container $c ): LockService => new LockService( $c->get( Database::class ), $c->get( BootOptionState::class )->lockMode() ) );
		$container->bind( Clock::class, static fn(): Clock => new SystemClock() );
		$container->bind( IdGenerator::class, static fn( Container $c ): IdGenerator => new SystemIdGenerator( $c->get( Clock::class ) ) );
		$container->bind(
			Migrator::class,
			static fn( Container $c ): Migrator => new Migrator( $c->get( Database::class ), $c->get( LockService::class ), $c->get( DatabaseState::class ), $c->get( DataRegistry::class )->migrations(), $c->get( Clock::class ), $c->get( Reporter::class ) )
		);
		$container->bind( TransactionManager::class, static fn( Container $c ): TransactionManager => new GatedTransactionManager( $c->get( Database::class ), $c->get( SchemaGate::class ) ) );
		$container->bind( MigrateCommand::class, static fn( Container $c ): MigrateCommand => new MigrateCommand( $c->get( Migrator::class ), self::commandOutput() ) );
	}

	/**
	 * The data registry: what the plugin owns in a site, whose migrations the migrator runs and whose tables a deleted site drops.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function registryRegister( Container $container ): void {
		$container->bind( DataRegistry::class, static fn(): DataRegistry => OwnedData::registry() );
	}

	/**
	 * The logging module: the correlation id, the logger, the reporter every module reports through, the retention sweep and the doctor.
	 *
	 * The correlation id accepts the client's X-Request-Id when it is a UUID, so a REST client can
	 * name the id its errors and log lines carry. The logger writes from Info up, or from Debug up
	 * where WP_DEBUG is on; the logger and the reporter share one FallbackLog, so a process that
	 * loses lines through both writes one count.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function loggingRegister( Container $container ): void {
		$container->bind(
			CorrelationId::class,
			static function ( Container $c ): CorrelationId {
				$correlation = new CorrelationId( $c->get( IdGenerator::class ) );

				$correlation->accept( isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] ) ) : null );

				return $correlation;
			}
		);
		$container->bind( FallbackLog::class, static fn(): FallbackLog => new FallbackLog() );
		$container->bind( Redactor::class, static fn( Container $c ): Redactor => Redactor::fromDeclarations( $c->get( DataRegistry::class ), ...self::declaredFields( $c->get( OperationRegistry::class ) ) ) );
		$container->bind(
			Logger::class,
			static fn( Container $c ): Logger => new Logger(
				$c->get( Database::class ),
				$c->get( CorrelationId::class ),
				$c->get( Redactor::class ),
				defined( 'WP_DEBUG' ) && WP_DEBUG ? Level::Debug : Level::Info,
				null,
				$c->get( FallbackLog::class )
			)
		);
		$container->bind( Reporter::class, static fn( Container $c ): Reporter => new Reporter( static fn(): Logger => $c->get( Logger::class ), $c->get( CorrelationId::class ), $c->get( FallbackLog::class ) ) );
		$container->bind( LogRetention::class, static fn( Container $c ): LogRetention => new LogRetention( $c->get( Database::class ) ) );
		$container->bind( Doctor::class, static fn( Container $c ): Doctor => new Doctor( $c->get( Database::class ), $c->get( DataRegistry::class ), $c->get( Migrator::class ), $c->get( Outbox::class ), $c->get( JobQueue::class ), $c->get( StockProjectionCheck::class ) ) );
		$container->bind( DoctorCommand::class, static fn( Container $c ): DoctorCommand => new DoctorCommand( $c->get( Doctor::class ), self::commandOutput() ) );
	}

	/**
	 * The authorization module: the capability declaration, its mapper and installer, and the authorizer.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function authorizationRegister( Container $container ): void {
		$container->bind( CapabilityDeclaration::class, static fn(): CapabilityDeclaration => new CapabilityDeclaration() );
		$container->bind( CapabilityMapper::class, static fn( Container $c ): CapabilityMapper => new CapabilityMapper( $c->get( CapabilityDeclaration::class ) ) );
		// The installer's record, in a settings document of the site. The ledger refuses to record inside a transaction.
		$container->bind( GrantLedger::class, static fn( Container $c ): GrantLedger => new OptionGrantLedger( $c->get( SettingsStore::class ), $c->get( Database::class ) ) );
		$container->bind( CapabilityInstaller::class, static fn( Container $c ): CapabilityInstaller => new CapabilityInstaller( $c->get( CapabilityDeclaration::class ), $c->get( GrantLedger::class ) ) );
		$container->bind( Authorizer::class, static fn( Container $c ): Authorizer => new Authorizer( $c->get( CapabilityDeclaration::class ) ) );
	}

	/**
	 * The authorization module's hooks: the one capability mapper, and the role names where roles are listed.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container   The container.
	 * @param bool      $rolesRender Whether this request can list roles: the admin or the command line.
	 */
	private static function authorizationSubscribe( Container $container, bool $rolesRender ): void {
		// A check on anything but a plugin capability returns before the mapper, or its declaration, is loaded.
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $userId, $args ) use ( $container ) {
				if ( ! is_string( $cap ) || ! str_contains( $cap, self::CAPABILITY_PREFIX ) ) {
					return $caps;
				}

				return $container->get( CapabilityMapper::class )->map( $caps, $cap, $userId, $args );
			},
			10,
			4
		);

		if ( $rolesRender ) {
			add_filter( 'gettext_with_context_default', array( RoleNames::class, 'translate' ), 10, 3 );
		}
	}

	/**
	 * The operations and their surfaces: the registry, the invoker, the error translator and the REST, ability and command adapters.
	 *
	 * The invoker resolves an operation's service from this container when the operation runs, so
	 * every service an operation names must be bound; a test holds that.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function operationsRegister( Container $container ): void {
		$container->bind( OperationRegistry::class, static fn(): OperationRegistry => Operations::registry() );
		$container->bind( ErrorTable::class, static fn(): ErrorTable => ErrorTable::compose( ...self::ERROR_CATALOGS ) );
		$container->bind(
			ErrorTranslator::class,
			static fn( Container $c ): ErrorTranslator => new RestErrorTranslator(
				$c->get( ErrorTable::class ),
				static fn(): string => $c->get( CorrelationId::class )->current(),
				array( $c->get( Reporter::class ), 'internal' )
			)
		);
		$container->bind(
			OperationInvoker::class,
			static fn( Container $c ): OperationInvoker => new OperationInvoker(
				static fn( string $service ): object => $c->get( $service ),
				$c->get( ErrorTranslator::class ),
				array( $c->get( Reporter::class ), 'unexpected' )
			)
		);
		$container->bind( RestAdapter::class, static fn( Container $c ): RestAdapter => new RestAdapter( $c->get( OperationRegistry::class ), $c->get( OperationInvoker::class ), $c->get( ErrorTranslator::class ) ) );
		$container->bind( AbilitiesAdapter::class, static fn( Container $c ): AbilitiesAdapter => new AbilitiesAdapter( $c->get( OperationRegistry::class ), $c->get( OperationInvoker::class ) ) );
		$container->bind( CliAdapter::class, static fn( Container $c ): CliAdapter => new CliAdapter( $c->get( OperationRegistry::class ), $c->get( OperationInvoker::class ) ) );
	}

	/**
	 * The operations' hooks: the abilities, which WordPress asks for when the Abilities API initialises.
	 *
	 * The routes are registered on `rest_api_init` by the kernel's hook, which also reconciles a
	 * REST request's site.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function operationsSubscribe( Container $container ): void {
		add_action(
			'wp_abilities_api_categories_init',
			static function () use ( $container ): void {
				$container->get( AbilitiesAdapter::class )->registerCategory();
			}
		);
		add_action(
			'wp_abilities_api_init',
			static function () use ( $container ): void {
				$container->get( AbilitiesAdapter::class )->registerAbilities();
			}
		);
	}

	/**
	 * The settings module: the store that reads and writes every setting, and the service behind the settings operations.
	 *
	 * The store and the service are built over Settings::registry(), the production list and the
	 * only settings registry the plugin builds, so the registry itself is not a service here.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function settingsRegister( Container $container ): void {
		$container->bind( SettingsStore::class, static fn( Container $c ): SettingsStore => new SettingsStore( Settings::registry(), $c->get( Database::class ) ) );
		$container->bind(
			SettingsService::class,
			static fn( Container $c ): SettingsService => new SettingsService(
				Settings::registry(),
				$c->get( SettingsStore::class ),
				$c->get( Authorizer::class ),
				$c->get( SecretSealer::class ),
				$c->get( TransactionManager::class )
			)
		);
	}

	/**
	 * The secrets module: the encryption key, the data keys, the vault that seals the secret settings, the canary, the status and its command.
	 *
	 * SEOCART_ENCRYPTION_KEY is read here, once, when the data keys are first needed.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function secretsRegister( Container $container ): void {
		$container->bind( EncryptionKey::class, static fn(): EncryptionKey => EncryptionKey::fromEnvironment() );
		$container->bind( SecretKeys::class, static fn( Container $c ): SecretKeys => new SecretKeys( Settings::registry(), $c->get( SettingsStore::class ), $c->get( Database::class ), $c->get( EncryptionKey::class ) ) );
		$container->bind( SecretVault::class, static fn( Container $c ): SecretVault => new SecretVault( Settings::registry(), $c->get( SettingsStore::class ), $c->get( SecretKeys::class ) ) );
		$container->bind( SecretSealer::class, static fn( Container $c ): SecretSealer => $c->get( SecretVault::class ) );
		$container->bind( SecretsCanary::class, static fn( Container $c ): SecretsCanary => new SecretsCanary( $c->get( SecretKeys::class ) ) );
		$container->bind( SecretsStatus::class, static fn( Container $c ): SecretsStatus => new SecretsStatus( $c->get( SecretKeys::class ), $c->get( SecretVault::class ), $c->get( SecretsCanary::class ) ) );
		$container->bind( SecretsCommand::class, static fn( Container $c ): SecretsCommand => new SecretsCommand( $c->get( SecretKeys::class ), $c->get( SecretVault::class ), $c->get( SecretsStatus::class ), self::commandOutput() ) );
	}

	/**
	 * The events module: the catalog, the outbox, the hook bridge, the drainer, the publisher and the outbox command.
	 *
	 * The publisher's wake is the jobs module's EventWake, which delivers what a request stored
	 * after its response has been sent. The drainer pauses while Safe Mode is on.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function eventsRegister( Container $container ): void {
		$container->bind( EventCatalog::class, static fn(): EventCatalog => new EventCatalog( self::EVENT_CLASSES ) );
		$container->bind( Outbox::class, static fn( Container $c ): Outbox => new Outbox( $c->get( Database::class ) ) );
		$container->bind( HookBridge::class, static fn( Container $c ): HookBridge => new HookBridge( $c->get( Reporter::class ) ) );
		$container->bind(
			OutboxDrainer::class,
			static fn( Container $c ): OutboxDrainer => new OutboxDrainer(
				$c->get( Database::class ),
				$c->get( Outbox::class ),
				$c->get( HookBridge::class ),
				$c->get( EventCatalog::class ),
				$c->get( LockService::class ),
				$c->get( CorrelationId::class ),
				$c->get( Reporter::class ),
				self::paused( $c )
			)
		);
		$container->bind(
			EventPublisher::class,
			static fn( Container $c ): EventPublisher => new Publisher(
				$c->get( TransactionManager::class ),
				$c->get( Outbox::class ),
				$c->get( HookBridge::class ),
				$c->get( EventCatalog::class ),
				$c->get( CorrelationId::class ),
				$c->get( EventWake::class )
			)
		);
		$container->bind( OutboxCommand::class, static fn( Container $c ): OutboxCommand => new OutboxCommand( $c->get( OutboxDrainer::class ), $c->get( Outbox::class ), self::commandOutput() ) );
	}

	/**
	 * The jobs module: the queue on Action Scheduler, the handlers, the runner, the triggers, the events' wake and the jobs command.
	 *
	 * Each handler in JobHandlers::PRODUCTION is resolved from this container when one of its
	 * jobs runs, so each is bound here; a test holds the two lists equal. The runner and the
	 * triggers pause while Safe Mode is on.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function jobsRegister( Container $container ): void {
		$container->bind( JobHandlers::class, static fn( Container $c ): JobHandlers => new JobHandlers( JobHandlers::PRODUCTION, static fn( string $handler ): object => $c->get( $handler ) ) );
		$container->bind( ActionSchedulerQueue::class, static fn( Container $c ): ActionSchedulerQueue => new ActionSchedulerQueue( $c->get( Database::class ), $c->get( LockService::class ), $c->get( JobHandlers::class ), $c->get( CorrelationId::class ), $c->get( Reporter::class ) ) );
		$container->bind( JobQueue::class, static fn( Container $c ): JobQueue => $c->get( ActionSchedulerQueue::class ) );
		$container->bind(
			JobRunner::class,
			static fn( Container $c ): JobRunner => new JobRunner(
				$c->get( JobHandlers::class ),
				$c->get( ActionSchedulerQueue::class ),
				$c->get( CorrelationId::class ),
				$c->get( IdGenerator::class ),
				$c->get( Reporter::class ),
				self::paused( $c )
			)
		);
		$container->bind( EventWake::class, static fn( Container $c ): EventWake => new EventWake( $c->get( OutboxDrainer::class ), $c->get( JobQueue::class ), $c->get( IdGenerator::class ), $c->get( Reporter::class ) ) );
		$container->bind(
			RunnerTriggers::class,
			static fn( Container $c ): RunnerTriggers => new RunnerTriggers(
				$c->get( JobRunner::class ),
				$c->get( ActionSchedulerQueue::class ),
				$c->get( OutboxDrainer::class ),
				$c->get( LockService::class ),
				$c->get( Reporter::class ),
				self::paused( $c ),
				array( $c->get( EventWake::class ), 'handedOff' )
			)
		);
		$container->bind( JobsCommand::class, static fn( Container $c ): JobsCommand => new JobsCommand( $c->get( RunnerTriggers::class ), $c->get( JobQueue::class ), self::commandOutput() ) );
		$container->bind( OutboxCatchUp::class, static fn( Container $c ): OutboxCatchUp => new OutboxCatchUp( $c->get( OutboxDrainer::class ) ) );
		$container->bind( OutboxRetention::class, static fn( Container $c ): OutboxRetention => new OutboxRetention( $c->get( Outbox::class ) ) );
		$container->bind( MigrationAttempt::class, static fn( Container $c ): MigrationAttempt => new MigrationAttempt( $c->get( Migrator::class ) ) );
		$container->bind( JobHistoryCleanup::class, static fn( Container $c ): JobHistoryCleanup => new JobHistoryCleanup( $c->get( JobQueue::class ) ) );
		$container->bind( LogRetentionJob::class, static fn( Container $c ): LogRetentionJob => new LogRetentionJob( $c->get( LogRetention::class ) ) );
	}

	/**
	 * The jobs module's hooks: the job hook, on every request, and the repair of lost recurring runs where the library runs its queue.
	 *
	 * The job hook is added on every request, idle ones included, because whichever copy of Action
	 * Scheduler is in control may run the plugin's jobs in any request its runner starts, and a job
	 * whose hook has no callback is recorded as failed.
	 *
	 * The repair (RunnerTriggers::CRON_REPAIR_HOOKS) is added only where the library runs its
	 * queue and so can fire those hooks: a WP-Cron run, an AJAX request (the library's asynchronous
	 * runner is one) and a WP-CLI run (its own `action-scheduler run` command). Added on every
	 * request it would cost two idle hooks that almost never fire. A site whose WP-Cron runs inside
	 * a front-end request (ALTERNATE_WP_CRON) misses the repair there; the admin tick and
	 * `wp seocart jobs run` repair the same loss.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 * @param bool      $runsQueue Whether this request can run the library's queue.
	 */
	private static function jobsSubscribe( Container $container, bool $runsQueue ): void {
		add_action(
			self::JOB_HOOK,
			static function ( $stored ) use ( $container ): void {
				$container->get( JobRunner::class )->run( $stored );
			}
		);

		if ( ! $runsQueue ) {
			return;
		}

		foreach ( RunnerTriggers::CRON_REPAIR_HOOKS as $hook => $priority ) {
			add_action(
				$hook,
				static function () use ( $container ): void {
					$container->get( RunnerTriggers::class )->repairRecurring();
				},
				$priority
			);
		}
	}

	/**
	 * The catalog module: the product repository, the sellability query, the product post gateway, the locale of a post, the product write, the product's REST controller and the editor's panel.
	 *
	 * The repository, the write and the panel read the store's base currency from the settings
	 * when they need it, never when they are built. Without a multilingual plugin every post has
	 * the site's locale. The gateway reports other plugins' save listeners under WP_DEBUG.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function catalogRegister( Container $container ): void {
		$container->bind(
			ProductRepository::class,
			static fn( Container $c ): ProductRepository => new MysqlProductRepository( $c->get( Database::class ), self::baseCurrency( $c ) )
		);
		$container->bind( Sellability::class, static fn( Container $c ): Sellability => new Sellability( $c->get( ProductRepository::class ) ) );
		$container->bind( PostGateway::class, static fn( Container $c ): PostGateway => new WordPressPostGateway( $c->get( TransactionManager::class ), $c->get( Reporter::class ), defined( 'WP_DEBUG' ) && WP_DEBUG ) );
		$container->bind( PostLocales::class, static fn(): PostLocales => new SiteLocale() );
		$container->bind(
			SaveProduct::class,
			static fn( Container $c ): SaveProduct => new SaveProduct(
				$c->get( ProductRepository::class ),
				$c->get( PostGateway::class ),
				$c->get( StockService::class ),
				$c->get( TransactionManager::class ),
				array( $c->get( LockService::class ), 'withLock' ),
				$c->get( EventPublisher::class ),
				$c->get( Sellability::class ),
				$c->get( PostLocales::class ),
				$c->get( Clock::class ),
				$c->get( IdGenerator::class ),
				self::baseCurrency( $c ),
				$c->get( Reporter::class )
			)
		);
		$container->bind(
			ProductPostsController::class,
			static fn( Container $c ): ProductPostsController => new ProductPostsController(
				ProductCapabilities::POST_TYPE,
				static fn(): SaveProduct => $c->get( SaveProduct::class ),
				$c->get( ProductRepository::class ),
				$c->get( Sellability::class ),
				$c->get( PostGateway::class ),
				$c->get( ErrorTranslator::class ),
				array( $c->get( Reporter::class ), 'unexpected' )
			)
		);
		$container->bind( ProductEditorPanel::class, static fn( Container $c ): ProductEditorPanel => new ProductEditorPanel( SEOCART_PLUGIN_FILE, self::baseCurrency( $c ) ) );
	}

	/**
	 * Puts the container's product controller into the product post type's controller slot. Runs whenever a REST server is built.
	 *
	 * WordPress would build the controller itself, from the post type's `rest_controller_class`,
	 * with the post type's name only. The kernel's `rest_api_init` callback calls this first, before
	 * core registers its routes at priority 99, so get_rest_controller() returns the instance built
	 * here with its services, and core's autosave and revision controllers, which take that as their
	 * parent when their routes are registered, use it too. The container builds the controller only
	 * when the post type is registered with it as its class. It adds no hook of its own, so an idle
	 * request pays nothing for it.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function catalogRestInit( Container $container ): void {
		ProductPostsController::install( ProductCapabilities::POST_TYPE, static fn(): ProductPostsController => $container->get( ProductPostsController::class ) );
	}

	/**
	 * Returns the store's base currency, read from its setting when it is asked for.
	 *
	 * Owns one fact: where every catalog service gets the base currency. It is the setting, read
	 * when a service asks for it, never when the service is built, so building one sends no query.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container the settings store comes from.
	 * @return \Closure(): Currency The reader.
	 */
	private static function baseCurrency( Container $container ): \Closure {
		return static fn(): Currency => Currency::of( (string) $container->get( SettingsStore::class )->value( InternationalSettings::BASE_CURRENCY ) );
	}

	/**
	 * The catalog module's hooks: the product post type, registered on every request's `init`, and in the admin the product editor's panel.
	 *
	 * The registration callback is the registration itself, which loads its own file and the
	 * capability map, builds nothing from the container and sends no query. The panel is enqueued
	 * on `enqueue_block_editor_assets`, which only the admin fires, and only for the product
	 * editor.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 * @param bool      $admin     Whether this is an admin request.
	 */
	private static function catalogSubscribe( Container $container, bool $admin ): void {
		add_action( 'init', array( ProductPostType::class, 'register' ) );

		if ( $admin ) {
			add_action(
				'enqueue_block_editor_assets',
				static function () use ( $container ): void {
					$container->get( ProductEditorPanel::class )->enqueue();
				}
			);
		}
	}

	/**
	 * The inventory module: the stock repository and service, the sweep of expired holds and the stock check of doctor.
	 *
	 * It adds no hook: the sweep runs through JOB_HOOK, and the check through doctor.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function inventoryRegister( Container $container ): void {
		$container->bind( MysqlStockRepository::class, static fn( Container $c ): MysqlStockRepository => new MysqlStockRepository( $c->get( Database::class ) ) );
		$container->bind( StockRepository::class, static fn( Container $c ): StockRepository => $c->get( MysqlStockRepository::class ) );
		$container->bind(
			StockService::class,
			static fn( Container $c ): StockService => new StockService(
				$c->get( StockRepository::class ),
				$c->get( TransactionManager::class ),
				$c->get( EventPublisher::class ),
				$c->get( IdGenerator::class ),
				$c->get( Clock::class ),
				$c->get( CorrelationId::class ),
				$c->get( Authorizer::class )
			)
		);
		$container->bind( SweepHolds::class, static fn( Container $c ): SweepHolds => new SweepHolds( $c->get( StockService::class ) ) );
		$container->bind( StockProjectionCheck::class, static fn( Container $c ): StockProjectionCheck => new StockProjectionCheck( $c->get( MysqlStockRepository::class ) ) );
	}

	/**
	 * The kernel: the boot record, the schema gate, Safe Mode, the lifecycle, the notices, Site Health and the Safe Mode command.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function kernelRegister( Container $container ): void {
		$container->bind( BootOption::class, static fn( Container $c ): BootOption => new BootOption( $c->get( Database::class ), $c->get( Reporter::class ) ) );
		$container->bind( SchemaGate::class, static fn( Container $c ): SchemaGate => new SchemaGate( $c->get( BootOption::class ), static fn(): Migrator => $c->get( Migrator::class ) ) );
		$container->bind( SafeMode::class, static fn( Container $c ): SafeMode => new SafeMode( $c->get( BootOption::class ), $c->get( Clock::class ), SafeMode::switchFromConstant() ) );
		$container->bind( Lifecycle::class, static fn( Container $c ): Lifecycle => new Lifecycle( $c, $c->get( Reporter::class ) ) );
		$container->bind(
			Notices::class,
			static fn( Container $c ): Notices => new Notices(
				$c->get( SchemaGate::class ),
				$c->get( SafeMode::class ),
				$c->get( BootOption::class ),
				static fn(): Migrator => $c->get( Migrator::class ),
				static fn(): Lifecycle => $c->get( Lifecycle::class ),
				static fn(): ?string => $c->get( SecretsCanary::class )->check()->cause()
			)
		);
		$container->bind(
			SiteHealth::class,
			static fn( Container $c ): SiteHealth => new SiteHealth(
				$c->get( SchemaGate::class ),
				static fn(): Notices => $c->get( Notices::class ),
				static fn(): SecretsStatus => $c->get( SecretsStatus::class ),
				static fn(): JobQueue => $c->get( JobQueue::class )
			)
		);
		$container->bind( SafeModeCommand::class, static fn( Container $c ): SafeModeCommand => new SafeModeCommand( $c->get( SafeMode::class ), self::commandOutput() ) );
	}

	/**
	 * The kernel's hooks: the REST routes and a REST request's reconciliation, reconciliation and
	 * the admin's own work, the notices and their actions, Site Health, the commands, and a
	 * network's site lifecycle.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 * @param bool      $admin     Whether this is an admin request.
	 * @param bool      $ajax      Whether this is an AJAX request.
	 * @param bool      $cli       Whether this is a WP-CLI run.
	 * @param bool      $cron      Whether this is a cron run.
	 * @param bool      $multisite Whether the site belongs to a network.
	 */
	private static function kernelSubscribe( Container $container, bool $admin, bool $ajax, bool $cli, bool $cron, bool $multisite ): void {
		$reconcile = static function () use ( $container ): void {
			$container->get( Lifecycle::class )->reconcile();
		};

		/*
		 * The routes are registered whenever a REST server is built, after the catalog has put its
		 * product controller where core looks for it. The site is reconciled only
		 * when the request is served by the REST API: a REST server built inside another request,
		 * such as a front-end page that preloads a route, must not install the plugin there, and
		 * an admin, command-line or cron request reconciles on its own hook.
		 */
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				self::catalogRestInit( $container );
				$container->get( RestAdapter::class )->register();

				if ( wp_is_serving_rest_request() ) {
					$container->get( Lifecycle::class )->reconcile();
				}
			}
		);

		if ( $admin ) {
			$notices = static function () use ( $container ): void {
				$container->get( Notices::class )->render();
			};

			add_action( 'admin_init', $reconcile );
			add_action( 'admin_notices', $notices );
			add_action( 'network_admin_notices', $notices );
			add_action(
				'admin_post_' . Notices::DISMISS_ACTION,
				static function () use ( $container ): void {
					$container->get( Notices::class )->handleDismiss();
				}
			);
			add_action(
				'admin_post_' . Notices::SAFE_MODE_ACTION,
				static function () use ( $container ): void {
					$container->get( Notices::class )->handleSafeModeChoice();
				}
			);
			add_action(
				'admin_post_' . Notices::RETRY_ACTION,
				static function () use ( $container ): void {
					$container->get( Notices::class )->handleRetry();
				}
			);
		}

		if ( $admin && ! $ajax ) {
			// After reconciliation: the secrets canary, whose failure enters Safe Mode, and the jobs tick at the end of the request.
			add_action(
				'admin_init',
				static function () use ( $container ): void {
					$container->get( SafeMode::class )->recordCanary( $container->get( SecretsCanary::class )->check()->ok() );
					$container->get( RunnerTriggers::class )->tickAtShutdown();
				}
			);
		}

		if ( $admin || $cron ) {
			// Site Health's screen, and the weekly check WordPress runs in a cron request.
			add_filter(
				'site_status_tests',
				static function ( $tests ) use ( $container ) {
					return $container->get( SiteHealth::class )->addTests( $tests );
				}
			);
		}

		if ( $cli ) {
			add_action( 'cli_init', $reconcile );
			add_action(
				'cli_init',
				static function () use ( $container ): void {
					self::registerCommands( $container, array( \WP_CLI::class, 'add_command' ), self::commandDisplay(), array( \WP_CLI::class, 'error' ) );
				}
			);
		}

		if ( $cron ) {
			add_action( 'init', $reconcile );
		}

		if ( $multisite ) {
			// After core's own initializer (priority 10), which creates the new site's tables and options.
			add_action(
				'wp_initialize_site',
				static function ( $site ) use ( $container ): void {
					$container->get( Lifecycle::class )->initializeSite( $site );
				},
				20
			);
			// Before core's own (priority 10), which drops the site's tables.
			add_action(
				'wp_uninitialize_site',
				static function ( $site ) use ( $container ): void {
					$container->get( Lifecycle::class )->uninitializeSite( $site );
				},
				5
			);
			add_filter(
				'wpmu_drop_tables',
				static function ( $tables, $siteId ) use ( $container ) {
					return $container->get( Lifecycle::class )->dropTables( $tables, $siteId );
				},
				10,
				2
			);
		}
	}

	/**
	 * Returns the pause switch of every service that reaches outside: whether Safe Mode is on, asked when the service asks.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 * @return \Closure(): bool The switch.
	 */
	private static function paused( Container $container ): \Closure {
		return static fn(): bool => $container->get( SafeMode::class )->isActive();
	}

	/**
	 * Returns what a maintenance command prints a line through: WP_CLI::log(), called only when the command runs.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(string): void The output.
	 */
	private static function commandOutput(): \Closure {
		return static function ( string $line ): void {
			\WP_CLI::log( $line );
		};
	}

	/**
	 * Returns how an operation's command prints its result: WP-CLI's formatter, in the format the user asked for.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(array<string, mixed>, string): void The display.
	 */
	private static function commandDisplay(): \Closure {
		return static function ( array $item, string $format ): void {
			$arguments = array( 'format' => $format );

			( new \WP_CLI\Formatter( $arguments, array_keys( $item ) ) )->display_item( $item );
		};
	}
}

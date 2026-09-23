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

use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Authorization\CapabilityMapper;
use SEOCart\Platform\Authorization\GrantLedger;
use SEOCart\Platform\Authorization\OptionGrantLedger;
use SEOCart\Platform\Authorization\RoleNames;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\DatabaseState;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Secrets\SecretsError;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Clock;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\SupportError;
use SEOCart\Support\SystemClock;
use SEOCart\Support\SystemIdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Binds every module's services into the container, and hooks every module into WordPress.
 *
 * Owns one fact: how the modules are wired. Each module has one pair of private methods here,
 * `{module}Register()` and `{module}Subscribe()`, so a module's wiring is in one place and a
 * change to it is one hunk. One file rather than a provider class per module, because every file
 * the plugin loads on an idle request counts against its budget.
 *
 * The rule that keeps an idle request cheap: register() only binds factories, and subscribe() only
 * adds hooks whose callbacks resolve a service when the hook fires. Neither builds a service,
 * reads an option, translates or computes an address. Hooks that only matter in the admin, on the
 * command line, in a cron run or on a network are added only there.
 *
 * It also keeps the two lists the kernel cannot derive at run time without loading every class:
 * the error catalogs and the domain events. Each has a test that holds it to the classes under
 * src/. The migration chain is not one of them: the migrator runs the data registry's migrations,
 * the one production list of what the plugin owns in a site.
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
		DatabaseError::class,
		KernelError::class,
		SecretsError::class,
		SettingsError::class,
		SupportError::class,
	);

	/**
	 * Every domain event class, from which the event catalog is built. None exists yet.
	 *
	 * @since 0.1.0
	 *
	 * @var list<class-string>
	 */
	public const EVENT_CLASSES = array();

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
	 * Binds every module's factories. Builds nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	public static function register( Container $container ): void {
		self::databaseRegister( $container );
		self::registryRegister( $container );
		self::authorizationRegister( $container );
		self::settingsRegister( $container );
		self::eventsRegister( $container );
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

		self::authorizationSubscribe( $container, $admin || $cli );
		self::kernelSubscribe( $container, $admin, $cli, wp_doing_cron(), is_multisite() );
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
			static function (): Database {
				global $wpdb;

				// The transaction guards throw where a developer will see it, and report everywhere else.
				return new Database( $wpdb, in_array( wp_get_environment_type(), array( 'local', 'development' ), true ), self::reporter() );
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
			static fn( Container $c ): Migrator => new Migrator( $c->get( Database::class ), $c->get( LockService::class ), $c->get( DatabaseState::class ), $c->get( DataRegistry::class )->migrations(), $c->get( Clock::class ), self::reporter() )
		);
		$container->bind( TransactionManager::class, static fn( Container $c ): TransactionManager => new GatedTransactionManager( $c->get( Database::class ), $c->get( SchemaGate::class ) ) );
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
	 * The settings module: the store that reads and writes every setting.
	 *
	 * The store is built over Settings::registry(), the production list and the only settings
	 * registry the plugin builds, so the registry itself is not a service here.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function settingsRegister( Container $container ): void {
		$container->bind( SettingsStore::class, static fn( Container $c ): SettingsStore => new SettingsStore( Settings::registry(), $c->get( Database::class ) ) );
	}

	/**
	 * The events module: the catalog, the outbox, the hook bridge, the drainer and the publisher, and the correlation id they carry.
	 *
	 * The publisher's wake schedules the drainer for the end of a request that stored events, so
	 * nothing is registered until something is published. The drainer pauses while Safe Mode is on.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function eventsRegister( Container $container ): void {
		$container->bind( CorrelationId::class, static fn( Container $c ): CorrelationId => new CorrelationId( $c->get( IdGenerator::class ) ) );
		$container->bind( EventCatalog::class, static fn(): EventCatalog => new EventCatalog( self::EVENT_CLASSES ) );
		$container->bind( Outbox::class, static fn( Container $c ): Outbox => new Outbox( $c->get( Database::class ) ) );
		$container->bind( HookBridge::class, static fn(): HookBridge => new HookBridge( self::reporter() ) );
		$container->bind(
			OutboxDrainer::class,
			static fn( Container $c ): OutboxDrainer => new OutboxDrainer(
				$c->get( Database::class ),
				$c->get( Outbox::class ),
				$c->get( HookBridge::class ),
				$c->get( EventCatalog::class ),
				$c->get( LockService::class ),
				$c->get( CorrelationId::class ),
				self::reporter(),
				static fn(): bool => $c->get( SafeMode::class )->isActive()
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
				static function () use ( $c ): void {
					$c->get( OutboxDrainer::class )->scheduleAtShutdown();
				}
			)
		);
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
	 * The kernel: the boot record, the schema gate, Safe Mode, the lifecycle and the notices.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 */
	private static function kernelRegister( Container $container ): void {
		$container->bind( BootOption::class, static fn( Container $c ): BootOption => new BootOption( $c->get( Database::class ), self::reporter() ) );
		$container->bind( SchemaGate::class, static fn( Container $c ): SchemaGate => new SchemaGate( $c->get( BootOption::class ), static fn(): Migrator => $c->get( Migrator::class ) ) );
		$container->bind( SafeMode::class, static fn( Container $c ): SafeMode => new SafeMode( $c->get( BootOption::class ), $c->get( Clock::class ), SafeMode::switchFromConstant() ) );
		$container->bind( Lifecycle::class, static fn( Container $c ): Lifecycle => new Lifecycle( $c, self::reporter() ) );
		$container->bind(
			Notices::class,
			static fn( Container $c ): Notices => new Notices(
				$c->get( SchemaGate::class ),
				$c->get( SafeMode::class ),
				$c->get( BootOption::class ),
				static fn(): Migrator => $c->get( Migrator::class ),
				static fn(): Lifecycle => $c->get( Lifecycle::class )
			)
		);
	}

	/**
	 * The kernel's hooks: reconciliation, the notices and their actions, and a network's site lifecycle.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container The container.
	 * @param bool      $admin     Whether this is an admin request.
	 * @param bool      $cli       Whether this is a WP-CLI run.
	 * @param bool      $cron      Whether this is a cron run.
	 * @param bool      $multisite Whether the site belongs to a network.
	 */
	private static function kernelSubscribe( Container $container, bool $admin, bool $cli, bool $cron, bool $multisite ): void {
		$reconcile = static function () use ( $container ): void {
			$container->get( Lifecycle::class )->reconcile();
		};

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

		if ( $cli ) {
			add_action( 'cli_init', $reconcile );
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
	 * Returns the reporter every service receives for what it reports rather than throws.
	 *
	 * It writes one line to the PHP error log until the logging module takes its place.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(string, array<string, mixed>): void The reporter.
	 */
	private static function reporter(): \Closure {
		return static function ( string $code, array $context ): void {
			error_log( 'SEOCart ' . $code . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the reporter until the logging module replaces it: a line for the site's operator, never shown to a visitor.
		};
	}
}

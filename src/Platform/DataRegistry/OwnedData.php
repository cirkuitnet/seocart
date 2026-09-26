<?php
/**
 * OwnedData: the production list of what each module owns in a site
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\DataRegistry;

use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Migrations\CreateCatalogTables;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Migrations\CreateStockTablesMigration;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\RateLimiter\Migrations\CreateRateCountersMigration;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Platform\Secrets\Migrations\CreateSecretKeysMigration;
use SEOCart\Platform\Secrets\SecretKeysTable;
use SEOCart\Platform\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the data registry the plugin runs with.
 *
 * Owns one fact: which modules contribute what to the site. The kernel reads this registry to
 * install each site and to know what exists at uninstall, `doctor --residue` reads it, and the
 * coverage tests apply its migrations to an empty database and compare the result with its
 * tables, so all of them see the same list. There is no second one.
 *
 * A module adds one line: a Contribution naming its tables beside the migrations that create
 * them, and its options and job groups, for example
 *
 *     new Contribution( tables: array( ExampleTable::definition() ), migrations: array( new CreateExampleTable() ) ),
 *
 * Capabilities and roles are not contributed: the registry takes the capability declaration
 * whole.
 *
 * @since 0.1.0
 */
final class OwnedData {

	/**
	 * Returns a new registry of everything the plugin owns in a site. Calls no WordPress function.
	 *
	 * @since 0.1.0
	 *
	 * @return DataRegistry The registry.
	 */
	public static function registry(): DataRegistry {
		return new DataRegistry(
			new CapabilityDeclaration(),
			new Contribution( tables: array( PlatformTables::migrations(), PlatformTables::locks() ), migrations: array( new PlatformBootstrapMigration() ) ),
			new Contribution( tables: array( OutboxTable::definition() ), migrations: array( new CreateOutboxMigration() ) ),
			new Contribution( options: Settings::registry()->optionDefinitions() ),
			new Contribution( options: array( new OptionDefinition( BootOption::NAME, 'Kernel', 'The installation record: the plugin version and schema head the site was installed to, how locks are held, the install identity and address Safe Mode compares with, and the recorded Safe Mode reason.', true, Classification::Public ) ) ),
			new Contribution( tables: array( SecretKeysTable::definition() ), migrations: array( new CreateSecretKeysMigration() ) ),
			new Contribution( tables: array( LogsTable::definition() ), migrations: array( new CreateLogsMigration() ) ),
			new Contribution( tables: CatalogTables::all(), migrations: array( new CreateCatalogTables() ) ),
			new Contribution( jobGroups: array( JobQueue::GROUP => 'Jobs' ) ),
			new Contribution( tables: InventoryTables::all(), migrations: array( new CreateStockTablesMigration() ) ),
			new Contribution( tables: array( RateCountersTable::definition() ), migrations: array( new CreateRateCountersMigration() ) ),
			new Contribution( tables: OrderTables::all(), migrations: array( new CreateOrderTables() ) ),
		);
	}
}

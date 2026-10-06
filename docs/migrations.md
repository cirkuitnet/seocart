# Migrations

SEOCart keeps its commerce data in its own tables, and a migration is the only thing that
creates or changes one. This page explains how a migration is declared and ordered, what the
migrator guarantees, how a table is declared once for both the migration and the data registry,
what `composer test:migration` proves, and how to add a table or a column.

The code is in `src/Platform/Database/`. The commands below were run on a disposable site, as the
administrator (`wp --user=admin`), with the table prefix `wp_docs_`. Your prefix will differ.

## Declaring a migration

A migration is a class that implements one of two interfaces, both of which extend `Migration`
(`src/Platform/Database/Migration.php`):

- **`SchemaMigration`** changes table structure. It is fast and synchronous.
- **`DataMigration`** rewrites existing rows in batches, and can be resumed.

Every migration has two things in common.

- **An id**, which is also its sort key: `YYYYMMDD_NNNN_name`, where the date is the day it was
  written, `NNNN` is a four-digit sequence, and the name is lower-case letters, digits and
  underscores. For example `20261001_0003_order_status_index`. The migrator refuses an id that
  does not match `^\d{8}_\d{4}_[a-z0-9_]+$`.
- **`canOperateHalfApplied()`**, which says whether the store may keep taking writes while the
  migration is pending, running or failed. Return `true` when the old code works without the
  change, such as an index that only speeds up a read. Return `false` when it does not: the store
  then refuses commerce writes until the migration completes (see
  [the schema gate](#the-boot-record-and-the-code-version)). There is no down-migration.

A `SchemaMigration` also has:

- **`tables()`**, which returns the `TableDefinition` of every table the migration creates or
  changes, **in the shape they end in**.
- **`up( SchemaOperations $operations )`**, which makes it so. It runs outside any transaction,
  because DDL commits implicitly, so it must be safe to run again. In practice it is one line:
  `$operations->createTables( $this->tables() );`.

A `DataMigration` has **`runBatch( ?string $cursor, Database $db ): ?string`**. The migrator calls
it with `null` for the first batch and then with the cursor the previous batch returned. It
returns the next cursor, or `null` when the migration is complete. Each batch runs in its own
transaction, together with the update of the migration's cursor, so a crash can neither skip a
batch nor replay one that committed. A batch must still be idempotent, because the whole unit can
be retried.

### How migrations are ordered and registered

A module registers its migrations in **one line** of `OwnedData::registry()`
(`src/Platform/DataRegistry/OwnedData.php`), beside the tables they create:

```php
new Contribution( tables: array( RateCountersTable::definition() ), migrations: array( new CreateRateCountersMigration() ) ),
```

The `DataRegistry` collects every contribution and sorts the migrations by id as strings, so the
order of the lines does not matter, and the id decides everything. A migration registered twice is
refused. The migrator also demands that the first migration of the chain is
`PlatformBootstrapMigration`, which creates the migrator's own tables, `migrations` and `locks`.

Two rules follow from ordering by id:

- **An id is append-only.** Never release a migration with an id that sorts before one that is
  already released. The per-request write gate decides from the schema head alone and counts every
  migration after the head as outstanding, so it cannot see a migration that sorts earlier, and
  the store keeps taking writes without it. `status()` and `wp seocart doctor` report such a
  migration as out of order, and `wp seocart migrate` still applies it, but nothing makes the
  store wait for it.
- **Never edit a migration that has shipped.** The migrator records the SHA-256 of the migration's
  class file when it runs, and reports a changed file on every later run. It is a report, not a
  failure, because the verifier guards the shape. A change belongs in a new migration.

Two tests keep the registration honest.
`tests/Unit/Platform/DataRegistry/RegisteredMigrationsTest.php` holds the registered chain equal,
in both directions, to every concrete migration class under `src/`, so a migration nobody
registered fails the build. `tests/Integration/Kernel/WiredMigrationsTest.php` proves the
migrator the kernel builds runs the registry's migrations and no others.

## What the migrator guarantees

`wp seocart migrate` (or an activation, or the kernel's own reconciliation) runs
`Migrator::migrate()`. Its rules are written at the top of `src/Platform/Database/Migrator.php`.

- **It is idempotent and re-runnable.** A migration is pending until its row in the `migrations`
  table says `applied`. A migration that is `running` (it was interrupted) or `failed` runs again
  on the next run, from the start, or from its cursor if it is a data migration. A run on an
  up-to-date site changes nothing:

    ```text
    $ wp seocart migrate
    Nothing to migrate: the schema is up to date.
    ```

    To see a failed migration run again, mark the newest one failed and ask the doctor. The ids,
    counts and timings in the output below are what the site this page was written on printed. A
    site gains tables and migrations with every release, so yours will print larger numbers and
    a different id:

    ```text
    $ wp db query "UPDATE $(wp db prefix)seocart_migrations SET state = 'failed', error_code = 'demo' ORDER BY migration_id DESC LIMIT 1"
    Success: Query succeeded. Rows affected: 1
    $ wp seocart doctor | head -4
    [ok]   schema: All 39 registered tables match their declarations, and no undeclared plugin table exists.
    [FAIL] migrations: The schema is not where the code expects it.
             - Migration 20261003_0001_payment_intent_mode failed. Run `wp seocart migrate` to see why and to try again.
             - Not applied: 20261003_0001_payment_intent_mode. Run `wp seocart migrate`.
    $ wp seocart migrate
    Applied 20261003_0001_payment_intent_mode in 25 ms.
    The schema is up to date.
    $ wp seocart doctor | head -3
    [ok]   schema: All 39 registered tables match their declarations, and no undeclared plugin table exists.
    [ok]   migrations: All 17 migrations are applied, and none changed since.
    [ok]   locks: No lock holds a lease that lapsed.
    ```

    The example marks the newest migration. Mark an older one failed instead and the doctor reports
    it out of order in place of the "Not applied" line, because a migration that sorts before the
    newest applied one is out of order (the first rule above):

    ```text
    - Migration 20261001_0003_order_status_index is out of order: it is not applied, but 20261003_0001_payment_intent_mode, which sorts after it, is. Apply it with `wp seocart migrate`. Commerce writes are not refused for it.
    ```

- **One runner at a time.** The migrator takes the lock named `schema`, waiting a bounded time
  (`--wait=<seconds>`, 30 by default). The lock is a lease of 300 seconds, renewed before every
  migration and every data batch. A runner that cannot take the lock reports "blocked", changes
  nothing and `wp seocart migrate` exits with status 2. Under the lock the migrator reads the
  `migrations` table again, so what another runner applied while this one waited is skipped, never
  repeated. How the lock is held depends on the host: with `GET_LOCK()` where a probe shows it can
  be trusted, and with a row in the `locks` table otherwise (`LockService`).
- **The bootstrap migration is the exception.** It creates the `locks` table, so it runs first and
  without a lock. If another runner creates the same table at the same moment, the "table exists"
  error (1050) is tolerated for that step only.
- **Never inside a transaction.** Calling the migrator inside one raises an error and sends
  nothing.
- **The result is verified, not trusted.** After a schema migration's `up()`, the migrator compares
  every table in `tables()` with what the server reports in `information_schema`: columns, types,
  nullability, defaults, collations, keys, engine. Any difference fails the migration. The `up()`
  call's own success, including `dbDelta()`'s, decides nothing.
- **A failure is recorded and stops the chain.** The migration's row becomes `failed`, with the
  machine code, the first 500 characters of the message and the post-condition diff. The chain stops,
  and `MigrationFailed` is thrown once the lock is released. `wp seocart migrate` then exits with
  status 1 and prints the migration's id, code and diff.
- **Every applied migration leaves a record**: its kind, state, timing, the plugin version that ran
  it, the checksum of its class file and, for a schema migration, the verified summary of each table.

Look at the record on your own site. The first five ids stay the first five, because ids are only
ever appended, so this shows the first five of what is one row per migration, in id order:

```text
$ wp db query "SELECT migration_id, kind, can_operate_half_applied AS half, state, plugin_version FROM $(wp db prefix)seocart_migrations ORDER BY migration_id LIMIT 5"
migration_id	kind	half	state	plugin_version
20260922_0001_platform_bootstrap	schema	0	applied	0.1.0
20260923_0001_events_outbox	schema	0	applied	0.1.0
20260923_0002_logging_logs	schema	1	applied	0.1.0
20260924_0001_inventory_stock	schema	0	applied	0.1.0
20260924_0001_secrets_keys	schema	0	applied	0.1.0
```

### The boot record and the code version

The code has a **version of its schema**: the id of the last migration in its chain
(`Migrator::codeHead()`). The site has a **schema head**: the id of the newest migration it has
applied. The kernel keeps the site's head in the installation record, the autoloaded option
`seocart_boot`, together with the plugin version that last completed an installation and how locks
are held on this host:

```text
$ wp eval '$r = json_decode( get_option( "seocart_boot" ), true ); echo wp_json_encode( array_intersect_key( $r, array_flip( array( "v", "plugin_version", "schema_head", "lock_mode" ) ) ) ), "\n";'
{"v":1,"plugin_version":"0.1.0","schema_head":"20261003_0001_payment_intent_mode","lock_mode":"get_lock"}
```

The head is the newest migration the site has applied. The same site's table says so too (what
your site prints is its own newest id, which moves with every release):

```text
$ wp db query "SELECT MAX(migration_id) AS newest FROM $(wp db prefix)seocart_migrations WHERE state = 'applied'"
newest
20261003_0001_payment_intent_mode
```

Because the head is cached there, every request can compare it with the code's at **no database
query**. That comparison is the schema gate (`src/Platform/Kernel/SchemaGate.php`), and it
answers one of four things:

| State           | Meaning                                                                                              |
| --------------- | ---------------------------------------------------------------------------------------------------- |
| `ready`         | The schema matches the code, or only migrations that allow trading while they run are pending        |
| `not_installed` | The site has no installation record yet, or it was lost                                              |
| `code_newer`    | The code has a migration the site lacks, and a migration that cannot operate half-applied is pending |
| `schema_newer`  | The site's head sorts after the code's: an older copy of the plugin is running on a newer schema     |

In any state but `ready`, every commerce write is refused with the error `store.unavailable`
(HTTP 503 on REST, the same error from an Ability, a message and a non-zero exit status from a
command). The refusal is made once, when the outermost transaction would begin, in
`GatedTransactionManager`, so no service can forget it. Reads are never refused.

A site gets back to `ready` by itself. Activation installs the site, and on every admin,
command-line, cron and REST request of a site whose record is missing or older than the code, the
kernel's reconciliation makes one bounded attempt to migrate, and queues a job to finish any data
migrations that remain. A failed migration is not retried by page loads, which would re-run the
failing statement on every one of them: the admin notice's Retry link, the job's own retries and
`wp seocart migrate` retry it on purpose.

## Declaring a table once

A table is declared by one `TableDefinition`
(`src/Platform/Database/Schema/TableDefinition.php`), built by a static factory in the module that
owns it. The migration that creates the table calls the factory from `tables()`. The data registry
lists the same factory's result in the module's `Contribution`. The generator that writes the
`CREATE TABLE` statement and the verifier read the storage shape from it, and the registry reads
the rest, so nothing about a table is written twice.
`src/Platform/RateLimiter/RateCountersTable.php` and
`src/Platform/RateLimiter/Migrations/CreateRateCountersMigration.php` are the smallest complete
example.

A declaration says all of this, and the constructor refuses one that is incomplete:

- **The name**, without the prefix, in lower-case snake case. The table on disk is
  `{$wpdb->prefix}seocart_{name}`.
- **The owning module and a one-sentence purpose.**
- **A mutation pattern**, one of five: `config` (merchant-authored, low volume),
  `mutable-transactional` (takes part in a money or stock transaction), `append-only` (a ledger:
  `INSERT` only), `queue` (work claimed by a worker) and `derived` (rebuildable, never the source of
  truth for a decision).
- **Every column**, as a `ColumnSpec`: its name, its type written the way MySQL reports it and in
  lower case (`bigint unsigned`, `varchar(191)`, `datetime(6)`), its classification, and a note
  saying what it holds. A declaration cannot express an `ENUM` column, a generated column or an
  expression default.
- **The primary key, the unique keys and the indexes.** Each unique key says which invariant it
  enforces, and each index says which query it serves.
- **A retention policy**, naming one of the policies in `RetentionCatalog`
  (`src/Platform/DataRegistry/RetentionCatalog.php`), or `permanent`.
- **An orphan policy** for each relationship worth one. There are no foreign keys.

### The classification of a column

Every column is declared with one of four classes (`Classification`): `public`, `pii` (identifies or
describes a person), `secret` (a credential or a key) or `financial` (a monetary record kept under
the financial retention policy). Log redaction (`src/Platform/Logging/Redactor.php`) reads these
classes, and nothing classifies a column a second time. No privacy exporter or eraser exists yet.
What REST shows of a field comes from the privacy class on its `FieldSpec`, not from a column's
class.

A `pii` column must also say how the privacy tools will treat it, and `ColumnSpec` refuses to be
built without that: `erasure` is `destroy`, `anonymize` or `retain` (with `retainedBecause` saying
why), and `notExportedBecause` says why an exporter would leave it out, if it does. The handling
is declared now so that the exporter and the eraser, when they are built, read the same
declarations. A column of any other class cannot carry that handling.
`tests/Integration/DataRegistry/CoverageTest.php` applies the registry's own migrations to an empty
database and checks that every table and column the server then has is registered and classified.

### Retention

The `RetentionCatalog` holds every policy a table may name, what it does and its default periods as
ISO 8601 durations: `logs` keeps 30 days, `outbox` deletes a dispatched event after 7 days and a
failed one after 90, `financial` anonymizes after 7 years, and so on. The retention settings, the
sweeps and the doctor checks take their periods from it. The registry refuses a table that names a
policy the catalog does not declare, and refuses a table that keeps its rows `permanent`ly, has a
`created_at` column and does not say in its purpose why: the purpose must read "… kept permanently
because …".

## What `composer test:migration` proves

`composer test:migration` runs the integration tests in the `migration` group against a real MySQL
database, and fails if the group selects nothing. Set the database up as
[development.md](development.md#set-up-the-integration-suite) describes. The group holds:

- **`tests/Integration/Database/MigratorTest.php`**: runs, resumes, failures, the schema lock, the
  write gate and the re-run of a migration whose tables already exist, using fixture migrations
  that create `test_` tables. Each test names the violation it was shown failing on.
- **`tests/Integration/Database/SchemaVerifierTest.php`**: the verifier notices each kind of
  difference on its own, from a table built by hand with exactly one deviation.
- **A test for most modules' migrations**, such as
  `tests/Integration/Inventory/StockTablesMigrationTest.php`: the tables are created exactly as
  declared, after the platform's, and a second run changes nothing and sends no DDL. The
  rate-limiter and secrets migrations have none of their own; a new migration should.
- **`tests/Integration/Order/OrderStatusIndexMigrationTest.php`**: a migration that adds an index
  to a table created before it existed, which is the pattern for changing a table.
- **The kernel's use of the migrator**: `WiredMigrationsTest`, `SchemaGateTest`, `LifecycleTest`,
  `ActivationRequestTest` and `MultisiteInstallTest` under `tests/Integration/Kernel/`, which prove
  the chain the kernel runs, the gate's refusal on every surface, activation in the request
  WordPress really makes, and the installation of each site of a network. The last one skips
  unless the suite runs as a multisite.

It does not prove that a migration is safe on a table with millions of rows, or how long it takes:
that is for the author and the reviewer to judge.

## Adding a table

1. **Declare the table.** Add a factory in the module's `Infrastructure` directory, following
   `RateCountersTable.php`: a `TableDefinition` with every column classified, every key and index
   explained, a retention policy that already exists in the `RetentionCatalog`, and, for a
   `pii` column, its erasure handling. A module with several tables gives one factory per table
   and an `all()` that returns them.
2. **Write the migration.** Add a class under the module's `Infrastructure/Migrations/` that
   implements `SchemaMigration`: an id of today's date and the next free sequence number,
   `canOperateHalfApplied()`, `tables()` returning your factory's result, and `up()` calling
   `createTables( $this->tables() )`. Copy `CreateRateCountersMigration.php`.
3. **Register both** in one `Contribution` line of `OwnedData::registry()`.
4. **Test it.** Add an integration test in group `migration` that follows
   `StockTablesMigrationTest.php`, and name the violation it fails on.
5. **Run** `composer test:unit` (the registry tests need no database), then
   `composer test:migration`. If the table is read by a new `SELECT`, `composer test:query-plans`
   checks that query's plan too.

## Adding a column

A column is a change to the table's declaration plus a migration that brings existing sites to it.

1. **Change the declaration** in the table's factory: add the `ColumnSpec`, with its classification,
   its note and, for a `pii` column, its erasure handling. The factory is the end state. A new site
   creates the table from it in the table's original migration, so it gets the column at once.
2. **Add a new migration** whose `tables()` returns that factory's table and whose `up()` calls
   `createTables( $this->tables() )`. For a table that already exists, `SchemaOperations` hands the
   statement to `dbDelta()`, which adds the missing column or index and changes nothing else, and on
   a new site the migration finds nothing to do and sends no DDL. `AddOrderStatusIndex`
   (`src/Order/Infrastructure/Migrations/AddOrderStatusIndex.php`) does exactly this for an index.
3. **Say whether the store may trade meanwhile.** Return `true` from `canOperateHalfApplied()` only
   if the code works, and the invariants hold, on a site that has not got the column yet. Otherwise
   return `false`, and the store refuses commerce writes until the migration is applied.
4. **Give a new column a default if it is `NOT NULL`**, so that existing rows satisfy it.
5. **Register** the migration in the module's `Contribution`, next to the one that created the
   table.
6. **Test the upgrade**, not only a new site: follow
   `tests/Integration/Order/OrderStatusIndexMigrationTest.php`, which builds the older state, runs
   the migration and checks the result against the declaration.

`dbDelta()` cannot drop, retype or rename a column, and nothing in the migrator does either yet.
That kind of change needs a different shape, such as adding the new column, copying the data with a
`DataMigration` while the store keeps trading, and removing the old column in a later release.
Decide it with the maintainers in an issue before you build it.

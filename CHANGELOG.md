# Changelog

All notable changes to SEOCart are documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/), and
the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before
version 1.0, a **minor** version may contain breaking changes and a **patch** version does
not; no compatibility promise exists until 1.0.

Every pull request that changes behaviour a merchant or a developer can see adds an entry
under `[Unreleased]`. The release process moves those entries under the new version heading.

## [Unreleased]

This is the platform foundation and the start of the catalog. **The plugin cannot sell
anything yet:** it registers a product post type, but nothing prices or stocks a product so
far, and it has no cart, checkout, orders, payments, blocks or admin screens of its own. It registers the store
settings route (`GET` and `PATCH`) and its two WP-CLI commands, the `wp seocart` maintenance
commands, three Site Health tests and its own background jobs. Activation installs the
plugin's database tables, its roles and capabilities, one installation record, a data key
for secret settings and the recurring jobs. The rest is the skeleton and the checks that
keep it releasable.

### Added

- The plugin main file, `seocart.php`. It checks the PHP and WordPress versions, registers a
  PSR-4 autoloader for the `SEOCart\` namespace, and hooks the kernel to `plugins_loaded`.
  On a site below PHP 8.3 or WordPress 7.1 it shows an admin notice and does not load.
- A kernel, `SEOCart\Platform\Kernel\Kernel`, that boots once per request and builds each
  module only when it is first used.
- An `uninstall.php` that deletes nothing. Uninstalling preserves store data by design.
- The planned directory layout under `src/`, `assets/`, `templates/`,
  `languages/` and `tests/`.
- Composer and npm tooling, with committed lockfiles. Runtime libraries are copied into
  `vendor-scoped/` and prefixed by Strauss; Action Scheduler is copied without a prefix.
- Quality gates: a PHP syntax check, PHP_CodeSniffer with the WordPress Coding Standards
  and PHPCompatibilityWP, PHPStan at level 5 with no baseline, ESLint, Stylelint and
  Prettier, and a compiled-asset size budget that runs on every build.
- A PHPUnit configuration with three suites: `unit` and `tools`, which never load WordPress,
  and `integration`, which runs against WordPress and a real MySQL database.
- A unit test that keeps the plugin header, the version constants, the package manifests
  and the requirements table in `README.md` in agreement.
- A custom PHP_CodeSniffer standard, `SEOCart`, with its own self-tests. It rejects a
  JSON-Schema array spelled out outside the schema module, a raw option write outside the
  settings registry, money arithmetic in an interface adapter, and `eval()`.
- The integration test harness: query, hook and file-load counters, and a test that holds
  an idle request to zero plugin queries, 15 loaded files and 25 registered hooks.
- The end-to-end harness: Playwright with the WordPress test utilities and axe-core, and a
  first test that finds SEOCart active on the Plugins screen.
- Scripts under `bin/dev/` that create and remove a disposable WordPress site or test
  database, optionally with WooCommerce or Polylang active, and a check that nothing is
  left behind.
- Release packaging: `bin/build-zip.php` builds a reproducible zip from `.distignore`, and
  `bin/check-zip.php` rejects a zip that is over budget or holds a file that must not ship.
- `readme.txt` for the WordPress.org directory, with checks for its headers, its build
  steps, the licences of bundled libraries, and an "External services" section generated
  from the one list of outbound endpoints.
- GitHub Actions workflows for pull requests, nightly runs, end-to-end tests, code scanning,
  releases and the WordPress.org deployment, and a script that lints them.
- Contributor documentation: `README.md`, `CONTRIBUTING.md`, `SECURITY.md`,
  `CODE_OF_CONDUCT.md`, issue templates and a pull request template that
  carries the definition of done.
- Developer documentation under `docs/`: development setup, testing and releasing.
- The capability model, not yet installed on activation: fine-grained `seocart_`
  capabilities that fail closed, role bundles for store staff and customers with an
  installer that never re-grants a capability a merchant removed, the product post type's
  capability map, one `map_meta_cap` callback, and a test that fails when a SEOCart REST
  route does not use the plugin's permission callback.
- The shared kernel under `SEOCart\Support`: exact money in integer minor units with
  checked overflow and largest-remainder allocation, fixed-scale decimals without floats or
  PHP extensions, the ISO 4217 currencies with their minor units, amounts carrying net, tax
  and gross, frozen exchange rates with a fingerprint, locales, addresses, time-ordered
  identifiers, and one table of error codes with its HTTP status and
  translatable message per code.
- The database layer, `SEOCart\Platform\Database`. It has
  three parts:
    - A transaction wrapper that nests with savepoints, refuses to commit after `$wpdb`
      reconnects or after a commit it did not send, and re-runs a deadlocked unit of work.
    - A lock service that uses `GET_LOCK` where a probe shows it can be trusted and a
      `locks` table otherwise.
    - A migrator that records each migration and checks every table against its declaration
      in `information_schema`. It runs as `wp seocart migrate`.
- The authorization check that application services use: the acting user or process is
  named explicitly, never assumed from the logged-in user, and an action it may not take
  fails with a "not allowed" error (HTTP 403) that names the capability it needs.
- The operations mechanism: one declaration per operation compiles its REST route, its
  ability, its WP-CLI command, its permission check, the privacy rules of its fields, and
  the generated OpenAPI document and reference pages (`docs/openapi.json`,
  `docs/reference/`). The first operations read and change the store settings.
- A data registry. It lists every table, migration, option, capability, role and job group
  the plugin owns, the privacy handling of each personal-data column, and the retention
  policies. Tests fail when a site holds a plugin table, column or option that nothing
  registered.
- One documented error shape for the REST API, abilities and WP-CLI commands: every error
  carries `status`, `details` and `correlation_id`. Internal failures, such as database
  errors, reach a client only as a generic message with the correlation id, and their
  details go to the error log. Every operation response is sent with
  `Cache-Control: no-store, private`, plus `Vary: Cookie` when the request was
  cookie-authenticated.
- Domain events and a transactional outbox. An event that must not be lost is stored in the
  same transaction as the change that raised it and is delivered after the commit, at least
  once, as a `seocart_` action, with each listener contained so one failure cannot stop the
  others. A delivery that fails is retried after 1, 4, 16 and 60 minutes, then parked. `wp
seocart outbox drain|status|prune` runs and inspects delivery.
- A typed settings registry. Every plugin option is declared
  once, listed in the data registry and written only through the settings store, and none
  is autoloaded. An independent setting has an option of its own; settings that belong
  together share one versioned document saved by compare-and-swap. The first settings are
  the store's base currency (default USD) and the capability installer's per-site record of
  what it has granted.
- Encrypted secret settings. A setting classed as secret is sealed with XChaCha20-Poly1305
  under a random data key, which `SEOCART_ENCRYPTION_KEY` in `wp-config.php` wraps when it
  is defined; the WordPress salts are never used. A canary record, a Site Health test and
  `wp seocart secrets status|rotate|rekey` report, rotate and re-seal the keys. No read,
  error, log line or command ever prints a secret.
- A logger and `wp seocart doctor`. Log lines go to a new `logs` table, kept 30 days, each
  with the correlation id of the request that caused it. Personal data is redacted and
  secrets are dropped according to the plugin's own data declarations, card numbers are
  removed from every line, and a line written by work that is later rolled back is still
  kept. `wp seocart doctor` checks the schema, the migrations, the locks and the event
  outbox without changing anything, and exits non-zero when it finds a problem.
- Installation. Activating the plugin installs it on the current site (and on each new site
  of a network as it is created): its database tables, the store roles and capabilities,
  and one small installation record, the only option the plugin autoloads. Deactivating and
  uninstalling remove nothing, and a capability the site owner took away is never granted
  again. While the database schema and the code disagree, the store refuses every change
  with `store.unavailable` (HTTP 503) and says so in an admin notice. When the site's
  address changes, or the site looks like a copy, Safe Mode stops the copy from acting as
  the store until an administrator confirms it.
- Background jobs on the bundled Action Scheduler. A job with a key is queued once; a
  failing job is retried, then recorded as failed and counted; jobs run on WP-Cron, from `wp
seocart jobs run` and from a short tick on admin requests; `wp seocart jobs status`
  reports them; and cleanup touches only the plugin's own jobs, never another plugin's. The
  first jobs deliver missed events, prune the event outbox and apply database migrations a
  little at a time. A request that publishes events ends its response before their listeners
  run, or, where the server cannot end it early, hands their delivery to the job runner. The
  main file now loads the bundled Action Scheduler so that it takes part in choosing the
  newest copy on the site.
- `wp seocart doctor` now checks background jobs: that a runner has started one recently,
  that a supported copy of Action Scheduler with its own store is in control, and that
  none has failed. `--residue` also lists the plugin's leftover jobs. Log lines past
  their retention period are deleted by a daily job.
- The foundation modules now run in the plugin. `GET`/`PATCH /seocart/v1/settings` and
  `wp seocart settings get|update` read and change the store settings for users with
  `seocart_manage_settings`. `wp seocart migrate|safe-mode|outbox|jobs|doctor|secrets` are
  available. Background jobs run on WP-Cron, from admin requests and from
  `wp seocart jobs run`. Site Health shows SEOCart's database schema, stored secrets and
  background jobs. When the stored secrets can't be opened, Safe Mode turns on and says why.
  Deactivating cancels the plugin's own background jobs and changes nothing else. An idle
  front-end request still runs no plugin query.
- An operation whose input or output holds personal data or a secret can't be exposed to
  agents.
- The `seocart_product` post type, served at `wp/v2/seocart-products` by WordPress's own
  controller for now, and the catalog's tables for products, the posts they are bound to,
  variants and prices. Nothing writes a product yet, so a product post created now is not
  yet a product and cannot be sold. Deactivating the plugin removes the product permalinks;
  reactivating adds them back.

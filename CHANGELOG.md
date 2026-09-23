# Changelog

All notable changes to SEOCart are documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/), and
the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before
version 1.0, a **minor** version may contain breaking changes and a **patch** version does
not; no compatibility promise exists until 1.0.

Every pull request that changes behaviour a merchant or a developer can see adds an entry
under `[Unreleased]`. The release process moves those entries under the new version heading.

## [Unreleased]

This is the repository bootstrap. **The plugin has no store features yet:** it has no
products, cart, checkout, orders or payments, it creates no database tables, options, roles
or scheduled jobs, and it registers no REST route, block, admin screen or WP-CLI command.
What exists is the skeleton that the features will be built on, and the checks that keep it
releasable.

### Added

- The plugin main file, `seocart.php`. It checks the PHP and WordPress versions, registers a
  PSR-4 autoloader for the `SEOCart\` namespace, and hooks the kernel to `plugins_loaded`.
  On a site below PHP 8.3 or WordPress 7.1 it shows an admin notice and does not load.
- A kernel, `SEOCart\Platform\Kernel\Kernel`, that boots once per request and registers
  nothing yet.
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
  and gross, frozen exchange rates with a fingerprint, locales, addresses, date ranges,
  time-ordered identifiers, and one table of error codes with its HTTP status and
  translatable message per code.

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
- An `uninstall.php` that deletes nothing. Uninstalling preserves store data by design
  (ADR-0009).
- The directory layout of the target architecture under `src/`, `assets/`, `templates/`,
  `languages/` and `tests/`.
- Composer and npm tooling, with committed lockfiles. Runtime libraries are copied into
  `vendor-scoped/` and prefixed by Strauss; Action Scheduler is copied without a prefix
  (ADR-0008).
- Quality gates: a PHP syntax check, PHP_CodeSniffer with the WordPress Coding Standards
  and PHPCompatibilityWP, PHPStan at level 5 with no baseline, ESLint, Stylelint and
  Prettier, and a compiled-asset size budget that runs on every build.
- A PHPUnit configuration with three suites: `unit` and `tools`, which never load WordPress,
  and `integration`, which runs against WordPress and a real MySQL database.
- A unit test that keeps the plugin header, the version constants and the package
  manifests in agreement.
- Contributor documentation: `README.md`, `CONTRIBUTING.md`, `SECURITY.md`,
  `CODE_OF_CONDUCT.md`, `AGENTS.md`, issue templates and a pull request template that
  carries the definition of done.
- Developer documentation under `docs/`: development setup, testing, releasing, the
  architecture reference and the architecture decision records (ADR-0001 to ADR-0021).

# Development Setup

This page takes you from a fresh clone to a working copy that passes every gate. It covers
both groups of contributors described in [CONTRIBUTING.md](../CONTRIBUTING.md): outside
contributors on their own machine, and the maintainers and their coding agents on the
project's development server.

## Requirements

| Tool      | Version                                       | Needed for                                                  |
| --------- | --------------------------------------------- | ----------------------------------------------------------- |
| PHP       | A supported version; 8.4 is used day to day   | Every Composer script                                       |
| Composer  | 2                                             | PHP dependencies, Strauss, the gate scripts                 |
| Node.js   | The major version in `.nvmrc`                 | The asset build, the JavaScript and CSS linters, Playwright |
| npm       | The version bundled with that Node.js         | Installing from `package-lock.json`                         |
| MySQL     | 8.4 recommended                               | The integration suite and any WordPress site                |
| WordPress | A supported version; 7.1.1 is used day to day | The integration suite, end-to-end tests, manual testing     |
| WP-CLI    | 2.12 or newer                                 | The provisioning scripts in `bin/dev/`                      |
| Git       | any current version                           |                                                             |

The supported PHP and WordPress versions are in the
[README requirements](../README.md#requirements). This table is the one place that states the
tool versions; the rest of this page refers to it.

You do not need all of it on one machine. See the next section.

## Where commands run

**PHP tooling runs where PHP is.** Every PHP tool — the syntax check, PHP_CodeSniffer,
PHPStan, PHPUnit, Strauss — is a Composer script, and you run it on a machine that has PHP
and the Composer dependencies installed. If your workstation has no PHP, keep your checkout
on a machine that does, or sync to one, and run the Composer scripts there.

JavaScript tooling — the build, ESLint, Stylelint, Prettier, Jest — runs anywhere Node.js is
installed. Playwright runs on any machine with a browser, against the URL of a running
WordPress site, so the browser and the site do not have to share a machine.

## Install

Follow the [quick start in the README](../README.md#quick-start). It has three steps after the
clone, and the rest of this section says what each one does and what it needs.

### What `composer install` does

`composer install` installs the development tools into `vendor/` and then runs the
`scope-vendor` script, which runs Strauss.

Strauss copies the plugin's **runtime** libraries into `vendor-scoped/` and prefixes their
namespaces, classes and constants, so that SEOCart's copy of a library can never collide with
another plugin's copy. `vendor-scoped/` is the only copy the plugin loads, in development and
in the release zip alike. It is generated, so it is never committed.

Two facts about that setup are easy to get wrong:

- **Strauss is configured in `composer.json`, under `extra.strauss`. There is no
  `strauss.json`**, because Strauss does not read one. Change the configuration in
  `composer.json` and nowhere else.
- **Action Scheduler is the deliberate exception.** It is copied into `vendor-scoped/`
  **without a prefix**, and it is kept out of the generated classmap. Action Scheduler
  negotiates, across every active plugin that bundles it, which copy loads; that negotiation
  depends on the real class names and on Action Scheduler's own loader. A prefixed copy would
  break it.
  [ADR-0008](adr/ADR-0008-background-jobs-run-on-action-scheduler-bundled-unscoped-behind-a-port.md)
  records the decision. Every other runtime library is prefixed.

Run `composer scope-vendor` to repeat the Strauss step on its own. After
`composer install --no-dev` Strauss is absent on purpose and the step skips; in any other
case a missing Strauss is an error.

`composer.json` pins `config.platform.php` to the lowest supported PHP version, so the
lockfile always resolves for that version, whatever version you run.

### What `npm ci` needs

Use the Node.js and npm versions in the [requirements table](#requirements). `npm ci` installs
exactly what `package-lock.json` records and fails if the lockfile and `package.json`
disagree.

**Regenerate `package-lock.json` only with those versions of Node.js and npm.** This rule
comes from a real failure: a lockfile written by npm 11 on Node.js 20 silently dropped 14
transitive packages, and `npm ci` then failed on Node.js 22. When you change a dependency, run
`npm install` with the right versions, commit the lockfile with the change, and confirm that
`npm ci` succeeds from a clean `node_modules/`.

### What `npm run build` does

It compiles `assets/` into `build/` with `@wordpress/scripts`, then runs
`bin/check-asset-budget.js`, which fails the build when a compiled file is larger, gzipped,
than its ceiling in `budget.json`. `build/` is generated and never committed.

## Turn Xdebug off for the gates

Xdebug makes PHP_CodeSniffer, PHPStan and PHPUnit many times slower, even when no debugger
is attached. Set `XDEBUG_MODE=off` for those commands:

```sh
XDEBUG_MODE=off composer cs
XDEBUG_MODE=off composer test:unit
```

Or export it once for the shell session. Turn it back on only when you are stepping through
a test.

## Run the gates

[testing.md](testing.md#commands) lists every gate command and says what each one runs. The
[README](../README.md#the-gates) names the two commands to run before every pull request.

## The unit suite needs no WordPress

`composer test:unit` and `composer test:tools` never load WordPress and need no database.
Their bootstrap, `tests/bootstrap-unit.php`, defines `ABSPATH` as a **placeholder** that
points at a directory that does not exist. Every file under `src/` starts with the
direct-access guard that the WordPress.org directory expects, and the placeholder lets those
files load. Because the path does not exist, a unit test that tries to include WordPress
fails loudly instead of working by accident.

## Set up the integration suite

`composer test:integration` loads WordPress through `wp-phpunit` and runs against a **real
MySQL database**. SQLite and WordPress Playground are not substitutes: the plugin's core
guarantees are InnoDB transaction semantics, and only a real MySQL or MariaDB server can
verify them.

The suite needs two things:

1. **A WordPress core checkout** that PHPUnit uses as `ABSPATH`. It is only read, never
   served and never changed, so one checkout per WordPress version can be shared by every
   working copy on the machine.
2. **A dedicated, empty database**, and a local configuration file that points at it:
   `tests/wp-tests-config.local.php`. That file is ignored by git, because it holds the
   database credentials. The test run **empties the database it is given**, so never point it
   at a database that holds anything you want to keep.

Create the configuration file in one of two ways:

- Run `bin/dev/provision-test-db.sh`. It creates the test database and a database user whose
  privileges are limited to that database, generates the credentials, and writes
  `tests/wp-tests-config.local.php` for you. It needs a MySQL account that is allowed to
  create databases and users. Read the usage notes at the top of the script.
- Or create an empty database yourself, copy `tests/wp-tests-config.template.php` to
  `tests/wp-tests-config.local.php`, and replace the tokens `__DB_NAME__`, `__DB_USER__`,
  `__DB_PASSWORD__`, `__DB_HOST__` and `__WP_CORE_DIR__`.

Continuous integration fills the same template with `bin/ci/prepare-integration.sh`, so the
template is the single description of what the suite needs.

## Disposable WordPress sites

Manual testing, the WordPress smoke pass in the definition of done, and the end-to-end suite
all need a running site with the plugin active. Do not use a site you care about.
`bin/dev/provision-site.sh` creates a **disposable** one:

- it creates a database for the site;
- it creates a WordPress install whose core files are copied from the shared core checkout,
  with its own `wp-config.php`, its own table prefix and fresh salts;
- it links your working copy into the site as `wp-content/plugins/seocart` — the link is set
  once and never repointed, so one site always serves one working copy;
- it installs WordPress with WP-CLI, activates SEOCart, and prints the site URL;
- it writes the generated administrator credentials to a file **outside the web root**,
  `~/.seocart-dev/instances/<slug>.env`, readable only by you (mode `600`), with the keys
  `WP_BASE_URL`, `WP_USERNAME` and `WP_PASSWORD`.

The script can also create a site with **WooCommerce active**, to exercise Action Scheduler
coexistence, and a site with **Polylang (free) active**, for the multilingual conformance
suite. Both plugins take over parts of a whole install, which is why they are only ever
activated on a disposable site. The usage notes at the top of the script list the options.
A teardown script in `bin/dev/` removes the site, its databases and its database users, and
leaves nothing behind.

Credentials for disposable sites are generated, never chosen and never committed. Do not
copy them into a tracked file, an issue, a pull request or a log.

### End-to-end tests

`npm run test:e2e` runs Playwright against a running site. It reads the same three variables
— `WP_BASE_URL`, `WP_USERNAME` and `WP_PASSWORD` — from the environment or from a `.env`
file in the repository root, which git ignores. Load the instance file that
`provision-site.sh` wrote, or copy its three values into `.env`.

## The two setups

### Maintainers and their coding agents

The maintainers work on a shared development server that has PHP, MySQL and WP-CLI but no
Docker:

- one canonical clone that always has `main` checked out, and is never itself activated as a
  plugin;
- one git worktree per task, next to the clone, on its own short-lived branch;
- one shared, read-only WordPress core checkout per WordPress version;
- per worktree, one test database for PHPUnit and one disposable WordPress site with its own
  database, both created and destroyed by the scripts in `bin/dev/`.

A shared plugin symlink that is repointed between branches is **not** used. A request that
is already running can resolve some files against the old target and some against the new
one, only one branch could be live at a time, and each branch needs its own database anyway.

Workstations that have Node.js but no PHP run the JavaScript tooling and Playwright locally
and run every Composer script on the server. That server is private; nothing about it is
needed to contribute.

### Outside contributors

Any local WordPress site that meets the [README requirements](../README.md#requirements)
works: place or symlink your clone at `wp-content/plugins/seocart`. If you have Docker,
[`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
is the standard way to get one: run `npx @wordpress/env start` in the clone, and it starts a
WordPress site with the current directory mounted as a plugin. The repository does not ship
a `.wp-env.json` yet, so set the WordPress and PHP versions yourself if the defaults are too
old or too new.

`wp-env` is a convenient way to run the plugin. It is not the project's source of truth for
integration tests; a real MySQL server is.

## Editor settings

The gated formatters are authoritative: PHP_CodeSniffer for PHP, and Prettier for JavaScript,
JSON, YAML, CSS and Markdown. `composer cs:fix` and `npm run format` apply their rules for
you, so run them instead of indenting by hand. `.editorconfig` only gives your editor
starting defaults; where it and a formatter disagree, the formatter wins, because the
formatter is what `composer cs` and `npm run format:check` enforce. `.gitattributes`
normalizes line endings to LF on every platform.

## Troubleshooting

| Symptom                                                                                                          | Cause and fix                                                                                                                              |
| ---------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| `npm ci` fails with missing or mismatched packages                                                               | The lockfile was written by the wrong npm. Regenerate it with the Node.js and npm versions in the requirements table                       |
| `scope-vendor: vendor/bin/strauss is missing`                                                                    | Run `composer install` without `--no-dev`                                                                                                  |
| PHP_CodeSniffer or PHPUnit is very slow                                                                          | Xdebug is loaded. Set `XDEBUG_MODE=off`                                                                                                    |
| A unit test fails with an error about a file under a directory named `__wordpress-is-not-loaded-in-unit-tests__` | The test, or the code under test, tried to load WordPress. Move the test to the integration suite, or put the WordPress call behind a port |
| `composer test:integration` cannot connect, or cannot find its configuration                                     | `tests/wp-tests-config.local.php` is missing or points at the wrong database or core checkout. See "Set up the integration suite"          |

# Testing

This page describes the test layers, lists every command, and states the rules that every
test and every gate must follow. For the setup that the integration and end-to-end suites
need, see [development.md](development.md).

The checklist a change must satisfy before review is the
[pull request template](../.github/pull_request_template.md). It is the only copy of the
definition of done, so it is not repeated here.

## Principles

1. **The architecture forces the shape of the pyramid.** Domain code calls no WordPress
   function, so the calculation pipeline, the state machines and most pricing, tax, shipping
   and promotion logic are tested as plain PHPUnit with no WordPress. Most of the valuable
   tests are fast unit tests. If a business rule needs WordPress or a database to be tested,
   the boundary is drawn in the wrong place.
2. **A real MySQL server is the source of truth for integration tests.** The plugin's core
   guarantees are InnoDB transaction semantics. SQLite and WordPress Playground cannot verify
   them and are never used as a correctness oracle.
3. **Money, time and identity are always test doubles.** A test never reads the real clock,
   never generates a random id, and never holds money in a float.
4. **No legacy code runs in this test suite.** The legacy engine is read for rules and edge
   cases. It is never called, imported or executed.
5. **Passing tests do not make a component done.** Static checks, tests, a WordPress smoke
   pass and an independent review are all required.

## Layers

From many, fast and cheap to few, slow and high in value:

| Layer                 | Tooling                                                             | Lives in                                                 | What belongs there                                                                                                                                                          |
| --------------------- | ------------------------------------------------------------------- | -------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unit                  | PHPUnit. No WordPress, no database                                  | `tests/Unit/`                                            | Domain and Application code, `src/Support/`: value objects, the calculation pipeline, state machines, promotion and stock rules                                             |
| Tool self-tests       | PHPUnit. No WordPress                                               | `tools/`                                                 | The custom PHP_CodeSniffer sniffs and the packaging, readme and licence checkers. Each one is shown to fail on a fixture that violates its rule                             |
| JavaScript unit       | Jest, through `@wordpress/scripts`                                  | `tests/JS/`, and `test/` beside the code under `assets/` | Client-side logic as plain functions: the asset-budget check, and the product editor panel in `assets/admin/product-editor/` (its amount and translation helpers)           |
| WordPress integration | PHPUnit with `wp-phpunit` and `WP_UnitTestCase`, against real MySQL | `tests/Integration/`                                     | Anything that needs a real `$wpdb`, real hooks or a real request lifecycle: repositories, migrations, transactions, the hook bridge, capabilities, REST routes, jobs, cache |
| API contract          | PHPUnit, group `contract`                                           | both PHPUnit suites                                      | The DRY derivation checks: every route, Ability and command resolves to one declaration; every compiled schema and declared example is valid                                |
| End-to-end            | Playwright with `@wordpress/e2e-test-utils-playwright` and axe      | `tests/E2E/`                                             | The smallest number of real journeys through a real browser against a real site. Accessibility assertions run in the same suite                                             |

### Unit tests

- Extend PHPUnit's `TestCase`. Never use `WP_UnitTestCase` here. The suite must run on a
  machine that has only PHP and Composer.
- Domain code depends on ports — interfaces declared in the module's own `Domain` namespace.
  Test against hand-written fakes that behave consistently, not against mocks that return a
  canned value.
- To unit-test a thin Infrastructure adapter that calls one WordPress function, stub that
  function with Brain Monkey. If an adapter needs more than two or three stubbed functions,
  the port is in the wrong place.
- The bootstrap defines a placeholder `ABSPATH`; see
  [development.md](development.md#the-unit-suite-needs-no-wordpress).

### Integration tests

- Extend `WP_UnitTestCase`. Write data through the real repository classes and the factories
  in `tests/Support/Factories/`, never through raw SQL, so that a test exercises the write
  path that production uses.
- Select specialised sets with PHPUnit groups: `concurrency`, `migration`, `performance`,
  `contract`, `reference-fixture`, `international` and `multilingual-conformance`. The
  commands below map to those groups.
- Never trust the return value of `dbDelta()`. A migration test inspects
  `information_schema` after the migration runs.
- Never assume that a persistent object cache is present. A cache miss must still return
  correct data.

### End-to-end tests

- Playwright targets a **real base URL**, given in `WP_BASE_URL`. Use a disposable site.
- The Store API spec places its own order: it creates a product through the REST API as the
  administrator, walks a guest's cart to a placed order, reads the order's status with its key,
  and moves the product to the trash.
- Two of its tests need data nothing in the Store API creates, and are skipped without it:
  `SEOCART_E2E_CODE` names an active promotion code with no usage limit and no fixed amount, and
  `SEOCART_E2E_CURRENCY` names a currency the site sells in besides its base currency, enabled
  with a rate in the current exchange-rate version. `tests/E2E/seed.php` plants both, and is
  not part of the release zip. Run it inside the site with `wp eval-file`, from the checkout; it
  is safe to run twice, and it prints the two variables, one `NAME=value` per line. It refuses a
  site that is not marked disposable, as `tests/Support/Seed/seed-site.php` does:

    ```sh
    wp --path=<the instance directory> eval-file tests/E2E/seed.php
    ```

    Export what it printed in the shell that runs `npm run test:e2e`. The test run stays
    green without them, with the two tests skipped. `SEOCART_E2E_REQUIRE_SEEDS=1` makes a missing
    seed fail them instead; the end-to-end workflow sets it, and passes the seed's output on to
    the suite itself.

- There is exactly one cart and checkout flow, so there is one end-to-end journey to
  maintain for it.
- Accessibility assertions use `@axe-core/playwright` in the same suite and gate on
  WCAG 2.2 AA. They complement a manual keyboard and screen-reader pass; they do not replace
  it.

The `assetBytes` fixture (`tests/E2E/fixtures/asset-bytes.ts`) asserts the performance budgets
in `budget.json`: it records every response one page load causes, attributes each to the
plugin by URL (the plugin's own directory under `wp-content/plugins/`, read from the site
rather than assumed), and sums the transferred (compressed) bytes.
`tests/E2E/specs/asset-budgets.spec.ts` holds the front page, an ordinary post and the
non-plugin admin screens to a budget of zero, and `npm run test:e2e -- asset-budgets` runs it
against the site in `WP_BASE_URL`. The attribution and summing logic also has its own
selftest, `tests/E2E/selftest/asset-bytes.spec.ts`, which needs no site.

## Commands

The script names below are defined in `composer.json` and `package.json`. Those two files are
the source of truth. Run `composer list` or `npm run` to see them with their descriptions.

### Composer

| Command                                  | What it runs                                                                                                    |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `composer validate --strict`             | Composer's own check of `composer.json` and `composer.lock`                                                     |
| `composer lint:php`                      | A syntax check of every PHP file, with parallel-lint                                                            |
| `composer cs`                            | PHP_CodeSniffer: the WordPress Coding Standards, PHPCompatibilityWP and the SEOCart DRY and compliance sniffs   |
| `composer cs:fix`                        | PHP Code Beautifier: fixes the violations that can be fixed automatically                                       |
| `composer cs:dry`                        | Only the SEOCart DRY and compliance sniffs, on `seocart.php`, `uninstall.php`, `src/` and `templates/`          |
| `composer stan`                          | PHPStan at the level set in `tools/phpstan/shared.neon`, which `phpstan.neon.dist` includes                     |
| `composer test`                          | `test:unit`, then `test:integration`                                                                            |
| `composer test:unit`                     | The `unit` suite. WordPress is never loaded                                                                     |
| `composer test:tools`                    | The `tools` suite: self-tests for the custom sniffs and checkers                                                |
| `composer test:integration`              | The `integration` suite, against WordPress and a real MySQL database                                            |
| `composer test:concurrency`              | The integration suite, group `concurrency`                                                                      |
| `composer test:migration`                | The integration suite, group `migration`                                                                        |
| `composer test:performance`              | The integration suite, group `performance`: query counts, autoload size and the idle-request budget             |
| `composer test:query-plans`              | `test:performance` with the query-plan run switched on: the medium reference dataset and the query-plan gate    |
| `composer test:contracts`                | Group `contract` in both suites: the DRY derivation checks                                                      |
| `composer test:contracts:unit`           | The unit half of that group; fails when the group selects no test                                               |
| `composer test:reference-fixtures`       | Group `reference-fixture` in both suites: hand-authored input and expected-output scenarios                     |
| `composer test:international`            | Group `international` in both suites: tax-inclusive pricing, multi-currency and multilingual scenarios          |
| `composer test:multilingual-conformance` | The integration suite, group `multilingual-conformance`, on a disposable site with a real multilingual plugin   |
| `composer docs:generate`                 | Regenerates every committed, generated document                                                                 |
| `composer docs:check`                    | The same generators in check mode. Fails on drift or on an unexpected skip, and prints the regeneration command |
| `composer wporg:check`                   | The WordPress.org directory checks that do not need a built zip                                                 |
| `composer licenses:check`                | Fails when a runtime dependency is not on the GPLv3-compatible licence allow-list                               |
| `composer gates`                         | Every gate that needs neither a database nor a browser, in one command                                          |
| `composer scope-vendor`                  | Runs Strauss. Not a gate; `composer install` runs it for you                                                    |
| `composer audit`                         | Composer's own check of the dependencies against known security advisories                                      |

A group command fails when its group selects no test, because an empty run proves nothing. Every
group has tests today, so if one fails for that reason, the group name or the test's `@group`
annotation is wrong.

A SEOCart extension runs these tools from SEOCart's checkout through one script,
`sh ../seocart/bin/ci/extension.sh <gate> .`, and so does its continuous integration;
[writing-an-extension.md](writing-an-extension.md) lists its gates.

### npm

| Command                     | What it runs                                                                          |
| --------------------------- | ------------------------------------------------------------------------------------- |
| `npm run build`             | Compiles `assets/` into `build/`, then checks the result against `budget.json`        |
| `npm run start`             | The same build in watch mode, without the budget check                                |
| `npm run lint`              | `lint:js`, `lint:css` and `format:check`                                              |
| `npm run lint:js`           | ESLint with the WordPress configuration                                               |
| `npm run lint:css`          | Stylelint with the WordPress configuration, on the CSS and SCSS files under `assets/` |
| `npm run format`            | Prettier, writing changes                                                             |
| `npm run format:check`      | Prettier, checking only. Covers JavaScript, JSON, YAML, CSS and Markdown              |
| `npm run test:unit`         | Jest                                                                                  |
| `npm run test:e2e`          | Playwright, against the site in `WP_BASE_URL`                                         |
| `npm run test:e2e:selftest` | The self-tests of the end-to-end harness. They need no site                           |
| `npm run typecheck`         | The TypeScript compiler, checking only, over the Playwright configuration and tests   |
| `npm run env:start`         | Starts a disposable `wp-env` site (Docker) with the checkout mounted as the plugin    |
| `npm run env:cli`           | Runs a command in that site, for example `npm run env:cli -- wp plugin list`          |
| `npm audit`                 | npm's own check of the dependencies against known security advisories                 |

## The baseline smoke tests

Seventeen checks answer one question: does the plugin start, install, migrate, refuse and keep
its data the way it promises? `bin/ci/smoke-suite.sh` runs them in order, prints one line each
(`ok`, `FAIL`, or `ci` for a check a CI job proves that the machine cannot run, where the suite
checks the thing it can) and exits non-zero when any fails. `sh bin/ci/smoke-suite.sh --list`
prints the live mapping without running it; the script is the source of truth, and this table
is a reading of it.

| #   | What it proves                                          | The command                                                                                                                                                                                                                |
| --- | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | A clean WordPress and PHP start                         | `install-smoke.sh`, the baseline step                                                                                                                                                                                      |
| 2   | Install and activation raise no notice under `WP_DEBUG` | `install-smoke.sh`: activation, and the debug log of the whole run                                                                                                                                                         |
| 3   | Activation and migrations run on an empty database      | `composer test:migration`, filtered to `LifecycleTest::test_activating_a_fresh_site_installs_it` and `MigratorTest::test_the_bootstrap_creates_the_platform_tables_and_records_itself`                                     |
| 4   | Deactivation leaves no broken hooks or corrupt data     | `install-smoke.sh` deactivation, and `composer test:integration` filtered to the two deactivation tests of `LifecycleTest`                                                                                                 |
| 5   | Reactivation succeeds                                   | `install-smoke.sh`, the reactivation step                                                                                                                                                                                  |
| 6   | Migrations are idempotent and can run again             | `composer test:migration`, filtered to `LifecycleTest::test_activating_again_changes_nothing` and `MigratorTest::test_a_chain_applies_in_order_once`                                                                       |
| 7   | An order is written and read back                       | `install-smoke.sh`: a guest orders through the Store API and reads the order back                                                                                                                                          |
| 8   | Authorization rejects unauthorized access               | `composer test:integration`, filtered to `AuthorizerTest`, `PermissionCallbackTest` and `OrderStatusRouteTest`                                                                                                             |
| 9   | Every REST route has an explicit permission behaviour   | `composer test:integration`, filtered to `RoutePermissionWalkTest`                                                                                                                                                         |
| 10  | Unrelated requests do not start expensive subsystems    | `composer test:integration`, filtered to `IdleBudgetTest`                                                                                                                                                                  |
| 11  | PHPCS passes                                            | `composer cs`                                                                                                                                                                                                              |
| 12  | Static analysis passes                                  | `composer stan`                                                                                                                                                                                                            |
| 13  | PHPUnit passes                                          | `composer test:unit`                                                                                                                                                                                                       |
| 14  | The WordPress integration tests pass                    | `composer test:integration`                                                                                                                                                                                                |
| 15  | The Playwright smoke test passes                        | `ci`: the end-to-end workflow runs it; the suite checks that the specs, the `test:e2e` script and the job's command exist                                                                                                  |
| 16  | Uninstall keeps the data, as the policy says            | `install-smoke.sh`, the uninstall step, and `composer test:integration` filtered to `LifecycleTest::test_deactivation_and_uninstallation_remove_nothing` and `CoverageTest::test_every_retention_policy_is_in_the_catalog` |
| 17  | A clean second site installs the packaged zip           | `install-smoke.sh`, the whole run                                                                                                                                                                                          |

Every test a check names gets a PHPUnit call of its own, and each call fails when its filter
selects no test (`--fail-on-empty-test-suite`), so a renamed test fails its check instead of hiding
behind one that still exists. `install-smoke.sh` runs once, for the first check that needs it, and
prints a `proved <name>` line for each fact; the checks read those lines. The same run also proves
the upgrade from an earlier release and a WordPress network (see
[releasing.md](releasing.md#prove-the-package)); the suite fails when either is missing.

On a development machine the suite builds the zip and the earlier zip itself, reads the database
of `install-smoke.sh` from `tests/wp-tests-config.local.php`, and runs the whole integration
suite, so it takes several minutes. `--only 3,9` runs just the checks numbered, to find out why
one of them fails; that is not "all 17 green".

In CI the `smoke-suite` job of `ci.yml` runs `smoke-suite.sh --report` and does no work the other
jobs did not do. It waits for the gate jobs and for `package`. Checks 11 to 14 take their result
from the jobs that ran `composer cs`, `stan`, `test:unit` and `test:integration`. Checks 1, 2, 5, 7
and 17, and the install half of 4 and 16, read the `proved` lines that every
`package / install-smoke` cell uploaded, and fail unless `package` succeeded: a cell that failed
uploads nothing, so the files that are there cannot vouch for it. Only the filtered PHPUnit checks (3, 4, 6, 8, 9, 10 and the
test half of 16) run in the job. Check 15 is the conclusion of the end-to-end workflow's run for the
commit, when there is one; a commit without a run reads `ci: not run for this commit`, never `ok`.
The job is not a required check.

## Static gates

- **Coding standards have no warning allowance.** `composer cs` must report nothing.
- **PHPStan starts at level 5 with no baseline.** A baseline file may exist only to
  grandfather issues at the moment the level is raised. It may only shrink, and new code is
  never added to it.
- **The DRY and compliance sniffs** fail a JSON Schema literal outside `Support\Schema`, a raw
  `update_option()` call on a plugin setting, `Money` arithmetic under `Interfaces\*`, and any
  use of `eval` or `create_function`. The fifteen DRY rules are rows of the
  [pull request template](../.github/pull_request_template.md).
- **Performance budgets are tests.** An idle request — one that touches no commerce — must add
  zero database queries and a bounded number of files and hooks, as
  `tests/Integration/Performance/IdleBudgetTest.php` states.
- **The WordPress.org gates** are described in [releasing.md](releasing.md).

## Reference datasets and query plans

`tests/Support/Seed/ReferenceSeed.php` writes a reference dataset: products with their posts
(every second one also in a second locale), variants, base-currency prices, stock items with
a ledger of merchant movements, a share of live and expired holds, carts with their lines (most
live, the rest expired), and single-use promotion codes. It writes no orders. It is deterministic: a
fixed RNG seed and one anchor time give byte-identical rows. It writes the tables directly
with multi-row `INSERT`s, for speed, so a seeded store is checked rather than trusted:
`wp seocart doctor` must pass, and the catalog must answer `sellable` for every seeded variant.
It is test infrastructure and is not in the release zip. It measures scale; the hand-built
representative catalogue of the [one-seed rule](#determinism-rules) is another thing.

| Dataset  | Products | Where it runs                                                                 |
| -------- | -------- | ----------------------------------------------------------------------------- |
| `small`  | 200      | Every integration run (`tests/Integration/Performance/ReferenceSeedTest.php`) |
| `medium` | 10,000   | The query-plan gate; it must be written in three minutes or less              |
| `large`  | 100,000  | Scale questions by hand; never a gate                                         |

`composer test:query-plans` runs the `performance` group with the query-plan run switched on.
That run seeds `medium` into its test database, prints how long seeding took, runs the
plugin's reads over a recording `wpdb`, and `EXPLAIN`s each plugin `SELECT` once for each length
of IN list it was sent with. It fails when
a plan reads a table of 10,000 rows or more with a full scan, or expects to examine more than
5,000 of its rows, unless the query is listed in `tests/query-plan-allow-list.json` with the
reason its plan is accepted; and it fails on a listed query that the run no longer sends or
that now keeps the rule; and it fails when the catalog's or the inventory's source writes a
`SELECT` the run did not send. Give it a test database of its own: the seed refuses a store that
already has products, and `WP_PHPUNIT__TESTS_CONFIG` names the configuration to use. CI runs it
as the required `query-plans` job. To seed a disposable development site by hand, run
`SEOCART_SEED_DISPOSABLE=1 SEOCART_SEED_DATASET=medium wp --path=<the instance directory> eval-file tests/Support/Seed/seed-site.php`
from the checkout; it refuses a site whose environment type is not `local` or `development`.

## The planted-violation rule

**A gate that cannot fail is worthless.** When you add or change a check — a sniff, a lint
rule, a drift test, a budget, a packaging check, a CI job — prove that it can fail:

1. Plant a violation of the rule.
2. Run the check and confirm that it fails, for the right reason, with a message that tells
   the reader what to do.
3. Remove the violation and confirm that the check passes.
4. Record both results — the command, the failing output and the passing output — in the
   pull request.

Where the check has self-tests, keep the planted violation as a permanent fixture, so that
the proof runs on every build. The sniff fixtures under `tools/` are violations on purpose,
which is why the main PHP_CodeSniffer and PHPStan runs exclude them.

The same rule applies to generators: a generator that skips something must fail. Skips are an
asserted set, not a line in a log.

## Determinism rules

A test that can fail because of when or where it ran is a defect in the test.

- **Time.** Production code reads time through the `Clock` port. Tests use a frozen or
  scripted clock. No test depends on the wall clock, on the server's time zone, or on
  `sleep()`.
- **Identity.** Production code creates ids through the `IdGenerator` port. Tests use a
  sequential generator, so that assertions on generated ids and order numbers are stable.
- **Money.** Compare money as integer minor units plus the ISO 4217 currency code. Never
  compare a monetary value as a float.
- **Network.** No test makes a real outbound request. Providers are fakes behind ports.
- **Order and isolation.** Every test passes alone and in any order. A test leaves no state
  behind in the database, the object cache or a global.
- **One seed.** The representative catalogue under `tests/Fixtures/Seed/` is the one seed for
  PHPUnit, for Playwright and for reference scenarios, so that "a representative store" means
  the same thing in every layer. The generated
  [reference datasets](#reference-datasets-and-query-plans) are for measuring scale, not for it.
- **Strict PHPUnit.** `phpunit.xml.dist` fails a run on a warning, on a risky test, on
  unexpected output and on a test that asserts nothing. Do not relax those settings to make a
  test pass.

## Where the suites run

| Suite or gate                         | On a development machine                                                           | In continuous integration                                                                                        |
| ------------------------------------- | ---------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Static gates, unit, tool self-tests   | Anywhere PHP and Composer are installed                                            | Every pull request, on each supported PHP version                                                                |
| JavaScript lint and unit              | Anywhere Node.js is installed                                                      | Every pull request                                                                                               |
| Integration and its groups            | Against a dedicated MySQL database                                                 | Every pull request, against a MySQL service, also as a multisite and, for the query plans, on the medium dataset |
| Action Scheduler coexistence          | In the integration suite, and by hand on a disposable site with WooCommerce active | In the integration suite (`tests/Integration/Jobs/CoexistenceTest.php`); no job installs WooCommerce             |
| Multilingual conformance              | On a disposable site with Polylang (free) active                                   | Every pull request and every push to main; not a required check                                                  |
| End-to-end and accessibility          | From any machine with a browser, against a disposable site                         | A secondary job against a disposable site                                                                        |
| Packaging and the WordPress.org gates | `composer wporg:check`, `composer licenses:check`, the zip scripts                 | Every pull request and every release                                                                             |
| Install smoke and the smoke suite     | `sh bin/ci/install-smoke.sh`, `sh bin/ci/smoke-suite.sh`                           | Every pull request: `package / install-smoke` on two PHP and WordPress pairs, and the `smoke-suite` job          |

`bin/ci/polylang-pin.env` states the one Polylang (free) version, and its checksum, that both
places install. To run the suite by hand: `sh bin/ci/download-polylang.sh <dir>` downloads
that version, verifies it, and prints the path of `polylang.php` in `<dir>` on stdout. Then
run `SEOCART_ML_ADAPTER=polylang SEOCART_ML_PLUGIN=<that path> composer
test:multilingual-conformance` against a real MySQL database. Leave either variable unset, or
point `SEOCART_ML_PLUGIN` at a path that is not readable, and every test in the group is
silently skipped, not failed: check the run's `Skipped:` count against its `Tests:` count.

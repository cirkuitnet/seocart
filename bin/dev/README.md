# bin/dev — disposable WordPress instances

Development-server tooling: one throwaway WordPress install and one PHPUnit database per
git worktree, created and removed by script. Nothing here ships in the release zip.

The scripts are POSIX `sh` (the dev server has no bash). They contain no host name,
address, account name or password: the base URL and the MySQL account that provisions
are read from the integration site at run time, the one per-server setting lives in an
untracked file, and every other secret is generated.

## Scripts

| Script                                                                                | What it does                                                                                                                                                                                                                                                                                                                                                                                                 |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `new-worktree.sh <slug> <branch>`                                                     | `git worktree add`, `composer install`, `npm ci`, then the two `provision-*` scripts. Prints the URL and the database names. Accepts `--with-woocommerce` and `--with-polylang`.                                                                                                                                                                                                                             |
| `provision-test-db.sh <slug> [--checkout=<path>]`                                     | Creates the database `<prefix>test_<slug>` and the account `seocart_test_<slug>`, then fills `tests/wp-tests-config.template.php` into `tests/wp-tests-config.local.php` (gitignored, mode 600). Safe to repeat: the password is replaced.                                                                                                                                                                   |
| `provision-site.sh <slug> [--checkout=<path>] [--with-woocommerce] [--with-polylang]` | Creates `<prefix>wt_<slug>` and its account, builds `<integration site>/_worktrees/<slug>/`, installs WordPress, activates SEOCart, writes the credentials file, then requests the home page and `wp-login.php` and fails unless both return 200. `--with-woocommerce` and `--with-polylang` (languages `en_US`, `en_GB`, `de_DE`, at the version `bin/ci/polylang-pin.env` pins) affect this instance only. |
| `teardown-site.sh <slug>`                                                             | Removes the instance directory, both databases, both accounts, the credentials file, the debug log and the checkout's local test configuration. Missing pieces are not an error.                                                                                                                                                                                                                             |
| `teardown-worktree.sh <slug> [--discard-changes]`                                     | Refuses a dirty worktree before touching the site, then runs `teardown-site.sh`, `git worktree remove` and `git branch -d` (which only deletes a merged branch). `--discard-changes` explicitly permits a forced removal.                                                                                                                                                                                    |
| `check-residue.sh <slug>`                                                             | The "no residue" gate, below.                                                                                                                                                                                                                                                                                                                                                                                |
| `selftest.sh`                                                                         | Tests the scripts as far as that is possible without MySQL, WP-CLI or a web server, in a sandbox. Run it after every change here.                                                                                                                                                                                                                                                                            |
| `lib.sh`                                                                              | Shared functions; sourced by the others. `polylang-languages.php` is run inside an instance by `--with-polylang`.                                                                                                                                                                                                                                                                                            |

Run them as `sh bin/dev/<script>`; none depends on its executable bit. Every script prints
its usage, the slug rules included, with `--help`. Exit codes: `0` done, `1` failed, `2`
usage error or invalid slug; `check-residue.sh` adds `3`.

A slug is lower-case `a-z`, `0-9` and `-`, and short: the limit (`SC_SLUG_MAX` in `lib.sh`,
printed by `--help`) keeps `wp_<slug>_` plus WooCommerce's longest table name inside MySQL's
64 characters, and the account name inside MySQL's 32.

## Prerequisites

- WP-CLI 2.12 or newer, the `mysql` client, `php`, `curl`, `git`, `base64`; `composer` and
  `npm` for `new-worktree.sh`.
- The integration site and the shared WordPress core checkout (paths: "Environment").
- **One grant, run once by a MySQL administrator**, for the account named by `DB_USER` in
  the integration site's `wp-config.php`:

    ```sql
    GRANT ALL PRIVILEGES ON `seocart\_%`.* TO '<account>'@'localhost' WITH GRANT OPTION;
    GRANT CREATE USER ON *.* TO '<account>'@'localhost';
    ```

    - `ALL PRIVILEGES` on the pattern: create and drop the per-slug databases, and nothing
      outside the pattern. The underscore is escaped so that the pattern does not also
      match `seocartX…`. With a database prefix (next item) the pattern is that prefix
      instead, for example `<account>\_seocart\_%`.
    - `WITH GRANT OPTION`: each instance gets its own account, limited to its own database,
      and only an account holding the grant option can hand that privilege on.
    - `CREATE USER`: create and drop those accounts. It is a global privilege — the holder
      can also alter or drop other ordinary accounts — so on a shared MySQL server use a
      dedicated provisioning account.
    - `partial_revokes` must be `OFF` (the default); when it is on, MySQL reads the `%` in
      the pattern literally.

- **The database prefix, if the server demands one.** `<prefix>` is `seocart_` unless the
  MySQL administrator only grants names that start with something else, typically the
  account's own name. Then the untracked settings file `<state dir>/config` says so:

    ```
    SEOCART_DEV_DB_PREFIX=<account>_seocart_
    ```

    The file is parsed, never executed, and there is no environment override, so two
    shells cannot disagree about what a slug's databases are called. Every script
    compares the setting with the provisioning account's own grants (`SHOW GRANTS`)
    before it uses a database name, and stops if the two disagree: a wrong prefix must
    not make the residue gate look for the wrong names and report a clean state.
    Accounts are never prefixed.

Without the grant, the scripts stop at `CREATE DATABASE`, print the statements above and
leave nothing behind.

## What an instance looks like

```
<integration site>/_worktrees/<slug>/    served at <home URL>/_worktrees/<slug>/
  wp-admin/ wp-includes/ *.php            WordPress core, copied from the shared checkout
  wp-config.php                           generated (mode 600)
  .htaccess                               generated
  wp-content/themes/<default theme>/      copied
  wp-content/plugins/seocart  ->          the checkout (the only symlink; never re-pointed)
  wp-content/uploads/
<state dir>/instances/<slug>.env          WP_BASE_URL, WP_USERNAME, WP_PASSWORD (mode 600)
<state dir>/logs/<slug>-debug.log         WP_DEBUG_LOG, outside the web root (mode 600)
```

- **Core is copied, not symlinked** (about 50 MB). WordPress sets `ABSPATH` from `__DIR__`,
  and PHP resolves symlinks in `__DIR__`, so linked core would look for its configuration
  inside the shared checkout.
- **PHP must run as the owner of the files.** On the dev server it does: the virtual host
  hands PHP to an FPM pool that runs as the account owning the integration site. Nothing
  is therefore world-writable, `wp-config.php`, the credentials file and the debug log
  are readable by their owner alone, and teardown can delete whatever WordPress wrote.
  Where the web server's PHP runs as somebody else, the instance cannot read its
  `wp-config.php` and the smoke check fails; that setup is not supported, because files
  created by the other user could not be removed again without root.
- Passwords are generated from `/dev/urandom` and never printed or passed on a command
  line. The provisioning account reaches the `mysql` client through a temporary option
  file (mode 600, removed by a trap); the administrator password reaches WP-CLI the same
  way.
- **The instance is marked disposable and development-only.** `wp-config.php` carries three
  lines nothing else in `bin/dev` writes:
    - `define( 'WP_ENVIRONMENT_TYPE', 'development' );` — otherwise a fresh instance reports
      `production`, which the reference seed's guard refuses and which turns off the plugin's
      developer-only checks (strict transaction checks).
    - `define( 'SEOCART_ENCRYPTION_KEY', '<base64 of 32 bytes from /dev/urandom>' );` — the
      key `src/Platform/Secrets/EncryptionKey.php` reads. Generated fresh per instance, never
      printed or logged, and never anywhere but this one mode-600 file; teardown removes it
      with the rest of `wp-config.php`.
    - `putenv( 'SEOCART_SEED_DISPOSABLE=1' );` — `tests/Support/Seed/seed-site.php` reads
      `SEOCART_SEED_DISPOSABLE` with `getenv()`, not a constant, so it is set here with
      `putenv()` rather than `define()`; `wp eval-file tests/Support/Seed/seed-site.php` then
      seeds the instance without the caller passing that variable itself.
- The smoke check runs on the server. If the site's public name does not answer from
  there (split DNS, TLS terminated elsewhere), it asks the machine's own addresses for the
  same URL; `SEOCART_DEV_SMOKE_CONNECT_TO=<address>` names one explicitly.
- `wp seocart migrate` and `wp seocart test-seed` do not exist yet. `provision-site.sh` has
  one marked hook point for them.

## The residue guarantee

After `teardown-site.sh <slug>` (or `teardown-worktree.sh <slug>`), `check-residue.sh <slug>`
exits `0` only if none of these exists: the instance directory, the two databases, the two
MySQL accounts, the credentials file, the debug log, and a local test configuration that
still names the slug's account. Otherwise it lists what it found and exits `1`. What
exists for a slug is declared once, in `lib.sh`; teardown and the gate both walk that
declaration, so neither can know of an artefact the other does not.

The gate fails closed. If the provisioning account may not look at a database or at
accounts, the check is reported as `UNVERIFIED` and the exit code is `3`, not `0`;
`--allow-unverified` accepts that explicitly. Teardown refuses any slug that fails
validation, and removes a directory only if it resolves to exactly
`<integration site>/_worktrees/<slug>`.

The account check is not strictly read-only. Because the provisioning account cannot read
the `mysql` schema, it uses `ALTER USER IF EXISTS ... ACCOUNT UNLOCK` and reads the warning;
the only accounts it probes are ones these scripts create and never lock, so the statement
is a no-op for every account they own.

One limit: a local test configuration is looked for in the slug's worktree and in the
checkout the instance serves. If `provision-test-db.sh` configured a checkout somewhere
else, pass the same `--checkout=<path>` to `teardown-site.sh` and `check-residue.sh`;
they cannot find it on their own. What would be left behind names an account that no
longer exists.

## Environment

Every path is derived from `HOME`; the defaults are at the top of `lib.sh`.

| Variable                       | Meaning                                                                     |
| ------------------------------ | --------------------------------------------------------------------------- |
| `SEOCART_DEV_SITE_PATH`        | The integration site: provisioning account, base URL, web root              |
| `SEOCART_DEV_STATE_DIR`        | Credentials files, debug logs and the settings file `config`                |
| `SEOCART_DEV_WP_CORE_DIR`      | Shared WordPress core checkout                                              |
| `SEOCART_DEV_REPO`             | Canonical clone                                                             |
| `SEOCART_DEV_WORKTREES_ROOT`   | One worktree per slug                                                       |
| `SEOCART_DEV_MYSQL_SOCKET`     | Detected from the site's `DB_HOST`, else from PHP's `mysqli.default_socket` |
| `SEOCART_DEV_SMOKE_CONNECT_TO` | Address(es) to send the smoke check to; detected                            |
| `SEOCART_DEV_ADMIN_EMAIL`      | Administrator e-mail of new instances                                       |
| `SEOCART_DEV_WP`               | WP-CLI executable                                                           |

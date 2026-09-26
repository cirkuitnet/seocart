# Changelog

All notable changes to SEOCart are documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/), and
the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before
version 1.0, a **minor** version may contain breaking changes and a **patch** version does
not; no compatibility promise exists until 1.0.

Every pull request that changes behaviour a merchant or a developer can see adds an entry
under `[Unreleased]`. The release process moves those entries under the new version heading.

## [Unreleased]

This is the platform foundation and the start of the catalog and stock. **The plugin cannot
sell anything yet:** a product can be created and priced in the block editor, and stock is
kept per variant, but there is no cart, checkout, orders, payments, storefront blocks or
admin screen of its own. It registers the store settings route (`GET` and `PATCH`) and its
two WP-CLI commands, the stock adjustment route, ability and command, the product route at
`wp/v2/seocart-products`, the `wp seocart` maintenance commands, three Site Health tests
and its own background jobs. Activation installs the
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
- A generated hooks reference, `docs/reference/hooks.md`: the `seocart_` action fired for
  each domain event, with its delivery and payload, and every filter the plugin applies
  (today `seocart_end_response_early`). It is regenerated by `composer docs:generate` and
  checked for drift by `composer docs:check`.
- Stock for each product variant: a stock item per variant, an append-only stock ledger,
  expiring checkout holds and order allocations. Holds are taken without overselling, even
  under concurrent checkouts; an expired hold is reclaimed at once by the next checkout that
  needs its units and by a sweep every five minutes. Adjusting on-hand stock writes a ledger
  entry and fires `seocart_stock_adjusted`; reclaiming an expired hold fires
  `seocart_stock_hold_expired`. `wp seocart doctor` checks that every stock counter agrees
  with its rows.
- Stock adjustment, the first store operation offered on every surface from one
  declaration: `POST /seocart/v1/stock-items/{variant_id}/adjustments`, the ability
  `seocart/adjust-stock` and `wp seocart stock adjust`. It needs `seocart_manage_inventory`,
  on REST takes the variant from the URL only, and accepts an optional `expected_on_hand` so a
  retried request cannot apply twice. The ability is marked destructive and is not offered to
  agents.
- A product write service that saves a product's post and its commerce fields (SKU, a price
  in the store's base currency, a compare-at price and a weight) in one database
  transaction, gives the product's variant its stock item, and records
  `seocart_product_saved` through the outbox. A save that fails leaves a live product on
  sale as it was; a save cut short by a crash, a lost connection or another plugin ending
  the transaction leaves the product unsellable until it is saved again; a save without a
  price leaves the product incomplete.
- The block editor saves a product's title, content, SKU, price, compare-at price and
  weight in one request: `wp/v2/seocart-products` is served by the plugin's own posts
  controller, which adds a `seocart` object to product responses (with the product's sale
  status), and a Commerce panel in the product editor edits it. Autosaves and restoring a
  revision never touch the commerce fields.
- A product post created by a path the plugin doesn't own (`wp post create`, an import, a duplicator, another plugin) becomes an incomplete product that can't be sold until it is saved through the product editor or the REST API. Trashing a product releases its checkout holds, and deleting its post deletes the product, its commerce rows and its stock item while keeping the stock ledger, or refuses the delete when that can't be done cleanly, for example while an order holds its stock. If the product can't be changed after WordPress has deleted the post, the product is kept and `wp seocart doctor` reports it.
- `wp seocart doctor` also checks the catalog: products with no post, product posts with no product, bindings to deleted posts, variants with no stock item, stock items with no variant, saves left unfinished and other orphaned rows, plus third-party callbacks on the product save and delete hooks and, with a multilingual plugin, product posts whose binding disagrees with their translation group. `wp seocart doctor --repair` fixes what is structural and fully recoverable, then runs the checks again; it never changes a price, a variant, a product's source post or the stock ledger.
- A product can have one post per language. With Polylang active, a product's translations share its one SKU, price and stock, and the product endpoint sets and reads a post's language and the post it translates (`seocart.locale`, `seocart.translation_of`), which Polylang's free edition doesn't offer over REST. Deleting a translation removes it from the product only; deleting the product's source post hands the source to the oldest remaining published translation. A product is not for sale in a language it has no post in.
- Products are served at `/products/<slug>/`, with their archive at `/products/`, whatever the site's permalink front. `wp seocart doctor`'s runner check passes on a new site until one of SEOCart's jobs has waited longer than the runner's window.
- The Store API namespace, `seocart/store/v1`, and its session read (`GET /seocart/store/v1/session`), which returns a REST nonce and the signed-in user's id without ever setting a cookie. Every Store API write needs the `X-SEOCart-Store: 1` header, a valid nonce when the request carries a login cookie, and the cart token once a cart exists (the `seocart_cart_token` cookie, sent only by the write that creates a cart, or the `X-SEOCart-Cart-Token` header), and is rate-limited per client in a counter table (or the persistent object cache) that is emptied after a day; a proxy's forwarded address is trusted only when `SEOCART_TRUSTED_PROXIES` names the proxy. A request to an unknown path or with the wrong method under the plugin's namespaces answers in the plugin's error shape.
- The calculation pipeline, the one place that produces totals: a pure engine that prices a cart's lines in the store's base currency, applies promotion discounts and free shipping, adds shipping and fees, computes tax on net- or gross-authored prices, and allocates every rounded figure so that parts sum to their whole, with a trace of each step and rounding. It reads all prices in one query, takes its shipping and tax quotes from built-in flat-rate stand-ins for now, and is not yet reachable through the Store API.
- Orders, not yet created by any checkout: the order tables (lines, adjustments, tax components, addresses, totals snapshots with their calculation trace, an append-only event history), a status registry whose allowed transitions are enforced by the database update itself, order numbers allocated atomically (never reused, not guaranteed gapless), a guest access key stored only as a hash and valid for 72 hours, lookups by uuid only, and the frozen exchange-rate context each order references. An order copies every name and amount it displays, so later catalog changes never alter it. Three events: `seocart_order_created`, `seocart_order_placed` and `seocart_order_status_changed`.

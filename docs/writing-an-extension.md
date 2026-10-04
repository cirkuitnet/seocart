# Writing an extension

A SEOCart extension is a WordPress plugin of its own, in a repository of its own: a payment
gateway first, and later a carrier, an accounting link or another integration. It is free, it
is published on WordPress.org like SEOCart, its main file says `Requires Plugins: seocart`, and
its author is SEOCart.

This page shows how an extension's repository is made, tested, kept up to date with SEOCart and
released. Every command below was run as shown, and the output under it is what it printed.

## How an extension stays easy to keep

An extension holds almost nothing but its own code. Its coding standard, its static analysis
settings, its test tools, its packaging and its CI and release workflows all come from SEOCart,
at one pinned commit:

- The extension is checked out **beside SEOCart, as `seocart/`**: `~/dev/seocart` next to
  `~/dev/seocart-gateway-for-stripe` on a developer machine, `seocart/` next to `ext/` in CI.
- **One file pins the commit:** `seocart-core.env`, with the one line
  `SEOCART_CORE_REF=<40 hexadecimal digits>`. Scripts read it as data; it is never run.
- The extension's workflows call SEOCart's reusable workflows **at that same commit**, and
  `bin/ci/check-workflows.sh` fails when the two differ. `bin/dev/bump-core.sh` moves both.
- The extension installs **no development dependency**. PHPUnit, PHP_CodeSniffer, PHPStan and the
  PHP linter run from SEOCart's `vendor/`, through SEOCart's `bin/ci/extension.sh`, which is also
  what the extension's CI runs.
- SEOCart is always a **git checkout**, never a release zip: the integration bootstrap the
  extension's tests run on lives in SEOCart's `tests/`, which no zip or `git archive` contains.

So SEOCart needs `composer install` before an extension's gates can run, and its test database
(`sh bin/dev/provision-test-db.sh <slug>` in SEOCart, or `new-worktree.sh`, which runs it) before
the gates that load WordPress.

## Generate the repository

`bin/dev/new-extension.sh` in SEOCart writes the repository from the templates in
`bin/dev/extension-template/` and `bin/dev/extension-types/<type>/`, beside the SEOCart checkout
it runs from. The type and a label name the plugin: a `payments` extension labelled `Stripe` is
**SEOCart Gateway for Stripe**. WordPress.org makes a plugin's slug from its name when the plugin
is submitted, so the slug must be exactly the one WordPress makes of that name,
`seocart-gateway-for-stripe`; any other is refused. The extension is pinned to the commit the
SEOCart checkout is at.

The walkthrough generates a payments extension labelled `Example`. It ran on the development
server, in a directory where `seocart` is the SEOCart checkout; that directory is written as
`~/dev` below, and the commit hashes are those of the checkout it ran against. From the SEOCart
checkout, `~/dev/seocart`:

```text
$ sh bin/dev/new-extension.sh seocart-gateway-for-example --type=payments --label=Example
new-extension: wrote ~/dev/seocart-gateway-for-example (22 files, staged in a new git repository, nothing committed):
  .distignore
  .editorconfig
  .gitattributes
  .github/workflows/ci.yml
  .github/workflows/nightly.yml
  .github/workflows/release.yml
  .gitignore
  CHANGELOG.md
  LICENSE
  SECURITY.md
  bin/dev/bump-core.sh
  composer.json
  phpcs.xml.dist
  phpstan.neon.dist
  phpunit.xml.dist
  readme.txt
  seocart-core.env
  seocart-gateway-for-example.php
  src/Gateway.php
  tests/Integration/LoadsBesideSEOCartTest.php
  tests/Unit/PrivateReferencesTest.php
  tests/bootstrap.php
new-extension: pinned to SEOCart ab210646e1759327d543440500a77ae76ce3db44 (seocart-core.env and .github/workflows).
new-extension: note: SEOCart at this commit declares no registration action for payments extensions yet, so seocart-gateway-for-example.php registers nothing and src/ holds a placeholder. Generate again, or write the registration by hand, once SEOCart declares it.
new-extension: next: commit the files, then run the gates: sh ../seocart/bin/ci/extension.sh all . (from ~/dev/seocart-gateway-for-example).
```

A slug that is not the name's is refused before anything is written:

```text
$ sh bin/dev/new-extension.sh seocart-example --type=payments --label=Example
"seocart-example" is not the slug of the plugin's name, "SEOCart Gateway for Example": WordPress.org makes the slug from the name when the plugin is submitted, which gives "seocart-gateway-for-example". Use that slug, or the label whose name gives yours.
```

The files, staged in a new git repository and not yet committed:

```text
$ git status --short
A  .distignore
A  .editorconfig
A  .gitattributes
A  .github/workflows/ci.yml
A  .github/workflows/nightly.yml
A  .github/workflows/release.yml
A  .gitignore
A  CHANGELOG.md
A  LICENSE
A  SECURITY.md
A  bin/dev/bump-core.sh
A  composer.json
A  phpcs.xml.dist
A  phpstan.neon.dist
A  phpunit.xml.dist
A  readme.txt
A  seocart-core.env
A  seocart-gateway-for-example.php
A  src/Gateway.php
A  tests/Integration/LoadsBesideSEOCartTest.php
A  tests/Unit/PrivateReferencesTest.php
A  tests/bootstrap.php
```

| File                                                           | What it is                                                                                                                                                       |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `seocart-gateway-for-example.php`                              | The main file: the plugin header, an autoloader for its namespace (`SEOCart\GatewayForExample`, from `src/`) and its registration with SEOCart, and nothing else |
| `src/`                                                         | The extension's code                                                                                                                                             |
| `seocart-core.env`                                             | The pin                                                                                                                                                          |
| `phpcs.xml.dist`, `phpstan.neon.dist`                          | SEOCart's shared rules, included from `../seocart/tools/`, with the extension's own text domain, prefixes and paths                                              |
| `phpunit.xml.dist`, `tests/bootstrap.php`                      | The `unit` and `integration` suites, on SEOCart's bootstraps                                                                                                     |
| `tests/Unit/PrivateReferencesTest.php`                         | SEOCart's private-reference check, over this repository                                                                                                          |
| `tests/Integration/LoadsBesideSEOCartTest.php`                 | The extension loads beside SEOCart without a PHP error                                                                                                           |
| `.github/workflows/`                                           | `ci.yml`, `nightly.yml` and `release.yml`: a few lines each, calling SEOCart's workflows at the pin                                                              |
| `bin/dev/bump-core.sh`                                         | Moves the pin (below)                                                                                                                                            |
| `readme.txt`, `CHANGELOG.md`, `SECURITY.md`                    | The WordPress.org readme, the changelog and the security policy                                                                                                  |
| `LICENSE`, `composer.json`                                     | GPL-3.0-or-later; the package's metadata and its runtime requirement only                                                                                        |
| `.distignore`, `.gitattributes`, `.gitignore`, `.editorconfig` | What the zip and `git archive` leave out, and the editor settings                                                                                                |

The main file loads before SEOCart: WordPress loads active plugins in the order of their paths,
and `seocart-gateway-for-example/` sorts before `seocart/`. Nothing in it may use a SEOCart class,
function or constant at file scope. An idle request may cost an extension this one file and one
hook registration, and no query.

SEOCart at the commit this walkthrough is pinned to does not yet declare the action a payment
gateway registers on, so the generated main file registers nothing and `src/Gateway.php` holds a
placeholder; the generator says so. The registration is one `add_action()` that names the action
by its string, never by a SEOCart constant, because the main file runs before SEOCart is loaded.

## Run the gates

Commit the files first: the private-reference check reads what git tracks, and the zip takes its
timestamp from the last commit. Then run the gates from the extension's root, with
`bin/ci/extension.sh` from the SEOCart checkout beside it:

```text
$ git commit -q -m "Start the extension from SEOCart's template."
```

`all` runs every gate that needs no database:

```text
$ sh ../seocart/bin/ci/extension.sh all .

extension: php ~/dev/seocart/vendor/bin/parallel-lint --exclude ~/dev/seocart-gateway-for-example/.git --exclude ~/dev/seocart-gateway-for-example/dist --exclude ~/dev/seocart-gateway-for-example/vendor --exclude ~/dev/seocart-gateway-for-example/node_modules ~/dev/seocart-gateway-for-example
PHP 8.4.23 | 10 parallel jobs
.....                                                        5/5 (100%)


Checked 5 files in 0 seconds
No syntax error found

extension: php ~/dev/seocart/vendor/bin/phpcs
..... 5 / 5 (100%)


Time: 679ms; Memory: 18MB


extension: php ~/dev/seocart/vendor/bin/phpstan analyse --memory-limit=1536M
Note: Using configuration file ~/dev/seocart-gateway-for-example/phpstan.neon.dist.
 5/5 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%


 [OK] No errors


extension: php ~/dev/seocart/vendor/bin/phpunit --testsuite unit --fail-on-empty-test-suite
PHPUnit 9.6.36 by Sebastian Bergmann and contributors.

.                                                                   1 / 1 (100%)

Time: 00:00.017, Memory: 6.00 MB

OK (1 test, 1 assertion)

extension: php ~/dev/seocart/vendor/bin/phpunit --testsuite unit --filter PrivateReferencesTest --fail-on-empty-test-suite
PHPUnit 9.6.36 by Sebastian Bergmann and contributors.

.                                                                   1 / 1 (100%)

Time: 00:00.013, Memory: 6.00 MB

OK (1 test, 1 assertion)

extension: php ~/dev/seocart/bin/build-zip.php --plugin=~/dev/seocart-gateway-for-example
build-zip: wrote ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip
           6 files, 15709 bytes (0.01 MiB)
           sha256 39f6eb3e360e42dd811ca9a63bdfb02eaaa94a1052bc35573990f98d349f7b77

extension: php ~/dev/seocart/bin/check-zip.php --plugin=~/dev/seocart-gateway-for-example ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip
check-zip: OK ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip, 15709 bytes (budget 5242880, limit 10485760)

extension: every gate that needs no database passed for ~/dev/seocart-gateway-for-example.
```

| Gate                 | What it runs                                                                                                                        |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `lint`               | PHP's syntax check over every PHP file                                                                                              |
| `cs`                 | PHP_CodeSniffer with the extension's `phpcs.xml.dist`                                                                               |
| `stan`               | PHPStan with the extension's `phpstan.neon.dist`                                                                                    |
| `unit`               | The `unit` suite                                                                                                                    |
| `references`         | The private-reference check alone                                                                                                   |
| `zip`                | Builds the release zip into `dist/` and checks it, with SEOCart's `bin/build-zip.php --plugin=.` and `bin/check-zip.php --plugin=.` |
| `integration`        | The `integration` suite, on SEOCart's integration bootstrap, with the extension and then SEOCart loaded                             |
| `idle`               | SEOCart's idle-request probe with and without the extension: its share is at most one file, one hook registration and no query      |
| `conformance <type>` | SEOCart's conformance suite for the extension's type, once SEOCart has one                                                          |
| `version <tag>`      | The main file's `Version` and the readme's `Stable tag` equal the tag                                                               |
| `all`                | `lint`, `cs`, `stan`, `unit`, `references` and `zip`                                                                                |

The gates that load WordPress use SEOCart's test database:

```text
$ sh ../seocart/bin/ci/extension.sh integration .

extension: php ~/dev/seocart/vendor/bin/phpunit --testsuite integration --bootstrap ~/dev/seocart/tests/bootstrap-integration.php --fail-on-empty-test-suite
Installing...
Running as single site... To run multisite, use -c tests/phpunit/multisite.xml
Not running ajax tests. To execute these, use --group ajax.
Not running ms-files tests. To execute these, use --group ms-files.
Not running external-http tests. To execute these, use --group external-http.
PHPUnit 9.6.36 by Sebastian Bergmann and contributors.

.                                                                   1 / 1 (100%)

Time: 00:00.017, Memory: 52.50 MB

OK (1 test, 3 assertions)
```

```text
$ sh ../seocart/bin/ci/extension.sh idle .

extension: php ~/dev/seocart/vendor/bin/phpunit --testsuite integration --bootstrap tests/bootstrap-integration.php --group extension-idle --fail-on-empty-test-suite
Installing...
Running as single site... To run multisite, use -c tests/phpunit/multisite.xml
Not running ajax tests. To execute these, use --group ajax.
Not running ms-files tests. To execute these, use --group ms-files.
Not running external-http tests. To execute these, use --group external-http.
PHPUnit 9.6.36 by Sebastian Bergmann and contributors.

.                                                                   1 / 1 (100%)

Time: 00:02.224, Memory: 78.50 MB

OK (1 test, 8 assertions)
```

```text
$ sh ../seocart/bin/ci/extension.sh conformance . payments
extension: SEOCart at ab210646e1759327d543440500a77ae76ce3db44 has no conformance suite for payments extensions yet, so there is nothing to run. This step runs it once SEOCart names one (tools/Extension/ExtensionType.php).
```

When the SEOCart checkout is not at the pinned commit, each gate says so first and runs anyway;
CI always checks out the pin.

## Continuous integration

The generated `.github/workflows/ci.yml` is one job:

```yaml
jobs:
    ci:
        uses: cirkuitnet/seocart/.github/workflows/extension-ci.yml@<the pinned commit>
        with:
            extension-type: payments
```

SEOCart's `extension-ci.yml` checks the extension out into `ext/` and SEOCart into `seocart/` at
the pinned commit, and runs the gates above through `bin/ci/extension.sh`. Its jobs are the
extension's required checks, under the names GitHub gives a called workflow's jobs:
`ci / gates` (the workflow lint and pin check, `lint`, `cs`, `stan`, `references`),
`ci / unit-tests (PHP 8.3)`, `(PHP 8.4)` and `(PHP 8.5)`, `ci / integration-tests (WordPress 7.1)`
(`integration`, `idle` and `conformance` against MySQL), `ci / package` (`zip`) and
`ci / plugin-check` (WordPress's Plugin Check on the zip). `nightly.yml` runs the same workflow
every night against SEOCart's `main` branch instead of the pin, so a change in SEOCart that breaks
the extension shows there before the next bump.

The `gates` job lints the extension's workflows the way SEOCart lints its own. It needs
`actionlint` and `shellcheck`; with both on `PATH`, the same check runs locally (this run was on
a workstation, in the same layout):

```text
$ sh ../seocart/bin/ci/check-workflows.sh .

check-workflows: actionlint (3 workflow files)

check-workflows: shellcheck bin/**/*.sh (1 files)

check-workflows: shared setup, action pins and remaining agreement

check-workflows: PASS
```

## Protect the main branch

A maintainer applies SEOCart's ruleset for extensions to a new repository once, with the GitHub
CLI signed in with admin rights on it: no deletion, no force-push, linear history, and the checks
above required with the strict policy (`tools/github/extension-ruleset.json`). `--dry-run` prints
the requests and sends none:

```text
$ sh ../seocart/bin/dev/apply-ruleset.sh cirkuitnet/seocart-gateway-for-example --dry-run
apply-ruleset: dry run; nothing is sent.
apply-ruleset: would send: gh api repos/cirkuitnet/seocart-gateway-for-example/rulesets (GET, to find a ruleset named "main")
apply-ruleset: would send: gh api --method PUT repos/cirkuitnet/seocart-gateway-for-example/rulesets/<its id> --input ~/dev/seocart/tools/github/extension-ruleset.json, or, when there is none,
apply-ruleset: would send: gh api --method POST repos/cirkuitnet/seocart-gateway-for-example/rulesets --input ~/dev/seocart/tools/github/extension-ruleset.json
apply-ruleset: would send: gh api repos/cirkuitnet/seocart-gateway-for-example/rulesets/<its id> (GET, printed)
apply-ruleset: the body:
{
	"name": "main",
	"target": "branch",
	"enforcement": "active",
	"conditions": {
		"ref_name": {
			"exclude": [],
			"include": [ "~DEFAULT_BRANCH" ]
		}
	},
	"bypass_actors": [],
	"rules": [
		{
			"type": "deletion"
		},
		{
			"type": "non_fast_forward"
		},
		{
			"type": "required_linear_history"
		},
		{
			"type": "required_status_checks",
			"parameters": {
				"strict_required_status_checks_policy": true,
				"do_not_enforce_on_create": false,
				"required_status_checks": [
					{
						"context": "ci / gates",
						"integration_id": 15368
					},
					{
						"context": "ci / unit-tests (PHP 8.3)",
						"integration_id": 15368
					},
					{
						"context": "ci / unit-tests (PHP 8.4)",
						"integration_id": 15368
					},
					{
						"context": "ci / unit-tests (PHP 8.5)",
						"integration_id": 15368
					},
					{
						"context": "ci / integration-tests (WordPress 7.1)",
						"integration_id": 15368
					},
					{
						"context": "ci / package",
						"integration_id": 15368
					},
					{
						"context": "ci / plugin-check",
						"integration_id": 15368
					}
				]
			}
		}
	]
}
```

## Move to a newer SEOCart

Fetch the new commit into the SEOCart checkout, then move the pin with `bin/dev/bump-core.sh`.
It changes `seocart-core.env` and every call of SEOCart's workflows together, and refuses a
commit the SEOCart checkout does not have:

```text
$ sh bin/dev/bump-core.sh d02e9038af3e8ed8f9aa34a7108c14a2388c4cd2
bump-core: ~/dev/seocart-gateway-for-example now pins SEOCart d02e9038af3e8ed8f9aa34a7108c14a2388c4cd2 (was ab210646e1759327d543440500a77ae76ce3db44): seocart-core.env and 3 workflow call(s).
bump-core: next: git -C ~/dev/seocart checkout d02e9038af3e8ed8f9aa34a7108c14a2388c4cd2, run the gates (sh ~/dev/seocart/bin/ci/extension.sh all ~/dev/seocart-gateway-for-example), then commit.
```

```text
$ git diff --stat
 .github/workflows/ci.yml      | 2 +-
 .github/workflows/nightly.yml | 2 +-
 .github/workflows/release.yml | 2 +-
 seocart-core.env              | 2 +-
 4 files changed, 4 insertions(+), 4 deletions(-)
```

Check the SEOCart checkout out at the new commit, run the gates, and commit the change.

## Release

A release is a tag `vX.Y.Z` on `main`. Before tagging, raise the version in the main file's
`Version` header and the readme's `Stable tag`, and give it a section in `CHANGELOG.md`. The
`version` gate checks the first two against the tag:

```text
$ sh ../seocart/bin/ci/extension.sh version . v0.1.0

extension: php ~/dev/seocart/tools/extension.php check-version ~/dev/seocart-gateway-for-example v0.1.0
check-version: seocart-gateway-for-example.php and readme.txt state 0.1.0, as the tag does.
```

```text
$ sh ../seocart/bin/ci/extension.sh version . v0.2.0

extension: php ~/dev/seocart/tools/extension.php check-version ~/dev/seocart-gateway-for-example v0.2.0
check-version: seocart-gateway-for-example.php Version is 0.1.0, not 0.2.0 as the tag v0.2.0 says.
check-version: readme.txt Stable tag is 0.1.0, not 0.2.0 as the tag v0.2.0 says.
```

`zip` builds the zip the release publishes, with a `SHA256SUMS` file beside it:

```text
$ sh ../seocart/bin/ci/extension.sh zip .

extension: php ~/dev/seocart/bin/build-zip.php --plugin=~/dev/seocart-gateway-for-example
build-zip: wrote ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip
           6 files, 15709 bytes (0.01 MiB)
           sha256 39f6eb3e360e42dd811ca9a63bdfb02eaaa94a1052bc35573990f98d349f7b77

extension: php ~/dev/seocart/bin/check-zip.php --plugin=~/dev/seocart-gateway-for-example ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip
check-zip: OK ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip, 15709 bytes (budget 5242880, limit 10485760)
```

Pushing the tag starts `release.yml`, which calls SEOCart's `extension-release.yml` at the pin.
It refuses a tag that is not on `main`, has no successful CI run for its commit, does not raise
the version, or has no changelog section; then it builds and checks the zip, builds SEOCart's zip
at the pinned commit, installs both on a fresh WordPress site (`bin/ci/extension-install-smoke.sh`:
SEOCart first, then the extension, a front-page request, then the extension deactivated and
uninstalled with SEOCart still answering, and no line in the debug log), and publishes a GitHub
Release with the zip, `SHA256SUMS` and an attestation of the zip's build provenance. It does not
deploy to WordPress.org.

## Try it on a disposable site

`bin/dev/provision-site.sh <site slug> --with-extension=<path>` in SEOCart links the extension's
checkout into a disposable site beside SEOCart and activates it after SEOCart;
`bin/dev/teardown-site.sh <site slug>` removes the site and both links. See `bin/dev/README.md`.

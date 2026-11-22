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
- A payment extension's **live workflow**, which runs it against its provider's test environment,
  runs SEOCart's steps, set-up action and checks from that same checkout; the extension states
  only what is its provider's, in `tests/live.php` (see
  [The live workflow of a payment extension](#the-live-workflow-of-a-payment-extension)).

So SEOCart needs `composer install` before an extension's gates can run, and its test database
(`sh bin/dev/provision-test-db.sh <slug>` in SEOCart, or `new-worktree.sh`, which runs it) before
the gates that load WordPress.

## Generate the repository

`bin/dev/new-extension.sh` in SEOCart writes the repository from the templates in
`bin/dev/extension-template/` and `bin/dev/extension-types/<type>/`, beside the SEOCart checkout
it runs from. The type and a label name the plugin: a `payments` extension labelled `Stripe` is
**SEOCart Gateway for Stripe**. WordPress.org makes a plugin's slug from its name when the plugin
is submitted, so the slug must be exactly the one WordPress makes of that name,
`seocart-gateway-for-stripe`; any other is refused. A payments extension also has a gateway id,
which a payment intent and the ledger record: the label in lower-case snake_case (`Stripe` is `stripe`,
`Authorize.Net` is `authorize_net`), or the one `--gateway-id=<id>` names. SEOCart's contract
refuses an id that does not start with a letter, holds anything but lower-case letters, digits and
single underscores, or is longer than 32 characters, and the generator refuses it first, as it
refuses `stub`, the id of the stand-in gateway SEOCart registers itself, and `bank_transfer`,
`cheque` and `cash_on_delivery`, the ids of its offline payment methods. The
extension is pinned to the commit the SEOCart checkout is at.

The walkthrough generates a payments extension labelled `Example`. It ran on the development
server, in a directory where `seocart` is the SEOCart checkout; that directory is written as
`~/dev` below, and the commit hashes are those of the checkout it ran against. From the SEOCart
checkout, `~/dev/seocart`:

```text
$ sh bin/dev/new-extension.sh seocart-gateway-for-example --type=payments --label=Example
new-extension: wrote ~/dev/seocart-gateway-for-example (26 files, staged in a new git repository, nothing committed):
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
  docs/capabilities.md
  phpcs.xml.dist
  phpstan.neon.dist
  phpunit.xml.dist
  readme.txt
  seocart-core.env
  seocart-gateway-for-example.php
  src/Gateway.php
  tests/Integration/ConformanceTest.php
  tests/Integration/LoadsBesideSEOCartTest.php
  tests/Integration/RegistersWithSEOCartTest.php
  tests/Unit/PrivateReferencesTest.php
  tests/Unit/RegistrationTest.php
  tests/bootstrap.php
new-extension: pinned to SEOCart 7d4c6a0e966d44c2ecd6edbacfa23621772d5938 (seocart-core.env and .github/workflows).
new-extension: seocart-gateway-for-example.php hooks seocart_register_payment_gateways to register the gateway "example", written against contract 0.2.0; src/Gateway.php declares no capability yet.
new-extension: next: commit the files, then run the gates: sh ../seocart/bin/ci/extension.sh all . (from ~/dev/seocart-gateway-for-example).
```

A slug that is not the name's is refused before anything is written, and so is an id the
contract would refuse:

```text
$ sh bin/dev/new-extension.sh seocart-example --type=payments --label=Example
"seocart-example" is not the slug of the plugin's name, "SEOCart Gateway for Example": WordPress.org makes the slug from the name when the plugin is submitted, which gives "seocart-gateway-for-example". Use that slug, or the label whose name gives yours.
```

```text
$ sh bin/dev/new-extension.sh seocart-gateway-for-2checkout --type=payments --label=2Checkout
The label "2Checkout" gives the gateway id "2checkout", which is not one: lower-case letters and digits in words joined by underscores, starting with a letter, at most 32 characters, for example authorize_net. Name it with --gateway-id=<id>.
```

```text
$ sh bin/dev/new-extension.sh seocart-gateway-for-stub --type=payments --label=Stub
The label "Stub" gives the gateway id "stub", which is the stand-in gateway's id: SEOCart registers that gateway itself and would refuse a second gateway of that id. Name another with --gateway-id=<id>.
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
A  docs/capabilities.md
A  phpcs.xml.dist
A  phpstan.neon.dist
A  phpunit.xml.dist
A  readme.txt
A  seocart-core.env
A  seocart-gateway-for-example.php
A  src/Gateway.php
A  tests/Integration/ConformanceTest.php
A  tests/Integration/LoadsBesideSEOCartTest.php
A  tests/Integration/RegistersWithSEOCartTest.php
A  tests/Unit/PrivateReferencesTest.php
A  tests/Unit/RegistrationTest.php
A  tests/bootstrap.php
```

| File                                                           | What it is                                                                                                                                                       |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `seocart-gateway-for-example.php`                              | The main file: the plugin header, an autoloader for its namespace (`SEOCart\GatewayForExample`, from `src/`) and its registration with SEOCart, and nothing else |
| `src/Gateway.php`                                              | The gateway: a skeleton of the adapter that SEOCart accepts and never calls (below)                                                                              |
| `seocart-core.env`                                             | The pin                                                                                                                                                          |
| `phpcs.xml.dist`, `phpstan.neon.dist`                          | SEOCart's shared rules, included from `../seocart/tools/`, with the extension's own text domain, prefixes and paths                                              |
| `phpunit.xml.dist`, `tests/bootstrap.php`                      | The `unit` and `integration` suites, on SEOCart's bootstraps; a plain run leaves the `conformance` group out                                                     |
| `tests/Unit/PrivateReferencesTest.php`                         | SEOCart's private-reference check, over this repository                                                                                                          |
| `tests/Unit/RegistrationTest.php`                              | The main file's action string is the one SEOCart declares, and the gateway declares nothing it can do yet                                                        |
| `tests/Integration/LoadsBesideSEOCartTest.php`                 | The extension loads beside SEOCart without a PHP error                                                                                                           |
| `tests/Integration/RegistersWithSEOCartTest.php`               | SEOCart's own registry accepts the gateway, written against a contract version it supports, and does not offer it                                                |
| `tests/Integration/ConformanceTest.php`                        | SEOCart's payment gateway conformance suite on the gateway, which the `conformance` gate runs (below)                                                            |
| `docs/capabilities.md`                                         | What the gateway declares, generated from its `describe()` and checked by the `conformance` gate (below)                                                         |
| `.github/workflows/`                                           | `ci.yml`, `nightly.yml` and `release.yml`: a few lines each, calling SEOCart's workflows at the pin                                                              |
| `bin/dev/bump-core.sh`                                         | Moves the pin (below)                                                                                                                                            |
| `readme.txt`, `CHANGELOG.md`, `SECURITY.md`                    | The WordPress.org readme, the changelog and the security policy                                                                                                  |
| `LICENSE`, `composer.json`                                     | GPL-3.0-or-later; the package's metadata and its runtime requirement only                                                                                        |
| `.distignore`, `.gitattributes`, `.gitignore`, `.editorconfig` | What the zip and `git archive` leave out, and the editor settings                                                                                                |

The main file loads before SEOCart: WordPress loads active plugins in the order of their paths,
and `seocart-gateway-for-example/` sorts before `seocart/`. Nothing in it may use a SEOCart class,
function or constant at file scope. An idle request may cost an extension this one file and one
hook registration, and no query.

### The registration

The main file ends with the extension's one registration: a single `add_action()` that names
SEOCart's action by its string, never by a SEOCart constant, because the main file runs before
SEOCart is loaded. SEOCart fires the action, with its gateway registry, the first time something
needs a payment gateway, and never on a request that needs none. The callback asks the registry
for the gateway's context and registers the gateway with it; the registry refuses a gateway it
cannot use, with a line in SEOCart's log and never an error.

```text
$ tail -n 12 seocart-gateway-for-example.php
/*
 * The registration with SEOCart: one hook, named by its string. SEOCart fires this action, with
 * its gateway registry, the first time something needs a payment gateway, and never on a request
 * that needs none. The name is written out because this file runs before SEOCart is loaded;
 * tests/Unit/RegistrationTest.php holds it equal to the constant SEOCart declares it in.
 */
add_action(
	'seocart_register_payment_gateways',
	static function ( \SEOCart\Contracts\Payment\GatewayRegistry $registry ): void {
		$registry->register( new \SEOCart\GatewayForExample\Gateway( $registry->context( \SEOCart\GatewayForExample\Gateway::ID ) ) );
	}
);
```

The generator writes the string from `GatewayRegistry::ACTION` when it generates the extension,
and `tests/Unit/RegistrationTest.php` holds the two equal: a wrong string would register the
gateway on an action SEOCart never fires. The generated integration test asks SEOCart's registry
for the gateway and fails too (`payment.gateway_unavailable`); only a live site would stay
silent. The core repository's own tests also run the main file in a process where SEOCart does not exist, and fail when it uses a
SEOCart class, function or constant at file scope.

### The gateway skeleton

`src/Gateway.php` is the starting point of the adapter: a final class that implements
`SEOCart\Contracts\Payment\PaymentGateway`, built with the `ExtensionContext` SEOCart gives it
(its logger, clock, HTTP client and settings). Its `describe()` returns the gateway's
declaration: the id, the label (a closure around a translation call, run only when a screen shows
it), the type `payments`, **the contract version it was written against** (a literal, written
from `PaymentGateway::CONTRACT_VERSION` when the extension is generated, which SEOCart compares
with its own when the gateway registers and refuses the gateway on an incompatible one), one mode
(`test`), no settings, no hosts, and a capability matrix with no rows, which declares nothing.
SEOCart therefore registers the gateway, never takes a payment through it (placement refuses it,
before anything is written) and refuses every operation of it, as unsupported, before a call;
each operation in the skeleton is a short placeholder that writes a line in SEOCart's log and
throws a `LogicException`, and never reaches a provider.

To build the adapter, declare what the provider can do in `describe()` (its settings, its hosts,
its capability matrix and its idempotency profile), then replace the placeholders. A gateway with
settings, or with a second mode, gets a settings document in SEOCart.

Every `GatewayResult` an operation returns answers the request it was given: it carries the
request's `intentUuid`, the gateway's own id (the one `describe()` declares) as its `provider`, and
the operation asked: `authorize()` answers an authorization, `capture()` a capture, `refund()` and
`queryRefund()` a refund. The one exception is a void the provider did not make because the
authorization still stands: `void()` then answers with that authorization's approval. `query()`
answers with the operation the provider's state maps to. SEOCart refuses any other answer as the
gateway's error, as it treats a call the gateway did not answer: nothing of it is recorded, a
placement waits for the reconciliation run, a capture or a void asked again sends the same
idempotency key, and a refund stays claimed.

`authorize()` is given a `PaymentRequest`: the intent's uuid, which is its idempotency key, the
amount, the shopper's payment token (at most 1,024 characters, as the provider's script in the
browser made it), the mode, the order's uuid and number, the address a shopper asked to act comes
back to, and the order's billing contact, `$billing`: a `BillingContact` of the given and family
name, the company (`organization`), two address lines, the city, the region, the postcode and the
country, each plain text within the order's own limits (no Unicode control character, and no line
or paragraph separator), or null when a caller has none to give.
The contact is personal data. An adapter sends it only when its provider needs it, such as for its
address check, names it in the `dataSent` of the host that receives it, which the extension's readme
discloses, and never logs it: SEOCart's log writes the contact under the names it declares personal
data, each value redacted, and redacts its members copied out under their own names (`firstName`,
`organization` and the rest, as `BillingContact::LOGGED_AS` lists them), and a dump shows it masked,
but a field copied out under a name of the adapter's own is beyond that. A request an extension serialized before a member was added reads
back with that member's default: one kept from before the billing contact has none.

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
........                                                     8/8 (100%)


Checked 8 files in 0.1 seconds
No syntax error found

extension: php ~/dev/seocart/vendor/bin/phpcs
........ 8 / 8 (100%)


Time: 1.01 secs; Memory: 20MB


extension: php ~/dev/seocart/vendor/bin/phpstan analyse --memory-limit=1536M
Note: Using configuration file ~/dev/seocart-gateway-for-example/phpstan.neon.dist.
  8/8 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%


 [OK] No errors


extension: php ~/dev/seocart/vendor/bin/phpunit --testsuite unit --fail-on-empty-test-suite
PHPUnit 9.6.36 by Sebastian Bergmann and contributors.

....                                                                4 / 4 (100%)

Time: 00:00.033, Memory: 8.00 MB

OK (4 tests, 120 assertions)

extension: php ~/dev/seocart/vendor/bin/phpunit --testsuite unit --filter PrivateReferencesTest --fail-on-empty-test-suite
PHPUnit 9.6.36 by Sebastian Bergmann and contributors.

.                                                                   1 / 1 (100%)

Time: 00:00.016, Memory: 6.00 MB

OK (1 test, 1 assertion)

extension: php ~/dev/seocart/bin/build-zip.php --plugin=~/dev/seocart-gateway-for-example
build-zip: wrote ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip
           6 files, 17699 bytes (0.02 MiB)
           sha256 9e4aa9d02b75def20ea490b27c3c8adeea72a0b1f1f6eef83ba1c9ffa5e0da4f

extension: php ~/dev/seocart/bin/check-zip.php --plugin=~/dev/seocart-gateway-for-example ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip
check-zip: OK ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip, 17699 bytes (budget 5242880, limit 10485760)

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
| `conformance <type>` | SEOCart's conformance suite for the extension's type: its unit group, then the extension's conformance test                         |
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

..                                                                  2 / 2 (100%)

Time: 00:00.120, Memory: 58.50 MB

OK (2 tests, 10 assertions)
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

Time: 00:02.812, Memory: 88.50 MB

OK (1 test, 8 assertions)
```

```text
$ sh ../seocart/bin/ci/extension.sh conformance . payments

extension: composer test:conformance:payment
PHPUnit 9.6.36 by Sebastian Bergmann and contributors.

.................................................                 49 / 49 (100%)

Time: 00:00.877, Memory: 54.00 MB

OK (49 tests, 134 assertions)
Installing...
Running as single site... To run multisite, use -c tests/phpunit/multisite.xml
Not running ajax tests. To execute these, use --group ajax.
Not running ms-files tests. To execute these, use --group ms-files.
Not running external-http tests. To execute these, use --group external-http.
PHPUnit 9.6.36 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.23
Configuration: ~/dev/seocart-gateway-for-example/phpunit.xml.dist

S..SSSSSSSSSSSSS.                                                 17 / 17 (100%)

Time: 00:36.821, Memory: 70.50 MB

There were 14 skipped tests:

1) SEOCart\GatewayForExample\Tests\Integration\ConformanceTest::test_rule_01_ledger_shape
Conformance rule 1 (ledger shape): skipped: the gateway declares nothing yet; its capability matrix has no rows.

[… one entry for each rule the skeleton skips, each saying why …]

OK, but incomplete, skipped, or risky tests!
Tests: 17, Assertions: 70, Skipped: 14.
```

When the SEOCart checkout is not at the pinned commit, each gate says so first and runs anyway;
CI always checks out the pin.

## Hold the gateway to the conformance suite

SEOCart publishes a conformance suite for payment gateways: the rules every gateway is held to,
from the ledger an authorization, a capture and its refunds leave to a webhook delivery
verified before it is read. [docs/reference/gateways.md](reference/gateways.md) in SEOCart lists
them, with the proof of each. The generated `tests/Integration/ConformanceTest.php` is the
extension's test; without its docblocks it is five lines:

```php
final class ConformanceTest extends GatewayConformanceCase {
	protected function gatewayId(): string { return Gateway::ID; }
	protected function tapes(): string { return dirname( __DIR__ ) . '/tapes'; }
}
```

`gatewayId()` names the gateway the plugin registers: the suite builds SEOCart as it runs on a
site, lets the plugin's own listener register the gateway, and checks the gateway SEOCart's
payment service resolves. `tapes()` is the directory of the provider's recorded answers. The
conformance step runs it, and runs what the suite checks without WordPress (rules 4 and 15, and
rule 12's reading of `src/` for network calls that bypass the plugin's client) on the gateways the
main file registers; a plain integration run leaves the test out. An extension without the test
is skipped, and says so where it cannot be missed: a line that begins `conformance: SKIPPED` and,
under GitHub Actions, a warning, each naming the file to add. Rule 18, the idle budget, is the
`idle` gate. The conformance step in [Run the gates](#run-the-gates) shows it on
the skeleton: rules 2 and 3 and the page's check run, and every other rule is skipped and says
why.

While the capability matrix declares nothing, every rule that places a payment is skipped and
says so. Once it declares a cell, those rules place payments in it, so the test names a payment
token for each scenario they place, and a gateway that declares `webhooks` signs deliveries the
way its provider does:

```php
protected function tokens(): array {
	return array(
		Scenario::Approve->value                 => 'tok_example_approve',
		Scenario::Decline->value                 => 'tok_example_decline',
		Scenario::RequiresAction->value          => 'tok_example_3ds',
		Scenario::RequiresActionCompleted->value => new NotProducible( 'Completing the bank\'s page needs a browser.' ),
		Scenario::Expires->value                 => new NotProducible( 'The provider cannot be made to cancel a waiting payment in test mode.' ),
		Scenario::Pending->value                 => 'tok_example_async',
	);
}

protected function signDelivery( array $headers, string $body, string $secret, \DateTimeImmutable $at ): array {
	// The provider's own scheme, for example an HMAC of the timestamp and the body.
}
```

A scenario named `NotProducible` skips the rules that need it, with the reason; a scenario not
named at all fails them. Among the deliveries a gateway that declares `webhooks` records, one is
an event it applies, which it reads as its result, naming the intent by the store's uuid or, where
the provider's payload carries none, by the provider's id of it, which the test then names for the
delivery in `deliveryPayments()` (by the delivery's name, the provider's id of the payment it is
about: the result must name it, and the store's route must settle the delivery against the
store's payment of that id), and one an event it does not apply, which it reads as ignored: rule
10 reads both, through the gateway's `readWebhook()` and through the store's
webhook route, whose decision must match the gateway's reading. The tokens above are made up: name
the provider's own test tokens. The
scenarios are `approve`, `decline`, `requires_action`, `requires_action_completed` (the customer
acts before the store's wait for them ends), `expires` (the payment waits, and the provider
cancels it once the wait has ended) and `pending` (the provider takes the payment and decides
later), which rule 8 asks about when the test names it.

A gateway that exchanges its credentials for a token before its money request names that
request, by its method and its path with its query, in `credentialRequests()`. The request moves
no money: rule 6 asks it for no idempotency key, and no rule takes it for an operation's own
request. The test names four at most, and a request on a named path that names the payment (the
operation's key, the intent's uuid, the provider's id of the intent or of one of its objects, or
fields that hold the operation's amount, in minor units or as a decimal, and its currency) fails
rules 6 and 12.
The suite cannot know what a provider's endpoint does, so the pull request
that adds the declaration is checked by hand against the provider's documentation of the request:

```php
protected function credentialRequests(): array {
	return array( array( 'method' => 'POST', 'path' => '/v1/oauth2/token' ) );
}
```

A provider with a single endpoint names each request in its JSON body, such as
`{"getTransactionDetailsRequest": {…}}` sent to `POST /xml/v1/request.api`, so its reads are POSTs
too. Its test names each read, by its method, its path with its query and the one top-level key of
its body, in `readRequests()`. A request of that method and path, at a host the gateway declares,
whose body is a JSON object of that key alone, moves no money: rule 6 asks it for no idempotency
key, no rule takes it for an operation's own request or looks in its answer for the operation's
object, and rule 2 never loses its answer. The test names four at most, none a GET, HEAD or
OPTIONS; a named read that carries the operation's key, or names the operation's amount in a field
beside its currency in another, fails rules 6 and 12; and an operation whose every request is a
named read sends none that moves money, which both rules fail. A read names the payment it reads,
so the provider's id of it is not held against it. The pull request that adds the declaration is
checked by hand against the provider's documentation of each request:

```php
protected function readRequests(): array {
	return array(
		array( 'method' => 'POST', 'path' => '/xml/v1/request.api', 'body_key' => 'getTransactionDetailsRequest' ),
	);
}
```

Rule 2 refunds once with its answer lost, and asks the refund again. The answer lost is that of
the next request that can move money, neither a read nor a named credential request or read, so a
gateway that reads the payment or asks for a token before it refunds has those answered.

A provider that answers a declined payment by creating nothing says so in
`declineLeavesNoPayment()`: rule 8 then requires `query()` of the declined payment to answer
nothing and send no request, where it otherwise requires the decline. The suite cannot see the
decline's own answer, so the pull request that adds the declaration is checked by hand against
the provider's recorded decline, which must create nothing:

```php
protected function declineLeavesNoPayment(): bool {
	return true;
}
```

A provider that keeps one object for a payment, which a capture and a void change and answer by its
own id, says so in `paymentIsOneObject()`, and names in `objectIdField()` the field of its answers
that names the object each is about: rule 1 then lets the authorization, the capture and a refund
name the provider's intent as their object, where it otherwise requires an object of their own.
Each object must be the one that field of an answer to the operation names, so an answer about an
object of its own, such as a separate capture that names the payment in another field, refuses an
outcome that names the payment; two refunds never name one object; and a matrix that declares
`multi_capture`, or a test that names no field, refuses the declaration, as two captures of one
object would be one row of the payment ledger. The pull request that adds the declaration is
checked by hand against the provider's documentation of its transactions:

```php
protected function paymentIsOneObject(): bool {
	return true;
}

protected function objectIdField(): string {
	return 'transactionResponse.transId';
}
```

A provider that signs a delivery's body alone, with no time, and sends a delivery again for days,
says so in `deliveriesCarryNoSignedTime()`. Rule 10 then reads each delivery with the clock an hour
later, an hour earlier and twenty-nine days later, within the thirty days the store keeps a
receipt, and requires it to read as it does on arrival, with the same kind and event id, where it
otherwise requires a delivery an hour old or an hour ahead to be rejected: the store's receipt of
the event refuses a replay, and a window on the time the body states would refuse every late
retry. A delivery with a bad signature is still rejected. A delivery whose headers hold a time (a `t=` part, a Unix time within ten
years of its signing, an RFC 3339 or an HTTP date) refuses the declaration, as its provider may
sign it. The pull request that adds the declaration is checked by hand against the provider's
documentation of its signature:

```php
protected function deliveriesCarryNoSignedTime(): bool {
	return true;
}
```

A provider that refunds nothing of a capture before the day's settlement, and cancels a payment
only whole, says so in `partialRefundsNeedSettlement()`: its gateway refunds a captured payment
whole before it settles and declines a part without asking the provider for it, and no sandbox
settles in the minutes a recording takes. Every rule that refunds a part and then the rest then
refunds the payment whole, once, the lost answer of rule 2 included, and rule 2 first asks, in each
cell that declares `partial_refund`, for one unit's refund and requires the gateway's decline, the
intent's refunded amount unchanged and no request that moves money sent, through the gateway's
client or past it. A matrix that declares no `partial_refund` refuses the
declaration, and the run prints one line saying that partial refunds are proved by the gateway's
live run, which refunds payments an earlier run left to settle. The pull request that adds the
declaration is checked by hand against the provider's documentation of its refunds:

```php
protected function partialRefundsNeedSettlement(): bool {
	return true;
}
```

### Tapes: replay and record

The suite never reaches the provider on a pull request. It replays the provider's answers from
`tests/tapes/`, one directory per scenario, one file per request (`1.json`, `2.json`, …), each
holding the request as sent (method, host, path with its query, headers, body) and the answer
(status, headers, body); `tests/tapes/settings.json` holds the non-secret settings of the
recording and its nonce, if it had one, and `tests/tapes/webhooks/` the recorded deliveries. A
replayed request must equal its tape in its method, its path with its query, and its body; when
the adapter sends anything else, the test fails and names the scenario, the request's number and
the part that differs.

Recording calls the provider for real, in test mode, from a developer's shell: name a JSON file of
the test mode's settings, in the format `wp seocart gateway configure --from-file` reads (see
[Saving a mode's settings](#saving-a-modes-settings)) and that no one but you can read or write
(`chmod 600`), and set `SEOCART_TAPE=record`:

```text
$ SEOCART_TAPE=record SEOCART_TAPE_SETTINGS=~/dev/example-test.json sh ../seocart/bin/ci/extension.sh conformance . payments
```

On the skeleton, which declares nothing, it prints what the conformance step above prints, and
writes no tape:

```text
$ git status --short
```

Each scenario's ids, and with them every idempotency key and store uuid its requests carry, are
drawn from the scenario's name, so a recording sends the same requests every time it is made. A
provider that keeps idempotency keys for a while answers a key it has seen with its first answer,
so to record again within that while, set a fresh nonce, and every id is drawn anew. A nonce is 1
to 19 characters of letters, digits, `.`, `:`, `_` and `-`, beginning with a letter or a digit;
an empty `SEOCART_TAPE_NONCE` is no nonce:

```text
$ SEOCART_TAPE=record SEOCART_TAPE_NONCE=2026-10-16.b SEOCART_TAPE_SETTINGS=~/dev/example-test.json sh ../seocart/bin/ci/extension.sh conformance . payments
```

The nonce is no secret: `tests/tapes/settings.json` keeps it as `recording-nonce`, and a replay
draws its ids with it again, so the whole directory is recorded with one nonce.

A recording is made in test mode alone, and rule 16 also places a live-mode payment, which never
reaches the provider: its secret keys come from the test's `placeholder()`, and each non-secret
setting is the recorded one with `_test_` in it written `_live_` (`pk_test_…` becomes
`pk_live_…`), so an availability check that reads a key's mode offers the gateway in both modes.

Before a tape is written, it is scrubbed and scanned:

1. every value of a credential of the run becomes `{{secret:<setting>}}`, wherever it is and in
   whatever form it was sent (as it is, URL-encoded, escaped for JSON, base64-encoded or
   hexadecimal), and so does every secret the provider issues that the test names in
   `providerSecrets()`, such as a client secret the customer's browser needs;
2. the `Authorization`, `Proxy-Authorization` and `Cookie` request headers are dropped, and of the
   answer's headers only `Content-Type` and those the test names in `publicHeaders()` are kept:
   every other response header, a provider's security and tracing headers among them, is dropped
   before the scan and never read, and `Set-Cookie` is dropped even when named;
3. each delivery is signed again, by the test's `signDelivery()`, with SEOCart's published test
   secret, so the provider's signing secret never reaches the tape;
4. everything about to be written is read field by field (the URL and each field of its query,
   each header, each field of a JSON or form body and of a URL a field holds, each delivery,
   each setting) for a credential of the run, a value shaped like a provider's secret (`sk_` or
   `rk_` then `test_` or `live_`, `whsec_`, `cs_test_` or `cs_live_`, or `_secret_`), an
   opaque token (a run of 20 or more letters, digits, `_` and `-`, in any case) in no public
   shape, a header that carries credentials, a card number wherever it is, and a field named for
   a card's verification code or its full number (`cvc`, `cvv`, `cvv2`, `cvc2`, `csc`,
   `security_code`, `number`, `card_number` or `account_number`) whatever its value; a field's
   name is read for all of these but the opaque token, unless it is shaped like a URL, which is
   read as the URL it is, or is a key of a URL's query, which is read as a value is. A card's
   `last4`, `exp_month`, `exp_year`, `brand`, `funding` and `country` are kept: PCI-DSS lets a
   merchant store them, every receipt shows them, and a provider's decline answer carries them.
   Then the raw text of each body and query, as it is written, is read for an opaque token that
   none of its decoded fields holds, such as the first of a field sent twice or a number too long
   to read as one, and for any card number at all, which no field may hold. A hit fails the
   recording, names the part, the field and what it found but never the value, and nothing is
   written.

An opaque token passes only in a public shape: a UUID, as the store's own references are; one of
the provider's enumerated codes, as an exact string the test names in `publicCodes()`, such as a
long status; an id of exactly a shape the test names in `publicIdShapes()`, a prefix with the
number of letters and digits after it (`'pi_' => 24`) or the least and the most
(`'cus_' => array( 14, 24 )`); in a
field the test names in `publicDigestFields()`, such as a delivery's signature header, a run of 64
hexadecimal digits, or a whole value of 128 with a letter among them, bare or after `sha512=`; a
whole value in base64 (the standard alphabet in whole groups of
four, `=` padding at the end) of 20 to 1024 characters in a field the test names in
`publicSignatureFields()`, such as the signature a provider puts on a public delivery, kept as it
was recorded unless its decoded bytes are printable text that holds a credential, a secret, a card
number or an opaque token; and the media type of a `Content-Type` or `Accept` header. No public
shape passes for a token with a secret's prefix (`sk_`, `rk_`, `whsec_`, `cs_`, `secret_`,
`private_`, `token_`, `key_`, `pass_` or `password_`, or `_live_`, `_test_`, `_secret_`,
`_private_`, `_token_` or `_key_` inside it), in a field named like a credential (`key`, or a name
holding `secret`, `token`, `password`, `passphrase`, `authorization`, `cookie` or `credential`, or
ending in `api_key`, `client_key`, `signing_key` and the like), or in the query of a redirect's
`Location`. A publishable key (`pk_test_` or `pk_live_`, then letters and digits) is public, as
the provider publishes it in the customer's browser: it is no secret's shape or prefix, and
`publishable_key` is no credential's name, so a short one is written as it is and a long one in
the shape the test declares for it, such as `'pk_test_' => array( 90, 110 )`. A secret the
provider issues is named in `providerSecrets()`, by a name for its placeholder:

```php
protected function providerSecrets(): array {
	return array( 'client_secret' => '/\bpi_[A-Za-z0-9]+_secret_[A-Za-z0-9]+\b/' );
}

protected function publicIdShapes(): array {
	return array(
		'pi_' => 24,
		'ch_' => 24,
		're_' => 24,
	);
}

protected function publicDigestFields(): array {
	return array( 'example-signature' );
}

protected function publicSignatureFields(): array {
	return array( 'example-signature-base64' );
}

protected function publicCodes(): array {
	return array( 'requires_payment_method' );
}

protected function publicHeaders(): array {
	return array( 'request-id' );
}
```

`publicHeaders()` names, in lower case, each response header the gateway reads, such as a request
id it logs. A header named there is scanned as every other part is, so a request id of 20 or more
characters is declared in `publicIdShapes()` too, such as `'req_' => 24`.

A provider's id of letters and digits often holds a run of 13 to 16 digits that passes a card
number's checksum. In a tape, a value that is, as a whole, an id of a shape named in
`publicIdShapes()` with a letter after its prefix is not read as a card number, and nor is such an
id as a whole segment of the path of an `http` or `https` URL, such as the id in
`/v2/checkout/orders/{id}`; a whole digest of 64 or 128 hexadecimal digits, bare or after
`sha256=` or `sha512=` as its length is, with a letter among its digits, in a field named in
`publicDigestFields()` is not either, while digits alone, another length or label, and the digest
among other text are. In a log line, only a value of the line's context that is such an id, or
such a digest under a key named in `publicDigestFields()`, as a whole is skipped: an id inside a
URL or in the message is read as a card number as before. The skip of a digest is by design: rule
13's logger and the tape's scan read a value by the one shared test, so a field named in
`publicDigestFields()` changes what a log line may carry as it changes what a tape keeps. Digits alone after the prefix, an id among other text (`order-{id}`, a
sentence, free text that holds a URL), a field's name and a shape not named are read as before,
and every other check still reads the value. For ids with no prefix, a shape may have none while
it is shorter than 20 characters, such as `'' => 17`; it lets no opaque token pass, and one of 20
or more is refused. A field named like a credential is never skipped either, so a provider's link
that carries an id in such a query field, such as `token={id}`, is refused when that id's digits
pass the checksum, and the tape is recorded again.

The same `publicCodes()` is rule 13's list of the provider's words a log line may carry: a gateway
that logs a status from the provider's answer, such as `succeeded`, declares it there. Any other
text of the answers the gateway was served, an error's message above all, fails rule 13 when a
line it logs holds it, found however the line encodes it, writes it in another case or splits it
across its values. A log line never names a card field either, `last4` and the expiry included: a
tape keeps them, as a receipt shows them, but a log has no need of them.

A tape's JSON keeps its keys sorted, so a new recording's diff shows only what changed. The
suite writes the files, so they are left out of formatting: SEOCart's `.prettierignore` names
`**/tapes/**`, and an extension that formats with Prettier leaves `tests/tapes/` out the same way.

Commit the tapes through a pull request, so a change in the provider's answers is a reviewed diff.
SEOCart's own reference gateway, `tests/Conformance/Payment/Reference/ReferenceGateway.php`, is a
worked example of an adapter the suite passes, with its tapes beside it.

### The capabilities page

`docs/capabilities.md` is generated from the gateway's `describe()`: the matrix, the idempotency
profile and the external services. The conformance step fails when it no longer matches.
Regenerate it, or check it, from the extension's root; on the skeleton both find it current:

```text
$ php ../seocart/bin/generate-docs.php --plugin=.
ok     docs/capabilities.md (gateways)
```

```text
$ php ../seocart/bin/generate-docs.php --plugin=. --check
ok     docs/capabilities.md (gateways)
```

## What running a gateway asks of its adapter

A merchant sets a gateway up, switches it off and keeps it out of live mode through SEOCart, and
each of those reaches the adapter. This section says how.

### Webhook endpoints

A gateway whose provider can create webhook endpoints through its API implements
`SEOCart\Contracts\Payment\ProvisionsWebhooks` as well as `PaymentGateway`. Any other gateway has
its endpoint set up by the merchant, who saves the signing secret with the credentials.

- `webhookEvents()` returns the event types the endpoint subscribes to, the ones `readWebhook()`
  reads, at least one. SEOCart asks once, when the gateway registers.
- The gateway also declares the credential `webhook_secret` (`GatewayDescriptor::WEBHOOK_SECRET`).
  Without it, or without an event type, SEOCart refuses the registration with the reason
  `webhooks_undeclared`.
- `provisionWebhooks( WebhookTarget $target )` is called when `wp seocart gateway configure` saves
  a mode's settings, outside any transaction, unless the merchant gives `--no-webhooks`. The
  gateway calls its provider through `ExtensionContext::http()` only.
- The `WebhookTarget` carries the URL SEOCart's route has for the gateway and the mode, the mode,
  the site's install uuid, the events, and `$secretHeld`. When SEOCart holds the signing secret of
  an endpoint it set up for the mode, `$secretHeld` is true and `$endpointId` names that endpoint;
  otherwise `$endpointId` is null.
- An endpoint is this site's only when the provider's record of it carries the install uuid and the
  mode as metadata and its URL is the target's URL. A copied site keeps the install uuid but serves
  another URL, so the URL is part of the test. The gateway never lists, changes or deletes an
  endpoint tagged with another install uuid or mode, or one of this site and mode at another URL,
  which it counts in `WebhookProvisioning::$elsewhere`.
- An endpoint is reused only when `WebhookTarget::$endpointId` names it. Any other endpoint of this
  site for the mode is deleted and a new one created, because a provider shows a signing secret
  only when the endpoint is created and SEOCart does not hold this one's.
- The answer is a `WebhookProvisioning`: `REUSED` carries no signing secret, `REPLACED` and
  `CREATED` carry the new one, and the constructor refuses any other pairing. It also lists the
  endpoint ids deleted. An endpoint id is one line of at most
  `WebhookProvisioning::ENDPOINT_ID_MAX_LENGTH` bytes.
- SEOCart stores the signing secret sealed under `webhook_secret` and the endpoint id beside it
  under `webhook_endpoint`. It reports a `REUSED` answer that names any endpoint other than
  `$endpointId` as a failed webhook step, since that endpoint's secret is not held.
- When the provider cannot be asked or refuses, the gateway throws `GatewayUnavailable`. SEOCart
  keeps the settings it saved, reports the webhook step failed by the exception's class and never
  its message, which may quote a credential, and `configure` exits 2.
- A gateway that provisions its endpoints takes no payment in a mode until that mode's
  `webhook_secret` is saved.

### Setting names SEOCart reserves

A gateway declares no setting named `mode` (`GatewayDescriptor::RESERVED_SETTING`) or
`webhook_endpoint` (`GatewayDescriptor::WEBHOOK_ENDPOINT`): SEOCart keeps the gateway's mode under
the first, when the gateway has more than one, and the endpoint id under the second. A descriptor
that declares either, or one name twice, is refused when the gateway registers, with a line in
SEOCart's log and the reason `invalid_descriptor`. Two names are SEOCart's to give meaning to:
`webhook_secret` is the signing secret above, and `account_country` selects the row of the
capability matrix and must be plain text. A name that reads as card data, such as `card_number`, is
refused too.

### The kill switch and Safe Mode

An operator switches a gateway off with `wp seocart gateway disable <gateway>`. A gateway switched
off takes no new payment, and checkout does not offer it. The payments it already holds go on
through the adapter: `capture()`, `void()`, `refund()`, `queryRefund()` and `query()` are called as
before, and its webhook deliveries are still read. The switch is for stopping money coming in, so
an adapter keeps answering about money already moving.

Safe Mode (`wp seocart safe-mode on`, which SEOCart also turns on for a site it finds copied)
keeps the site off live money:

- SEOCart makes no call about a live payment: it refuses `payment.gateway_unavailable` with the
  reason `safe_mode` before the adapter is asked, so the adapter never sees a live call.
- A live delivery to the webhook route is answered `payment.gateway_unavailable` before any secret
  is opened, so the provider retries later.
- `wp seocart gateway configure` for a live mode is refused for every gateway, whether or not it
  sets up its own endpoints, unless the merchant gives `--no-webhooks`, so `provisionWebhooks()` is
  not called.
- A gateway set to live takes new payments in its test mode, or none when it has no test mode or is
  not configured for it. Payments created live keep their mode and wait until Safe Mode ends.

SEOCart opens every credential the gateway declares for a payment's mode before it calls the
adapter, and refuses the call, with the reason `credentials_missing` or `credentials_unreadable`,
when one cannot be used. Both the switch and Safe Mode are read from a record each request loads
once, so a change takes effect for the requests that start after it and stops none already running.

### Saving a mode's settings

`wp seocart gateway configure <gateway> --mode=<test|live> --from-file=<path> --user=<login>`
saves a mode's settings; the user needs `seocart_manage_secrets`. The settings never travel on the
command line, which other users of the server can see, so the file has these rules:

- It is one JSON object of the gateway's settings for the mode, by the names `describe()` declares.
  It names every setting that has no default, but `webhook_secret`. A gateway that sets up its own
  endpoints has that secret set up by the webhook step, and the file gives it only with
  `--no-webhooks`. A name the gateway does not declare is refused, `mode` included, since the mode
  is the option's.
- It is a regular file, not a symbolic link, and no user but its owner can read or write it
  (`chmod 600`). A file of more than `GatewayCommand::MAX_FILE_BYTES` (65536) bytes is refused.
  The file opened must be the file checked. No message quotes its content.
- The capability, the gateway and Safe Mode are checked before the file is opened.

The command exits 0 when done, 1 when it refused and saved nothing, and 2 when the settings were
saved and the webhook step failed.

### A payment form in the checkout

A gateway whose provider takes the shopper's payment details in the browser adds a payment island
to the checkout: a classic script that registers with `window.seocart.payments`, mounts the
provider's form in the payment section, returns the `payment_data` the placement sends, and takes
the step the provider asks of the shopper, such as a bank's confirmation. The adapter enqueues it on
the action `seocart_enqueue_payment_islands`, and the script ships as written in the plugin's
`assets/` directory. [Payment islands](payment-islands.md) describes the registration, the island's
interface, the context it is mounted with, the placement step by step, and the contract's versions.

## Add checkout fields

An extension may ask the shopper for something the checkout does not: a purchase order number, a
delivery slot, a box to tick. It registers each field once, on the action
`seocart_register_checkout_fields`, with the checkout field registry SEOCart hands it, and that one
registration is all it writes. The checkout form (`checkout.get_form`), the store's list of fields
and its choice of each one's presence (`checkout.get_fields`, `checkout.update_fields`), the
session write's rules, the placement, the order and staff's read of an order's values
(`order.list_field_values`) all take the field from it. SEOCart fires the action at most once a
request, the first time something needs the checkout's fields, and never on a request that needs
none; the main file names the action by its string, as it names the gateway registration's.

This is the registration of `acme`, the extension SEOCart's own tests walk through every surface
(`tests/Support/Checkout/AcmeCheckoutFields.php`); its main file hooks it with
`add_action( 'seocart_register_checkout_fields', … )`:

```php
public static function register( CheckoutFieldRegistry $registry ): void {
	if ( ! $registry->supportsContract( self::CONTRACT ) ) {
		return;
	}

	$registry->register(
		self::ID,
		new CheckoutField(
			key: 'po_number',
			section: Section::Order,
			type: CheckoutFieldType::Text,
			label: static fn(): string => __( 'Purchase order number', 'acme-checkout' ),
			maxLength: 20,
			presences: array( Presence::Optional, Presence::Required, Presence::Hidden ),
			help: static fn(): string => __( 'For an order your company pays by invoice.', 'acme-checkout' ),
			check: static function ( string $value ): ?string {
				if ( str_contains( $value, '?' ) ) {
					// A check may throw: SEOCart refuses the value and logs the field and the exception's class, never this message.
					throw new \UnexpectedValueException( sprintf( 'Cannot read the purchase order number %s.', $value ) );
				}

				return str_starts_with( $value, 'PO-' ) ? null : __( 'A purchase order number starts with PO-.', 'acme-checkout' );
			}
		)
	);
	$registry->register(
		self::ID,
		new CheckoutField(
			key: 'delivery_slot',
			section: Section::Order,
			type: CheckoutFieldType::Select,
			label: static fn(): string => __( 'Delivery slot', 'acme-checkout' ),
			maxLength: 20,
			presences: array( Presence::Optional, Presence::Required, Presence::Hidden ),
			options: array(
				'morning'   => static fn(): string => __( 'Morning', 'acme-checkout' ),
				'afternoon' => static fn(): string => __( 'Afternoon', 'acme-checkout' ),
			)
		)
	);
	$registry->register(
		self::ID,
		new CheckoutField(
			key: 'gift_wrap',
			section: Section::Order,
			type: CheckoutFieldType::Checkbox,
			label: static fn(): string => __( 'Wrap it as a gift', 'acme-checkout' ),
			maxLength: 1,
			presences: array( Presence::Optional, Presence::Hidden )
		)
	);
}
```

- **The key.** A field is stored as `{extensionId}_{key}`, `acme_po_number` above, and every surface
  names it so. A session write sends its value in `custom_fields`, a list of `{ key, field_value }`
  entries; a select sends its option's value, and a ticked checkbox `1`.
- **What may be registered.** The sections `contact` and `order`; the types `text`, `textarea`,
  `select` (one to 50 options) and `checkbox`; at most 20 fields from all extensions together. An
  extension's field has no autocomplete token. A select's `maxLength` covers its longest option
  value: a select with an option longer than that is refused when it is declared.
- **What is refused.** A 21st field, a field of the `address` section, another type, a key that is
  one of SEOCart's own fields or a member of the session write, given or stored, a stored key that
  starts `seocart_` or `custom_`, a key registered already, and a registration after the action ran.
  A refused field is left out, logged once with the code `checkout.field_rejected` and noted by
  `wp seocart doctor`; it is never an error, and the first registration of a key wins. A listener
  that throws ends the registration where it threw: the fields registered before it are kept, the
  listeners after it on the action do not run, and SEOCart logs the exception's class and the
  plugin its file lies in, which doctor names, with the reason `registration_failed`, never its
  message.
- **Presence.** The presences listed are the ones a store may choose, the first the default; a
  field that lists one is locked. Only the store chooses, through `checkout.update_fields`.
- **Words.** The label, the help and each option's label are closures around the extension's own
  translation calls, run in the request's language. An order keeps the field's label and a chosen
  option's label as the shopper saw them, so a later version that changes them, or the extension's
  removal, leaves the orders placed before as they were.
- **The check.** A field's own `check` is given a value every other rule of the field accepted, and
  answers null to accept it or a sentence to refuse it; the session write answers the reason
  `rejected` with the sentence as `message`, and the placement judges a kept value again. A check
  that throws refuses the value with no message, and SEOCart logs the field's key and the
  exception's class, never the value or the exception's message. A check's sentence should say
  the rule, not repeat the value: the shopper sees what they typed already, and a sentence that
  quotes it carries personal data wherever the sentence goes.
- **Personal data.** Every value is personal data, whatever the field: it travels under the name
  `field_value`, which SEOCart's log redacts wherever it appears, and the session and the order
  declare it for their eraser to destroy, keeping the field's key and label. A value an extension
  needs as something else belongs in storage of its own.
- **The contract.** `supportsContract()` answers whether this version of SEOCart implements the
  checkout field contract the extension was written against; before 1.0 the major and minor
  versions must be equal.

[Checkout fields](reference/checkout-fields.md) lists SEOCart's own fields and the session write's
rules, and [the hooks reference](reference/hooks.md) the action.

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
(`integration`, `idle` and `conformance` against MySQL), `ci / package` (`zip`), `ci / seocart-zip`
(SEOCart's own zip at the pinned commit, built the way SEOCart builds it) and `ci / plugin-check`
(WordPress's Plugin Check on the extension's zip). Plugin Check runs on a site where SEOCart is
installed from that zip and active: WordPress activates a plugin only when every plugin its
`Requires Plugins` header names is active, and the version on WordPress.org, once there is one,
is not the pinned commit. The action installs the plugins that header names from WordPress.org
itself, so a must-use plugin on that site answers its lookup for `seocart` with the installed
copy, which WP-CLI reports as already installed and activates. `nightly.yml` runs the same
workflow every night against SEOCart's `main` branch instead of the pin, so a change in SEOCart
that breaks the extension shows there before the next bump. That is the one place that may pass
`core-ref`: on a pull request or a push the workflow fails when a caller passes it, and
`check-workflows.sh` reports the call, so a required check always tests the pin.

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

## The live workflow of a payment extension

A payment extension's pull requests replay its provider's recorded answers. Its live workflow
calls the provider for real: it runs the extension against the provider's test environment with
the repository's secrets, weekly and by hand, and never on a pull request or a push, so it is never
a check a merge waits for and a provider's outage never blocks one. The workflow is the
extension's own file, such as `.github/workflows/stripe-test-mode.yml` in the Stripe gateway;
everything in it that is no provider's comes from SEOCart, at the commit the extension pins.

### What SEOCart provides

- **`bin/ci/extension-live.sh`**, the steps that are no provider's, run as
  `sh seocart/bin/ci/extension-live.sh <step> ext [<argument>]`: `heads` (the two commits under
  test, as the job's outputs); `site` (SEOCart's scripts and both zips built, WordPress installed
  with SEOCart and the extension from those zips, and the site the checkout's browser tests use
  served on the runner); `conformance` (the conformance suite with every request sent to the
  provider, its tests and skips checked against the declaration, and what it created there put on
  the run list from the tapes it recorded); `live <part>` (a part's live tests: exactly as many as
  the declaration states must run, and each pass); `optional <job>` (whether the secret the
  optional part needs is set, never its value); and `verdict` (the run's closing table, and its
  result).
- **`bin/ci/extension-live-lib.sh`**, sourced by that script and by the extension's own: the run's
  files, in one work directory, `$RUNNER_TEMP/seocart-live` (`settings.json`, `run-list`,
  `report`, and the checkout's `site`), and the helpers `fail`, `output`, `mask`, `random_hex`,
  `nonce`, `filtered` (a command's output passed through the guard below, line by line, and kept
  in a file; the step fails when the guard cannot run, whatever the command returned), `site_wp`
  and `count` (a count from Playwright's summary).
- **`.github/actions/extension-live-setup`**, a job's set-up: PHP, Composer and SEOCart's
  development install; with `wordpress: 'true'`, the default, WordPress and SEOCart's test
  configuration; with `browser: 'true'`, Node.js, the browser scripts' dependencies, Chromium for
  Playwright, and the browser scripts' own tests.
- **`tests/Conformance/Payment/Live/`**: the declaration an extension writes (`LiveDeclaration`);
  the guard (`MessageGuard`, below); the readers of the run's files and the jobs' outputs; the
  verdict; and the two checks the extension's workflow test runs, `WorkflowFacts` and
  `SettingsLast`.

The log of a public repository's run is public, so the guard blanks out what must not be shown,
or refuses to print it. It reads each text it is given a line at a time, so a value split over two
lines is not recognised, and it recognises: an address with a query; an authorization header (its
name in any letter case, then `:` or `=`, plain or in JSON, and whatever credentials follow it, of
any scheme, to the end of the value), and a `Bearer` or `Basic` token on its own; a card number, 13
to 19 digits that pass the Luhn check, written as a run of digits with single spaces allowed
between them, or in groups the way a card is printed (four digits, then two to four groups of
three to six, each after a space or a hyphen); and the shapes of the provider's credentials that
the extension's declaration adds, none of which may match an empty text. What
passes through it: the output of every command run through `filtered`, which is the conformance
suite's and the live tests' PHPUnit output and whatever the extension's steps run through it, such
as the checkout's Playwright tests; the outputs, errors and summary lines SEOCart's PHP steps
write, the run's report included, and the verdict's table; and the live tests' failure messages
and facts, when they are built with the guard's `say()` and `LiveRun::report()`. What does not:
anything else a step prints, such as the extension's script's own lines, WP-CLI's output while the
site is built and configured, and the set-up's output. The PHP server's log of the checkout's
site goes to a file in the work directory, which nothing prints.

### What the extension writes

**`tests/live.php`** returns the declaration: what differs from one provider to the next, and
nothing that can be derived. SEOCart reads it by path, so whatever class of its own it needs, it
requires by path too: the verdict's job installs nothing. The Stripe gateway's, abridged:

```php
return new class() extends LiveDeclaration {
	public function workflow(): string {
		return 'stripe-test-mode.yml';
	}

	public function jobs(): array {
		return array(
			'live-conformance' => 'conformance',
			'three-d-secure'   => 'three-d-secure',
			'webhooks'         => 'webhooks',
			'checkout'         => 'checkout',
		);
	}

	// liveParts(): each part that runs `live <part>`, with its PHPUnit filter and how many tests it runs;
	// proofs(), facts(), suiteTests(), suiteSkips(), shapes(), createdBy(); and optionalPart(), when a
	// part runs nothing while a secret is absent.
};
```

What is derived, and from where:

| Derived                       | From                                                  | The Stripe gateway's                            |
| ----------------------------- | ----------------------------------------------------- | ----------------------------------------------- |
| The repository and the slug   | the `name` of `composer.json`                         | `cirkuitnet/seocart-gateway-for-stripe`         |
| The live tests' PHPUnit group | the slug without `seocart-gateway-for-`, then `-live` | `stripe-live`                                   |
| The release zip               | the slug                                              | `dist/seocart-gateway-for-stripe-<version>.zip` |
| The provider's script         | the workflow's basename                               | `bin/ci/stripe-test-mode.sh`                    |
| The concurrency group         | the workflow's basename                               | `stripe-test-mode`                              |
| The verdict's title           | the workflow's `name:`                                | `Stripe test mode`                              |

**`bin/ci/<the workflow's basename>.sh`** holds the provider's steps: `check-secrets`, `settings`
(the one file of credentials the tests read, readable by its owner alone, and the run list and the
report beside it), the gateway's configuration on the checkout's site, the checkout's browser
tests, and the clean-up at the provider. `check-secrets` runs before SEOCart is checked out, so it
does without the lib; every other step sources it:

```sh
#!/bin/sh
# shellcheck disable=SC2154 # The paths and helpers of SEOCart's lib are assigned there.

set -eu

ext=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
core=$(CDPATH='' cd -- "$ext/../seocart" 2>/dev/null && pwd) || core=$ext/../seocart
# shellcheck source=/dev/null
[ "${1:-}" = check-secrets ] || . "$core/bin/ci/extension-live-lib.sh"
```

Once it has sourced the lib, the script assigns none of the lib's variables (its paths such as
`work`, `settings` and `step_php`, and `core` and `ext`): the lib's functions read them, so a
script that changed one would change what SEOCart's helpers run and where they write. A PHP entry of
the extension's own has a path of its own.

**The workflow** has one job per part and a `verdict` job. A part's job, in order:

```yaml
steps:
    - *checkout-extension # actions/checkout into ext/
    - *check-secrets # sh ext/bin/ci/<basename>.sh check-secrets, the secrets in its env
    - *choose-core # the commit ext/seocart-core.env pins, or the core-ref input
    - *checkout-core # cirkuitnet/seocart at that commit, into seocart/
    - *heads # sh seocart/bin/ci/extension-live.sh heads ext
    - uses: ./seocart/.github/actions/extension-live-setup
    - *settings # sh ext/bin/ci/<basename>.sh settings, the secrets in its env
    - run: sh seocart/bin/ci/extension-live.sh live ext three-d-secure
    - *cleanup # sh ext/bin/ci/<basename>.sh cleanup, if: always()
```

The `verdict` job needs every part, runs `if: always()`, checks out the extension and SEOCart, and
runs `sh seocart/bin/ci/extension-live.sh verdict ext` with `SEOCART_LIVE_NEEDS:
${{ toJSON(needs) }}` and `SEOCART_LIVE_PARTS: ${{ inputs.parts || 'all' }}` in its env. GitHub
gives the result of every job the verdict needs, `skipped` included, so the verdict is red when a
job of the declaration has none, whatever the parts were; a part the run selected must succeed. A
part that waits for a secret, such as webhooks before the provider's webhook exists, runs
`sh seocart/bin/ci/extension-live.sh optional ext <job>` after `*checkout-core`, with that secret
alone in its env, and its live step only `if:` the output says `present`; the verdict then reads
its proofs as skipped, with the declaration's reason, and the run stays green.

A secret is named only in the `env:` of a step that needs it: a step that runs the extension's
script (its secrets' check, its settings step) or `extension-live.sh optional`; never a step that
uses an action or runs anything else, and never a job or the whole workflow. Neither the workflow
nor a job sets `defaults:`, and a job has no `env:` of its own, as every step after the settings
step would follow them; the workflow's own `env:` (the WordPress version and the database's
throwaway values) is the place for what all its steps share.

The extension's workflow test calls `WorkflowFacts::violations()` on the workflow (the triggers,
the permissions, the repository's guard on every job, secrets only in the env of the steps that
need them, one concurrency group, the jobs and the `parts` choices exactly the declaration's, the
verdict's needs and checkout), `WorkflowFacts::copies()` on its root (no class of SEOCart's left
copied in its `tests/`), and `SettingsLast`.

### The settings file is written last

The settings file holds the provider's key, so a job writes it after everything that installs,
downloads or builds, and nothing after it installs, downloads or builds: a package's install
script, a build, a downloaded browser or an action would otherwise run with the key readable.
`SettingsLast` holds a workflow to that with closed lists. A job's settings step is the step that
runs `sh ext/bin/ci/<basename>.sh settings`, whatever it is named or anchored as, with or without a
comment after it on its line; every part's job has one. After it, the job may run only
`sh seocart/bin/ci/extension-live.sh conformance ext`, `… live ext <part>` for a part the
declaration states, and the provider's steps the extension names, as
`sh ext/bin/ci/<basename>.sh <step>`; each with the keys `name`, `id`, `if` and `run` alone, and
no action. Those steps read the file, so a job that runs one with no settings step before it is
refused. The rule fails closed: a workflow from which it reads no job, a part's job it cannot read,
and a part's job whose settings step it does not recognise are refused, never passed. The shell
those steps reach, SEOCart's script and lib included, may run only commands that cannot install,
download or build: no `npm`, `npx`, `composer` or `pip`; Playwright as the set-up installed it,
`node_modules/.bin/playwright`; WP-CLI, `sed` and `sort`, whose options can run code, only in calls
written out whole; `jq`; the site's file read with `. "$site_env"`; and of PHP, besides what
SEOCart's own steps run, only the extension's `tests/Integration/Live/cleanup.php`, so a clean-up's
PHP entry must have that path. What the rule does not read it refuses: a here-document, a command
substitution in backticks, and a quote that never closes. SEOCart's own tests hold its script and
lib to the rule on a fixture extension; the extension's workflow test holds its workflow and script
to it, naming its steps after the settings step and its whole calls that name its provider:

```php
$rule = new SettingsLast(
	$declaration,
	array( 'configure', 'checkout', 'cleanup' ),
	array( 'site_wp seocart gateway configure <gateway id> --mode=test --from-file="$settings" --no-webhooks --user=admin' )
);

$this->assertSame( array(), $rule->violations( SettingsLast::sources( $declaration ) ) );
```

### SEOCart's action, from the checkout beside the extension

A job uses the action as `./seocart/.github/actions/extension-live-setup`, never as
`cirkuitnet/seocart/.github/actions/extension-live-setup@<ref>`. The job checks SEOCart out at
the commit `seocart-core.env` pins, or the one `core-ref` names, so the local path runs the action
of the same commit as the script and the classes, and `bin/dev/bump-core.sh` has nothing more to
move. A reference to GitHub must name a literal ref, which would be a second pin that nothing
moves with `seocart-core.env`; in an extension, `check-workflows.sh` reports one. Two facts of
GitHub's shape the layout: an action of the workspace exists only once the checkout that brings
it has run, so the steps before `*checkout-core` (the extension's checkout, the secrets' check, the
choice of the commit) stay in the extension's workflow; and a `./` path is read from the job's
workspace, not from the action's own folder, so the action names `./seocart/...` for what it
calls, and serves only this layout.

### One run

A run starts weekly, or by hand from the repository's Actions tab or with GitHub's CLI, which
takes the workflow's name; `parts` picks one part, or `all`:

```text
gh workflow run "Stripe test mode" -R cirkuitnet/seocart-gateway-for-stripe -f parts=checkout
gh run list -R cirkuitnet/seocart-gateway-for-stripe --workflow "Stripe test mode" --limit 1
gh run watch <the run's id> -R cirkuitnet/seocart-gateway-for-stripe
```

`--ref <branch>` runs a branch's copy of the workflow. GitHub starts a workflow by hand only when
its file is on the default branch, so a new live workflow's first run follows its merge. The
verdict's table, in the run's summary, lists each thing the run proves with the job that proves
it, its result and counts, and each job's two commits and the facts it reported.

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
						"context": "ci / seocart-zip",
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
$ sh bin/dev/bump-core.sh 29c123dc5f1a6ec2a00ef430ad4e2f16cbc42dd3
bump-core: ~/dev/seocart-gateway-for-example now pins SEOCart 29c123dc5f1a6ec2a00ef430ad4e2f16cbc42dd3 (was 7d4c6a0e966d44c2ecd6edbacfa23621772d5938): seocart-core.env and 3 workflow call(s).
bump-core: next: git -C ~/dev/seocart checkout 29c123dc5f1a6ec2a00ef430ad4e2f16cbc42dd3, run the gates (sh ~/dev/seocart/bin/ci/extension.sh all ~/dev/seocart-gateway-for-example), then commit.
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
           6 files, 17699 bytes (0.02 MiB)
           sha256 9e4aa9d02b75def20ea490b27c3c8adeea72a0b1f1f6eef83ba1c9ffa5e0da4f

extension: php ~/dev/seocart/bin/check-zip.php --plugin=~/dev/seocart-gateway-for-example ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip
check-zip: OK ~/dev/seocart-gateway-for-example/dist/seocart-gateway-for-example-0.1.0.zip, 17699 bytes (budget 5242880, limit 10485760)
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

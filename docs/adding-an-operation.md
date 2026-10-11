# Adding a field to an operation

This is a walkthrough with a point to prove. SEOCart declares each use case once, and the REST
route, the Ability, the WP-CLI command and the OpenAPI document are all compiled from that
declaration ([architecture.md](architecture.md#one-operation-every-surface)). So if you add a
field in that one place, every surface should pick it up. Here you do exactly that to a real
operation, **adjust stock**, and watch it happen.

Every command below was run, in this order, on a disposable site whose stock had never been
adjusted, and what is shown is what it printed. `…` marks output left out because it is long. Your
output will differ in ids, and in the stock numbers if the variant already has stock.

You need a working copy with the dependencies installed and a disposable WordPress site that runs
it, as [development.md](development.md) describes. Do not use a site you care about.

**Where each command runs.** Composer, `git` and `grep` commands run in the root of your working
copy (the checkout). Every `wp` command names the site's WordPress directory with
`--path="$WP_PATH"`, so it runs from anywhere, and `curl` reaches the site by its address,
`$SITE`. Step 1 sets those two variables once.

## 1. Prepare the site

Create a product to adjust the stock of. This uses the REST API as an administrator, with an
[application password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/).
First name the site: the directory of its WordPress install, and its address. Replace both with
yours.

```sh
WP_PATH=/path/to/the/site
SITE=https://example.test
```

Create the password straight into a file that only you can read, and never print it. The two
files go in your home directory, outside the repository:

```sh
umask 077
wp --path="$WP_PATH" user application-password create admin walkthrough --porcelain > ~/.walkthrough-password
printf 'user = "admin:%s"\n' "$(cat ~/.walkthrough-password)" > ~/.walkthrough-curl
```

Make a small helper so the later commands stay readable:

```sh
api() { curl -sS -K ~/.walkthrough-curl -H 'Content-Type: application/json' "$@"; }
```

Create the product. The response is long; what matters is `seocart.variant_id`, the id of the
variant whose stock you will adjust:

```text
$ api -X POST "$SITE/wp-json/wp/v2/seocart-products" -d '{"title":"Walkthrough product","status":"publish","seocart":{"sku":"WALK-1","price_minor":1999}}'
{"id":6, … "seocart":{"variant_id":1,"sku":"WALK-1","price_minor":1999,"currency":"USD","compare_at_minor":null,"weight_grams":null,"locale":"en_US","translation_of":null,"sellability":"sellable","generation_state":"complete"}, …}
```

The variant is `1`. In the checkout, check that the plugin's own gates pass before you change
anything, so that any failure later is yours:

```text
$ composer docs:check
ok     readme.txt (readme-external-services)
ok     docs/openapi.json (openapi)
ok     docs/reference/abilities.md (reference-abilities)
ok     docs/reference/cli.md (reference-cli)
ok     docs/reference/errors.md (reference-errors)
ok     docs/reference/hooks.md (reference-hooks)
```

## 2. Find the declaration

The operation's id is `inventory.adjust_stock`. Its human label is "Adjust stock". In the
checkout, search the source for the label:

```text
$ grep -rn "'Adjust stock'" src
src/Inventory/Application/InventoryOperations.php:103:			label: static fn(): string => __( 'Adjust stock', 'seocart' ),
```

The declaration is the static method `InventoryOperations::adjustStock()` in
`src/Inventory/Application/InventoryOperations.php`. It builds one `OperationDefinition`. Its
`input:` argument is a list of `FieldSpec` objects, one per field a client may send:

```text
$ grep -n "new FieldSpec(\|function " src/Inventory/Application/InventoryOperations.php
100:	public static function adjustStock(): OperationDefinition {
107:				new FieldSpec(
117:				new FieldSpec(
126:				new FieldSpec(
170:	private static function variantId(): FieldSpec {
171:		return new FieldSpec(
195:	private static function count( string $name, string $description, \Closure $label, int $example ): FieldSpec {
196:		return new FieldSpec(
```

The three inputs on lines 107, 117 and 126 are `delta`, `reason` and `expected_on_hand`, after
`variantId()` (the variant, which is both an input and an output field). The last of them is the
simplest one to copy:

```php
new FieldSpec(
	name: 'expected_on_hand',
	type: FieldType::Integer,
	description: 'The units on hand the client last read. When given, the change applies only while the variant still has exactly that many, so a repeated request cannot apply it twice.',
	label: static fn(): string => __( 'Expected units on hand', 'seocart' ),
	example: 12,
	minimum: 0
),
```

A `FieldSpec` carries everything the surfaces need: the wire name, the type, the constraints, a
machine description (one English sentence, which becomes the REST and OpenAPI description), a
label for people (a separate string, translated by a literal `__()` call so that translation
tools can find it), and an example that must be valid.

## 3. See how it behaves before the edit

First, a normal adjustment: five units arrived.

```text
$ api -X POST "$SITE/wp-json/seocart/v1/stock-items/1/adjustments" -d '{"delta":5,"reason":"received"}'
{"variant_id":1,"on_hand":5,"allocated":0,"held":0,"available":5,"ledger_entry_id":1}
```

Now send a field the operation does not declare. The route ignores it, and the stock still
changes:

```text
$ api -X POST "$SITE/wp-json/seocart/v1/stock-items/1/adjustments" -d '{"delta":1,"reason":"received","note":"Pallet 7"}'
{"variant_id":1,"on_hand":6,"allocated":0,"held":0,"available":6,"ledger_entry_id":2}
```

The command refuses what the declaration does not list:

```text
$ wp --path="$WP_PATH" --user=admin seocart stock adjust 1 --delta=1 --reason=received --note="Pallet 7"
Error: Parameter errors:
 unknown --note parameter
```

And the Ability's input schema lists four properties:

```text
$ wp --path="$WP_PATH" --user=admin eval '$a = wp_get_ability( "seocart/adjust-stock" ); echo wp_json_encode( array_keys( $a->get_input_schema()["properties"] ) ), "\n";'
["variant_id","delta","reason","expected_on_hand"]
```

## 4. Make the one edit

Add an optional `note`, with a length limit, after `expected_on_hand`. It is optional because it
does not say `required: true`. A text limit is declared with `max_length`:

```diff
--- a/src/Inventory/Application/InventoryOperations.php
+++ b/src/Inventory/Application/InventoryOperations.php
@@ -131,6 +131,14 @@ final class InventoryOperations {
 					example: 12,
 					minimum: 0
 				),
+				new FieldSpec(
+					name: 'note',
+					type: FieldType::String,
+					description: 'A short remark for whoever reads the stock ledger later, such as the delivery a recount refers to.',
+					label: static fn(): string => __( 'Note', 'seocart' ),
+					example: 'Pallet 7 from the spring order',
+					max_length: 200
+				),
 			),
 			output: new ResourceSchema(
 				self::RESOURCE,
```

That is the whole change. You edit no route, no command, no schema and no document.

## 5. The drift test fails, and you regenerate

`docs/openapi.json` and the files under `docs/reference/` are generated from the declarations and
committed. Until you regenerate them they are out of date, and `composer docs:check` says so, and
says what to do:

```text
$ composer docs:check
ok     readme.txt (readme-external-services)
DRIFT  docs/openapi.json (openapi): line 1647 is "                                    }" but its source generates "                                    },".
DRIFT  docs/reference/abilities.md (reference-abilities): line 23 is "" but its source generates "- `note`: A short remark for whoever reads the stock ledger later, such as the delivery a recount refers to. Text of at most 200 characters.".
DRIFT  docs/reference/cli.md (reference-cli): line 47 is "wp seocart stock adjust <variant_id> --delta=<delta> --reason=<reason> [--expected_on_hand=<expected_on_hand>] [--format=<format>]" but its source generates "wp seocart stock adjust <variant_id> --delta=<delta> --reason=<reason> [--expected_on_hand=<expected_on_hand>] [--note=<note>] [--format=<format>]".
ok     docs/reference/errors.md (reference-errors)
ok     docs/reference/hooks.md (reference-hooks)

A generated document no longer matches its source. Do not edit it by hand; regenerate it with:

    composer docs:generate

Script @php bin/generate-docs.php --check handling the docs:check event returned with error code 1
```

This is the drift test, and it is a gate in continuous integration. Do what it says:

```text
$ composer docs:generate
ok     readme.txt (readme-external-services)
wrote  docs/openapi.json (openapi)
wrote  docs/reference/abilities.md (reference-abilities)
wrote  docs/reference/cli.md (reference-cli)
ok     docs/reference/errors.md (reference-errors)
ok     docs/reference/hooks.md (reference-hooks)

$ git status --short
 M docs/openapi.json
 M docs/reference/abilities.md
 M docs/reference/cli.md
 M src/Inventory/Application/InventoryOperations.php
```

Three generated files changed, and nothing else. This is what they gained. The fence around the
diff has four backticks, because one of the lines it shows is itself a code fence:

````diff
--- a/docs/openapi.json
+++ b/docs/openapi.json
@@ -1646,2 +1646,10 @@
                                         ]
+                                    },
+                                    "note": {
+                                        "type": "string",
+                                        "maxLength": 200,
+                                        "description": "A short remark for whoever reads the stock ledger later, such as the delivery a recount refers to.",
+                                        "examples": [
+                                            "Pallet 7 from the spring order"
+                                        ]
                                     }
--- a/docs/reference/abilities.md
+++ b/docs/reference/abilities.md
@@ -22,2 +22,3 @@ Changes the units on hand of one variant by a signed amount, records the change
 - `expected_on_hand`: The units on hand the client last read. When given, the change applies only while the variant still has exactly that many, so a repeated request cannot apply it twice. An integer of at least 0.
+- `note`: A short remark for whoever reads the stock ledger later, such as the delivery a recount refers to. Text of at most 200 characters.

--- a/docs/reference/cli.md
+++ b/docs/reference/cli.md
@@ -46,3 +46,3 @@ Changes the units on hand of one variant by a signed amount, records the change
 ```sh
-wp seocart stock adjust <variant_id> --delta=<delta> --reason=<reason> [--expected_on_hand=<expected_on_hand>] [--format=<format>]
+wp seocart stock adjust <variant_id> --delta=<delta> --reason=<reason> [--expected_on_hand=<expected_on_hand>] [--note=<note>] [--format=<format>]
 ```
@@ -59,2 +59,3 @@ wp seocart stock adjust <variant_id> --delta=<delta> --reason=<reason> [--expect
 - `[--expected_on_hand=<expected_on_hand>]`: The units on hand the client last read. When given, the change applies only while the variant still has exactly that many, so a repeated request cannot apply it twice. An integer of at least 0.
+- `[--note=<note>]`: A short remark for whoever reads the stock ledger later, such as the delivery a recount refers to. Text of at most 200 characters.
 - `[--format=<format>]`: Render the result in a particular format. One of `table`, `json`. Default `table`.
````

`docs/reference/errors.md` did not change. The error table lists the plugin's own coded errors, and
a note that is too long is refused by WordPress's validation, with its own error codes, before the
plugin's code runs. You would only touch `errors.md` if you added a new error code to a module's
catalog and declared it in the operation's `errors:` list.

## 6. Every surface has the field

### REST

The route accepts the field:

```text
$ api -X POST "$SITE/wp-json/seocart/v1/stock-items/1/adjustments" -d '{"delta":1,"reason":"received","note":"Pallet 7 from the spring order"}'
{"variant_id":1,"on_hand":7,"allocated":0,"held":0,"available":7,"ledger_entry_id":3}
```

It validates it, against the limit and the type you declared, and answers in the plugin's one
error shape:

```text
$ api -X POST "$SITE/wp-json/seocart/v1/stock-items/1/adjustments" -d "{\"delta\":1,\"reason\":\"received\",\"note\":\"$(printf 'x%.0s' $(seq 1 201))\"}"
{"code":"rest_invalid_param","message":"Invalid parameter(s): note","data":{"status":400,"details":{"params":{"note":"note must be at most 200 characters long."},"param_codes":{"note":"rest_too_long"}},"correlation_id":"01a0ff18-2bdf-786a-958f-1063e8a1318a"}}

$ api -X POST "$SITE/wp-json/seocart/v1/stock-items/1/adjustments" -d '{"delta":1,"reason":"received","note":["a"]}'
{"code":"rest_invalid_param","message":"Invalid parameter(s): note","data":{"status":400,"details":{"params":{"note":"note is not of type string."},"param_codes":{"note":"rest_invalid_type"}},"correlation_id":"01a0ff18-2d52-78f0-8af0-3188318f23e6"}}
```

Neither refused request changed the stock.

### The Ability

The input schema now has the property, with the limit and the description:

```text
$ wp --path="$WP_PATH" --user=admin eval '$a = wp_get_ability( "seocart/adjust-stock" ); echo wp_json_encode( $a->get_input_schema()["properties"]["note"], JSON_PRETTY_PRINT ), "\n";'
{
    "type": "string",
    "maxLength": 200,
    "description": "A short remark for whoever reads the stock ledger later, such as the delivery a recount refers to."
}
```

Executing the ability accepts a valid note and refuses a long one:

```text
$ wp --path="$WP_PATH" --user=admin eval '$r = wp_get_ability( "seocart/adjust-stock" )->execute( array( "variant_id" => 1, "delta" => 1, "reason" => "received", "note" => "Pallet 7 from the spring order" ) ); echo wp_json_encode( is_wp_error( $r ) ? array( $r->get_error_code(), $r->get_error_message() ) : $r ), "\n";'
{"variant_id":1,"on_hand":8,"allocated":0,"held":0,"available":8,"ledger_entry_id":4}

$ LONG=$(printf 'x%.0s' $(seq 1 201)) wp --path="$WP_PATH" --user=admin eval '$r = wp_get_ability( "seocart/adjust-stock" )->execute( array( "variant_id" => 1, "delta" => 1, "reason" => "received", "note" => getenv( "LONG" ) ) ); echo wp_json_encode( is_wp_error( $r ) ? array( $r->get_error_code(), $r->get_error_message() ) : $r ), "\n";'
["ability_invalid_input","Ability \"seocart\/adjust-stock\" has invalid input. Reason: input[note] must be at most 200 characters long."]
```

The declaration marks adjusting stock as destructive
(`annotations: new Annotations( read_only: false, destructive: true, idempotent: false )`), and a
destructive operation cannot be declared public to outside clients such as the REST API, so the ability is
exercised here through its PHP interface.

### WP-CLI

The command's synopsis and its help have the option:

```text
$ wp --path="$WP_PATH" help seocart stock adjust
…
SYNOPSIS

  wp seocart stock adjust <variant_id> --delta=<delta> --reason=<reason>
  [--expected_on_hand=<expected_on_hand>] [--note=<note>] [--format=<format>]
…
  [--note=<note>]
    A short remark for whoever reads the stock ledger later, such as the
    delivery a recount refers to.
```

And the command takes it, and refuses one that is too long, with the same error code the REST
route gave:

```text
$ wp --path="$WP_PATH" --user=admin seocart stock adjust 1 --delta=1 --reason=received --note="Pallet 7 from the spring order"
Field	Value
variant_id	1
on_hand	9
allocated	0
held	0
available	9
ledger_entry_id	5

$ wp --path="$WP_PATH" --user=admin seocart stock adjust 1 --delta=1 --reason=received --note="$(printf 'x%.0s' $(seq 1 201))"
Error: rest_too_long: input[note] must be at most 200 characters long.
```

### OpenAPI

It is the first diff of step 5: the `note` property, with its `maxLength` and its example, is in
`docs/openapi.json`.

## 7. The generated files are current, and the contracts hold

```text
$ composer docs:check
ok     readme.txt (readme-external-services)
ok     docs/openapi.json (openapi)
ok     docs/reference/abilities.md (reference-abilities)
ok     docs/reference/cli.md (reference-cli)
ok     docs/reference/errors.md (reference-errors)
ok     docs/reference/hooks.md (reference-hooks)
```

Then run the contract tests, which walk every operation in the registry and check every rule in
[the next section](#the-rules-a-reviewer-applies). The first half needs no database. The second
needs the integration database from [development.md](development.md#set-up-the-integration-suite):

```text
$ composer test:contracts
PHPUnit 9.6.36 by Sebastian Bergmann and contributors.
…
OK (135 tests, 757 assertions)
…
OK (56 tests, 412 assertions)
```

### What the contracts catch

Change the example to something the field would refuse, here by setting `max_length: 10` in the
declaration while the example is still 30 characters long, and the contract test says so in plain
words:

```text
$ composer test:integration -- --filter DeclaredExamplesValidateTest
…
1) SEOCart\Tests\Integration\Operations\DeclaredExamplesValidateTest::test_every_example_validates_against_its_compiled_schemas
Declared examples the API would refuse:
  inventory.adjust_stock: the example of the input field note is refused by its REST argument: note must be at most 10 characters long.
  inventory.adjust_stock: the input examples are refused by the input schema: input[note] must be at most 10 characters long.
  inventory.adjust_stock: the input examples are refused by the OpenAPI schema: input[note] must be at most 10 characters long.
…
FAILURES!
Tests: 2, Assertions: 9, Failures: 1.
```

Put `max_length` back to `200`, so that the field you keep is a passing one, and run
`composer docs:check` again (it needs no regeneration, because the generated files were never
built from the `10`).

## 8. What the service does with it

Nothing, yet. `OperationInvoker::prepare()` keeps the fields the operation declares, so the
service now receives `$input['note']`, where before it never saw it. But
`StockService::adjustStock()` reads only the fields it knows, and the stock ledger has no column for
a note. That is why the stock changed identically in every run above.

Making the field _do_ something is a second, separate piece of work, in the layers below the
declaration: the service reads the value and passes it on, the ledger records it, and that needs a
column ([migrations.md](migrations.md#adding-a-column)), a classification for it, and tests. The
declaration does not change again. The surfaces already offer the field, validate it and document
it.

## 9. Revert the field

This walkthrough is a document, and the repository does not keep the example field. So undo it,
and check that the generated files are back in step with the declaration:

```text
$ git checkout -- src/Inventory/Application/InventoryOperations.php docs/openapi.json docs/reference/abilities.md docs/reference/cli.md
$ git status --short
$ composer docs:check
ok     readme.txt (readme-external-services)
ok     docs/openapi.json (openapi)
ok     docs/reference/abilities.md (reference-abilities)
ok     docs/reference/cli.md (reference-cli)
ok     docs/reference/errors.md (reference-errors)
ok     docs/reference/hooks.md (reference-hooks)
```

You can also keep it as your first contribution: finish step 8, add the tests, and open a pull
request. Either way, remove the `~/.walkthrough-curl` and `~/.walkthrough-password` files you made in step 1 when you are
done.

## The rules a reviewer applies

The same rules are the "DRY" section of the
[pull request template](../.github/pull_request_template.md), which numbers them. Each rule is
checked by something you can read and run. Where the check is a person, the table says so.

| #   | The rule                                                                                                            | What checks it                                                                                                                                                                                                                                                                                                                                                          |
| --- | ------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Every REST route, Ability and command resolves to one operation id, in both directions, or is a listed signed route | `tests/Integration/Operations/OperationSurfacesTest.php` walks the routes, abilities and commands the plugin registers against the registry, and holds the maintenance commands and the signed routes to lists with a reason each                                                                                                                                       |
| 2   | A field's type, constraints, default and example are declared once: no JSON Schema literal outside `Support\Schema` | The `SEOCart.DRY.SchemaLiteral` sniff, in `composer cs` and `composer cs:dry`                                                                                                                                                                                                                                                                                           |
| 3   | Schemas are compiled, never copied: one compiler function per dialect, with one call site                           | `tests/Unit/Application/Operations/CompilerCallSitesTest.php`                                                                                                                                                                                                                                                                                                           |
| 4   | No class exists only to mirror another surface                                                                      | A reviewer. `SurfaceParityTest` shows one operation driven through all three surfaces with the same outcome                                                                                                                                                                                                                                                             |
| 5   | Generated documents are committed and drift-tested                                                                  | `composer docs:check`, which prints the command that regenerates, and the `docs-drift` job in continuous integration                                                                                                                                                                                                                                                    |
| 6   | A generator that skips something must fail: skips are an asserted set                                               | `tools/Docs/DocsRunner.php` fails a run whose skipped items differ from what the generator declares; `tools/Docs/Tests/DocsRunnerTest.php` shows it                                                                                                                                                                                                                     |
| 7   | No money arithmetic in `Interfaces`: adapters format, never compute                                                 | The `SEOCart.DRY.MoneyArithmeticInInterfaces` sniff, and `tests/Unit/Order/NoMoneyArithmeticTest.php` for the order, checkout and payment modules                                                                                                                                                                                                                       |
| 8   | One error table: every error code has exactly one row, and every row is used                                        | `tests/Unit/Support/Error/ErrorTableTotalityTest.php`; `tests/Unit/Application/Operations/DeclaredErrorCodesTest.php` for the codes an operation declares                                                                                                                                                                                                               |
| 9   | One permission path: every operation route's permission comes from the shared factory                               | `tests/Integration/Authorization/RoutePermissionWalkTest.php`, which fails any route of the plugin's namespaces that has no permission callback or the wrong kind; the one route with no operation, the webhook route, is held to `PermissionCallback::signed()` by `OperationSurfacesTest`                                                                             |
| 10  | Every field carries a privacy class, and a stored personal-data column declares its erasure                         | A table column cannot be declared without a class, and a `pii` column not without its erasure handling (`ColumnSpec`), checked against a real database by `CoverageTest`. No exporter or eraser exists yet; log redaction reads the classes today. An operation field defaults to `Privacy::Public`, so a reviewer must catch a personal-data field left at the default |
| 11  | A hand-kept list is held equal to what exists by a test, in both directions                                         | For example `KernelListsTest`, `RegisteredMigrationsTest`, `FilterDeclarationsTest`, `DoctorListsTest` and `TestGroupsTest`                                                                                                                                                                                                                                             |
| 12  | Declarations are data: building the registry calls no WordPress function                                            | `tests/Unit/Application/Operations/DeclarationsAreDataTest.php`, and the same kind of test for the data registry, the settings and the table declarations                                                                                                                                                                                                               |
| 13  | Every declared example validates against its own compiled schema                                                    | `tests/Integration/Operations/DeclaredExamplesValidateTest.php`, shown failing in step 7                                                                                                                                                                                                                                                                                |
| 14  | Documentation is generated, so a hand-written region of a generated file cannot grow                                | Any hand edit makes the file drift, and `composer docs:check` fails                                                                                                                                                                                                                                                                                                     |
| 15  | A shared abstraction names, in its docblock, the one fact it owns                                                   | A reviewer. The classes you have read here each state the one fact they own in their docblock                                                                                                                                                                                                                                                                           |

Two more rules are not on that list but a reviewer applies them to a change like yours. Domain
code calls no WordPress function, which is why its tests run with no WordPress
([architecture.md](architecture.md#the-four-layers)). And a pull request changes a generated file
only by regenerating it from a changed declaration, never by hand.

The checks are what the repository enforces; the template is the checklist you fill in. When you
add a check of your own, prove that it can fail first
([testing.md](testing.md#the-planted-violation-rule)).

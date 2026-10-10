# Architecture tour

This page explains how the code is organised, so that you can find your way around it and
know where a change belongs. It takes about twenty minutes to read. It describes the code as it
is today and points at the files that hold each fact. When a statement here and the code
disagree, the code is right, and this page needs fixing.

SEOCart is one WordPress plugin built as a **modular monolith**: one deployable unit, with
business modules that keep to their own directories under `src/` and meet only through narrow,
named seams. Nothing in it is loaded until a request needs it. The main file, `seocart.php`,
registers an autoloader and hands control to the kernel on `plugins_loaded`.

## The modules

Every directory under `src/` is one of these.

| Directory          | What it is                                                                                                                                                                                                                                         |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Support/`     | The shared kernel, with no WordPress dependency: `Money`, `Decimal`, `Currency`, `Clock`, `IdGenerator`, addresses, locales, the error table, the domain event base, and the schema classes (`FieldSpec`, `ResourceSchema`, `JsonSchemaCompiler`). |
| `src/Application/` | The operation mechanism: `OperationDefinition`, the lazy `OperationRegistry`, and `Operations::registry()`, the one list of every operation the plugin exposes.                                                                                    |
| `src/Interfaces/`  | The adapters that turn an operation into a REST route, an Ability and a WP-CLI command, and the one `OperationInvoker` they share.                                                                                                                 |
| `src/Platform/`    | What every module stands on: the kernel and its module wiring, the database layer, the data registry, events and the outbox, background jobs, logging, authorization, rate limiting, secrets, settings, localization and the doctor command.       |
| `src/Catalog/`     | Products and their variants and prices, the product post type that gives each product its editorial face, the rule that decides whether a variant can be sold, and the product REST controller and editor panel.                                   |
| `src/Inventory/`   | Stock per variant: the items, an append-only ledger, expiring checkout holds, durable order allocations, and the stock adjustment operation.                                                                                                       |
| `src/Pricing/`     | Price resolution, exchange rates and the currencies a store sells in, and the pure, two-phase calculation that turns a cart into totals.                                                                                                           |
| `src/Tax/`         | The vocabulary of tax: the cross-zone policy, the effective rate and the rounding mode. The calculation in Pricing applies them.                                                                                                                   |
| `src/Promotion/`   | Promotions that apply by code: finding them, evaluating them into the effects the calculation carries out, and a usage ledger whose limit holds under concurrency.                                                                                 |
| `src/Cart/`        | A shopper's cart and its lines, the cart token, and the Store API operations that read and change the cart.                                                                                                                                        |
| `src/Checkout/`    | The checkout session, idempotency keys, and the placement of an order from a cart, including the switch of a cart to another currency.                                                                                                             |
| `src/Order/`       | Orders: their status machine, the snapshot of what was bought, the order number, and who may see an order.                                                                                                                                         |
| `src/Payment/`     | Payment intents, the gateway port with the stub gateway the plugin ships with, the payment ledger, and refunds.                                                                                                                                    |

`src/Platform/Admin/`, `src/Platform/Cache/` and `src/Platform/PrivateFiles/` hold only a
`.gitkeep`: they are reserved and empty.

## The four layers

A business module has up to four directories inside it:

```text
src/Inventory/
    Interfaces/       how the outside world reaches the module (REST, Store API, admin)
    Application/      services: one use case each, run as a unit of work
    Domain/           the rules, as plain PHP: value objects, state machines, ports
    Infrastructure/   MySQL repositories, migrations, jobs, doctor checks, WordPress adapters
```

Dependencies point one way: `Interfaces → Application → Domain ← Infrastructure`. Interfaces
call application services. Application services use the domain, and reach storage, time and
the outside world through **ports**: interfaces such as `StockRepository`, `Clock` and
`PaymentGateway`. Infrastructure implements those ports. The domain depends on nothing that
depends on it.

Two rules follow from that shape, and the code keeps to them:

- **The domain calls no WordPress function.** That is why the rules of pricing, tax, stock and
  promotions are tested as plain PHP, with no WordPress and no database
  ([testing.md](testing.md#layers)). The one exception is the message of an error: each
  module's error catalog (for example `src/Catalog/Domain/CatalogError.php`) holds its
  messages as closures that call `__()`, and a closure runs only when a message is rendered.
  The unit suite loads no WordPress, and the pure-data tests, such as
  `tests/Unit/Application/Operations/DeclarationsAreDataTest.php`, count every WordPress function
  a declaration calls.
- **Interfaces never do money arithmetic.** An adapter formats amounts and never computes them.
  The `SEOCart.DRY.MoneyArithmeticInInterfaces` sniff in
  `tools/phpcs/SEOCart/Sniffs/DRY/MoneyArithmeticInInterfacesSniff.php` catches the direct
  spellings, and `tests/Unit/Order/NoMoneyArithmeticTest.php` applies the same rule to the order,
  checkout and payment modules.

Not every module has every layer. Tax is only a domain. Inventory has no `Interfaces` directory
because its one operation is declared in its `Application` layer and served by the shared
adapters in `src/Interfaces/`.

## How a module is wired into the kernel

A module does not register itself. The kernel knows each module from a few lists. For most of
them a companion test fails when the list is missing something that exists; the list of services
and hooks has no such test.

| What                                           | Where                                                                                                                  | The test that fails when it is forgotten                                                                                                                                                    |
| ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Its tables, migrations, options and job groups | One `Contribution` line in `OwnedData::registry()`                                                                     | `tests/Unit/Platform/DataRegistry/RegisteredMigrationsTest.php`, `OwnedDataTest.php`, and `tests/Integration/DataRegistry/CoverageTest.php`, which cover its tables, migrations and options |
| Its services and its hooks                     | A `{module}Register()` and, if it adds a hook, a `{module}Subscribe()` method in `src/Platform/Kernel/Modules.php`     | None lists them. The container throws when a service nobody bound is asked for, which is how a forgotten binding shows                                                                      |
| Its error catalog                              | `Modules::ERROR_CATALOGS`                                                                                              | `tests/Unit/Platform/Kernel/KernelListsTest.php`                                                                                                                                            |
| Its domain events                              | `Modules::EVENT_CLASSES`                                                                                               | `tests/Unit/Platform/Kernel/KernelListsTest.php`                                                                                                                                            |
| A maintenance command (one with no REST twin)  | `Modules::MAINTENANCE_COMMANDS`, and the list with each command's reason in `tests/Support/OperationSurfaceWalker.php` | `tests/Integration/Operations/OperationSurfacesTest.php`                                                                                                                                    |
| A signed route (one with no operation)         | A `register()` line in the kernel's `rest_api_init` callback; the route in `OperationSurfaceWalker::SIGNED_ROUTES`     | `tests/Integration/Operations/OperationSurfacesTest.php`, `tests/Integration/Authorization/RoutePermissionWalkTest.php`                                                                     |
| Its operations                                 | One `$registry->add()` line in `Operations::registry()`                                                                | `tests/Integration/Operations/OperationSurfacesTest.php`, `tests/Integration/Authorization/RoutePermissionWalkTest.php`                                                                     |
| Its doctor checks                              | `Doctor::checks()` and the lists `Modules` builds                                                                      | `tests/Integration/Cli/DoctorListsTest.php`                                                                                                                                                 |

Two properties of the wiring matter for performance. `Modules::register()` only binds
**factories** to the container, and `Modules::subscribe()` only adds hooks whose callbacks
resolve a service when the hook fires. Neither builds a service, reads an option or runs a
query. `tests/Integration/Performance/IdleBudgetTest.php` measures what an idle request, one that
touches no commerce, costs with the plugin active, and states how many files and hooks it may
add. It holds the cost down; it does not check that every service is bound.

## One operation, every surface

The most important idea in the code is that a use case is declared once and every way of
reaching it is compiled from that declaration.

An `OperationDefinition` (`src/Application/Operations/OperationDefinition.php`) is plain data:
the operation's id, its summary, its input as a list of `FieldSpec` objects, its output shape,
the capability it needs, the error codes it can fail with, its annotations (read-only,
destructive, idempotent), the service method that performs it, and which surfaces it appears
on. `src/Inventory/Application/InventoryOperations.php` declares `inventory.adjust_stock`, and
that one declaration produces:

- the REST route `POST seocart/v1/stock-items/{variant_id}/adjustments`;
- the Ability `seocart/adjust-stock`;
- the command `wp seocart stock adjust <variant_id>`;
- the entries in `docs/openapi.json` and in the generated references under `docs/reference/`.

[adding-an-operation.md](adding-an-operation.md) changes that declaration and shows all four
changing together.

### The path of one request

This is what happens when an administrator sends `POST /wp-json/seocart/v1/stock-items/1/adjustments`:

1. **The route exists because of the registry.** On `rest_api_init`, the kernel's callback in
   `Modules.php` calls `RestAdapter::register()`. For every operation in the registry that
   declares a REST binding, it calls `register_rest_route()` with the arguments compiled from
   the operation's fields, the permission callback from `PermissionFactory`, and a callback that
   hands the request to the invoker.
2. **WordPress validates the arguments.** The compiled arguments carry each field's type, its
   bounds and its allowed values, so a request that breaks one is refused with `rest_invalid_param`
   before any plugin code that does work has run.
3. **The permission check runs.** `PermissionFactory::forRest()` builds a `PermissionCallback`
   that asks `current_user_can()` for the capability the operation declares. The plugin's one
   `map_meta_cap` callback, `CapabilityMapper`, answers for the plugin's capabilities and fails
   closed.
4. **The invoker prepares the input.** `OperationInvoker::prepare()` keeps only the declared
   fields, applies declared defaults and sanitizes each value with the operation's own schema.
   A field the operation does not declare never reaches the service.
5. **The service runs.** `OperationInvoker::invoke()` resolves the service class from the
   container and calls the declared method (`StockService::adjustStock()`) with the prepared
   input and an `Actor`, which names who is acting: the current user on REST and in Abilities,
   the `--user` on the command line. The service checks the same capability itself with the
   `Authorizer`, then does its work.
6. **The answer is shaped.** A `CodedException` becomes the one documented error shape, with its
   HTTP status, details and a correlation id; any other exception becomes a generic internal
   error and is reported, never shown. A success is serialized through the output schema, which
   leaves out secrets and leaves out personal data unless the actor may see it.

The Ability and the command validate their input, check the permission and call the same
invoker, through `AbilitiesAdapter` and `CliAdapter`. The three surfaces share the validation
rules, the permission check and the failures a service raises, so they cannot disagree about
what is allowed or what went wrong. Each surface reports a refusal of the input in its own
way: REST answers with a `rest_*` code such as `rest_invalid_param`, an Ability with
`ability_invalid_input`, and a command with a `rest_*` code too; the messages name the same field
(`tests/Integration/Interfaces/SurfaceParityTest.php` drives one operation through all three and
checks exactly that).

The operations that shoppers use, the Store API under `seocart/store/v1`, are declared the same
way, with no capability: they are public reads and public writes, and a public write declares
the rate limit it is counted against and whether it needs a cart that already exists. They exist
on the REST surface only.

One route is no operation's: the webhook route,
`POST seocart/v1/webhooks/{gateway_id}/{mode}`, where a payment provider delivers its events, one
address per gateway and mode. Its input is a raw body and the provider's headers, and its only
answer is `{ received: true }`, so it has no Ability and no command
(`src/Checkout/Interfaces/Rest/WebhookRoute.php`). It is guarded by the fourth kind of
permission callback, a signed request (`PermissionCallback::signed()`), whose policy checks only
that the request arrived as a write and that its body is present and at most 1 MB; the receiver
has the gateway verify the signature before anything of the body is read. The kernel registers it
on the same `rest_api_init` callback, and `OperationSurfaceWalker::SIGNED_ROUTES` lists it with
that reason.

## Units of work

Everything that must change together changes inside one database transaction, through the
`TransactionManager` port that application services depend on (`src/Platform/Database/TransactionManager.php`;
`Database` implements it).

- Transactions **nest** with savepoints. Only the outermost level commits, and only the outermost
  level may retry: when a deadlock or a lock-wait timeout aborts it, the whole unit of work runs
  again from the start, after the rollback has finished.
- A unit asks for its **isolation level** when it starts. A unit that changes stock asks for
  `READ COMMITTED`, so a lock on one item never waits on another's.
- **Contended changes are one conditional `UPDATE`.** The invariant is in its `WHERE` clause. The
  stock adjustment is `UPDATE … SET on_hand = on_hand + %d WHERE variant_id = %d AND on_hand + %d >= 0`
  (`MysqlStockRepository::ADJUST`), so two requests that race can never take a count below zero,
  and a refused update is turned into a coded error.
- **Nothing slow or irreversible happens inside a transaction.** No outbound HTTP request, no
  `wp_mail()`, no DDL. `TransactionGuards` watches for each (`pre_http_request`, `pre_wp_mail`,
  the `query` filter). When the site's environment type is `local` or `development` it throws;
  elsewhere it reports. The
  calculation refuses to run inside a transaction at all, because its quotes may come over the
  network.
- Work that must wait for the commit is registered with `afterCommit()`, and is dropped if the
  unit rolls back.

### The two units of work of a placement

`PlaceOrder` (`src/Checkout/Application/PlaceOrder.php`) places an order in **two** units of
work, with the payment gateway called between them and outside any transaction.

The **first unit** locks what it needs in one fixed order for every placement, so two placements
cannot deadlock each other: the cart, the idempotency key, the stock items in ascending order, the
order number and the order's rows, the promotions in ascending order, and the payment intent.
Inside it the cart moves to `placing`, the key is claimed, the sale decision is read again, the
stock is held, the order is written from the totals the calculation produced, promotion uses are
recorded, and the key is completed with the answer a retry will get. Any refusal rolls all of it
back.

The **second unit**, `SettlePlacement`, applies what the gateway answered through a single
path, and then settles the stock, the promotions and the cart by what the
payment came to: a decline releases everything the order held and opens the cart again. If the
gateway cannot be reached, the order waits as placed and a reconciliation job asks the gateway
later. Both units run at `READ COMMITTED` and retry on a deadlock.

## Money

An amount of money is an integer count of minor units plus an ISO 4217 currency
(`src/Support/Money.php`). There are no floats. Amounts combine only within one currency; a
`ConversionContext` is the only way across. Integer arithmetic is checked, so an overflow throws
instead of turning into a float. Multiplying by a rate gives a `Decimal`, and turning a decimal
back into money always names a rounding mode. Splitting an amount allocates by largest remainder,
so the parts add up to the whole.

**Only the calculation produces totals.** The engine in `src/Pricing/Domain/Engine/` is a pure
function of its input: it does no I/O and holds no state, and
`tests/Unit/Pricing/EngineHasNoIoTest.php` proves both. `Calculator` gathers the input around
it, takes shipping and tax quotes once, and runs the engine's two phases. A cart, a checkout and
an order show or copy those figures. An order stores a **snapshot** of the totals it was placed
with (`order_totals`) and never adds anything up again, which `NoMoneyArithmeticTest` holds.
Money crosses a currency boundary only through a conversion at a recorded rate, and an order keeps
the conversion context it was placed at.

## The transactional outbox

When something happens that other code may need to know about, such as a stock adjustment or a
placed order, the service **publishes a domain event** through the `EventPublisher` port, inside
its unit of work. The publisher (`src/Platform/Events/Publisher.php`) checks the event first, then
does one of two things depending on the event's delivery mode:

- **`outbox`**: the event is written as a row in the `outbox` table, in the same transaction as the
  change it describes. A rollback removes the row too, and a commit can never be without it. This is
  for anything that must not be lost: money, stock, orders.
- **`after_commit`**: the event is delivered from memory right after the commit, and is lost if the
  request dies. This is for hints whose loss is harmless.

After the commit, the `OutboxDrainer` claims pending rows, rebuilds each event and fires it as a
`seocart_` WordPress action through the `HookBridge`, then marks the row. Delivery is **at least
once**: a listener must be safe to run twice, and a failing listener is contained and reported
without stopping the others. A row that cannot be delivered is retried with a growing delay and
then parked. The request that published drains at its end, `wp seocart outbox drain` and the job
runner drain too, and the generated [hooks reference](reference/hooks.md) lists every event and
its payload.

## Where the data lives

- **The editorial face of a product is a WordPress post** of the type `seocart_product`
  (`src/Catalog/Infrastructure/ProductPostType.php`). Title, content, excerpt, image, permalink,
  revisions and the block editor all come from WordPress. The post type serves REST at
  `wp/v2/seocart-products`, through a controller that saves the post and the product's commerce
  data in one request.
- **Commerce data lives in the plugin's own InnoDB tables**, named `{prefix}seocart_{name}`:
  products, variants and prices, stock and its ledger, carts, checkout sessions, orders and their
  snapshots, payments and refunds, promotions, exchange rates, the outbox, logs and the
  migrator's own bookkeeping. A product's post and its commerce identity are tied by the
  `product_posts` table. On a multisite network each site has its own set.
- **There are no foreign keys.** The DDL generator never writes one. What a foreign key would
  do is done in code: a unit of work writes related rows together, a conditional `UPDATE` guards
  an invariant, and a `TableDefinition` can declare an orphan policy for each of its
  relationships. `wp seocart doctor` has checks, such as the stock projection, the payment
  ledger and the promotion usage checks, that find the inconsistencies a missing constraint would
  otherwise let through.
- **Every table is declared once**, in a `TableDefinition` in the module that owns it, and both
  the migration that creates it and the data registry that lists it read that one declaration.
  [migrations.md](migrations.md) explains how.
- **Settings** are declared once, with the same `FieldSpec` machinery as an operation's input,
  and stored through the settings store. Only one option of the plugin is autoloaded: the
  installation record, `seocart_boot`, which the kernel reads on every request, and the data
  registry refuses a second autoloaded option.

## Where to go next

- [adding-an-operation.md](adding-an-operation.md): add a field to a real operation and watch
  every surface change.
- [migrations.md](migrations.md): add a table or a column.
- [development.md](development.md) and [testing.md](testing.md): set up, and run every test layer.
- `docs/reference/`: the generated references for abilities, commands, error codes and hooks,
  and `docs/openapi.json` for the REST API.

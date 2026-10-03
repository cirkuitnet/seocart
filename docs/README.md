# SEOCart Documentation

This directory holds the documentation for people who work on SEOCart. It is not shipped in
the release zip. Documentation for merchants lives in `readme.txt` and, later, in the
plugin's own help screens.

## Working on the plugin

| Document                                                                     | What it covers                                                                                                                                                                 |
| ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| [development.md](development.md)                                             | Setting up a working copy: requirements, Composer and npm, Strauss, the test database, disposable sites                                                                        |
| [architecture.md](architecture.md)                                           | A tour of the code: the modules, the four layers, how a module is wired in, how a request reaches a service, units of work, money, the outbox and where the data lives         |
| [adding-an-operation.md](adding-an-operation.md)                             | A walkthrough that adds a field to an operation and shows the REST route, the Ability, the command and the OpenAPI document change together, with the rules a reviewer applies |
| [migrations.md](migrations.md)                                               | How a migration is declared and ordered, what the migrator guarantees, and how to add a table or a column                                                                      |
| [testing.md](testing.md)                                                     | The test layers, every gate command, the planted-violation rule and the determinism rules                                                                                      |
| [releasing.md](releasing.md)                                                 | Versioning, how the release zip is built, the WordPress.org gates, and the manual deployment to the plugin directory                                                           |
| [maintenance/rest-posts-controller.md](maintenance/rest-posts-controller.md) | What the product's REST controller overrides or reproduces of WordPress core, to check against each WordPress major                                                            |

The contribution process is in [CONTRIBUTING.md](../CONTRIBUTING.md). The definition of done
is the [pull request template](../.github/pull_request_template.md), and that file is its
only copy. Vulnerability reports follow [SECURITY.md](../SECURITY.md).

## Generated reference

`docs/openapi.json` and the files under `docs/reference/` — abilities, CLI commands, error
codes and hooks — are **generated** by `composer docs:generate` from the operation registry,
the error catalogs, the event catalog and the filter declarations, and checked for drift by
`composer docs:check`, which prints the command to run when a file is out of date. Do not edit
them by hand: change the declaration and regenerate.
[adding-an-operation.md](adding-an-operation.md) shows the whole cycle. The same command also
regenerates the "External services" section of `readme.txt`, and the check covers it.

## Terms

"Legacy" means the proprietary SEOCart engine whose _behaviour_ the plugin reimplements. Its
source code is not part of this repository.

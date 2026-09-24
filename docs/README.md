# SEOCart Documentation

This directory holds the documentation for people who work on SEOCart. It is not shipped in
the release zip. Documentation for merchants lives in `readme.txt` and, later, in the
plugin's own help screens.

## Working on the plugin

| Document                         | What it covers                                                                                                       |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| [development.md](development.md) | Setting up a working copy: requirements, Composer and npm, Strauss, the test database, disposable sites              |
| [testing.md](testing.md)         | The test layers, every gate command, the planted-violation rule and the determinism rules                            |
| [releasing.md](releasing.md)     | Versioning, how the release zip is built, the WordPress.org gates, and the manual deployment to the plugin directory |

The contribution process is in [CONTRIBUTING.md](../CONTRIBUTING.md). The definition of done
is the [pull request template](../.github/pull_request_template.md), and that file is its
only copy. Vulnerability reports follow [SECURITY.md](../SECURITY.md).

## Generated reference

`docs/openapi.json` and the files under `docs/reference/` — abilities, CLI commands, error
codes and hooks — are **generated** by `composer docs:generate` from the operation registry,
the error catalogs, the event catalog and the filter declarations, and checked for drift by
`composer docs:check`, which prints the command to run when a file is out of date. Do not edit
them by hand: change the declaration and regenerate. The registry has no operations yet, so the
API and command references are still empty.

## Terms

"Legacy" means the proprietary SEOCart engine whose _behaviour_ the plugin reimplements. Its
source code is not part of this repository.

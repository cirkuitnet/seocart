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

## Architecture

Start with [architecture/overview.md](architecture/overview.md). It explains how the
documents below fit together and in which order to read them.

| Document                                                                   | What it covers                                                                                              |
| -------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| [architecture/target-architecture.md](architecture/target-architecture.md) | **The decision-bearing document.** Style, layers, modules, storage, checkout, extensibility, support policy |
| [architecture/domain-map.md](architecture/domain-map.md)                   | The ubiquitous language, the modules and their aggregates, and who may call whom                            |
| [architecture/data-storage.md](architecture/data-storage.md)               | Every table, its keys and indexes, and the reasoning behind the storage choices                             |
| [architecture/extensibility.md](architecture/extensibility.md)             | The public surface: contracts, registries, events and the curated hooks                                     |
| [architecture/security.md](architecture/security.md)                       | Trust boundaries, authorization, payment safety, secrets, privacy and the threat list                       |
| [architecture/performance.md](architecture/performance.md)                 | The measurable budgets, where each is measured, and the hot-path designs                                    |

If two documents disagree, `target-architecture.md` wins and the other document has a bug.

## Architecture decision records

[adr/README.md](adr/README.md) explains when to write an ADR, gives the template, and
indexes the accepted records, ADR-0001 to ADR-0021. An accepted ADR is never edited to change
its decision; a new ADR supersedes it.

## Generated reference

`docs/openapi.json` and the files under `docs/reference/` — abilities, CLI commands, error
codes and hooks — are **generated** from the operation registry by `composer docs:generate`
and checked for drift by `composer docs:check`. Do not edit them by hand. The registry has no
operations yet, so there is nothing to generate so far.

## References to unpublished material

The architecture documents and the ADRs were written during the project's planning phase,
next to research notes, review records and planning documents that are **not published**.
The imported documents keep their citations so that the reasoning stays traceable, but those
citations are plain text, not links:

- `research/NN` and "internal research note NN" name a planning-phase research note.
- `reviews/…`, `DISPOSITIONS.md` and finding ids such as `CX-08`, `SO-10` or `WP-07` name a
  planning-phase review and the record of how each finding was resolved.
- `test-strategy.md`, `repo-ci-worktree-strategy.md`, `wordpress-org-compliance.md`,
  `migration-strategy.md`, `open-questions.md` and `phase-1-backlog.md` name planning
  documents. Their durable content is in [testing.md](testing.md),
  [development.md](development.md) and [releasing.md](releasing.md).
- "Legacy" means the proprietary SEOCart engine whose _behaviour_ the plugin reimplements.
  Its source code is not part of this repository.

The imported documents are copies with redactions: internal environment details were removed,
links were repointed to this tree, and the text was reformatted by Prettier. Nothing else was
changed.

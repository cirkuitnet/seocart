# Contributing to SEOCart

Thank you for helping. This page tells you how work is organised, what a change must satisfy,
and where the details are. Everyone who takes part follows the
[Code of Conduct](CODE_OF_CONDUCT.md).

**Found a security problem?** Do not open an issue. Follow [SECURITY.md](SECURITY.md).

## Before you start

1. Read the [README](README.md), especially the principles. SEOCart is free, with no feature
   gating, no telemetry and no upsell surface. A change that conflicts with a principle is
   declined, however well it is built.
2. Read [docs/architecture/overview.md](docs/architecture/overview.md). The
   [target architecture](docs/architecture/target-architecture.md) is the decision-bearing
   document. Do not contradict it in code; propose the change through an
   [architecture decision record](docs/adr/README.md) instead.
3. For anything larger than a small fix, open an issue first and agree the approach before
   you write the code.

## Two ways to work

The project has two groups of contributors, and they set up differently.
[docs/development.md](docs/development.md) has the full steps for both.

### Maintainers and their coding agents

The maintainers, and the AI coding agents that work under their direction, use a shared
development server: one git worktree per task, a real MySQL server, and one disposable
WordPress site per worktree, created and destroyed by the scripts in `bin/dev/`. That server
is private. Access to it is neither needed nor offered for outside contributions.
[AGENTS.md](AGENTS.md) holds the standing rules for agents.

### Everyone else

You need nothing private. Follow the [quick start in the README](README.md#quick-start), then
run [the gates](README.md#the-gates).

The unit suite never loads WordPress, so PHP and Composer are all it needs. To run the plugin,
use any local WordPress site that meets the [README requirements](README.md#requirements) —
for example
[`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/),
which needs Docker. The integration suite needs a MySQL database;
[docs/development.md](docs/development.md) explains how to point it at yours.

## Rules for every change

- **One piece of knowledge, one declaration.** Don't repeat a rule, a formula, a field's type
  or an identifier list. The pull request template lists the fifteen checks.
- **Respect the layers.** Domain code calls no WordPress function. Application services are
  the only entry to a use case. REST, Abilities, WP-CLI, blocks and admin screens are adapters.
- **Never copy WooCommerce or Elementor** source, tests, comments or documentation text. They
  may be studied for ideas only.
- **No secrets.** No credentials, tokens, host names or customer data in any file, commit,
  log or report.
- **License.** SEOCart is GPL-3.0-or-later, and your contribution is accepted under the same
  license. Every PHP file starts with a file docblock that carries `@package SEOCart`,
  `@since` and `@license GPL-3.0-or-later`. A bundled library must be GPLv3-compatible;
  `composer licenses:check` enforces that.
- **Code style.** Docblocks and inline comments follow the WordPress core inline
  documentation standard. Classes are namespaced PSR-4 with PascalCase names and camelCase
  methods. Hook, option, capability and table names are snake_case with the `seocart_` prefix.
  `composer cs` checks all of it, and `composer cs:fix` fixes what can be fixed automatically.
- **Tests come with the code.** See [docs/testing.md](docs/testing.md). When you add a check
  that can fail a build, prove that it fails: plant a violation, watch the check go red, then
  remove the violation.

## Branches and commits

- Development is trunk-based. `main` is always releasable. Work on a short-lived branch named
  `feature/<slug>`, and open a pull request against `main`.
- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).
  The types in use are `feat`, `fix`, `docs`, `refactor`, `test`, `build`, `ci` and `chore`.
  For example: `fix(cart): reject a negative quantity`.
- Keep commits small and coherent. Say what changed and why in the body.
- Tags mark releases and nothing else. Only a maintainer creates one, as a step of the
  release process in [docs/releasing.md](docs/releasing.md).
- Add an entry under `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md) when your change alters
  behaviour that a merchant or a developer can see.

## Review

Every change is reviewed by someone who did not write it, and nothing is merged without that
review. A second, senior WordPress review is also required for:

- the first slice of a new module;
- any change to the platform kernel;
- any deviation from the target architecture.

Reviewers check the change against the layer rules and the DRY rules, not only against the
tests. Expect to be asked for an ADR when a decision is consequential and hard to reverse;
[docs/adr/README.md](docs/adr/README.md) says when one is needed.

## Definition of done

The [pull request template](.github/pull_request_template.md) **is** the definition of done.
It opens in every new pull request. Check every row; mark a row that does not apply as "n/a"
with one clause that says why. The checklist is kept in that one file on purpose, so it is
not repeated here.

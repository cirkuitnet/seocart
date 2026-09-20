# SEOCart

SEOCart is a free, open-source e-commerce plugin for WordPress, licensed under
GPL-3.0-or-later. It is planned as a complete store — products, cart, checkout, orders and
payments — that feels native to WordPress and keeps transactional guarantees where WordPress
primitives cannot give them.

This README is for contributors. The description that merchants see in the WordPress.org
plugin directory lives in `readme.txt`.

## Status

**Pre-release foundation. The plugin sells nothing yet.**

The repository currently holds the skeleton that the store will be built on: a main file
that checks versions and boots an empty kernel, the directory layout, the build tooling, the
quality gates and the test harnesses. There are no products, no cart, no checkout, no
database tables and no admin screens. Do not install it on a production site.

The architecture is decided and documented; see [docs/](docs/README.md).
[CHANGELOG.md](CHANGELOG.md) records what exists so far.

## Principles

These are fixed. A contribution that conflicts with one of them is declined.

- **Free.** Every feature ships in the one free plugin. The launch payment gateways are
  separate plugins, and they are free too.
- **No feature gating.** Nothing is locked, limited or held back for a paid tier.
- **No telemetry.** The plugin does not track sites, merchants or shoppers. Every external
  service it can contact is disclosed in `readme.txt`.
- **No upsell.** There is no advertising, promotion or marketing surface in the admin.
- **WordPress.org rules apply.** The plugin follows the WordPress.org plugin guidelines and
  the Plugin Developer FAQ.
- **Don't repeat yourself.** Each piece of knowledge — a business rule, a field's type, an
  error code, a schema — is declared once, and every interface that needs it is derived or
  generated from that declaration. Duplicated knowledge is a defect, and automated checks
  enforce the rule.

## Requirements

| To run the plugin     | Version       |
| --------------------- | ------------- |
| PHP                   | 8.3 or newer  |
| WordPress             | 7.1 or newer  |
| MySQL (with InnoDB)   | 8.0 or newer  |
| MariaDB (with InnoDB) | 10.6 or newer |

The other contributor pages link to this table instead of repeating it.

To work on the plugin you also need Composer, Node.js, npm and, for the integration suite, a
real MySQL server. [docs/development.md](docs/development.md#requirements) lists those tools
and their versions.

## Quick start

```sh
git clone https://github.com/cirkuitnet/seocart.git
cd seocart
composer install
npm ci
npm run build
```

`composer install` also runs Strauss, which copies the runtime libraries into
`vendor-scoped/`. `npm run build` compiles `assets/` into `build/` and checks the result
against the size budget in `budget.json`.

To try the plugin in WordPress, place or symlink the checkout at
`wp-content/plugins/seocart` and activate **SEOCart** on the Plugins screen. It activates
cleanly and does nothing else yet.

[docs/development.md](docs/development.md) has the full setup, including the database for
the integration suite and disposable test sites.

## The gates

Run these before you open a pull request. Both must pass.

```sh
composer gates    # every PHP gate that needs neither a database nor a browser
npm run lint      # ESLint, Stylelint and the Prettier check
```

`composer test:integration` needs a MySQL database, and `npm run test:e2e` needs a running
WordPress site. [docs/testing.md](docs/testing.md#commands) lists every command and says what
each one runs, and the [pull request template](.github/pull_request_template.md) says which
of them a change must pass.

## Documentation

- [docs/README.md](docs/README.md) — the index of all documentation.
- [docs/development.md](docs/development.md) — setting up a working copy.
- [docs/testing.md](docs/testing.md) — the test layers, the commands and the rules for
  writing tests.
- [docs/releasing.md](docs/releasing.md) — how a release is packaged and checked.
- [docs/architecture/overview.md](docs/architecture/overview.md) — where to start reading
  the architecture.
- [docs/adr/](docs/adr/README.md) — why each consequential decision was made.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) first. Everyone who takes part is expected to
follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Security

Do not report a vulnerability in a public issue. [SECURITY.md](SECURITY.md) explains how to
report one privately.

## License

SEOCart is free software, released under the
[GNU General Public License, version 3 or later](LICENSE).

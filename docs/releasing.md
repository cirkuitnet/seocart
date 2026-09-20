# Releasing

This page describes how a version of SEOCart is numbered, packaged, checked and published.
SEOCart is distributed through the WordPress.org plugin directory, so the directory's
guidelines and its Plugin Developer FAQ are release gates, not a launch-week checklist.

## Versions

- SEOCart follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0 the
  version is `0.MINOR.PATCH`: a breaking change raises **MINOR**, anything else raises
  **PATCH**. There is no compatibility promise before 1.0.
- Tags are annotated and named `vX.Y.Z`. A tag is only ever placed on a commit of `main`
  whose gates are green.
- Releases before 1.0 are published as GitHub releases only. They are not deployed to the
  WordPress.org directory.

One version number appears in several places, and they must agree:

| Place                              | Checked by                                        |
| ---------------------------------- | ------------------------------------------------- |
| `Version` header in `seocart.php`  | —                                                 |
| `SEOCART_VERSION` in `seocart.php` | `composer test:unit` (the version agreement test) |
| `version` in `package.json`        | `composer test:unit` (the version agreement test) |
| `Stable tag` in `readme.txt`       | `composer wporg:check`                            |
| The git tag                        | `php bin/check-wporg.php --tag=vX.Y.Z`            |

The same unit test keeps the `Requires PHP` and `Requires at least` headers in step with the
constants in `seocart.php` and with `composer.json`.

## Prepare the release

1. Make sure `main` is green: every gate in [testing.md](testing.md), including the
   integration suite.
2. Raise the version in every place in the table above.
3. In [CHANGELOG.md](../CHANGELOG.md), move the entries under `[Unreleased]` to a new heading
   for the version, with the release date. Leave an empty `[Unreleased]` section.
4. Update `Tested up to` in `readme.txt` if a new WordPress version has been tested.
5. Run `composer docs:check`. The generated parts of `readme.txt` and of `docs/` must be
   free of drift.
6. Merge that change through a pull request like any other. Then create the annotated tag
   `vX.Y.Z` on the merge commit. Pushing the tag starts the release workflow. Tags are
   created for releases only, and only by a maintainer.

## Build the package

The release workflow runs these steps on a clean checkout of the tag. You can run the same
steps by hand to inspect a package before you tag.

```sh
composer install
npm ci
npm run build
php bin/build-zip.php
php bin/check-zip.php dist/seocart-X.Y.Z.zip
```

1. `composer install` installs the tools and runs Strauss, which writes the prefixed runtime
   libraries — and the unprefixed Action Scheduler — to `vendor-scoped/`.
2. `npm ci` and `npm run build` compile `assets/` into `build/` and enforce the asset
   budget.
3. `bin/build-zip.php` writes `dist/seocart-X.Y.Z.zip` and `dist/SHA256SUMS`. It leaves out
   every path that `.distignore` lists: tests, `docs/`, development configuration, `bin/`,
   `tools/`, the uncompiled `assets/`, `node_modules/` and the unscoped `vendor/`.
4. `bin/check-zip.php` inspects the zip that was built and **fails closed**: a top-level
   entry that is not on its allow-list fails the build. A zip that has not passed this check
   is never published.

Nothing in the package is downloaded at run time or on activation. Every dependency is in
the zip.

`.gitattributes` marks development paths `export-ignore` as well, so that GitHub's "Download
ZIP" is not mistaken for a release. A unit test keeps that list and `.distignore` in step.

## The WordPress.org gates

Every gate below blocks a release. Most of them also run on every pull request, so that a
release is never the first time a problem is seen.

| Gate                      | Fails when                                                                                                                                                                               | How it runs                            |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------- |
| **Zip size**              | The zip is larger than the project's **5 MB budget**. The directory's hard limit is **10 MB**; the check fails there whatever the budget says                                            | `php bin/check-zip.php <zip>`          |
| **Zip contents**          | The zip holds tests, `docs/`, development configuration, `node_modules/` or an unscoped `vendor/`; or Action Scheduler has been prefixed                                                 | `php bin/check-zip.php <zip>`          |
| **Version agreement**     | The `Version` header, the `Stable tag` in `readme.txt` and the git tag do not all agree                                                                                                  | `php bin/check-wporg.php --tag=vX.Y.Z` |
| **Readme validator**      | `Stable tag` is `trunk`; `Tested up to` is not a real WordPress version; the license is not `GPLv3 or later` with a license URI; there are more than five tags; a tag names a competitor | `composer wporg:check`                 |
| **Licence allow-list**    | A Composer or npm **runtime** dependency has a license that is not compatible with GPLv3                                                                                                 | `composer licenses:check`              |
| **External services**     | The generated "External services" section of `readme.txt` has drifted from the registry of outbound endpoints                                                                            | `composer docs:check`                  |
| **No executable content** | `eval` or `create_function` appears anywhere in the plugin                                                                                                                               | `composer cs`                          |
| **Plugin Check**          | The official Plugin Check tool reports any error or security finding **against the built zip**, not against the source tree. Warnings are tracked and must reach zero before 1.0         | The continuous integration workflow    |
| **Install smoke**         | The built zip does not install and activate on a clean WordPress site, or it produces a PHP notice under `WP_DEBUG`                                                                      | The continuous integration workflow    |

Three more directory rules are enforced by tests as the features they concern arrive: no
credit link on the storefront with default settings, no third-party `iframe` on an admin
screen, and no sitewide admin notice that cannot be dismissed.

## Publish

1. The release workflow attaches the zip and `SHA256SUMS` to a GitHub release and takes the
   release notes from the version's section of `CHANGELOG.md`.
2. It installs **that exact zip** on a clean WordPress site. A release that fails its own
   install check is not published.

## Deploy to the WordPress.org directory

**SVN is release-only, and the deployment is manual.**

- Development happens on GitHub. The WordPress.org SVN repository only ever receives tagged
  releases, with meaningful commit messages. It is never used for development.
- Deployment is a separate workflow that a maintainer starts by hand for a release that is
  already published on GitHub. It never runs automatically when a tag is pushed, because
  **the push to SVN is what makes a version live, and there is no off switch**.
- The workflow mirrors the contents of the checked zip into `trunk/` and `tags/X.Y.Z/`. It
  does not build the package a second time.
- `tags/` keeps the current and the previous major version.
- The banner, the icon and the screenshots use SEOCart's own branding only, with no
  third-party logo.

Two facts about the directory shape the plan. WordPress.org reviews one plugin per author at
a time, so SEOCart and its three gateway plugins are four sequential reviews, with SEOCart
first. And approval is not publication: a plugin goes live with its first SVN push.

## The gateway plugins

Stripe, PayPal Complete Payments and Authorize.Net are three separate, free plugins, each in
its own repository. Each one is released in the same way, from its own repository, under its
own version number. Each declares `Requires Plugins: seocart`, and its install check installs
it on top of the current SEOCart release.

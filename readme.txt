=== SEOCart ===
Contributors: cirkuitnet
Tags: ecommerce, shop, cart, checkout, store
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A free, open-source store plugin in early development. Version 0.1.0 is a development foundation and does not sell anything yet.

== Description ==

SEOCart is a free, open-source e-commerce plugin for WordPress, and it is at the very start of its development.

**Version 0.1.0 is a development foundation. It does not sell anything yet.** It adds no products, no cart, no checkout, no payment methods and no settings screens. Activating it changes nothing that a visitor or an administrator can see. It exists so that the project's code layout, quality checks and release packaging can be built and tested in the open before any store feature is written.

Do not install this version on a site that needs a working store.

The project's goal is a complete store for WordPress. That work has not been released. This readme describes a feature only once a release contains it.

= Principles that already apply =

* **Free.** Everything the project releases is part of this plugin. There is no paid edition and there are no licence keys.
* **No feature gating.** Nothing is locked, limited or held back for an upgrade.
* **No telemetry.** The plugin does not track you and sends no usage data anywhere.
* **No upsell.** The plugin shows no advertisements, no promotions and no upgrade notices.
* **No credits on your site.** The plugin adds no "powered by" link to your pages.

== Installation ==

The minimum WordPress and PHP versions are listed with this plugin's details. WordPress will not activate SEOCart on a site that does not meet them.

1. In your site's dashboard, go to **Plugins > Add Plugin**, select **Upload Plugin**, choose the SEOCart zip file and select **Install Now**. Alternatively, unpack the zip and upload the `seocart` folder to the `/wp-content/plugins/` directory.
2. Activate SEOCart on the **Plugins** screen.

There is nothing to configure in version 0.1.0.

== Frequently Asked Questions ==

= Can I sell products with this version? =

No. Version 0.1.0 is a development foundation and contains no store features.

= Is there a paid version? =

No. SEOCart is free software under the GNU General Public License, version 3 or later. There is no paid edition, no licence key and no feature that is held back.

= Does SEOCart collect data about my site or my visitors? =

No. The plugin contains no telemetry. The "External services" section lists every server the plugin contacts, and the "Privacy" section says what it does with personal data.

= What happens to my data when I delete the plugin? =

Version 0.1.0 stores nothing, so deleting it leaves nothing behind.

= Where do I report a bug? =

Open an issue at https://github.com/cirkuitnet/seocart/issues.

= How do I report a security issue? =

Please do not open a public issue. Write to security@seocart.com with the details, and allow time for a fix before you disclose the issue publicly.

== External services ==

SEOCart does not connect to any external service: it sends no data from your site to any other server.

== Source code and build steps ==

The complete, human-readable source code of this plugin, including the source of every compiled script and stylesheet, is public at https://github.com/cirkuitnet/seocart.

To build the plugin from source you need PHP, Composer, and Node.js with npm. The supported PHP version is listed under `require` in `composer.json`, and the supported Node.js and npm versions are listed under `engines` in `package.json`. In the root of the repository, run:

1. `composer install`
2. `npm ci`
3. `npm run build`
4. `php bin/build-zip.php`

`composer install` installs the PHP dependencies and copies the runtime libraries into `vendor-scoped/`. `npm ci` and `npm run build` compile the scripts and stylesheets in `assets/` into `build/`. `php bin/build-zip.php` assembles the installable zip in `dist/`.

== Privacy ==

SEOCart 0.1.0 does not collect, store or share personal data. It sets no cookies and contains no telemetry. On activation it creates its own database tables, which hold no personal data in this version. The "External services" section lists every server the plugin contacts.

== Changelog ==

= 0.1.0 =
* Development foundation: the plugin's main file, code layout, quality checks and release packaging. This version contains no store features.

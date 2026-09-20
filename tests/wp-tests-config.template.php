<?php
/**
 * Template of the WordPress configuration the integration test suite runs with
 *
 * This file is never loaded. bin/dev/provision-test-db.sh (on a development machine) and
 * bin/ci/prepare-integration.sh (in continuous integration) copy it to
 * tests/wp-tests-config.local.php, which git ignores, and replace the five tokens. A token is
 * one of the names below between two underscores on either side:
 *
 *     WP_CORE_DIR  absolute path of the WordPress checkout the tests load
 *     DB_NAME      a database used by nothing else: installing the test site DROPS ITS TABLES
 *     DB_USER      a MySQL user with every privilege on that database
 *     DB_PASSWORD  that user's password
 *     DB_HOST      host, host:port, or localhost:/path/to/mysql.sock
 *
 * The tokens are spelled out only where they are used, in the define() calls below. Both
 * writers replace every occurrence in the file, so a token spelled out in this comment would
 * put the database password into the generated file a second time, and would let a writer
 * find all five tokens in a template whose define() calls had lost one.
 *
 * Each token sits inside a single-quoted PHP string and is replaced verbatim, so a value must
 * contain no single quote, no backslash and no line break. No real value may ever be written
 * here.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride.Prohibited -- every name in this file is dictated by WordPress and its test library.

// WordPress requires the trailing slash; the token may come with or without one.
define( 'ABSPATH', rtrim( '__WP_CORE_DIR__', '/' ) . '/' );

define( 'DB_NAME', '__DB_NAME__' );
define( 'DB_USER', '__DB_USER__' );
define( 'DB_PASSWORD', '__DB_PASSWORD__' );
define( 'DB_HOST', '__DB_HOST__' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Distinct from the prefix of any real site, so a misdirected run is recognizable at a glance.
$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'SEOCart integration tests' );

// The test library installs WordPress in a child process; it must be the PHP that runs PHPUnit.
define( 'WP_PHP_BINARY', PHP_BINARY );

define( 'WPLANG', '' );

// Zero notices under WP_DEBUG is an acceptance criterion, so the suite always runs with it on.
define( 'WP_DEBUG', true );

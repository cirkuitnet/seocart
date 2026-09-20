#!/bin/sh
#
# Prepares a checkout for `composer test:integration`.
#
# Usage: sh bin/ci/prepare-integration.sh <wp-version|nightly>
#
#   <wp-version>  Anything download-wordpress.sh accepts: an exact release ("7.1.1"), a
#                 release line ("7.1"), "latest" or "nightly".
#
# Environment:
#   SEOCART_CI_DB_HOST      Required. Database host, with the port when it is not 3306.
#   SEOCART_CI_DB_USER      Required.
#   SEOCART_CI_DB_PASSWORD  Required. May be empty.
#   SEOCART_CI_DB_NAME      Required. The database must already exist and must be
#                           disposable: the WordPress test library drops and recreates
#                           its tables on every run.
#   WP_CORE_DIR             Optional. Where WordPress core goes. Defaults to a directory
#                           under $RUNNER_TEMP (or $TMPDIR) named after the version.
#   SEOCART_CI_OVERWRITE    Optional. Set to 1 to replace an existing
#                           tests/wp-tests-config.local.php.
#
#   No value, WP_CORE_DIR included, may hold a single quote, a backslash or a line break:
#   the template replaces its tokens verbatim inside single-quoted PHP strings.
#
# It downloads WordPress core and writes tests/wp-tests-config.local.php (ignored by git)
# from tests/wp-tests-config.template.php. In CI the database values are the throwaway
# credentials of the job's MySQL service container. On a developer machine
# bin/dev/provision-test-db.sh is the usual way to get the same file; this script never
# creates or drops a database.

set -eu

fail() {
	printf 'prepare-integration: %s\n' "$*" >&2
	exit 1
}

[ "$#" -eq 1 ] || fail 'usage: prepare-integration.sh <wp-version|nightly>'

wp_version=$1

for name in SEOCART_CI_DB_HOST SEOCART_CI_DB_USER SEOCART_CI_DB_PASSWORD SEOCART_CI_DB_NAME; do
	eval "is_set=\${$name+set}"
	[ "${is_set:-}" = set ] || fail "the environment variable $name is not set."
done

[ -n "$SEOCART_CI_DB_HOST" ] || fail 'SEOCART_CI_DB_HOST is empty.'
[ -n "$SEOCART_CI_DB_USER" ] || fail 'SEOCART_CI_DB_USER is empty.'
[ -n "$SEOCART_CI_DB_NAME" ] || fail 'SEOCART_CI_DB_NAME is empty.'

command -v php >/dev/null 2>&1 || fail 'php is required but was not found on PATH.'

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
root=$(CDPATH='' cd -- "$script_dir/../.." && pwd)
template=$root/tests/wp-tests-config.template.php
config=$root/tests/wp-tests-config.local.php

[ -f "$template" ] || fail "$template does not exist."

if [ -e "$config" ] && [ "${SEOCART_CI_OVERWRITE:-0}" != 1 ]; then
	fail "$config already exists. Set SEOCART_CI_OVERWRITE=1 to replace it."
fi

core_dir=${WP_CORE_DIR:-${RUNNER_TEMP:-${TMPDIR:-/tmp}}/seocart-wordpress-$wp_version}
# ABSPATH in the template supplies its own trailing slash.
core_dir=${core_dir%/}

sh "$script_dir/download-wordpress.sh" "$wp_version" "$core_dir"

# The file holds a database password: nobody but its owner may read it.
umask 077

# Values travel through the environment, never the argument list, so the password does not
# show up in the process table.
#
# The rules are the template's own, and bin/dev/lib.sh (sc_render_template) applies the
# same two: every token sits inside a single-quoted PHP string and is replaced verbatim,
# so a value may hold no single quote, backslash or line break; and the tokens filled
# here must be exactly the tokens the template holds. A token the template gained would
# otherwise reach the configuration as a literal, and the suite would run against it.
# shellcheck disable=SC2016
SEOCART_CI_TEMPLATE=$template SEOCART_CI_CONFIG=$config SEOCART_CI_CORE_DIR=$core_dir php -r '
	$values = array(
		"__DB_NAME__"     => "SEOCART_CI_DB_NAME",
		"__DB_USER__"     => "SEOCART_CI_DB_USER",
		"__DB_PASSWORD__" => "SEOCART_CI_DB_PASSWORD",
		"__DB_HOST__"     => "SEOCART_CI_DB_HOST",
		"__WP_CORE_DIR__" => "SEOCART_CI_CORE_DIR",
	);

	$stop = static function ( string $message ): void {
		fwrite( STDERR, "prepare-integration: " . $message . PHP_EOL );
		exit( 1 );
	};

	$template = (string) file_get_contents( (string) getenv( "SEOCART_CI_TEMPLATE" ) );

	preg_match_all( "/__[A-Z][A-Z_]*__/", $template, $found );

	$unfilled = array_diff( array_unique( $found[0] ), array_keys( $values ) );
	$missing  = array_diff( array_keys( $values ), $found[0] );

	if ( array() !== $unfilled ) {
		$stop( "the template has tokens this script does not fill: " . implode( " ", $unfilled ) );
	}

	if ( array() !== $missing ) {
		$stop( "the template does not contain: " . implode( " ", $missing ) );
	}

	foreach ( $values as $token => $variable ) {
		$value = (string) getenv( $variable );

		// chr( 39 ) is a single quote, chr( 92 ) a backslash, chr( 10 ) and chr( 13 ) line breaks.
		if ( false !== strpbrk( $value, chr( 39 ) . chr( 92 ) . chr( 10 ) . chr( 13 ) ) ) {
			$stop( $variable . " contains a single quote, a backslash or a line break, which the template cannot hold." );
		}

		$values[ $token ] = $value;
	}

	if ( false === file_put_contents( (string) getenv( "SEOCART_CI_CONFIG" ), strtr( $template, $values ) ) ) {
		$stop( "could not write the configuration." );
	}
' || fail "$config was not written."

php -l "$config" >/dev/null || fail "$config is not valid PHP."

printf 'prepare-integration: wrote %s (WordPress core: %s)\n' "$config" "$core_dir"

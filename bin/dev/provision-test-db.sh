#!/bin/sh
#
# Creates the PHPUnit integration-test database of one checkout and points the checkout
# at it. See README.md in this directory.

set -eu

SC_DEV_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
# shellcheck source=bin/dev/lib.sh
. "$SC_DEV_DIR/lib.sh"

usage() {
	cat <<EOF
usage: provision-test-db.sh <slug> [--checkout=<path>] [--wp-core-dir=<path>]

Creates the MySQL database <prefix>test_<slug> (utf8mb4) and the account
seocart_test_<slug>, which can reach nothing else, then fills
tests/wp-tests-config.template.php into tests/wp-tests-config.local.php (gitignored,
mode 600) inside the checkout. <prefix> is this server's SEOCART_DEV_DB_PREFIX
(default "$SC_DB_PREFIX_DEFAULT"); README.md explains it.

Running it again keeps the database, gives the account a new password and rewrites
the file.

  <slug>                lower-case a-z, 0-9 and "-", at most $SC_SLUG_MAX characters
  --checkout=<path>     the checkout to configure (default: the git work tree around
                        the current directory)
  --wp-core-dir=<path>  the WordPress core checkout PHPUnit loads
                        (default: $SEOCART_DEV_WP_CORE_DIR)

Exit codes: 0 done, 1 failed, 2 usage error or invalid slug.
EOF
}

slug=
checkout_option=
core_dir=$SEOCART_DEV_WP_CORE_DIR

for argument in "$@"; do
	case $argument in
		-h | --help)
			usage
			exit 0
			;;
		--checkout=*)
			checkout_option=${argument#*=}
			;;
		--wp-core-dir=*)
			core_dir=${argument#*=}
			;;
		-*)
			sc_usage_error "unknown option: $argument"
			;;
		*)
			[ -n "$argument" ] || sc_usage_error "empty argument"
			[ -z "$slug" ] || sc_usage_error "unexpected argument: $argument"
			slug=$argument
			;;
	esac
done

[ -n "$slug" ] || sc_usage_error "missing <slug>"
sc_validate_slug "$slug" || exit 2

# Everything that can be checked without side effects comes first, so that a refusal
# further down leaves nothing behind.
sc_require_wp_cli
sc_require_commands mysql php sed grep
checkout=$(sc_resolve_checkout "$checkout_option")
template=$checkout/tests/wp-tests-config.template.php
[ -f "$template" ] || sc_die "$template not found; it belongs to the test harness, so this checkout cannot run integration tests yet"
case $core_dir in
	/*) ;;
	*)
		sc_die "--wp-core-dir must be an absolute path: $core_dir"
		;;
esac
[ -f "$core_dir/wp-settings.php" ] || sc_die "no WordPress core checkout at $core_dir (SEOCART_DEV_WP_CORE_DIR or --wp-core-dir)"
socket=$(sc_mysql_socket) || exit 1
sc_load_db_prefix || exit 1

database=$(sc_db_name test "$slug")
account=$(sc_account_name test "$slug")
password=$(sc_password) || exit 1

# Rendered before anything is created, into the private scratch directory: a template
# this script cannot fill stops the run here, with no database to clean up.
sc_tmp_init
rendered=$SC_TMPDIR/wp-tests-config.php
sc_render_template "$template" "$rendered" \
	"DB_NAME=$database" \
	"DB_USER=$account" \
	"DB_PASSWORD=$password" \
	"DB_HOST=$SC_DB_ACCOUNT_HOST:$socket" \
	"WP_CORE_DIR=$core_dir"

sc_create_database "$database" || exit 1
SC_FAILURE_HINT="provision-test-db.sh did not finish. Remove what it created with: sh $SC_DEV_DIR/teardown-site.sh $slug"
sc_create_account "$account" "$database" "$password" || exit 1

tests_config=$(sc_tests_config "$checkout")
mv -f -- "$rendered" "$tests_config" || sc_die "could not write $tests_config"
SC_FAILURE_HINT=

sc_info "Test database ready."
sc_info "  database: $database"
sc_info "  account:  $account@$SC_DB_ACCOUNT_HOST (its password is only in the config file)"
sc_info "  config:   $tests_config"
sc_info "  core:     $core_dir"

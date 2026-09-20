#!/bin/sh
#
# Removes everything provision-site.sh and provision-test-db.sh created for one slug.
# See README.md in this directory.

set -eu

SC_DEV_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
# shellcheck source=bin/dev/lib.sh
. "$SC_DEV_DIR/lib.sh"

usage() {
	cat <<EOF
usage: teardown-site.sh <slug> [--checkout=<path>]

Removes, for one slug, whatever of the following exists:

  - the instance directory <integration site>/$SC_INSTANCES_DIRNAME/<slug>/
  - its databases and its scoped MySQL accounts (the instance's and PHPUnit's)
  - the credentials file and the debug log below the state directory
  - tests/wp-tests-config.local.php in the checkout, if it names this slug's account

Partly provisioned state is expected, not an error: every step runs even if an
earlier one failed. The git worktree is left alone; teardown-worktree.sh removes it.
Confirm the result with check-residue.sh.

  <slug>              lower-case a-z, 0-9 and "-", at most $SC_SLUG_MAX characters
  --checkout=<path>   a checkout that was configured with provision-test-db.sh
                      --checkout (the instance's own checkout and
                      <worktrees root>/<slug> are looked at without being named)

Exit codes: 0 everything is gone, 1 something could not be removed, 2 usage error or
invalid slug.
EOF
}

slug=
checkout_option=

for argument in "$@"; do
	case $argument in
		-h | --help)
			usage
			exit 0
			;;
		--checkout=*)
			checkout_option=${argument#*=}
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

# What there is to remove is declared once, in lib.sh ("What exists for a slug");
# check-residue.sh walks the same declarations.

status=0
site_dir=$(sc_site_dir "$slug")
test_account=$(sc_account_name test "$slug")

# Local test configurations. Listed before the instance goes: one candidate is read from
# the instance's plugin link.
candidates=$(sc_tests_config_candidates "$slug" "$checkout_option")
while IFS= read -r candidate; do
	[ -n "$candidate" ] || continue
	tests_config=$(sc_tests_config "$candidate")
	if sc_tests_config_names_account "$tests_config" "$test_account"; then
		if rm -f -- "$tests_config"; then
			sc_info "removed $tests_config"
		else
			status=1
		fi
	fi
done <<EOF
$candidates
EOF

if [ -e "$site_dir" ] || [ -L "$site_dir" ]; then
	# In a subshell: a refusal must not stop the remaining steps.
	if (sc_remove_site_dir "$slug"); then
		sc_info "removed $site_dir"
	else
		sc_warn "could not remove $site_dir"
		status=1
	fi
fi
# The shared parent goes once the last instance is gone; rmdir refuses while one remains.
rmdir "$SC_INSTANCES_ROOT" 2>/dev/null || true

# One statement per call: a refusal of one must not skip the others.
sql_failed=no
if sc_load_db_prefix; then
	for kind in $SC_DB_KINDS; do
		database=$(sc_db_name "$kind" "$slug")
		if sc_drop_database "$database"; then
			sc_info "dropped database $database (if it existed)"
		else
			sc_warn "could not drop database $database"
			sql_failed=yes
		fi
	done
else
	sc_warn "the database names are unknown; no database was dropped"
	sql_failed=yes
fi
for kind in $SC_DB_KINDS; do
	account=$(sc_account_name "$kind" "$slug")
	if sc_drop_account "$account"; then
		sc_info "dropped account $account@$SC_DB_ACCOUNT_HOST (if it existed)"
	else
		sc_warn "could not drop account $account@$SC_DB_ACCOUNT_HOST"
		sql_failed=yes
	fi
done
if [ "$sql_failed" = yes ]; then
	status=1
	sc_print_grant_help
fi

while IFS= read -r file; do
	if [ -e "$file" ] || [ -L "$file" ]; then
		if rm -f -- "$file"; then
			sc_info "removed $file"
		else
			status=1
		fi
	fi
done <<EOF
$(sc_state_files "$slug")
EOF

if [ "$status" -eq 0 ]; then
	sc_info "Teardown of \"$slug\" complete. Confirm with: sh $SC_DEV_DIR/check-residue.sh $slug"
else
	sc_warn "teardown of \"$slug\" is incomplete; see the messages above, then run sh $SC_DEV_DIR/check-residue.sh $slug"
fi
exit "$status"

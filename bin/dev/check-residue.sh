#!/bin/sh
#
# The "no residue" gate: fails if anything provisioned for a slug still exists. The
# account probe's limited state-changing potential is explained below. See README.md in
# this directory.

set -eu

SC_DEV_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
# shellcheck source=bin/dev/lib.sh
. "$SC_DEV_DIR/lib.sh"

usage() {
	cat <<EOF
usage: check-residue.sh <slug> [--checkout=<path>] [--allow-unverified]

Looks for everything the provisioning scripts create for one slug and lists what is
still there:

  - the instance directory <integration site>/$SC_INSTANCES_DIRNAME/<slug>
  - its databases and its scoped MySQL accounts (the instance's and PHPUnit's)
  - the credentials file and the debug log below the state directory
  - a tests/wp-tests-config.local.php that still names this slug's test account, in
    <worktrees root>/<slug> or in --checkout

A check that cannot be carried out (the provisioning account may not look at the
database or at accounts) is not a pass: the script says which one and exits 3.

  <slug>               lower-case a-z, 0-9 and "-", at most $SC_SLUG_MAX characters
  --checkout=<path>    also look at this checkout's local test configuration
  --allow-unverified   report checks that could not be carried out, but do not fail
                       because of them

Exit codes: 0 no residue, 1 residue found, 2 usage error or invalid slug, 3 no residue
found but at least one check could not be carried out.
EOF
}

slug=
checkout_option=
allow_unverified=no

for argument in "$@"; do
	case $argument in
		-h | --help)
			usage
			exit 0
			;;
		--checkout=*)
			checkout_option=${argument#*=}
			;;
		--allow-unverified)
			allow_unverified=yes
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

residue=0
unverified=0

found() {
	residue=$((residue + 1))
	printf 'RESIDUE     %s\n' "$*"
}

could_not_check() {
	unverified=$((unverified + 1))
	printf 'UNVERIFIED  %s\n' "$*"
}

# Both functions set `state` to present, absent or unknown and, for unknown, `reason` to
# what MySQL answered.

# USE is the one statement that tells the three apart without changing anything: it
# succeeds on an existing database, fails with 1049 on a missing one the account may
# see, and with 1044 where the account may not look. SHOW DATABASES would silently
# leave out what the account may not see.
database_state() {
	reason=
	# The format's backticks are literal SQL syntax, so single quotes prevent shell expansion.
	# shellcheck disable=SC2016
	if state_output=$(printf 'USE `%s`;\n' "$1" | sc_sql 2>&1); then
		state=present
		return 0
	fi
	case $state_output in
		*'ERROR 1049'*)
			state=absent
			;;
		*)
			state=unknown
			reason=$(printf '%s\n' "$state_output" | head -n 1)
			;;
	esac
}

# The provisioning account cannot read mysql.user, so ALTER USER IF EXISTS plus its warning
# is the only available existence probe. That statement changes state if an account is
# locked. Only seocart_wt_<slug> and seocart_test_<slug> accounts are probed, and these
# scripts never lock them, so unlocking is a no-op for every account they own.
account_state() {
	reason=
	if state_output=$(printf "ALTER USER IF EXISTS '%s'@'%s' ACCOUNT UNLOCK;\nSHOW WARNINGS;\n" "$1" "$SC_DB_ACCOUNT_HOST" | sc_sql 2>&1); then
		case $state_output in
			*3162* | *'does not exist'*)
				state=absent
				;;
			*)
				state=present
				;;
		esac
		return 0
	fi
	state=unknown
	reason=$(printf '%s\n' "$state_output" | head -n 1)
}

# What there is to look for is declared once, in lib.sh ("What exists for a slug");
# teardown-site.sh walks the same declarations.

site_dir=$(sc_site_dir "$slug")
if [ -e "$site_dir" ] || [ -L "$site_dir" ]; then
	found "directory   $site_dir"
fi

while IFS= read -r file; do
	if [ -e "$file" ] || [ -L "$file" ]; then
		found "file        $file"
	fi
done <<EOF
$(sc_state_files "$slug")
EOF

test_account=$(sc_account_name test "$slug")
while IFS= read -r candidate; do
	[ -n "$candidate" ] || continue
	tests_config=$(sc_tests_config "$candidate")
	if sc_tests_config_names_account "$tests_config" "$test_account"; then
		found "test config $tests_config (names $test_account)"
	fi
done <<EOF
$(sc_tests_config_candidates "$slug" "$checkout_option")
EOF

if sc_load_db_prefix; then
	for kind in $SC_DB_KINDS; do
		database=$(sc_db_name "$kind" "$slug")
		database_state "$database"
		case $state in
			present)
				found "database    $database"
				;;
			unknown)
				could_not_check "database    $database: $reason"
				;;
		esac
	done
else
	could_not_check "databases   their names could not be determined (the error is above)"
fi

for kind in $SC_DB_KINDS; do
	account=$(sc_account_name "$kind" "$slug")
	account_state "$account"
	case $state in
		present)
			found "account     $account@$SC_DB_ACCOUNT_HOST"
			;;
		unknown)
			could_not_check "account     $account@$SC_DB_ACCOUNT_HOST: $reason"
			;;
	esac
done

if [ "$residue" -gt 0 ]; then
	printf '\n%s item(s) left over for "%s". Remove them with: sh %s/teardown-site.sh %s\n' "$residue" "$slug" "$SC_DEV_DIR" "$slug"
	exit 1
fi
if [ "$unverified" -gt 0 ]; then
	printf '\nNo residue found for "%s", but %s check(s) could not be carried out.\n' "$slug" "$unverified"
	if [ "$allow_unverified" = yes ]; then
		printf 'Accepted because of --allow-unverified.\n'
		exit 0
	fi
	sc_print_grant_help
	exit 3
fi
printf 'No residue for "%s".\n' "$slug"

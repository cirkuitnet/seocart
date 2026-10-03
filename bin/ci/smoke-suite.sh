#!/bin/sh
#
# Runs the baseline smoke tests: 17 checks, each one named command, in order.
#
# Usage: sh bin/ci/smoke-suite.sh [--list] [--only <numbers>] [--report <directory>] [<zip>]
#
#   --list                Prints the table (number, what the check proves, its command) and
#                         runs nothing.
#   --only <numbers>      Runs only the checks numbered, for example --only 3,9. For finding
#                         out why one check fails; a run that is not whole is not "all 17
#                         green".
#   --report <directory>  The reporting mode of CI. Nothing is built and nothing is installed:
#                         the checks that the jobs of the run already did are read from them
#                         (see below), and only the cheap PHPUnit checks run here.
#   <zip>                 The built plugin. Without it the suite builds one: `npm run build`,
#                         then `php bin/build-zip.php`.
#
# One line per check: "ok", "FAIL" or "ci" (proved by a CI job this machine cannot run, so the
# suite checks the thing it can). It exits non-zero when any check fails, so "all 17 green" is
# one answer. Without arguments it does everything itself, on a developer machine. It runs the
# whole integration suite and builds two zips, so it takes a while.
#
# The checks that run PHPUnit name their tests in the lists below and nowhere else. Each test of
# a list gets a PHPUnit call of its own, with --fail-on-empty-test-suite, so a renamed test
# fails the check instead of hiding behind one that still exists.
#
# The checks that install the zip read what bin/ci/install-smoke.sh proved: it runs once, for
# the first of them, and prints a "proved <name>" line for each fact.
#
# Environment:
#   SEOCART_CI_DB_HOST, SEOCART_CI_DB_USER, SEOCART_CI_DB_PASSWORD, SEOCART_CI_DB_NAME
#       The database of install-smoke.sh. Left out, they are read from
#       tests/wp-tests-config.local.php, which bin/ci/prepare-integration.sh writes in CI and
#       bin/dev/provision-test-db.sh writes on a dev box. install-smoke uses tables of its own
#       prefix in that database and drops them again.
#   SEOCART_CI_OLD_ZIP
#       The earlier zip of the upgrade proof. Left out, bin/ci/build-older-zip.sh builds it.
#
# With --report, the results of the jobs that ran the rest come in as:
#   <directory>      The files install-smoke.sh appended its "proved" lines to
#                    (SEOCART_CI_PROOF_FILE), one per run; every one must prove every fact.
#   SEOCART_CI_RESULT_PACKAGE
#                    The result of the job that ran install-smoke in every cell. Only
#                    "success" lets the install checks pass: a cell that failed uploaded no
#                    file, so the files that are there say nothing about it.
#   SEOCART_CI_RESULT_CS, _STAN, _UNIT, _INTEGRATION
#                    The result of the job that ran `composer cs`, `composer stan`,
#                    `composer test:unit` and `composer test:integration`: "success" passes the
#                    check, anything else, or nothing, fails it.
#   SEOCART_CI_SHA   The commit whose end-to-end run check 15 reads with `gh run list`
#                    (GH_TOKEN and GH_REPO in the environment). A commit that has no run is
#                    reported as "ci: not run for this commit", never as "ok".
#
# Needs what the checks need: Composer with the project's dependencies, a prepared integration
# suite (tests/wp-tests-config.local.php), WP-CLI, curl, unzip, git, and for a build Node.js.
# POSIX sh.

set -eu

# --- The tests the checks name ---------------------------------------------------------------
# One test (or class) per line; each line is a PHPUnit filter of its own.

# 3. Activation and migrations on an empty database: a fresh site installs, and the platform
#    bootstrap creates its tables.
tests_fresh_install='LifecycleTest::test_activating_a_fresh_site_installs_it$
MigratorTest::test_the_bootstrap_creates_the_platform_tables_and_records_itself$'

# 4. Deactivation: the plugin's jobs are cancelled, and no table, option or role goes.
tests_deactivation='LifecycleTest::test_deactivation_cancels_the_plugins_jobs$
LifecycleTest::test_deactivation_and_uninstallation_remove_nothing$'

# 6. Migrations are idempotent: activating again changes nothing, and a chain applies once.
tests_migrations_rerun='LifecycleTest::test_activating_again_changes_nothing$
MigratorTest::test_a_chain_applies_in_order_once$'

# 8. Authorization: who may do what, and who may read which order. Whole classes: every test
#    in them refuses somebody.
tests_authorization='AuthorizerTest::
PermissionCallbackTest::
OrderStatusRouteTest::'

# 9. Every REST route of the plugin has an explicit permission behaviour.
tests_route_walk='RoutePermissionWalkTest::'

# 10. A request that does not use the plugin starts none of its expensive subsystems.
tests_idle_budget='IdleBudgetTest::'

# 16. Uninstalling removes nothing, and every retention policy is in the catalogue.
tests_retention='LifecycleTest::test_deactivation_and_uninstallation_remove_nothing$
CoverageTest::test_every_retention_policy_is_in_the_catalog$'

# --- The machinery ----------------------------------------------------------------------------

fail() {
	printf 'smoke-suite: %s\n' "$*" >&2
	exit 1
}

usage='Usage: smoke-suite.sh [--list] [--only <numbers>] [--report <directory>] [<zip>]'
list_only=0
only=''
report_dir=''
zip=''

while [ "$#" -gt 0 ]; do
	case $1 in
		--list) list_only=1 ;;
		--only)
			[ "$#" -ge 2 ] || fail "--only needs the numbers of the checks. $usage"
			only=",$2,"
			shift
			;;
		--report)
			[ "$#" -ge 2 ] || fail "--report needs the directory of the install smoke results. $usage"
			report_dir=$2
			shift
			;;
		-*) fail "unknown option $1. $usage" ;;
		*)
			[ -z "$zip" ] || fail "one zip at most. $usage"
			zip=$1
			;;
	esac
	shift
done

root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"

work=$(mktemp -d "${TMPDIR:-/tmp}/seocart-smoke-suite.XXXXXX")
smoke_status=''
failures=0

cleanup() {
	status=$?
	trap - EXIT INT TERM
	rm -rf "$work"
	exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Prints one constant of tests/wp-tests-config.local.php. The value is for the environment, not
# for a screen.
test_config() {
	# The PHP source is a literal: nothing in it is meant to expand in the shell.
	# shellcheck disable=SC2016
	php -r 'include $argv[1]; echo constant( $argv[2] );' tests/wp-tests-config.local.php "$1" </dev/null
}

# Prints the path of the zip that the SHA256SUMS in directory $1 names: the file the build that
# wrote it wrote, never a stale zip that happens to match a glob.
zip_named_in() {
	[ -f "$1/SHA256SUMS" ] || fail "$1/SHA256SUMS does not exist: the build wrote no zip there."

	named=$(awk 'NF == 2 { sub( /^\*/, "", $2 ); print $2 }' "$1/SHA256SUMS")

	case $named in
		'' | *'
'*) fail "$1/SHA256SUMS must name exactly one zip." ;;
	esac

	[ -f "$1/$named" ] || fail "$1/SHA256SUMS names $named, which is not in $1."

	printf '%s/%s\n' "$1" "$named"
}

# Settles which zip, which earlier zip and which database install-smoke.sh gets.
prepare_install_smoke() {
	if [ -z "$zip" ]; then
		printf 'smoke-suite: no zip given; building one.\n' >&2
		npm run build >&2
		php bin/build-zip.php >&2
		zip=$(zip_named_in dist)
	fi

	[ -f "$zip" ] || fail "$zip does not exist."

	if [ -z "${SEOCART_CI_OLD_ZIP:-}" ]; then
		printf 'smoke-suite: no earlier zip given; building the one bin/ci/upgrade-from.env names.\n' >&2
		sh bin/ci/build-older-zip.sh "$work/older" >&2
		SEOCART_CI_OLD_ZIP=$(zip_named_in "$work/older")
	fi

	# Each value is taken from the test configuration when the environment does not set it; a
	# value is only ever assigned to its own variable.
	if [ -z "${SEOCART_CI_DB_HOST+set}" ]; then
		SEOCART_CI_DB_HOST=$(test_config DB_HOST)
	fi
	if [ -z "${SEOCART_CI_DB_USER+set}" ]; then
		SEOCART_CI_DB_USER=$(test_config DB_USER)
	fi
	if [ -z "${SEOCART_CI_DB_PASSWORD+set}" ]; then
		SEOCART_CI_DB_PASSWORD=$(test_config DB_PASSWORD)
	fi
	if [ -z "${SEOCART_CI_DB_NAME+set}" ]; then
		SEOCART_CI_DB_NAME=$(test_config DB_NAME)
	fi

	export SEOCART_CI_OLD_ZIP SEOCART_CI_DB_HOST SEOCART_CI_DB_USER SEOCART_CI_DB_PASSWORD SEOCART_CI_DB_NAME
}

# Runs install-smoke.sh once and keeps its output. Later checks read the same output. In the
# reporting mode there is nothing to run: the jobs of CI did.
run_install_smoke() {
	if [ -n "$report_dir" ] || [ -n "$smoke_status" ]; then
		return 0
	fi

	prepare_install_smoke
	smoke_status=0
	sh bin/ci/install-smoke.sh "$zip" >"$work/smoke.out" 2>&1 || smoke_status=$?
}

# Succeeds when install-smoke.sh passed and proved every named fact. In the reporting mode, when
# every file in the report directory proves every fact.
smoke_proved() {
	if [ -n "$report_dir" ]; then
		if [ "${SEOCART_CI_RESULT_PACKAGE:-}" != success ]; then
			printf 'the job that ran install-smoke ended with "%s", not "success"; a cell that failed left no result.\n' "${SEOCART_CI_RESULT_PACKAGE:-no result}"
			return 1
		fi

		proofs=$(find "$report_dir" -type f)

		if [ -z "$proofs" ]; then
			printf 'no install-smoke result under %s.\n' "$report_dir"
			return 1
		fi

		for proof in $proofs; do
			for fact in "$@"; do
				grep -qx "install-smoke: proved $fact" "$proof" || {
					printf '%s does not prove "%s".\n' "$proof" "$fact"
					return 1
				}
			done
		done

		return 0
	fi

	run_install_smoke

	if [ "$smoke_status" -ne 0 ]; then
		printf 'install-smoke.sh failed (status %s). The end of its output:\n' "$smoke_status"
		tail -n 25 "$work/smoke.out"
		return 1
	fi

	for fact in "$@"; do
		grep -qx "install-smoke: proved $fact" "$work/smoke.out" || {
			printf 'install-smoke.sh passed but did not prove "%s".\n' "$fact"
			return 1
		}
	done
}

# Succeeds when the job that ran composer script $1 succeeded. Only for the reporting mode.
reported_job_succeeded() {
	case $1 in
		cs) result=${SEOCART_CI_RESULT_CS:-} ;;
		stan) result=${SEOCART_CI_RESULT_STAN:-} ;;
		test:unit) result=${SEOCART_CI_RESULT_UNIT:-} ;;
		test:integration) result=${SEOCART_CI_RESULT_INTEGRATION:-} ;;
		*) result='' ;;
	esac

	if [ "$result" = success ]; then
		return 0
	fi

	printf 'the job that ran composer %s ended with "%s", not "success".\n' "$1" "${result:-no result}"
	return 1
}

# Succeeds when the files that carry the Playwright smoke test are all in place.
playwright_in_place() {
	for spec in tests/E2E/specs/*.spec.ts; do
		[ -f "$spec" ] && break
		printf 'no Playwright spec under tests/E2E/specs.\n'
		return 1
	done

	grep -q '"test:e2e":' package.json || {
		printf 'package.json has no test:e2e script.\n'
		return 1
	}

	grep -q 'npm run test:e2e$' .github/workflows/e2e.yml || {
		printf '.github/workflows/e2e.yml does not run npm run test:e2e.\n'
		return 1
	}
}

# Prints "ok", "FAIL" or "ci" for check 15 in the reporting mode, then a note: what the end-to-end
# workflow concluded for SEOCART_CI_SHA. The workflow runs when its paths change or by hand, so
# a commit without a run is the usual case, and it is never reported as "ok".
reported_end_to_end() {
	if [ -z "${SEOCART_CI_SHA:-}" ] || ! command -v gh >/dev/null 2>&1; then
		printf 'ci\nthe end-to-end result could not be read (no SEOCART_CI_SHA or no gh)\n'
		return 0
	fi

	if ! conclusion=$(gh run list --workflow e2e.yml --commit "$SEOCART_CI_SHA" --json conclusion \
		--jq 'if length == 0 then "none" else (.[0].conclusion | if . == "" or . == null then "pending" else . end) end' 2>"$work/gh.err"); then
		printf 'ci\nthe end-to-end result could not be read: %s\n' "$(head -n 1 "$work/gh.err")"
		return 0
	fi

	case $conclusion in
		success) printf 'ok\nthe end-to-end workflow succeeded for %s\n' "$SEOCART_CI_SHA" ;;
		none) printf 'ci\nnot run for this commit\n' ;;
		pending) printf 'ci\nthe end-to-end workflow has not finished for this commit\n' ;;
		*) printf 'FAIL\nthe end-to-end workflow concluded "%s" for %s\n' "$conclusion" "$SEOCART_CI_SHA" ;;
	esac
}

# One check: check <number> <what> <install-smoke facts, or -> <composer script, or -> [<tests>].
# <tests> is a list of PHPUnit filters, one per line; each runs on its own. Facts and a script
# together are both required. Neither marks a check a CI job proves.
check() {
	number=$1
	what=$2
	facts=$3
	script=$4
	tests=${5:-}

	if [ -n "$only" ]; then
		case $only in
			*",$number,"*) ;;
			*) return 0 ;;
		esac
	fi

	if [ "$facts" = - ]; then
		facts=''
	fi

	command=''
	if [ -n "$facts" ]; then
		command="sh bin/ci/install-smoke.sh <zip>  (proves: $facts)"
	fi
	if [ "$script" != - ]; then
		if [ -z "$tests" ]; then
			command=${command:+$command; }"composer $script"
		else
			while IFS= read -r entry; do
				command=${command:+$command; }"composer $script -- --filter '$entry' --fail-on-empty-test-suite"
			done <<EOF
$tests
EOF
		fi
	fi
	if [ -z "$command" ]; then
		command="proved by .github/workflows/e2e.yml; here: the spec files, the npm script and the job's command exist"
	fi

	if [ "$list_only" -eq 1 ]; then
		printf '%2d  %-62s  %s\n' "$number" "$what" "$command"
		return 0
	fi

	# Outside the redirect below: a zip that cannot be built or found stops the suite with its
	# own message on the terminal, not with a line in a file that nobody reads.
	if [ -n "$facts" ]; then
		run_install_smoke
	fi

	output=$work/check.out
	: >"$output"
	outcome=ok
	note=''

	if [ -n "$facts" ]; then
		# The facts are separate words by design.
		# shellcheck disable=SC2086
		smoke_proved $facts >>"$output" 2>&1 || outcome=FAIL
	fi

	if [ "$script" != - ]; then
		if [ -n "$tests" ]; then
			while IFS= read -r entry; do
				composer "$script" -- --filter "$entry" --fail-on-empty-test-suite </dev/null >>"$output" 2>&1 || outcome=FAIL
			done <<EOF
$tests
EOF
		elif [ -n "$report_dir" ]; then
			reported_job_succeeded "$script" >>"$output" 2>&1 || outcome=FAIL
		else
			composer "$script" >>"$output" 2>&1 || outcome=FAIL
		fi
	fi

	if [ -z "$facts" ] && [ "$script" = - ]; then
		if [ -n "$report_dir" ]; then
			reported=$(reported_end_to_end)
			outcome=$(printf '%s\n' "$reported" | sed -n 1p)
			note=$(printf '%s\n' "$reported" | sed -n 2p)
		elif playwright_in_place >>"$output" 2>&1; then
			outcome=ci
		else
			outcome=FAIL
		fi
	fi

	if [ "$outcome" = FAIL ]; then
		failures=$((failures + 1))
		printf 'FAIL  %2d  %s: %s\n' "$number" "$what" "${note:-$command}"
		tail -n 30 "$output" | sed -e 's/^/        /'
	else
		printf '%-4s  %2d  %s%s\n' "$outcome" "$number" "$what" "${note:+: $note}"
	fi
}

# --- The 17 checks ---------------------------------------------------------------------------

php_version=''
if [ "$list_only" -eq 0 ]; then
	php_version=" $(php -r 'echo PHP_VERSION;')"
fi

check 1 "Clean WordPress and PHP$php_version start" baseline -
check 2 'Install and activation raise no notice under WP_DEBUG' 'activation clean-log' -
check 3 'Activation and migrations run on an empty database' - test:migration "$tests_fresh_install"
check 4 'Deactivation leaves no broken hooks or corrupt data' deactivation test:integration "$tests_deactivation"
check 5 'Reactivation succeeds' reactivation -
check 6 'Migrations are idempotent and can run again' - test:migration "$tests_migrations_rerun"
check 7 'An order is written and read back' order -
check 8 'Authorization rejects unauthorized access' - test:integration "$tests_authorization"
check 9 'Every REST route has an explicit permission behaviour' - test:integration "$tests_route_walk"
check 10 'Unrelated requests do not start expensive subsystems' - test:integration "$tests_idle_budget"
check 11 'PHPCS passes' - cs
check 12 'Static analysis passes' - stan
check 13 'PHPUnit passes' - test:unit
check 14 'The WordPress integration tests pass' - test:integration
check 15 'The Playwright smoke test passes' - -
check 16 'Uninstall keeps the data, as the policy says' uninstall-preserve test:integration "$tests_retention"
check 17 'A clean second site installs the packaged zip' clean-install -

# install-smoke.sh also proves the upgrade and the network. They are not among the 17, but a run
# that skipped them is not the run this suite stands for.
if [ "$list_only" -eq 0 ]; then
	if [ -z "$only" ]; then
		if smoke_proved upgrade multisite >"$work/extra.out" 2>&1; then
			printf 'ok    --  install-smoke also proved the upgrade and the network\n'
		else
			failures=$((failures + 1))
			printf 'FAIL  --  install-smoke also proves the upgrade and the network\n'
			sed -e 's/^/        /' "$work/extra.out"
		fi
	fi

	if [ "$failures" -ne 0 ]; then
		printf '\nsmoke-suite: %s check(s) FAILED.\n' "$failures"
		exit 1
	fi

	if [ -n "$only" ]; then
		printf '\nsmoke-suite: the checks run passed; this was not the whole suite.\n'
	else
		printf '\nsmoke-suite: every check passed.\n'
	fi
fi

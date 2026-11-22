#!/bin/sh
#
# Runs one step of a payment extension's live workflow that is no provider's own.
#
# Usage: sh seocart/bin/ci/extension-live.sh <step> <extension root> [<argument>]
#
# A payment extension's live workflow runs it against its provider's test environment with the
# repository's secrets, started by hand or weekly (.github/workflows/<workflow> in the
# extension). Its jobs check the extension out into ext/ and SEOCart beside it into seocart/, set
# up with SEOCart's action (./seocart/.github/actions/extension-live-setup), and run their steps
# through this script, as `sh seocart/bin/ci/extension-live.sh <step> ext`, and through the
# extension's own bin/ci/<workflow's basename>.sh for its provider's steps: the secrets' check,
# the settings file, the gateway's configuration, the checkout's browser tests and the clean-up.
# What differs from one provider to the next is stated once, by the extension's tests/live.php
# (tests/Conformance/Payment/Live/LiveDeclaration.php).
#
# Steps:
#
#   heads              Gives the commits of the extension and of SEOCart as the job's outputs.
#   site               Builds and installs the site the checkout's browser tests run against, in
#                      the work directory, before the settings file exists: SEOCart's scripts
#                      built, SEOCart and the extension installed from the zips built here, the
#                      runner's own address trusted as a proxy, and the site served by PHP's
#                      built-in server (WORDPRESS_VERSION and the SEOCART_CI_DB_* variables, as
#                      SEOCart's extension workflow sets them). Its administrator's password is
#                      made for the run and masked.
#   conformance        Runs SEOCart's conformance suite with every request sent to the provider,
#                      adds what it created there to the run list from the tapes it recorded, and
#                      checks it ran the declared tests with the declared skips.
#   live <part>        Runs the live tests of a part the declaration states: exactly as many as
#                      it states must run, and each pass, none skipped.
#   optional <job>     Gives as an output whether the secret the declaration's optional part
#                      needs is set (its env), never its value; without it, the part runs nothing
#                      and says why.
#   verdict            Says, for each thing the run proves, which job proves it and how it went
#                      (SEOCART_LIVE_NEEDS, SEOCART_LIVE_PARTS), and fails a red run.
#
# Nothing here prints a credential or a card number: PHPUnit's and Playwright's output passes
# through the guard of the extension's declaration, and the shell never traces its commands.
# Outside GitHub Actions (a dry run), RUNNER_TEMP, GITHUB_OUTPUT and GITHUB_STEP_SUMMARY may be
# left unset.
#
# Exit codes: 0 passed, 1 failed, 2 usage error. POSIX sh: runs on the Ubuntu runner and on a
# developer machine alike.

set -eu

usage() {
	printf 'usage: extension-live.sh heads|site|conformance|verdict <extension root>\n' >&2
	printf '       extension-live.sh live <extension root> <part>\n' >&2
	printf '       extension-live.sh optional <extension root> <job>\n' >&2
	exit 2
}

# Fails with the usage unless the step was given its number of arguments: takes <count>.
takes() {
	[ "$argc" -eq "$1" ] || usage
}

argc=$#
[ "$argc" -ge 2 ] || usage
core=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
# shellcheck source=bin/ci/extension-live-lib.sh
. "$core/bin/ci/extension-live-lib.sh"
ext=$(CDPATH='' cd -- "$2" 2>/dev/null && pwd) || fail "$2 is not a directory."

heads() {
	takes 2
	output ext-head "$(git -C "$ext" rev-parse HEAD)"
	output core-head "$(git -C "$core" rev-parse HEAD)"
}

site() {
	takes 2
	for name in WORDPRESS_VERSION SEOCART_CI_DB_HOST SEOCART_CI_DB_USER SEOCART_CI_DB_PASSWORD SEOCART_CI_DB_NAME; do
		eval "is_set=\${$name+set}"
		[ "${is_set:-}" = set ] || fail "site: the environment variable $name is not set."
	done
	slug=$(php "$step_php" describe "$ext" slug) || fail "site: $ext/tests/live.php cannot be read."
	umask 077

	echo 'Building SEOCart'\''s scripts and the two zips.'
	(cd "$core" && npm ci && npm run build)
	php "$core/bin/build-zip.php"
	php "$core/bin/build-zip.php" --plugin="$ext"

	echo 'Installing WordPress.'
	sh "$core/bin/ci/download-wordpress.sh" "$WORDPRESS_VERSION" "$site_dir"
	# The passwords are read from standard input so that they never appear in the process table.
	printf '%s\n' "$SEOCART_CI_DB_PASSWORD" | site_wp config create --dbname="$SEOCART_CI_DB_NAME" \
		--dbuser="$SEOCART_CI_DB_USER" --dbhost="$SEOCART_CI_DB_HOST" --dbprefix=checkout_ --prompt=dbpass >/dev/null
	site_wp config set WP_ENVIRONMENT_TYPE local </dev/null
	site_wp config set DISABLE_WP_CRON true --raw </dev/null
	# The tests reach the site from this runner, as a proxy would, and give each test an address of
	# its own in X-Forwarded-For: the store allows one client five placements and resumes an hour.
	site_wp config set SEOCART_TRUSTED_PROXIES '127.0.0.0/8, ::1' </dev/null
	password=$(random_hex)
	mask "$password"
	printf '%s\n' "$password" | site_wp core install --url="$site_url" --title='SEOCart checkout' --admin_user=admin \
		--admin_email=admin@example.org --skip-email --prompt=admin_password >/dev/null

	echo 'Installing SEOCart and the extension from their zips.'
	for zip in "$core"/dist/seocart-*.zip "$ext"/dist/"$slug"-*.zip; do
		site_wp plugin install "$zip" --activate </dev/null
	done

	# Opcache off, for the reason SEOCart's install smoke test gives.
	php -d opcache.enable=0 -d opcache.enable_cli=0 -S "${site_url#http://}" -t "$site_dir" >"$work/server.log" 2>&1 &
	echo "$!" >"$work/server.pid"
	attempts=0
	until curl -s -o /dev/null "$site_url/license.txt"; do
		attempts=$((attempts + 1))
		[ "$attempts" -lt 20 ] || fail 'PHP'\''s built-in server did not start.'
		sleep 1
	done

	printf 'WP_BASE_URL=%s\nWP_USERNAME=admin\nWP_PASSWORD=%s\n' "$site_url" "$password" >"$site_env"
	chmod 600 "$site_env"
	echo "The site answers at $site_url, SEOCart and the extension active."
}

conformance() {
	takes 2
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	run_nonce=$(nonce)
	output nonce "$run_nonce"
	status=0
	# The suite rewrites the tape of each scenario it completes: those newer than this mark are the
	# run's. A file's time is read in whole seconds, so the suite starts in the second after the
	# mark's, and a tape from the mark's own second is an earlier recording's.
	touch "$work/conformance.mark"
	sleep 1
	# env gives the suite its recording's environment: a mode, the run's nonce and the settings
	# file's path, none of them a credential.
	filtered "$work/conformance.out" env SEOCART_TAPE=record SEOCART_TAPE_NONCE="$run_nonce" SEOCART_TAPE_SETTINGS="$settings" \
		sh "$core/bin/ci/extension.sh" conformance "$ext" payments || status=$?
	# Whatever became of the suite, what it created at the provider goes on the run list, so the
	# clean-up ends what it left open.
	php "$step_php" harvest "$ext" "$ext/tests/tapes" "$work/conformance.mark" "$run_list" || status=1
	php "$step_php" suite "$ext" "$work/conformance.out" || status=1
	return "$status"
}

live() {
	takes 3
	# The part's live tests, as the PHPUnit filter of their classes the declaration states, in the
	# group derived from the extension's slug; how many they must be is the declaration's too.
	filter=$(php "$step_php" describe "$ext" filter "$1") || fail "live: $1 is no live part of $ext/tests/live.php."
	group=$(php "$step_php" describe "$ext" group) || fail "live: $ext/tests/live.php cannot be read."
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	mkdir -p "$work/tmp"
	run_nonce=$(nonce)
	status=0
	# As in the suite's step, env gives PHPUnit the run's environment: paths, a mode and the nonce,
	# none of them a credential. What else a part's tests read, the job's environment gives them.
	(
		cd "$ext"
		filtered "$work/live.out" env SEOCART_EXTENSION_PATH="$ext" SEOCART_TAPE=record SEOCART_TAPE_NONCE="$run_nonce" \
			SEOCART_TAPE_SETTINGS="$settings" SEOCART_LIVE_RUN_LIST="$run_list" SEOCART_LIVE_REPORT="$report" TMPDIR="$work/tmp" \
			php "$core/vendor/bin/phpunit" --configuration "$ext/phpunit.xml.dist" --testsuite integration \
			--bootstrap "$core/tests/bootstrap-integration.php" --group "$group" --filter "$filter" --fail-on-empty-test-suite --verbose
	) || status=$?
	php "$step_php" live "$ext" "$1" "$work/live.out" "$report" || status=1
	if [ -n "${GITHUB_STEP_SUMMARY:-}" ] && [ -s "$report" ]; then
		{
			printf '### Live tests: %s\n\n' "$1"
			php "$step_php" redact "$ext" <"$report" | sed 's/^/- /'
		} >>"$GITHUB_STEP_SUMMARY"
	fi
	return "$status"
}

optional() {
	takes 3
	php "$step_php" optional "$ext" "$1"
}

verdict() {
	takes 2
	php "$step_php" verdict "$ext"
}

case ${1:-} in
	heads) heads ;;
	site) site ;;
	conformance) conformance ;;
	live) live "${3:-}" ;;
	optional) optional "${3:-}" ;;
	verdict) verdict ;;
	*) usage ;;
esac

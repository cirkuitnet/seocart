#!/bin/sh
#
# Installs the release zip on a fresh WordPress site and proves that it behaves.
#
# Usage: sh bin/ci/install-smoke.sh <zip> [wp-version]
#
#   <zip>         The built plugin, for example dist/seocart-0.1.0.zip.
#   [wp-version]  Anything download-wordpress.sh accepts. Defaults to "latest", because
#                 that is what a new site installs.
#
# Environment:
#   SEOCART_CI_DB_HOST      Required. Database host, with the port when it is not 3306.
#   SEOCART_CI_DB_USER      Required.
#   SEOCART_CI_DB_PASSWORD  Required. May be empty.
#   SEOCART_CI_DB_NAME      Required. The database must already exist. The site uses a
#                           table prefix of its own and drops those tables when it is
#                           done, so nothing else in the database is touched.
#   SEOCART_CI_HTTP_PORT    Optional. Port for PHP's built-in web server. Default 8889.
#   SEOCART_CI_KEEP         Optional. Set to 1 to keep the site directory and its tables
#                           for inspection.
#
# What it proves (baseline smoke test 17: a clean second site can install the packaged
# plugin): the zip, not the source tree, installs with WP-CLI; the plugin activates,
# deactivates, reactivates and uninstalls; WordPress still answers through WP-CLI and
# over HTTP at every step; and, with WP_DEBUG on, the debug log gains nothing at all.
# Whatever the log already held before the plugin was installed is WordPress's own and
# does not count. An empty log is only evidence when messages can reach it, so the run
# first raises a canary notice through WP-CLI and through the web server and fails unless
# both arrive in the log.
#
# Needs WP-CLI, PHP with mysqli, curl and a MySQL server. POSIX sh: runs on the Ubuntu
# runner and on a developer machine alike.

set -eu

# comm(1) needs both of its inputs sorted under one collation.
LC_ALL=C
export LC_ALL

plugin=seocart

fail() {
	printf 'install-smoke: FAIL: %s\n' "$*" >&2
	exit 1
}

step() {
	printf '\ninstall-smoke: %s\n' "$*"
}

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
	fail 'usage: install-smoke.sh <zip> [wp-version]'
fi

zip=$1
wp_version=${2:-latest}

[ -f "$zip" ] || fail "$zip does not exist."

for name in SEOCART_CI_DB_HOST SEOCART_CI_DB_USER SEOCART_CI_DB_PASSWORD SEOCART_CI_DB_NAME; do
	eval "is_set=\${$name+set}"
	[ "${is_set:-}" = set ] || fail "the environment variable $name is not set."
done

for tool in wp php curl; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required but was not found on PATH."
done

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
zip=$(CDPATH='' cd -- "$(dirname -- "$zip")" && pwd)/$(basename -- "$zip")

work=$(mktemp -d "${TMPDIR:-/tmp}/seocart-install-smoke.XXXXXX")
site=$work/site
log=$work/debug.log
baseline=$work/debug.baseline.log
port=${SEOCART_CI_HTTP_PORT:-8889}
url=http://127.0.0.1:$port
# Unique to this run, so the tables can be dropped again without touching anything else.
prefix=smoke$$_
server_pid=''

cleanup() {
	status=$?
	trap - EXIT INT TERM

	if [ -n "$server_pid" ]; then
		kill "$server_pid" 2>/dev/null || true
	fi

	if [ "${SEOCART_CI_KEEP:-0}" = 1 ]; then
		printf 'install-smoke: kept %s and the tables prefixed %s\n' "$work" "$prefix" >&2
	else
		if [ -f "$site/wp-config.php" ] && ! wp --path="$site" db clean --yes >/dev/null 2>&1; then
			printf 'install-smoke: WARNING: could not drop the tables prefixed %s in %s; drop them by hand.\n' \
				"$prefix" "$SEOCART_CI_DB_NAME" >&2
		fi

		rm -rf "$work"
	fi

	exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Every WP-CLI call goes through here so that each one targets the throwaway site.
site_wp() {
	wp --path="$site" "$@"
}

# Prints what the built-in server left behind when a request fails: whether it is still
# running or how it ended, and the end of its log and of the debug log. Cleanup deletes
# $work, so without this a failed request leaves no record of why.
server_evidence() {
	if kill -0 "$server_pid" 2>/dev/null; then
		printf 'install-smoke: the built-in server (pid %s) is still running.\n' "$server_pid" >&2
	else
		# A status above 128 is 128 plus the signal that ended it: 139 is SIGSEGV.
		server_status=0
		wait "$server_pid" 2>/dev/null || server_status=$?
		printf 'install-smoke: the built-in server (pid %s) exited with status %s.\n' "$server_pid" "$server_status" >&2
		server_pid=''
	fi

	printf 'install-smoke: the end of the server log:\n' >&2
	tail -n 40 "$work/server.log" >&2 || true
	printf 'install-smoke: the end of the debug log:\n' >&2
	tail -n 20 "$log" >&2 2>/dev/null || true

	# The server's process and any children: a worker that died, or one still busy with the
	# request, shows here. PHP_CLI_SERVER_WORKERS would make the server fork workers.
	printf 'install-smoke: PHP_CLI_SERVER_WORKERS=%s; the server process and its children:\n' "${PHP_CLI_SERVER_WORKERS:-unset}" >&2
	if [ -n "$server_pid" ]; then
		ps -A -o pid= -o ppid= -o stat= -o etime= -o args= 2>/dev/null |
			awk -v pid="$server_pid" '$1 == pid || $2 == pid' >&2 || true
	fi

	# A segfault in any PHP process lands in the kernel log. Best effort: it needs sudo
	# without a password, which the hosted runner has and a developer machine may not.
	printf 'install-smoke: the end of the kernel log (best effort):\n' >&2
	sudo -n dmesg 2>/dev/null | tail -n 20 >&2 || true

	# Whether the request was only slow: the server logs its response and "Closing" when it
	# finishes. A blocking request from WordPress to this single-threaded server would show
	# up as a response logged later, or as a follow-up request that is not answered either.
	sleep 5
	printf 'install-smoke: the server log five seconds later:\n' >&2
	tail -n 10 "$work/server.log" >&2 || true
	printf 'install-smoke: a follow-up request for a static file: HTTP %s\n' \
		"$(curl -sS -m 10 -o /dev/null -w '%{http_code}' "$url/license.txt" 2>&1 || true)" >&2
}

# Loads WordPress once through WP-CLI and once over HTTP. Both must succeed.
exercise() {
	site_wp eval 'echo "WordPress ", get_bloginfo( "version" ), " loaded.", PHP_EOL;'

	if ! http_status=$(curl -sS -o "$work/response.html" -w '%{http_code}' "$url/"); then
		server_evidence
		fail "no HTTP response from $url/ ($1)."
	fi

	if [ "$http_status" != 200 ]; then
		server_evidence
		fail "$url/ answered HTTP $http_status, expected 200 ($1)."
	fi

	printf 'GET %s/ -> %s\n' "$url" "$http_status"
}

# The positive control of the debug-log gate. The gate passes when the log gains nothing,
# so it would also pass if nothing could reach the log: WP_DEBUG_LOG not honoured, a path
# that cannot be written, an error_log setting that wins over it. This raises one notice
# through WP-CLI and one through the web server, the two ways `exercise` loads WordPress,
# and fails unless both arrive in $log. It runs before the baseline is taken, so the
# canaries are part of the baseline and never count against the plugin.
prove_the_log_is_live() {
	canary=seocart-install-smoke-canary

	# WP-CLI also prints the notice on standard error; it is only shown when the call fails.
	site_wp eval "trigger_error( '$canary-cli', E_USER_NOTICE );" >/dev/null 2>"$work/canary.err" ||
		fail "WP-CLI could not raise the canary notice: $(cat "$work/canary.err")"
	grep -q -- "$canary-cli" "$log" 2>/dev/null ||
		fail "a notice raised through WP-CLI did not reach $log, so an empty log would prove nothing."

	# A must-use plugin is the only code a site without plugins runs on a web request. It
	# exists for one request and is gone before the plugin under test is installed.
	mkdir -p "$site/wp-content/mu-plugins"
	printf '<?php\ntrigger_error( "%s-http", E_USER_NOTICE );\n' "$canary" >"$site/wp-content/mu-plugins/$canary.php"
	if ! curl -sS -o /dev/null "$url/"; then
		server_evidence
		fail "no HTTP response from $url/ (canary request)."
	fi
	rm -f "$site/wp-content/mu-plugins/$canary.php"
	grep -q -- "$canary-http" "$log" ||
		fail "a notice raised during a web request did not reach $log, so an empty log would prove nothing."

	printf 'Both canary notices reached the debug log.\n'
}

# Prints the log messages, without their timestamps, that are in $2 but not in $1.
new_log_messages() {
	sed -e 's/^\[[^]]*\] //' "$1" | sort -u >"$work/messages.before"
	sed -e 's/^\[[^]]*\] //' "$2" | sort -u >"$work/messages.after"
	comm -13 "$work/messages.before" "$work/messages.after"
}

step "downloading WordPress ($wp_version)"
sh "$script_dir/download-wordpress.sh" "$wp_version" "$site"

step 'creating the site'
# The password is read from standard input so that it never appears in the process table.
# Standard output is discarded because WP-CLI echoes a prompted command line, password
# included; errors still arrive on standard error. WP-CLI connects to the database here, so
# wrong credentials stop the run at this step.
printf '%s\n' "$SEOCART_CI_DB_PASSWORD" | site_wp config create \
	--dbname="$SEOCART_CI_DB_NAME" \
	--dbuser="$SEOCART_CI_DB_USER" \
	--dbhost="$SEOCART_CI_DB_HOST" \
	--dbprefix="$prefix" \
	--prompt=dbpass >/dev/null

site_wp config set WP_DEBUG true --raw
site_wp config set WP_DEBUG_DISPLAY false --raw
site_wp config set WP_DEBUG_LOG "$log"
# No background request may write to the log while it is being compared.
site_wp config set DISABLE_WP_CRON true --raw

# WP-CLI generates the administrator password; nothing here needs to know it.
site_wp core install \
	--url="$url" \
	--title='SEOCart install smoke' \
	--admin_user=admin \
	--admin_email=admin@example.org \
	--skip-email >/dev/null

php -S "127.0.0.1:$port" -t "$site" >"$work/server.log" 2>&1 &
server_pid=$!

attempts=0
until curl -s -o /dev/null "$url/license.txt"; do
	attempts=$((attempts + 1))
	[ "$attempts" -lt 20 ] || fail "PHP's built-in server did not start on port $port: $(cat "$work/server.log")"
	sleep 1
done

step 'baseline: WordPress without the plugin'
exercise 'before the plugin was installed'
prove_the_log_is_live
cp "$log" "$baseline"

step "installing $(basename -- "$zip")"
site_wp plugin install "$zip" --activate
site_wp plugin is-active "$plugin" || fail "the zip did not install a plugin with the slug \"$plugin\"."
exercise 'after activation'

step 'deactivating'
site_wp plugin deactivate "$plugin"
exercise 'after deactivation'

step 'reactivating'
site_wp plugin activate "$plugin"
exercise 'after reactivation'

step 'uninstalling'
site_wp plugin uninstall "$plugin" --deactivate
exercise 'after uninstalling'

step 'checking the debug log'
added=$(new_log_messages "$baseline" "$log")

if [ -n "$added" ]; then
	printf '%s\n' "$added" >&2
	fail 'the debug log gained the messages above after SEOCart was installed. Notices, warnings, deprecations and errors all count.'
fi

printf 'install-smoke: PASS: installed, activated, deactivated, reactivated and uninstalled with a clean debug log.\n'

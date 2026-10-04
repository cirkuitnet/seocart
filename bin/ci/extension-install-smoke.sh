#!/bin/sh
#
# Installs a SEOCart extension's release zip beside SEOCart's zip on a fresh WordPress site.
#
# Usage: sh bin/ci/extension-install-smoke.sh <seocart zip> <extension zip> [wp-version]
#
#   <seocart zip>    SEOCart's release zip, for example dist/seocart-0.1.0.zip.
#   <extension zip>  The extension's release zip, for example dist/seocart-gateway-for-example-0.1.0.zip.
#   [wp-version]     Anything download-wordpress.sh accepts. Defaults to "latest".
#
# Environment: SEOCART_CI_DB_HOST, SEOCART_CI_DB_USER, SEOCART_CI_DB_PASSWORD and
# SEOCART_CI_DB_NAME, as install-smoke.sh reads them (the database must exist; the site uses
# a table prefix of its own and drops its tables when it is done), and optionally
# SEOCART_CI_HTTP_PORT (default 8889).
#
# What it proves, from the two zips and never from a source tree:
#
#   1. SEOCart's zip installs and activates, then the extension's zip installs and activates
#      beside it;
#   2. WordPress answers through WP-CLI and over HTTP with both active;
#   3. the extension deactivates and uninstalls, and SEOCart stays active and still answers;
#   4. with WP_DEBUG on, the debug log gains not one byte from the moment the extension is
#      installed. A canary notice, raised through WP-CLI and through the web server right
#      before the log is read, proves that messages reach it.
#
# SEOCart's own lifecycle (upgrade, uninstall, a network) is install-smoke.sh's to prove; this
# script proves what an extension adds. Needs WP-CLI, PHP with mysqli, curl, unzip and a MySQL
# server. POSIX sh: runs on the Ubuntu runner and on a developer machine alike.

set -eu

fail() {
	printf 'extension-install-smoke: FAIL: %s\n' "$*" >&2
	exit 1
}

step() {
	printf '\nextension-install-smoke: %s\n' "$*"
}

if [ "$#" -lt 2 ] || [ "$#" -gt 3 ]; then
	fail 'usage: extension-install-smoke.sh <seocart zip> <extension zip> [wp-version]'
fi

for name in SEOCART_CI_DB_HOST SEOCART_CI_DB_USER SEOCART_CI_DB_PASSWORD SEOCART_CI_DB_NAME; do
	eval "is_set=\${$name+set}"
	[ "${is_set:-}" = set ] || fail "the environment variable $name is not set."
done

for tool in wp php curl unzip; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required but was not found on PATH."
done

for zip in "$1" "$2"; do
	[ -f "$zip" ] || fail "$zip does not exist."
done

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
core_zip=$(CDPATH='' cd -- "$(dirname -- "$1")" && pwd)/$(basename -- "$1")
extension_zip=$(CDPATH='' cd -- "$(dirname -- "$2")" && pwd)/$(basename -- "$2")
wp_version=${3:-latest}

# The extension's slug is the one folder its zip unpacks to (bin/check-zip.php ensures there is one).
slug=$(unzip -Z1 "$extension_zip" | head -n 1 | cut -d / -f 1)
[ -n "$slug" ] || fail "$extension_zip holds no plugin folder."

work=$(mktemp -d "${TMPDIR:-/tmp}/seocart-extension-smoke.XXXXXX")
site=$work/site
log=$work/debug.log
server_log=$work/server.log
url=http://127.0.0.1:${SEOCART_CI_HTTP_PORT:-8889}
server_pid=''
canary_count=0

cleanup() {
	status=$?
	trap - EXIT INT TERM

	if [ -n "$server_pid" ]; then
		kill "$server_pid" 2>/dev/null || true
		wait "$server_pid" 2>/dev/null || true
	fi

	if [ -f "$site/wp-config.php" ] && ! wp --path="$site" db clean --yes >/dev/null 2>&1; then
		printf 'extension-install-smoke: WARNING: could not drop the tables of the site in %s; drop them by hand.\n' "$SEOCART_CI_DB_NAME" >&2
	fi

	rm -rf "$work"
	exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

site_wp() {
	wp --path="$site" "$@"
}

# Requests the front page and fails unless it answers 200; $1 says when.
front_page_answers() {
	http_status=$(curl -sS -o /dev/null -w '%{http_code}' "$url/") || http_status=none

	if [ "$http_status" != 200 ]; then
		tail -n 20 "$server_log" >&2 || true
		tail -n 20 "$log" >&2 2>/dev/null || true
		fail "$url/ answered $http_status, expected 200 ($1)."
	fi

	printf 'GET %s/ -> 200 (%s)\n' "$url" "$1"
}

# Raises a notice through WP-CLI and one during a web request, and fails unless both reach the
# debug log: a log nothing can reach would make an empty one prove nothing.
prove_the_log_is_live() {
	canary_count=$((canary_count + 1))
	canary=seocart-extension-smoke-canary-$canary_count

	site_wp eval "trigger_error( '$canary-cli', E_USER_NOTICE );" >/dev/null 2>&1 || true
	grep -q -- "$canary-cli" "$log" 2>/dev/null || fail "a notice raised through WP-CLI did not reach $log."

	mkdir -p "$site/wp-content/mu-plugins"
	printf '<?php\ntrigger_error( "%s-http", E_USER_NOTICE );\n' "$canary" >"$site/wp-content/mu-plugins/$canary.php"
	curl -sS -o /dev/null "$url/" || true
	rm -f "$site/wp-content/mu-plugins/$canary.php"
	grep -q -- "$canary-http" "$log" || fail "a notice raised during a web request did not reach $log."
}

log_bytes() {
	wc -c <"$log" | tr -d ' '
}

step "downloading WordPress ($wp_version)"
sh "$script_dir/download-wordpress.sh" "$wp_version" "$work/wordpress"
cp -R "$work/wordpress" "$site"

step 'creating the site'
# The password is read from standard input so that it never appears in the process table.
printf '%s\n' "$SEOCART_CI_DB_PASSWORD" | site_wp config create \
	--dbname="$SEOCART_CI_DB_NAME" \
	--dbuser="$SEOCART_CI_DB_USER" \
	--dbhost="$SEOCART_CI_DB_HOST" \
	--dbprefix="extsmoke$$_" \
	--prompt=dbpass >/dev/null
site_wp config set WP_DEBUG true --raw
site_wp config set WP_DEBUG_DISPLAY false --raw
site_wp config set WP_DEBUG_LOG "$log"
site_wp config set DISABLE_WP_CRON true --raw
site_wp config set WP_ENVIRONMENT_TYPE local
site_wp core install --url="$url" --title='SEOCart extension smoke' --admin_user=admin --admin_email=admin@example.org --skip-email >/dev/null

# Opcache off, for the reason install-smoke.sh gives.
php -d opcache.enable=0 -d opcache.enable_cli=0 -S "${url#http://}" -t "$site" >"$server_log" 2>&1 &
server_pid=$!
attempts=0
until curl -s -o /dev/null "$url/license.txt"; do
	attempts=$((attempts + 1))
	[ "$attempts" -lt 20 ] || fail "PHP's built-in server did not start: $(cat "$server_log")"
	sleep 1
done

step "installing SEOCart from $(basename -- "$core_zip")"
site_wp plugin install "$core_zip" --activate
front_page_answers 'SEOCart active'
prove_the_log_is_live
baseline_bytes=$(log_bytes)

step "installing the extension from $(basename -- "$extension_zip")"
site_wp plugin install "$extension_zip" --activate
site_wp plugin is-active "$slug" || fail "$slug is not active after its installation."
site_wp eval 'echo "WordPress ", get_bloginfo( "version" ), " loaded with SEOCart and the extension.", PHP_EOL;'
front_page_answers 'SEOCart and the extension active'

step 'deactivating and uninstalling the extension'
site_wp plugin deactivate "$slug"
site_wp plugin uninstall "$slug"
site_wp plugin is-active seocart || fail 'SEOCart is no longer active after the extension was uninstalled.'
front_page_answers 'the extension uninstalled'

step 'checking the debug log'
end_bytes=$(log_bytes)
prove_the_log_is_live
if [ "$end_bytes" -ne "$baseline_bytes" ]; then
	tail -c "+$((baseline_bytes + 1))" "$log" | head -c "$((end_bytes - baseline_bytes))" >&2
	fail 'the debug log gained the bytes above once the extension was installed.'
fi

printf '\nextension-install-smoke: PASS: %s installed, ran beside SEOCart and uninstalled, and the debug log gained nothing.\n' "$slug"

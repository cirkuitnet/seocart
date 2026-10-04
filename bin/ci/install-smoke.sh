#!/bin/sh
#
# Installs the release zip on fresh WordPress sites and proves its whole lifecycle.
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
#   SEOCART_CI_DB_NAME      Required. The database must already exist. Each site uses a
#                           table prefix of its own and drops those tables when it is
#                           done, so nothing else in the database is touched.
#   SEOCART_CI_OLD_ZIP      Optional. An earlier release zip (bin/ci/build-older-zip.sh builds
#                           the one bin/ci/upgrade-from.env names). With it, the plugin is
#                           first installed from that zip and the run proves the upgrade;
#                           without it the upgrade is skipped and the run says so.
#   SEOCART_CI_HTTP_PORT    Optional. Port for PHP's built-in web server. Default 8889. The
#                           network site of the last part uses the next port.
#   SEOCART_CI_PROOF_FILE   Optional. A file the "proved <name>" lines of a passing run are
#                           appended to, for whoever reports on the run later.
#   SEOCART_CI_KEEP         Optional. Set to 1 to keep the site directories and their tables
#                           for inspection.
#
# What it proves (the lifecycle behind baseline smoke tests 1, 2, 4, 5, 7, 16 and 17: a clean
# second site can install the packaged plugin). Every step works from the zip, never from the
# source tree.
#
#   1. install: the zip installs with WP-CLI and activates;
#   2. upgrade: on a site where the earlier zip is installed and holds a product with stock
#      and an order for it, installing this zip over it queues the migrations since as a job;
#      once the plugin's job runner has run it (`wp seocart jobs run`: what cron does), every
#      migration since is recorded once, as applied, none of the earlier ones ran again, and the
#      product, its stock, the order and every value of every data table are as they were:
#      each column a table had before reads the same, so a column the upgrade adds is allowed
#      and a value it changed or lost shows;
#   3. a guest buys the product through the Store API with the stub gateway and reads the
#      order back, with the earlier zip when there is one (the new tables are written and read);
#   4. deactivate and reactivate;
#   5. uninstall preserves the store: every table of the plugin, every value in it, and the
#      plugin's options are all still there, and installing the zip again recognises the same
#      store, with its order;
#   6. on a WordPress network: network activation installs the main site only; a site that
#      existed before installs itself on its first request, and a site created afterwards as
#      soon as it exists; the plugin can be switched per site; deleting a site drops its
#      plugin tables.
#
# WordPress still answers through WP-CLI and over HTTP at every step. With WP_DEBUG on, the
# debug log gains not one byte, on the single site and on the network. Whatever a log already
# held before the plugin was installed is WordPress's own and does not count. An empty log is
# only evidence when messages can reach it, so each site raises a canary notice through WP-CLI
# and through the web server before its baseline is taken and again right before the final
# comparison, and fails unless both arrive in its log each time.
#
# A passing run ends with one "proved <name>" line per fact. bin/ci/smoke-suite.sh reads them.
#
# Needs WP-CLI, PHP with mysqli, curl, unzip and a MySQL server. POSIX sh: runs on the
# Ubuntu runner and on a developer machine alike.

set -eu

# sort(1) and comm(1) must order the same way everywhere.
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
old_zip=${SEOCART_CI_OLD_ZIP:-}

[ -f "$zip" ] || fail "$zip does not exist."
[ -z "$old_zip" ] || [ -f "$old_zip" ] || fail "$old_zip does not exist."

for name in SEOCART_CI_DB_HOST SEOCART_CI_DB_USER SEOCART_CI_DB_PASSWORD SEOCART_CI_DB_NAME; do
	eval "is_set=\${$name+set}"
	[ "${is_set:-}" = set ] || fail "the environment variable $name is not set."
done

for tool in wp php curl unzip; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required but was not found on PATH."
done

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
zip=$(CDPATH='' cd -- "$(dirname -- "$zip")" && pwd)/$(basename -- "$zip")
if [ -n "$old_zip" ]; then
	old_zip=$(CDPATH='' cd -- "$(dirname -- "$old_zip")" && pwd)/$(basename -- "$old_zip")
fi

work=$(mktemp -d "${TMPDIR:-/tmp}/seocart-install-smoke.XXXXXX")
core=$work/wordpress
first_port=${SEOCART_CI_HTTP_PORT:-8889}
# Unique to this run, so the tables can be dropped again without touching anything else.
run_prefix=smoke$$

# The site being worked on. create_site() sets these; every function below reads them.
site=''
log=''
baseline_bytes=0
canary_count=0
url=''
table_prefix=''
server_pid=''
server_log=''
# The sites created so far, for cleanup, and the facts proved so far, for the closing report.
created=''
proved=''

stop_server() {
	if [ -n "$server_pid" ]; then
		kill "$server_pid" 2>/dev/null || true
		wait "$server_pid" 2>/dev/null || true
		server_pid=''
	fi
}

cleanup() {
	status=$?
	trap - EXIT INT TERM

	stop_server

	if [ "${SEOCART_CI_KEEP:-0}" = 1 ]; then
		printf 'install-smoke: kept %s and the tables prefixed %s\n' "$work" "$run_prefix" >&2
	else
		for name in $created; do
			if [ -f "$work/$name/wp-config.php" ] && ! wp --path="$work/$name" db clean --yes >/dev/null 2>&1; then
				printf 'install-smoke: WARNING: could not drop the tables of the site %s (prefix %s) in %s; drop them by hand.\n' \
					"$name" "$run_prefix" "$SEOCART_CI_DB_NAME" >&2
			fi
		done

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

# Records that a named fact was proved. smoke-suite.sh looks for these names.
prove() {
	proved="$proved $1"
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
	tail -n 40 "$server_log" >&2 || true
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
	tail -n 10 "$server_log" >&2 || true
	printf 'install-smoke: a follow-up request for a static file: HTTP %s\n' \
		"$(curl -sS -m 10 -o /dev/null -w '%{http_code}' "$url/license.txt" 2>&1 || true)" >&2
}

# Requests one URL of the site over HTTP and fails unless it answers 200. $1 is the URL, $2
# says when the request was made.
http_ok() {
	if ! http_status=$(curl -sS -o "$work/response.html" -w '%{http_code}' "$1"); then
		server_evidence
		fail "no HTTP response from $1 ($2)."
	fi

	if [ "$http_status" != 200 ]; then
		server_evidence
		fail "$1 answered HTTP $http_status, expected 200 ($2)."
	fi

	printf 'GET %s -> %s\n' "$1" "$http_status"
}

# Loads WordPress once through WP-CLI and once over HTTP. Both must succeed.
exercise() {
	site_wp eval 'echo "WordPress ", get_bloginfo( "version" ), " loaded.", PHP_EOL;'
	http_ok "$url/" "$1"
}

# The positive control of the debug-log gate. The gate passes when the log gains nothing,
# so it would also pass if nothing could reach the log: WP_DEBUG_LOG not honoured, a path
# that cannot be written, an error_log setting that wins over it. This raises one notice
# through WP-CLI and one through the web server, the two ways `exercise` loads WordPress,
# and fails unless both arrive in $log. Each call raises notices of its own name, so an
# earlier call's notices never stand in for these. check_the_log() runs it again right
# before it reads the log; create_site() runs it before the baseline is taken, so those
# canaries are part of the baseline and never count against the plugin.
prove_the_log_is_live() {
	canary_count=$((canary_count + 1))
	canary=seocart-install-smoke-canary-$canary_count

	# WP-CLI also prints the notice on standard error; it is only shown when the call fails.
	site_wp eval "trigger_error( '$canary-cli', E_USER_NOTICE );" >/dev/null 2>"$work/canary.err" ||
		fail "WP-CLI could not raise the canary notice: $(cat "$work/canary.err")"
	grep -q -- "$canary-cli" "$log" 2>/dev/null ||
		fail "a notice raised through WP-CLI did not reach $log, so an empty log would prove nothing."

	# A must-use plugin is the only code a site without plugins runs on a web request. It
	# exists for one request, and is gone before the plugin under test is installed.
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

# Prints how many bytes the current site's debug log holds.
log_bytes() {
	wc -c <"$log" | tr -d ' '
}

# Fails when the site's debug log gained a single byte since the baseline was taken. The bytes
# are compared, not the messages: a message the log already held, raised again, counts. The
# canaries are raised right before the log is read, and whatever else the canary requests
# wrote besides the canaries counts too.
check_the_log() {
	end=$(log_bytes)
	prove_the_log_is_live

	if [ "$end" -ne "$baseline_bytes" ]; then
		tail -c "+$((baseline_bytes + 1))" "$log" | head -c "$((end - baseline_bytes))" >&2
		fail 'the debug log gained the bytes above after SEOCART was installed. Notices, warnings, deprecations and errors all count.'
	fi

	extra=$(tail -c "+$((end + 1))" "$log" | grep -v -- "$canary" || true)

	if [ -n "$extra" ]; then
		printf '%s\n' "$extra" >&2
		fail 'the requests that raised the canaries wrote the lines above besides the canaries.'
	fi

	printf 'The debug log gained nothing since the baseline (%s bytes).\n' "$baseline_bytes"
}

# Creates a WordPress site from the downloaded core, starts PHP's built-in server for it and
# takes the debug-log baseline. $1 names the site, $2 is its port, $3 is "single" or
# "network". The site becomes the current one.
create_site() {
	name=$1
	site=$work/$name
	log=$work/$name-debug.log
	server_log=$work/$name-server.log
	url=http://127.0.0.1:$2
	table_prefix=${run_prefix}${name}_
	created="$created $name"

	step "creating the $name site"
	cp -R "$core" "$site"

	# The password is read from standard input so that it never appears in the process table.
	# Standard output is discarded because WP-CLI echoes a prompted command line, password
	# included; errors still arrive on standard error. WP-CLI connects to the database here, so
	# wrong credentials stop the run at this step.
	printf '%s\n' "$SEOCART_CI_DB_PASSWORD" | site_wp config create \
		--dbname="$SEOCART_CI_DB_NAME" \
		--dbuser="$SEOCART_CI_DB_USER" \
		--dbhost="$SEOCART_CI_DB_HOST" \
		--dbprefix="$table_prefix" \
		--prompt=dbpass >/dev/null

	site_wp config set WP_DEBUG true --raw
	site_wp config set WP_DEBUG_DISPLAY false --raw
	site_wp config set WP_DEBUG_LOG "$log"
	# No background request may write to the log while it is being compared.
	site_wp config set DISABLE_WP_CRON true --raw
	# WordPress offers application passwords over plain HTTP on a local site only.
	site_wp config set WP_ENVIRONMENT_TYPE local

	# WP-CLI generates the administrator password; nothing here needs to know it.
	if [ "$3" = network ]; then
		core_install='multisite-install'
	else
		core_install='install'
	fi
	site_wp core "$core_install" \
		--url="$url" \
		--title="SEOCART install smoke $name" \
		--admin_user=admin \
		--admin_email=admin@example.org \
		--skip-email >/dev/null

	# Opcache is off for the built-in server: PHP 8.4's opcache segfaulted in it on CI after
	# activation (kernel log: "segfault ... in opcache.so"), which made this check fail at random.
	php -d opcache.enable=0 -d opcache.enable_cli=0 -S "127.0.0.1:$2" -t "$site" >"$server_log" 2>&1 &
	server_pid=$!

	attempts=0
	until curl -s -o /dev/null "$url/license.txt"; do
		attempts=$((attempts + 1))
		[ "$attempts" -lt 20 ] || fail "PHP's built-in server did not start on port $2: $(cat "$server_log")"
		sleep 1
	done

	step "baseline of the $name site: WordPress without the plugin"
	exercise 'before the plugin was installed'
	prove_the_log_is_live
	baseline_bytes=$(log_bytes)
}

# --- Reading the site's data ----------------------------------------------------------------

# Prints the rows of a SELECT, one per line, columns separated by a tab. The query runs through
# WordPress's own connection with every plugin skipped, so what it reads is what the database
# holds and no plugin code has run to change it. {px} stands for the site's table prefix. A
# query that fails is an error of the caller, never an empty answer: callers that test the
# answer assign it first, so that the failure stops the run.
db_rows() {
	SMOKE_SQL=$(printf '%s' "$1" | sed -e "s/{px}/$table_prefix/g")
	export SMOKE_SQL
	# The PHP source is a literal: nothing in it is meant to expand in the shell.
	# shellcheck disable=SC2016
	site_wp --skip-plugins --skip-themes eval '
		global $wpdb;
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( getenv( "SMOKE_SQL" ), ARRAY_N );
		if ( ! is_array( $rows ) || "" !== $wpdb->last_error ) {
			fwrite( STDERR, "install-smoke: the query failed: " . $wpdb->last_error . PHP_EOL );
			exit( 1 );
		}
		foreach ( $rows as $row ) {
			echo implode( "\t", $row ), PHP_EOL;
		}
	'
}

# Prints the names of a site's plugin tables, without the site's prefix $1, sorted.
plugin_tables() {
	found=$(db_rows "SHOW TABLES LIKE '$1seocart\\_%'")
	printf '%s\n' "$found" | sed -e "s/^$1//" | sort
}

# Prints one line per plugin table of the current site: its name, how many rows it holds, a hash
# of every value of every row, and the columns hashed. $1 is an optional extended regular
# expression of table names, without the prefix, to leave out. $2 is an optional earlier output
# of this function: each table it names is then read by the columns it had then, so a migration
# that adds a column keeps the hash of the values that were there, and one that drops or changes
# a value does not.
table_checksums() {
	SMOKE_PREFIX=$table_prefix
	SMOKE_SKIP=${1:-}
	SMOKE_COLUMNS=${2:-}
	export SMOKE_PREFIX SMOKE_SKIP SMOKE_COLUMNS
	# The PHP source is a literal: nothing in it is meant to expand in the shell.
	# shellcheck disable=SC2016
	site_wp --skip-plugins --skip-themes eval '
		global $wpdb;
		$wpdb->suppress_errors( true );
		$prefix = (string) getenv( "SMOKE_PREFIX" );
		$skip   = (string) getenv( "SMOKE_SKIP" );
		$tables = $wpdb->get_col( "SHOW TABLES LIKE \"" . $prefix . "seocart\\_%\"" );
		if ( ! is_array( $tables ) || array() === $tables || "" !== $wpdb->last_error ) {
			fwrite( STDERR, "install-smoke: no plugin tables could be listed: " . $wpdb->last_error . PHP_EOL );
			exit( 1 );
		}
		$earlier = array();
		foreach ( explode( "\n", (string) getenv( "SMOKE_COLUMNS" ) ) as $line ) {
			$fields = explode( "\t", $line );
			if ( 4 === count( $fields ) ) {
				$earlier[ $fields[0] ] = $fields[3];
			}
		}
		foreach ( $tables as $table ) {
			if ( "" !== $skip && 1 === preg_match( "/^(" . $skip . ")$/", substr( $table, strlen( $prefix ) + 8 ) ) ) {
				continue;
			}
			$columns = $earlier[ $table ] ?? implode( ",", (array) $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ) );
			$listed  = implode( ", ", array_map( static fn( string $column ): string => "`" . $column . "`", explode( ",", $columns ) ) );
			$rows    = $wpdb->get_results( "SELECT $listed FROM `$table` ORDER BY $listed", ARRAY_N );
			if ( ! is_array( $rows ) || "" !== $wpdb->last_error ) {
				fwrite( STDERR, "install-smoke: could not read $table by the columns $columns: " . $wpdb->last_error . PHP_EOL );
				exit( 1 );
			}
			echo $table, "\t", count( $rows ), "\t", md5( serialize( $rows ) ), "\t", $columns, PHP_EOL;
		}
	'
}

# Prints the values at paths of a JSON file, one per line: $1 is the file, the rest are paths,
# keys joined by "/". A scalar prints as it is, anything else as JSON, a missing path as an
# empty line.
json_values() {
	# shellcheck disable=SC2016
	php -r '
		$d = json_decode( (string) file_get_contents( $argv[1] ), true );
		foreach ( array_slice( $argv, 2 ) as $path ) {
			$v = $d;
			foreach ( explode( "/", $path ) as $key ) {
				$v = is_array( $v ) && array_key_exists( $key, $v ) ? $v[ $key ] : null;
			}
			echo is_scalar( $v ) ? $v : ( null === $v ? "" : json_encode( $v ) ), PHP_EOL;
		}
	' "$@"
}

# Prints the values at paths of the last REST answer, one per line. See json_values.
answer() {
	json_values "$work/answer.json" "$@"
}

# Prints one field of a site's boot record, which holds the store's identity. $1 is the field,
# $2 the site's URL when it is not the current one.
boot_field() {
	site_wp --url="${2:-$url}" --skip-plugins --skip-themes option get seocart_boot >"$work/boot.json" 2>/dev/null || true
	json_values "$work/boot.json" "$1"
}

# --- Requests to the site's REST API --------------------------------------------------------

# Sends one request; the answer goes to $work/answer.json and the HTTP status is printed.
# $1 is the method, $2 the route, $3 the JSON body or an empty string, the rest curl options.
send() {
	send_method=$1
	send_route=$2
	send_body=$3
	shift 3

	# A route may carry a query string; behind "?rest_route=" it becomes one more parameter.
	case $send_route in
		*\?*) send_route=${send_route%%\?*}\&${send_route#*\?} ;;
	esac

	if [ -n "$send_body" ]; then
		set -- "$@" --data "$send_body"
	fi

	curl -sS -m 60 -o "$work/answer.json" -w '%{http_code}' -H 'Content-Type: application/json' -X "$send_method" "$@" "$url/?rest_route=$send_route"
}

# A request by the administrator. The application password is in a file only its owner can
# read and reaches curl through that file, never through a command line.
as_admin() {
	send "$1" "$2" "${3:-}" -K "$work/curl-auth"
}

# A request by a guest: the cart cookie lives in a jar, the Store API wants its marker header,
# and $4, when given, is one more header ("Name: value").
as_guest() {
	guest_header=${4:-}

	if [ -n "$guest_header" ]; then
		send "$1" "$2" "${3:-}" -b "$work/jar" -c "$work/jar" -H 'X-SEOCart-Store: 1' -H "$guest_header"
	else
		send "$1" "$2" "${3:-}" -b "$work/jar" -c "$work/jar" -H 'X-SEOCart-Store: 1'
	fi
}

# Fails unless the status $1 is one of those listed in $2. $3 says what was requested.
expect_status() {
	case " $2 " in
		*" $1 "*) return 0 ;;
	esac

	fail "$3 answered HTTP $1, expected $2: $(cat "$work/answer.json")"
}

# Gives the administrator an application password, in a file only its owner can read.
prepare_admin_requests() {
	(
		umask 077
		site_wp user application-password create admin install-smoke --porcelain >"$work/app-pass"
		{
			printf 'user = "admin:'
			tr -d '\n' <"$work/app-pass"
			printf '"\n'
		} >"$work/curl-auth"
	)
}

# Creates a product with a price and five units of stock, the way a merchant does: through the
# REST API. Sets $product_id, $variant_id and $product_sku.
create_a_product_with_stock() {
	product_sku=SMOKE-$$

	status=$(as_admin POST /wp/v2/seocart-products "{\"title\":\"Install smoke product\",\"status\":\"publish\",\"seocart\":{\"sku\":\"$product_sku\",\"price_minor\":1999}}")
	expect_status "$status" 201 'creating a product'
	product_id=$(answer id)

	variant_id=$(db_rows "SELECT id FROM {px}seocart_variants WHERE sku = '$product_sku'")
	[ -n "$variant_id" ] || fail "the product $product_id has no variant with the SKU $product_sku."

	status=$(as_admin POST "/seocart/v1/stock-items/$variant_id/adjustments" '{"delta":5,"reason":"received"}')
	expect_status "$status" 200 'receiving stock'

	printf 'Product %s (variant %s, SKU %s) with 5 units in stock.\n' "$product_id" "$variant_id" "$product_sku"
}

# Fails unless the rows the walk wrote are there: the variant's price, its stock, and, once an
# order was placed ($1 is "ordered"), the order and the unit it allocated. A comparison of two
# snapshots proves nothing when both are empty, so this runs before the snapshots are compared.
assert_the_store_has_its_rows() {
	price=$(db_rows "SELECT price_minor FROM {px}seocart_variant_prices WHERE variant_id = $variant_id")
	[ "$price" = 1999 ] || fail "the variant's price row is missing or changed: \"$price\" instead of 1999."

	stock=$(db_rows "SELECT on_hand, allocated FROM {px}seocart_stock_items WHERE variant_id = $variant_id")

	if [ "${1:-}" = ordered ]; then
		orders=$(db_rows 'SELECT COUNT(*) FROM {px}seocart_orders')
		[ "$orders" = 1 ] || fail "the orders table holds \"$orders\" orders, not the one."
		[ "$stock" = "$(printf '5\t1')" ] || fail "the stock row is \"$stock\", expected 5 on hand and 1 allocated."
	else
		[ "$stock" = "$(printf '5\t0')" ] || fail "the stock row is \"$stock\", expected 5 on hand and none allocated."
	fi
}

# Prints what the store holds about the product: what a client reads, and the rows of the
# variant, its prices and its stock, as the database holds them. Two prints are equal when
# nothing about the product changed.
product_snapshot() {
	status=$(as_admin GET "/wp/v2/seocart-products/$product_id?context=edit" '')
	expect_status "$status" 200 'reading the product'
	answer title/raw status seocart/sku seocart/price_minor
	db_rows "SELECT * FROM {px}seocart_variants WHERE id = $variant_id"
	db_rows "SELECT * FROM {px}seocart_variant_prices WHERE variant_id = $variant_id"
	db_rows "SELECT variant_id, on_hand, allocated, held, track FROM {px}seocart_stock_items WHERE variant_id = $variant_id"
	db_rows "SELECT delta, on_hand_after, reason FROM {px}seocart_stock_ledger WHERE variant_id = $variant_id ORDER BY id"
}

# A guest buys one unit of the product with the stub gateway. Sets $order_uuid and $order_key.
# The route and its bodies are the Store API's own: cart, checkout, then the order.
place_an_order() {
	rm -f "$work/jar"

	status=$(as_guest POST /seocart/store/v1/cart/lines "{\"lines\":[{\"variant_id\":$variant_id,\"quantity\":1}]}")
	expect_status "$status" 200 'adding the product to the cart'
	cart_version=$(answer version)

	status=$(as_guest PUT /seocart/store/v1/checkout "{\"cart_version\":$cart_version,\"billing_address\":{\"country\":\"GB\",\"first_name\":\"Ada\",\"last_name\":\"Lovelace\",\"line1\":\"12 St James's Square\",\"city\":\"London\",\"postcode\":\"SW1Y 4JH\",\"email\":\"ada@example.com\"},\"shipping_address\":{\"country\":\"GB\",\"line1\":\"12 St James's Square\",\"city\":\"London\",\"postcode\":\"SW1Y 4JH\"},\"payment_method_key\":\"stub\"}")
	expect_status "$status" 200 'filling in the checkout'
	checkout_version=$(answer version)
	grand_total=$(answer totals/summary/grand_minor)
	currency=$(answer totals/currency)

	status=$(as_guest POST /seocart/store/v1/checkout "{\"cart_version\":$checkout_version,\"grand_total_minor\":$grand_total,\"currency\":\"$currency\",\"payment_data\":{\"payment_token\":\"stub:approve\"}}" "Idempotency-Key: install-smoke-$$")
	expect_status "$status" 200 'placing the order'
	order_uuid=$(answer order_uuid)
	order_key=$(answer order_key)
	if [ -z "$order_uuid" ] || [ -z "$order_key" ]; then
		fail "placing the order returned no order: $(cat "$work/answer.json")"
	fi

	printf 'Order %s placed: %s %s.\n' "$(answer order_number)" "$grand_total" "$currency"
}

# Prints what a guest reads about the order with its key: the same on every read.
order_snapshot() {
	status=$(as_guest GET "/seocart/store/v1/orders/$order_uuid" '' "X-SEOCart-Order-Key: $order_key")
	expect_status "$status" 200 'reading the order'
	answer uuid order_number status payment_status currency subtotal_minor tax_total_minor grand_total_minor paid_minor due_minor lines
}

# --- The lifecycle --------------------------------------------------------------------------

# Prints the id of every migration a zip carries, sorted. Each migration class declares its id
# in `const ID`, so the list comes from the zip and nothing here restates it.
zip_migration_ids() {
	unzip -Z1 "$1" | grep '/Migrations/[^/]*\.php$' | while IFS= read -r entry; do
		unzip -p "$1" "$entry" | sed -n "s/^[[:space:]]*public const ID = '\([^']*\)';.*/\1/p"
	done | sort
}

# Prints how many lines $1 has.
count_lines() {
	printf '%s\n' "$1" | wc -l | tr -d ' '
}

# Prints what the site's migrations table holds: one line per migration with its state and when
# it was applied.
migration_rows() {
	db_rows 'SELECT migration_id, state, applied_at, checksum FROM {px}seocart_migrations ORDER BY migration_id'
}

# Proves the upgrade from the earlier zip. $1 is what migration_rows printed before it.
prove_the_upgrade() {
	before=$1
	older_ids=$(zip_migration_ids "$old_zip")
	newer_ids=$(zip_migration_ids "$zip")
	since=$(printf '%s\n%s\n' "$older_ids" "$newer_ids" | sort | uniq -u)

	[ -n "$older_ids" ] || fail 'the earlier zip carries no migration, so it is not a version of this plugin.'
	[ -n "$since" ] || fail 'the earlier zip and this zip carry the same migrations, so installing one over the other proves no upgrade.'
	[ "$(printf '%s\n' "$newer_ids" | head -n "$(count_lines "$older_ids")")" = "$older_ids" ] ||
		fail 'the migrations of the earlier zip are not a strict prefix of this zip'"'"'s, so the upgrade proof does not apply (bin/ci/upgrade-from.env).'

	after=$(migration_rows)

	# Every migration of this zip is recorded once (the table's unique key forbids a second row
	# for one id), as applied, and with the time it was applied.
	[ "$(printf '%s\n' "$after" | cut -f1)" = "$newer_ids" ] ||
		fail "the migrations table does not hold exactly this zip's migrations. It holds:
$(printf '%s\n' "$after" | cut -f1)
The zip carries:
$newer_ids"
	[ -z "$(printf '%s\n' "$after" | cut -f2 | grep -vx applied || true)" ] || fail "a migration is not in the state \"applied\":
$after"

	tab=$(printf '\t')
	for id in $since; do
		[ -n "$(printf '%s\n' "$after" | grep "^$id$tab" | cut -f3)" ] || fail "the migration $id is recorded without the time it was applied."
	done

	# The earlier migrations ran once, under the earlier version, and the upgrade left them alone:
	# a migration that ran again would carry a new time.
	for id in $older_ids; do
		[ "$(printf '%s\n' "$before" | grep "^$id$tab")" = "$(printf '%s\n' "$after" | grep "^$id$tab")" ] ||
			fail "the migration $id ran again during the upgrade."
	done

	printf 'The upgrade recorded %s migrations once each, as applied:\n%s\n' "$(count_lines "$since")" "$since"
	printf 'The %s earlier migrations were not run again.\n' "$(count_lines "$older_ids")"

	newest=$(printf '%s\n' "$newer_ids" | tail -n 1)
	[ "$(boot_field schema_head)" = "$newest" ] || fail "the boot record's schema head is \"$(boot_field schema_head)\", expected $newest."
	printf 'The boot record points at the newest migration, %s.\n' "$newest"
}

# Prints the plugin's options and a checksum of each value.
plugin_options() {
	db_rows "SELECT option_name, MD5( option_value ) FROM {px}options WHERE option_name LIKE 'seocart\\_%' ORDER BY option_name"
}

# --- Run ------------------------------------------------------------------------------------

# Tables whose rows change when requests are served or the plugin's jobs run, whatever the data
# of the store is. Everything else the earlier version wrote must read back unchanged after the
# upgrade.
operational_tables='migrations|locks|outbox|logs|rate_counters'

# Fails unless every table of $1 (table_checksums output taken before) reads the same in $2
# (taken after): the same rows, down to every value. $3 says what happened in between.
same_tables() {
	tab=$(printf '\t')

	for table in $(printf '%s\n' "$1" | cut -f1); do
		[ "$(printf '%s\n' "$1" | grep "^$table$tab")" = "$(printf '%s\n' "$2" | grep "^$table$tab")" ] ||
			fail "the table $table changed $3. Before: $(printf '%s\n' "$1" | grep "^$table$tab") After: $(printf '%s\n' "$2" | grep "^$table$tab")"
	done
}

# A guest buys the product and reads the order back: the vertical path through the new tables.
buy_and_check() {
	place_an_order
	order_before=$(order_snapshot)
	printf '%s\n' "$order_before"
	[ "$(order_snapshot)" = "$order_before" ] || fail 'two reads of the order answered differently.'
	assert_the_store_has_its_rows ordered
	printf 'The orders table holds the order, and one unit of the stock is allocated to it.\n'
}

step "downloading WordPress ($wp_version)"
sh "$script_dir/download-wordpress.sh" "$wp_version" "$core"

create_site single "$first_port" single
prove baseline

if [ -n "$old_zip" ]; then
	step "installing the earlier version, $(basename -- "$old_zip")"
	site_wp plugin install "$old_zip" --activate
	site_wp plugin is-active "$plugin" || fail "the earlier zip did not install a plugin with the slug \"$plugin\"."
	exercise 'with the earlier version'

	step 'writing data with the earlier version: a product with stock, and an order for it'
	prepare_admin_requests
	create_a_product_with_stock
	assert_the_store_has_its_rows
	buy_and_check
	product_before=$(product_snapshot)
	data_before=$(table_checksums "$operational_tables")
	migrations_before=$(migration_rows)
	printf '%s\n' "$product_before"
	printf '%s\n' "$data_before"

	step "upgrading: installing $(basename -- "$zip") over it"
	site_wp plugin install "$zip" --force
	site_wp plugin is-active "$plugin" || fail 'the upgrade left the plugin inactive.'
	exercise 'after the upgrade'

	# The first request after the upgrade queues the migrations since as a job, and the store
	# goes on trading while they are outstanding. A site's cron or an admin request runs the job;
	# with cron off, the plugin's own command does the same.
	step 'running the jobs the upgrade queued'
	site_wp seocart jobs status
	site_wp seocart jobs run

	step 'checking the upgrade'
	prove_the_upgrade "$migrations_before"
	assert_the_store_has_its_rows ordered
	product_after=$(product_snapshot)
	[ "$product_after" = "$product_before" ] || fail "the product, its prices or its stock changed in the upgrade. Before:
$product_before
After:
$product_after"
	order_after=$(order_snapshot)
	[ "$order_after" = "$order_before" ] || fail "the order (header, lines, totals, payment status) reads differently after the upgrade. Before:
$order_before
After:
$order_after"
	same_tables "$data_before" "$(table_checksums "$operational_tables" "$data_before")" 'in the upgrade'
	printf 'The product, its prices, its stock and the order read back unchanged, and so does every one of the %s data tables.\n' "$(count_lines "$data_before")"
	prove upgrade
else
	step "installing $(basename -- "$zip")"
	site_wp plugin install "$zip" --activate
	site_wp plugin is-active "$plugin" || fail "the zip did not install a plugin with the slug \"$plugin\"."
	exercise 'after activation'
	printf 'install-smoke: the upgrade was NOT proved: SEOCART_CI_OLD_ZIP names no earlier zip.\n'

	step 'a guest buys a product and reads the order back'
	prepare_admin_requests
	create_a_product_with_stock
	assert_the_store_has_its_rows
	buy_and_check
fi
prove activation
prove order

step 'deactivating'
site_wp plugin deactivate "$plugin"
exercise 'after deactivation'
prove deactivation

step 'reactivating'
site_wp plugin activate "$plugin"
exercise 'after reactivation'
prove reactivation

step 'uninstalling: the store is preserved'
site_wp plugin deactivate "$plugin"
assert_the_store_has_its_rows ordered
tables_before=$(table_checksums)
options_before=$(plugin_options)
identity_before=$(boot_field install_uuid)
[ -n "$identity_before" ] || fail 'the site has no boot record, so it has no identity to preserve.'
printf '%s\n' "$tables_before"

site_wp plugin uninstall "$plugin" --deactivate
[ ! -e "$site/wp-content/plugins/$plugin" ] || fail 'uninstalling left the plugin files behind.'
exercise 'after uninstalling'

assert_the_store_has_its_rows ordered
tables_after=$(table_checksums)
[ "$tables_after" = "$tables_before" ] || fail "uninstalling changed the plugin's tables or what they hold (name, rows, a hash of every value, the columns). Before:
$tables_before
After:
$tables_after"
options_after=$(plugin_options)
[ "$options_after" = "$options_before" ] || fail "uninstalling changed the plugin's options. Before:
$options_before
After:
$options_after"
printf 'The plugin is gone; its %s tables, every value in them and its %s options are all still there.\n' \
	"$(count_lines "$tables_before")" "$(count_lines "$options_before")"

site_wp plugin install "$zip" --activate
exercise 'after installing again'
[ "$(boot_field install_uuid)" = "$identity_before" ] || fail 'installing again after an uninstall gave the site a new identity.'
[ "$(order_snapshot)" = "$order_before" ] || fail 'the order reads differently after the plugin was installed again.'
[ -z "$(boot_field safe_mode)" ] || fail "installing again after an uninstall put the store in safe mode: $(boot_field safe_mode)"
printf 'Installing again recognised the same store (%s), and its order.\n' "$identity_before"
prove uninstall-preserve

step 'checking the debug log'
check_the_log
prove clean-log

# --- A WordPress network --------------------------------------------------------------------

stop_server
create_site network "$((first_port + 1))" network
network_root=$url
main_prefix=$table_prefix

# A site that exists before the plugin does, to see that network activation leaves it alone.
early_id=$(site_wp site create --slug=early --title='Early' --email=admin@example.org --porcelain)
early_url=$network_root/early
early_prefix=${main_prefix}${early_id}_

step 'network activation installs the main site, and only the main site'
site_wp plugin install "$zip" --activate-network
site_wp plugin is-active "$plugin" --network || fail 'the zip did not install a network-active plugin.'
exercise 'after network activation'
main_tables=$(plugin_tables "$main_prefix")
[ -n "$main_tables" ] || fail 'network activation installed no table on the main site.'
main_identity=$(boot_field install_uuid)
[ -n "$main_identity" ] || fail 'network activation left the main site without a boot record.'
printf 'The main site holds %s plugin tables and the identity %s.\n' "$(count_lines "$main_tables")" "$main_identity"
early_tables=$(plugin_tables "$early_prefix")
[ -z "$early_tables" ] || fail 'network activation installed the plugin on a site that existed before it, eagerly.'
printf 'Site %s, which existed before the plugin, has none of its tables yet.\n' "$early_id"

step 'a site that existed before installs itself on its first request'
# A request of the kind a visitor's tools send: the REST index of that site.
http_ok "$early_url/?rest_route=/" 'the first request to the early site'
early_tables=$(plugin_tables "$early_prefix")
[ "$early_tables" = "$main_tables" ] || fail "the early site did not install itself on its first request. It has:
$early_tables
The main site has:
$main_tables"
early_identity=$(boot_field install_uuid "$early_url")
[ -n "$early_identity" ] || fail 'the early site has no boot record after its first request.'
[ "$early_identity" != "$main_identity" ] || fail "the early site shares the main site's identity."
site_wp --url="$early_url" role exists seocart_manager || fail "the early site has none of the plugin's roles."
printf 'Site %s installed itself: the same %s plugin tables, its own identity and its own roles.\n' "$early_id" "$(count_lines "$early_tables")"

step 'a site created afterwards installs itself'
second_id=$(site_wp site create --slug=second --title='Second' --email=admin@example.org --porcelain)
second_url=$network_root/second
second_prefix=${main_prefix}${second_id}_
second_tables=$(plugin_tables "$second_prefix")
[ "$second_tables" = "$main_tables" ] || fail "the new site has not the plugin tables of the main site. It has:
$second_tables
The main site has:
$main_tables"
second_identity=$(boot_field install_uuid "$second_url")
[ -n "$second_identity" ] || fail 'the new site has no boot record.'
[ "$second_identity" != "$main_identity" ] || fail "the new site shares the main site's identity."
[ "$second_identity" != "$early_identity" ] || fail "the new site shares the early site's identity."
site_wp --url="$second_url" role exists seocart_manager || fail "the new site has none of the plugin's roles."
printf 'Site %s holds the same %s plugin tables, its own identity and its own roles.\n' "$second_id" "$(count_lines "$second_tables")"
http_ok "$second_url/" 'the new site'

step 'the plugin can be switched per site'
site_wp plugin deactivate "$plugin" --network
if site_wp plugin is-active "$plugin" --url="$second_url"; then
	fail 'the second site still has the plugin after the network deactivation.'
fi
site_wp plugin activate "$plugin" --url="$second_url"
site_wp plugin is-active "$plugin" --url="$second_url" || fail 'activating the plugin on one site did not stick.'
if site_wp plugin is-active "$plugin"; then
	fail 'activating the plugin on the second site activated it on the main site.'
fi
site_wp plugin deactivate "$plugin" --url="$second_url"
site_wp plugin activate "$plugin" --url="$second_url"
http_ok "$second_url/" 'after the plugin was switched on the second site'
switched_tables=$(plugin_tables "$second_prefix")
[ "$switched_tables" = "$main_tables" ] || fail "switching the plugin on the second site changed its tables. It has:
$switched_tables
The main site has:
$main_tables"
printf 'The plugin switches on and off per site, and keeps the site'"'"'s tables.\n'

step 'deleting a site drops its plugin tables'
site_wp plugin activate "$plugin" --network
third_id=$(site_wp site create --slug=third --title='Third' --email=admin@example.org --porcelain)
third_prefix=${main_prefix}${third_id}_
third_tables=$(plugin_tables "$third_prefix")
[ -n "$third_tables" ] || fail 'the third site was not installed.'
site_wp site delete "$third_id" --yes
leftover=$(db_rows "SHOW TABLES LIKE '${third_prefix}%'")
[ -z "$leftover" ] || fail "deleting the site left tables behind: $leftover"
printf 'Deleting site %s dropped all its tables, the plugin'"'"'s among them.\n' "$third_id"
exercise 'after a site was deleted'

step 'checking the network'"'"'s debug log'
check_the_log
prove multisite
prove clean-install

printf '\ninstall-smoke: PASS: every step above passed, each with a clean debug log.\n'
for name in $proved; do
	printf 'install-smoke: proved %s\n' "$name"

	if [ -n "${SEOCART_CI_PROOF_FILE:-}" ]; then
		printf 'install-smoke: proved %s\n' "$name" >>"$SEOCART_CI_PROOF_FILE"
	fi
done

# shellcheck shell=sh
#
# Shared functions for the bin/dev/ provisioning scripts. Sourced, never run on its own
# (hence no interpreter line and no executable bit):
#
#     . "$SC_DEV_DIR/lib.sh"
#
# Portable POSIX sh (the dev server's /bin/sh is not bash): no `local`, no arrays, no
# `pipefail`. Function-private variables carry an `sc_` prefix instead.
#
# Nothing in this directory may contain a host name, an address, an account name or a
# password. Everything environment-specific is derived at run time from the integration
# site (SEOCART_DEV_SITE_PATH), read from the untracked settings file below the state
# directory, or generated from /dev/urandom.
#
# Exit codes used by every script: 0 success, 1 the operation failed, 2 usage error or
# invalid slug. check-residue.sh adds 3: a check could not be carried out.

set -eu

# Byte-wise character classes: `[a-z]` must never match an upper-case or accented letter.
LC_ALL=C
export LC_ALL

# ---------------------------------------------------------------------------------------
# Paths. Every one is derived from HOME and can be overridden from the environment.
# ---------------------------------------------------------------------------------------

# The integration site. Its wp-config.php names the MySQL account that provisions
# databases, and its `home` option is the base URL of every disposable instance.
: "${SEOCART_DEV_SITE_PATH:=$HOME/public_html}"

# Generated credentials, debug logs and the per-server settings file live here, outside
# the web root and the repository.
: "${SEOCART_DEV_STATE_DIR:=$HOME/.seocart-dev}"

# The shared, read-only WordPress core checkout.
: "${SEOCART_DEV_WP_CORE_DIR:=$HOME/wp-core-testing/7.1.1}"

# The canonical clone and the directory that holds one git worktree per task.
: "${SEOCART_DEV_REPO:=$HOME/dev/seocart}"
: "${SEOCART_DEV_WORKTREES_ROOT:=$HOME/dev/seocart.worktrees}"

# The WP-CLI executable.
: "${SEOCART_DEV_WP:=wp}"

# Per-server settings, untracked: NAME=value lines. See sc_configured_db_prefix.
SC_CONFIG_FILE=$SEOCART_DEV_STATE_DIR/config

# Disposable instances are subdirectory installs below the integration site, so the web
# server reaches them with no virtual-host change.
SC_INSTANCES_DIRNAME=_worktrees
SC_INSTANCES_ROOT=$SEOCART_DEV_SITE_PATH/$SC_INSTANCES_DIRNAME

# Scoped MySQL accounts connect through the local socket only.
SC_DB_ACCOUNT_HOST=localhost

# The longest slug that keeps every derived name legal:
#   - MySQL account names hold 32 characters; `seocart_test_` takes 13, leaving 19.
#   - MySQL table names hold 64 characters; the table prefix is `wp_<slug>_` and the
#     longest table a --with-woocommerce instance creates has 44 characters
#     (woocommerce_downloadable_product_permissions): 64 - 44 - 4 = 16.
SC_SLUG_MAX=16

# ---------------------------------------------------------------------------------------
# Messages, temporary files, exit handling.
# ---------------------------------------------------------------------------------------

sc_info() {
	printf '%s\n' "$*"
}

sc_warn() {
	printf 'warning: %s\n' "$*" >&2
}

sc_die() {
	printf 'error: %s\n' "$*" >&2
	exit 1
}

# Usage errors exit 2. The calling script defines usage().
sc_usage_error() {
	printf 'error: %s\n\n' "$1" >&2
	usage >&2
	exit 2
}

SC_TMPDIR=
SC_FAILURE_HINT=

# Creates this run's private scratch directory (mode 700). Call it from the main shell,
# not from a command substitution, so that the exit trap below knows about it.
sc_tmp_init() {
	if [ -z "$SC_TMPDIR" ]; then
		SC_TMPDIR=$(umask 077 && mktemp -d "${TMPDIR:-/tmp}/seocart-dev.XXXXXXXX") || sc_die "could not create a temporary directory"
	fi
}

sc_on_exit() {
	sc_exit_status=$?
	trap - EXIT
	# Guarded: only ever a directory that mktemp created from the pattern above.
	case $SC_TMPDIR in
		*/seocart-dev.????????)
			rm -rf -- "$SC_TMPDIR"
			;;
	esac
	if [ "$sc_exit_status" -ne 0 ] && [ -n "$SC_FAILURE_HINT" ]; then
		printf '%s\n' "$SC_FAILURE_HINT" >&2
	fi
	exit "$sc_exit_status"
}

trap sc_on_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------------------------------
# Slugs and the names derived from them.
# ---------------------------------------------------------------------------------------

# Returns 0 for a usable slug; otherwise explains on stderr and returns 1. Every script
# validates before it builds a path or a database name, so `../x` never reaches rm or SQL.
sc_validate_slug() {
	sc_slug=${1-}
	if [ -z "$sc_slug" ]; then
		printf 'error: the slug is empty\n' >&2
		return 1
	fi
	if [ "${#sc_slug}" -gt "$SC_SLUG_MAX" ]; then
		printf 'error: slug "%s" has %s characters; the limit is %s (MySQL account and table name lengths)\n' "$sc_slug" "${#sc_slug}" "$SC_SLUG_MAX" >&2
		return 1
	fi
	case $sc_slug in
		*[!a-z0-9-]*)
			printf 'error: slug "%s" may contain only lower-case a-z, 0-9 and "-"\n' "$sc_slug" >&2
			return 1
			;;
		-* | *-)
			printf 'error: slug "%s" may not start or end with "-"\n' "$sc_slug" >&2
			return 1
			;;
	esac
	return 0
}

# The slug as it appears inside MySQL names. "_" cannot occur in a slug, so replacing "-"
# keeps the mapping one-to-one.
sc_slug_underscored() {
	printf '%s' "$1" | tr '-' '_'
}

# sc_account_name <kind> <slug> — the scoped MySQL account of a database. Never prefixed:
# an account name holds only 32 characters.
sc_account_name() {
	printf 'seocart_%s_%s\n' "$1" "$(sc_slug_underscored "$2")"
}

# sc_db_name <kind> <slug> — the database, behind the prefix this server demands (see
# sc_load_db_prefix, which has to run first).
sc_db_name() {
	[ "$SC_DB_PREFIX_LOADED" = yes ] || sc_die "internal error: sc_load_db_prefix has not run"
	printf '%s%s_%s\n' "$SC_DB_PREFIX" "$1" "$(sc_slug_underscored "$2")"
}

sc_table_prefix() {
	printf 'wp_%s_\n' "$(sc_slug_underscored "$1")"
}

sc_site_dir() {
	printf '%s/%s\n' "$SC_INSTANCES_ROOT" "$1"
}

# The one symlink of an instance: its SEOCart plugin directory, pointing at the checkout.
sc_plugin_link() {
	printf '%s/wp-content/plugins/seocart\n' "$(sc_site_dir "$1")"
}

sc_env_file() {
	printf '%s/instances/%s.env\n' "$SEOCART_DEV_STATE_DIR" "$1"
}

sc_debug_log() {
	printf '%s/logs/%s-debug.log\n' "$SEOCART_DEV_STATE_DIR" "$1"
}

sc_tests_config() {
	printf '%s/tests/wp-tests-config.local.php\n' "$1"
}

# ---------------------------------------------------------------------------------------
# What exists for a slug. provision-*.sh create it; teardown-site.sh removes it and
# check-residue.sh looks for it, both by walking the declarations below and nothing
# else. A new artefact is declared here first; the two scripts then remove it and check
# for it without being edited. The instance directory (sc_site_dir) is the one artefact
# they handle by name, because its removal is guarded (sc_remove_site_dir).
# ---------------------------------------------------------------------------------------

# One database and one scoped account of each kind: the instance's and PHPUnit's.
SC_DB_KINDS='wt test'

# sc_state_files <slug> — the files below the state directory, one per line.
sc_state_files() {
	sc_env_file "$1"
	sc_debug_log "$1"
}

# sc_tests_config_candidates <slug> [<checkout>] — the checkouts whose local test
# configuration may belong to this slug, one per line: the one the caller names, the one
# the instance's plugin link points at (while the instance exists), and the slug's
# worktree.
sc_tests_config_candidates() {
	{
		if [ -n "${2-}" ]; then
			printf '%s\n' "$2"
		fi
		sc_link=$(sc_plugin_link "$1")
		if [ -L "$sc_link" ]; then
			readlink "$sc_link" || true
		fi
		printf '%s\n' "$SEOCART_DEV_WORKTREES_ROOT/$1"
	} | awk '!seen[$0]++'
}

# sc_tests_config_names_account <file> <account> — true if the file exists and names the
# account as a whole word. The file of another slug is not this slug's to report or
# delete: seocart_test_a must not match seocart_test_a_b.
sc_tests_config_names_account() {
	[ -f "$1" ] && grep -Eq "(^|[^a-z0-9_])$2([^a-z0-9_]|\$)" "$1"
}

# Prints the physical path of an existing directory.
sc_physical_dir() {
	(CDPATH='' cd -- "$1" && pwd -P)
}

# Prints the checkout to work on: the given path, or the git work tree around the
# current directory.
sc_resolve_checkout() {
	if [ -n "${1-}" ]; then
		[ -d "$1" ] || sc_die "checkout not found: $1"
		sc_physical_dir "$1"
		return 0
	fi
	sc_toplevel=$(git rev-parse --show-toplevel 2>/dev/null) || sc_die "not inside a git work tree; pass --checkout=<path>"
	sc_physical_dir "$sc_toplevel"
}

# Removes the instance directory of a slug and nothing else. The slug is validated again
# here, and the physical path must be exactly <instances root>/<slug>.
sc_remove_site_dir() {
	sc_validate_slug "${1-}" || return 1
	[ -n "$SC_INSTANCES_ROOT" ] && [ "$SC_INSTANCES_ROOT" != / ] || sc_die "refusing to remove anything: the instances root is not set"
	sc_target=$SC_INSTANCES_ROOT/$1
	if [ -L "$sc_target" ]; then
		# A symlink where a directory belongs: remove the link, never what it points to.
		rm -f -- "$sc_target"
		return 0
	fi
	[ -d "$sc_target" ] || return 0
	sc_target_physical=$(sc_physical_dir "$sc_target") || return 1
	sc_root_physical=$(sc_physical_dir "$SC_INSTANCES_ROOT") || return 1
	if [ -z "$sc_root_physical" ] || [ "$sc_target_physical" != "$sc_root_physical/$1" ]; then
		sc_die "refusing to remove $sc_target: it does not resolve to $SC_INSTANCES_ROOT/$1"
	fi
	rm -rf -- "$sc_target_physical"
}

# ---------------------------------------------------------------------------------------
# Prerequisites.
# ---------------------------------------------------------------------------------------

sc_require_commands() {
	for sc_command in "$@"; do
		command -v "$sc_command" >/dev/null 2>&1 || sc_die "required command not found on PATH: $sc_command"
	done
}

# WP-CLI 2.12 or newer.
sc_require_wp_cli() {
	sc_require_commands "$SEOCART_DEV_WP"
	sc_version=$("$SEOCART_DEV_WP" --version </dev/null 2>/dev/null) || sc_die "could not run $SEOCART_DEV_WP --version"
	sc_version=${sc_version#WP-CLI }
	sc_major=${sc_version%%.*}
	sc_minor=${sc_version#*.}
	sc_minor=${sc_minor%%.*}
	case $sc_major$sc_minor in
		'' | *[!0-9]*)
			sc_die "could not read the WP-CLI version from: $sc_version"
			;;
	esac
	if [ "$sc_major" -lt 2 ] || { [ "$sc_major" -eq 2 ] && [ "$sc_minor" -lt 12 ]; }; then
		sc_die "WP-CLI 2.12 or newer is required; found $sc_version"
	fi
}

# ---------------------------------------------------------------------------------------
# The integration site: provisioning account, socket, base URL.
# ---------------------------------------------------------------------------------------

# Prints one wp-config.php constant of the integration site. Callers must never print it.
sc_site_config() {
	"$SEOCART_DEV_WP" --path="$SEOCART_DEV_SITE_PATH" config get "$1" </dev/null 2>/dev/null
}

# Prints the MySQL socket path: SEOCART_DEV_MYSQL_SOCKET, else the socket part of the
# integration site's DB_HOST, else PHP's mysqli default. The mysql client's compiled-in
# default is not consulted: on the dev server it points at a socket that does not exist.
sc_mysql_socket() {
	if [ -n "${SEOCART_DEV_MYSQL_SOCKET-}" ]; then
		sc_socket=$SEOCART_DEV_MYSQL_SOCKET
	else
		sc_db_host=$(sc_site_config DB_HOST) || sc_db_host=
		case $sc_db_host in
			*:/*)
				sc_socket=/${sc_db_host#*:/}
				;;
			*)
				sc_socket=$(php -r 'echo ini_get( "mysqli.default_socket" );' </dev/null 2>/dev/null) || sc_socket=
				;;
		esac
	fi
	if [ -z "$sc_socket" ] || [ ! -S "$sc_socket" ]; then
		printf 'error: no MySQL socket at "%s"; set SEOCART_DEV_MYSQL_SOCKET\n' "$sc_socket" >&2
		return 1
	fi
	printf '%s\n' "$sc_socket"
}

# Prints the home URL of the integration site without a trailing slash.
sc_base_url() {
	sc_url=$("$SEOCART_DEV_WP" --path="$SEOCART_DEV_SITE_PATH" --skip-plugins --skip-themes option get home </dev/null 2>/dev/null) || sc_url=
	sc_url=${sc_url%/}
	case $sc_url in
		http://?* | https://?*) ;;
		*)
			printf 'error: could not read the home URL of the integration site at %s (SEOCART_DEV_SITE_PATH)\n' "$SEOCART_DEV_SITE_PATH" >&2
			return 1
			;;
	esac
	printf '%s\n' "$sc_url"
}

sc_instance_url() {
	sc_home=$(sc_base_url) || return 1
	printf '%s/%s/%s\n' "$sc_home" "$SC_INSTANCES_DIRNAME" "$1"
}

# ---------------------------------------------------------------------------------------
# Secrets.
# ---------------------------------------------------------------------------------------

# sc_random <length> <tr character set> — characters drawn from /dev/urandom.
sc_random() {
	sc_random_value=$(tr -dc "$2" </dev/urandom | dd bs=1 count="$1" 2>/dev/null)
	if [ "${#sc_random_value}" -ne "$1" ]; then
		printf 'error: could not read %s random characters from /dev/urandom\n' "$1" >&2
		return 1
	fi
	printf '%s\n' "$sc_random_value"
}

# Letters and digits only: the value is embedded in SQL, PHP, YAML and .env files, and
# none of them needs quoting rules for it. 32 characters carry about 190 bits.
sc_password() {
	sc_random 32 'A-Za-z0-9'
}

# ---------------------------------------------------------------------------------------
# MySQL. sc_sql is the ONLY function that starts the mysql client.
# ---------------------------------------------------------------------------------------

# Escapes a value for a double-quoted entry of a MySQL option file. The value travels
# through a here-document, never through a command line.
sc_option_file_escape() {
	sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' <<EOF
$1
EOF
}

# Runs the SQL on standard input as the provisioning account; prints rows tab-separated
# without a header. The account is read from the integration site's wp-config.php at
# call time and reaches the client through an option file (mode 600, removed by a trap) —
# never through a command line, where `ps` would show it. SQL arrives on standard input
# for the same reason: CREATE USER statements carry generated passwords. Error text is
# passed on with the account name taken out.
#
# The body is a subshell so that its trap and variables stay its own; that also makes it
# safe to call from a command substitution.
sc_sql() (
	umask 077
	sc_option_file=$(mktemp "${TMPDIR:-/tmp}/seocart-dev.XXXXXXXX") || exit 1
	sc_error_file=$(mktemp "${TMPDIR:-/tmp}/seocart-dev.XXXXXXXX") || {
		rm -f -- "$sc_option_file"
		exit 1
	}
	trap 'rm -f -- "$sc_option_file" "$sc_error_file"' EXIT
	trap 'exit 129' HUP
	trap 'exit 130' INT
	trap 'exit 143' TERM

	sc_account=$(sc_site_config DB_USER) && sc_secret=$(sc_site_config DB_PASSWORD) || {
		printf 'error: could not read the provisioning account from %s/wp-config.php (SEOCART_DEV_SITE_PATH)\n' "$SEOCART_DEV_SITE_PATH" >&2
		exit 1
	}
	sc_socket_path=$(sc_mysql_socket) || exit 1

	cat >"$sc_option_file" <<EOF
[client]
user="$(sc_option_file_escape "$sc_account")"
password="$(sc_option_file_escape "$sc_secret")"
socket="$(sc_option_file_escape "$sc_socket_path")"
default-character-set=utf8mb4
EOF

	sc_sql_status=0
	mysql --defaults-file="$sc_option_file" --batch --skip-column-names 2>"$sc_error_file" || sc_sql_status=$?
	sed -e "s/for user '[^']*'@'[^']*'/for the provisioning account/g" "$sc_error_file" >&2
	exit "$sc_sql_status"
)

# The start of every database name. It is a per-server setting, because it has to match
# what the server's MySQL administrator granted: some servers only let an account own
# databases whose names start with the account's own name. By owner decision (repository
# strategy, section 6.1) the value lives in the untracked settings file, never in a
# tracked one:
#
#     SEOCART_DEV_DB_PREFIX=<account>_seocart_
#
# Without the file, or without that line, the prefix is the default below. Accounts are
# never prefixed. There is deliberately no environment override: two shells must not be
# able to disagree about what a slug's databases are called.
SC_DB_PREFIX_DEFAULT=seocart_

# Prints the configured prefix. Needs no MySQL.
sc_configured_db_prefix() {
	sc_configured=$SC_DB_PREFIX_DEFAULT
	if [ -f "$SC_CONFIG_FILE" ] && grep -q '^SEOCART_DEV_DB_PREFIX=' "$SC_CONFIG_FILE"; then
		# Parsed, not sourced: a settings file must not be able to run commands.
		sc_configured=$(sed -n -e 's/^SEOCART_DEV_DB_PREFIX=//p' "$SC_CONFIG_FILE" | tail -n 1)
	fi
	case $sc_configured in
		'' | *[!A-Za-z0-9_]*)
			printf 'error: SEOCART_DEV_DB_PREFIX in %s must be letters, digits and "_", written without quotes; found "%s"\n' "$SC_CONFIG_FILE" "$sc_configured" >&2
			return 1
			;;
	esac
	# MySQL database names hold 64 characters: prefix + "test_" + slug.
	if [ "${#sc_configured}" -gt $((64 - 5 - SC_SLUG_MAX)) ]; then
		printf 'error: SEOCART_DEV_DB_PREFIX in %s is too long: "%s"\n' "$SC_CONFIG_FILE" "$sc_configured" >&2
		return 1
	fi
	printf '%s\n' "$sc_configured"
}

# Sets SC_DB_PREFIX for sc_db_name; call it from the main shell.
#
# The setting and the grant state the same fact in two places that cannot be merged (MySQL
# enforces the one, the owner decision names the other), so they are compared here, where
# every script passes: the prefix must be what stands before the final "\_%" of one of
# the provisioning account's database-level grants. A disagreement stops provisioning,
# teardown and the residue gate alike, before any of them uses a wrong name. An account
# with no such grant (global privileges, as in CI) cannot be compared; that is said, once
# per run, rather than passed over.
SC_DB_PREFIX=
SC_DB_PREFIX_LOADED=no

sc_load_db_prefix() {
	[ "$SC_DB_PREFIX_LOADED" = no ] || return 0
	sc_prefix=$(sc_configured_db_prefix) || return 1
	sc_grants=$(printf 'SHOW GRANTS;\n' | sc_sql) || {
		printf 'error: could not read the grants of the provisioning account\n' >&2
		return 1
	}
	# One candidate per database-level grant whose pattern ends in seocart_% — with the
	# underscore escaped, as it should be, or not. The client prints a backslash as two
	# in batch mode, so any number is accepted; an escaped underscore inside the pattern
	# is a literal underscore.
	sc_granted=$(printf '%s\n' "$sc_grants" | sed -n -e 's/^GRANT .* ON `\(.*seocart\)\\*_%`\.\* TO .*$/\1_/p' | sed -e 's/\\\\*_/_/g')
	if [ -z "$sc_granted" ]; then
		printf 'note: the provisioning account has no database-level grant on a pattern ending in "seocart\\_%%"; the database prefix "%s" is used unchecked\n' "$sc_prefix" >&2
	elif ! printf '%s\n' "$sc_granted" | grep -Fxq -- "$sc_prefix"; then
		printf 'error: the database prefix is "%s" (SEOCART_DEV_DB_PREFIX in %s, default "%s"), but the provisioning account is granted: %s\n' "$sc_prefix" "$SC_CONFIG_FILE" "$SC_DB_PREFIX_DEFAULT" "$(printf '%s' "$sc_granted" | tr '\n' ' ')" >&2
		printf 'error: make the setting and the grant agree; nothing was created, dropped or checked under a guessed name\n' >&2
		return 1
	fi
	SC_DB_PREFIX=$sc_prefix
	SC_DB_PREFIX_LOADED=yes
}

# What a MySQL administrator has to run once. Printed when a statement is refused.
sc_print_grant_help() {
	sc_pattern=$(sc_configured_db_prefix 2>/dev/null | sed -e 's/_/\\_/g') || sc_pattern=
	cat >&2 <<EOF

If MySQL refused a statement above, the provisioning account (DB_USER in
$SEOCART_DEV_SITE_PATH/wp-config.php) lacks a privilege. What it needs, run once by a MySQL
administrator with that account in place of <account>:

    GRANT ALL PRIVILEGES ON \`${sc_pattern:-<prefix>}%\`.* TO '<account>'@'$SC_DB_ACCOUNT_HOST' WITH GRANT OPTION;
    GRANT CREATE USER ON *.* TO '<account>'@'$SC_DB_ACCOUNT_HOST';

The pattern is SEOCART_DEV_DB_PREFIX from $SC_CONFIG_FILE (default
"$SC_DB_PREFIX_DEFAULT") with each "_" escaped. bin/dev/README.md, "Prerequisites", explains why each
privilege is needed.
EOF
}

# sc_create_database <name> — a utf8mb4 database. An existing one is kept.
sc_create_database() {
	if ! sc_sql <<EOF; then
CREATE DATABASE IF NOT EXISTS \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
EOF
		printf 'error: CREATE DATABASE `%s` failed; nothing was created.\n' "$1" >&2
		sc_print_grant_help
		return 1
	fi
}

# sc_create_account <account> <database> <password> — the account '<account>'@localhost,
# whose privileges stop at that one database. An existing account gets the new password.
#
# In GRANT, "_" and "%" in a database name are wildcards. They are escaped for two
# reasons: the account must not reach a sibling such as seocartXtest, and MySQL accepts
# this grant from an account whose own privilege is on `seocart\_%` only when the name is
# written the same, escaped, way.
sc_create_account() {
	sc_grant_name=$(printf '%s' "$2" | sed -e 's/_/\\_/g')
	if ! sc_sql <<EOF; then
CREATE USER IF NOT EXISTS '$1'@'$SC_DB_ACCOUNT_HOST' IDENTIFIED BY '$3';
ALTER USER '$1'@'$SC_DB_ACCOUNT_HOST' IDENTIFIED BY '$3';
GRANT ALL PRIVILEGES ON \`$sc_grant_name\`.* TO '$1'@'$SC_DB_ACCOUNT_HOST';
EOF
		printf 'error: creating the account %s, or granting it its database, failed. Database `%s` exists.\n' "$1" "$2" >&2
		sc_print_grant_help
		return 1
	fi
}

sc_drop_database() {
	sc_sql <<EOF
DROP DATABASE IF EXISTS \`$1\`;
EOF
}

sc_drop_account() {
	sc_sql <<EOF
DROP USER IF EXISTS '$1'@'$SC_DB_ACCOUNT_HOST';
EOF
}

# ---------------------------------------------------------------------------------------
# Templates.
# ---------------------------------------------------------------------------------------

# PHP's own __NAME__ words. A template may use them; they are not tokens.
SC_PHP_MAGIC_CONSTANTS='__(CLASS|COMPILER_HALT_OFFSET|DIR|FILE|FUNCTION|LINE|METHOD|NAMESPACE|PROPERTY|TRAIT)__'

# sc_render_template <template> <destination> NAME=value ... — replaces each __NAME__ and
# writes the result with mode 600. Fails, leaving no file, if a value cannot sit inside a
# single-quoted PHP string or if a token is left unfilled.
#
# Both the sed script and the rendering are made inside the private scratch directory:
# the values do not pass through a command line, and a half-made file that holds a
# password never lies in a checkout, not even when the script is interrupted.
sc_render_template() {
	sc_template=$1
	sc_destination=$2
	shift 2
	[ -f "$sc_template" ] || sc_die "template not found: $sc_template"
	sc_tmp_init
	sc_sed_script=$SC_TMPDIR/render.sed
	sc_rendered=$SC_TMPDIR/render.out
	: >"$sc_sed_script"
	for sc_pair in "$@"; do
		sc_value=${sc_pair#*=}
		case $sc_value in
			*\'* | *\\* | *'
'*)
				rm -f -- "$sc_sed_script"
				sc_die "a value for ${sc_pair%%=*} contains a quote, a backslash or a line break"
				;;
		esac
		sc_value=$(
			sed -e 's/[&|]/\\&/g' <<EOF
$sc_value
EOF
		)
		cat >>"$sc_sed_script" <<EOF
s|__${sc_pair%%=*}__|$sc_value|g
EOF
	done
	(umask 077 && sed -f "$sc_sed_script" "$sc_template" >"$sc_rendered") || sc_die "could not render $sc_template"
	rm -f -- "$sc_sed_script"
	sc_unfilled=$(grep -o '__[A-Z][A-Z_]*__' "$sc_rendered" | grep -v -x -E "$SC_PHP_MAGIC_CONSTANTS" | sort -u | tr '\n' ' ')
	if [ -n "$sc_unfilled" ]; then
		rm -f -- "$sc_rendered"
		sc_die "$sc_template has tokens this script does not fill: $sc_unfilled"
	fi
	sc_place_file "$sc_rendered" "$sc_destination"
}

# sc_place_file <source> <destination> — puts a finished file from the private scratch
# directory in its place, with mode 600 whatever was there before.
#
# Not mv: the scratch directory is usually on another file system, where mv copies and
# then tries to keep the group of the source. On BSD a file made below /tmp carries the
# group of /tmp, which the account may not hand out, so mv warned on every run.
sc_place_file() {
	rm -f -- "$2" || sc_die "could not replace $2"
	(umask 077 && cp -- "$1" "$2") || sc_die "could not write $2"
	rm -f -- "$1"
}

# ---------------------------------------------------------------------------------------
# HTTP smoke check.
# ---------------------------------------------------------------------------------------

# sc_http_status <connect-to> <url> — prints the HTTP status. With a non-empty first
# argument curl connects to that address instead of resolving the URL's host, keeping
# the Host header and SNI of the URL. Certificate verification is off for that case
# only: this machine's own web server is addressed by number, which no certificate names.
sc_http_status() {
	if [ -n "$1" ]; then
		curl -s -k --connect-timeout 3 -m 30 -o /dev/null -w '%{http_code}' --connect-to "::$1:" "$2"
	else
		curl -s --connect-timeout 5 -m 30 -o /dev/null -w '%{http_code}' "$2"
	fi
}

# The IPv4 addresses of this machine's interfaces, read from ifconfig where it exists.
sc_local_addresses() {
	command -v ifconfig >/dev/null 2>&1 || return 0
	ifconfig 2>/dev/null | awk '$1 == "inet" { print $2 }'
}

# How to reach a site from this machine: empty = resolve its name as usual, otherwise
# the address to connect to. Set by sc_find_route; call that from the main shell.
SC_ROUTE=

# sc_find_route <url that must return 200> — decides SC_ROUTE.
#
# A development site's public name is not always reachable from the server that hosts
# it (split DNS, a TLS front end elsewhere). If the URL does not answer at all, find out
# which of this machine's own addresses serves it: the first that returns 200, tried in
# the order of SEOCART_DEV_SMOKE_CONNECT_TO or, without it, of the interface list.
sc_find_route() {
	SC_ROUTE=
	if sc_http_status '' "$1" >/dev/null 2>&1; then
		return 0
	fi
	for sc_candidate in ${SEOCART_DEV_SMOKE_CONNECT_TO:-$(sc_local_addresses)}; do
		sc_code=$(sc_http_status "$sc_candidate" "$1" 2>/dev/null) || sc_code=000
		if [ "$sc_code" = 200 ]; then
			SC_ROUTE=$sc_candidate
			sc_info "the public name does not answer from this machine; asking its own web server directly"
			return 0
		fi
	done
	printf 'error: %s did not answer, and no address of this machine returned 200 for it (set SEOCART_DEV_SMOKE_CONNECT_TO=<address> to name one)\n' "$1" >&2
	return 1
}

# sc_smoke_check <base url> — expects 200 from the home page and from wp-login.php, asked
# for over SC_ROUTE: call sc_find_route first.
sc_smoke_check() {
	sc_smoke_status=0
	for sc_path in / /wp-login.php; do
		sc_code=$(sc_http_status "$SC_ROUTE" "$1$sc_path" 2>/dev/null) || sc_code=000
		if [ "$sc_code" = 200 ]; then
			sc_info "smoke check: $1$sc_path -> 200"
		else
			printf 'error: smoke check: %s%s returned HTTP %s, expected 200\n' "$1" "$sc_path" "$sc_code" >&2
			sc_smoke_status=1
		fi
	done
	return "$sc_smoke_status"
}

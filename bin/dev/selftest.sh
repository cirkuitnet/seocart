#!/bin/sh
#
# Self-test of the bin/dev/ scripts: everything that needs neither MySQL, nor WP-CLI, nor
# a web server. Run it after every change to this directory:
#
#     sh bin/dev/selftest.sh
#
# The scripts under test run in a sandbox. The integration site, the state directory, the
# canonical clone and the worktrees root all point into one scratch directory, and the
# WP-CLI executable is `false`, so no credentials can be read. A script that wrongly gets
# past its argument checks therefore fails with exit 1 and touches nothing real — which
# matters, because the checks below feed the scripts deliberately bad arguments.

set -eu

SC_DEV_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)

# The name pattern is the one lib.sh's exit trap is willing to remove.
sandbox=$(umask 077 && mktemp -d "${TMPDIR:-/tmp}/seocart-dev.XXXXXXXX")
SEOCART_DEV_SITE_PATH=$sandbox/site
SEOCART_DEV_STATE_DIR=$sandbox/state
SEOCART_DEV_WP_CORE_DIR=$sandbox/core
SEOCART_DEV_REPO=$sandbox/repo
SEOCART_DEV_WORKTREES_ROOT=$sandbox/worktrees
SEOCART_DEV_WP=false
export SEOCART_DEV_SITE_PATH SEOCART_DEV_STATE_DIR SEOCART_DEV_WP_CORE_DIR SEOCART_DEV_REPO SEOCART_DEV_WORKTREES_ROOT SEOCART_DEV_WP
unset SEOCART_DEV_MYSQL_SOCKET SEOCART_DEV_SMOKE_CONNECT_TO

# shellcheck source=bin/dev/lib.sh
. "$SC_DEV_DIR/lib.sh"
SC_TMPDIR=$sandbox
mkdir -p "$SEOCART_DEV_SITE_PATH" "$SEOCART_DEV_STATE_DIR" "$SEOCART_DEV_WORKTREES_ROOT"

checks=0
failures=0
output=$sandbox/selftest.out

# SC_SELFTEST_CAPTURE, when set, also appends every ok/FAIL line to that file: a section
# that must prove it never prints a secret sets it, then greps the file, then clears it.
SC_SELFTEST_CAPTURE=

passed() {
	checks=$((checks + 1))
	printf 'ok    %s\n' "$1"
	[ -z "$SC_SELFTEST_CAPTURE" ] || printf 'ok    %s\n' "$1" >>"$SC_SELFTEST_CAPTURE"
}

failed() {
	checks=$((checks + 1))
	failures=$((failures + 1))
	printf 'FAIL  %s\n' "$1"
	[ -z "$SC_SELFTEST_CAPTURE" ] || printf 'FAIL  %s\n' "$1" >>"$SC_SELFTEST_CAPTURE"
}

# expect_exit <status> <description> <command> [<argument> ...] — output goes to $output.
expect_exit() {
	expected=$1
	description=$2
	shift 2
	actual=0
	"$@" >"$output" 2>&1 </dev/null || actual=$?
	if [ "$actual" -eq "$expected" ]; then
		passed "$description (exit $actual)"
	else
		failed "$description: exit $actual, expected $expected"
		sed -e 's/^/          /' "$output" | head -n 6
	fi
}

# expect_output <fixed string> <description> — looks in what the last expect_exit caught.
expect_output() {
	if grep -Fq -- "$1" "$output"; then
		passed "$2"
	else
		failed "$2: \"$1\" is not in the output"
		sed -e 's/^/          /' "$output" | head -n 6
	fi
}

# expect_equal <expected> <actual> <description>
expect_equal() {
	if [ "$1" = "$2" ]; then
		passed "$3"
	else
		failed "$3: got \"$2\", expected \"$1\""
	fi
}

# expect_path <path> <description> — checks that a file or directory still exists.
expect_path() {
	if [ -e "$1" ] || [ -L "$1" ]; then
		passed "$2"
	else
		failed "$2: $1 does not exist"
	fi
}

# expect_absent <path> <description> — checks that a file or directory is gone.
expect_absent() {
	if [ ! -e "$1" ] && [ ! -L "$1" ]; then
		passed "$2"
	else
		failed "$2: $1 still exists"
	fi
}

# Runs a function that may call exit without ending this script.
contained() {
	("$@")
}

run_script() {
	name=$1
	shift
	sh "$SC_DEV_DIR/$name.sh" "$@"
}

repeat() {
	awk -v count="$2" -v text="$1" 'BEGIN { while ( count-- > 0 ) printf "%s", text }'
}

slug=zz-selftest
longest_slug=$(repeat a "$SC_SLUG_MAX")
too_long_slug=$(repeat a $((SC_SLUG_MAX + 1)))

printf '== syntax\n'
for file in "$SC_DEV_DIR"/*.sh; do
	expect_exit 0 "sh -n ${file##*/}" sh -n "$file"
done

printf '\n== arguments: usage and exit 2, before anything else happens\n'
for name in check-residue provision-site provision-test-db teardown-site teardown-worktree; do
	expect_exit 2 "$name: no argument" run_script "$name"
	expect_output 'usage:' "$name: no argument prints the usage"
	expect_exit 2 "$name: empty slug" run_script "$name" ''
	expect_exit 2 "$name: empty slug followed by a valid one" run_script "$name" '' "$slug"
	expect_exit 2 "$name: valid slug followed by an empty argument" run_script "$name" "$slug" ''
	expect_exit 2 "$name: second slug" run_script "$name" "$slug" other
	expect_exit 2 "$name: path traversal ../x" run_script "$name" ../x
	expect_exit 2 "$name: upper case" run_script "$name" Upper
	expect_exit 2 "$name: $((SC_SLUG_MAX + 1)) characters" run_script "$name" "$too_long_slug"
	expect_exit 2 "$name: unknown option" run_script "$name" --bogus "$slug"
	expect_exit 0 "$name: --help" run_script "$name" --help
	expect_output 'usage:' "$name: --help prints the usage"
done
expect_exit 2 "new-worktree: no argument" run_script new-worktree
expect_output 'usage:' "new-worktree: no argument prints the usage"
expect_exit 2 "new-worktree: no branch" run_script new-worktree "$slug"
expect_exit 2 "new-worktree: empty slug followed by a valid one" run_script new-worktree '' "$slug" some-branch
expect_exit 2 "new-worktree: empty branch" run_script new-worktree "$slug" '' some-branch
expect_exit 2 "new-worktree: third argument" run_script new-worktree "$slug" some-branch other
expect_exit 2 "new-worktree: path traversal ../x" run_script new-worktree ../x some-branch
expect_exit 2 "new-worktree: unknown option" run_script new-worktree --bogus "$slug" some-branch
expect_exit 0 "new-worktree: --help" run_script new-worktree --help
expect_output 'usage:' "new-worktree: --help prints the usage"

printf '\n== slugs\n'
for good in a 7 abc my-task-1 "$longest_slug"; do
	expect_exit 0 "accepted: \"$good\"" sc_validate_slug "$good"
done
for bad in '' ../x a/b . .. Upper a_b a.b 'a b' -a a- 'a;b' "a\$b" 'a*' "$too_long_slug" "$(repeat a 40)"; do
	expect_exit 1 "rejected: \"$bad\"" sc_validate_slug "$bad"
done

printf '\n== derived names stay inside the limits of MySQL\n'
expect_equal seocart_test_my_task_1 "$(sc_account_name test my-task-1)" "account name of my-task-1"
expect_equal wp_my_task_1_ "$(sc_table_prefix my-task-1)" "table prefix of my-task-1"
for kind in $SC_DB_KINDS; do
	account=$(sc_account_name "$kind" "$longest_slug")
	if [ "${#account}" -le 32 ]; then
		passed "longest $kind account name has ${#account} of 32 characters"
	else
		failed "longest $kind account name has ${#account} characters; MySQL allows 32"
	fi
done
table_prefix=$(sc_table_prefix "$longest_slug")
if [ $((${#table_prefix} + 44)) -le 64 ]; then
	passed "longest table prefix plus a 44-character table name has $((${#table_prefix} + 44)) of 64 characters"
else
	failed "longest table prefix plus a 44-character table name has $((${#table_prefix} + 44)) characters; MySQL allows 64"
fi

printf '\n== database prefix setting\n'
rm -f -- "$SC_CONFIG_FILE"
expect_equal "$SC_DB_PREFIX_DEFAULT" "$(sc_configured_db_prefix)" "no settings file: the default"
printf '# a comment\nOTHER=1\n' >"$SC_CONFIG_FILE"
expect_equal "$SC_DB_PREFIX_DEFAULT" "$(sc_configured_db_prefix)" "settings file without the line: the default"
printf '# a comment\nSEOCART_DEV_DB_PREFIX=acct_seocart_\n' >"$SC_CONFIG_FILE"
expect_equal acct_seocart_ "$(sc_configured_db_prefix)" "the configured value"
longest_prefix=$(repeat p $((64 - 5 - SC_SLUG_MAX)))
printf 'SEOCART_DEV_DB_PREFIX=%s\n' "$longest_prefix" >"$SC_CONFIG_FILE"
expect_exit 0 "longest prefix accepted" sc_configured_db_prefix
SC_DB_PREFIX=$longest_prefix
SC_DB_PREFIX_LOADED=yes
database=$(sc_db_name test "$longest_slug")
if [ "${#database}" -le 64 ]; then
	passed "longest database name has ${#database} of 64 characters"
else
	failed "longest database name has ${#database} characters; MySQL allows 64"
fi
SC_DB_PREFIX=
SC_DB_PREFIX_LOADED=no
printf 'SEOCART_DEV_DB_PREFIX=%sp\n' "$longest_prefix" >"$SC_CONFIG_FILE"
expect_exit 1 "longer prefix rejected" sc_configured_db_prefix
for bad in '' '"quoted_"' 'a b_' 'x_;id' "x_\$(id)" 'dash-'; do
	printf 'SEOCART_DEV_DB_PREFIX=%s\n' "$bad" >"$SC_CONFIG_FILE"
	expect_exit 1 "rejected value: $bad" sc_configured_db_prefix
done
rm -f -- "$SC_CONFIG_FILE"

printf '\n== generated passwords (only their shape is shown)\n'
first=$(sc_password)
second=$(sc_password)
expect_equal 32 "${#first}" "length"
case $first in
	*[!A-Za-z0-9]*)
		failed "letters and digits only"
		;;
	*)
		passed "letters and digits only"
		;;
esac
if [ "$first" != "$second" ]; then
	passed "two passwords differ"
else
	failed "two passwords differ"
fi

printf '\n== disposable-site markers (behavioural: proves no path here prints a key, not just that none is asked to)\n'
key1_errfile=$sandbox/key1.stderr
key2_errfile=$sandbox/key2.stderr
key1=$(sc_encryption_key 2>"$key1_errfile")
key2=$(sc_encryption_key 2>"$key2_errfile")
expect_equal '' "$(cat "$key1_errfile" "$key2_errfile")" "sc_encryption_key: nothing on stderr, on either call"
expect_equal 44 "${#key1}" "encryption key: length (base64 of 32 bytes)"
case $key1 in
	*[!A-Za-z0-9+/=]*)
		failed "encryption key: base64 characters only"
		;;
	*)
		passed "encryption key: base64 characters only"
		;;
esac
if [ "$key1" != "$key2" ]; then
	passed "two encryption keys differ"
else
	failed "two encryption keys differ"
fi

# This capture holds every ok/FAIL line the rest of this section prints (passed()/failed()
# append to it while SC_SELFTEST_CAPTURE is set): the descriptions and, on a failure, the
# "got/expected" text of every assertion below — including one that matches a pattern built
# from key1, so a key that leaked into a message here, not just into a grep pattern, would
# show. Searched afterwards for either raw key; finding one is the failure this proves against.
disposable_capture=$sandbox/disposable-section.out
: >"$disposable_capture"
SC_SELFTEST_CAPTURE=$disposable_capture

disposable_lines=$(sc_wp_config_disposable_lines "$key1")
expect_equal 1 "$(printf '%s\n' "$disposable_lines" | grep -Fc "define( 'WP_ENVIRONMENT_TYPE', 'development' );")" "marks the environment type development, once"
expect_equal 1 "$(printf '%s\n' "$disposable_lines" | grep -Fc "define( 'SEOCART_ENCRYPTION_KEY', '$key1' );")" "defines the generated encryption key, once"
expect_equal 1 "$(printf '%s\n' "$disposable_lines" | grep -Fc "putenv( 'SEOCART_SEED_DISPOSABLE=1' );")" "marks the site disposable for the reference seed, once"
expect_equal 3 "$(printf '%s\n' "$disposable_lines" | grep -c .)" "exactly the three lines, nothing else"

SC_SELFTEST_CAPTURE=
if grep -F -e "$key1" -e "$key2" "$disposable_capture" >/dev/null 2>&1; then
	failed "this section's own reported output never contains a generated key"
else
	passed "this section's own reported output never contains a generated key"
fi

# A static guard for the one path a sandboxed self-test cannot exercise: provision-site.sh
# needs a real WP-CLI and MySQL to run write_wp_config(), so nothing above ever calls it.
# Anything that could put the key on the operator's screen — a message helper, or a bare
# printf or echo — is refused wherever it also names the key's variable.
if grep -nE 'sc_(info|warn|die)|printf|echo' "$SC_DEV_DIR/provision-site.sh" | grep -q 'encryption_key'; then
	failed "provision-site.sh: the encryption key variable is never passed to sc_info, sc_warn, sc_die, printf or echo"
else
	passed "provision-site.sh: the encryption key variable is never passed to sc_info, sc_warn, sc_die, printf or echo"
fi

printf '\n== template rendering\n'
template=$sandbox/template.php
rendered=$sandbox/checkout/rendered.php
mkdir -p "$sandbox/checkout"
cat >"$template" <<'EOF'
<?php
require_once __DIR__ . '/x.php';
define( 'DB_NAME', '__DB_NAME__' );
define( 'DB_PASSWORD', '__DB_PASSWORD__' );
EOF
expect_exit 0 "fills the tokens" contained sc_render_template "$template" "$rendered" DB_NAME=name_1 "DB_PASSWORD=$first"
expect_equal "define( 'DB_NAME', 'name_1' );" "$(grep DB_NAME "$rendered")" "token replaced"
expect_equal 1 "$(grep -c __DIR__ "$rendered")" "PHP's __DIR__ is not taken for a token"
# The generated filename is fixed and contains no characters that make ls ambiguous.
# shellcheck disable=SC2012
expect_equal -rw------- "$(ls -l "$rendered" | cut -c1-10)" "mode 600"
rm -f -- "$rendered"
expect_exit 1 "refuses a template with a token it cannot fill" contained sc_render_template "$template" "$rendered" DB_NAME=name_1
expect_output __DB_PASSWORD__ "names the unfilled token"
expect_exit 1 "refuses a value with a quote" contained sc_render_template "$template" "$rendered" "DB_NAME=a'b" DB_PASSWORD=x
expect_equal '' "$(ls "$sandbox/checkout")" "a refusal leaves no file in the checkout"
expect_equal '' "$(find "$sandbox" -name 'render*' -prune -print)" "and none in the scratch directory"

printf '\n== guarded removal of an instance directory\n'
mkdir -p "$SC_INSTANCES_ROOT/zz-real/wp-content" "$sandbox/elsewhere/victim"
ln -s "$sandbox/elsewhere" "$SC_INSTANCES_ROOT/zz-link"
expect_exit 0 "a symlink in place of the directory" contained sc_remove_site_dir zz-link
expect_equal victim "$(ls "$sandbox/elsewhere")" "the link is gone, its target is untouched"
expect_exit 0 "a real instance directory" contained sc_remove_site_dir zz-real
expect_equal '' "$(ls "$SC_INSTANCES_ROOT")" "is removed"
expect_exit 0 "an absent instance directory" contained sc_remove_site_dir zz-absent
expect_exit 1 "path traversal ../elsewhere" contained sc_remove_site_dir ../elsewhere
expect_exit 1 "empty slug" contained sc_remove_site_dir ''
expect_equal victim "$(ls "$sandbox/elsewhere")" "the directory outside the instances root is still there"
saved_root=$SC_INSTANCES_ROOT
SC_INSTANCES_ROOT=
expect_exit 1 "an unset instances root" contained sc_remove_site_dir "$slug"
SC_INSTANCES_ROOT=$saved_root

printf '\n== residue gate and teardown, as far as they go without MySQL\n'
account=$(sc_account_name test "$slug")
worktree_config=$(sc_tests_config "$SEOCART_DEV_WORKTREES_ROOT/$slug")
other_config=$(sc_tests_config "$sandbox/other-checkout")
neighbour_config=$(sc_tests_config "$SEOCART_DEV_WORKTREES_ROOT/neighbour")

expect_exit 3 "clean, but the MySQL checks cannot run: not a pass" run_script check-residue "$slug"
expect_output UNVERIFIED "says what it could not check"
expect_exit 0 "clean, --allow-unverified" run_script check-residue "$slug" --allow-unverified

plant() {
	mkdir -p "$(dirname -- "$1")"
	printf '%s\n' "${2-}" >"$1"
}

mkdir -p "$(sc_site_dir "$slug")"
expect_exit 1 "planted: the instance directory" run_script check-residue "$slug" --allow-unverified
expect_output "$(sc_site_dir "$slug")" "names it"
rmdir "$(sc_site_dir "$slug")"

sc_state_files "$slug" >"$sandbox/state-files"
while IFS= read -r file; do
	plant "$file"
	expect_exit 1 "planted: ${file#"$sandbox"/}" run_script check-residue "$slug" --allow-unverified
	expect_output "$file" "names it"
	rm -f -- "$file"
done <"$sandbox/state-files"

plant "$worktree_config" "define( 'DB_USER', '$account' );"
expect_exit 1 "planted: a test configuration in the slug's worktree" run_script check-residue "$slug" --allow-unverified
rm -f -- "$worktree_config"
plant "$other_config" "define( 'DB_USER', '$account' );"
expect_exit 1 "planted: a test configuration in --checkout" run_script check-residue "$slug" --allow-unverified --checkout="$sandbox/other-checkout"
rm -f -- "$other_config"
plant "$neighbour_config" "define( 'DB_USER', '${account}_b' );"
plant "$worktree_config" "define( 'DB_USER', '${account}_b' );"
expect_exit 0 "the configuration of a longer-named neighbour is not this slug's residue" run_script check-residue "$slug" --allow-unverified
rm -f -- "$worktree_config"
expect_exit 0 "clean again" run_script check-residue "$slug" --allow-unverified

mkdir -p "$(sc_site_dir "$slug")/wp-content/plugins" "$sandbox/linked-checkout"
ln -s "$sandbox/linked-checkout" "$(sc_plugin_link "$slug")"
plant "$(sc_tests_config "$sandbox/linked-checkout")" "define( 'DB_USER', '$account' );"
plant "$worktree_config" "define( 'DB_USER', '$account' );"
while IFS= read -r file; do
	plant "$file"
done <"$sandbox/state-files"
expect_exit 1 "everything planted at once" run_script check-residue "$slug" --allow-unverified
expect_exit 1 "teardown-site: the MySQL steps cannot run, so it reports failure" run_script teardown-site "$slug"
expect_exit 0 "but every file it is responsible for is gone" run_script check-residue "$slug" --allow-unverified
expect_equal '' "$(ls "$sandbox/linked-checkout/tests")" "including the configuration in the instance's own checkout"
expect_equal 1 "$(grep -c "${account}_b" "$neighbour_config")" "the neighbour's configuration is untouched"

printf '\n== teardown-worktree protects uncommitted work before teardown\n'
# The real teardown needs MySQL, so a stub records whether the worktree guard allowed it
# to start while a fake Git exposes the exact registry and status responses under test.
teardown_harness=$sandbox/teardown-worktree-harness
fake_bin=$teardown_harness/fake-bin
git_status=$teardown_harness/status
git_log=$teardown_harness/git.log
teardown_log=$teardown_harness/teardown.log
mkdir -p "$fake_bin" "$SEOCART_DEV_REPO/.git"
cp "$SC_DEV_DIR/teardown-worktree.sh" "$SC_DEV_DIR/lib.sh" "$teardown_harness/"
cat >"$teardown_harness/teardown-site.sh" <<'EOF'
#!/bin/sh
set -eu
printf '%s\n' "$1" >"$SEOCART_SELFTEST_TEARDOWN_LOG"
EOF
cat >"$fake_bin/git" <<'EOF'
#!/bin/sh
set -eu

directory=
if [ "${1-}" = -C ]; then
	directory=$2
	shift 2
fi

case ${1-}:${2-} in
	worktree:list)
		if [ -d "$SEOCART_SELFTEST_GIT_DIRECTORY" ]; then
			printf 'worktree %s\n\n' "$SEOCART_SELFTEST_GIT_WORKTREE"
		fi
		;;
	status:--porcelain)
		[ "$directory" = "$SEOCART_SELFTEST_GIT_WORKTREE" ]
		[ "${3-}" = --untracked-files=all ]
		cat "$SEOCART_SELFTEST_GIT_STATUS"
		;;
	rev-parse:--abbrev-ref)
		[ "$directory" = "$SEOCART_SELFTEST_GIT_WORKTREE" ]
		printf 'selftest-branch\n'
		;;
	worktree:remove)
		shift 2
		force=no
		if [ "${1-}" = --force ]; then
			force=yes
			shift
		fi
		[ "$1" = "$SEOCART_SELFTEST_GIT_WORKTREE" ]
		printf 'remove force=%s %s\n' "$force" "$1" >>"$SEOCART_SELFTEST_GIT_LOG"
		rm -f -- "$SEOCART_SELFTEST_GIT_DIRECTORY/dirty.txt"
		rmdir "$SEOCART_SELFTEST_GIT_DIRECTORY/tests" 2>/dev/null || true
		rmdir "$SEOCART_SELFTEST_GIT_DIRECTORY"
		;;
	worktree:prune)
		;;
	branch:-d)
		;;
	*)
		exit 1
		;;
esac
EOF
chmod +x "$fake_bin/git"

worktree_fixture=$SEOCART_DEV_WORKTREES_ROOT/$slug
SEOCART_SELFTEST_GIT_WORKTREE=$(sc_physical_dir "$SEOCART_DEV_WORKTREES_ROOT")/$slug
SEOCART_SELFTEST_GIT_DIRECTORY=$worktree_fixture
SEOCART_SELFTEST_GIT_STATUS=$git_status
SEOCART_SELFTEST_GIT_LOG=$git_log
SEOCART_SELFTEST_TEARDOWN_LOG=$teardown_log
export SEOCART_SELFTEST_GIT_WORKTREE SEOCART_SELFTEST_GIT_DIRECTORY SEOCART_SELFTEST_GIT_STATUS SEOCART_SELFTEST_GIT_LOG SEOCART_SELFTEST_TEARDOWN_LOG
saved_path=$PATH
PATH=$fake_bin:$PATH
export PATH

mkdir -p "$worktree_fixture"
plant "$worktree_fixture/dirty.txt" dirty
printf '?? dirty.txt\n' >"$git_status"
expect_exit 1 "teardown-worktree: refuses a dirty worktree" sh "$teardown_harness/teardown-worktree.sh" "$slug"
expect_output '?? dirty.txt' "teardown-worktree: prints the dirty status"
expect_output 'nothing was removed' "teardown-worktree: explains the refusal"
expect_path "$worktree_fixture/dirty.txt" "teardown-worktree: the dirty worktree is untouched"
expect_absent "$teardown_log" "teardown-worktree: site teardown did not start"

rm -f -- "$worktree_fixture/dirty.txt"
: >"$git_status"
: >"$git_log"
expect_exit 0 "teardown-worktree: accepts a clean worktree" sh "$teardown_harness/teardown-worktree.sh" "$slug"
expect_absent "$worktree_fixture" "teardown-worktree: removes the clean worktree"
expect_equal "remove force=no $SEOCART_SELFTEST_GIT_WORKTREE" "$(cat "$git_log")" "teardown-worktree: clean removal does not force"

rm -f -- "$teardown_log"
mkdir -p "$worktree_fixture"
plant "$worktree_fixture/dirty.txt" dirty
printf '?? dirty.txt\n' >"$git_status"
: >"$git_log"
expect_exit 0 "teardown-worktree: accepts a dirty worktree with --discard-changes" sh "$teardown_harness/teardown-worktree.sh" "$slug" --discard-changes
expect_absent "$worktree_fixture" "teardown-worktree: removes the discarded worktree"
expect_equal "remove force=yes $SEOCART_SELFTEST_GIT_WORKTREE" "$(cat "$git_log")" "teardown-worktree: discard removal is forced"

PATH=$saved_path
export PATH
unset SEOCART_SELFTEST_GIT_WORKTREE SEOCART_SELFTEST_GIT_DIRECTORY SEOCART_SELFTEST_GIT_STATUS SEOCART_SELFTEST_GIT_LOG SEOCART_SELFTEST_TEARDOWN_LOG

printf '\n%s checks, %s failed\n' "$checks" "$failures"
[ "$failures" -eq 0 ]

#!/bin/sh
#
# Runs one gate of a SEOCart extension with SEOCart's own tools.
#
# Usage: sh bin/ci/extension.sh <gate> <extension root> [<argument>]
#
# An extension is a plugin of its own, in a repository of its own, checked out beside
# SEOCart: ../seocart from the extension on a developer machine, seocart/ beside ext/ in CI.
# It installs no development dependency: PHPUnit, PHP_CodeSniffer, PHPStan and the PHP
# linter come from SEOCart's vendor/ (`composer install` in SEOCart). The extension's CI
# (.github/workflows/extension-ci.yml) runs these gates through this script, and so does a
# developer, from the extension's root:
#
#     sh ../seocart/bin/ci/extension.sh all .
#
# Gates:
#
#   lint                PHP syntax of every PHP file of the extension.
#   cs                  PHP_CodeSniffer, with the extension's phpcs.xml.dist, which includes
#                       SEOCart's tools/phpcs/seocart-project.xml.
#   stan                PHPStan, with the extension's phpstan.neon.dist, which includes
#                       SEOCart's tools/phpstan/extension.neon.
#   unit                The extension's unit suite.
#   references          The extension's private-reference test on its own.
#   integration         The extension's integration suite, on SEOCart's integration bootstrap
#                       with the extension and SEOCart loaded. Needs SEOCart's test database
#                       (tests/wp-tests-config.local.php in SEOCart).
#   idle                SEOCart's idle-request probe with the extension loaded: the
#                       extension's share of an idle request is at most one hook, one file
#                       and no query. Needs the test database too.
#   conformance <type>  SEOCart's conformance suite for the extension's type, once SEOCart has
#                       one; until then it says so and passes. Needs the test database too.
#   zip                 Builds and checks the extension's release zip (bin/build-zip.php and
#                       bin/check-zip.php with --plugin).
#   version <tag>       The main file's Version and readme.txt's Stable tag equal the tag.
#   all                 lint, cs, stan, unit, references and zip: every gate that needs no
#                       database.
#
# seocart-core.env in the extension pins the SEOCart commit it is built against. When this
# checkout of SEOCart is at another commit, the script says so and carries on: CI checks out
# the pinned commit (or SEOCart's main branch, for the nightly run) itself.
#
# Exit codes: 0 passed, 1 failed, 2 usage error. POSIX sh: runs on the Ubuntu runner and on a
# developer machine alike.

set -eu

fail() {
	printf 'extension: %s\n' "$*" >&2
	exit 1
}

usage() {
	printf 'usage: extension.sh lint|cs|stan|unit|references|integration|idle|zip|all <extension root>\n' >&2
	printf '       extension.sh conformance <extension root> <type>\n' >&2
	printf '       extension.sh version <extension root> <tag>\n' >&2
	exit 2
}

# Prints a command, then runs it.
run() {
	printf '\nextension: %s\n' "$*"
	"$@"
}

[ "$#" -ge 2 ] || usage

gate=$1
core=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
root=$(CDPATH='' cd -- "$2" 2>/dev/null && pwd) || fail "$2 is not a directory."
argument=${3:-}

case $gate in
	conformance | version)
		[ "$#" -eq 3 ] || usage
		;;
	lint | cs | stan | unit | references | integration | idle | zip | all)
		[ "$#" -eq 2 ] || usage
		;;
	*)
		usage
		;;
esac

# Every gate but zip runs on SEOCart's development install; the zip scripts need PHP alone.
if [ "$gate" != zip ] && [ ! -f "$core/vendor/autoload.php" ]; then
	fail "run \`composer install\` in $core first: the extension's gates run on SEOCart's development install."
fi
[ -f "$root/seocart-core.env" ] || fail "$root holds no seocart-core.env, so it is not a SEOCart extension's checkout."

# seocart-core.env is data, never a script: the one line SEOCART_CORE_REF=<40 hex>.
pin=$(sed -n 's/^SEOCART_CORE_REF=\([0-9a-f]\{40\}\)$/\1/p' "$root/seocart-core.env")
[ -n "$pin" ] || fail "$root/seocart-core.env must hold the line SEOCART_CORE_REF=<40 hexadecimal digits>."
head=$(git -C "$core" rev-parse HEAD 2>/dev/null) || head=unknown
if [ "$head" != "$pin" ]; then
	printf 'extension: note: SEOCart here is at %s, but the extension pins %s. Check out the pin for a faithful run: git -C %s checkout %s\n' "$head" "$pin" "$core" "$pin" >&2
fi

phpunit=$core/vendor/bin/phpunit

# SEOCart's integration bootstrap loads the extension this names (tests/bootstrap-integration.php).
SEOCART_EXTENSION_PATH=$root
export SEOCART_EXTENSION_PATH

gate_lint() {
	run php "$core/vendor/bin/parallel-lint" --exclude "$root/.git" --exclude "$root/dist" --exclude "$root/vendor" --exclude "$root/node_modules" "$root"
}

gate_cs() {
	(cd "$root" && run php "$core/vendor/bin/phpcs")
}

gate_stan() {
	(cd "$root" && run php "$core/vendor/bin/phpstan" analyse --memory-limit=1536M)
}

gate_unit() {
	(cd "$root" && run php "$phpunit" --testsuite unit --fail-on-empty-test-suite)
}

gate_references() {
	(cd "$root" && run php "$phpunit" --testsuite unit --filter PrivateReferencesTest --fail-on-empty-test-suite)
}

# The extension's own integration tests, on SEOCart's bootstrap: it loads the extension that
# SEOCART_EXTENSION_PATH names, before SEOCart, as WordPress does.
gate_integration() {
	(cd "$root" && run php "$phpunit" --testsuite integration --bootstrap "$core/tests/bootstrap-integration.php" --fail-on-empty-test-suite)
}

gate_idle() {
	(cd "$core" && run php "$phpunit" --testsuite integration --bootstrap tests/bootstrap-integration.php --group extension-idle --fail-on-empty-test-suite)
}

# The type decides the suite (tools/Extension/ExtensionType.php). A suite named there but
# missing from SEOCart's composer.json fails: a renamed script must not turn into a skip.
gate_conformance() {
	script=$(php "$core/tools/extension.php" conformance-script "$argument") || exit "$?"
	if [ -z "$script" ]; then
		message="SEOCart at $head has no conformance suite for $argument extensions yet, so there is nothing to run. This step runs it once SEOCart names one (tools/Extension/ExtensionType.php)."
		if [ "${GITHUB_ACTIONS:-}" = true ]; then
			printf '::notice title=Conformance suite::%s\n' "$message"
		fi
		printf 'extension: %s\n' "$message"
		return 0
	fi
	# The PHP source is a literal: nothing in it is meant to expand in the shell.
	# shellcheck disable=SC2016
	php -r '$c = json_decode( (string) file_get_contents( $argv[1] ), true ); exit( isset( $c["scripts"][ $argv[2] ] ) ? 0 : 1 );' "$core/composer.json" "$script" ||
		fail "the $argument conformance suite is the Composer script $script, which $core/composer.json does not define."
	(cd "$core" && run composer "$script")
}

# dist/ is build output; earlier zips are removed so that exactly the one just built is checked.
gate_zip() {
	rm -f "$root"/dist/*.zip "$root/dist/SHA256SUMS"
	run php "$core/bin/build-zip.php" --plugin="$root"
	set -- "$root"/dist/*.zip
	if [ "$#" -ne 1 ] || [ ! -f "$1" ]; then
		fail "bin/build-zip.php left no single zip in $root/dist."
	fi
	run php "$core/bin/check-zip.php" --plugin="$root" "$1"
}

gate_version() {
	run php "$core/tools/extension.php" check-version "$root" "$argument"
}

case $gate in
	all)
		for each in lint cs stan unit references zip; do
			"gate_$each"
		done
		printf '\nextension: every gate that needs no database passed for %s.\n' "$root"
		;;
	*)
		"gate_$gate"
		;;
esac

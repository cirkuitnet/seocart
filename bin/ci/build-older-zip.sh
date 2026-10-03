#!/bin/sh
#
# Builds the release zip of the earlier commit that the upgrade proof starts from.
#
# Usage: sh bin/ci/build-older-zip.sh <directory>
#
#   <directory>  Where seocart-<version>.zip and SHA256SUMS go. Created when missing.
#
# bin/ci/upgrade-from.env names the commit (read as data, never run). The commit is checked out into a temporary
# worktree of this repository, built there the way a release is built (composer install,
# npm ci, npm run build, php bin/build-zip.php, all from that commit's own files), and the
# worktree is removed again, whatever happens. The current checkout is not touched.
#
# Needs git with the commit in its history (a shallow clone does not have it), PHP,
# Composer, Node.js and npm. The runner and a developer machine alike: POSIX sh.

set -eu

fail() {
	printf 'build-older-zip: %s\n' "$*" >&2
	exit 1
}

[ "$#" -eq 1 ] || fail 'usage: build-older-zip.sh <directory>'

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
root=$(CDPATH='' cd -- "$script_dir/../.." && pwd)
pin=$script_dir/upgrade-from.env

[ -f "$pin" ] || fail "$pin does not exist."

# The pin is data, never code: it is read, not run, so a value in it can only be a commit.
# Comment and blank lines aside it holds exactly one line,
# SEOCART_UPGRADE_FROM=<40 lower-case hexadecimal digits>.
assignment=$(grep -v -e '^[[:space:]]*#' -e '^[[:space:]]*$' "$pin" || true)

case $assignment in
	*'
'*) fail "$pin must hold exactly one SEOCART_UPGRADE_FROM line." ;;
esac

printf '%s\n' "$assignment" | grep -Eqx 'SEOCART_UPGRADE_FROM=[0-9a-f]{40}' ||
	fail "$pin must hold one line, SEOCART_UPGRADE_FROM=<40 lower-case hexadecimal digits>, and nothing else but comments."

upgrade_from=${assignment#SEOCART_UPGRADE_FROM=}

for tool in git php composer npm; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required but was not found on PATH."
done

git -C "$root" cat-file -e "$upgrade_from^{commit}" 2>/dev/null ||
	fail "the commit $upgrade_from is not in this clone's history; fetch the full history."

# The upgrade starts from a commit this checkout descends from, so it is an earlier state of
# this very project and never a commit of some other branch.
git -C "$root" merge-base --is-ancestor "$upgrade_from" HEAD ||
	fail "the commit $upgrade_from is not an ancestor of HEAD."

mkdir -p "$1"
out=$(CDPATH='' cd -- "$1" && pwd)

tree=$(mktemp -d "${TMPDIR:-/tmp}/seocart-older-build.XXXXXX")

cleanup() {
	status=$?
	trap - EXIT INT TERM
	git -C "$root" worktree remove --force "$tree" >/dev/null 2>&1 || rm -rf "$tree"
	git -C "$root" worktree prune
	exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

printf 'build-older-zip: building %s\n' "$upgrade_from"
git -C "$root" worktree add --detach --quiet "$tree" "$upgrade_from"

(
	cd "$tree"
	composer install --no-interaction --no-progress --prefer-dist
	npm ci
	npm run build
	php bin/build-zip.php
)

cp "$tree"/dist/seocart-*.zip "$tree/dist/SHA256SUMS" "$out/"

printf 'build-older-zip: wrote the zip and SHA256SUMS to %s\n' "$out"

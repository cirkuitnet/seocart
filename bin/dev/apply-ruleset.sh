#!/bin/sh
#
# Applies tools/github/extension-ruleset.json to a SEOCart extension's repository on GitHub
# with the GitHub CLI, then reads the ruleset back and prints it. See README.md in this
# directory and docs/writing-an-extension.md.

set -eu

usage() {
	cat >&2 <<EOF
usage: apply-ruleset.sh <owner/repo> [--dry-run]

Protects the default branch of a SEOCart extension's repository the way SEOCart's own is
protected: no deletion, no force-push, linear history, and the extension CI's checks
required with the strict policy (tools/github/extension-ruleset.json). A ruleset of the same
name is replaced, any other is left alone. Needs the GitHub CLI, signed in with admin rights
on the repository.

  --dry-run   print the requests it would send, and send none

Exit codes: 0 done, 1 failed, 2 usage error.
EOF
	exit 2
}

fail() {
	printf 'apply-ruleset: %s\n' "$*" >&2
	exit 1
}

repository=
dry_run=no

for argument in "$@"; do
	case $argument in
		--dry-run)
			dry_run=yes
			;;
		-h | --help | -*)
			usage
			;;
		*)
			[ -z "$repository" ] || usage
			repository=$argument
			;;
	esac
done

[ -n "$repository" ] || usage

case $repository in
	*/*/* | /* | */)
		usage
		;;
	*/*) ;;
	*)
		usage
		;;
esac

# Owner and repository names: letters, digits, `-`, `_` and `.` only.
if printf '%s\n' "$repository" | grep -q '[^A-Za-z0-9._/-]'; then
	usage
fi

core=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
ruleset=$core/tools/github/extension-ruleset.json

# The PHP source is a literal: nothing in it is meant to expand in the shell.
# shellcheck disable=SC2016
name=$(php -r 'echo json_decode( (string) file_get_contents( $argv[1] ), false, 512, JSON_THROW_ON_ERROR )->name;' "$ruleset") ||
	fail "$ruleset is not valid JSON with a name."

if [ "$dry_run" = yes ]; then
	printf 'apply-ruleset: dry run; nothing is sent.\n'
	printf 'apply-ruleset: would send: gh api repos/%s/rulesets (GET, to find a ruleset named "%s")\n' "$repository" "$name"
	printf 'apply-ruleset: would send: gh api --method PUT repos/%s/rulesets/<its id> --input %s, or, when there is none,\n' "$repository" "$ruleset"
	printf 'apply-ruleset: would send: gh api --method POST repos/%s/rulesets --input %s\n' "$repository" "$ruleset"
	printf 'apply-ruleset: would send: gh api repos/%s/rulesets/<its id> (GET, printed)\n' "$repository"
	printf 'apply-ruleset: the body:\n'
	cat "$ruleset"
	exit 0
fi

command -v gh >/dev/null 2>&1 || fail 'the GitHub CLI (gh) is required but was not found on PATH.'

id=$(gh api "repos/$repository/rulesets" --jq ".[] | select(.name == \"$name\") | .id") ||
	fail "could not list the rulesets of $repository."

if [ -n "$id" ]; then
	gh api --method PUT "repos/$repository/rulesets/$id" --input "$ruleset" >/dev/null ||
		fail "could not replace ruleset $id of $repository."
	printf 'apply-ruleset: replaced ruleset "%s" (%s) of %s.\n' "$name" "$id" "$repository"
else
	id=$(gh api --method POST "repos/$repository/rulesets" --input "$ruleset" --jq '.id') ||
		fail "could not create the ruleset of $repository."
	printf 'apply-ruleset: created ruleset "%s" (%s) of %s.\n' "$name" "$id" "$repository"
fi

printf 'apply-ruleset: as GitHub now has it:\n'
gh api "repos/$repository/rulesets/$id"

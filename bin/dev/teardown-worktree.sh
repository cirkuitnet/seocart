#!/bin/sh
#
# Removes a task's disposable instance, databases and git worktree. See README.md in
# this directory.

set -eu

SC_DEV_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
# shellcheck source=bin/dev/lib.sh
. "$SC_DEV_DIR/lib.sh"

usage() {
	cat <<EOF
usage: teardown-worktree.sh <slug>

Runs teardown-site.sh <slug>, then removes the git worktree
<worktrees root>/<slug> from the canonical clone. It uses --force, so uncommitted
changes in it are lost. The worktree's branch is deleted only if git considers it
merged (git branch -d); otherwise it is kept and named.

Partly provisioned state is expected, not an error. Confirm the result with
check-residue.sh.

  <slug>   lower-case a-z, 0-9 and "-", at most $SC_SLUG_MAX characters

Exit codes: 0 everything is gone, 1 something could not be removed, 2 usage error or
invalid slug.
EOF
}

slug=

for argument in "$@"; do
	case $argument in
		-h | --help)
			usage
			exit 0
			;;
		-*)
			sc_usage_error "unknown option: $argument"
			;;
		*)
			[ -n "$argument" ] || sc_usage_error "empty argument"
			[ -z "$slug" ] || sc_usage_error "unexpected argument: $argument"
			slug=$argument
			;;
	esac
done

[ -n "$slug" ] || sc_usage_error "missing <slug>"
sc_validate_slug "$slug" || exit 2
sc_require_commands git

status=0
worktree=$SEOCART_DEV_WORKTREES_ROOT/$slug

# Through sh, as everywhere in this directory: no script depends on its executable bit.
sh "$SC_DEV_DIR/teardown-site.sh" "$slug" || status=1

branch=
if [ -d "$worktree" ]; then
	branch=$(git -C "$worktree" rev-parse --abbrev-ref HEAD 2>/dev/null) || branch=
fi

# This script may have been started from inside the worktree it removes.
cd "$SEOCART_DEV_REPO" || sc_die "canonical clone not found at $SEOCART_DEV_REPO (SEOCART_DEV_REPO)"

if [ -e "$worktree" ]; then
	# git removes registered worktrees only, and never the main working tree.
	if git worktree remove --force "$worktree"; then
		sc_info "removed worktree $worktree"
	else
		sc_warn "git could not remove $worktree; if it is not a worktree of $SEOCART_DEV_REPO, look at it and remove it by hand"
		status=1
	fi
fi
git worktree prune

if [ -n "$branch" ] && [ "$branch" != HEAD ]; then
	if git branch -d "$branch" 2>/dev/null; then
		sc_info "deleted merged branch $branch"
	else
		sc_info "kept branch $branch (not merged, or checked out elsewhere)"
	fi
fi

if [ "$status" -eq 0 ]; then
	sc_info "Worktree \"$slug\" is gone. Confirm with: sh $SC_DEV_DIR/check-residue.sh $slug"
else
	sc_warn "teardown of worktree \"$slug\" is incomplete; see the messages above"
fi
exit "$status"

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
usage: teardown-worktree.sh <slug> [--discard-changes]

Runs teardown-site.sh <slug>, then removes the git worktree
<worktrees root>/<slug> from the canonical clone. It refuses a worktree with staged,
unstaged or untracked changes before removing anything. The worktree's branch is
deleted only if git considers it merged (git branch -d); otherwise it is kept and
named.

Partly provisioned state is expected, not an error. Confirm the result with
check-residue.sh.

  <slug>              lower-case a-z, 0-9 and "-", at most $SC_SLUG_MAX characters
  --discard-changes   remove a dirty worktree and lose its uncommitted changes

Exit codes: 0 everything is gone, 1 something could not be removed, 2 usage error or
invalid slug.
EOF
}

slug=
discard_changes=no

for argument in "$@"; do
	case $argument in
		-h | --help)
			usage
			exit 0
			;;
		--discard-changes)
			discard_changes=yes
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
worktree_path=$SEOCART_DEV_WORKTREES_ROOT/$slug
if [ -d "$SEOCART_DEV_WORKTREES_ROOT" ]; then
	worktrees_root=$(sc_physical_dir "$SEOCART_DEV_WORKTREES_ROOT") || sc_die "could not resolve $SEOCART_DEV_WORKTREES_ROOT"
	worktree_path=$worktrees_root/$slug
fi

# Git's registry is authoritative because a directory at the conventional path might be
# unrelated and must never be treated as this task's worktree.
worktree_list=$(git -C "$SEOCART_DEV_REPO" worktree list --porcelain) || sc_die "could not read the worktree registry of $SEOCART_DEV_REPO; nothing was removed"
worktree=$(printf '%s\n' "$worktree_list" | awk -v target="$worktree_path" '
	substr( $0, 1, 9 ) == "worktree " && substr( $0, 10 ) == target {
		print target
		matches++
	}
	END {
		exit matches > 1
	}
') || sc_die "could not resolve the registered worktree for $slug; nothing was removed"

if [ -z "$worktree" ] && { [ -e "$worktree_path" ] || [ -L "$worktree_path" ]; }; then
	sc_die "$worktree_path exists but is not a registered worktree of $SEOCART_DEV_REPO; nothing was removed"
fi

branch=
if [ -n "$worktree" ]; then
	changes=$(git -C "$worktree" status --porcelain --untracked-files=all) || sc_die "could not inspect $worktree; nothing was removed"
	if [ -n "$changes" ] && [ "$discard_changes" = no ]; then
		sc_warn "$worktree has uncommitted changes (showing at most 20 lines):"
		printf '%s\n' "$changes" | sed -n '1,20p' >&2
		sc_warn "nothing was removed; commit or stash the changes, or pass --discard-changes to lose them"
		exit 1
	fi
	branch=$(git -C "$worktree" rev-parse --abbrev-ref HEAD 2>/dev/null) || branch=
fi

# Through sh, as everywhere in this directory: no script depends on its executable bit.
sh "$SC_DEV_DIR/teardown-site.sh" "$slug" || status=1

# This script may have been started from inside the worktree it removes.
cd "$SEOCART_DEV_REPO" || sc_die "canonical clone not found at $SEOCART_DEV_REPO (SEOCART_DEV_REPO)"

if [ -n "$worktree" ]; then
	# git removes registered worktrees only, and never the main working tree.
	if [ "$discard_changes" = yes ]; then
		remove_status=0
		git worktree remove --force "$worktree" || remove_status=$?
	else
		remove_status=0
		git worktree remove "$worktree" || remove_status=$?
	fi
	if [ "$remove_status" -eq 0 ]; then
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

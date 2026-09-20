#!/bin/sh
#
# Creates a task's git worktree, installs its dependencies and provisions its test
# database and disposable instance. See README.md in this directory.

set -eu

SC_DEV_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
# shellcheck source=bin/dev/lib.sh
. "$SC_DEV_DIR/lib.sh"

usage() {
	cat <<EOF
usage: new-worktree.sh <slug> <branch> [--with-woocommerce] [--with-polylang]

  1. git worktree add <worktrees root>/<slug>, on <branch> (created from the
     canonical clone's HEAD if it does not exist yet)
  2. composer install and npm ci in the worktree
  3. provision-test-db.sh and provision-site.sh for the worktree
  4. prints the instance URL and the database names

  <slug>     lower-case a-z, 0-9 and "-", at most $SC_SLUG_MAX characters
  <branch>   a git branch name, for example feature/<slug>
  --with-woocommerce, --with-polylang   passed on to provision-site.sh

Undo with teardown-worktree.sh <slug>.

Exit codes: 0 done, 1 failed, 2 usage error or invalid slug.
EOF
}

slug=
branch=
site_options=

for argument in "$@"; do
	case $argument in
		-h | --help)
			usage
			exit 0
			;;
		--with-woocommerce | --with-polylang)
			site_options="$site_options $argument"
			;;
		-*)
			sc_usage_error "unknown option: $argument"
			;;
		*)
			[ -n "$argument" ] || sc_usage_error "empty argument"
			if [ -z "$slug" ]; then
				slug=$argument
			elif [ -z "$branch" ]; then
				branch=$argument
			else
				sc_usage_error "unexpected argument: $argument"
			fi
			;;
	esac
done

[ -n "$slug" ] || sc_usage_error "missing <slug>"
[ -n "$branch" ] || sc_usage_error "missing <branch>"
sc_validate_slug "$slug" || exit 2
sc_require_commands git composer npm
git check-ref-format --branch "$branch" >/dev/null 2>&1 || sc_usage_error "not a valid branch name: $branch"
[ -d "$SEOCART_DEV_REPO/.git" ] || sc_die "canonical clone not found at $SEOCART_DEV_REPO (SEOCART_DEV_REPO)"

worktree=$SEOCART_DEV_WORKTREES_ROOT/$slug
if [ -e "$worktree" ]; then
	sc_die "$worktree exists already; run sh $SC_DEV_DIR/teardown-worktree.sh $slug first"
fi

SC_FAILURE_HINT="new-worktree.sh did not finish. Remove what it created with: sh $SC_DEV_DIR/teardown-worktree.sh $slug"

mkdir -p "$SEOCART_DEV_WORKTREES_ROOT"
if git -C "$SEOCART_DEV_REPO" show-ref --verify --quiet "refs/heads/$branch"; then
	git -C "$SEOCART_DEV_REPO" worktree add "$worktree" "$branch"
else
	git -C "$SEOCART_DEV_REPO" worktree add "$worktree" -b "$branch"
fi

(cd "$worktree" && composer install --no-interaction && npm ci)

# The scripts next to this one, not the worktree's copies: the version that was started
# is the version that runs to the end. Through sh: no script depends on its executable bit.
sh "$SC_DEV_DIR/provision-test-db.sh" "$slug" --checkout="$worktree"
# $site_options holds only the two literal flags accepted above; it is split on purpose.
# shellcheck disable=SC2086
sh "$SC_DEV_DIR/provision-site.sh" "$slug" --checkout="$worktree" $site_options

SC_FAILURE_HINT=
sc_load_db_prefix || exit 1
sc_info ""
sc_info "Worktree ready."
sc_info "  worktree:  $worktree ($branch)"
sc_info "  databases: $(sc_db_name wt "$slug"), $(sc_db_name test "$slug")"
sc_info "  URL:       $(sc_instance_url "$slug")/"

#!/bin/sh
#
# Moves a SEOCart extension to another SEOCart commit: its seocart-core.env and every call of
# SEOCart's workflows in its .github/workflows, together, so the two never disagree. An
# extension's own bin/dev/bump-core.sh runs this script from the SEOCart checkout beside it.
# See docs/writing-an-extension.md.

set -eu

usage() {
	cat >&2 <<EOF
usage: bump-extension-core.sh <extension root> <commit>

  <extension root>  the extension's checkout, which holds seocart-core.env
  <commit>          anything this SEOCart checkout resolves to a commit (a hash, a branch,
                    a tag); the pin is always its full hash, so fetch it first

Exit codes: 0 done, 1 failed, 2 usage error.
EOF
	exit 2
}

fail() {
	printf 'bump-core: %s\n' "$*" >&2
	exit 1
}

[ "$#" -eq 2 ] || usage

core=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
root=$(CDPATH='' cd -- "$1" 2>/dev/null && pwd) || fail "$1 is not a directory."
env_file=$root/seocart-core.env

[ -f "$env_file" ] || fail "$root holds no seocart-core.env, so it is not a SEOCart extension's checkout."

# seocart-core.env is data, never a script: the one line SEOCART_CORE_REF=<40 hex>.
old=$(sed -n 's/^SEOCART_CORE_REF=\([0-9a-f]\{40\}\)$/\1/p' "$env_file")
[ -n "$old" ] || fail "$env_file must hold the line SEOCART_CORE_REF=<40 hexadecimal digits>."

new=$(git -C "$core" rev-parse --verify --quiet "$2^{commit}") || fail "$core does not have the commit \"$2\". Fetch it first: git -C $core fetch"

if [ "$old" = "$new" ]; then
	printf 'bump-core: %s already pins %s; nothing to move.\n' "$root" "$new"
	exit 0
fi

calls='cirkuitnet/seocart/\.github/workflows/[A-Za-z0-9._-]*@'

# A call at a third commit is a second pin, which this script would leave behind: refuse before
# changing anything.
stale=$(grep -n "$calls" "$root"/.github/workflows/*.y*ml 2>/dev/null | grep -v "@$old" || true)
[ -z "$stale" ] || fail "these calls of SEOCart's workflows do not name the pinned commit $old; fix them by hand first:
$stale"

# Each file is rewritten through a copy, because `sed -i` is spelled differently on BSD and GNU.
rewrite() {
	sed "$2" "$1" >"$1.bump"
	mv "$1.bump" "$1"
}

rewrite "$env_file" "s/^SEOCART_CORE_REF=$old\$/SEOCART_CORE_REF=$new/"

moved=0
for workflow in "$root"/.github/workflows/*.yml "$root"/.github/workflows/*.yaml; do
	[ -f "$workflow" ] || continue
	count=$(grep -c "$calls$old" "$workflow") || count=0
	if [ "$count" -gt 0 ]; then
		rewrite "$workflow" "s#\\($calls\\)$old#\\1$new#g"
		moved=$((moved + count))
	fi
done

printf 'bump-core: %s now pins SEOCart %s (was %s): seocart-core.env and %d workflow call(s).\n' "$root" "$new" "$old" "$moved"
printf 'bump-core: next: git -C %s checkout %s, run the gates (sh %s/bin/ci/extension.sh all %s), then commit.\n' "$core" "$new" "$core" "$root"

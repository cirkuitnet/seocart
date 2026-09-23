#!/bin/sh
#
# Lints the GitHub Actions workflows and the CI scripts, and checks that every fact the
# workflow files state more than once has one value.
#
# Usage: sh bin/ci/check-workflows.sh [root]
#
#   [root]  The tree to check. Defaults to the repository this script is in. actionlint
#           only recognises a tree that has a .git entry as a project.
#
# Three checks. All of them run, so one run reports everything:
#
#   1. actionlint over .github/workflows/. With shellcheck on PATH, which check 2 needs
#      anyway, actionlint also runs it over every `run:` block.
#   2. shellcheck over bin/ci/*.sh.
#   3. Agreement. Steps cannot be shared between workflow files without a composite
#      action, so a few facts are stated once per file or once per job: PHP_VERSION,
#      PHP_FLOOR, the pin of each third-party action, the image of a service container,
#      the Composer cache key and the `composer install` line. This check is the
#      set-equality companion of those parallel statements: each fact must have the same
#      value wherever it is stated. It also requires every third-party action to be pinned
#      to a full commit SHA with the version in a trailing comment, which actionlint does
#      not look at, and every Node.js setup to read its version from .nvmrc, the one place
#      that states it.
#
# Needs actionlint (https://github.com/rhysd/actionlint) and shellcheck on PATH. POSIX sh
# and awk: runs on the Ubuntu runner and on a developer machine alike.

set -eu

fail() {
	printf 'check-workflows: %s\n' "$*" >&2
	exit 1
}

step() {
	printf '\ncheck-workflows: %s\n' "$*"
}

[ "$#" -le 1 ] || fail 'usage: check-workflows.sh [root]'

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
root=$(CDPATH='' cd -- "${1:-$script_dir/../..}" && pwd)

for tool in actionlint shellcheck awk; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required but was not found on PATH. Nothing was checked."
done

# The positional parameters become the list of workflow files.
set --

for file in "$root"/.github/workflows/*.yml "$root"/.github/workflows/*.yaml; do
	if [ -f "$file" ]; then
		set -- "$@" "$file"
	fi
done

[ "$#" -gt 0 ] || fail "$root/.github/workflows holds no workflow file. Nothing was checked."

status=0

step "actionlint ($# workflow files)"
(cd "$root" && actionlint) || status=1

step 'shellcheck bin/ci/*.sh'
shellcheck "$root"/bin/ci/*.sh || status=1

step 'agreement of the facts stated in more than one place'
awk '
	# Records the first statement of a fact and reports every later one that differs.
	function note( key, value ) {
		if ( ! ( key in first ) ) {
			first[ key ]    = value
			first_at[ key ] = FILENAME ":" FNR
			return
		}

		if ( first[ key ] != value ) {
			printf "%s:%d: %s is \"%s\" here but \"%s\" at %s\n", FILENAME, FNR, key, value, first[ key ], first_at[ key ]
			bad = 1
		}
	}

	# Returns what follows the first colon of a line.
	function after_colon( text ) {
		sub( /^[^:]*:[ \t]*/, "", text )
		return text
	}

	{
		line = $0
		sub( /^[ \t]*(-[ \t]+)?/, "", line )
		sub( /[ \t]+$/, "", line )
	}

	line ~ /^uses:[ \t]/ {
		reference = after_colon( line )

		# A workflow or an action of this repository: there is nothing to pin.
		if ( reference ~ /^\.\// ) {
			next
		}

		at     = index( reference, "@" )
		action = substr( reference, 1, at - 1 )
		pin    = substr( reference, at + 1 )
		sha    = pin
		sub( /[ \t].*$/, "", sha )

		if ( at == 0 || length( sha ) != 40 || sha ~ /[^0-9a-f]/ || pin !~ /^[0-9a-f]+ # [^ \t]+$/ ) {
			printf "%s:%d: \"%s\" is not pinned to a full commit SHA followed by \" # <version>\"\n", FILENAME, FNR, reference
			bad = 1
			next
		}

		# The actions of one repository (github/codeql-action/init and /analyze) are released
		# together, so they share one pin.
		split( action, parts, "/" )
		note( "the pin of " parts[1] "/" parts[2], pin )
		next
	}

	# .nvmrc states the Node.js version once; a version written into a workflow would be a
	# second statement that nothing keeps in step with it.
	line ~ /^node-version:/ {
		printf "%s:%d: state the Node.js version in .nvmrc and read it with \"node-version-file: .nvmrc\"\n", FILENAME, FNR
		bad = 1
		next
	}

	line ~ /^node-version-file:/ {
		note( "the Node.js version file", after_colon( line ) )
		next
	}

	line ~ /^(PHP_VERSION|PHP_FLOOR):/ {
		key = line
		sub( /:.*$/, "", key )
		note( key, after_colon( line ) )
		next
	}

	line ~ /^image:[ \t]/ {
		image = after_colon( line )
		name  = image
		sub( /[:@].*$/, "", name )
		note( "the " name " image", image )
		next
	}

	line ~ /^key:[ \t]+composer-/ {
		note( "the Composer cache key", after_colon( line ) )
		next
	}

	line ~ /^run:[ \t]+composer install/ {
		command = after_colon( line )
		sub( / --no-scripts$/, "", command )
		note( "the composer install line (apart from --no-scripts)", command )
		next
	}

	END {
		exit bad
	}
' "$@" || status=1

if [ "$status" -ne 0 ]; then
	printf '\ncheck-workflows: FAIL\n' >&2
	exit 1
fi

printf '\ncheck-workflows: PASS\n'

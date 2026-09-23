#!/bin/sh
#
# Lints workflows and CI scripts, requires PHP/Composer setup through the local action,
# checks third-party action pins in workflows and composite actions, and checks the
# remaining shared facts (PHP floor, service images and Node.js version file).
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
#   3. Shared setup and agreement. Workflows cannot call setup-php or install Composer
#      directly, or cache Composer outside the composite action. Every third-party action
#      in workflows and composite actions needs a full commit SHA and a version comment.
#      Repeated pins, the PHP matrix development version, PHP_FLOOR and service images
#      must agree; Node.js setup reads .nvmrc.
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

step 'shared setup, action pins and remaining agreement'
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
		is_workflow = FILENAME !~ /[\\\/]\.github[\\\/]actions[\\\/]/
		if ( is_workflow && line ~ /^uses:[ \t]+shivammathur\/setup-php@/ ) {
			printf "%s:%d: use ./.github/actions/setup-php-composer instead of direct setup-php\n", FILENAME, FNR
			bad = 1
		}
		if ( is_workflow && line ~ /^(run:[ \t]+)?composer install([ \t]|$)/ ) {
			printf "%s:%d: use ./.github/actions/setup-php-composer instead of direct composer install\n", FILENAME, FNR
			bad = 1
		}
		if ( is_workflow && line ~ /^uses:[ \t]+actions\/cache@/ ) {
			cache_at = FILENAME ":" FNR
		}
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

	# The PHP versions the unit-test matrix covers. The version the setup action falls back
	# to, the one the plugin is developed on, must be one of them (checked at the end).
	line ~ /^php: &php-versions \[/ {
		matrix_at = FILENAME ":" FNR
		count = split( line, parts, "\047" )
		for ( i = 2; i <= count; i += 2 ) {
			matrix_versions[ parts[ i ] ] = 1
		}
		next
	}

	! is_workflow && line ~ /^php-version:.*inputs.php-version \|\|/ {
		split( line, parts, "\047" )
		development_version = parts[ 2 ]
		development_at      = FILENAME ":" FNR
		next
	}

	line ~ /^PHP_FLOOR:/ {
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
		if ( is_workflow && cache_at != "" ) {
			printf "%s:%d: use ./.github/actions/setup-php-composer instead of a direct Composer cache (%s)\n", FILENAME, FNR, cache_at
			bad = 1
		}
		next
	}

	END {
		if ( development_version == "" ) {
			printf "the setup action states no development PHP version (php-version: ${{ inputs.php-version || \047<version>\047 }})\n"
			bad = 1
		} else if ( matrix_at == "" ) {
			printf "no workflow declares the unit-test PHP matrix (php: &php-versions [...])\n"
			bad = 1
		} else if ( ! ( development_version in matrix_versions ) ) {
			printf "%s: the unit-test PHP matrix does not include %s, the development version stated at %s\n", matrix_at, development_version, development_at
			bad = 1
		}

		exit bad
	}
' "$@" "$root"/.github/actions/*/action.yml || status=1

if [ "$status" -ne 0 ]; then
	printf '\ncheck-workflows: FAIL\n' >&2
	exit 1
fi

printf '\ncheck-workflows: PASS\n'

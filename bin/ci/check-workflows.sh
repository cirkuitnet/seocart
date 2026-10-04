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
#      Repeated pins, the PHP matrix development version, PHP_FLOOR, the actionlint version
#      and checksum, and service images must agree; Node.js setup reads .nvmrc (SEOCart's,
#      also as seocart/.nvmrc from an extension's CI).
#
# A SEOCart extension's checkout, which holds seocart-core.env, is checked the same way, as
# its CI does (`sh seocart/bin/ci/check-workflows.sh ext`), with these differences: check 2
# covers every *.sh under its bin/; check 3 has no setup action, PHP matrix or PHP_FLOOR of
# its own to check, and instead requires every call of SEOCart's workflows
# (cirkuitnet/seocart/.github/workflows/<file>@<commit>) to name the commit
# SEOCART_CORE_REF in seocart-core.env pins, so the pin is stated once and moved once.
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

# An extension's checkout: the commit its seocart-core.env pins, read as data, never sourced.
core_pin=
if [ -f "$root/seocart-core.env" ]; then
	core_pin=$(sed -n 's/^SEOCART_CORE_REF=\([0-9a-f]\{40\}\)$/\1/p' "$root/seocart-core.env")
	[ -n "$core_pin" ] || fail "$root/seocart-core.env must hold the line SEOCART_CORE_REF=<40 hexadecimal digits>. Nothing was checked."
fi

status=0

step "actionlint ($# workflow files)"
(cd "$root" && actionlint) || status=1

if [ -z "$core_pin" ]; then
	step 'shellcheck bin/ci/*.sh'
	shellcheck "$root"/bin/ci/*.sh || status=1
else
	scripts=$(find "$root/bin" -type f -name '*.sh' 2>/dev/null | sort)
	step "shellcheck bin/**/*.sh ($(printf '%s' "$scripts" | grep -c . || true) files)"
	if [ -n "$scripts" ]; then
		# One path per line, none with white space: the extension's own scripts.
		# shellcheck disable=SC2086
		shellcheck $scripts || status=1
	fi
fi

# The composite actions join the workflows; an extension has none.
for file in "$root"/.github/actions/*/action.yml "$root"/.github/actions/*/action.yaml; do
	if [ -f "$file" ]; then
		set -- "$@" "$file"
	fi
done

step 'shared setup, action pins and remaining agreement'
awk -v core_pin="$core_pin" '
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
		# Only the one setup action may set up PHP or Composer; every other file must call it.
		outside_setup = FILENAME !~ /[\\\/]\.github[\\\/]actions[\\\/]setup-php-composer[\\\/]action\.ya?ml$/
		if ( outside_setup && line ~ /^uses:[ \t]+shivammathur\/setup-php@/ ) {
			printf "%s:%d: use ./.github/actions/setup-php-composer instead of direct setup-php\n", FILENAME, FNR
			bad = 1
		}
		if ( outside_setup && line ~ /(^|[ \t;&|(])composer([ \t]+-[^ \t]+)*[ \t]+(install|i|update)([ \t;]|$)/ ) {
			printf "%s:%d: use ./.github/actions/setup-php-composer instead of running composer install or update directly\n", FILENAME, FNR
			bad = 1
		}
		if ( outside_setup && line ~ /^uses:[ \t]+actions\/cache(\/restore|\/save)?@/ ) {
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

		# An extension calls the reusable workflows of SEOCart at the commit seocart-core.env pins:
		# one pin, which bin/dev/bump-core.sh moves together with these calls.
		if ( core_pin != "" && action ~ /^cirkuitnet\/seocart\/\.github\/workflows\// ) {
			if ( sha != core_pin ) {
				printf "%s:%d: calls %s at \"%s\", but seocart-core.env pins %s; move both with bin/dev/bump-core.sh\n", FILENAME, FNR, action, pin, core_pin
				bad = 1
			}
			next
		}

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

	# The release of an extension builds SEOCart from the checkout beside it: seocart/.nvmrc.
	line ~ /^node-version-file:/ {
		version_file = after_colon( line )
		sub( /^seocart\//, "", version_file )
		note( "the Node.js version file", version_file )
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

	! outside_setup && line ~ /^php-version:.*inputs.php-version \|\|/ {
		split( line, parts, "\047" )
		development_version = parts[ 2 ]
		development_at      = FILENAME ":" FNR
		next
	}

	line ~ /^(PHP_FLOOR|ACTIONLINT_VERSION|ACTIONLINT_SHA256):/ {
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

	line ~ /^key:.*composer/ {
		if ( outside_setup && cache_at != "" ) {
			printf "%s:%d: use ./.github/actions/setup-php-composer instead of a direct Composer cache (%s)\n", FILENAME, FNR, cache_at
			bad = 1
		}
		next
	}

	END {
		# An extension has no setup action and no PHP matrix of its own: it uses those of SEOCart.
		if ( core_pin != "" ) {
			exit bad
		}

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
' "$@" || status=1

if [ "$status" -ne 0 ]; then
	printf '\ncheck-workflows: FAIL\n' >&2
	exit 1
fi

printf '\ncheck-workflows: PASS\n'

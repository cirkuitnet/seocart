#!/bin/sh
#
# Prints the CHANGELOG.md section of one release, for use as its GitHub Release notes.
#
# Usage: sh bin/ci/release-notes.sh <tag> [changelog]
#
#   <tag>        The release tag, for example v0.1.0.
#   [changelog]  The changelog to read. Defaults to CHANGELOG.md at the repository root.
#
# CHANGELOG.md follows Keep a Changelog: the section of a release starts at its
# "## [0.1.0] - 2026-01-31" heading and ends at the next "## [" heading. A release without
# a section fails here, which is what keeps a tag from being published before its
# changelog entry has been written.

set -eu

fail() {
	printf 'release-notes: %s\n' "$*" >&2
	exit 1
}

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
	fail 'usage: release-notes.sh <tag> [changelog]'
fi

version=${1#v}
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
changelog=${2:-$root/CHANGELOG.md}

[ -n "$version" ] || fail 'the tag is empty.'
[ -f "$changelog" ] || fail "$changelog does not exist."

# Blank lines are held back until the next line of text, which drops them from both ends of
# the section. The link definitions that close a Keep a Changelog file ("[0.1.0]: https://...")
# belong to the file, not to a section.
awk -v heading="## [$version]" '
	index( $0, "## [" ) == 1 {
		printing = ( index( $0, heading ) == 1 )
		next
	}
	! printing || /^\[[^]]+\]:[[:space:]]/ {
		next
	}
	/^[[:space:]]*$/ {
		held = held $0 "\n"
		next
	}
	{
		if ( seen ) {
			printf "%s", held
		}
		held = ""
		seen = 1
		print
	}
	END {
		exit seen ? 0 : 1
	}
' "$changelog" || fail "$changelog has no section for $version, or the section is empty."

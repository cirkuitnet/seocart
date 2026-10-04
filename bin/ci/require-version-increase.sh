#!/bin/sh
#
# Requires one release tag to be greater than every earlier release tag in its history.
#
# Usage: sh bin/ci/require-version-increase.sh <tag> [plugin root]
#
#   <tag>          The release tag, in the form vX.Y.Z.
#   [plugin root]  The git checkout whose tags are compared. Defaults to the repository this
#                  script is in; a SEOCart extension's release passes its own checkout.
#
# Git tag filters use glob syntax rather than regular expressions, so the release workflow
# calls this script for the exact format check. Comparing only tags merged into the tagged
# commit also ignores releases made later from another line of history.

set -eu

fail() {
	printf 'require-version-increase: %s\n' "$*" >&2
	exit 1
}

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
	fail 'usage: require-version-increase.sh <tag> [plugin root]'
fi

tag=$1
root=${2:-$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)}
version=${tag#v}
major=${version%%.*}
remainder=${version#*.}
minor=${remainder%%.*}
patch=${remainder#*.}

case $major:$minor:$patch in
	*[!0-9:]* | :* | *::* | *:)
		fail "\"$tag\" is not a release tag in the form vX.Y.Z."
		;;
esac

[ "$tag" = "v$major.$minor.$patch" ] || fail "\"$tag\" is not a release tag in the form vX.Y.Z."

for tool in git php; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required but was not found on PATH."
done

tags=$(git -C "$root" tag --merged "$tag^{commit}" --list 'v[0-9]*') || fail "could not read tags reachable from $tag in $root."

# The PHP source is a literal: nothing in it is meant to expand in the shell.
# shellcheck disable=SC2016
previous=$(
	printf '%s\n' "$tags" | SEOCART_CI_TAG=$tag php -r '
		$current  = (string) getenv( "SEOCART_CI_TAG" );
		$greatest = "";

		while ( false !== ( $candidate = fgets( STDIN ) ) ) {
			$candidate = rtrim( $candidate, "\r\n" );

			if ( $candidate === $current || 1 !== preg_match( "/^v[0-9]+\\.[0-9]+\\.[0-9]+$/D", $candidate ) ) {
				continue;
			}

			if ( "" === $greatest || version_compare( substr( $candidate, 1 ), substr( $greatest, 1 ), ">" ) ) {
				$greatest = $candidate;
			}
		}

		echo $greatest;
	'
) || fail 'could not compare the release tags.'

if [ -z "$previous" ]; then
	printf 'require-version-increase: %s is the first release.\n' "$tag"
	exit 0
fi

# The PHP source is a literal: nothing in it is meant to expand in the shell.
# shellcheck disable=SC2016
if SEOCART_CI_NEW=$version SEOCART_CI_PREVIOUS=${previous#v} php -r '
	exit( version_compare(
		(string) getenv( "SEOCART_CI_NEW" ),
		(string) getenv( "SEOCART_CI_PREVIOUS" ),
		">"
	) ? 0 : 1 );
'; then
	printf 'require-version-increase: %s is greater than previous release %s.\n' "$tag" "$previous"
	exit 0
fi

fail "$tag must be greater than previous release $previous."

#!/bin/sh
#
# Downloads WordPress core into a directory.
#
# Usage: sh bin/ci/download-wordpress.sh <version> <directory>
#
#   <version>    An exact release ("7.1.1"), a release line ("7.1", resolved to the newest
#                release of that line), "latest" or "nightly".
#   <directory>  Where WordPress core goes. Created when it does not exist.
#
# This is the one place that knows how a version specification becomes a WordPress
# checkout: prepare-integration.sh and install-smoke.sh both call it. A release line
# rather than an exact release is what the CI matrix names, because the support policy
# is stated in release lines and the newest patch release is the one sites run.
#
# Needs WP-CLI, PHP and curl. POSIX sh: runs on the Ubuntu runner and on a developer
# machine alike.

set -eu

fail() {
	printf 'download-wordpress: %s\n' "$*" >&2
	exit 1
}

[ "$#" -eq 2 ] || fail 'usage: download-wordpress.sh <version|latest|nightly> <directory>'

spec=$1
target=$2

for tool in wp php curl; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required but was not found on PATH."
done

# Prints the newest release of a release line, using the list of every released version
# that api.wordpress.org publishes.
newest_release_of_line() {
	releases=$(mktemp "${TMPDIR:-/tmp}/seocart-wp-releases.XXXXXX")
	trap 'rm -f -- "$releases"' 0
	trap 'exit 1' HUP INT TERM

	if ! curl -fsSL --retry 3 -o "$releases" https://api.wordpress.org/core/stable-check/1.0/; then
		rm -f "$releases"
		fail 'could not read the list of WordPress releases from api.wordpress.org.'
	fi

	# The PHP source is a literal: nothing in it is meant to expand in the shell.
	# shellcheck disable=SC2016
	newest=$(
		SEOCART_CI_RELEASE_LINE=$1 php -r '
			$line     = (string) getenv( "SEOCART_CI_RELEASE_LINE" );
			$releases = array_keys( (array) json_decode( (string) file_get_contents( $argv[1] ), true ) );
			$matches  = array_filter(
				$releases,
				static fn ( $release ) => $release === $line || str_starts_with( (string) $release, $line . "." )
			);

			if ( array() === $matches ) {
				exit( 1 );
			}

			usort( $matches, "version_compare" );
			echo end( $matches ), PHP_EOL;
		' "$releases"
	) || newest=''

	rm -f "$releases"

	[ -n "$newest" ] || fail "WordPress has no release in the $1 line."
	printf '%s\n' "$newest"
}

case $spec in
	latest | nightly)
		version=$spec
		;;
	*[!0-9.]* | '' | .* | *. | *..*)
		fail "\"$spec\" is not a WordPress version, \"latest\" or \"nightly\"."
		;;
	*.*.*)
		version=$spec
		;;
	*.*)
		version=$(newest_release_of_line "$spec")
		;;
	*)
		fail "\"$spec\" is not a WordPress version: name a release (7.1.1) or a release line (7.1)."
		;;
esac

# An exact release that is already in place is left alone, so a developer machine does not
# download it again. "latest" and "nightly" move, so they are always fetched.
if [ -f "$target/wp-includes/version.php" ]; then
	# shellcheck disable=SC2016
	present=$(php -r 'include $argv[1]; echo $wp_version;' "$target/wp-includes/version.php")

	if [ "$present" = "$version" ]; then
		printf 'download-wordpress: WordPress %s is already in %s\n' "$version" "$target"
		exit 0
	fi
elif [ -d "$target" ] && [ -n "$(ls -A "$target")" ]; then
	fail "$target exists, is not empty and is not a WordPress checkout; refusing to write into it."
fi

mkdir -p "$target"
wp core download --path="$target" --version="$version" --locale=en_US --force

# shellcheck disable=SC2016
printf 'download-wordpress: WordPress %s is in %s\n' \
	"$(php -r 'include $argv[1]; echo $wp_version;' "$target/wp-includes/version.php")" \
	"$target"

#!/bin/sh
#
# Downloads Polylang (free) at the one pinned, checksum-verified version, verifies it, and
# unpacks it.
#
# Usage: sh bin/ci/download-polylang.sh <directory>
#
#   <directory>  Where Polylang goes. Must not exist yet, so a caller always gets a clean
#                unpack: nothing here decides whether to reuse or replace an old copy.
#
# bin/ci/polylang-pin.env is the one place that states the version and its SHA-256;
# bin/dev/provision-site.sh --with-polylang reads the same file, so a CI cell and a
# developer's disposable site install the same version. Only this script verifies the
# download's checksum; the WP-CLI install provision-site.sh runs does not.
#
# The digest is verified against the downloaded zip before anything is unpacked. A mismatch
# fails with the expected and the actual digest, and <directory> is left exactly as it was
# found: the zip and the unpacked files live in a temporary directory until the digest has
# passed.
#
# Prints the path of polylang.php in <directory> on stdout, and nothing else there: every
# progress line goes to stderr, so a caller can capture the path with $( ... ).
#
# Needs curl, unzip and one of sha256sum or shasum. POSIX sh: runs on the Ubuntu runner and
# on a developer machine alike.

set -eu

fail() {
	printf 'download-polylang: %s\n' "$*" >&2
	exit 1
}

[ "$#" -eq 1 ] || fail 'usage: download-polylang.sh <directory>'

target=$1

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
pin=$script_dir/polylang-pin.env

[ -f "$pin" ] || fail "$pin does not exist."

# The pin file only assigns the two variables checked below; shellcheck need not follow it.
# shellcheck source=/dev/null
. "$pin"

[ -n "${POLYLANG_VERSION:-}" ] || fail "$pin does not set POLYLANG_VERSION."
[ -n "${POLYLANG_SHA256:-}" ] || fail "$pin does not set POLYLANG_SHA256."

case "$POLYLANG_SHA256" in
	*[!0-9a-f]*)
		fail "POLYLANG_SHA256 in $pin is not lower-case hexadecimal: $POLYLANG_SHA256"
		;;
esac
[ "${#POLYLANG_SHA256}" -eq 64 ] || fail "POLYLANG_SHA256 in $pin is not 64 characters: $POLYLANG_SHA256"

for tool in curl unzip; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required but was not found on PATH."
done

# Prints the SHA-256 of one file, as a bare lower-case hex digest.
sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{ print $1 }'
	else
		fail 'sha256sum or shasum is required but was not found on PATH.'
	fi
}

[ ! -e "$target" ] || fail "$target exists already; refusing to unpack over it."

work=$(mktemp -d "${TMPDIR:-/tmp}/seocart-polylang.XXXXXX")
trap 'rm -rf -- "$work"' 0
trap 'exit 1' HUP INT TERM

zip_file=$work/polylang.$POLYLANG_VERSION.zip
url=https://downloads.wordpress.org/plugin/polylang.$POLYLANG_VERSION.zip

printf 'download-polylang: downloading %s\n' "$url" >&2
curl -fsSL --retry 3 -o "$zip_file" "$url" || fail "could not download $url"

actual=$(sha256_of "$zip_file")

if [ "$actual" != "$POLYLANG_SHA256" ]; then
	fail "checksum mismatch for polylang.$POLYLANG_VERSION.zip: expected $POLYLANG_SHA256, got $actual"
fi

printf 'download-polylang: checksum verified (%s)\n' "$actual" >&2

mkdir -p "$work/unpacked"
unzip -q "$zip_file" -d "$work/unpacked"

[ -f "$work/unpacked/polylang/polylang.php" ] || fail "polylang.php was not found after unpacking $zip_file"

mkdir -p "$(dirname -- "$target")"
mv "$work/unpacked/polylang" "$target"

printf '%s\n' "$target/polylang.php"

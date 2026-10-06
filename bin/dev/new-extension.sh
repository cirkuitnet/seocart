#!/bin/sh
#
# Writes a new SEOCart extension's repository. See README.md in this directory and
# docs/writing-an-extension.md.

set -eu

SC_DEV_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)

# SEOCart's root as the caller reaches it, through any link: the extension is written beside it,
# as ../<slug>, where its gates look for ../seocart.
core=$(CDPATH='' cd -- "$SC_DEV_DIR/../.." && pwd)

usage() {
	cat <<EOF
usage: new-extension.sh <slug> --type=<type> --label=<label> [--namespace=<segment>] [--gateway-id=<id>] [--dir=<path>]

Writes the repository of a new SEOCart extension from the templates in
bin/dev/extension-template/ and bin/dev/extension-types/<type>/, makes it a git
repository and stages every file; nothing is committed.

  <slug>                 the slug WordPress.org makes of the plugin's name, which the
                         type and the label decide: "SEOCart Gateway for Stripe" is
                         seocart-gateway-for-stripe. Any other is refused. The main
                         file is <slug>.php
  --type=<type>          the kind of extension: payments, named "SEOCart Gateway for
                         <label>"
  --label=<label>        the service it integrates, for example Stripe; letters, digits,
                         spaces, dots and hyphens
  --namespace=<segment>  the PHP namespace after SEOCart\\ (default: the slug after
                         seocart-, in StudlyCase, for example GatewayForStripe)
  --gateway-id=<id>      the id the gateway registers with: lower-case snake_case,
                         starting with a letter, at most 32 characters (default: the
                         label in snake_case, for example stripe or authorize_net)
  --dir=<path>           where to write it (default: ../<slug> beside this SEOCart
                         checkout, which is where the extension's gates look for SEOCart)

The extension is pinned to the commit this SEOCart checkout is at (seocart-core.env).

Exit codes: 0 done, 1 failed, 2 usage error.
EOF
}

for argument in "$@"; do
	case $argument in
		-h | --help)
			usage
			exit 0
			;;
	esac
done

for tool in php git; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		printf 'new-extension: %s is required but was not found on PATH.\n' "$tool" >&2
		exit 1
	fi
done

exec php "$core/tools/extension.php" new "$core" "$@"

#!/bin/sh
# shellcheck disable=SC2154 # The paths and helpers of SEOCart's lib are assigned there.
#
# The provider's steps of the fixture extension's live workflow (.github/workflows/fixture-pay-sandbox.yml),
# in the shape a payment extension writes them: SEOCart's tests read it, and nothing runs it. Run
# from the directory that holds the extension's checkout and SEOCart's beside it, as ext/ and
# seocart/ are on the runner:
#
#   sh ext/bin/ci/fixture-pay-sandbox.sh <step>
#
#   check-secrets   Fails, naming the secret and never its value, when FIXTURE_PAY_API_KEY is
#                   empty. It runs before SEOCart is checked out, so it alone does without
#                   SEOCart's lib.
#   settings        Writes the one file of sandbox settings the tests read, readable by its owner
#                   alone, in the work directory, and starts the run list and the report there.
#   configure       Configures the checkout's site's gateway in test mode from the settings file.
#   checkout        Runs the checkout's browser tests against that site: each must pass, none
#                   skipped.
#   cleanup         Puts the site's charges on the run list, when the job has a site; ends at the
#                   provider what the run left open; stops the site's server and removes the work
#                   directory.
#
# Every other step is SEOCart's (seocart/bin/ci/extension-live.sh), and so are the helpers and
# paths below that this script does not define (seocart/bin/ci/extension-live-lib.sh).

set -eu

ext=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
core=$(CDPATH='' cd -- "$ext/../seocart" 2>/dev/null && pwd) || core=$ext/../seocart
# shellcheck source=/dev/null
[ "${1:-}" = check-secrets ] || . "$core/bin/ci/extension-live-lib.sh"

check_secrets() {
	if [ -z "${FIXTURE_PAY_API_KEY:-}" ]; then
		printf '::error::The secret FIXTURE_PAY_API_KEY is empty: the repository'\''s Actions secrets must hold it.\n'
		exit 1
	fi
	echo 'The secret FIXTURE_PAY_API_KEY is set.'
}

write_settings() {
	check_secrets
	umask 077
	mkdir -p "$work"
	# jq reads the values from its environment: none is an argument a process list would show.
	jq -n '{
		api_key: env.FIXTURE_PAY_API_KEY,
		signature_key: (env.FIXTURE_PAY_SIGNATURE_KEY // "")
	}' >"$settings"
	: >"$run_list"
	: >"$report"
	chmod 600 "$settings" "$run_list" "$report"
	echo 'The settings file is written, readable by its owner alone: api_key, signature_key.'
}

# Configures the site's gateway from the settings file, once it is written; nothing is installed or
# built from then on.
configure() {
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	[ -f "$site_env" ] || fail 'The site is missing: run the site step first.'
	site_wp seocart gateway configure fixture_pay --mode=test --from-file="$settings" --no-webhooks --user=admin </dev/null
	echo 'The gateway takes payments in test mode, its webhooks left out.'
}

# Adds the site's charges to the run list, each once, as the provider's id and the store's uuid.
harvest_site() {
	prefix=$(site_wp db prefix </dev/null) || return 1
	site_wp db query "SELECT provider_intent_id, uuid FROM ${prefix}seocart_payment_intents WHERE gateway_id = 'fixture_pay'" \
		--skip-column-names </dev/null >"$work/site-charges" || return 1
	tr '\t' ' ' <"$work/site-charges" >>"$run_list" || return 1
	sort -u -o "$run_list" "$run_list" || return 1
}

checkout() {
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	[ -f "$site_env" ] || fail 'The site is missing: run the site step first.'
	status=0
	# Playwright is run as the set-up installed it, never through npx, which would fetch a package
	# it did not find.
	(
		cd "$ext/tests/browser"
		set -a
		# shellcheck disable=SC1090 # The site's file, written by the site step.
		. "$site_env"
		set +a
		SEOCART_TAPE_SETTINGS=$settings
		export SEOCART_TAPE_SETTINGS
		filtered "$work/checkout.out" node_modules/.bin/playwright test --config playwright.config.mjs --forbid-only
	) || status=$?
	passed=$(count "$work/checkout.out" passed)
	skipped=$(count "$work/checkout.out" skipped)
	output tests "${passed:-0}"
	output skipped "${skipped:-0}"
	if [ "${passed:-0}" -eq 0 ] || [ "${skipped:-0}" -ne 0 ]; then
		printf '::error::The checkout'\''s browser tests passed %s and skipped %s: each must run and pass.\n' "${passed:-0}" "${skipped:-0}"
		status=1
	fi
	return "$status"
}

cleanup() {
	status=0
	if [ -f "$settings" ]; then
		if [ -f "$site_env" ]; then
			harvest_site || status=1
		fi
		php "$ext/tests/Integration/Live/cleanup.php" "$settings" "$run_list" || status=1
	else
		echo 'cleanup: no settings file, so this job made no request to the provider.'
	fi
	if [ -f "$work/server.pid" ]; then
		kill "$(cat "$work/server.pid")" 2>/dev/null || true
	fi
	rm -rf "$work"
	return "$status"
}

case ${1:-} in
	check-secrets) check_secrets ;;
	settings) write_settings ;;
	configure) configure ;;
	checkout) checkout ;;
	cleanup) cleanup ;;
	*) fail 'usage: fixture-pay-sandbox.sh check-secrets|settings|configure|checkout|cleanup' ;;
esac

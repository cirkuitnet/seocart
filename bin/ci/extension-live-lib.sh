# shellcheck shell=sh disable=SC2034,SC2154
# (shellcheck reads this file on its own: it sees neither the scripts that use the paths below nor
# the core and ext they assign.)
#
# The helpers and paths every script of a payment extension's live workflow shares: SEOCart's
# bin/ci/extension-live.sh, and the extension's own bin/ci/<workflow>.sh, which runs its
# provider's steps. Sourced, never run, once the script has set core (SEOCart's checkout) and,
# before any helper runs, ext (the extension's):
#
#     . "$core/bin/ci/extension-live-lib.sh"
#
# One job is one run, so the files of a run live in one work directory, whatever the provider:
# ${RUNNER_TEMP:-${TMPDIR:-/tmp}}/seocart-live. The settings file, the run list and the report
# there are readable by their owner alone; the job's clean-up removes the directory. Nothing here
# installs, downloads or builds: every step may source it, the steps after the settings step
# included (SettingsLast holds each function to that).

work=${RUNNER_TEMP:-${TMPDIR:-/tmp}}/seocart-live
settings=$work/settings.json
run_list=$work/run-list
report=$work/report
site_dir=$work/site
site_env=$work/site.env
site_url=http://127.0.0.1:${SEOCART_LIVE_HTTP_PORT:-8890}
outputs=${GITHUB_OUTPUT:-/dev/stdout}
step_php=$core/tests/Conformance/Payment/Live/workflow-step.php

# Fails the step with an error the workflow shows.
fail() {
	printf '::error::%s\n' "$*"
	exit 1
}

# Writes one output of the step.
output() {
	printf '%s=%s\n' "$1" "$2" >>"$outputs"
}

# Masks a value in the rest of the job's log; outside GitHub Actions there is no log to mask.
mask() {
	if [ "${GITHUB_ACTIONS:-}" = true ]; then
		printf '::add-mask::%s\n' "$1"
	fi
}

# A random value of 32 hexadecimal digits.
random_hex() {
	od -An -N16 -tx1 /dev/urandom | tr -d ' \n'
}

# The run's nonce: fresh for every attempt, as a provider answers a key it has seen with its first
# answer.
nonce() {
	if [ -n "${GITHUB_RUN_ID:-}" ]; then
		printf 'r%s.%s' "$GITHUB_RUN_ID" "${GITHUB_RUN_ATTEMPT:-1}"
	else
		printf 'r%s.1' "$(date +%s)"
	fi
}

# Runs a command with its output passed through the guard of the extension's declaration and kept
# in a file, and returns the command's status: the pipe's status would be the filter's.
filtered() {
	kept=$1
	shift
	status_file=$work/status
	{
		code=0
		"$@" 2>&1 || code=$?
		echo "$code" >"$status_file"
	} | php "$step_php" redact "$ext" | tee "$kept"
	return "$(cat "$status_file")"
}

# Runs WP-CLI against the checkout's site. Standard input is closed so that no command waits for a
# prompt, unless the caller gives one.
site_wp() {
	wp --path="$site_dir" "$@"
}

# Reads a count of Playwright's summary from a run's output: count <output> passed|skipped.
count() {
	sed -n "s/^ *\([0-9][0-9]*\) $2.*/\1/p" "$1" | tail -n 1
}

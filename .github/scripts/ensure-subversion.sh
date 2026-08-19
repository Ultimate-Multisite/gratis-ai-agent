#!/usr/bin/env bash

set -euo pipefail

if command -v svn >/dev/null 2>&1; then
	svn --version --quiet
	exit 0
fi

timeout_seconds="${SUBVERSION_INSTALL_TIMEOUT_SECONDS:-120}"
kill_after_seconds="${SUBVERSION_INSTALL_KILL_AFTER_SECONDS:-30}"

if [[ ! "${timeout_seconds}" =~ ^[1-9][0-9]*$ ]] || [[ ! "${kill_after_seconds}" =~ ^[1-9][0-9]*$ ]]; then
	printf 'Subversion installation timeouts must be positive integers.\n' >&2
	exit 2
fi

if sudo timeout --signal=TERM --kill-after="${kill_after_seconds}s" "${timeout_seconds}s" apt-get install --yes --no-install-recommends subversion; then
	svn --version --quiet
	exit 0
else
	status=$?
	printf 'Initial Subversion install failed (exit %d); refreshing package indexes before one retry.\n' "${status}" >&2
fi

sudo timeout --signal=TERM --kill-after="${kill_after_seconds}s" "${timeout_seconds}s" apt-get update || {
	status=$?
	printf 'Subversion setup failed during apt-get update fallback (exit %d).\n' "${status}" >&2
	exit "${status}"
}
sudo timeout --signal=TERM --kill-after="${kill_after_seconds}s" "${timeout_seconds}s" apt-get install --yes --no-install-recommends subversion || {
	status=$?
	printf 'Subversion setup failed during apt-get install retry (exit %d).\n' "${status}" >&2
	exit "${status}"
}

svn --version --quiet

#!/usr/bin/env bash
#
# bin/wporg-review.sh — Mirror the WordPress.org Plugin Check AI review
# pipeline locally against the core WordPress.org build of this plugin.
#
# Why: the WP.org review team runs github.com/WordPress/plugin-check
# (PCP) with the AI checks enabled. PCP scans the EXACT zip that was
# submitted, not the dev source tree. Reviewing the source tree as-is
# produces irrelevant noise (node_modules, vendor/, src/, tests/, and the
# advanced-plugin companion package — none of which ship in the core
# WordPress.org zip per .distignore).
#
# This script:
#   1. Builds the core WordPress.org zip via `bin/build.sh --target=wporg`
#      (alias of `--target=core`). Advanced features live in the separate
#      superdav-ai-agent-advanced package and are not copied into this zip.
#   2. Extracts the zip into wp-content/plugins/<REVIEW_SLUG>/ on the
#      sibling ../wordpress dev install — a *separate* directory from
#      the dev symlink at wp-content/plugins/superdav-ai-agent/.
#   3. Ensures the Plugin Check (PCP) plugin is installed and active
#      and is recent enough to support `--ai` and `--ai-model=...`
#      (PCP 2.0.0+; older versions fall back to non-AI checks).
#   4. Runs `wp plugin check <REVIEW_SLUG> --slug=superdav-ai-agent
#      --ai --ai-model=<MODEL>` so PCP scans the extracted core build
#      but reports against the real submission slug (so the trademark
#      / similar-name AI checks see the slug we will actually submit).
#   5. Writes the report to build/wporg-review/<version>-<timestamp>.txt
#      (gitignored via build/, distignored via /build/wporg-review).
#   6. Cleans up the extracted plugin dir unless KEEP=1.
#
# The model defaults to `openai::gpt-5.5`, our best current guess at
# what the WP.org Internal Scanner uses for AI review (the team has
# not published the model). Override per run:
#
#   npm run wporg-review                                       # default
#   MODEL=anthropic::claude-sonnet-4-5 npm run wporg-review    # claude
#   MODEL=openai::gpt-4o            npm run wporg-review       # gpt-4o
#   KEEP=1 npm run wporg-review                                # leave extracted
#   SKIP_PLUGINS=foo,bar npm run wporg-review                  # extra skips
#
# Prereqs:
#   - ../wordpress  (the dev install per AGENTS.md "Local Development
#                    Environment")
#   - bin/build.sh  (this repo)
#   - wp-cli `wp`   (in PATH; wp-cli.yml in this repo points it at
#                    ../wordpress automatically)
#   - unzip
#   - An AI provider connector plugin installed and configured with
#     valid credentials in the dev WP install (e.g. ai-provider-for-
#     openai or ai-provider-for-anthropic). Without credentials PCP's
#     AI checks degrade silently to non-AI checks.
#
# Exit codes:
#   0   review completed and PCP found no issues
#   !=0 review completed and PCP found issues (this is the normal
#       outcome of a useful review — non-zero is NOT a script failure)
#       OR the script aborted before running PCP (build/extract/etc).
#
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: 2025-2026 Superdav AI Agent contributors

set -euo pipefail

# ── Resolve plugin root ──────────────────────────────────────────────────────

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

# ── Config (env-overridable) ─────────────────────────────────────────────────

: "${MODEL:=openai::gpt-5.5}"
: "${REVIEW_SLUG:=superdav-ai-agent-wporg-review}"
: "${SUBMISSION_SLUG:=superdav-ai-agent}"
: "${SKIP_PLUGINS:=multisite-ultimate-domain-seller}"
: "${KEEP:=0}"

# WP_DIR defaults to the sibling ../wordpress dev install per
# AGENTS.md > Local Development Environment. Override it explicitly when
# running this script from a git worktree under ~/Git/<repo>-worktrees/,
# where ${PLUGIN_DIR}/../wordpress points at the wrong directory:
#
#   WP_DIR=/home/dave/Git/wordpress npm run wporg-review
: "${WP_DIR:=${PLUGIN_DIR}/../wordpress}"
PLUGINS_DIR="${WP_DIR}/wp-content/plugins"
REPORT_DIR="${PLUGIN_DIR}/build/wporg-review"

# ── Pre-flight ───────────────────────────────────────────────────────────────

require_cmd() {
	local cmd="$1"
	if ! command -v "$cmd" >/dev/null 2>&1; then
		echo "ERROR: required command not found: ${cmd}" >&2
		return 1
	fi
	return 0
}

require_cmd wp
require_cmd unzip
require_cmd bash

if [ ! -d "$WP_DIR" ]; then
	echo "ERROR: WordPress dev install not found at ${WP_DIR}" >&2
	echo "       AGENTS.md > Local Development Environment expects ../wordpress" >&2
	echo "       relative to this repo." >&2
	exit 1
fi

if [ ! -x "${PLUGIN_DIR}/bin/build.sh" ]; then
	echo "ERROR: bin/build.sh is missing or not executable." >&2
	exit 1
fi

# Read version from the plugin header so we can name the report and
# locate the zip bin/build.sh will produce.
VERSION="$(
	grep -E "^[[:space:]]*\*[[:space:]]*Version:" superdav-ai-agent.php |
		head -1 |
		sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//' |
		tr -d '[:space:]'
)"
if [ -z "$VERSION" ]; then
	echo "ERROR: could not read Version from superdav-ai-agent.php header." >&2
	exit 1
fi

TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
ZIP_PATH="${PLUGIN_DIR}/superdav-ai-agent-${VERSION}.zip"
TARGET_DIR="${PLUGINS_DIR}/${REVIEW_SLUG}"
REPORT_PATH="${REPORT_DIR}/${VERSION}-${TIMESTAMP}.txt"

echo "==> Superdav AI Agent — WP.org Plugin Check AI review"
echo "    Version          : ${VERSION}"
echo "    Model            : ${MODEL}"
echo "    Review dir slug  : ${REVIEW_SLUG}"
echo "    Submission slug  : ${SUBMISSION_SLUG}  (reported against, not scanned)"
echo "    Skip plugins     : ${SKIP_PLUGINS}"
echo "    Report path      : ${REPORT_PATH}"

# ── 1. Build the wporg target ────────────────────────────────────────────────

echo
echo "==> [1/5] Building core wporg zip..."
bash bin/build.sh --target=wporg

if [ ! -f "$ZIP_PATH" ]; then
	echo "ERROR: expected zip not produced at: ${ZIP_PATH}" >&2
	echo "       Did bin/build.sh change its naming convention?" >&2
	exit 1
fi

# ── 2. Extract to a separate plugins dir ─────────────────────────────────────

echo
echo "==> [2/5] Extracting core wporg zip into wp-content/plugins/${REVIEW_SLUG}/..."

# Defence in depth: refuse to touch anything other than our review slug
# path. This guards against an env-injected REVIEW_SLUG pointing at the
# dev symlink (superdav-ai-agent) or another plugin directory.
if [ -z "$REVIEW_SLUG" ] || [ "$REVIEW_SLUG" = "superdav-ai-agent" ]; then
	echo "ERROR: REVIEW_SLUG must be set and must NOT equal 'superdav-ai-agent'" >&2
	echo "       (that's the dev symlink — we will not overwrite it)." >&2
	exit 1
fi
case "$TARGET_DIR" in
*/wp-content/plugins/"${REVIEW_SLUG}") : ;;
*)
	echo "ERROR: computed TARGET_DIR is not under wp-content/plugins/: ${TARGET_DIR}" >&2
	exit 1
	;;
esac
if [ -L "$TARGET_DIR" ]; then
	echo "ERROR: ${TARGET_DIR} is a symlink; refusing to remove or overwrite." >&2
	exit 1
fi

rm -rf "$TARGET_DIR"

TMP_EXTRACT="$(mktemp -d)"
# shellcheck disable=SC2317  # invoked via trap, not directly
cleanup_tmp() {
	rm -rf "$TMP_EXTRACT"
	return 0
}
trap cleanup_tmp EXIT

unzip -q "$ZIP_PATH" -d "$TMP_EXTRACT"

# bin/build.sh always names the top-level directory inside the zip
# `superdav-ai-agent/` (see bin/build.sh ~line 389). Rename it to the
# review slug so wp-cli picks it up under that directory name.
if [ ! -d "${TMP_EXTRACT}/superdav-ai-agent" ]; then
	echo "ERROR: zip did not contain expected top-level directory" \
		"'superdav-ai-agent/'" >&2
	exit 1
fi
mv "${TMP_EXTRACT}/superdav-ai-agent" "$TARGET_DIR"

# ── 3. Ensure Plugin Check is installed and active ───────────────────────────

echo
echo "==> [3/5] Ensuring Plugin Check (PCP) is installed and recent..."

# We need PCP ≥ 2.0.0 for the --ai / --ai-model flags.
# `wp plugin install plugin-check --activate --force` always pulls the
# latest, which is what we want for review parity with .org.
wp --path="$WP_DIR" plugin install plugin-check --activate --force \
	--skip-plugins="$SKIP_PLUGINS" --skip-themes \
	>/dev/null

PCP_VERSION="$(
	wp --path="$WP_DIR" plugin get plugin-check --field=version \
		--skip-plugins="$SKIP_PLUGINS" --skip-themes 2>/dev/null || true
)"
echo "    Plugin Check version: ${PCP_VERSION:-unknown}"

PCP_CLI="${PLUGINS_DIR}/plugin-check/cli.php"
if [ ! -f "$PCP_CLI" ]; then
	echo "ERROR: Plugin Check cli.php not found at ${PCP_CLI}" >&2
	exit 1
fi

# ── 4. Run the AI-enabled plugin check ───────────────────────────────────────

echo
echo "==> [4/5] Running wp plugin check ${REVIEW_SLUG}" \
	"--slug=${SUBMISSION_SLUG} --ai --ai-model=${MODEL}..."

mkdir -p "$REPORT_DIR"

# Header for the report so the artefact is self-describing.
{
	echo "Superdav AI Agent — WP.org Plugin Check AI review"
	echo "=================================================="
	echo "Generated      : ${TIMESTAMP}"
	echo "Plugin version : ${VERSION}"
	echo "PCP version    : ${PCP_VERSION:-unknown}"
	echo "Model          : ${MODEL}"
	echo "Review dir     : ${TARGET_DIR}"
	echo "Reported slug  : ${SUBMISSION_SLUG}"
	echo "Skipped plugins: ${SKIP_PLUGINS}"
	echo "Command        : wp --path=${WP_DIR} plugin check ${REVIEW_SLUG} \\"
	echo "                     --slug=${SUBMISSION_SLUG} \\"
	echo "                     --ai --ai-model=${MODEL} \\"
	echo "                     --require=${PCP_CLI} \\"
	echo "                     --skip-plugins=${SKIP_PLUGINS} --skip-themes"
	echo "=================================================="
	echo
} >"$REPORT_PATH"

# PCP exits non-zero when issues are found — that's the whole point of
# running it. Capture the exit code but don't abort the script on it.
set +e
wp --path="$WP_DIR" plugin check "$REVIEW_SLUG" \
	--slug="$SUBMISSION_SLUG" \
	--ai \
	--ai-model="$MODEL" \
	--require="$PCP_CLI" \
	--skip-plugins="$SKIP_PLUGINS" \
	--skip-themes \
	2>&1 | tee -a "$REPORT_PATH"
PCP_EXIT=${PIPESTATUS[0]}
set -e

# ── 5. Cleanup ───────────────────────────────────────────────────────────────

echo
echo "==> [5/5] Cleanup..."
if [ "$KEEP" = "1" ]; then
	echo "    KEEP=1 — leaving ${TARGET_DIR} in place for inspection."
else
	# Symlink/path safety re-check before rm -rf.
	if [ ! -L "$TARGET_DIR" ] && [ -d "$TARGET_DIR" ]; then
		case "$TARGET_DIR" in
		*/wp-content/plugins/"${REVIEW_SLUG}")
			rm -rf "$TARGET_DIR"
			echo "    Removed ${TARGET_DIR}."
			;;
		*)
			echo "    WARNING: skipping cleanup — TARGET_DIR shape changed: ${TARGET_DIR}" >&2
			;;
		esac
	fi
fi

echo
echo "==> Done."
echo "    Report  : ${REPORT_PATH}"
echo "    PCP exit: ${PCP_EXIT}  (non-zero is normal — it means issues were found)"
exit "$PCP_EXIT"

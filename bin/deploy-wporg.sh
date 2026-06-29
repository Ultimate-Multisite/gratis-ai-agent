#!/usr/bin/env bash
#
# Deploy Superdav AI Agent to the WordPress.org plugin directory via SVN.
#
# Usage:
#   bin/deploy-wporg.sh --version 1.2.0 --username YOUR_WP_USERNAME [--svn-dir ~/svn/superdav-ai-agent]
#
# Prerequisites:
#   1. Plugin approved on WordPress.org (SVN repo must exist)
#   2. SVN checked out: svn checkout https://plugins.svn.wordpress.org/superdav-ai-agent/ ~/svn/superdav-ai-agent
#   3. svn CLI installed (apt-get install subversion / brew install subversion)
#
# Note: this script always builds the core WordPress.org package via
# `bin/build.sh --target=wporg` (alias of `--target=core`). Advanced
# features are packaged separately from /advanced-plugin as
# superdav-ai-agent-advanced and never sync to the WP.org SVN trunk.
#
# What this script does:
#   1. Builds production assets and ZIP via bin/build.sh
#   2. Syncs built files into SVN trunk/
#   3. Stages new/deleted files with svn add / svn delete
#   4. Commits trunk
#   5. Creates a version tag via svn copy
#
# See docs/wordpress-org-submission.md for the full submission workflow.

set -euo pipefail

# ── Defaults ──────────────────────────────────────────────────────────────────
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# The SVN slug is the WordPress.org-approved plugin slug. Override --svn-dir
# only when using a non-default local checkout path.
SVN_DIR="${HOME}/svn/superdav-ai-agent"
WP_USERNAME=""
VERSION=""
DRY_RUN=false

# ── Argument parsing ──────────────────────────────────────────────────────────
usage() {
	cat >&2 <<EOF
Usage: bin/deploy-wporg.sh --version VERSION --username WP_USERNAME [OPTIONS]

Required:
  --version VERSION       Plugin version to deploy (e.g. 1.2.0)
  --username WP_USERNAME  WordPress.org username for SVN authentication

Options:
  --svn-dir DIR           Path to SVN checkout (default: ~/svn/superdav-ai-agent)
  --dry-run               Build and sync but do not commit or tag
  --help                  Show this help

Examples:
  bin/deploy-wporg.sh --version 1.2.0 --username developer-dave
  bin/deploy-wporg.sh --version 1.3.0 --username developer-dave --dry-run
EOF
	return 0
}

while [ $# -gt 0 ]; do
	case "$1" in
	--version)
		if [ $# -lt 2 ]; then
			echo "ERROR: --version requires a value." >&2
			usage
			exit 1
		fi
		VERSION="$2"
		shift 2
		;;
	--username)
		if [ $# -lt 2 ]; then
			echo "ERROR: --username requires a value." >&2
			usage
			exit 1
		fi
		WP_USERNAME="$2"
		shift 2
		;;
	--svn-dir)
		if [ $# -lt 2 ]; then
			echo "ERROR: --svn-dir requires a value." >&2
			usage
			exit 1
		fi
		SVN_DIR="$2"
		shift 2
		;;
	--dry-run)
		DRY_RUN=true
		shift
		;;
	--help | -h)
		usage
		exit 0
		;;
	*)
		echo "Unknown option: $1" >&2
		usage
		exit 1
		;;
	esac
done

if [ -z "$VERSION" ] || [ -z "$WP_USERNAME" ]; then
	echo "ERROR: --version and --username are required." >&2
	usage
	exit 1
fi

# ── Validate prerequisites ────────────────────────────────────────────────────
if ! command -v svn >/dev/null 2>&1; then
	echo "ERROR: svn is not installed." >&2
	echo "  Ubuntu/Debian: sudo apt-get install subversion" >&2
	echo "  macOS:         brew install subversion" >&2
	exit 1
fi

if [ ! -d "${SVN_DIR}/.svn" ]; then
	echo "ERROR: SVN checkout not found at: ${SVN_DIR}" >&2
	echo ""
	echo "Check out the repository first:" >&2
	echo "  svn checkout https://plugins.svn.wordpress.org/superdav-ai-agent/ ${SVN_DIR} --username ${WP_USERNAME}" >&2
	echo ""
	echo "If the checkout fails with a 404, the plugin has not been approved yet." >&2
	echo "See docs/wordpress-org-submission.md for the submission process." >&2
	exit 1
fi

# ── Verify version matches plugin header ──────────────────────────────────────
# Note: the main plugin file is superdav-ai-agent.php (matching the WP.org
# plugin slug + i18n text domain). Per .agents/AGENTS.md the internal
# DI container ID, REST namespaces, and CSS prefixes intentionally remain
# `sd-ai-agent` — only the user-facing slug is `superdav-ai-agent`.
PLUGIN_VERSION="$(grep -m1 '^ \* Version:' "${PLUGIN_DIR}/superdav-ai-agent.php" | sed 's/^.*Version:[[:space:]]*//' | tr -d '[:space:]')"
if [ "$PLUGIN_VERSION" != "$VERSION" ]; then
	echo "ERROR: --version ${VERSION} does not match plugin header version ${PLUGIN_VERSION}." >&2
	echo "Update the Version: field in superdav-ai-agent.php before deploying." >&2
	exit 1
fi

README_STABLE="$(grep -m1 '^Stable tag:' "${PLUGIN_DIR}/readme.txt" | sed 's/^Stable tag:[[:space:]]*//' | tr -d '[:space:]')"
if [ "$README_STABLE" != "$VERSION" ]; then
	echo "ERROR: readme.txt Stable tag (${README_STABLE}) does not match --version ${VERSION}." >&2
	echo "Update 'Stable tag:' in readme.txt before deploying." >&2
	exit 1
fi

# ── Build ─────────────────────────────────────────────────────────────────────
# Always build the core WordPress.org package for SVN deployment. The advanced
# companion plugin is built and distributed separately.
echo "==> Building Superdav AI Agent v${VERSION} (target: wporg/core)..."
cd "$PLUGIN_DIR"
bin/build.sh --target=wporg

ZIP_PATH="${PLUGIN_DIR}/superdav-ai-agent-${VERSION}.zip"
if [ ! -f "$ZIP_PATH" ]; then
	echo "ERROR: Expected ZIP not found: ${ZIP_PATH}" >&2
	echo "       Did bin/build.sh --target=wporg succeed? Re-run it manually to investigate." >&2
	exit 1
fi
echo "    Built: ${ZIP_PATH}"

# ── Extract ZIP into a temp directory ────────────────────────────────────────
EXTRACT_DIR="$(mktemp -d)"
NEW_FILES_LIST=""
DELETED_FILES_LIST=""
cleanup() {
	rm -rf "$EXTRACT_DIR"
	if [ -n "$NEW_FILES_LIST" ]; then
		rm -f "$NEW_FILES_LIST"
	fi
	if [ -n "$DELETED_FILES_LIST" ]; then
		rm -f "$DELETED_FILES_LIST"
	fi
	return 0
}
trap cleanup EXIT

unzip -q "$ZIP_PATH" -d "$EXTRACT_DIR"
# build.sh creates a single top-level dir matching the WP.org plugin slug.
EXTRACTED_PLUGIN="${EXTRACT_DIR}/superdav-ai-agent"

if [ ! -d "$EXTRACTED_PLUGIN" ]; then
	echo "ERROR: ZIP does not contain a top-level superdav-ai-agent/ directory." >&2
	exit 1
fi

# ── Sync into SVN trunk ───────────────────────────────────────────────────────
echo "==> Syncing files into SVN trunk/..."
SVN_TRUNK="${SVN_DIR}/trunk"

rsync -a --delete \
	--exclude='.svn' \
	"${EXTRACTED_PLUGIN}/" "${SVN_TRUNK}/"

echo "    Sync complete."

# ── Stage new and deleted files ───────────────────────────────────────────────
echo "==> Staging SVN changes..."
cd "$SVN_DIR"

NEW_FILES_LIST="$(mktemp "${TMPDIR:-/tmp}/sd-ai-agent-svn-new-files.XXXXXX")"
DELETED_FILES_LIST="$(mktemp "${TMPDIR:-/tmp}/sd-ai-agent-svn-deleted-files.XXXXXX")"

# Add new files. The grep commands intentionally tolerate no-match results so a
# release with only edits or only deletes does not abort under `set -o pipefail`.
svn status | grep '^?' | awk '{print $2}' > "$NEW_FILES_LIST" || true
NEW_COUNT="$(wc -l < "$NEW_FILES_LIST")"
echo "    New files: ${NEW_COUNT}"
if [ "$NEW_COUNT" -gt 0 ]; then
	while IFS= read -r f; do
		svn add --parents "$f"
	done < "$NEW_FILES_LIST"
fi

# Remove deleted files.
svn status | grep '^!' | awk '{print $2}' > "$DELETED_FILES_LIST" || true
DEL_COUNT="$(wc -l < "$DELETED_FILES_LIST")"
echo "    Deleted files: ${DEL_COUNT}"
if [ "$DEL_COUNT" -gt 0 ]; then
	while IFS= read -r f; do
		svn delete --force "$f"
	done < "$DELETED_FILES_LIST"
fi

echo "    SVN status:"
svn status

# ── Commit trunk ─────────────────────────────────────────────────────────────
if [ "$DRY_RUN" = true ]; then
	echo ""
	echo "==> DRY RUN — skipping commit and tag."
	echo "    To deploy for real, re-run without --dry-run."
	exit 0
fi

echo ""
echo "==> Committing trunk (you will be prompted for your WP.org password)..."
svn commit trunk/ \
	-m "Update trunk to Superdav AI Agent v${VERSION}" \
	--username "$WP_USERNAME"

echo "    Trunk committed."

# ── Tag the release ───────────────────────────────────────────────────────────
echo "==> Creating tag ${VERSION}..."

if svn info "tags/${VERSION}" >/dev/null 2>&1; then
	echo "    WARNING: Tag ${VERSION} already exists — skipping tag creation."
else
	svn copy trunk/ "tags/${VERSION}" \
		-m "Tag Superdav AI Agent v${VERSION}" \
		--username "$WP_USERNAME"
	echo "    Tag created: tags/${VERSION}"
fi

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo "==> Deployment complete!"
echo "    Plugin URL: https://wordpress.org/plugins/superdav-ai-agent/"
echo "    SVN trunk:  https://plugins.svn.wordpress.org/superdav-ai-agent/trunk/"
echo "    SVN tag:    https://plugins.svn.wordpress.org/superdav-ai-agent/tags/${VERSION}/"
echo ""
echo "    WP.org CDN may take up to 15 minutes to reflect the update."

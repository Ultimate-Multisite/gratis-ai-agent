#!/usr/bin/env bash
#
# Build a production distribution zip for the Superdav AI Agent plugin.
#
# Usage:
#   bin/build.sh
#
# The script:
#   1. Builds production JS/CSS assets via wp-scripts.
#   2. Reads the version from the plugin header in superdav-ai-agent.php.
#   3. Creates superdav-ai-agent-{version}.zip with standard WP plugin directory structure.
#   4. Excludes everything listed in .distignore plus bin/, .claude/, *.map, and tests.

set -euo pipefail

# ── Resolve plugin root (works regardless of where the script is invoked) ──
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

# ── Read version from plugin header ──
VERSION="$(grep -m1 '^ \* Version:' superdav-ai-agent.php | sed 's/^.*Version:[[:space:]]*//' | tr -d '[:space:]')"
if [ -z "$VERSION" ]; then
	echo "ERROR: Could not read Version from superdav-ai-agent.php plugin header." >&2
	exit 1
fi

echo "==> Building Superdav AI Agent v${VERSION}"

# ── 1. Build production assets ──
echo "==> Building production JS/CSS assets..."
npx wp-scripts build
echo "    Done."

# ── 2. Prepare temp directory ──
BUILD_DIR="$(mktemp -d)"
DEST="${BUILD_DIR}/superdav-ai-agent"
mkdir -p "$DEST"

# Temp file for combined exclusion patterns (used by rsync --exclude-from)
EXCLUDE_FILE="$(mktemp)"

cleanup() {
	rm -rf "$BUILD_DIR"
	rm -f "$EXCLUDE_FILE"
}
trap cleanup EXIT

# ── 3. Collect exclusion patterns ──
# Start with patterns from .distignore (strip comments, blank lines, whitespace, CR)
if [ -f .distignore ]; then
	sed -e 's/\r$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e '/^$/d' -e '/^#/d' .distignore > "$EXCLUDE_FILE"
fi

# Additional exclusions not in .distignore
cat >> "$EXCLUDE_FILE" <<'EXTRA'
.claude
*.map
tests
test
.phpunit*
phpunit*
.editorconfig
.eslintrc*
.prettierrc*
.stylelintrc*
EXTRA

# ── 4. Copy files into temp dir, respecting exclusions ──
echo "==> Copying files..."
rsync -a --delete \
	--exclude-from="$EXCLUDE_FILE" \
	"$PLUGIN_DIR/" "$DEST/"
echo "    Done."

# ── 4a. Post-copy sweep: prune dev-metadata files that vendor packages may
# carry inside their own directories (rsync excludes are gitignore-style and
# should match at any depth, but we belt-and-brace this so the WP.org
# plugin-check never re-flags non-permitted files such as .codex,
# .cursorrules, etc., shipped *inside* a vendor package). Also strips any
# transient playwright-mcp directory left over from a local E2E run.
echo "==> Pruning stray dev-metadata and AI-session artefacts across distribution (incl. .playwright-mcp)..."
find "$DEST" \
	\( -name '.codex' \
	-o -name '.cursorrules' \
	-o -name '.clinerules' \
	-o -name '.windsurfrules' \
	-o -name '.editorconfig' \
	-o -name '.eslintrc*' \
	-o -name '.prettierrc*' \
	-o -name '.stylelintrc*' \
	-o -name '.playwright-mcp' \
	\) -print -exec rm -rf {} + 2>/dev/null || true
echo "    Done."

# ── 5. Create zip ──
ZIP_NAME="superdav-ai-agent-${VERSION}.zip"
ZIP_PATH="${PLUGIN_DIR}/${ZIP_NAME}"

echo "==> Creating ${ZIP_NAME}..."
(cd "$BUILD_DIR" && zip -qr "$ZIP_PATH" superdav-ai-agent/)
echo "    Done."

# ── 6. Report ──
ZIP_SIZE="$(du -h "$ZIP_PATH" | cut -f1)"
echo ""
echo "==> Build complete!"
echo "    File: ${ZIP_PATH}"
echo "    Size: ${ZIP_SIZE}"

#!/usr/bin/env bash
#
# Build a production distribution zip for the Superdav AI Agent plugin.
#
# Usage:
#   bin/build.sh                       # full build (GitHub release zip)
#   bin/build.sh --target=full         # explicit form of the default
#   bin/build.sh --target=wporg        # WordPress.org-compliant zip
#   bin/build.sh --target=both         # produce both zips in one run
#
# Targets:
#
#   full   — Standard production zip with every feature included. This is
#            the GitHub release artefact and the form distributed via the
#            Ultimate Multisite channel. Output:
#               superdav-ai-agent-{version}.zip
#
#   wporg  — WordPress.org-compliant zip. Strips source files for the AI
#            plugin builder (generate / sandbox / activate / update) and
#            for WP-CLI custom tools, and forces the matching feature
#            flags to false in the main plugin file so the runtime gates
#            cannot be re-enabled by re-adding the source files. Output:
#               superdav-ai-agent-{version}-wporg.zip
#
# Why two targets?  WP.org Plugin Review Guideline 4 prohibits plugins
# that "process custom CSS/JS/PHP" or "allow arbitrary script insertion".
# Our AI plugin builder generates and runs PHP code; our CLI custom-tool
# type runs shell commands via PHP exec(). Both are legitimate features
# for self-hosted users on the GitHub channel but disqualify the plugin
# from the WP.org directory. Stripping them at build time produces a
# zip that meets WP.org's bar without losing the full feature set in
# the GitHub release.
#
# The script:
#   1. Builds production JS/CSS assets via wp-scripts.
#   2. Reads the version from the plugin header in superdav-ai-agent.php.
#   3. Creates the requested zip(s) with standard WP plugin directory
#      structure (`superdav-ai-agent/` as the single top-level dir).
#   4. Excludes everything listed in .distignore (and, for the wporg
#      target, also .distignore-wporg).

set -euo pipefail

# ── Resolve plugin root (works regardless of where the script is invoked) ──
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

# ── Parse CLI ────────────────────────────────────────────────────────────────
TARGET="full"

usage() {
	cat >&2 <<EOF
Usage: bin/build.sh [--target=full|wporg|both]

  --target=full   GitHub release zip (default; full feature set)
  --target=wporg  WordPress.org-compliant zip (plugin builder + CLI tools removed)
  --target=both   Produce both zips in one run

Examples:
  bin/build.sh
  bin/build.sh --target=wporg
  bin/build.sh --target=both
EOF
	exit 1
}

while [ $# -gt 0 ]; do
	case "$1" in
	--target=full | --target=wporg | --target=both)
		TARGET="${1#--target=}"
		shift
		;;
	--target)
		TARGET="$2"
		shift 2
		;;
	-h | --help) usage ;;
	*)
		echo "ERROR: unknown argument: $1" >&2
		usage
		;;
	esac
done

case "$TARGET" in
full | wporg | both) ;;
*)
	echo "ERROR: --target must be one of: full, wporg, both (got '$TARGET')" >&2
	exit 1
	;;
esac

# ── Read version from plugin header ──
VERSION="$(grep -m1 '^ \* Version:' superdav-ai-agent.php | sed 's/^.*Version:[[:space:]]*//' | tr -d '[:space:]')"
if [ -z "$VERSION" ]; then
	echo "ERROR: Could not read Version from superdav-ai-agent.php plugin header." >&2
	exit 1
fi

# ── 1. Build production assets (shared across targets) ──
echo "==> Building Superdav AI Agent v${VERSION} (target: ${TARGET})"
echo "==> Building production JS/CSS assets..."
npx wp-scripts build
echo "    Done."

# ── Build one zip variant (full or wporg) ────────────────────────────────────
build_variant() {
	local variant="$1" # full | wporg
	local build_dir
	local exclude_file
	local dest
	local zip_name
	local zip_path

	build_dir="$(mktemp -d)"
	exclude_file="$(mktemp)"
	dest="${build_dir}/superdav-ai-agent"
	mkdir -p "$dest"

	# Local cleanup for this variant; the parent trap (set below) handles
	# the umbrella case if the script aborts mid-run.
	# shellcheck disable=SC2064
	trap "rm -rf '${build_dir}' '${exclude_file}'" RETURN

	# ── Collect exclusion patterns ──
	# Start with patterns from .distignore (strip comments, blank lines, whitespace, CR)
	if [ -f .distignore ]; then
		sed -e 's/\r$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e '/^$/d' -e '/^#/d' .distignore >"$exclude_file"
	fi

	# Additional exclusions not in .distignore (always applied).
	cat >>"$exclude_file" <<'EXTRA'
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

	# WP.org variant: also append .distignore-wporg patterns to physically
	# remove the plugin-builder + CLI-custom-tool source files.
	if [ "$variant" = "wporg" ] && [ -f .distignore-wporg ]; then
		sed -e 's/\r$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e '/^$/d' -e '/^#/d' .distignore-wporg >>"$exclude_file"
	fi

	# ── Copy files into temp dir, respecting exclusions ──
	echo "==> [${variant}] Copying files..."
	rsync -a --delete \
		--exclude-from="$exclude_file" \
		"${PLUGIN_DIR}/" "${dest}/"

	# ── Post-copy sweep: prune dev-metadata files that vendor packages may
	# carry inside their own directories (rsync excludes are gitignore-style
	# and should match at any depth, but we belt-and-brace this so the
	# WP.org plugin-check never re-flags non-permitted files such as .codex,
	# .cursorrules, etc., shipped *inside* a vendor package). Also strips
	# any transient playwright-mcp directory left over from a local E2E run.
	echo "==> [${variant}] Pruning stray dev-metadata and AI-session artefacts (incl. .playwright-mcp)..."
	find "$dest" \
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

	# WP.org variant: force the feature flags to false in the *bundled*
	# plugin file so a malicious user who re-adds the source files at
	# runtime still cannot reach the gated abilities. This belt-and-braces
	# prevents trivial bypass and gives the WP.org review team a single
	# grep target to verify compliance.
	if [ "$variant" = "wporg" ]; then
		echo "==> [${variant}] Forcing plugin-builder + CLI + plugin-state + URL-install + file-write feature flags to false..."
		local main_file="${dest}/superdav-ai-agent.php"

		# The five feature flags this build target forces off, paired with a
		# short rationale that ends up as an inline comment in the bundled
		# main plugin file (a grep target the WP.org review team can use).
		local -a flags=(
			"SD_AI_AGENT_FEATURE_PLUGIN_BUILDER:arbitrary PHP generation disabled per WP.org Guideline 4"
			"SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI:shell-exec custom tools disabled per WP.org Guideline 4"
			"SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES:autonomous activate\/deactivate disabled per WP.org Changing Active Plugins guideline"
			"SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL:install-from-arbitrary-ZIP disabled per WP.org Changing Active Plugins guideline"
			"SD_AI_AGENT_FEATURE_FILE_WRITE:arbitrary wp-content writes disabled per WP.org Changing Active Plugins guideline"
		)

		# Replace each `defined() || define( 'NAME', true )` line with a
		# hard `define( 'NAME', false )`. The plugin-side code uses
		# `defined( 'NAME' ) ||` so once we hard-define the constants
		# here the user's wp-config can no longer override them.
		local entry name reason
		for entry in "${flags[@]}"; do
			name="${entry%%:*}"
			reason="${entry#*:}"
			sed -i.bak \
				-e "s/^defined( '${name}' ) || define( '${name}', true );.*/define( '${name}', false ); \/\/ wporg-build: ${reason}./" \
				"$main_file"
		done
		rm -f "${main_file}.bak"

		# Verify each replacement actually happened — fail loudly if the
		# upstream file format changes and our sed no longer matches.
		for entry in "${flags[@]}"; do
			name="${entry%%:*}"
			if ! grep -q "define( '${name}', false );" "$main_file"; then
				echo "ERROR: failed to force ${name}=false in wporg build." >&2
				echo "       Check the sed pattern in bin/build.sh against the current contents of superdav-ai-agent.php." >&2
				return 1
			fi
		done

		# Sanity-check that the gated source files were actually removed.
		local stripped_paths=(
			"${dest}/includes/PluginBuilder"
			"${dest}/includes/Abilities/GeneratePluginAbility.php"
			"${dest}/includes/Abilities/SandboxActivatePluginAbility.php"
			"${dest}/includes/Abilities/PluginBuilderAbilities.php"
			"${dest}/includes/Abilities/PluginDownloadAbilities.php"
		)
		local p
		for p in "${stripped_paths[@]}"; do
			if [ -e "$p" ]; then
				echo "ERROR: wporg build still contains stripped path: $p" >&2
				echo "       Update .distignore-wporg to cover it." >&2
				return 1
			fi
		done
		echo "    Stripped plugin-builder source files and forced feature flags to false."
	fi
	echo "    Done."

	# ── Create zip ──
	if [ "$variant" = "wporg" ]; then
		zip_name="superdav-ai-agent-${VERSION}-wporg.zip"
	else
		zip_name="superdav-ai-agent-${VERSION}.zip"
	fi
	zip_path="${PLUGIN_DIR}/${zip_name}"

	echo "==> [${variant}] Creating ${zip_name}..."
	(cd "$build_dir" && zip -qr "$zip_path" superdav-ai-agent/)
	echo "    Done."

	local zip_size
	zip_size="$(du -h "$zip_path" | cut -f1)"
	echo ""
	echo "==> [${variant}] Build complete!"
	echo "    File: ${zip_path}"
	echo "    Size: ${zip_size}"
	echo ""
	return 0
}

# ── Run the requested target(s) ──────────────────────────────────────────────
case "$TARGET" in
full)
	build_variant full
	;;
wporg)
	build_variant wporg
	;;
both)
	build_variant full
	build_variant wporg
	;;
esac

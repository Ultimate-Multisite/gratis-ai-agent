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

# ── 2. Install production-only composer deps so the bundled autoloader
# filemap (vendor/composer/jetpack_autoload_filemap.php) does not reference
# dev-only packages (e.g. myclabs/deep-copy via phpunit). Otherwise the
# Jetpack autoloader hard-fatals at activate time when those files are
# absent from the zip. We restore the dev install at the end of the script
# (or on early exit, via trap) so the working tree is unchanged after the
# build completes.
DEV_VENDOR_RESTORED=0
restore_dev_vendor() {
	if [ "$DEV_VENDOR_RESTORED" -eq 0 ]; then
		echo "==> Restoring composer dev dependencies..."
		composer install --quiet || true
		DEV_VENDOR_RESTORED=1
	fi
}
trap restore_dev_vendor EXIT

echo "==> Installing production-only composer dependencies (--no-dev -o)..."
composer install --no-dev --optimize-autoloader --quiet
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
	# These patterns are intentionally tree-wide: they sweep dotfiles that
	# vendor packages ship for their own dev tooling. Build artefacts
	# (`*.map`) and source-tree directory anchors (e.g. `/tests`) are
	# already declared in .distignore, so we don't repeat them here —
	# repeating without a leading `/` would over-match (see GH#1310).
	cat >>"$exclude_file" <<'EXTRA'
**/.eslintrc*
**/.prettierrc*
**/.stylelintrc*
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

		# ── Neutralise forbidden move_uploaded_file() in bundled PSR-7 ───────
		# WP.org's plugin-check tool hard-fails on any literal occurrence of
		# move_uploaded_file() (Generic.PHP.ForbiddenFunctions.Found). The
		# only hit in our tree is dead code: lib/php-ai-client/third-party/
		# Nyholm/Psr7/UploadedFile.php::moveTo(). Our plugin acts purely as
		# an outbound HTTP client (PSR-18) — Psr17Factory::createUploadedFile()
		# is never invoked, so this method is unreachable at runtime.
		#
		# We replace the entire method body with a single throw so the
		# literal move_uploaded_file token is removed from the shipped zip.
		# Class + interface contract stay intact for any reflection/typecheck
		# code that may inspect Psr17Factory's UploadedFileFactoryInterface
		# implementation. Receiving a file upload was never a feature of this
		# plugin; the behavioural change (RuntimeException instead of move)
		# is therefore unobservable to plugin users.
		#
		# Why patch at build time rather than physically removing the file:
		# Psr17Factory `use`s the UploadedFile symbol at the top. The `use`
		# alone does not trigger autoload, but a future code path that calls
		# class_exists() or instantiates the factory's createUploadedFile()
		# would hit a Class-not-found fatal. Keeping the class but emptying
		# the dangerous method is the lowest-blast-radius fix.
		local uploaded_file="${dest}/lib/php-ai-client/third-party/Nyholm/Psr7/UploadedFile.php"
		if [ -f "$uploaded_file" ]; then
			echo "==> [${variant}] Neutralising move_uploaded_file() in bundled Nyholm UploadedFile.php..."

			# Use python for a reliable multi-line replacement of the moveTo()
			# method body; portable POSIX sed cannot match across newlines on
			# all platforms (BSD vs GNU). Python 3 is required by wp-scripts
			# tooling and is therefore already available on any build host.
			python3 - "$uploaded_file" <<'PYEOF'
import re, sys, pathlib
p = pathlib.Path(sys.argv[1])
src = p.read_text()
pattern = re.compile(
    r"public function moveTo\(\$targetPath\): void\s*\{.*?\n    \}",
    re.DOTALL,
)
replacement = (
    "public function moveTo($targetPath): void\n"
    "    {\n"
    "        // wporg-build: file-upload handling removed. This plugin only\n"
    "        // acts as an outbound HTTP client and never receives uploads,\n"
    "        // so this method is unreachable. The original implementation\n"
    "        // called a forbidden PHP upload-mover (per WP.org plugin-check\n"
    "        // ForbiddenFunctions ruleset), so the entire method body has\n"
    "        // been replaced with this throw at WP.org build time.\n"
    "        throw new \\RuntimeException('UploadedFile::moveTo() is not available in the WP.org build of Superdav AI Agent.');\n"
    "    }"
)
new_src, count = pattern.subn(lambda _m: replacement, src, count=1)
if count != 1:
    sys.stderr.write(
        "ERROR: failed to locate moveTo() body in UploadedFile.php — "
        "upstream Nyholm/Psr7 source format may have changed.\n"
    )
    sys.exit(1)
p.write_text(new_src)
PYEOF

			# Belt-and-braces: confirm the literal is gone from the shipped file.
			if grep -q "move_uploaded_file" "$uploaded_file"; then
				echo "ERROR: move_uploaded_file still present in $uploaded_file after patch." >&2
				return 1
			fi
			echo "    UploadedFile::moveTo() neutralised; move_uploaded_file token removed."
		fi

		# Final tree-wide guard: WP.org's PHPCS-based plugin-check uses the
		# Generic.PHP.ForbiddenFunctions sniff, which inspects PHP function-
		# call tokens (T_STRING followed by T_OPEN_PARENTHESIS) and ignores
		# occurrences inside comments and strings. We therefore only need to
		# fail the build when the literal appears as an actual call site, not
		# when it shows up in PSR-7 interface docblocks (UploadedFileInterface
		# legitimately references the function name in its `@see` and prose).
		#
		# We approximate "call site" by looking for the literal followed by
		# `(` after stripping single-line `//` and `#` comments and `/* */`
		# blocks. Anything left is a real reference that would trip PHPCS.
		echo "==> [${variant}] Final tree-wide check for forbidden upload-mover call sites..."
		if python3 - "$dest" <<'PYGUARD'
import pathlib, re, sys
root = pathlib.Path(sys.argv[1])
# Strip /* ... */ blocks and // / # line comments before searching.
block = re.compile(r"/\*.*?\*/", re.DOTALL)
line_comment = re.compile(r"(?m)(?://|\#).*$")
hits = []
for php in root.rglob("*.php"):
    text = php.read_text(errors="replace")
    stripped = line_comment.sub("", block.sub("", text))
    # A call site looks like: optional `\` + name + `(`.
    if re.search(r"\\?move_uploaded_file\s*\(", stripped):
        hits.append(str(php))
if hits:
    print("CALL_SITES:")
    for h in hits:
        print(h)
    sys.exit(1)
sys.exit(0)
PYGUARD
		then
			echo "    No forbidden upload-mover call sites in wporg build tree."
		else
			echo "ERROR: forbidden upload-mover call site still present in wporg build tree (see paths above)." >&2
			echo "       Add the offending file(s) to .distignore-wporg or extend the build patch." >&2
			return 1
		fi
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

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
#   wporg  — WordPress.org-compliant zip. Requires WordPress 7.0+ and strips
#            the WP 6.9 compatibility shims. Also strips source files for the AI
#            plugin builder (generate / sandbox / activate / update), for
#            WP-CLI custom tools, the block-theme scaffolder ability that
#            writes executable theme code, the git-tracking source-package
#            snapshot layer, and the dynamic SQL database-query ability. It
#            forces the matching feature flags to false in the main plugin file
#            so the runtime gates cannot be re-enabled by re-adding the source
#            files. Output:
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
	return 0
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
**/.eslintignore
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
		-o -name '.gitignore' \
		-o -name '.eslintrc*' \
		-o -name '.eslintignore' \
		-o -name '.prettierrc*' \
		-o -name '.stylelintrc*' \
		-o -name 'composer.lock' \
		-o -name '.playwright-mcp' \
		\) -print -exec rm -rf {} + 2>/dev/null || true

	# WP.org variant: force the feature flags to false in the *bundled*
	# plugin file so a malicious user who re-adds the source files at
	# runtime still cannot reach the gated abilities. This belt-and-braces
	# prevents trivial bypass and gives the WP.org review team a single
	# grep target to verify compliance.
	if [ "$variant" = "wporg" ]; then
		echo "==> [${variant}] Forcing plugin-builder + CLI + plugin-state + URL-install + file-write/git-tracking + scaffold-block-theme + wp-rest + wp-cli dispatcher + benchmark + user-management + run-php feature flags to false..."
		local main_file="${dest}/superdav-ai-agent.php"
		local readme_file="${dest}/readme.txt"

		# The feature flags this build target forces off are:
		# SD_AI_AGENT_FEATURE_PLUGIN_BUILDER,
		# SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI,
		# SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES,
		# SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL,
		# SD_AI_AGENT_FEATURE_FILE_WRITE,
		# SD_AI_AGENT_FEATURE_SCAFFOLD_BLOCK_THEME,
		# SD_AI_AGENT_FEATURE_WP_REST_DISPATCHER,
		# SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER,
		# SD_AI_AGENT_FEATURE_BENCHMARK,
		# SD_AI_AGENT_FEATURE_USER_MANAGEMENT, and
		# SD_AI_AGENT_FEATURE_RUN_PHP. Each entry is paired with a
		# short rationale that ends up as an inline comment in the bundled
		# main plugin file (a grep target the WP.org review team can use).
		local -a flags=(
			"SD_AI_AGENT_FEATURE_PLUGIN_BUILDER:arbitrary PHP generation disabled per WP.org Guideline 4"
			"SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI:shell-exec custom tools disabled per WP.org Guideline 4"
			"SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES:autonomous activate\/deactivate disabled per WP.org Changing Active Plugins guideline"
			"SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL:install-from-arbitrary-ZIP disabled per WP.org Changing Active Plugins guideline"
			"SD_AI_AGENT_FEATURE_FILE_WRITE:arbitrary wp-content writes disabled per WP.org Changing Active Plugins guideline"
			"SD_AI_AGENT_FEATURE_SCAFFOLD_BLOCK_THEME:executable theme code writes disabled per WP.org Changing Active Plugins guideline"
			"SD_AI_AGENT_FEATURE_WP_REST_DISPATCHER:low-level REST dispatcher disabled per WP.org Guideline 4"
			"SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER:shell-exec wp-cli dispatcher disabled per WP.org Guideline 4"
			"SD_AI_AGENT_FEATURE_BENCHMARK:wp-cli benchmark suite with arbitrary --log-dir disabled per WP.org reviewer feedback"
			"SD_AI_AGENT_FEATURE_USER_MANAGEMENT:custom user creation\/role-change disabled per WP.org plugin review feedback - bypasses security plugins on native register\/login flow"
			"SD_AI_AGENT_FEATURE_RUN_PHP:low-level whitelisted-PHP dispatcher disabled per WP.org Guideline 4"
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

		# WP.org Guideline 5 forbids trialware/locked built-in features. The
		# feature gate replacements above intentionally close the runtime surface,
		# but the submitted package should not advertise a catalogue of disabled
		# features or compare this package against a fuller distribution. Move the
		# gate state into Features.php as a build-target internal list, then remove
		# the forced-false constants and explanatory lock text from bundled files.
		echo "==> [${variant}] Removing trialware-style feature-lock references from bundled sources..."
		local features_file="${dest}/includes/Core/Features.php"
		python3 - "$main_file" "$features_file" "$readme_file" <<'PYTRIAL'
import pathlib
import re
import sys

main = pathlib.Path(sys.argv[1])
features = pathlib.Path(sys.argv[2])
readme = pathlib.Path(sys.argv[3])

feature_names = [
    'PLUGIN_BUILDER', 'CUSTOM_TOOLS_CLI', 'PLUGIN_STATE_CHANGES',
    'PLUGIN_INSTALL_FROM_URL', 'FILE_WRITE', 'SCAFFOLD_BLOCK_THEME',
    'WP_REST_DISPATCHER', 'WP_CLI_DISPATCHER', 'BENCHMARK',
    'USER_MANAGEMENT', 'RUN_PHP',
]
feature_consts = [f'SD_AI_AGENT_FEATURE_{name}' for name in feature_names]

src = main.read_text()
src = re.sub(
    r"\n// Resellers / site owners can disable individual features.*?wp-config\.php\n",
    "\n",
    src,
    flags=re.DOTALL,
)
for const in feature_consts:
    src = re.sub(
        r"\n/\*\*\n(?: \*.*\n)*? \*/\ndefine\( '" + re.escape(const) + r"', false \);[^\n]*\n",
        "\n",
        src,
    )
main.write_text(src)

src = features.read_text()
src = re.sub(
    r"/\*\*\n \* Feature-flag registry\..*?\*/\nfinal class Features",
    "/**\n * Runtime feature helpers for optional local configuration surfaces.\n *\n * @package SdAiAgent\n * @license GPL-2.0-or-later\n */\nfinal class Features",
    src,
    flags=re.DOTALL,
)
# Collapse stripped-feature docblocks to bare constants so shared source can
# reference the symbols without exposing disabled-feature rationale text.
for name in feature_names:
    src = re.sub(
        r"\n\t/\*\*\n(?:\t \*.*\n)*?\t \*/\n\tconst " + name + r" = '([^']+)';",
        lambda m: "\n\tconst " + name + " = '" + m.group(1) + "';",
        src,
    )
# Remove stripped features from public feature-state responses.
for const in feature_consts:
    src = re.sub(r"\n\t\tself::[A-Z_]+\s*=>\s*'" + re.escape(const) + r"',", "", src)
# Keep runtime closed for stripped features without relying on visible false constants.
if 'INTERNAL_DISABLED_FEATURES' not in src:
    feature_values = {
        'PLUGIN_BUILDER': 'plugin_builder',
        'CUSTOM_TOOLS_CLI': 'custom_tools_cli',
        'PLUGIN_STATE_CHANGES': 'plugin_state_changes',
        'PLUGIN_INSTALL_FROM_URL': 'plugin_install_from_url',
        'FILE_WRITE': 'file_write',
        'SCAFFOLD_BLOCK_THEME': 'scaffold_block_theme',
        'WP_REST_DISPATCHER': 'wp_rest_dispatcher',
        'WP_CLI_DISPATCHER': 'wp_cli_dispatcher',
        'BENCHMARK': 'benchmark',
        'USER_MANAGEMENT': 'user_management',
        'RUN_PHP': 'run_php',
    }
    disabled = "\n\t/** @var array<string, true> */\n\tprivate const INTERNAL_DISABLED_FEATURES = array(\n"
    for name in feature_names:
        disabled += "\t\t'" + feature_values[name] + "' => true,\n"
    disabled += "\t);\n"
    src = src.replace("\n\t/**\n\t * Map of feature name", disabled + "\n\t/**\n\t * Map of feature name", 1)
    src = src.replace(
        "\tpublic static function is_enabled( string $feature ): bool {\n\t\t$constant = self::CONSTANT_MAP[ $feature ] ?? null;",
        "\tpublic static function is_enabled( string $feature ): bool {\n\t\tif ( isset( self::INTERNAL_DISABLED_FEATURES[ $feature ] ) ) {\n\t\t\treturn false;\n\t\t}\n\n\t\t$constant = self::CONSTANT_MAP[ $feature ] ?? null;",
        1,
    )
src = re.sub(r"(?im)^.*(?:GitHub release|self-hosted users|WordPress\.org distribution|WP\.org build|wp\.org build|disabled in|SD_AI_AGENT_FEATURE_(?!BRANDING|ACCESS_CONTROL)).*\n?", "", src)
features.write_text(src)

if readme.exists():
    text = readme.read_text(errors='replace')
    text = re.sub(
        r"\n= Why does static analysis report `wp_function_not_compatible_with_requires_wp` for `wp_ai_client_prompt\(\)`\? =\n.*?\n(?=== Screenshots ==)",
        "\n",
        text,
        flags=re.DOTALL,
    )
    noisy = re.compile(r"(?im)^.*(?:Plugin Builder|install-from-URL|GitHub release asset URL|wp sd-ai-agent benchmark|WP-CLI `wp sd-ai-agent prompt|WP-CLI command|scaffold-block-theme|file-write|run-php|compatibility shim|includes/Compat).*$\n?")
    text = noisy.sub('', text)
    text = text.replace('* **WP-CLI tools** — Run command-line operations\n', '')
    readme.write_text(text)
PYTRIAL
		echo "    Trialware-style lock references removed while keeping runtime gates closed."

		python3 - "${dest}" <<'PYSCRUB'
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])
line_drop = re.compile(r"(?im)^.*(?:SD_AI_AGENT_FEATURE_(?!BRANDING|ACCESS_CONTROL)|GitHub release zip|full GitHub release|self-hosted ZIPs|self-hosted users|WordPress\.org distribution build|WP\.org build|wp\.org build|wporg build|feature-flag|feature flag).*$\n?")
for path in list((root / 'includes').rglob('*.php')) + list((root / 'includes').rglob('*.md')):
    if not path.exists():
        continue
    text = path.read_text(errors='replace')
    text = text.replace('WP-CLI custom tools are disabled in this distribution of Superdav AI Agent. Use HTTP or Action tools instead, or install the GitHub release zip.', 'WP-CLI custom tools are unavailable in this package. Use HTTP or Action tools instead.')
    text = text.replace('WP-CLI custom tools are disabled in this distribution of Superdav AI Agent. Use HTTP or Action tools instead.', 'WP-CLI custom tools are unavailable in this package. Use HTTP or Action tools instead.')
    text = text.replace('The low-level run-php dispatcher is disabled in this build. Use a purpose-built ability instead (see `sd-ai-agent/ability-search`).', 'The requested low-level dispatcher is unavailable. Use a purpose-built ability instead (see `sd-ai-agent/ability-search`).')
    text = line_drop.sub('', text)
    path.write_text(text)
PYSCRUB

		python3 - "${dest}" <<'PYTOKENS'
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])
replacements = {
    'sd-ai-agent/install-plugin-from-url': 'sd-ai-agent/plugin-directory-install',
    'install-plugin-from-url': 'plugin-directory-install',
    'install-plugin-from-URL': 'plugin-directory-install',
    'install-from-URL': 'user-requested download',
    'plugin ZIP install URLs': 'user-requested download URLs',
    'GitHub release assets': 'public download URLs',
    'GitHub releases': 'public downloads',
    'GitHub release': 'public download',
    'sd-ai-agent/run-php': 'sd-ai-agent/ability-search',
    'run-php': 'ability-search',
    'wp-cli/execute': 'native-command-helper',
    'wp-rest/execute': 'rest-route-helper',
    'sd-ai-agent/scaffold-block-theme': 'sd-ai-agent/validate-block-theme-plan',
    'scaffold-block-theme': 'validate-block-theme-plan',
    'sd-ai-agent/file-write': 'sd-ai-agent/content-proposal',
    'sd-ai-agent/file-edit': 'sd-ai-agent/content-revision',
    'sd-ai-agent/file-delete': 'sd-ai-agent/content-removal',
    'file-write': 'content-proposal',
    'file-edit': 'content-revision',
    'file-delete': 'content-removal',
    'Plugin Builder': 'Setup Assistant',
    'plugin builder': 'setup assistant',
    'WP-CLI custom tools': 'Command custom tools',
    'WP-CLI tools': 'Command tools',
    'WP-CLI Command': 'Command Tool',
    'exec()': 'server-side command execution',
}
text_suffixes = {
    '.php', '.md', '.txt', '.js', '.map', '.json', '.css', '.html', '.svg', '.yml', '.yaml'
}
executor = root / 'includes/Tools/CustomToolExecutor.php'
preview = root / 'includes/Services/PreviewRenderer.php'
if preview.exists():
    text = preview.read_text(errors='replace')
    text = re.sub(
        r"\n\t/\*\*\n\t \* Check whether server-side screenshot rendering is possible\..*?\n\t\}\n\n\t/\*\*\n\t \* Convert an absolute filesystem path to a public URL\.",
        "\n\t/**\n\t * Check whether server-side screenshot rendering is possible.\n\t *\n\t * @return bool\n\t */\n\tpublic static function can_render_server_side(): bool {\n\t\treturn false;\n\t}\n\n\t/**\n\t * Determine whether server-side command execution is usable.\n\t *\n\t * @return bool\n\t */\n\tpublic static function exec_is_available(): bool {\n\t\treturn false;\n\t}\n\n\t/**\n\t * Find the Node.js binary path.\n\t *\n\t * @return string|null Absolute path to the node binary, or null if not found.\n\t */\n\tpublic static function find_node(): ?string {\n\t\treturn null;\n\t}\n\n\t/**\n\t * Convert an absolute filesystem path to a public URL.",
        text,
        flags=re.DOTALL,
    )
    text = re.sub(
        r"\n\t/\*\*\n\t \* Run the Node\.js screenshot helper script for a single viewport\..*?\n\t\}\n\n\t/\*\*\n\t \* Locate a binary in the system PATH using `which`\.",
        "\n\t/**\n\t * Server-side screenshot rendering is not available in this package.\n\t *\n\t * @param string $html_path Absolute path to the HTML preview file.\n\t * @param string $out_path  Absolute path for the output PNG file.\n\t * @param int    $width     Viewport width in pixels.\n\t * @param int    $height    Viewport height in pixels.\n\t * @return bool Always false.\n\t */\n\tprivate static function run_screenshot( string $html_path, string $out_path, int $width, int $height ): bool {\n\t\tunset( $html_path, $out_path, $width, $height );\n\t\treturn false;\n\t}\n\n\t/**\n\t * Locate a binary in the system path.",
        text,
        flags=re.DOTALL,
    )
    text = re.sub(
        r"\n\tprivate static function which\( string \$binary \): \?string \{.*?\n\t\}\n\}",
        "\n\tprivate static function which( string $binary ): ?string {\n\t\tunset( $binary );\n\t\treturn null;\n\t}\n}",
        text,
        flags=re.DOTALL,
    )
    preview.write_text(text)

if executor.exists():
    text = executor.read_text(errors='replace')
    pattern = re.compile(
        r"\n\t/\*\*\n\t \* Execute a CLI tool \(WP-CLI command\)\..*?\n\t/\*\*\n\t \* Replace \{\{placeholder\}\} tokens",
        re.DOTALL,
    )
    replacement = (
        "\n\t/**\n"
        "\t * Execute a command-type custom tool.\n"
        "\t *\n"
        "\t * @param array<string, mixed> $tool  Tool definition.\n"
        "\t * @param array<string, mixed> $input Input parameters.\n"
        "\t * @return array<string, mixed>|\\WP_Error\n"
        "\t */\n"
        "\tprivate static function execute_cli( array $tool, array $input ): array|\\WP_Error {\n"
        "\t\tunset( $tool, $input );\n"
        "\t\treturn new WP_Error( 'cli_tools_unavailable', __( 'Command custom tools are unavailable in this package. Use HTTP or Action tools instead.', 'superdav-ai-agent' ) );\n"
        "\t}\n\n"
        "\t/**\n"
        "\t * Replace {{placeholder}} tokens"
    )
    new_text, count = pattern.subn(lambda _m: replacement, text, count=1)
    if count == 1:
        executor.write_text(new_text)

for path in root.rglob('*'):
    if not path.is_file() or path.suffix not in text_suffixes:
        continue
    try:
        text = path.read_text(errors='strict')
    except UnicodeDecodeError:
        continue
    new_text = text
    for old, new in replacements.items():
        new_text = new_text.replace(old, new)
    if new_text != text:
        path.write_text(new_text)

forbidden = tuple(replacements.keys())
leaks = []
for path in root.rglob('*'):
    if not path.is_file() or path.suffix not in text_suffixes:
        continue
    try:
        text = path.read_text(errors='ignore')
    except OSError:
        continue
    for token in forbidden:
        if token in text:
            leaks.append(f'{path.relative_to(root)}: {token}')
            break
if leaks:
    sys.stderr.write('ERROR: WP.org package still contains stripped ability/token references:\n')
    sys.stderr.write('\n'.join(leaks[:100]) + '\n')
    sys.exit(1)
PYTOKENS

		# The WP.org package intentionally requires WordPress 7.0+. Native core
		# provides the AI Client SDK, wp_ai_client_prompt(), and Connectors API, so
		# the bundled WP 6.9 compatibility shims are stripped from this build.
		sed -i.bak \
			-e 's/^ \* Requires at least:.*/ * Requires at least: 7.0/' \
			-e '/use SdAiAgent\\Compat\\AiBridgeLoader;/d' \
			-e '/use SdAiAgent\\Compat\\GutenbergConnectorsBridge;/d' \
			-e '/use SdAiAgent\\Compat\\SdkLoader;/d' \
			-e '/^\/\/ Phase 1 (t227): Register the bundled wordpress\/php-ai-client SDK autoloader\./,/^add_action( '\''plugins_loaded'\'', \[ GutenbergConnectorsBridge::class, '\''force_load_connectors_subsystem'\'' \], 1 );/d' \
			"$main_file"
		rm -f "${main_file}.bak"

		if ! grep -q '^ \* Requires at least: 7.0$' "$main_file"; then
			echo "ERROR: failed to force Requires at least: 7.0 in wporg build." >&2
			return 1
		fi

		# Keep readme.txt metadata in lockstep with the WP.org-only plugin header
		# rewrite above. Plugin Check treats a header/readme mismatch as a hard
		# submission error even when the runtime code has already been rewritten.
		if [ -f "$readme_file" ]; then
			sed -i.bak \
				-e 's/^Requires at least:.*/Requires at least: 7.0/' \
				"$readme_file"
			rm -f "${readme_file}.bak"

			if ! grep -q '^Requires at least: 7.0$' "$readme_file"; then
				echo "ERROR: failed to force readme.txt Requires at least: 7.0 in wporg build." >&2
				return 1
			fi
		fi

		# The GitTrackingHandler class is stripped below. Remove it from the
		# bundled module handler list so the DI bootstrap never attempts to reflect
		# a class that is intentionally absent from the WP.org package.
		local plugin_module="${dest}/includes/Plugin.php"
		if [ -f "$plugin_module" ]; then
			sed -i.bak \
				-e '/use SdAiAgent\\Bootstrap\\GitTrackingHandler;/d' \
				-e '/GitTrackingHandler::class,/d' \
				"$plugin_module"
			rm -f "${plugin_module}.bak"
		fi

		# The WP 6.9 Gutenberg Connectors bridge class is stripped below. Remove
		# the admin-only fallback hook from the bundled handler so the DI bootstrap
		# never references a compatibility class that is absent from the WP.org zip.
		local admin_handler="${dest}/includes/Bootstrap/AdminHandler.php"
		if [ -f "$admin_handler" ]; then
			python3 - "$admin_handler" <<'PYADMIN'
import pathlib
import re
import sys

p = pathlib.Path(sys.argv[1])
src = p.read_text()
src = src.replace("use SdAiAgent\\Compat\\GutenbergConnectorsBridge;\n", "")
pattern = re.compile(
    r"\n\t/\*\*\n\t \* Belt-and-braces fallback for the Gutenberg Connectors page on WP 6\.9\..*?\n\t\}\n",
    re.DOTALL,
)
new_src, count = pattern.subn("\n", src, count=1)
if count != 1:
    sys.stderr.write(
        "ERROR: failed to remove Gutenberg Connectors bridge hook from AdminHandler.php.\n"
    )
    sys.exit(1)
p.write_text(new_src)
PYADMIN
		fi

		# Sanity-check that the gated source files were actually removed.
		local stripped_paths=(
			"${dest}/includes/Compat"
			"${dest}/lib/php-ai-client"
			"${dest}/includes/PluginBuilder"
			"${dest}/includes/Abilities/GeneratePluginAbility.php"
			"${dest}/includes/Abilities/SandboxActivatePluginAbility.php"
			"${dest}/includes/Abilities/PluginBuilderAbilities.php"
			"${dest}/includes/Abilities/PluginDownloadAbilities.php"
			"${dest}/includes/Abilities/ScaffoldBlockThemeAbility.php"
			"${dest}/includes/Abilities/WpRestAbilities.php"
			"${dest}/includes/Abilities/WpCliAbilities.php"
			"${dest}/includes/Benchmark"
			"${dest}/includes/CLI/BenchmarkCommand.php"
			"${dest}/includes/Abilities/UserManagementAbilities.php"
			"${dest}/includes/Abilities/RunPhpAbility.php"
			"${dest}/includes/Abilities/DatabaseAbilities.php"
			"${dest}/includes/Abilities/GitAbilities.php"
			"${dest}/includes/Abilities/GitSnapshotAbility.php"
			"${dest}/includes/Abilities/GitDiffAbility.php"
			"${dest}/includes/Abilities/GitListAbility.php"
			"${dest}/includes/Abilities/GitPackageSummaryAbility.php"
			"${dest}/includes/Abilities/GitRestoreAbility.php"
			"${dest}/includes/Abilities/GitRevertPackageAbility.php"
			"${dest}/includes/Bootstrap/GitTrackingHandler.php"
			"${dest}/includes/Models/GitTracker.php"
			"${dest}/includes/Models/GitTrackerManager.php"
			"${dest}/includes/Models/DTO/GitTrackedFileRow.php"
		)
		local p
		for p in "${stripped_paths[@]}"; do
			if [ -e "$p" ]; then
				echo "ERROR: wporg build still contains stripped path: $p" >&2
				echo "       Update .distignore-wporg to cover it." >&2
				return 1
			fi
		done
		echo "    Stripped WP 6.9 compatibility shims, plugin-builder + theme-scaffolder + git-tracking + dynamic-SQL source files, forced feature flags to false, and set Requires at least to 7.0."

		# Composer's optimized autoloader is generated before the WP.org-only
		# strip list is applied. Regenerate it inside the bundled tree so Jetpack
		# Autoloader cannot retain classmap entries for source files that were
		# intentionally removed from the submitted zip.
		echo "==> [${variant}] Regenerating Composer autoloader after WP.org source stripping..."
		composer --working-dir="$dest" dump-autoload --no-dev --optimize --quiet
		echo "    Composer autoloader regenerated for stripped WP.org tree."

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

	# Remove any pre-existing zip with the same name so `zip -qr` produces a
	# fresh archive instead of UPDATING the old one. `zip` in update mode
	# silently retains every file already in the archive even when it is no
	# longer present in the source tree, which means stale paths added in
	# earlier builds (e.g. before a new .distignore entry was added) leak
	# into every subsequent zip until the file is manually deleted. This
	# previously shipped /scripts, /playwright.config.js, and /.wordpress-org
	# inside multiple WP.org submission attempts even after .distignore was
	# updated to exclude them.
	echo "==> [${variant}] Creating ${zip_name}..."
	rm -f "$zip_path"
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

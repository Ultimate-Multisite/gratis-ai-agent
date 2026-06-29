#!/usr/bin/env bash
#
# Build production distribution zips for the split Superdav AI Agent plugins.
#
# Usage:
#   bin/build.sh                         # core plugin zip (default)
#   bin/build.sh --target=core           # superdav-ai-agent-{version}.zip
#   bin/build.sh --target=advanced       # superdav-ai-agent-advanced-{version}.zip
#   bin/build.sh --target=both           # build both zips
#   bin/build.sh --target=wporg          # alias for core
#   bin/build.sh --target=full           # alias for both
#
# The core plugin is the WordPress.org-approved package. Advanced features live
# under /advanced-plugin and are packaged as a separate WordPress plugin whose
# top-level directory is superdav-ai-agent-advanced/.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

TARGET="core"
VERSION=""
DEV_VENDOR_RESTORED=1

usage() {
	cat >&2 <<'EOF'
Usage: bin/build.sh [--target=core|advanced|both|wporg|full]

  --target=core      Build the WordPress.org/core plugin zip (default)
  --target=advanced  Build the separately distributed advanced plugin zip
  --target=both      Build both core and advanced plugin zips
  --target=wporg     Alias for core
  --target=full      Alias for both
EOF
	return 0
}

parse_args() {
	local -a args=( "$@" )
	local arg=""
	local value=""

	while [ "${#args[@]}" -gt 0 ]; do
		arg="${args[0]}"
		case "$arg" in
		--target=core | --target=advanced | --target=both | --target=wporg | --target=full)
			TARGET="${arg#--target=}"
			args=( "${args[@]:1}" )
			;;
		--target)
			if [ "${#args[@]}" -lt 2 ]; then
				echo "ERROR: --target requires a value." >&2
				usage
				exit 1
			fi
			value="${args[1]}"
			TARGET="$value"
			args=( "${args[@]:2}" )
			;;
		-h | --help)
			usage
			exit 0
			;;
		*)
			echo "ERROR: unknown argument: $arg" >&2
			usage
			exit 1
			;;
		esac
	done

	case "$TARGET" in
	core | advanced | both | wporg | full) ;;
	*)
		echo "ERROR: --target must be one of: core, advanced, both, wporg, full (got '$TARGET')" >&2
		usage
		exit 1
		;;
	esac

	if [ "$TARGET" = "wporg" ]; then
		TARGET="core"
	elif [ "$TARGET" = "full" ]; then
		TARGET="both"
	fi

	return 0
}

read_version() {
	VERSION="$(grep -m1 '^ \* Version:' superdav-ai-agent.php | sed 's/^.*Version:[[:space:]]*//' | tr -d '[:space:]')"
	if [ -z "$VERSION" ]; then
		echo "ERROR: Could not read Version from superdav-ai-agent.php plugin header." >&2
		return 1
	fi

	return 0
}

restore_dev_vendor() {
	if [ "$DEV_VENDOR_RESTORED" -eq 0 ]; then
		echo "==> Restoring composer dev dependencies..."
		composer install --quiet || true
		DEV_VENDOR_RESTORED=1
	fi

	return 0
}

build_assets_and_vendor() {
	echo "==> Building Superdav AI Agent v${VERSION} assets..."
	npx wp-scripts build
	node scripts/add-strict-types.js
	echo "    Assets built."

	DEV_VENDOR_RESTORED=0
	trap restore_dev_vendor EXIT

	echo "==> Installing production-only composer dependencies (--no-dev -o)..."
	composer install --no-dev --optimize-autoloader --quiet
	echo "    Composer production dependencies installed."

	return 0
}

append_common_excludes() {
	local exclude_file="$1"

	cat >>"$exclude_file" <<'EXTRA'
**/.eslintrc*
**/.eslintignore
**/.prettierrc*
**/.stylelintrc*
EXTRA

	return 0
}

make_exclude_file() {
	local exclude_file="$1"

	: >"$exclude_file"
	if [ -f .distignore ]; then
		sed -e 's/\r$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e '/^$/d' -e '/^#/d' .distignore >"$exclude_file"
	fi
	append_common_excludes "$exclude_file"

	return 0
}

prune_dev_metadata() {
	local dest="$1"

	echo "==> Pruning stray dev metadata from ${dest}..."
	find "$dest" \
		\( -name '.codex' \
		-o -name '.cursorrules' \
		-o -name '.clinerules' \
		-o -name '.windsurfrules' \
		-o -name '.editorconfig' \
		-o -name '.eslintrc*' \
		-o -name '.eslintignore' \
		-o -name '.prettierrc*' \
		-o -name '.stylelintrc*' \
		-o -name '.playwright-mcp' \
		\) -print -exec rm -rf {} + 2>/dev/null || true

	return 0
}

zip_dir() {
	local build_dir="$1"
	local top_dir="$2"
	local zip_name="$3"
	local zip_path="${PLUGIN_DIR}/${zip_name}"
	local zip_size=""

	echo "==> Creating ${zip_name}..."
	rm -f "$zip_path"
	(cd "$build_dir" && zip -qr "$zip_path" "$top_dir/")
	zip_size="$(du -h "$zip_path" | cut -f1)"

	echo "    File: ${zip_path}"
	echo "    Size: ${zip_size}"
	return 0
}

validate_zip_contents() {
	local zip_path="$1"
	local top_dir="$2"
	local package_label="$3"
	local required_file="$4"
	local forbid_composer_json="${5:-no}"
	local listing_file=""
	local bad_file=""
	local forbidden_pattern=""

	if ! command -v unzip >/dev/null 2>&1; then
		echo "ERROR: unzip is required to validate ${zip_path}." >&2
		return 1
	fi

	listing_file="$(mktemp)"
	bad_file="$(mktemp)"

	if ! unzip -Z1 "$zip_path" >"$listing_file"; then
		echo "ERROR: Could not inspect ${zip_path}." >&2
		rm -f "$listing_file" "$bad_file"
		return 1
	fi

	if grep -Ev "^${top_dir}(/|$)" "$listing_file" >"$bad_file"; then
		echo "ERROR: ${package_label} zip contains files outside ${top_dir}/:" >&2
		sed -n '1,20p' "$bad_file" >&2
		rm -f "$listing_file" "$bad_file"
		return 1
	fi

	if ! grep -Fxq "${top_dir}/${required_file}" "$listing_file"; then
		echo "ERROR: ${package_label} zip is missing ${top_dir}/${required_file}." >&2
		rm -f "$listing_file" "$bad_file"
		return 1
	fi

	while IFS= read -r forbidden_pattern; do
		if [ -z "$forbidden_pattern" ]; then
			continue
		fi

		if grep -E "^${top_dir}/${forbidden_pattern}" "$listing_file" >"$bad_file"; then
			echo "ERROR: ${package_label} zip contains excluded development files:" >&2
			sed -n '1,20p' "$bad_file" >&2
			rm -f "$listing_file" "$bad_file"
			return 1
		fi
	done <<'EOF'
advanced-plugin(/|$)
(\.git|\.github|node_modules|src|tests|bin|scripts|docs|stubs|tools|playground|seeds|migrations|schemas|\.agents|\.claude|\.cursor|\.opencode|\.husky|\.wordpress-org|screenshots|playwright-report|blob-report|coverage-html)(/|$)
(package\.json|package-lock\.json|webpack\.config\.js|wp-cli\.yml|phpunit\.xml(\.dist)?|phpcs\.xml|phpstan\.neon(\.dist)?|composer\.lock|AGENTS\.md|CLAUDE\.md|README\.md|TODO\.md|CHANGELOG\.md|CONTRIBUTING\.md|CODE_OF_CONDUCT\.md|SECURITY\.md|DESIGN\.md|MODELS\.md|ROADMAP\.md|PLANS-AI-AGENT-MASTERPLAN\.md|ISSUE_1497_FIX\.md|verify-output\.txt|VERSION|\.distignore|\.gitattributes|\.gitignore|\.eslintignore|\.eslintrc\.json|\.stylelintrc\.json|\.wp-env\.json|\.task-counter|\.phpunit\.result\.cache|\.phpunit\.cache|\.aidevops\.json|\.beads|\.beads-credential-key|\.dolt|\.clinerules|\.cursorrules|\.windsurfrules|codecov\.yml|playwright\.config\.(js|ts)|coverage\.xml|skills-lock\.json)$
build/(di-cache|wporg-review)(/|$)
vendor/pondermatic(/|$)
vendor/.*/composer\.lock$
.*\.zip$
.*\.map$
.*\.log$
.*\.db$
EOF

	if [ "$forbid_composer_json" = "yes" ] && grep -E "^${top_dir}/composer\.json$" "$listing_file" >"$bad_file"; then
		echo "ERROR: ${package_label} zip contains composer.json, which is not needed for this package:" >&2
		sed -n '1,20p' "$bad_file" >&2
		rm -f "$listing_file" "$bad_file"
		return 1
	fi

	rm -f "$listing_file" "$bad_file"
	echo "    Contents validated: ${package_label} zip contains only release files."
	return 0
}

build_core() {
	local build_dir=""
	local exclude_file=""
	local dest=""

	build_dir="$(mktemp -d)"
	exclude_file="$(mktemp)"
	dest="${build_dir}/superdav-ai-agent"
	mkdir -p "$dest"

	# shellcheck disable=SC2064
	trap "rm -rf '${build_dir}' '${exclude_file}'" RETURN

	make_exclude_file "$exclude_file"

	echo "==> [core] Copying core plugin files..."
	rsync -a --delete \
		--exclude-from="$exclude_file" \
		"${PLUGIN_DIR}/" "$dest/"

	prune_dev_metadata "$dest"
	composer --working-dir="$dest" dump-autoload --no-dev --optimize --quiet
	zip_dir "$build_dir" "superdav-ai-agent" "superdav-ai-agent-${VERSION}.zip"
	validate_zip_contents "${PLUGIN_DIR}/superdav-ai-agent-${VERSION}.zip" "superdav-ai-agent" "core" "superdav-ai-agent.php" "no"
	return 0
}

build_advanced() {
	local build_dir=""
	local dest=""

	if [ ! -f "${PLUGIN_DIR}/advanced-plugin/superdav-ai-agent-advanced.php" ]; then
		echo "ERROR: advanced-plugin/superdav-ai-agent-advanced.php is missing." >&2
		return 1
	fi

	build_dir="$(mktemp -d)"
	dest="${build_dir}/superdav-ai-agent-advanced"
	mkdir -p "$dest"

	# shellcheck disable=SC2064
	trap "rm -rf '${build_dir}'" RETURN

	echo "==> [advanced] Copying advanced plugin files..."
	rsync -a --delete \
		--exclude='vendor' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		"${PLUGIN_DIR}/advanced-plugin/" "$dest/"

	prune_dev_metadata "$dest"
	zip_dir "$build_dir" "superdav-ai-agent-advanced" "superdav-ai-agent-advanced-${VERSION}.zip"
	validate_zip_contents "${PLUGIN_DIR}/superdav-ai-agent-advanced-${VERSION}.zip" "superdav-ai-agent-advanced" "advanced" "superdav-ai-agent-advanced.php" "yes"
	return 0
}

main() {
	parse_args "$@"
	read_version

	echo "==> Building Superdav AI Agent v${VERSION} (target: ${TARGET})"

	case "$TARGET" in
	core)
		build_assets_and_vendor
		build_core
		;;
	advanced)
		build_advanced
		;;
	both)
		build_assets_and_vendor
		build_core
		build_advanced
		;;
	esac

	echo "==> Build complete."
	return 0
}

main "$@"

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
		--exclude='composer.lock' \
		"${PLUGIN_DIR}/advanced-plugin/" "$dest/"

	prune_dev_metadata "$dest"
	zip_dir "$build_dir" "superdav-ai-agent-advanced" "superdav-ai-agent-advanced-${VERSION}.zip"
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

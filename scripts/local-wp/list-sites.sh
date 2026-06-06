#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
if [ ! -d "$SUPERDAV_LOCAL_WP_SITES_ROOT" ]; then
	log "No sites root found: $SUPERDAV_LOCAL_WP_SITES_ROOT"
	exit 0
fi
printf '%-28s %-42s %s\n' 'SLUG' 'URL' 'PLUGIN_DIR'
find "$SUPERDAV_LOCAL_WP_SITES_ROOT" -mindepth 2 -maxdepth 2 -name site.json -print | sort | while IFS= read -r metadata; do
	slug="$(json_get "$metadata" '.slug')"
	url="$(json_get "$metadata" '.url')"
	plugin_dir="$(json_get "$metadata" '.plugin_dir')"
	printf '%-28s %-42s %s\n' "$slug" "$url" "$plugin_dir"
done

#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
metadata="$(load_site_json "${1:-}")"
wp_root="$(json_get "$metadata" '.wp_root')"
host="$(json_get "$metadata" '.host')"
url="$(json_get "$metadata" '.url')"
require_cmd "$SUPERDAV_LOCAL_WP_WP"
log "Running smoke checks for $url"
wp_cli core version --path="$wp_root"
wp_cli plugin is-active "$SUPERDAV_LOCAL_WP_PLUGIN_SLUG" --path="$wp_root"
if wp_cli plugin is-installed "$SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_SLUG" --path="$wp_root" >/dev/null 2>&1; then
	wp_cli plugin is-active "$SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_SLUG" --path="$wp_root"
fi
wp_cli option get siteurl --path="$wp_root"
wp_cli rewrite structure '/%postname%/' --path="$wp_root"
wp_cli rewrite flush --path="$wp_root"
wp_cli eval 'echo defined("SD_AI_AGENT_VERSION") ? SD_AI_AGENT_VERSION : "missing";' --path="$wp_root"
printf '\n'
if have curl; then
	curl -ks "https://$host/wp-json/" | { if have jq; then jq '.namespaces'; else head -c 1000; fi; }
else
	warn 'curl not found; skipped REST index check.'
fi
log "Smoke checks completed."

#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
metadata="$(load_site_json "${1:-}")"
wp_root="$(json_get "$metadata" '.wp_root')"
url="$(json_get "$metadata" '.url')"
slug="$(json_get "$metadata" '.slug')"
site_root="$(json_get "$metadata" '.site_root')"
safe_site_root "$site_root"
require_cmd "$SUPERDAV_LOCAL_WP_WP"
log "Resetting $slug at $wp_root"
run_cmd wp_cli db reset --yes --path="$wp_root"
run_cmd wp_cli core install --path="$wp_root" --url="$url" --title="Superdav $slug" --admin_user="$SUPERDAV_LOCAL_WP_ADMIN_USER" --admin_password="$SUPERDAV_LOCAL_WP_ADMIN_PASSWORD" --admin_email="$SUPERDAV_LOCAL_WP_ADMIN_EMAIL"
run_cmd wp_cli plugin activate "$SUPERDAV_LOCAL_WP_PLUGIN_SLUG" --path="$wp_root"
if wp_cli plugin is-installed "$SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_SLUG" --path="$wp_root" >/dev/null 2>&1; then
	run_cmd wp_cli plugin activate "$SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_SLUG" --path="$wp_root"
fi
run_cmd rm -rf "$wp_root/wp-content/uploads"/*
log "Reset complete: $url/wp-admin"

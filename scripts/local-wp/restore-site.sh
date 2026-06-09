#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
name="${1:-clean}"
slug_arg="${2:-}"
case "$name" in *[!A-Za-z0-9._-]*|'') die 'Snapshot name must contain only letters, numbers, dot, underscore, or hyphen.' ;; esac
metadata="$(load_site_json "$slug_arg")"
wp_root="$(json_get "$metadata" '.wp_root')"
site_root="$(json_get "$metadata" '.site_root')"
snapshot="$site_root/snapshots/$name.sql"
safe_site_root "$site_root"
[ -f "$snapshot" ] || die "Snapshot not found: $snapshot"
require_cmd "$SUPERDAV_LOCAL_WP_WP"
run_cmd wp_cli db import "$snapshot" --path="$wp_root"
run_cmd wp_cli plugin activate "$SUPERDAV_LOCAL_WP_PLUGIN_SLUG" --path="$wp_root"
log "Snapshot restored: $snapshot"

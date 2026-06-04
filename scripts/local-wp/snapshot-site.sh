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
safe_site_root "$site_root"
require_cmd "$SUPERDAV_LOCAL_WP_WP"
run_cmd install -d "$site_root/snapshots"
run_cmd wp_cli db export "$site_root/snapshots/$name.sql" --path="$wp_root"
log "Snapshot saved: $site_root/snapshots/$name.sql"

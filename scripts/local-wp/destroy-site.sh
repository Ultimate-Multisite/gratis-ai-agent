#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
YES=0
slug_arg=""
while [ $# -gt 0 ]; do
	case "$1" in
		--yes) YES=1 ;;
		*) slug_arg="$1" ;;
	esac
	shift
done
metadata="$(load_site_json "$slug_arg")"
site_root="$(json_get "$metadata" '.site_root')"
slug="$(json_get "$metadata" '.slug')"
db_name="$(json_get "$metadata" '.db_name')"
safe_site_root "$site_root"
case "$db_name" in ${SUPERDAV_LOCAL_WP_DB_PREFIX}*) ;; *) die "Refusing to drop unexpected DB name: $db_name" ;; esac
if [ "$YES" != "1" ]; then
	cat <<EOF
About to remove local site '$slug':
  Site root: $site_root
  Database:  $db_name
  Nginx:     $SUPERDAV_LOCAL_WP_NGINX_DIR/superdav-local-wp-$slug.conf
Re-run with --yes to confirm.
EOF
	exit 1
fi
nginx_conf="$SUPERDAV_LOCAL_WP_NGINX_DIR/superdav-local-wp-$slug.conf"
if [ -e "$nginx_conf" ]; then
	if [ -w "$(dirname "$nginx_conf")" ]; then
		run_cmd rm -f "$nginx_conf"
	elif have sudo; then
		run_cmd sudo rm -f "$nginx_conf"
	else
		warn "Cannot remove nginx config without write permission or sudo: $nginx_conf"
	fi
fi
run_cmd mysql_cli -e "DROP DATABASE IF EXISTS \`$db_name\`;"
run_cmd rm -rf "$site_root"
log "If nginx was enabled, run: sudo nginx -t && sudo systemctl reload nginx"
log "Destroyed site: $slug"

#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

RUN_BUILD=1
SKIP_DEPS=0
INSTALL_NGINX=0
usage() {
	cat <<EOF
Usage: scripts/local-wp/provision-site.sh [--dry-run] [--skip-build] [--build] [--skip-deps] [--install-nginx] [--skip-anthropic-max]

Creates or updates a host-native WordPress site for the current git worktree.
Use SUPERDAV_WP_SITE_SLUG to override the derived site slug. By default the
latest ai-provider-for-anthropic-max GitHub release is installed and activated;
set SUPERDAV_LOCAL_WP_INSTALL_ANTHROPIC_MAX=0 or pass --skip-anthropic-max to skip it.
EOF
}

while [ $# -gt 0 ]; do
	case "$1" in
		--dry-run) SUPERDAV_LOCAL_WP_DRY_RUN=1 ;;
		--skip-build) RUN_BUILD=0 ;;
		--build) RUN_BUILD=1 ;;
		--skip-deps) SKIP_DEPS=1 ;;
		--install-nginx) INSTALL_NGINX=1 ;;
		--skip-anthropic-max) SUPERDAV_LOCAL_WP_INSTALL_ANTHROPIC_MAX=0 ;;
		-h|--help) usage; exit 0 ;;
		*) die "Unknown argument: $1" ;;
	esac
	shift
done

plugin_dir="$(repo_root)"
slug="$(site_slug_for_worktree)"
host="$slug.$SUPERDAV_LOCAL_WP_DOMAIN"
url="https://$host"
site_root="$(site_root_for_slug "$slug")"
wp_root="$site_root/public"
db_name="$(sanitize_db_name "$slug")"
plugin_link="$wp_root/wp-content/plugins/$SUPERDAV_LOCAL_WP_PLUGIN_SLUG"

safe_site_root "$site_root"
if [ "$SUPERDAV_LOCAL_WP_DRY_RUN" != "1" ]; then
	require_cmd "$SUPERDAV_LOCAL_WP_WP"
	require_cmd "$SUPERDAV_LOCAL_WP_MYSQL"
	require_cmd openssl
else
	for cmd in "$SUPERDAV_LOCAL_WP_WP" "$SUPERDAV_LOCAL_WP_MYSQL" openssl; do
		if ! have "$cmd"; then
			warn "dry-run continuing without command: $cmd"
		fi
	done
fi

log "Provisioning $url for worktree $plugin_dir"
run_cmd install -d "$site_root" "$wp_root" "$site_root/logs" "$site_root/snapshots" "$wp_root/wp-content/plugins"

if [ "$SKIP_DEPS" = "0" ]; then
	if [ ! -d "$plugin_dir/vendor" ] && [ -f "$plugin_dir/composer.json" ] && have composer; then
		(cd "$plugin_dir" && run_cmd composer install)
	fi
	if [ ! -d "$plugin_dir/node_modules" ] && [ -f "$plugin_dir/package.json" ] && have npm; then
		(cd "$plugin_dir" && run_cmd npm install)
	fi
fi

if [ "$RUN_BUILD" = "1" ] && [ -f "$plugin_dir/package.json" ] && have npm; then
	(cd "$plugin_dir" && run_cmd npm run build)
fi

if [ ! -f "$wp_root/wp-load.php" ]; then
	run_cmd wp_cli core download --path="$wp_root"
fi

run_cmd mysql_cli -e "CREATE DATABASE IF NOT EXISTS \`$db_name\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

if [ ! -f "$wp_root/wp-config.php" ]; then
	run_cmd wp_cli config create --path="$wp_root" --dbname="$db_name" --dbuser="${SUPERDAV_LOCAL_WP_DB_USER:-root}" --dbpass="${SUPERDAV_LOCAL_WP_DB_PASSWORD:-}" --dbhost="${SUPERDAV_LOCAL_WP_DB_HOST:-localhost}" --skip-check --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'FORCE_SSL_ADMIN', true );
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}
PHP
fi

if ! wp_cli core is-installed --path="$wp_root" >/dev/null 2>&1; then
	run_cmd wp_cli core install --path="$wp_root" --url="$url" --title="Superdav $slug" --admin_user="$SUPERDAV_LOCAL_WP_ADMIN_USER" --admin_password="$SUPERDAV_LOCAL_WP_ADMIN_PASSWORD" --admin_email="$SUPERDAV_LOCAL_WP_ADMIN_EMAIL"
else
	run_cmd wp_cli option update siteurl "$url" --path="$wp_root"
	run_cmd wp_cli option update home "$url" --path="$wp_root"
fi

if [ -L "$plugin_link" ] || [ -e "$plugin_link" ]; then
	current_target="$(readlink "$plugin_link" 2>/dev/null || true)"
	if [ "$current_target" != "$plugin_dir" ]; then
		run_cmd rm -rf "$plugin_link"
	fi
fi
if [ ! -e "$plugin_link" ]; then
	run_cmd ln -s "$plugin_dir" "$plugin_link"
fi

run_cmd wp_cli plugin activate "$SUPERDAV_LOCAL_WP_PLUGIN_SLUG" --path="$wp_root"
install_anthropic_max_provider "$wp_root"
generate_site_cert "$slug" "$site_root" "$host"
write_nginx_config "$slug" "$site_root" "$host" "$wp_root"

if [ "$INSTALL_NGINX" = "1" ]; then
	run_cmd sudo ln -sf "$site_root/nginx-$slug.conf" "$SUPERDAV_LOCAL_WP_NGINX_DIR/superdav-local-wp-$slug.conf"
	run_cmd sudo nginx -t
	run_cmd sudo systemctl reload nginx
fi

write_site_json "$slug" "$site_root" "$wp_root" "$plugin_dir" "$db_name" "$host" "$url"
cat <<EOF
Site ready: $url/wp-admin
Admin: $SUPERDAV_LOCAL_WP_ADMIN_USER / $SUPERDAV_LOCAL_WP_ADMIN_PASSWORD
WordPress root: $wp_root
Database: $db_name
Plugin symlink: $plugin_link -> $plugin_dir
Nginx config: $site_root/nginx-$slug.conf
EOF

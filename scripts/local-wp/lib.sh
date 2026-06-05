#!/usr/bin/env bash
# Shared helpers for host-native local WordPress worktree testing.

set -euo pipefail

SUPERDAV_LOCAL_WP_DOMAIN="${SUPERDAV_LOCAL_WP_DOMAIN:-superdav.test}"
SUPERDAV_LOCAL_WP_SITES_ROOT="${SUPERDAV_LOCAL_WP_SITES_ROOT:-$HOME/local-wp-sites}"
SUPERDAV_LOCAL_WP_CONFIG_DIR="${SUPERDAV_LOCAL_WP_CONFIG_DIR:-$HOME/.config/superdav-local-wp}"
SUPERDAV_LOCAL_WP_CA_DIR="${SUPERDAV_LOCAL_WP_CA_DIR:-$HOME/.local/share/superdav-local-ca}"
SUPERDAV_LOCAL_WP_PLUGIN_SLUG="superdav-ai-agent"
SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_SLUG="ai-provider-for-anthropic-max"
SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_URL="${SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_URL:-https://github.com/Ultimate-Multisite/ai-provider-for-anthropic-max/releases/latest/download/ai-provider-for-anthropic-max.zip}"
SUPERDAV_LOCAL_WP_INSTALL_ANTHROPIC_MAX="${SUPERDAV_LOCAL_WP_INSTALL_ANTHROPIC_MAX:-1}"
SUPERDAV_LOCAL_WP_DB_PREFIX="${SUPERDAV_LOCAL_WP_DB_PREFIX:-wp_sd_}"
SUPERDAV_LOCAL_WP_ADMIN_USER="${SUPERDAV_LOCAL_WP_ADMIN_USER:-admin}"
SUPERDAV_LOCAL_WP_ADMIN_PASSWORD="${SUPERDAV_LOCAL_WP_ADMIN_PASSWORD:-admin}"
SUPERDAV_LOCAL_WP_ADMIN_EMAIL="${SUPERDAV_LOCAL_WP_ADMIN_EMAIL:-admin@example.com}"
SUPERDAV_LOCAL_WP_NGINX_DIR="${SUPERDAV_LOCAL_WP_NGINX_DIR:-/etc/nginx/conf.d}"
SUPERDAV_LOCAL_WP_FASTCGI_INCLUDE="${SUPERDAV_LOCAL_WP_FASTCGI_INCLUDE:-fastcgi.conf}"
SUPERDAV_LOCAL_WP_PHP_FPM_SOCKET="${SUPERDAV_LOCAL_WP_PHP_FPM_SOCKET:-}"
SUPERDAV_LOCAL_WP_MYSQL="${SUPERDAV_LOCAL_WP_MYSQL:-mariadb}"
SUPERDAV_LOCAL_WP_WP="${SUPERDAV_LOCAL_WP_WP:-wp}"
SUPERDAV_LOCAL_WP_DRY_RUN="${SUPERDAV_LOCAL_WP_DRY_RUN:-0}"

log() { printf '[local-wp] %s\n' "$*"; }
warn() { printf '[local-wp] WARNING: %s\n' "$*" >&2; }
die() { printf '[local-wp] ERROR: %s\n' "$*" >&2; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }

run_cmd() {
	if [ "$SUPERDAV_LOCAL_WP_DRY_RUN" = "1" ]; then
		printf '[local-wp] DRY RUN:'
		printf ' %q' "$@"
		printf '\n'
		return 0
	fi
	"$@"
}

require_cmd() {
	have "$1" || die "Required command not found: $1"
}

repo_root() {
	git rev-parse --show-toplevel 2>/dev/null || die 'Run from inside a git worktree.'
}

slugify() {
	printf '%s' "$1" \
		| tr '[:upper:]' '[:lower:]' \
		| sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//; s/-+/-/g'
}

short_hash() {
	printf '%s' "$1" | sha1sum | awk '{print substr($1,1,8)}'
}

site_slug_for_worktree() {
	local root base slug site_json existing_plugin
	root="$(repo_root)"
	if [ -n "${SUPERDAV_WP_SITE_SLUG:-}" ]; then
		slug="$(slugify "$SUPERDAV_WP_SITE_SLUG")"
	else
		base="$(basename "$root")"
		slug="$(slugify "$base")"
	fi
	[ -n "$slug" ] || slug="superdav-local"

	site_json="$SUPERDAV_LOCAL_WP_SITES_ROOT/$slug/site.json"
	if [ -f "$site_json" ] && have jq; then
		existing_plugin="$(jq -r '.plugin_dir // empty' "$site_json")"
		if [ -n "$existing_plugin" ] && [ "$existing_plugin" != "$root" ]; then
			slug="$slug-$(short_hash "$root")"
		fi
	fi
	printf '%s\n' "$slug"
}

site_root_for_slug() {
	local slug="$1"
	[ -n "$slug" ] || die 'Missing site slug.'
	printf '%s/%s\n' "$SUPERDAV_LOCAL_WP_SITES_ROOT" "$slug"
}

metadata_path_for_slug() {
	printf '%s/site.json\n' "$(site_root_for_slug "$1")"
}

safe_site_root() {
	local site_root="$1" sites_root_real site_root_real
	mkdir -p "$SUPERDAV_LOCAL_WP_SITES_ROOT"
	sites_root_real="$(cd "$SUPERDAV_LOCAL_WP_SITES_ROOT" && pwd -P)"
	if [ -d "$site_root" ]; then
		site_root_real="$(cd "$site_root" && pwd -P)"
	else
		site_root_real="$(cd "$(dirname "$site_root")" && pwd -P)/$(basename "$site_root")"
	fi
	case "$site_root_real" in
		"$sites_root_real"/*) ;;
		*) die "Refusing unsafe site path outside sites root: $site_root" ;;
	esac
}

load_site_json() {
	local selector="${1:-}"
	local slug metadata
	if [ -n "$selector" ]; then
		slug="$(slugify "$selector")"
	else
		slug="$(site_slug_for_worktree)"
	fi
	metadata="$(metadata_path_for_slug "$slug")"
	[ -f "$metadata" ] || die "Site metadata not found: $metadata. Run provision-site.sh first or pass a site slug."
	printf '%s\n' "$metadata"
}

json_get() {
	local file="$1" expr="$2"
	if have jq; then
		jq -r "$expr" "$file"
	else
		python - "$file" "$expr" <<'PY'
import json, sys
path, expr = sys.argv[1], sys.argv[2]
key = expr.strip().lstrip('.').split()[0]
with open(path, encoding='utf-8') as fh:
    data = json.load(fh)
print(data.get(key, ''))
PY
	fi
}

sanitize_db_name() {
	local slug="$1"
	printf '%s%s' "$SUPERDAV_LOCAL_WP_DB_PREFIX" "$(printf '%s' "$slug" | sed -E 's/[^A-Za-z0-9]+/_/g; s/^_+//; s/_+$//')"
}

detect_php_fpm_socket() {
	if [ -n "$SUPERDAV_LOCAL_WP_PHP_FPM_SOCKET" ]; then
		printf '%s\n' "$SUPERDAV_LOCAL_WP_PHP_FPM_SOCKET"
		return 0
	fi
	local candidate
	for candidate in \
		/run/php-fpm85/php-fpm.sock \
		/run/php-fpm/php-fpm.sock \
		/run/php/php8.5-fpm.sock \
		/run/php-fpm/php-fpm.sock; do
		if [ -S "$candidate" ]; then
			printf '%s\n' "$candidate"
			return 0
		fi
	done
	printf '%s\n' '/run/php-fpm85/php-fpm.sock'
}

ensure_local_ca() {
	local key="$SUPERDAV_LOCAL_WP_CA_DIR/rootCA.key" pem="$SUPERDAV_LOCAL_WP_CA_DIR/rootCA.pem"
	if [ -f "$key" ] && [ -f "$pem" ]; then
		printf '%s\n' "$pem"
		return 0
	fi
	require_cmd openssl
	run_cmd install -d -m 700 "$SUPERDAV_LOCAL_WP_CA_DIR"
	run_cmd openssl genrsa -out "$key" 4096
	run_cmd openssl req -x509 -new -nodes -key "$key" -sha256 -days 3650 -out "$pem" -subj '/C=US/O=Superdav Local Dev/CN=Superdav Local Dev Root CA'
	if [ "$SUPERDAV_LOCAL_WP_DRY_RUN" != "1" ]; then
		chmod 600 "$key"
	fi
	printf '%s\n' "$pem"
}

generate_site_cert() {
	local slug="$1" site_root="$2" host="$3"
	local cert_dir key csr crt ext ca_key ca_pem
	if [ "$SUPERDAV_LOCAL_WP_DRY_RUN" = "1" ]; then
		log "DRY RUN: would generate certificate for $host under $site_root/certs"
		return 0
	fi
	cert_dir="$site_root/certs"
	key="$cert_dir/site.key"
	csr="$cert_dir/site.csr"
	crt="$cert_dir/site.crt"
	ext="$cert_dir/site.ext"
	ca_key="$SUPERDAV_LOCAL_WP_CA_DIR/rootCA.key"
	ca_pem="$(ensure_local_ca)"
	run_cmd install -d -m 700 "$cert_dir"
	cat > "$ext" <<EOF_EXT
authorityKeyIdentifier=keyid,issuer
basicConstraints=CA:FALSE
keyUsage = digitalSignature, nonRepudiation, keyEncipherment, dataEncipherment
subjectAltName = @alt_names
[alt_names]
DNS.1 = $host
DNS.2 = localhost
IP.1 = 127.0.0.1
IP.2 = ::1
EOF_EXT
	run_cmd openssl genrsa -out "$key" 2048
	run_cmd openssl req -new -key "$key" -out "$csr" -subj "/C=US/O=Superdav Local Dev/CN=$host"
	run_cmd openssl x509 -req -in "$csr" -CA "$ca_pem" -CAkey "$ca_key" -CAcreateserial -out "$crt" -days 825 -sha256 -extfile "$ext"
	[ "$SUPERDAV_LOCAL_WP_DRY_RUN" = "1" ] || chmod 600 "$key"
}

write_nginx_config() {
	local slug="$1" site_root="$2" host="$3" wp_root="$4"
	local conf="$site_root/nginx-$slug.conf" socket
	if [ "$SUPERDAV_LOCAL_WP_DRY_RUN" = "1" ]; then
		log "DRY RUN: would generate nginx config: $conf"
		log "DRY RUN: would use PHP-FPM socket: $(detect_php_fpm_socket)"
		return 0
	fi
	socket="$(detect_php_fpm_socket)"
	cat > "$conf" <<EOF_NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $host;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $host;

    root $wp_root;
    index index.php index.html;

    ssl_certificate     $site_root/certs/site.crt;
    ssl_certificate_key $site_root/certs/site.key;

    access_log $site_root/logs/nginx-access.log;
    error_log  $site_root/logs/nginx-error.log;

    client_max_body_size 128m;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php$ {
        include $SUPERDAV_LOCAL_WP_FASTCGI_INCLUDE;
        fastcgi_pass unix:$socket;
    }
}
EOF_NGINX
	log "Generated nginx config: $conf"
	log "Install with: sudo ln -sf '$conf' '$SUPERDAV_LOCAL_WP_NGINX_DIR/superdav-local-wp-$slug.conf' && sudo nginx -t && sudo systemctl reload nginx"
}

wp_cli() {
	"$SUPERDAV_LOCAL_WP_WP" "$@"
}

mysql_cli() {
	"$SUPERDAV_LOCAL_WP_MYSQL" "$@"
}

install_anthropic_max_provider() {
	local wp_root="$1"

	if [ "$SUPERDAV_LOCAL_WP_INSTALL_ANTHROPIC_MAX" = "0" ]; then
		log "Skipping Anthropic Max provider install because SUPERDAV_LOCAL_WP_INSTALL_ANTHROPIC_MAX=0"
		return 0
	fi

	if [ "$SUPERDAV_LOCAL_WP_DRY_RUN" = "1" ]; then
		log "DRY RUN: would install and activate $SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_SLUG from $SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_URL"
		return 0
	fi

	log "Installing/updating Anthropic Max provider from latest release"
	run_cmd wp_cli plugin install "$SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_URL" --force --activate --path="$wp_root"
}

write_site_json() {
	local slug="$1" site_root="$2" wp_root="$3" plugin_dir="$4" db_name="$5" host="$6" url="$7"
	local json="$site_root/site.json"
	local created_at
	if [ "$SUPERDAV_LOCAL_WP_DRY_RUN" = "1" ]; then
		log "DRY RUN: would write metadata: $json"
		return 0
	fi
	created_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
	if have jq; then
		jq -n \
			--arg slug "$slug" \
			--arg host "$host" \
			--arg url "$url" \
			--arg wp_root "$wp_root" \
			--arg site_root "$site_root" \
			--arg plugin_dir "$plugin_dir" \
			--arg plugin_slug "$SUPERDAV_LOCAL_WP_PLUGIN_SLUG" \
			--arg db_name "$db_name" \
			--arg created_at "$created_at" \
			'{schema:1, slug:$slug, host:$host, url:$url, wp_root:$wp_root, site_root:$site_root, plugin_dir:$plugin_dir, plugin_slug:$plugin_slug, db_name:$db_name, created_at:$created_at}' > "$json"
	else
		python - "$json" "$slug" "$host" "$url" "$wp_root" "$site_root" "$plugin_dir" "$SUPERDAV_LOCAL_WP_PLUGIN_SLUG" "$db_name" "$created_at" <<'PY'
import json, sys
out, slug, host, url, wp_root, site_root, plugin_dir, plugin_slug, db_name, created_at = sys.argv[1:]
with open(out, 'w', encoding='utf-8') as fh:
    json.dump({"schema": 1, "slug": slug, "host": host, "url": url, "wp_root": wp_root, "site_root": site_root, "plugin_dir": plugin_dir, "plugin_slug": plugin_slug, "db_name": db_name, "created_at": created_at}, fh, indent=2)
    fh.write('\n')
PY
	fi
	log "Wrote metadata: $json"
}

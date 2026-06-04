#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

usage() {
	cat <<EOF
Usage: scripts/local-wp/bootstrap-host.sh [--check] [--print-config]

Checks the CachyOS/Arch host prerequisites for native WordPress worktree sites.
This script is non-destructive: it reports missing tools/services and prints the
sudo commands/config snippets to run manually.
EOF
}

CHECK=0
PRINT_CONFIG=0
while [ $# -gt 0 ]; do
	case "$1" in
		--check) CHECK=1 ;;
		--print-config) PRINT_CONFIG=1 ;;
		-h|--help) usage; exit 0 ;;
		*) die "Unknown argument: $1" ;;
	esac
	shift
done

packages=(nginx mariadb php php-fpm wp dnsmasq openssl)
optional=(paru certutil trust jq)

log "Checking required commands"
missing=0
for cmd in "${packages[@]}"; do
	if have "$cmd"; then
		log "ok: $cmd -> $(command -v "$cmd")"
	else
		warn "missing: $cmd"
		missing=1
	fi
done

log "Checking optional commands"
for cmd in "${optional[@]}"; do
	if have "$cmd"; then
		log "ok: $cmd -> $(command -v "$cmd")"
	else
		warn "optional missing: $cmd"
	fi
done

log "PHP version"
if have php; then php -v | head -n 1; fi

log "PHP-FPM socket candidate: $(detect_php_fpm_socket)"
if [ ! -S "$(detect_php_fpm_socket)" ]; then
	warn "PHP-FPM socket not found. Set SUPERDAV_LOCAL_WP_PHP_FPM_SOCKET if your host uses a different path."
fi

if have systemctl; then
	log "Service state"
	for unit in mariadb nginx dnsmasq php-fpm85.service php-fpm.service; do
		if systemctl list-unit-files "$unit" >/dev/null 2>&1; then
			state="$(systemctl is-active "$unit" 2>/dev/null || true)"
			log "$unit: ${state:-unknown}"
		fi
	done
fi

if [ "$PRINT_CONFIG" = "1" ] || [ "$CHECK" = "1" ]; then
	cat <<EOF

Suggested CachyOS/Arch package install (verify php85 package names on your host):
  paru -S --needed nginx mariadb php85 php85-fpm php85-mysql php85-gd php85-intl php85-imagick php85-mbstring php85-opcache php85-zip php85-curl php85-xml wp-cli dnsmasq nss ca-certificates-utils openssl jq

Suggested services:
  sudo systemctl enable --now mariadb
  sudo systemctl enable --now php-fpm85.service
  sudo systemctl enable --now nginx
  sudo systemctl enable --now dnsmasq

Suggested dnsmasq file (/etc/dnsmasq.d/superdav-local-wp.conf):
  address=/.$SUPERDAV_LOCAL_WP_DOMAIN/127.0.0.1
  address=/.$SUPERDAV_LOCAL_WP_DOMAIN/::1

Local CA trust after provision-site.sh creates ~/.local/share/superdav-local-ca/rootCA.pem:
  sudo trust anchor "$SUPERDAV_LOCAL_WP_CA_DIR/rootCA.pem"
  sudo update-ca-trust

Firefox/Chromium NSS DB, when needed:
  certutil -d sql:\$HOME/.pki/nssdb -A -t 'C,,' -n 'Superdav Local Dev Root CA' -i "$SUPERDAV_LOCAL_WP_CA_DIR/rootCA.pem"
EOF
fi

if [ "$missing" -ne 0 ]; then
	exit 1
fi
log "Host prerequisite check completed."

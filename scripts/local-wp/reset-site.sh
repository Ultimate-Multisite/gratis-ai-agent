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

backup_dir="$(mktemp -d)"
active_plugins_file="$backup_dir/active-plugins.txt"
preserved_options_file="$backup_dir/preserved-options.json"
trap 'rm -rf "$backup_dir"' EXIT

log "Capturing active plugins and provider configuration before reset"
if wp_cli core is-installed --path="$wp_root" >/dev/null 2>&1; then
	wp_cli plugin list --status=active --field=name --path="$wp_root" > "$active_plugins_file"
	# Preserve local AI provider connector credentials/configuration across the DB reset.
	# Keep this backup in a private temp file and restore it without printing option values.
	wp_cli eval '
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '\''anthropic_max_%'\'' OR option_name LIKE '\''ultimate_ai_connector_%'\''",
			ARRAY_A
		);
		fwrite( STDOUT, wp_json_encode( $rows ) . "\n" );
	' --path="$wp_root" > "$preserved_options_file"
else
	: > "$active_plugins_file"
	printf '[]\n' > "$preserved_options_file"
fi

if ! grep -qxF 'ai-provider-for-anthropic-max' "$active_plugins_file"; then
	printf '%s\n' 'ai-provider-for-anthropic-max' >> "$active_plugins_file"
fi
if ! grep -qxF "$SUPERDAV_LOCAL_WP_PLUGIN_SLUG" "$active_plugins_file"; then
	printf '%s\n' "$SUPERDAV_LOCAL_WP_PLUGIN_SLUG" >> "$active_plugins_file"
fi

log "Resetting $slug at $wp_root"
run_cmd wp_cli db reset --yes --path="$wp_root"
run_cmd wp_cli core install --path="$wp_root" --url="$url" --title="Superdav $slug" --admin_user="$SUPERDAV_LOCAL_WP_ADMIN_USER" --admin_password="$SUPERDAV_LOCAL_WP_ADMIN_PASSWORD" --admin_email="$SUPERDAV_LOCAL_WP_ADMIN_EMAIL"

if [ -s "$preserved_options_file" ]; then
	log "Restoring preserved AI provider configuration"
	PRESERVED_OPTIONS_FILE="$preserved_options_file" run_cmd wp_cli eval '
		global $wpdb;
		$path = getenv( "PRESERVED_OPTIONS_FILE" );
		$rows = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $rows ) ) {
			WP_CLI::error( "Invalid preserved options backup." );
		}
		foreach ( $rows as $row ) {
			if ( ! isset( $row["option_name"], $row["option_value"] ) ) {
				continue;
			}
			$autoload = isset( $row["autoload"] ) ? (string) $row["autoload"] : "auto";
			$wpdb->replace(
				$wpdb->options,
				array(
					"option_name"  => (string) $row["option_name"],
					"option_value" => (string) $row["option_value"],
					"autoload"     => $autoload,
				),
				array( "%s", "%s", "%s" )
			);
			wp_cache_delete( (string) $row["option_name"], "options" );
		}
	' --path="$wp_root"
fi

log "Reactivating previously active plugins"
while IFS= read -r plugin_slug; do
	[ -n "$plugin_slug" ] || continue
	if wp_cli plugin is-installed "$plugin_slug" --path="$wp_root" >/dev/null 2>&1; then
		if ! run_cmd wp_cli plugin activate "$plugin_slug" --path="$wp_root"; then
			warn "Plugin activation failed: $plugin_slug"
		fi
	else
		warn "Plugin not installed after reset, cannot reactivate: $plugin_slug"
	fi
done < "$active_plugins_file"

run_cmd rm -rf "$wp_root/wp-content/uploads"/*
log "Reset complete: $url/wp-admin"

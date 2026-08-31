<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

use WP_Error;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and updates the separately distributed Advanced companion plugin.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class AdvancedPluginManager {

	public const PLUGIN_BASENAME    = 'superdav-ai-agent-advanced/superdav-ai-agent-advanced.php';
	public const AUTO_UPDATE_OPTION = 'sd_ai_agent_advanced_auto_update';

	private SuperdavSiteConnectionService $connection;

	public function __construct( SuperdavSiteConnectionService $connection ) {
		$this->connection = $connection;
	}

	/** Register PUC for a separately installed Advanced plugin. */
	#[Action( tag: 'init', priority: 20 )]
	public function register_update_checker(): void {
		$plugin_file = $this->plugin_file();
		$endpoint    = $this->connection->get_advanced_plugin_metadata_endpoint();
		if ( $this->is_bundled_copy() || ! file_exists( $plugin_file ) || '' === $endpoint || ! class_exists( PucFactory::class ) ) {
			return;
		}

		PucFactory::buildUpdateChecker(
			$endpoint,
			$plugin_file,
			'superdav-ai-agent-advanced',
			12
		);
	}

	/**
	 * Download the exact public package and verify its service checksum.
	 *
	 * @param false|string|WP_Error $reply      Prior short-circuit value.
	 * @param string                $package    Requested package URL.
	 * @param mixed                 $upgrader   WordPress upgrader instance.
	 * @param array<string, mixed>  $hook_extra Upgrader context.
	 * @return false|string|WP_Error Verified temporary ZIP path or prior value.
	 */
	#[Filter( tag: 'upgrader_pre_download', priority: 10, args: 4 )]
	public function verified_package_download( false|string|WP_Error $reply, string $package, mixed $upgrader, array $hook_extra ): false|string|WP_Error {
		unset( $upgrader, $hook_extra );

		$package_path = wp_parse_url( $package, PHP_URL_PATH );
		if ( false !== $reply
			|| ! is_string( $package_path )
			|| 1 !== preg_match( '#/superdav-ai-agent-advanced-\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\.zip$#', $package_path )
		) {
			return $reply;
		}

		$metadata = $this->connection->request_advanced_plugin_metadata();
		if ( $metadata instanceof WP_Error ) {
			return $metadata;
		}
		if ( $package !== $metadata['download_url'] ) {
			return new WP_Error( 'sd_ai_agent_advanced_download_mismatch', __( 'SD AI Agent Advanced package metadata changed before download.', 'superdav-ai-agent' ) );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temporary_file = wp_tempnam( self::PLUGIN_BASENAME );
		if ( ! is_string( $temporary_file ) || '' === $temporary_file ) {
			return new WP_Error( 'sd_ai_agent_advanced_download_failed', __( 'WordPress could not create a temporary file for Advanced.', 'superdav-ai-agent' ) );
		}

		$response = wp_remote_get(
			$metadata['download_url'],
			array(
				'timeout'     => 300.0,
				'redirection' => 0,
				'stream'      => true,
				'filename'    => $temporary_file,
				'headers'     => array( 'Accept' => 'application/zip, application/octet-stream' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $temporary_file );
			return new WP_Error( 'sd_ai_agent_advanced_download_failed', __( 'SD AI Agent Advanced could not be downloaded.', 'superdav-ai-agent' ) );
		}

		$checksum        = hash_file( 'sha256', $temporary_file );
		$response_header = wp_remote_retrieve_header( $response, 'x-package-sha256' );
		$header_matches  = '' === $response_header
			|| ( is_string( $response_header ) && hash_equals( $metadata['package_sha256'], strtolower( $response_header ) ) );
		if ( ! is_string( $checksum )
			|| ! hash_equals( $metadata['package_sha256'], $checksum )
			|| ! $header_matches
		) {
			wp_delete_file( $temporary_file );
			return new WP_Error( 'sd_ai_agent_advanced_checksum_mismatch', __( 'SD AI Agent Advanced failed integrity verification.', 'superdav-ai-agent' ) );
		}

		return $temporary_file;
	}

	/**
	 * Enable or disable WordPress automatic updates for Advanced.
	 *
	 * @param mixed $update Existing automatic-update decision.
	 * @param mixed $item   WordPress plugin update item.
	 * @return mixed Automatic-update decision.
	 */
	#[Filter( tag: 'auto_update_plugin', priority: 10, args: 2 )]
	public function filter_auto_update( mixed $update, mixed $item ): mixed {
		$plugin = is_object( $item ) && isset( $item->plugin ) && is_string( $item->plugin ) ? $item->plugin : '';
		if ( self::PLUGIN_BASENAME !== $plugin ) {
			return $update;
		}

		return $this->auto_updates_enabled();
	}

	/**
	 * Return browser-safe local installation state.
	 *
	 * @return array<string, bool|string|null>
	 */
	public function get_status(): array {
		$installed   = file_exists( $this->plugin_file() );
		$active      = $installed && $this->is_active();
		$bundled     = $this->is_bundled_copy();
		$plugin_data = $installed ? $this->plugin_data() : array();

		return array(
			'installed'            => $installed,
			'active'               => $active,
			'bundled'              => $bundled,
			'version'              => isset( $plugin_data['Version'] ) && is_string( $plugin_data['Version'] ) ? $plugin_data['Version'] : null,
			'auto_updates_enabled' => $this->auto_updates_enabled(),
			'file_mods_allowed'    => $this->file_mods_allowed(),
			'can_manage'           => self::current_user_can_manage() && ! $bundled,
		);
	}

	/** Install Advanced from the managed service and activate it. */
	public function install_and_activate(): array|WP_Error {
		$permission = $this->validate_management_permission();
		if ( $permission instanceof WP_Error ) {
			return $permission;
		}

		if ( $this->is_bundled_copy() ) {
			return new WP_Error( 'sd_ai_agent_advanced_bundled', __( 'The repository-bundled Advanced plugin is already loaded.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
		}

		if ( ! file_exists( $this->plugin_file() ) ) {
			$metadata = $this->connection->request_advanced_plugin_metadata();
			if ( $metadata instanceof WP_Error ) {
				return $metadata;
			}

			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';

			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $metadata['download_url'] );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			if ( true !== $result || ! file_exists( $this->plugin_file() ) ) {
				return new WP_Error( 'sd_ai_agent_advanced_install_failed', __( 'SD AI Agent Advanced could not be installed.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
			}
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$network_wide = is_multisite() && is_plugin_active_for_network( plugin_basename( SD_AI_AGENT_DIR . '/superdav-ai-agent.php' ) );
		$activation   = activate_plugin( self::PLUGIN_BASENAME, '', $network_wide, true );
		if ( $activation instanceof WP_Error ) {
			return $activation;
		}

		update_option( self::AUTO_UPDATE_OPTION, true, false );

		return $this->get_status();
	}

	/** Persist the administrator's Advanced automatic-update preference. */
	public function set_auto_updates_enabled( bool $enabled ): array|WP_Error {
		$permission = $this->validate_management_permission();
		if ( $permission instanceof WP_Error ) {
			return $permission;
		}

		update_option( self::AUTO_UPDATE_OPTION, $enabled, false );

		return $this->get_status();
	}

	/** Whether the current administrator may install and activate plugins. */
	public static function current_user_can_manage(): bool {
		return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
	}

	private function validate_management_permission(): true|WP_Error {
		if ( ! self::current_user_can_manage() ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to manage plugins on this site.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		if ( ! $this->file_mods_allowed() ) {
			return new WP_Error( 'sd_ai_agent_file_mods_disabled', __( 'Plugin installation is disabled by this site configuration.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
		}

		return true;
	}

	private function file_mods_allowed(): bool {
		return wp_is_file_mod_allowed( 'sd_ai_agent_advanced' );
	}

	private function auto_updates_enabled(): bool {
		return true === (bool) get_option( self::AUTO_UPDATE_OPTION, true );
	}

	private function plugin_file(): string {
		return WP_PLUGIN_DIR . '/' . self::PLUGIN_BASENAME;
	}

	private function is_bundled_copy(): bool {
		return defined( 'SD_AI_AGENT_DIR' ) && file_exists( SD_AI_AGENT_DIR . '/advanced-plugin/superdav-ai-agent-advanced.php' );
	}

	private function is_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::PLUGIN_BASENAME ) || ( is_multisite() && is_plugin_active_for_network( self::PLUGIN_BASENAME ) );
	}

	/** @return array<string, mixed> */
	private function plugin_data(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugin_data( $this->plugin_file(), false, false );
	}
}

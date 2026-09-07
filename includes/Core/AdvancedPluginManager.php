<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports browser-safe state for the separately distributed Advanced plugin.
 */
final class AdvancedPluginManager {

	public const PLUGIN_BASENAME = 'superdav-ai-agent-advanced/superdav-ai-agent-advanced.php';

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
			'installed' => $installed,
			'active'    => $active,
			'bundled'   => $bundled,
			'version'   => isset( $plugin_data['Version'] ) && is_string( $plugin_data['Version'] ) ? $plugin_data['Version'] : null,
		);
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

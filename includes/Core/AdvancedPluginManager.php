<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports browser-safe state for the separately distributed Advanced plugin.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class AdvancedPluginManager {

	public const PLUGIN_BASENAME   = 'superdav-ai-agent-advanced/superdav-ai-agent-advanced.php';
	public const DIAGNOSTIC_OPTION = 'sd_ai_agent_advanced_update_diagnostic';

	private const STATUS_CURRENT              = 'current';
	private const STATUS_UPDATE_AVAILABLE     = 'update_available';
	private const STATUS_INCOMPATIBLE         = 'incompatible';
	private const STATUS_METADATA_UNAVAILABLE = 'metadata_unavailable';
	private const STATUS_UPDATE_FAILED        = 'update_failed';

	/**
	 * Persist the safe outcome reported by WordPress' automatic plugin updater.
	 *
	 * @param mixed $send        Existing debug-email decision.
	 * @param mixed $type        Automatic-update type.
	 * @param mixed $update_item Plugin update item.
	 * @param mixed $result      Update result.
	 * @return mixed Existing debug-email decision.
	 */
	#[Filter( tag: 'automatic_updates_send_debug_email', priority: 10, args: 4 )]
	public function record_automatic_update_result( mixed $send, mixed $type, mixed $update_item, mixed $result ): mixed {
		$plugin = is_object( $update_item ) && isset( $update_item->plugin ) && is_string( $update_item->plugin ) ? $update_item->plugin : '';
		if ( 'plugin' !== $type || self::PLUGIN_BASENAME !== $plugin ) {
			return $send;
		}

		if ( $result instanceof \WP_Error ) {
			$this->record_diagnostic( (string) $result->get_error_code() );
		} elseif ( true === $result ) {
			delete_option( self::DIAGNOSTIC_OPTION );
		} elseif ( false === $result ) {
			$this->record_diagnostic( 'sd_ai_agent_advanced_update_failed' );
		}

		return $send;
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
		$version     = isset( $plugin_data['Version'] ) && is_string( $plugin_data['Version'] ) ? $plugin_data['Version'] : null;
		$update      = $this->update_information( $version );
		$compatible  = is_string( $version ) ? $this->is_compatible_version( $version ) : null;
		$diagnostic  = $this->diagnostic_code();
		$status      = $this->status_state( $installed, $bundled, $compatible, $update['available'], $update['metadata_available'], $diagnostic );

		return array(
			'installed'        => $installed,
			'active'           => $active,
			'bundled'          => $bundled,
			'version'          => $version,
			'latest_version'   => $update['version'],
			'compatible'       => $compatible,
			'update_available' => $update['available'],
			'status'           => $status,
			'last_error_code'  => $diagnostic,
		);
	}

	/**
	 * @param string|null $current Installed Advanced version.
	 * @return array{available:bool,metadata_available:bool,version:string|null}
	 */
	private function update_information( ?string $current ): array {
		$updates            = get_site_transient( 'update_plugins' );
		$metadata_available = is_object( $updates ) && isset( $updates->last_checked );
		$response           = $metadata_available && isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
		$item               = $response[ self::PLUGIN_BASENAME ] ?? null;
		$version            = is_object( $item ) && isset( $item->new_version ) && is_string( $item->new_version ) ? $item->new_version : null;

		return array(
			'available'          => is_string( $current ) && is_string( $version ) && version_compare( $version, $current, '>' ),
			'metadata_available' => $metadata_available,
			'version'            => $version,
		);
	}

	private function is_compatible_version( string $version ): bool {
		$core_version = defined( 'SD_AI_AGENT_VERSION' ) ? (string) SD_AI_AGENT_VERSION : '';

		return '' !== $core_version && 0 === version_compare( $version, $core_version );
	}

	private function diagnostic_code(): ?string {
		$diagnostic = get_option( self::DIAGNOSTIC_OPTION, '' );

		return is_string( $diagnostic ) && '' !== $diagnostic ? sanitize_key( $diagnostic ) : null;
	}

	private function record_diagnostic( string $code ): void {
		update_option( self::DIAGNOSTIC_OPTION, sanitize_key( $code ), false );
	}

	private function status_state( bool $installed, bool $bundled, ?bool $compatible, bool $update_available, bool $metadata_available, ?string $diagnostic ): string {
		if ( ! $installed || $bundled ) {
			return self::STATUS_CURRENT;
		}
		if ( null !== $diagnostic ) {
			return self::STATUS_UPDATE_FAILED;
		}
		if ( false === $compatible ) {
			return self::STATUS_INCOMPATIBLE;
		}
		if ( ! $metadata_available ) {
			return self::STATUS_METADATA_UNAVAILABLE;
		}
		if ( $update_available ) {
			return self::STATUS_UPDATE_AVAILABLE;
		}

		return self::STATUS_CURRENT;
	}

	private function plugin_file(): string {
		return WP_PLUGIN_DIR . '/' . self::PLUGIN_BASENAME;
	}

	private function is_bundled_copy(): bool {
		$bundled = defined( 'SD_AI_AGENT_DIR' ) && file_exists( SD_AI_AGENT_DIR . '/advanced-plugin/superdav-ai-agent-advanced.php' );

		return (bool) apply_filters( 'sd_ai_agent_advanced_plugin_bundled_copy', $bundled );
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

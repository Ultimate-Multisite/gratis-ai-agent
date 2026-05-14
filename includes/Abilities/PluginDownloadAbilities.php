<?php

declare(strict_types=1);
/**
 * Plugin download abilities for the AI agent.
 *
 * Provides the ability to list AI-modified plugins and generate
 * download links so admins can retrieve modified plugin zips.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Database;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PluginDownloadAbilities {

	// ─── Static proxy methods (for backwards-compatible test access) ─────────

	/**
	 * List AI-modified plugins.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_list_modified_plugins( array $input = [] ) {
		$ability = new ListModifiedPluginsAbility(
			'sd-ai-agent/list-modified-plugins',
			[
				'label'       => __( 'List Modified Plugins', 'superdav-ai-agent' ),
				'description' => __( 'List all plugins that have been modified by the AI agent, with modification counts and download links.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Get a download URL for an AI-modified plugin.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_get_plugin_download_url( array $input = [] ) {
		$ability = new GetPluginDownloadUrlAbility(
			'sd-ai-agent/get-plugin-download-url',
			[
				'label'       => __( 'Get Plugin Download URL', 'superdav-ai-agent' ),
				'description' => __( 'Get a download URL for a plugin that has been modified by the AI agent.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Register all plugin download abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/list-modified-plugins',
			[
				'label'         => __( 'List Modified Plugins', 'superdav-ai-agent' ),
				'description'   => __( 'List all plugins that have been modified by the AI agent, with modification counts and download links.', 'superdav-ai-agent' ),
				'ability_class' => ListModifiedPluginsAbility::class,
				'show_in_rest'  => true,
			]
		);

		wp_register_ability(
			'sd-ai-agent/get-plugin-download-url',
			[
				'label'         => __( 'Get Plugin Download URL', 'superdav-ai-agent' ),
				'description'   => __( 'Get a download URL for a plugin that has been modified by the AI agent.', 'superdav-ai-agent' ),
				'ability_class' => GetPluginDownloadUrlAbility::class,
				'show_in_rest'  => true,
			]
		);
	}
}

/**
 * List Modified Plugins ability.
 *
 * Returns a list of plugins that have been modified by the AI agent,
 * including modification counts, last-modified timestamps, and download URLs.
 *
 * @since 1.1.0
 */
class ListModifiedPluginsAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'List Modified Plugins', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'List all plugins that have been modified by the AI agent, with modification counts and download links.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => (object) [],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'plugins' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'plugin_slug'        => [ 'type' => 'string' ],
							'modification_count' => [ 'type' => 'integer' ],
							'last_modified'      => [ 'type' => 'string' ],
							'download_url'       => [ 'type' => 'string' ],
						],
					],
				],
				'count'   => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$rows    = Database::get_modified_plugins();
		$plugins = [];

		foreach ( $rows as $row ) {
			$slug         = $row->plugin_slug ?? '';
			$nonce        = wp_create_nonce( 'sd_ai_agent_download_plugin_' . $slug );
			$rest_url     = rest_url( 'sd-ai-agent/v1/download-plugin/' . rawurlencode( $slug ) );
			$download_url = add_query_arg( '_wpnonce', $nonce, $rest_url );

			$plugins[] = [
				'plugin_slug'        => $slug,
				'modification_count' => (int) ( $row->modification_count ?? 0 ),
				'last_modified'      => $row->last_modified ?? '',
				'download_url'       => $download_url,
			];
		}

		return [
			'plugins' => $plugins,
			'count'   => count( $plugins ),
		];
	}

	protected function permission_callback( $input ): bool {
		return current_user_can( 'manage_options' );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'   => true,
				'idempotent' => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Get Plugin Download URL ability.
 *
 * Returns a signed download URL for a specific AI-modified plugin.
 *
 * @since 1.1.0
 */
class GetPluginDownloadUrlAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Get Plugin Download URL', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Get a download URL for a plugin that has been modified by the AI agent.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'plugin_slug' => [
					'type'        => 'string',
					'description' => 'The plugin directory slug (e.g. "my-plugin")',
				],
			],
			'required'   => [ 'plugin_slug' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'plugin_slug'        => [ 'type' => 'string' ],
				'download_url'       => [ 'type' => 'string' ],
				'modification_count' => [ 'type' => 'integer' ],
				'last_modified'      => [ 'type' => 'string' ],
				'plugin_dir_exists'  => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		// @phpstan-ignore-next-line
		$slug = sanitize_key( $input['plugin_slug'] ?? '' );

		if ( empty( $slug ) ) {
			return new WP_Error( 'sd_ai_agent_invalid_slug', __( 'Plugin slug cannot be empty.', 'superdav-ai-agent' ) );
		}

		// Verify the plugin directory exists.
		// NOTE: Using WP_PLUGIN_DIR per wp.org guidelines.
		// See: https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/
		$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
		if ( ! is_dir( $plugin_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_found',
				sprintf(
					/* translators: %s: plugin slug */
					__( 'Plugin directory not found: %s', 'superdav-ai-agent' ),
					$slug
				)
			);
		}

		// Get modification stats.
		$rows  = Database::get_modified_files_for_plugin( $slug );
		$count = count( $rows );

		if ( 0 === $count ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_modified',
				sprintf(
					/* translators: %s: plugin slug */
					__( 'No AI modifications recorded for plugin: %s', 'superdav-ai-agent' ),
					$slug
				)
			);
		}

		$last_modified = $rows[0]->modified_at ?? '';

		$nonce        = wp_create_nonce( 'sd_ai_agent_download_plugin_' . $slug );
		$rest_url     = rest_url( 'sd-ai-agent/v1/download-plugin/' . rawurlencode( $slug ) );
		$download_url = add_query_arg( '_wpnonce', $nonce, $rest_url );

		return [
			'plugin_slug'        => $slug,
			'download_url'       => $download_url,
			'modification_count' => $count,
			'last_modified'      => $last_modified,
			'plugin_dir_exists'  => true,
		];
	}

	protected function permission_callback( $input ): bool {
		return current_user_can( 'manage_options' );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'   => true,
				'idempotent' => true,
			],
			'show_in_rest' => true,
		];
	}
}

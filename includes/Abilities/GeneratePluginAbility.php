<?php

declare(strict_types=1);
/**
 * Generate Plugin ability — AI-powered plugin generation from a description.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\PluginBuilder\PluginGenerator;
use SdAiAgent\PluginBuilder\PluginInstaller;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate Plugin ability.
 *
 * Generates an implementation plan and full PHP source for a WordPress plugin
 * from a natural-language description, then installs it to disk.
 *
 * @since 1.5.0
 */
class GeneratePluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Generate Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Generate a WordPress plugin from a natural-language description. Returns the implementation plan and complete PHP source code.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'description' => [
					'type'        => 'string',
					'description' => 'Natural-language description of what the plugin should do.',
				],
				'slug'        => [
					'type'        => 'string',
					'description' => 'Plugin slug (directory name). Defaults to a sanitized version of the description.',
				],
				'install'     => [
					'type'        => 'boolean',
					'description' => 'Whether to install the generated plugin to wp-content/plugins/. Defaults to true.',
				],
			],
			'required'   => [ 'description' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'plan'        => [ 'type' => 'string' ],
				'files'       => [ 'type' => 'object' ],
				'plugin_file' => [ 'type' => 'string' ],
				'slug'        => [ 'type' => 'string' ],
				'installed'   => [ 'type' => 'boolean' ],
				'record_id'   => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input ): array|\WP_Error {
		$description = (string) ( $input['description'] ?? '' );
		$slug_input  = (string) ( $input['slug'] ?? '' );
		$install     = isset( $input['install'] ) ? (bool) $input['install'] : true;

		if ( empty( $description ) ) {
			return new WP_Error(
				'sd_ai_agent_empty_description',
				__( 'description is required.', 'superdav-ai-agent' )
			);
		}

		// Step 1: Generate structured plan (returns an array, not text).
		$plan = PluginGenerator::generate_plan( $description );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// Override slug if the caller provided an explicit one.
		if ( ! empty( $slug_input ) ) {
			$plan['slug'] = sanitize_title( $slug_input );
		}

		// Step 2: Generate code file-by-file respecting dependency order.
		$code_result = PluginGenerator::generate_code( $plan );
		if ( is_wp_error( $code_result ) ) {
			return $code_result;
		}

		$files = $code_result['files'];
		$plan  = $code_result['plan'];
		$slug  = $plan['slug'];

		$plugin_file = PluginGenerator::detect_main_file( $files, $slug );

		$result = [
			'plan'        => $plan,
			'files'       => $files,
			'plugin_file' => $plugin_file,
			'slug'        => $slug,
			'installed'   => false,
			'record_id'   => 0,
		];

		// Step 3: Install to disk (optional).
		if ( $install ) {
			$install_result = PluginInstaller::install(
				$slug,
				$files,
				$description,
				(string) wp_json_encode( $plan ),
				$plugin_file
			);
			if ( is_wp_error( $install_result ) ) {
				return $install_result;
			}
			$result['installed'] = true;
			$result['record_id'] = $install_result['id'];
		}

		return $result;
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}
}

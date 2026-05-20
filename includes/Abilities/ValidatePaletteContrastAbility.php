<?php

declare(strict_types=1);
/**
 * Validate Palette Contrast ability — WCAG AA gate for Theme Builder palettes.
 *
 * Wraps {@see \SdAiAgent\Services\PaletteValidator} so the AI agent can
 * call `sd-ai-agent/validate-palette-contrast` at the end of the
 * direction-selection step, BEFORE `sd-ai-agent/scaffold-block-theme`,
 * to ensure the chosen palette meets WCAG AA contrast minimums.
 *
 * When failures are present the ability returns a structured result so the
 * agent can either offer the user a choice (accept suggestions / mark as
 * decorative / proceed anyway) or auto-apply the suggested adjustments.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Services\PaletteValidator;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate a theme palette against WCAG AA contrast minimums.
 *
 * @since 1.16.0
 */
class ValidatePaletteContrastAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Validate Palette Contrast', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __(
			'Check a theme.json colour palette against WCAG AA contrast minimums (4.5:1 body, 3:1 large text). Call after the user picks a direction and BEFORE sd-ai-agent/scaffold-block-theme. Returns failing pairs and suggested hex adjustments so the agent can present options or auto-correct.',
			'superdav-ai-agent'
		);
	}

	protected function category(): string {
		return 'sd-ai-agent';
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'palette' => [
					'type'        => 'array',
					'description' => 'Theme.json-shaped colour palette: array of { slug, color } entries. Hex values may be #rgb, #rrggbb, or unprefixed.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'slug'  => [ 'type' => 'string' ],
							'color' => [ 'type' => 'string' ],
							'name'  => [ 'type' => 'string' ],
						],
						'required'   => [ 'slug', 'color' ],
					],
				],
				'pairs'   => [
					'type'        => 'array',
					'description' => 'Optional override of the default pair definitions. Each entry: { id, fg_slug, bg_slug, required, label }. When omitted, the default Theme Builder pairs are used (body, heading, link, button).',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'       => [ 'type' => 'string' ],
							'fg_slug'  => [ 'type' => 'string' ],
							'bg_slug'  => [ 'type' => 'string' ],
							'required' => [ 'type' => 'number' ],
							'label'    => [ 'type' => 'string' ],
						],
						'required'   => [ 'id', 'fg_slug', 'bg_slug' ],
					],
				],
			],
			'required'   => [ 'palette' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'passed'        => [ 'type' => 'boolean' ],
				'pairs_checked' => [ 'type' => 'integer' ],
				'failures'      => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'pair_id'  => [ 'type' => 'string' ],
							'label'    => [ 'type' => 'string' ],
							'fg_slug'  => [ 'type' => 'string' ],
							'bg_slug'  => [ 'type' => 'string' ],
							'fg_hex'   => [ 'type' => 'string' ],
							'bg_hex'   => [ 'type' => 'string' ],
							'ratio'    => [ 'type' => 'number' ],
							'required' => [ 'type' => 'number' ],
						],
					],
				],
				'suggestions'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'pair_id'           => [ 'type' => 'string' ],
							'label'             => [ 'type' => 'string' ],
							'required'          => [ 'type' => 'number' ],
							'original_fg'       => [ 'type' => 'string' ],
							'original_bg'       => [ 'type' => 'string' ],
							'suggested_fg'      => [ 'type' => 'string' ],
							'suggested_fg_step' => [ 'type' => 'integer' ],
							'suggested_bg'      => [ 'type' => 'string' ],
							'suggested_bg_step' => [ 'type' => 'integer' ],
						],
					],
				],
			],
		];
	}

	protected function execute_callback( $input ): array|WP_Error {
		$palette = isset( $input['palette'] ) && is_array( $input['palette'] ) ? $input['palette'] : [];
		if ( empty( $palette ) ) {
			return new WP_Error(
				'sd_ai_agent_palette_required',
				__( 'A non-empty palette array is required.', 'superdav-ai-agent' )
			);
		}

		$pairs = isset( $input['pairs'] ) && is_array( $input['pairs'] ) ? $input['pairs'] : null;

		$validator = new PaletteValidator( $palette, $pairs );
		return $validator->check();
	}

	protected function permission_callback( $input ): bool {
		// Read-only validation — same gate as other Theme Builder helpers.
		// The downstream scaffold-block-theme ability owns the install_themes
		// capability check; validation itself is informational.
		return ToolCapabilities::current_user_can( $this->name )
			&& current_user_can( 'edit_theme_options' );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

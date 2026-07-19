<?php

declare(strict_types=1);
/**
 * Compile Design Tokens ability for deterministic WordPress theme artifacts.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Services\DesignTokenCompiler;
use SdAiAgent\Services\DesignTokenContract;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles one strict design-token contract without mutating WordPress state.
 */
class CompileDesignTokensAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Compile Design Tokens', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __(
			'Validate and compile one complete schema-v1 design-token contract into deterministic theme.json v3, user Global Styles, semantic palette, style variation, and governed artifact manifest outputs. This is read-only: pass the resulting artifacts to the appropriate scaffold or governed-release ability to persist them.',
			'superdav-ai-agent'
		);
	}

	protected function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'contract' => DesignTokenContract::schema(),
			],
			'required'             => [ 'contract' ],
			'additionalProperties' => false,
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'theme_json'        => [ 'type' => 'object' ],
				'global_styles'     => [ 'type' => 'object' ],
				'palette'           => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'style_variation'   => [ 'type' => 'object' ],
				'artifact_manifest' => [ 'type' => 'object' ],
			],
			'required'   => [ 'theme_json', 'global_styles', 'palette', 'style_variation', 'artifact_manifest' ],
		];
	}

	protected function execute_callback( $input ): array|WP_Error {
		$contract = is_array( $input ) ? ( $input['contract'] ?? null ) : null;
		if ( ! is_array( $contract ) || array_is_list( $contract ) ) {
			return new WP_Error(
				'sd_ai_agent_design_token_contract_required',
				__( 'A complete design-token contract object is required.', 'superdav-ai-agent' ),
				[ 'path' => 'contract' ]
			);
		}

		return ( new DesignTokenCompiler() )->compile( $contract );
	}

	protected function permission_callback( $input ): bool {
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

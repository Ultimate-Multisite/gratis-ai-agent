<?php

declare(strict_types=1);
/**
 * Global styles (theme.json) management abilities for the AI agent.
 *
 * Provides tools for reading and updating WordPress global styles including
 * colors, typography, spacing, and layout settings. Uses the wp_global_styles
 * custom post type internally (WordPress 5.9+).
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Services\GlobalStylesService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GlobalStylesAbilities {

	/**
	 * Register all global styles abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/get-global-styles',
			[
				'label'               => __( 'Get Global Styles', 'superdav-ai-agent' ),
				'description'         => __( 'Read the current WordPress global styles (theme.json) including colors, typography, spacing, and layout settings. Returns the merged result of theme defaults and any user customizations.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'section'  => [
							'type'        => 'string',
							'description' => 'Optional section to retrieve: "color", "typography", "spacing", "layout", "elements", "blocks", or "all" (default: "all").',
						],
						'site_url' => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite (e.g. "https://example.com/mysite").',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'styles'  => [ 'type' => 'object' ],
						'section' => [ 'type' => 'string' ],
						'error'   => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_get_global_styles' ],
				'permission_callback' => function () {
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/get-global-styles' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/update-global-styles',
			[
				'label'               => __( 'Update Global Styles', 'superdav-ai-agent' ),
				'description'         => __( 'Update WordPress global styles (theme.json customizations). Pass a non-empty theme.json partial in styles (and optionally settings); never call this with empty styles/settings. Merges the provided colors, typography, spacing, elements, and layout into existing user customizations.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'styles'   => [
							'type'          => 'object',
							'minProperties' => 1,
							'description'   => 'Required non-empty theme.json styles object to merge. Example: {"color":{"background":"#fffaf3","text":"#20120d"},"typography":{"fontFamily":"system-ui, sans-serif","lineHeight":"1.6"},"elements":{"button":{"color":{"background":"#d97706","text":"#ffffff"},"border":{"radius":"0.75rem"}}}}.',
							'properties'    => [
								'color'      => [
									'type'        => 'object',
									'description' => 'Global color styles.',
									'properties'  => [
										'background' => [
											'type'        => 'string',
											'description' => 'Page background hex or preset var.',
										],
										'text'       => [
											'type'        => 'string',
											'description' => 'Body text hex or preset var.',
										],
									],
								],
								'typography' => [
									'type'        => 'object',
									'description' => 'Global typography styles.',
									'properties'  => [
										'fontFamily' => [
											'type'        => 'string',
											'description' => 'System or bundled font stack.',
										],
										'fontSize'   => [
											'type'        => 'string',
											'description' => 'Base font size.',
										],
										'lineHeight' => [
											'type'        => 'string',
											'description' => 'Base line height.',
										],
									],
								],
								'spacing'    => [
									'type'        => 'object',
									'description' => 'Global spacing styles.',
									'properties'  => [
										'blockGap' => [
											'type'        => 'string',
											'description' => 'Default block gap.',
										],
									],
								],
								'elements'   => [
									'type'        => 'object',
									'description' => 'Element styles such as links, headings, and buttons.',
								],
							],
						],
						'settings' => [
							'type'          => 'object',
							'minProperties' => 1,
							'description'   => 'Optional non-empty theme.json settings object to merge when presets changed (e.g. color.palette, typography.fontSizes, spacing.spacingSizes).',
						],
						'site_url' => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite (e.g. "https://example.com/mysite").',
						],
					],
					'required'   => [ 'styles' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'  => [ 'type' => 'boolean' ],
						'post_id'  => [ 'type' => 'integer' ],
						'message'  => [ 'type' => 'string' ],
						'error'    => [ 'type' => 'string' ],
						'affected' => self::affected_output_schema(),
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'ai'          => [
						'usage_instructions' => 'For homepage/theme builds, call update-global-styles exactly once with a real, non-empty theme.json partial. Do not pass [] or {} for styles/settings; include at least styles.color plus typography or element styles from the chosen design direction.',
					],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_update_global_styles' ],
				'permission_callback' => function () {
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/update-global-styles' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/get-theme-json',
			[
				'label'               => __( 'Get Theme JSON', 'superdav-ai-agent' ),
				'description'         => __( 'Retrieve the active theme\'s theme.json configuration as a structured object. Returns the full theme.json data including version, settings, styles, and custom templates.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'site_url' => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite (e.g. "https://example.com/mysite").',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'theme_json' => [ 'type' => 'object' ],
						'theme_name' => [ 'type' => 'string' ],
						'error'      => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_get_theme_json' ],
				'permission_callback' => function () {
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/get-theme-json' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/reset-global-styles',
			[
				'label'               => __( 'Reset Global Styles', 'superdav-ai-agent' ),
				'description'         => __( 'Reset WordPress global style customizations back to the theme defaults by deleting the wp_global_styles custom post. This removes all user-applied style overrides.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'site_url' => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite (e.g. "https://example.com/mysite").',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'  => [ 'type' => 'boolean' ],
						'message'  => [ 'type' => 'string' ],
						'error'    => [ 'type' => 'string' ],
						'affected' => self::affected_output_schema(),
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_reset_global_styles' ],
				'permission_callback' => function () {
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/reset-global-styles' );
				},
			]
		);
	}

	// ─── Handlers ─────────────────────────────────────────────────

	/**
	 * Handle getting current global styles.
	 *
	 * @param array<string,mixed> $input Input with optional section and site_url.
	 * @return array<string,mixed>|\WP_Error Result with styles data.
	 */
	public static function handle_get_global_styles( array $input ) {
		$section  = $input['section'] ?? 'all';
		$site_url = $input['site_url'] ?? '';

		$switched = self::maybe_switch_blog( $site_url );
		if ( is_wp_error( $switched ) ) {
			return $switched;
		}

		try {
			$styles = ( new GlobalStylesService() )->get_resolved_styles();

			if ( $section !== 'all' && isset( $styles[ $section ] ) ) {
				return [
					'styles'  => [ $section => $styles[ $section ] ],
					'section' => $section,
				];
			}

			return [
				'styles'  => $styles,
				'section' => 'all',
			];
		} finally {
			self::restore_switched_blog( $switched );
		}
	}

	/**
	 * Handle updating global styles.
	 *
	 * @param array<string,mixed> $input Input with styles, settings, and optional site_url.
	 * @return array<string,mixed>|\WP_Error Result with success status.
	 */
	public static function handle_update_global_styles( array $input ) {
		$new_styles   = $input['styles'] ?? [];
		$new_settings = $input['settings'] ?? [];
		$site_url     = $input['site_url'] ?? '';

		if ( empty( $new_styles ) && empty( $new_settings ) ) {
			return new \WP_Error( 'missing_input', 'Either styles or settings is required.' );
		}

		$switched = self::maybe_switch_blog( $site_url );
		if ( is_wp_error( $switched ) ) {
			return $switched;
		}

		try {
			$partial = [];
			if ( ! empty( $new_styles ) && is_array( $new_styles ) ) {
				$partial['styles'] = $new_styles;
			}
			if ( ! empty( $new_settings ) && is_array( $new_settings ) ) {
				$partial['settings'] = $new_settings;
			}

			$result = ( new GlobalStylesService() )->merge_user_document( $partial );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return [
				'success'  => true,
				'post_id'  => $result['post_id'],
				'message'  => __( 'Global styles updated successfully.', 'superdav-ai-agent' ),
				'affected' => self::build_affected_payload( [ 'styles', 'settings' ] ),
			];
		} finally {
			self::restore_switched_blog( $switched );
		}
	}

	/**
	 * Handle getting the active theme's theme.json.
	 *
	 * @param array<string,mixed> $input Input with optional site_url.
	 * @return array<string,mixed>|\WP_Error Result with theme_json data.
	 */
	public static function handle_get_theme_json( array $input ) {
		$site_url = $input['site_url'] ?? '';

		$switched = self::maybe_switch_blog( $site_url );
		if ( is_wp_error( $switched ) ) {
			return $switched;
		}

		$theme      = wp_get_theme();
		$theme_name = $theme->get( 'Name' );

		// Locate theme.json in the active theme directory.
		$theme_json_path = get_template_directory() . '/theme.json';
		$theme_json_data = [];

		if ( file_exists( $theme_json_path ) ) {
			$raw = file_get_contents( $theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $raw !== false ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$theme_json_data = $decoded;
				}
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}

		if ( empty( $theme_json_data ) ) {
			return [
				'theme_json' => [],
				'theme_name' => $theme_name,
				'message'    => __( 'No theme.json found for the active theme.', 'superdav-ai-agent' ),
			];
		}

		return [
			'theme_json' => $theme_json_data,
			'theme_name' => $theme_name,
		];
	}

	/**
	 * Handle resetting global styles to theme defaults.
	 *
	 * @param array<string,mixed> $input Input with optional site_url.
	 * @return array<string,mixed>|\WP_Error Result with success status.
	 */
	public static function handle_reset_global_styles( array $input ) {
		$site_url = $input['site_url'] ?? '';

		$switched = self::maybe_switch_blog( $site_url );
		if ( is_wp_error( $switched ) ) {
			return $switched;
		}

		try {
			$deleted = ( new GlobalStylesService() )->delete_user_document();
			if ( is_wp_error( $deleted ) ) {
				return new \WP_Error( 'delete_failed', 'Failed to delete global styles post.' );
			}

			if ( ! $deleted ) {
				return [
					'success' => true,
					'message' => __( 'No global style customizations found — already at theme defaults.', 'superdav-ai-agent' ),
				];
			}

			return [
				'success'  => true,
				'message'  => __( 'Global styles reset to theme defaults.', 'superdav-ai-agent' ),
				'affected' => self::build_affected_payload( [ 'reset' ] ),
			];
		} finally {
			self::restore_switched_blog( $switched );
		}
	}

	/**
	 * Build affected descriptor schema for global-styles mutations.
	 *
	 * @return array<string, mixed>
	 */
	private static function affected_output_schema(): array {
		return [
			'type'        => 'object',
			'description' => 'Transport descriptor for the frontend reflection bus. Global styles affect the whole site, so the public URL is the site home URL.',
			'properties'  => [
				'kind'   => [
					'type' => 'string',
					'enum' => [ 'global_styles' ],
				],
				'url'    => [ 'type' => 'string' ],
				'fields' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Build affected descriptor for global-styles mutations.
	 *
	 * @param string[] $fields Mutated fields.
	 * @return array<string, mixed>
	 */
	private static function build_affected_payload( array $fields ): array {
		return [
			'kind'   => 'global_styles',
			'url'    => home_url( '/' ),
			'fields' => array_values( array_unique( $fields ) ),
		];
	}

	// ─── Private helpers ──────────────────────────────────────────

	/**
	 * Switch to a subsite by URL if multisite is active.
	 *
	 * @param string $site_url Subsite URL to switch to.
	 * @return bool|\WP_Error True if switched, false if no switch needed, WP_Error on failure.
	 */
	private static function maybe_switch_blog( string $site_url ) {
		if ( empty( $site_url ) || ! is_multisite() ) {
			return false;
		}

		$blog_id = get_blog_id_from_url(
			// @phpstan-ignore-next-line
			(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
			// @phpstan-ignore-next-line
			(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
		);

		if ( ! $blog_id ) {
			// @phpstan-ignore-next-line
			return new \WP_Error( 'site_not_found', "Could not find a site matching URL: {$site_url}" );
		}

		if ( $blog_id !== get_current_blog_id() ) {
			switch_to_blog( $blog_id );
			return true;
		}

		return false;
	}

	/**
	 * Restore the original blog and clear resolver state after a multisite call.
	 *
	 * @param bool $switched Whether the handler switched blogs.
	 */
	private static function restore_switched_blog( bool $switched ): void {
		if ( ! $switched ) {
			return;
		}

		restore_current_blog();
		wp_clean_theme_json_cache();
	}
}

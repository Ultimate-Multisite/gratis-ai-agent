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
					return current_user_can( 'edit_theme_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/update-global-styles',
			[
				'label'               => __( 'Update Global Styles', 'superdav-ai-agent' ),
				'description'         => __( 'Update WordPress global styles (theme.json customizations). Merges the provided styles into the existing user customizations. Supports color palette, typography, spacing, and layout settings.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'styles'   => [
							'type'        => 'object',
							'description' => 'Styles object to merge. Supports nested keys: color (palette, background, text, link), typography (fontFamily, fontSize, fontWeight, lineHeight), spacing (padding, margin, blockGap), layout (contentSize, wideSize).',
						],
						'settings' => [
							'type'        => 'object',
							'description' => 'Settings object to merge (e.g. color.palette, typography.fontSizes, spacing.spacingSizes).',
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
						'success' => [ 'type' => 'boolean' ],
						'post_id' => [ 'type' => 'integer' ],
						'message' => [ 'type' => 'string' ],
						'error'   => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_update_global_styles' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_theme_options' );
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
					return current_user_can( 'edit_theme_options' );
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
						'success' => [ 'type' => 'boolean' ],
						'message' => [ 'type' => 'string' ],
						'error'   => [ 'type' => 'string' ],
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
					return current_user_can( 'edit_theme_options' );
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

		$styles = self::get_merged_global_styles();

		if ( $switched ) {
			restore_current_blog();
		}

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

		$post_id = self::get_or_create_global_styles_post();

		if ( is_wp_error( $post_id ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $post_id;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return new \WP_Error( 'post_not_found', 'Could not retrieve global styles post.' );
		}

		$existing = json_decode( $post->post_content, true );
		if ( ! is_array( $existing ) ) {
			$existing = [ 'version' => 2 ];
		}

		// Merge styles.
		if ( ! empty( $new_styles ) && is_array( $new_styles ) ) {
			if ( ! isset( $existing['styles'] ) || ! is_array( $existing['styles'] ) ) {
				$existing['styles'] = [];
			}
			$existing['styles'] = self::deep_merge( (array) $existing['styles'], $new_styles );
		}

		// Merge settings.
		if ( ! empty( $new_settings ) && is_array( $new_settings ) ) {
			if ( ! isset( $existing['settings'] ) || ! is_array( $existing['settings'] ) ) {
				$existing['settings'] = [];
			}
			$existing['settings'] = self::deep_merge( (array) $existing['settings'], $new_settings );
		}

		$updated = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_json_encode( $existing ),
			],
			true
		);

		if ( $switched ) {
			restore_current_blog();
		}

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return [
			'success' => true,
			'post_id' => $post_id,
			'message' => __( 'Global styles updated successfully.', 'superdav-ai-agent' ),
		];
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

		$post_id = self::find_global_styles_post();

		if ( ! $post_id ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return [
				'success' => true,
				'message' => __( 'No global style customizations found — already at theme defaults.', 'superdav-ai-agent' ),
			];
		}

		$deleted = wp_delete_post( $post_id, true );

		if ( $switched ) {
			restore_current_blog();
		}

		if ( ! $deleted ) {
			return new \WP_Error( 'delete_failed', 'Failed to delete global styles post.' );
		}

		return [
			'success' => true,
			'message' => __( 'Global styles reset to theme defaults.', 'superdav-ai-agent' ),
		];
	}

	// ─── Private helpers ──────────────────────────────────────────

	/**
	 * Get the merged global styles (theme defaults + user customizations).
	 *
	 * @return array<string,mixed> Merged styles object.
	 */
	private static function get_merged_global_styles(): array {
		$post_id = self::find_global_styles_post();

		if ( ! $post_id ) {
			return [];
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$data = json_decode( $post->post_content, true );
		if ( ! is_array( $data ) ) {
			return [];
		}

		return $data['styles'] ?? [];
	}

	/**
	 * Find the wp_global_styles post for the current theme.
	 *
	 * @return int|null Post ID or null if not found.
	 */
	private static function find_global_styles_post(): ?int {
		$stylesheet = get_stylesheet();

		$posts = get_posts(
			[
				'post_type'      => 'wp_global_styles',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => 'link',
						'value' => 'wp-global-styles-' . $stylesheet,
					],
				],
				'no_found_rows'  => true,
			]
		);

		if ( empty( $posts ) ) {
			return null;
		}

		return (int) $posts[0]->ID;
	}

	/**
	 * Get or create the wp_global_styles post for the current theme.
	 *
	 * @return int|\WP_Error Post ID or WP_Error on failure.
	 */
	private static function get_or_create_global_styles_post() {
		$existing = self::find_global_styles_post();
		if ( $existing ) {
			return $existing;
		}

		$stylesheet = get_stylesheet();

		$initial_content = wp_json_encode( [ 'version' => 2 ] );
		if ( $initial_content === false ) {
			$initial_content = '{"version":2}';
		}

		$post_id = wp_insert_post(
			[
				'post_title'   => 'Custom Styles',
				'post_name'    => 'wp-global-styles-' . $stylesheet,
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_content' => $initial_content,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, 'link', 'wp-global-styles-' . $stylesheet );

		return $post_id;
	}

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
	 * Deep merge two arrays, with values from $override taking precedence.
	 *
	 * @param array<string,mixed> $base     Base array.
	 * @param array<string,mixed> $override Override array.
	 * @return array<string,mixed> Merged result.
	 */
	private static function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}

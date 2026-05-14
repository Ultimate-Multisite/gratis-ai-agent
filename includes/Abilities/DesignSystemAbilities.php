<?php

declare(strict_types=1);
/**
 * Design system abilities for the AI agent.
 *
 * Provides tools for custom CSS injection, curated block pattern management,
 * site logo assignment, and theme.json preset management.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DesignSystemAbilities {

	/**
	 * Register all design system abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/inject-custom-css',
			[
				'label'               => __( 'Inject Custom CSS', 'superdav-ai-agent' ),
				'description'         => __( 'Inject or replace custom CSS for the site. Appends to or replaces the Additional CSS stored in the Customizer (wp_get_custom_css / wp_update_custom_css_post). Use to apply brand colours, typography overrides, or layout tweaks without editing theme files.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'css'     => [
							'type'        => 'string',
							'description' => 'CSS rules to inject. Must be valid CSS. Selectors should be specific enough to avoid unintended overrides.',
						],
						'mode'    => [
							'type'        => 'string',
							'enum'        => [ 'append', 'replace' ],
							'description' => 'Whether to append the new CSS to existing custom CSS (default: "append") or replace it entirely ("replace").',
						],
						'preview' => [
							'type'        => 'boolean',
							'description' => 'If true, return the resulting CSS without saving it (dry-run). Default: false.',
						],
					],
					'required'   => [ 'css' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'    => [ 'type' => 'boolean' ],
						'mode'       => [ 'type' => 'string' ],
						'preview'    => [ 'type' => 'boolean' ],
						'css_length' => [ 'type' => 'integer' ],
						'message'    => [ 'type' => 'string' ],
						'error'      => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_inject_custom_css' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_theme_options' );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
			]
		);

		wp_register_ability(
			'sd-ai-agent/curated-block-patterns',
			[
				'label'               => __( 'Curated Block Patterns', 'superdav-ai-agent' ),
				'description'         => __( 'Register a curated block pattern for the site. Patterns are stored as custom post types (wp_block) and appear in the block inserter. Provide a title, description, category, and the serialised block content.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'action'      => [
							'type'        => 'string',
							'enum'        => [ 'register', 'list', 'delete' ],
							'description' => 'Action to perform: "register" a new pattern, "list" existing patterns, or "delete" a pattern by slug.',
						],
						'title'       => [
							'type'        => 'string',
							'description' => 'Human-readable pattern title (required for "register").',
						],
						'description' => [
							'type'        => 'string',
							'description' => 'Short description of the pattern (optional, used in the inserter).',
						],
						'categories'  => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Array of pattern category slugs (e.g. ["featured", "text"]). Optional.',
						],
						'content'     => [
							'type'        => 'string',
							'description' => 'Serialised Gutenberg block content for the pattern (required for "register"). Use the create-block-content ability to generate this.',
						],
						'slug'        => [
							'type'        => 'string',
							'description' => 'Pattern slug (required for "delete"; optional for "register" — auto-generated from title if omitted).',
						],
					],
					'required'   => [ 'action' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'  => [ 'type' => 'boolean' ],
						'action'   => [ 'type' => 'string' ],
						'slug'     => [ 'type' => 'string' ],
						'patterns' => [ 'type' => 'array' ],
						'total'    => [ 'type' => 'integer' ],
						'message'  => [ 'type' => 'string' ],
						'error'    => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_curated_block_patterns' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
			]
		);

		wp_register_ability(
			'sd-ai-agent/set-site-logo',
			[
				'label'               => __( 'Set Site Logo', 'superdav-ai-agent' ),
				'description'         => __( 'Set or remove the site logo. Accepts a media attachment ID or a URL to an image already in the media library. To upload a new image first, use the media-upload ability.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'attachment_id' => [
							'type'        => 'integer',
							'description' => 'Media attachment ID to use as the site logo. Mutually exclusive with url.',
						],
						'url'           => [
							'type'        => 'string',
							'description' => 'URL of an image already in the media library. The attachment ID will be resolved automatically. Mutually exclusive with attachment_id.',
						],
						'remove'        => [
							'type'        => 'boolean',
							'description' => 'If true, remove the current site logo. Overrides attachment_id and url.',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'       => [ 'type' => 'boolean' ],
						'attachment_id' => [ 'type' => 'integer' ],
						'logo_url'      => [ 'type' => 'string' ],
						'message'       => [ 'type' => 'string' ],
						'error'         => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_set_site_logo' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);

		wp_register_ability(
			'sd-ai-agent/theme-json-presets',
			[
				'label'               => __( 'Theme JSON Presets', 'superdav-ai-agent' ),
				'description'         => __( 'Read or update theme.json global styles presets (colour palette, font sizes, spacing scale, border radius). Changes are written to the user-level theme.json override (wp_global_styles CPT) so they survive theme updates. Use "get" to inspect current values before modifying.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'action' => [
							'type'        => 'string',
							'enum'        => [ 'get', 'update', 'reset' ],
							'description' => '"get" returns the current user-level global styles. "update" merges the provided settings into the existing styles. "reset" removes the user-level override, reverting to theme defaults.',
						],
						'styles' => [
							'type'        => 'object',
							'description' => 'Partial theme.json "settings" or "styles" object to merge (required for "update"). Example: {"settings":{"color":{"palette":[{"slug":"primary","color":"#2271b1","name":"Primary"}]}}}.',
						],
					],
					'required'   => [ 'action' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'       => [ 'type' => 'boolean' ],
						'action'        => [ 'type' => 'string' ],
						'global_styles' => [ 'type' => 'object' ],
						'post_id'       => [ 'type' => 'integer' ],
						'message'       => [ 'type' => 'string' ],
						'error'         => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_theme_json_presets' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_theme_options' );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
			]
		);
	}

	// ─── Handlers ─────────────────────────────────────────────────

	/**
	 * Handle custom CSS injection.
	 *
	 * @param array<string,mixed> $input Input with 'css', optional 'mode' and 'preview'.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_inject_custom_css( array $input ) {
		$css     = $input['css'] ?? '';
		$mode    = $input['mode'] ?? 'append';
		$preview = ! empty( $input['preview'] );

		if ( empty( $css ) ) {
			return new WP_Error( 'missing_css', 'css is required.' );
		}

		if ( ! in_array( $mode, [ 'append', 'replace' ], true ) ) {
			$mode = 'append';
		}

		// Get the active theme stylesheet handle for the Customizer CSS post.
		$stylesheet = get_stylesheet();

		// Retrieve existing custom CSS.
		$existing_css = wp_get_custom_css( $stylesheet );
		if ( ! is_string( $existing_css ) ) {
			$existing_css = '';
		}

		// Build the resulting CSS.
		if ( 'replace' === $mode ) {
			$resulting_css = $css;
		} else {
			$resulting_css = $existing_css
				? $existing_css . "\n\n" . $css
				: $css;
		}

		if ( $preview ) {
			return [
				'success'    => true,
				'mode'       => $mode,
				'preview'    => true,
				'css_length' => strlen( $resulting_css ),
				'message'    => __( 'Preview only — no changes saved.', 'superdav-ai-agent' ),
			];
		}

		// Persist via the Customizer CSS post.
		$result = wp_update_custom_css_post( $resulting_css, [ 'stylesheet' => $stylesheet ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'success'    => true,
			'mode'       => $mode,
			'preview'    => false,
			'css_length' => strlen( $resulting_css ),
			'message'    => 'append' === $mode
				? __( 'Custom CSS appended successfully.', 'superdav-ai-agent' )
				: __( 'Custom CSS replaced successfully.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Handle curated block pattern management.
	 *
	 * @param array<string,mixed> $input Input with 'action', and optional 'title', 'description', 'categories', 'content', 'slug'.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_curated_block_patterns( array $input ) {
		$action = $input['action'] ?? '';

		switch ( $action ) {
			case 'register':
				return self::register_block_pattern( $input );

			case 'list':
				return self::list_block_patterns_cpt();

			case 'delete':
				return self::delete_block_pattern( $input );

			default:
				return new WP_Error( 'invalid_action', 'action must be one of: register, list, delete.' );
		}
	}

	/**
	 * Handle site logo assignment.
	 *
	 * @param array<string,mixed> $input Input with optional 'attachment_id', 'url', or 'remove'.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_set_site_logo( array $input ) {
		$remove = ! empty( $input['remove'] );

		if ( $remove ) {
			remove_theme_mod( 'custom_logo' );
			return [
				'success'       => true,
				'attachment_id' => 0,
				'logo_url'      => '',
				'message'       => __( 'Site logo removed.', 'superdav-ai-agent' ),
			];
		}

		// @phpstan-ignore-next-line
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		$url           = $input['url'] ?? '';

		// Resolve attachment ID from URL if needed.
		if ( ! $attachment_id && ! empty( $url ) ) {
			$attachment_id = attachment_url_to_postid( $url );
			if ( ! $attachment_id ) {
				return new WP_Error(
					'attachment_not_found',
					// @phpstan-ignore-next-line
					sprintf( 'Could not find a media attachment for URL: %s', $url )
				);
			}
		}

		if ( ! $attachment_id ) {
			return new WP_Error( 'missing_input', 'Provide attachment_id, url, or set remove to true.' );
		}

		// Verify the attachment exists and is an image.
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error(
				'invalid_attachment',
				// @phpstan-ignore-next-line
				sprintf( 'Attachment %d not found or is not a media file.', $attachment_id )
			);
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! is_string( $mime ) || strpos( $mime, 'image/' ) !== 0 ) {
			return new WP_Error(
				'not_an_image',
				// @phpstan-ignore-next-line
				sprintf( 'Attachment %d is not an image (mime: %s).', $attachment_id, (string) $mime )
			);
		}

		set_theme_mod( 'custom_logo', $attachment_id );

		$logo_url = wp_get_attachment_image_url( $attachment_id, 'full' );

		return [
			'success'       => true,
			'attachment_id' => $attachment_id,
			'logo_url'      => is_string( $logo_url ) ? $logo_url : '',
			'message'       => sprintf(
				/* translators: %d: attachment ID */
				__( 'Site logo set to attachment %d.', 'superdav-ai-agent' ),
				$attachment_id
			),
		];
	}

	/**
	 * Handle theme.json global styles preset management.
	 *
	 * @param array<string,mixed> $input Input with 'action' and optional 'styles'.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_theme_json_presets( array $input ) {
		$action = $input['action'] ?? '';

		switch ( $action ) {
			case 'get':
				return self::get_global_styles();

			case 'update':
				return self::update_global_styles( $input );

			case 'reset':
				return self::reset_global_styles();

			default:
				return new WP_Error( 'invalid_action', 'action must be one of: get, update, reset.' );
		}
	}

	// ─── Private helpers ──────────────────────────────────────────

	/**
	 * Register a block pattern as a wp_block CPT post.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function register_block_pattern( array $input ) {
		$title      = (string) ( $input['title'] ?? '' );
		$content    = (string) ( $input['content'] ?? '' );
		$desc       = (string) ( $input['description'] ?? '' );
		$categories = $input['categories'] ?? [];
		$slug       = (string) ( $input['slug'] ?? '' );

		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', 'title is required for action "register".' );
		}

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', 'content is required for action "register".' );
		}

		// Auto-generate slug from title if not provided.
		if ( empty( $slug ) ) {
			$slug = sanitize_title( $title );
		}

		// Build post meta for pattern categories.
		$meta = [];
		if ( ! empty( $categories ) && is_array( $categories ) ) {
			$meta['wp_pattern_category'] = $categories;
		}

		$post_data = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'wp_block',
			'post_name'    => $slug,
			'post_excerpt' => $desc,
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Assign pattern categories via taxonomy if available.
		if ( ! empty( $categories ) && is_array( $categories ) ) {
			$taxonomy = 'wp_pattern_category';
			if ( taxonomy_exists( $taxonomy ) ) {
				$term_ids = [];
				foreach ( $categories as $cat_slug ) {
					$cat_slug = (string) $cat_slug;
					$term     = get_term_by( 'slug', $cat_slug, $taxonomy );
					if ( $term && ! is_wp_error( $term ) ) {
						$term_ids[] = $term->term_id;
					} else {
						// Create the category if it doesn't exist.
						$new_term = wp_insert_term( $cat_slug, $taxonomy, [ 'slug' => $cat_slug ] );
						if ( ! is_wp_error( $new_term ) ) {
							$term_ids[] = $new_term['term_id'];
						}
					}
				}
				if ( ! empty( $term_ids ) ) {
					wp_set_object_terms( $post_id, $term_ids, $taxonomy );
				}
			}
		}

		return [
			'success' => true,
			'action'  => 'register',
			'slug'    => $slug,
			'message' => sprintf(
				/* translators: %s: pattern title */
				__( 'Block pattern "%s" registered successfully.', 'superdav-ai-agent' ),
				$title
			),
		];
	}

	/**
	 * List block patterns stored as wp_block CPT posts.
	 *
	 * @return array<string,mixed>
	 */
	private static function list_block_patterns_cpt(): array {
		$posts = get_posts(
			[
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$patterns = [];
		foreach ( $posts as $post ) {
			$categories = [];
			$terms      = get_the_terms( $post->ID, 'wp_pattern_category' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = $term->slug;
				}
			}

			$patterns[] = [
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'slug'        => $post->post_name,
				'description' => $post->post_excerpt,
				'categories'  => $categories,
				'content'     => strlen( $post->post_content ) > 300
					? substr( $post->post_content, 0, 300 ) . '...'
					: $post->post_content,
			];
		}

		return [
			'success'  => true,
			'action'   => 'list',
			'patterns' => $patterns,
			'total'    => count( $patterns ),
			'message'  => sprintf(
				/* translators: %d: pattern count */
				__( 'Found %d block pattern(s).', 'superdav-ai-agent' ),
				count( $patterns )
			),
		];
	}

	/**
	 * Delete a block pattern by slug.
	 *
	 * @param array<string,mixed> $input Input args with 'slug'.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function delete_block_pattern( array $input ) {
		$slug = $input['slug'] ?? '';

		if ( empty( $slug ) ) {
			return new WP_Error( 'missing_slug', 'slug is required for action "delete".' );
		}

		$posts = get_posts(
			[
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
			]
		);

		if ( empty( $posts ) ) {
			return new WP_Error(
				'pattern_not_found',
				// @phpstan-ignore-next-line
				sprintf( 'No block pattern found with slug "%s".', $slug )
			);
		}

		$post_id = $posts[0]->ID;
		$deleted = wp_delete_post( $post_id, true );

		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', 'Failed to delete the block pattern.' );
		}

		return [
			'success' => true,
			'action'  => 'delete',
			'slug'    => $slug,
			'message' => sprintf(
				/* translators: %s: pattern slug */
				__( 'Block pattern "%s" deleted.', 'superdav-ai-agent' ),
				$slug
			),
		];
	}

	/**
	 * Get the current user-level global styles (theme.json override).
	 *
	 * @return array<string,mixed>
	 */
	private static function get_global_styles(): array {
		$post = self::get_global_styles_post();

		if ( ! $post ) {
			return [
				'success'       => true,
				'action'        => 'get',
				'global_styles' => (object) [],
				'post_id'       => 0,
				'message'       => __( 'No user-level global styles override found. Theme defaults are active.', 'superdav-ai-agent' ),
			];
		}

		$decoded = json_decode( $post->post_content, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = [];
		}

		return [
			'success'       => true,
			'action'        => 'get',
			'global_styles' => $decoded,
			'post_id'       => $post->ID,
			'message'       => __( 'Global styles retrieved.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Update the user-level global styles by merging provided styles.
	 *
	 * @param array<string,mixed> $input Input args with 'styles'.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function update_global_styles( array $input ) {
		$new_styles = $input['styles'] ?? null;

		if ( empty( $new_styles ) || ! is_array( $new_styles ) ) {
			return new WP_Error( 'missing_styles', 'styles object is required for action "update".' );
		}

		$post    = self::get_global_styles_post();
		$current = [];

		if ( $post ) {
			$decoded = json_decode( $post->post_content, true );
			if ( is_array( $decoded ) ) {
				$current = $decoded;
			}
		}

		// Deep merge: new_styles takes precedence.
		$merged = self::deep_merge( $current, $new_styles );

		$json = wp_json_encode( $merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new WP_Error( 'json_encode_failed', 'Failed to encode styles as JSON.' );
		}

		if ( $post ) {
			$result = wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $json,
				],
				true
			);
		} else {
			$result = wp_insert_post(
				[
					'post_title'   => 'Custom Styles',
					'post_content' => $json,
					'post_status'  => 'publish',
					'post_type'    => 'wp_global_styles',
				],
				true
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'success'       => true,
			'action'        => 'update',
			'global_styles' => $merged,
			'post_id'       => is_int( $result ) ? $result : ( $post ? $post->ID : 0 ),
			'message'       => __( 'Global styles updated successfully.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Reset the user-level global styles override.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function reset_global_styles() {
		$post = self::get_global_styles_post();

		if ( ! $post ) {
			return [
				'success'       => true,
				'action'        => 'reset',
				'global_styles' => (object) [],
				'post_id'       => 0,
				'message'       => __( 'No user-level global styles to reset.', 'superdav-ai-agent' ),
			];
		}

		$deleted = wp_delete_post( $post->ID, true );

		if ( ! $deleted ) {
			return new WP_Error( 'reset_failed', 'Failed to delete the global styles override.' );
		}

		return [
			'success'       => true,
			'action'        => 'reset',
			'global_styles' => (object) [],
			'post_id'       => 0,
			'message'       => __( 'Global styles reset to theme defaults.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Retrieve the user-level wp_global_styles CPT post.
	 *
	 * WordPress stores user customisations in a wp_global_styles post with
	 * post_name = 'wp-global-styles-{stylesheet}'. We look for the most recent
	 * published post of this type.
	 *
	 * @return \WP_Post|null
	 */
	private static function get_global_styles_post(): ?\WP_Post {
		$stylesheet = get_stylesheet();
		$post_name  = 'wp-global-styles-' . $stylesheet;

		$posts = get_posts(
			[
				'post_type'      => 'wp_global_styles',
				'post_status'    => 'publish',
				'name'           => $post_name,
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		if ( ! empty( $posts ) ) {
			return $posts[0];
		}

		// Fallback: any published wp_global_styles post (theme-agnostic).
		$posts = get_posts(
			[
				'post_type'      => 'wp_global_styles',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Recursively merge two arrays, with $override taking precedence.
	 *
	 * Unlike array_merge_recursive(), this replaces scalar values rather than
	 * creating arrays of values.
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

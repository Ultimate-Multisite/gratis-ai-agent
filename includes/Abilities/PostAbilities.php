<?php

declare(strict_types=1);
/**
 * Post management abilities for the AI agent.
 *
 * Provides post creation, retrieval, update, and deletion.
 * Ported from the WordPress/ai experiments plugin pattern.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Models\MarkdownToBlocks;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostAbilities {

	/**
	 * Register all post management abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/get-post',
			[
				'label'               => __( 'Get Post', 'superdav-ai-agent' ),
				'description'         => __( 'Retrieve a WordPress post by ID. Returns title, content, excerpt, status, author, categories, tags, featured image, and meta.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'   => [
							'type'        => 'integer',
							'description' => 'The ID of the post to retrieve.',
						],
						'post_type' => [
							'type'        => 'string',
							'description' => 'Post type to validate against (default: any).',
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'             => [ 'type' => 'integer' ],
						'title'          => [ 'type' => 'string' ],
						'content'        => [ 'type' => 'string' ],
						'excerpt'        => [ 'type' => 'string' ],
						'status'         => [ 'type' => 'string' ],
						'post_type'      => [ 'type' => 'string' ],
						'author_id'      => [ 'type' => 'integer' ],
						'author_name'    => [ 'type' => 'string' ],
						'date'           => [ 'type' => 'string' ],
						'modified'       => [ 'type' => 'string' ],
						'permalink'      => [ 'type' => 'string' ],
						'categories'     => [ 'type' => 'array' ],
						'tags'           => [ 'type' => 'array' ],
						'featured_image' => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_get_post' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/create-post',
			[
				'label'               => __( 'Create Post', 'superdav-ai-agent' ),
				'description'         => __( 'Create a new WordPress post or page. This is the PRIMARY tool for creating any content — blog posts, landing pages, about pages, etc. Write content directly as HTML or markdown (auto-converted to Gutenberg blocks). Set post_type to "page" for pages or "post" for blog posts. Set status to "publish" to make it live immediately.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'title'             => [
							'type'        => 'string',
							'description' => 'The post title.',
						],
						'content'           => [
							'type'        => 'string',
							'description' => 'The post content. Write in markdown (headings with ##, lists with -, bold with **) or HTML — markdown is automatically converted to Gutenberg blocks.',
						],
						'excerpt'           => [
							'type'        => 'string',
							'description' => 'Optional post excerpt.',
						],
						'status'            => [
							'type'        => 'string',
							'description' => 'Post status: "draft" (default), "publish", "pending", "private", or "future".',
							'enum'        => [ 'draft', 'publish', 'pending', 'private', 'future' ],
						],
						'post_type'         => [
							'type'        => 'string',
							'description' => 'Post type (default: "post"). Use "page" for pages.',
						],
						'categories'        => [
							'type'        => 'array',
							'description' => 'Array of category IDs (integers) or names (strings) to assign.',
							'items'       => [
								'oneOf' => [
									[ 'type' => 'string' ],
									[ 'type' => 'integer' ],
								],
							],
						],
						'tags'              => [
							'type'        => 'array',
							'description' => 'Array of tag names to assign.',
							'items'       => [ 'type' => 'string' ],
						],
						'featured_image_id' => [
							'type'        => 'integer',
							'description' => 'Attachment ID to set as the featured image (e.g. from stock-image or generate-image result).',
						],
						'meta'              => [
							'type'        => 'object',
							'description' => 'Key-value pairs of post meta to set.',
						],
						'page_template'     => [
							'type'        => 'string',
							'description' => 'Page template filename to assign (e.g. "page-full-width.php"). Only meaningful for post_type "page".',
						],
						'site_url'          => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [ 'title' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'   => [ 'type' => 'integer' ],
						'permalink' => [ 'type' => 'string' ],
						'status'    => [ 'type' => 'string' ],
						'post_type' => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_create_post' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/update-post',
			[
				'label'               => __( 'Update Post', 'superdav-ai-agent' ),
				'description'         => __( 'Update an existing WordPress post or page. Only provided fields are changed; omitted fields are left as-is. Can update title, content, excerpt, status, categories, tags, featured image (featured_image_id), and custom meta. IMPORTANT: You must supply post_id — if you do not know it, call list-posts first (search by title) to find it. Do NOT call create-post when the intent is to update an existing post.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'           => [
							'type'        => 'integer',
							'description' => 'The ID of the post to update.',
						],
						'title'             => [
							'type'        => 'string',
							'description' => 'New post title.',
						],
						'content'           => [
							'type'        => 'string',
							'description' => 'New post content.',
						],
						'excerpt'           => [
							'type'        => 'string',
							'description' => 'New post excerpt.',
						],
						'status'            => [
							'type'        => 'string',
							'description' => 'New post status.',
							'enum'        => [ 'draft', 'publish', 'pending', 'private', 'future', 'trash' ],
						],
						'categories'        => [
							'type'        => 'array',
							'description' => 'Replace categories with this array of IDs (integers) or names (strings).',
							'items'       => [
								'oneOf' => [
									[ 'type' => 'string' ],
									[ 'type' => 'integer' ],
								],
							],
						],
						'tags'              => [
							'type'        => 'array',
							'description' => 'Replace tags with this array of names.',
							'items'       => [ 'type' => 'string' ],
						],
						'featured_image_id' => [
							'type'        => 'integer',
							'description' => 'Attachment ID to set as the featured image.',
						],
						'meta'              => [
							'type'        => 'object',
							'description' => 'Key-value pairs of post meta to update.',
						],
						'page_template'     => [
							'type'        => 'string',
							'description' => 'Page template filename to assign (e.g. "page-full-width.php"). Only meaningful for post_type "page".',
						],
						'site_url'          => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'   => [ 'type' => 'integer' ],
						'permalink' => [ 'type' => 'string' ],
						'status'    => [ 'type' => 'string' ],
						'post_type' => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_update_post' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/list-posts',
			[
				'label'               => __( 'List Posts', 'superdav-ai-agent' ),
				'description'         => __( 'Query and list WordPress posts or pages. Filter by post_type, status, search term, category, tag, and date. Returns id, title, excerpt, status, post_type, date, permalink, and featured_image_url for each match. Default: 10 most recent published posts.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type' => [
							'type'        => 'string',
							'description' => 'Post type to query (default: "post"). Use "page" for pages, "product" for WooCommerce products.',
						],
						'status'    => [
							'type'        => 'string',
							'description' => 'Post status filter: "publish" (default), "draft", "pending", "private", "future", "trash", or "any".',
							'enum'        => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' ],
						],
						'per_page'  => [
							'type'        => 'integer',
							'description' => 'Number of posts to return (default: 10, max: 50).',
						],
						'search'    => [
							'type'        => 'string',
							'description' => 'Search term to filter posts by title or content.',
						],
						'category'  => [
							'type'        => 'string',
							'description' => 'Category name or slug to filter by. Human-readable names (e.g. "My Category") are resolved to their slug automatically.',
						],
						'tag'       => [
							'type'        => 'string',
							'description' => 'Tag name or slug to filter by. Human-readable names are resolved to their slug automatically.',
						],
						'orderby'   => [
							'type'        => 'string',
							'description' => 'Order results by: "date" (default), "title", "modified", "menu_order", "rand".',
							'enum'        => [ 'date', 'title', 'modified', 'menu_order', 'rand' ],
						],
						'order'     => [
							'type'        => 'string',
							'description' => 'Sort direction: "DESC" (default, newest first) or "ASC" (oldest first).',
							'enum'        => [ 'DESC', 'ASC' ],
						],
						'site_url'  => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'posts'    => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'                 => [ 'type' => 'integer' ],
									'title'              => [ 'type' => 'string' ],
									'excerpt'            => [ 'type' => 'string' ],
									'status'             => [ 'type' => 'string' ],
									'post_type'          => [ 'type' => 'string' ],
									'date'               => [ 'type' => 'string' ],
									'modified'           => [ 'type' => 'string' ],
									'permalink'          => [ 'type' => 'string' ],
									'featured_image_url' => [ 'type' => 'string' ],
									'categories'         => [ 'type' => 'array' ],
									'tags'               => [ 'type' => 'array' ],
								],
							],
						],
						'total'    => [ 'type' => 'integer' ],
						'per_page' => [ 'type' => 'integer' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_list_posts' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/batch-create-posts',
			[
				'label'               => __( 'Batch Create Posts', 'superdav-ai-agent' ),
				'description'         => __( 'Create multiple WordPress posts or pages in a single call. Accepts an array of post definitions and returns an array of results. Use this instead of calling create-post repeatedly when building a full site — reduces ~7 sequential calls to 1.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'posts' => [
							'type'        => 'array',
							'description' => 'Array of post definitions to create.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'title'             => [
										'type'        => 'string',
										'description' => 'The post title (required).',
									],
									'content'           => [
										'type'        => 'string',
										'description' => 'Post content. Markdown is auto-converted to Gutenberg blocks.',
									],
									'excerpt'           => [
										'type'        => 'string',
										'description' => 'Optional post excerpt.',
									],
									'status'            => [
										'type'        => 'string',
										'description' => 'Post status: "draft" (default), "publish", "pending", "private", or "future".',
										'enum'        => [ 'draft', 'publish', 'pending', 'private', 'future' ],
									],
									'post_type'         => [
										'type'        => 'string',
										'description' => 'Post type (default: "post"). Use "page" for pages.',
									],
									'page_template'     => [
										'type'        => 'string',
										'description' => 'Page template file (e.g. "templates/blank.php"). Maps to _wp_page_template meta.',
									],
									'categories'        => [
										'type'        => 'array',
										'description' => 'Array of category IDs (integers) or names (strings).',
										'items'       => [
											'oneOf' => [
												[ 'type' => 'string' ],
												[ 'type' => 'integer' ],
											],
										],
									],
									'tags'              => [
										'type'        => 'array',
										'description' => 'Array of tag names.',
										'items'       => [ 'type' => 'string' ],
									],
									'featured_image_id' => [
										'type'        => 'integer',
										'description' => 'Attachment ID to set as the featured image.',
									],
									'meta'              => [
										'type'        => 'object',
										'description' => 'Key-value pairs of post meta to set.',
									],
									'site_url'          => [
										'type'        => 'string',
										'description' => 'Subsite URL for multisite. Omit for the main site.',
									],
								],
								'required'   => [ 'title' ],
							],
						],
					],
					'required'   => [ 'posts' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'results'       => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'post_id'   => [ 'type' => 'integer' ],
									'permalink' => [ 'type' => 'string' ],
									'title'     => [ 'type' => 'string' ],
									'status'    => [ 'type' => 'string' ],
									'error'     => [ 'type' => 'string' ],
								],
							],
						],
						'created_count' => [ 'type' => 'integer' ],
						'error_count'   => [ 'type' => 'integer' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_batch_create_posts' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/delete-post',
			[
				'label'               => __( 'Delete Post', 'superdav-ai-agent' ),
				'description'         => __( 'Move a WordPress post to the trash, or permanently delete it. Defaults to trash (recoverable). Set force_delete to true for permanent deletion.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'      => [
							'type'        => 'integer',
							'description' => 'The ID of the post to delete.',
						],
						'force_delete' => [
							'type'        => 'boolean',
							'description' => 'If true, permanently delete instead of trashing (default: false).',
						],
						'site_url'     => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'      => [ 'type' => 'integer' ],
						'title'        => [ 'type' => 'string' ],
						'action'       => [ 'type' => 'string' ],
						'force_delete' => [ 'type' => 'boolean' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_delete_post' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'delete_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/set-featured-image',
			[
				'label'               => __( 'Set Featured Image', 'superdav-ai-agent' ),
				'description'         => __( 'Set or remove the featured image (post thumbnail) for any WordPress post or page. Pass featured_image_id to set a new image, or 0 to remove the existing thumbnail. Use this as a focused single-purpose call after uploading a stock or generated image — no other post fields are changed.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'           => [
							'type'        => 'integer',
							'description' => 'The ID of the post or page to update.',
						],
						'featured_image_id' => [
							'type'        => 'integer',
							'description' => 'Attachment ID to set as the featured image. Pass 0 to remove the existing thumbnail.',
						],
						'site_url'          => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [ 'post_id', 'featured_image_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'           => [ 'type' => 'integer' ],
						'featured_image_id' => [ 'type' => 'integer' ],
						'result'            => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_set_featured_image' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * Handle the list-posts ability.
	 *
	 * @param array<string, mixed> $input Input with optional post_type, status, per_page, search, etc.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_list_posts( array $input ) {
		// @phpstan-ignore-next-line
		$post_type = sanitize_text_field( $input['post_type'] ?? 'post' );
		// @phpstan-ignore-next-line
		$status   = sanitize_text_field( $input['status'] ?? 'publish' );
		$per_page = isset( $input['per_page'] ) ? min( (int) $input['per_page'], 50 ) : 10;
		$per_page = max( 1, $per_page );
		// @phpstan-ignore-next-line
		$search = sanitize_text_field( $input['search'] ?? '' );
		// @phpstan-ignore-next-line
		$category = sanitize_text_field( $input['category'] ?? '' );
		// @phpstan-ignore-next-line
		$tag = sanitize_text_field( $input['tag'] ?? '' );
		// @phpstan-ignore-next-line
		$orderby = sanitize_text_field( $input['orderby'] ?? 'date' );
		// @phpstan-ignore-next-line
		$order    = strtoupper( sanitize_text_field( $input['order'] ?? 'DESC' ) );
		$site_url = $input['site_url'] ?? '';

		$allowed_orderby = [ 'date', 'title', 'modified', 'menu_order', 'rand' ];
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'date';
		}
		if ( ! in_array( $order, [ 'DESC', 'ASC' ], true ) ) {
			$order = 'DESC';
		}

		$switched = false;
		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);
			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$query_args = [
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'orderby'        => $orderby,
			'order'          => $order,
			'no_found_rows'  => false,
		];

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		if ( '' !== $category ) {
			$query_args['category_name'] = self::resolve_term_slug( $category, 'category' );
		}

		if ( '' !== $tag ) {
			$query_args['tag'] = self::resolve_term_slug( $tag, 'post_tag' );
		}

		$query = new \WP_Query( $query_args );
		$posts = [];

		foreach ( $query->posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}

			$thumbnail_url = '';
			$thumbnail_id  = get_post_thumbnail_id( $post->ID );
			if ( $thumbnail_id ) {
				$image_src     = wp_get_attachment_image_src( $thumbnail_id, 'medium' );
				$thumbnail_url = $image_src ? $image_src[0] : '';
			}

			$categories = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
			$tags       = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );

			$excerpt = $post->post_excerpt;
			if ( '' === $excerpt && '' !== $post->post_content ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20, '...' );
			}

			$posts[] = [
				'id'                 => $post->ID,
				'title'              => $post->post_title,
				'excerpt'            => $excerpt,
				'status'             => $post->post_status,
				'post_type'          => $post->post_type,
				'date'               => $post->post_date,
				'modified'           => $post->post_modified,
				'permalink'          => get_permalink( $post->ID ) ?: '',
				'featured_image_url' => $thumbnail_url,
				'categories'         => is_wp_error( $categories ) ? [] : $categories,
				'tags'               => is_wp_error( $tags ) ? [] : $tags,
			];
		}

		$total = (int) $query->found_posts;

		if ( $switched ) {
			restore_current_blog();
		}

		return [
			'posts'    => $posts,
			'total'    => $total,
			'per_page' => $per_page,
		];
	}

	/**
	 * Handle the get-post ability.
	 *
	 * @param array<string, mixed> $input Input with post_id and optional post_type.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_get_post( array $input ) {
		// @phpstan-ignore-next-line
		$post_id = (int) ( $input['post_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$post_type = sanitize_text_field( $input['post_type'] ?? 'any' );

		if ( ! $post_id ) {
			return new WP_Error( 'ai_agent_empty_post_id', __( 'post_id is required.', 'superdav-ai-agent' ) );
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return new WP_Error(
				'ai_agent_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d not found.', 'superdav-ai-agent' ), $post_id )
			);
		}

		if ( $post_type !== 'any' && $post->post_type !== $post_type ) {
			return new WP_Error(
				'ai_agent_post_type_mismatch',
				/* translators: 1: post ID, 2: expected type, 3: actual type */
				sprintf( __( 'Post %1$d is of type "%2$s", not "%3$s".', 'superdav-ai-agent' ), $post_id, $post->post_type, $post_type )
			);
		}

		$categories = wp_get_post_categories( $post_id, [ 'fields' => 'names' ] );
		$tags       = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );

		$featured_image_url = '';
		$thumbnail_id       = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$image_src          = wp_get_attachment_image_src( $thumbnail_id, 'full' );
			$featured_image_url = $image_src ? $image_src[0] : '';
		}

		return [
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'status'         => $post->post_status,
			'post_type'      => $post->post_type,
			'author_id'      => (int) $post->post_author,
			'author_name'    => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'permalink'      => get_permalink( $post_id ) ?: '',
			'categories'     => is_wp_error( $categories ) ? [] : $categories,
			'tags'           => is_wp_error( $tags ) ? [] : $tags,
			'featured_image' => $featured_image_url,
		];
	}

	/**
	 * Handle the create-post ability.
	 *
	 * @param array<string, mixed> $input Input with title, content, status, etc.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_create_post( array $input ) {
		// @phpstan-ignore-next-line
		$title       = sanitize_text_field( $input['title'] ?? '' );
		$raw_content = $input['content'] ?? '';
		// @phpstan-ignore-next-line
		$content = wp_kses_post( self::maybe_convert_markdown( $raw_content ) );
		// @phpstan-ignore-next-line
		$excerpt = sanitize_textarea_field( $input['excerpt'] ?? '' );
		// @phpstan-ignore-next-line
		$status = sanitize_text_field( $input['status'] ?? 'draft' );
		// @phpstan-ignore-next-line
		$post_type = sanitize_text_field( $input['post_type'] ?? 'post' );
		$site_url  = $input['site_url'] ?? '';

		if ( empty( $title ) ) {
			return new WP_Error( 'ai_agent_empty_title', __( 'Post title is required.', 'superdav-ai-agent' ) );
		}

		$allowed_statuses = [ 'draft', 'publish', 'pending', 'private', 'future' ];
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}

		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$post_data = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'post_type'    => $post_type,
		];

		// @phpstan-ignore-next-line
		$page_template = sanitize_text_field( $input['page_template'] ?? '' );

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $post_id;
		}

		// Assign categories.
		$categories = $input['categories'] ?? [];
		if ( ! empty( $categories ) && is_array( $categories ) ) {
			// @phpstan-ignore-next-line
			$cat_ids = self::resolve_category_ids( $categories );
			wp_set_post_categories( $post_id, $cat_ids );
		}

		// Assign tags.
		$tags = $input['tags'] ?? [];
		if ( ! empty( $tags ) && is_array( $tags ) ) {
			// @phpstan-ignore-next-line
			$tag_names = array_map( 'sanitize_text_field', $tags );
			wp_set_post_tags( $post_id, $tag_names );
		}

		if ( '' !== $page_template ) {
			update_post_meta( $post_id, '_wp_page_template', $page_template );
		}

		// Set featured image if provided.
		// @phpstan-ignore-next-line
		$featured_image_id = (int) ( $input['featured_image_id'] ?? 0 );
		if ( $featured_image_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_image_id );
		}

		// Set post meta.
		$meta = $input['meta'] ?? [];
		if ( ! empty( $meta ) && is_array( $meta ) ) {
			foreach ( $meta as $key => $value ) {
				update_post_meta( $post_id, sanitize_key( $key ), $value );
			}
		}

		$permalink = get_permalink( $post_id );

		if ( $switched ) {
			restore_current_blog();
		}

		return [
			'post_id'   => $post_id,
			'permalink' => $permalink ?: '',
			'status'    => $status,
			'post_type' => $post_type,
		];
	}

	/**
	 * Handle the batch-create-posts ability.
	 *
	 * Iterates over the provided post definitions and calls handle_create_post()
	 * for each one. Errors are captured per-item so partial success is possible —
	 * the caller receives a results array alongside created_count and error_count
	 * summary fields.
	 *
	 * @param array<string, mixed> $input Input with a 'posts' array of post definitions.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_batch_create_posts( array $input ) {
		$posts_input = $input['posts'] ?? [];

		if ( ! is_array( $posts_input ) || empty( $posts_input ) ) {
			return new WP_Error(
				'ai_agent_batch_empty',
				__( 'posts array is required and must not be empty.', 'superdav-ai-agent' )
			);
		}

		$results       = [];
		$created_count = 0;
		$error_count   = 0;

		foreach ( $posts_input as $post_def ) {
			if ( ! is_array( $post_def ) ) {
				++$error_count;
				$results[] = [
					'post_id'   => 0,
					'permalink' => '',
					'title'     => '',
					'status'    => '',
					'error'     => __( 'Post definition must be an object.', 'superdav-ai-agent' ),
				];
				continue;
			}

			$result = self::handle_create_post( $post_def );

			if ( is_wp_error( $result ) ) {
				++$error_count;
				$results[] = [
					'post_id'   => 0,
					'permalink' => '',
					'title'     => sanitize_text_field( (string) ( $post_def['title'] ?? '' ) ),
					'status'    => '',
					'error'     => $result->get_error_message(),
				];
			} else {
				++$created_count;
				$results[] = [
					'post_id'   => $result['post_id'],
					'permalink' => $result['permalink'],
					'title'     => sanitize_text_field( (string) ( $post_def['title'] ?? '' ) ),
					'status'    => $result['status'],
					'error'     => '',
				];
			}
		}

		return [
			'results'       => $results,
			'created_count' => $created_count,
			'error_count'   => $error_count,
		];
	}

	/**
	 * Handle the update-post ability.
	 *
	 * @param array<string, mixed> $input Input with post_id and fields to update.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_update_post( array $input ) {
		// @phpstan-ignore-next-line
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$site_url = $input['site_url'] ?? '';

		if ( ! $post_id ) {
			return new WP_Error( 'ai_agent_empty_post_id', __( 'post_id is required.', 'superdav-ai-agent' ) );
		}

		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return new WP_Error(
				'ai_agent_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d not found.', 'superdav-ai-agent' ), $post_id )
			);
		}

		$post_data = [ 'ID' => $post_id ];

		if ( isset( $input['title'] ) ) {
			// @phpstan-ignore-next-line
			$post_data['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			// @phpstan-ignore-next-line
			$post_data['post_content'] = wp_kses_post( self::maybe_convert_markdown( $input['content'] ) );
		}
		if ( isset( $input['excerpt'] ) ) {
			// @phpstan-ignore-next-line
			$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
		}
		if ( isset( $input['status'] ) ) {
			$allowed_statuses = [ 'draft', 'publish', 'pending', 'private', 'future', 'trash' ];
			// @phpstan-ignore-next-line
			$new_status = sanitize_text_field( $input['status'] );
			if ( in_array( $new_status, $allowed_statuses, true ) ) {
				$post_data['post_status'] = $new_status;
			}
		}
		$page_template = null;
		if ( isset( $input['page_template'] ) ) {
			// @phpstan-ignore-next-line
			$page_template = sanitize_text_field( $input['page_template'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $result;
		}

		// Update categories if provided.
		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			// @phpstan-ignore-next-line
			$cat_ids = self::resolve_category_ids( $input['categories'] );
			wp_set_post_categories( $post_id, $cat_ids );
		}

		// Update tags if provided.
		if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			// @phpstan-ignore-next-line
			$tag_names = array_map( 'sanitize_text_field', $input['tags'] );
			wp_set_post_tags( $post_id, $tag_names );
		}

		if ( null !== $page_template ) {
			update_post_meta( $post_id, '_wp_page_template', $page_template );
		}

		// Update featured image if provided.
		// @phpstan-ignore-next-line
		$featured_image_id = isset( $input['featured_image_id'] ) ? (int) $input['featured_image_id'] : 0;
		if ( $featured_image_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_image_id );
		}

		// Update meta if provided.
		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			foreach ( $input['meta'] as $key => $value ) {
				update_post_meta( $post_id, sanitize_key( $key ), $value );
			}
		}

		$updated_post = get_post( $post_id );
		$permalink    = get_permalink( $post_id );

		if ( $switched ) {
			restore_current_blog();
		}

		return [
			'post_id'   => $post_id,
			'permalink' => $permalink ?: '',
			'status'    => $updated_post instanceof WP_Post ? $updated_post->post_status : '',
			'post_type' => $updated_post instanceof WP_Post ? $updated_post->post_type : '',
		];
	}

	/**
	 * Handle the delete-post ability.
	 *
	 * @param array<string, mixed> $input Input with post_id and optional force_delete, site_url.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_delete_post( array $input ) {
		// @phpstan-ignore-next-line
		$post_id      = (int) ( $input['post_id'] ?? 0 );
		$force_delete = (bool) ( $input['force_delete'] ?? false );
		$site_url     = $input['site_url'] ?? '';

		if ( ! $post_id ) {
			return new WP_Error( 'ai_agent_empty_post_id', __( 'post_id is required.', 'superdav-ai-agent' ) );
		}

		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return new WP_Error(
				'ai_agent_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d not found.', 'superdav-ai-agent' ), $post_id )
			);
		}

		$title  = $post->post_title;
		$result = wp_delete_post( $post_id, $force_delete );

		if ( $switched ) {
			restore_current_blog();
		}

		if ( ! $result ) {
			return new WP_Error(
				'ai_agent_delete_failed',
				/* translators: %d: post ID */
				sprintf( __( 'Failed to delete post %d.', 'superdav-ai-agent' ), $post_id )
			);
		}

		return [
			'post_id'      => $post_id,
			'title'        => $title,
			'action'       => $force_delete ? 'permanently_deleted' : 'trashed',
			'force_delete' => $force_delete,
		];
	}

	/**
	 * Handle the set-featured-image ability.
	 *
	 * @param array<string, mixed> $input Input with post_id, featured_image_id, and optional site_url.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_set_featured_image( array $input ) {
		// @phpstan-ignore-next-line
		$post_id = (int) ( $input['post_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$featured_image_id = (int) ( $input['featured_image_id'] ?? 0 );
		$site_url          = $input['site_url'] ?? '';

		if ( ! $post_id ) {
			return new WP_Error( 'ai_agent_empty_post_id', __( 'post_id is required.', 'superdav-ai-agent' ) );
		}

		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return new WP_Error(
				'ai_agent_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d not found.', 'superdav-ai-agent' ), $post_id )
			);
		}

		if ( 0 === $featured_image_id ) {
			delete_post_thumbnail( $post_id );
			$result = true;
			$action = 'removed';
		} else {
			$result = set_post_thumbnail( $post_id, $featured_image_id );
			$action = 'set';
		}

		if ( $switched ) {
			restore_current_blog();
		}

		if ( false === $result ) {
			return new WP_Error(
				'ai_agent_set_thumbnail_failed',
				/* translators: %d: post ID */
				sprintf( __( 'Failed to update featured image for post %d.', 'superdav-ai-agent' ), $post_id )
			);
		}

		return [
			'post_id'           => $post_id,
			'featured_image_id' => $featured_image_id,
			'result'            => $action,
		];
	}

	/**
	 * Detect whether content looks like markdown and convert to Gutenberg blocks.
	 *
	 * Handles three cases:
	 * 1. Pure block markup (<!-- wp: ... -->) — returned as-is.
	 * 2. Pure markdown — converted entirely via MarkdownToBlocks.
	 * 3. Mixed content (blocks + markdown) — parsed with parse_blocks(),
	 *    freeform segments containing markdown signals are converted
	 *    individually, real blocks are preserved.
	 *
	 * @param string $content Raw content from the model.
	 * @return string Content ready for post_content (blocks HTML or original).
	 */
	private static function maybe_convert_markdown( string $content ): string {
		if ( '' === $content ) {
			return $content;
		}

		$has_blocks = str_contains( $content, '<!-- wp:' );

		// Mixed content: blocks + potential markdown in freeform segments.
		if ( $has_blocks ) {
			return self::convert_mixed_content( $content );
		}

		// Pure HTML (3+ block-level tags) — leave as-is.
		$html_block_tags = preg_match_all( '/<(?:p|h[1-6]|div|section|ul|ol|table|blockquote|figure|header|footer|article|nav)\b/i', $content );
		if ( $html_block_tags >= 3 ) {
			return $content;
		}

		// Check for markdown signals.
		$markdown_signals = self::count_markdown_signals( $content );

		if ( $markdown_signals < 2 ) {
			return $content;
		}

		return MarkdownToBlocks::convert( $content );
	}

	/**
	 * Count markdown formatting signals in a string.
	 *
	 * @param string $text Text to check for markdown patterns.
	 * @return int Number of distinct markdown patterns found.
	 */
	private static function count_markdown_signals( string $text ): int {
		$signals = 0;

		if ( preg_match( '/^#{1,6}\s+\S/m', $text ) ) {
			++$signals;
		}
		if ( preg_match( '/\*{1,2}[^*\n]+\*{1,2}/', $text ) || preg_match( '/_{1,2}[^_\n]+_{1,2}/', $text ) ) {
			++$signals;
		}
		if ( preg_match( '/^[\-\*]\s+\S/m', $text ) ) {
			++$signals;
		}
		if ( preg_match( '/^\d+\.\s+\S/m', $text ) ) {
			++$signals;
		}
		if ( preg_match( '/\[[^\]]+\]\([^)]+\)/', $text ) ) {
			++$signals;
		}
		if ( str_contains( $text, '```' ) ) {
			++$signals;
		}

		return $signals;
	}

	/**
	 * Handle mixed content: real blocks are preserved, freeform segments
	 * containing markdown are converted to blocks individually.
	 *
	 * Uses WordPress core's parse_blocks() to split the content. Freeform
	 * blocks (blockName === null) that contain markdown signals get their
	 * innerHTML converted via MarkdownToBlocks. Everything else is
	 * re-serialized as-is.
	 *
	 * @param string $content Mixed block + markdown content.
	 * @return string Fully blockified content.
	 */
	private static function convert_mixed_content( string $content ): string {
		$parsed = parse_blocks( $content );
		$output = '';

		foreach ( $parsed as $block ) {
			$block_name  = $block['blockName'] ?? null;
			$block_inner = $block['innerHTML'] ?? '';

			// Real block — serialize as-is.
			if ( null !== $block_name ) {
				// @phpstan-ignore-next-line
				$output .= serialize_block( $block ) . "\n\n";
				continue;
			}

			// Freeform block — check if it contains markdown worth converting.
			$trimmed = trim( (string) $block_inner );
			if ( '' === $trimmed ) {
				continue;
			}

			$signals = self::count_markdown_signals( $trimmed );
			if ( $signals >= 1 ) {
				// Convert the freeform markdown segment to blocks.
				$output .= MarkdownToBlocks::convert( $trimmed ) . "\n\n";
			} elseif ( '' !== trim( wp_strip_all_tags( $trimmed ) ) ) {
				// Plain text without markdown — wrap in a paragraph block.
				// Only if it has actual visible content (not just whitespace/newlines).
				// @phpstan-ignore-next-line
				$output .= serialize_block( MarkdownToBlocks::make_paragraph( $trimmed ) ) . "\n\n";
			}
		}

		return trim( $output );
	}

	/**
	 * Resolve a term name or slug to a slug.
	 *
	 * WP_Query's category_name and tag params accept slugs only. This helper
	 * tries to find the term by human-readable name first; if found it returns
	 * the term's slug. If no match is found the original value is returned
	 * unchanged — it may already be a valid slug.
	 *
	 * @param string $value    Term name or slug provided by the caller.
	 * @param string $taxonomy Taxonomy to search in ('category' or 'post_tag').
	 * @return string Resolved slug, or original value if no name match found.
	 */
	private static function resolve_term_slug( string $value, string $taxonomy ): string {
		if ( '' === $value ) {
			return $value;
		}

		$term = get_term_by( 'name', $value, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->slug;
		}

		return $value;
	}

	/**
	 * Resolve an array of category IDs or names to an array of IDs.
	 *
	 * @param array<int|string> $categories Array of category IDs or names.
	 * @return int[] Array of category IDs.
	 */
	private static function resolve_category_ids( array $categories ): array {
		$ids = [];
		foreach ( $categories as $cat ) {
			if ( is_numeric( $cat ) ) {
				$ids[] = (int) $cat;
			} else {
				$term = get_term_by( 'name', sanitize_text_field( (string) $cat ), 'category' );
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = $term->term_id;
				}
			}
		}
		return $ids;
	}
}

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

use SdAiAgent\Abilities\UrlResolverAbilities;
use SdAiAgent\Core\BlockValidator;
use SdAiAgent\Core\ChangeLogger;
use SdAiAgent\Core\RateLimiter;
use SdAiAgent\Core\RevisionGuard;
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
				'description'         => __( 'Retrieve a WordPress post by numeric ID, full URL, or slug + post_type. Exactly one of "id", "url", or "slug" must be supplied. Returns title, content, excerpt, status, author, categories, tags, featured image, and a resolved_via field that identifies how the post was found.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'id'        => [
							'type'        => 'integer',
							'description' => 'Numeric post ID. Use this when you already know the ID.',
						],
						'post_id'   => [
							'type'        => 'integer',
							'description' => 'Alias for id (deprecated; prefer id).',
						],
						'url'       => [
							'type'        => 'string',
							'description' => 'Full URL of the post (e.g. "https://example.com/about/"). Resolved via url_to_postid() with a slug fallback. Cross-host URLs are rejected.',
						],
						'slug'      => [
							'type'        => 'string',
							'description' => 'Post slug (e.g. "about"). Must be paired with post_type to avoid silent cross-type matches.',
						],
						'post_type' => [
							'type'        => 'string',
							'description' => 'Post type to look up or validate against (required when slug is provided; default "any" when id is provided).',
						],
					],
					'required'   => [],
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
						'resolved_via'   => [
							'type'        => 'string',
							'enum'        => [ 'id', 'url_to_postid', 'slug_lookup' ],
							'description' => 'How the post was located: "id" (numeric ID), "url_to_postid" (URL resolved via url_to_postid()), or "slug_lookup" (resolved via get_page_by_path()).',
						],
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
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/get-post' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/create-post',
			[
				'label'               => __( 'Create Post', 'superdav-ai-agent' ),
				'description'         => __( 'Create a new WordPress post or page. This is the PRIMARY tool for creating any content — blog posts, landing pages, about pages, etc. Write content directly as HTML or markdown (auto-converted to Gutenberg blocks). Set post_type to "page" for pages or "post" for blog posts. Set status to "publish" to make it live immediately. Frontier models can emit a full multi-section landing page in one call; if you are on a weaker model and the page truly will not fit, create the hero + intro here and use sd-ai-agent/append-post-content for each remaining section.', 'superdav-ai-agent' ),
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
								'type' => [ 'string', 'integer' ],
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
						'affected'  => self::affected_output_schema( 'post' ),
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
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/create-post' );
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
								'type' => [ 'string', 'integer' ],
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
						'expected_revision' => [
							'type'        => [ 'integer', 'string' ],
							'description' => 'Optimistic concurrency guard. Pass the revision_id returned by get-page-blocks (or the If-Match header value). If the post has been modified since you read it, the call returns HTTP 412 stale_revision with the current_revision_id so you can re-fetch and retry. Omit to skip the check (backward-compatible).',
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'     => [ 'type' => 'integer' ],
						'permalink'   => [ 'type' => 'string' ],
						'status'      => [ 'type' => 'string' ],
						'post_type'   => [ 'type' => 'string' ],
						'revision_id' => [
							'type'        => [ 'integer', 'null' ],
							'description' => 'Latest revision ID after the write. null when the post has no revisions yet. Use as expected_revision for the next write.',
						],
						'affected'    => [
							'type'        => 'object',
							'description' => 'Transport descriptor for the frontend reflection bus — identifies the entity, its public URL, and which fields changed so the client can refresh the visible page without a full reload.',
							'properties'  => [
								'kind'      => [
									'type' => 'string',
									'enum' => [ 'post' ],
								],
								'post_id'   => [ 'type' => 'integer' ],
								'post_type' => [ 'type' => 'string' ],
								'url'       => [ 'type' => 'string' ],
								'fields'    => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
							],
						],
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
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/update-post' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/append-post-content',
			[
				'label'               => __( 'Append Post Content', 'superdav-ai-agent' ),
				'description'         => __( 'Append a chunk of block markup to the end of an existing post or page WITHOUT re-sending the full content. Useful for: (1) extending an existing page with a new section, (2) building a long page section-by-section on a model with a small per-response token cap, or (3) streaming visible progress to the user. The appended content is concatenated as-is to post_content; use complete, self-contained block markup (no partial blocks). Returns the post_id, permalink, and new total content length.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'           => [
							'type'        => 'integer',
							'description' => 'The ID of the post or page to append to.',
						],
						'content'           => [
							'type'        => 'string',
							'description' => 'Block markup to append. Must be complete, self-contained blocks (each opening comment paired with its closing comment). A leading newline is recommended to separate from the previous section.',
						],
						'site_url'          => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
						'expected_revision' => [
							'type'        => [ 'integer', 'string' ],
							'description' => 'Optimistic concurrency guard. Pass the revision_id returned by get-page-blocks. If the post has been modified since you read it, the call returns HTTP 412 stale_revision with the current_revision_id so you can re-fetch and retry. Omit to skip the check (backward-compatible).',
						],
					],
					'required'   => [ 'post_id', 'content' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'        => [ 'type' => 'integer' ],
						'permalink'      => [ 'type' => 'string' ],
						'appended_bytes' => [ 'type' => 'integer' ],
						'total_bytes'    => [ 'type' => 'integer' ],
						'revision_id'    => [
							'type'        => [ 'integer', 'null' ],
							'description' => 'Latest revision ID after the write. null when the post has no revisions yet. Use as expected_revision for the next write.',
						],
						'affected'       => self::affected_output_schema( 'post' ),
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
				'execute_callback'    => [ __CLASS__, 'handle_append_post_content' ],
				'permission_callback' => function (): bool {
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/append-post-content' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/list-posts',
			[
				'label'               => __( 'List Posts', 'superdav-ai-agent' ),
				'description'         => __( 'Query and list WordPress posts or pages. Filter by post_type, post_status (single string or array), search term, category, tag, date range (date_after/date_before), author, tax_query, and meta_query. Returns id, title, excerpt, status, post_type, date, permalink, featured_image_url, and query_args for each match. Default: 10 most recent published posts.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type'       => [
							'type'        => 'string',
							'description' => 'Post type to query (default: "post"). Use "page" for pages, "product" for WooCommerce products.',
						],
						'post_status'     => [
							'type'        => [ 'string', 'array' ],
							'description' => 'Post status filter. Accepts a single string or an array of statuses. Examples: "draft", ["draft","pending"]. Default: ["publish"]. Special value "any" matches all statuses.',
							'items'       => [ 'type' => 'string' ],
						],
						'per_page'        => [
							'type'        => 'integer',
							'description' => 'Number of posts to return (default: 10, max: 50).',
						],
						'search'          => [
							'type'        => 'string',
							'description' => 'Search term to filter posts by title or content.',
						],
						'category'        => [
							'type'        => 'string',
							'description' => 'Category name or slug to filter by. Human-readable names (e.g. "My Category") are resolved to their slug automatically.',
						],
						'tag'             => [
							'type'        => 'string',
							'description' => 'Tag name or slug to filter by. Human-readable names are resolved to their slug automatically.',
						],
						'orderby'         => [
							'type'        => 'string',
							'description' => 'Order results by: "date" (default), "title", "modified", "ID", "menu_order", "rand".',
							'enum'        => [ 'date', 'title', 'modified', 'ID', 'menu_order', 'rand' ],
						],
						'order'           => [
							'type'        => 'string',
							'description' => 'Sort direction: "DESC" (default, newest first) or "ASC" (oldest first).',
							'enum'        => [ 'DESC', 'ASC' ],
						],
						'date_after'      => [
							'type'        => 'string',
							'description' => 'ISO-8601 date (YYYY-MM-DD). Only posts published after this date are returned.',
						],
						'date_before'     => [
							'type'        => 'string',
							'description' => 'ISO-8601 date (YYYY-MM-DD). Only posts published before this date are returned.',
						],
						'inclusive_dates' => [
							'type'        => 'boolean',
							'description' => 'Whether date_after/date_before bounds are inclusive (default: true).',
						],
						'author'          => [
							'type'        => [ 'integer', 'array' ],
							'description' => 'Filter by author user ID. Accepts a single integer or an array of integers.',
							'items'       => [ 'type' => 'integer' ],
						],
						'tax_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
								'type'        => 'array',
								'description' => 'Taxonomy filter clauses. Each clause: {"taxonomy":"category","terms":[1,2],"operator":"IN"}. Allowed operators: IN, NOT IN, AND.',
								'items'       => [
									'type'       => 'object',
									'properties' => [
										'taxonomy' => [
											'type'        => 'string',
											'description' => 'Taxonomy slug (e.g. "category", "post_tag").',
										],
										'terms'    => [
											'type'        => 'array',
											'items'       => [ 'type' => 'integer' ],
											'description' => 'Array of term IDs.',
										],
										'operator' => [
											'type'        => 'string',
											'enum'        => [ 'IN', 'NOT IN', 'AND' ],
											'description' => 'Match operator.',
										],
									],
									'required'   => [ 'taxonomy', 'terms' ],
								],
						],
						'meta_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							'type'        => 'array',
							'description' => 'Post meta filter clauses. Each clause: {"key":"_thumbnail_id","compare":"EXISTS"}. Allowed compare values: =, !=, EXISTS, NOT EXISTS, IN, NOT IN. LIKE and REGEXP are not permitted.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'key'     => [
										'type'        => 'string',
										'description' => 'Meta key.',
									],
									'compare' => [
										'type'        => 'string',
										'enum'        => [ '=', '!=', 'EXISTS', 'NOT EXISTS', 'IN', 'NOT IN' ],
										'description' => 'Comparison operator.',
									],
									'value'   => [
										'type'        => [ 'string', 'array', 'integer', 'number' ],
										'description' => 'Meta value. Not required for EXISTS/NOT EXISTS.',
									],
								],
								'required'   => [ 'key' ],
							],
						],
						'has_password'    => [
							'type'        => 'boolean',
							'description' => 'Filter by password protection: true returns only password-protected posts, false returns only unprotected posts.',
						],
						'site_url'        => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'posts'      => [
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
						'total'      => [ 'type' => 'integer' ],
						'per_page'   => [ 'type' => 'integer' ],
						'query_args' => [
							'type'        => 'object',
							'description' => 'The WP_Query args that were actually applied after sanitisation. Use this to self-correct over-broad filters.',
						],
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
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/list-posts' );
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
											'type' => [ 'string', 'integer' ],
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
						'affected'      => [
							'type'  => 'array',
							'items' => self::affected_output_schema( 'post' ),
						],
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
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/batch-create-posts' );
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
						'affected'     => self::affected_output_schema( 'post' ),
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
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/delete-post' );
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
						'affected'          => self::affected_output_schema( 'post' ),
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
					// Dual gate: per-tool cap AND core cap from CORE_CAP_MAP.
					return ToolCapabilities::current_user_can( 'sd-ai-agent/set-featured-image' );
				},
			]
		);
	}

	/**
	 * Handle the list-posts ability.
	 *
	 * Delegates input sanitisation to sanitize_list_posts_args() which returns a
	 * WP_Error when an invalid operator is detected (e.g. meta_query LIKE or
	 * tax_query REGEXP). On success the sanitised args are forwarded to WP_Query
	 * and mirrored back in the response as query_args so agents can self-correct.
	 *
	 * @param array<string, mixed> $input Input with optional filters (post_type, post_status, per_page, etc.).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_list_posts( array $input ) {
		$sanitized = self::sanitize_list_posts_args( $input );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}

		$per_page = isset( $input['per_page'] ) ? min( (int) $input['per_page'], 50 ) : 10;
		$per_page = max( 1, $per_page );

		// @phpstan-ignore-next-line
		$site_url = $input['site_url'] ?? '';

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

		$query_args = array_merge(
			$sanitized,
			[
				'posts_per_page' => $per_page,
				'no_found_rows'  => false,
			]
		);

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
			'posts'      => $posts,
			'total'      => $total,
			'per_page'   => $per_page,
			'query_args' => $sanitized,
		];
	}

	/**
	 * Sanitise list-posts input into WP_Query-ready args.
	 *
	 * All new filter fields (post_status[], date_after, date_before,
	 * inclusive_dates, author, tax_query, meta_query, has_password) are handled
	 * here so handle_list_posts stays readable. Returns a WP_Error immediately
	 * when an operator not in the allowlist is detected.
	 *
	 * Security notes:
	 * - meta_query compare: only =, !=, EXISTS, NOT EXISTS, IN, NOT IN are
	 *   permitted. LIKE and REGEXP are explicitly blocked to prevent pattern
	 *   injection from agent input.
	 * - tax_query operator: only IN, NOT IN, AND are permitted.
	 * - All string values pass through sanitize_text_field(); WP_Query handles
	 *   database escaping internally via $wpdb->prepare().
	 *
	 * @param array<string, mixed> $input Raw ability input.
	 * @return array<string, mixed>|WP_Error Sanitised args ready for WP_Query, or WP_Error on invalid input.
	 */
	private static function sanitize_list_posts_args( array $input ): array|WP_Error {
		$args = [];

		// post_type.
		// @phpstan-ignore-next-line
		$args['post_type'] = sanitize_text_field( $input['post_type'] ?? 'post' );

		// post_status: string | string[]. Falls back to legacy 'status' field for backward compat.
		// @phpstan-ignore-next-line
		$raw_status = $input['post_status'] ?? $input['status'] ?? [ 'publish' ];
		if ( is_string( $raw_status ) ) {
			$raw_status = [ $raw_status ];
		}
		$sanitized_statuses = [];
		foreach ( (array) $raw_status as $s ) {
			$s = sanitize_text_field( (string) $s );
			if ( '' !== $s ) {
				$sanitized_statuses[] = $s;
			}
		}
		$args['post_status'] = ! empty( $sanitized_statuses ) ? $sanitized_statuses : [ 'publish' ];

		// search.
		// @phpstan-ignore-next-line
		$search = sanitize_text_field( $input['search'] ?? '' );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		// category (resolves name → slug for WP_Query's category_name param).
		// @phpstan-ignore-next-line
		$category = sanitize_text_field( $input['category'] ?? '' );
		if ( '' !== $category ) {
			$args['category_name'] = self::resolve_term_slug( $category, 'category' );
		}

		// tag (resolves name → slug for WP_Query's tag param).
		// @phpstan-ignore-next-line
		$tag = sanitize_text_field( $input['tag'] ?? '' );
		if ( '' !== $tag ) {
			$args['tag'] = self::resolve_term_slug( $tag, 'post_tag' );
		}

		// orderby.
		$allowed_orderby = [ 'date', 'title', 'modified', 'ID', 'menu_order', 'rand' ];
		// @phpstan-ignore-next-line
		$orderby = sanitize_text_field( $input['orderby'] ?? 'date' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'date';
		}
		$args['orderby'] = $orderby;

		// order.
		// @phpstan-ignore-next-line
		$order = strtoupper( sanitize_text_field( $input['order'] ?? 'DESC' ) );
		if ( ! in_array( $order, [ 'DESC', 'ASC' ], true ) ) {
			$order = 'DESC';
		}
		$args['order'] = $order;

		// date_after / date_before / inclusive_dates → WP_Query date_query.
		// @phpstan-ignore-next-line
		$date_after = sanitize_text_field( $input['date_after'] ?? '' );
		// @phpstan-ignore-next-line
		$date_before = sanitize_text_field( $input['date_before'] ?? '' );
		$inclusive   = isset( $input['inclusive_dates'] ) ? (bool) $input['inclusive_dates'] : true;

		if ( '' !== $date_after || '' !== $date_before ) {
			$date_clause = [ 'inclusive' => $inclusive ];
			if ( '' !== $date_after ) {
				$date_clause['after'] = $date_after;
			}
			if ( '' !== $date_before ) {
				$date_clause['before'] = $date_before;
			}
			$args['date_query'] = [ $date_clause ];
		}

		// author: int | int[].
		if ( isset( $input['author'] ) ) {
			$raw_author = $input['author'];
			if ( is_array( $raw_author ) ) {
				$author_ids = array_values( array_filter( array_map( static fn ( mixed $v ): int => (int) $v, $raw_author ) ) );
				if ( ! empty( $author_ids ) ) {
					$args['author__in'] = $author_ids;
				}
			} else {
				$author_id = (int) $raw_author;
				if ( $author_id > 0 ) {
					$args['author'] = $author_id;
				}
			}
		}

		// tax_query: operator allowlist — IN, NOT IN, AND.
		if ( isset( $input['tax_query'] ) && is_array( $input['tax_query'] ) ) {
			$allowed_tax_ops = [ 'IN', 'NOT IN', 'AND' ];
			$tax_clauses     = [];

			foreach ( $input['tax_query'] as $clause ) {
				if ( ! is_array( $clause ) ) {
					continue;
				}
				// @phpstan-ignore-next-line
				$operator = strtoupper( sanitize_text_field( (string) ( $clause['operator'] ?? 'IN' ) ) );
				if ( ! in_array( $operator, $allowed_tax_ops, true ) ) {
					return new WP_Error(
						'invalid_tax_operator',
						sprintf(
							/* translators: 1: operator provided, 2: comma-separated allowed operators */
							__( 'tax_query operator "%1$s" is not allowed. Allowed operators: %2$s.', 'superdav-ai-agent' ),
							$operator,
							implode( ', ', $allowed_tax_ops )
						)
					);
				}
				// @phpstan-ignore-next-line
				$taxonomy = sanitize_text_field( (string) ( $clause['taxonomy'] ?? '' ) );
				$terms    = array_values( array_filter( array_map( static fn ( mixed $v ): int => (int) $v, (array) ( $clause['terms'] ?? [] ) ) ) );

				if ( '' === $taxonomy || empty( $terms ) ) {
					continue;
				}

				$tax_clauses[] = [
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $terms,
					'operator' => $operator,
				];
			}

			if ( ! empty( $tax_clauses ) ) {
				$args['tax_query'] = $tax_clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}
		}

		// meta_query: compare operator allowlist — never LIKE or REGEXP.
		if ( isset( $input['meta_query'] ) && is_array( $input['meta_query'] ) ) {
			$allowed_meta_ops = [ '=', '!=', 'EXISTS', 'NOT EXISTS', 'IN', 'NOT IN' ];
			$meta_clauses     = [];

			foreach ( $input['meta_query'] as $clause ) {
				if ( ! is_array( $clause ) ) {
					continue;
				}
				// @phpstan-ignore-next-line
				$compare = strtoupper( sanitize_text_field( (string) ( $clause['compare'] ?? '=' ) ) );
				if ( ! in_array( $compare, $allowed_meta_ops, true ) ) {
					return new WP_Error(
						'invalid_meta_compare',
						sprintf(
							/* translators: 1: compare operator provided, 2: comma-separated allowed operators */
							__( 'meta_query compare "%1$s" is not allowed. Allowed operators: %2$s.', 'superdav-ai-agent' ),
							$compare,
							implode( ', ', $allowed_meta_ops )
						)
					);
				}
				// @phpstan-ignore-next-line
				$key = sanitize_text_field( (string) ( $clause['key'] ?? '' ) );
				if ( '' === $key ) {
					continue;
				}

				$meta_clause = [
					'key'     => $key,
					'compare' => $compare,
				];

				// Include 'value' only for compares that actually need it.
				if ( isset( $clause['value'] ) && ! in_array( $compare, [ 'EXISTS', 'NOT EXISTS' ], true ) ) {
					if ( is_array( $clause['value'] ) ) {
						// @phpstan-ignore-next-line
						$meta_clause['value'] = array_map( 'sanitize_text_field', array_map( 'strval', $clause['value'] ) );
					} else {
						$meta_clause['value'] = sanitize_text_field( (string) $clause['value'] );
					}
				}

				$meta_clauses[] = $meta_clause;
			}

			if ( ! empty( $meta_clauses ) ) {
				$args['meta_query'] = $meta_clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}
		}

		// has_password.
		if ( isset( $input['has_password'] ) ) {
			$args['has_password'] = (bool) $input['has_password'];
		}

		return $args;
	}

	/**
	 * Handle the get-post ability.
	 *
	 * Accepts exactly one of:
	 *   - id / post_id — numeric post ID (post_id is a deprecated alias).
	 *   - url          — absolute URL resolved via UrlResolverAbilities.
	 *   - slug + post_type — slug resolved via get_page_by_path().
	 *
	 * @param array<string, mixed> $input Input params.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_get_post( array $input ) {
		// Normalise id: accept both 'id' and deprecated 'post_id'.
		// @phpstan-ignore-next-line
		$id_value   = (int) ( $input['id'] ?? $input['post_id'] ?? 0 );
		$url_value  = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
		$slug_value = isset( $input['slug'] ) ? trim( (string) $input['slug'] ) : '';

		// ── XOR guard: exactly one of id / url / slug must be provided ────────
		$provided = ( $id_value > 0 ? 1 : 0 )
			+ ( '' !== $url_value ? 1 : 0 )
			+ ( '' !== $slug_value ? 1 : 0 );

		if ( $provided > 1 ) {
			return new WP_Error(
				'too_many_inputs',
				__( 'Provide exactly one of "id", "url", or "slug" — not multiple.', 'superdav-ai-agent' )
			);
		}

		if ( 0 === $provided ) {
			return new WP_Error(
				'missing_input',
				__( 'One of "id", "url", or "slug" is required.', 'superdav-ai-agent' )
			);
		}

		// ── Resolve to a post_id ───────────────────────────────────────────────
		$post_id      = 0;
		$resolved_via = '';

		if ( $id_value > 0 ) {
			$post_id      = $id_value;
			$resolved_via = 'id';
		} elseif ( '' !== $url_value ) {
			// Delegate to shared URL resolver.
			$resolved = UrlResolverAbilities::resolve_to_post_id( [ 'url' => $url_value ], $resolved_via );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$post_id = $resolved;
		} else {
			// Slug + post_type form.
			// @phpstan-ignore-next-line
			$post_type_for_slug = isset( $input['post_type'] ) ? trim( (string) $input['post_type'] ) : '';
			$resolved           = UrlResolverAbilities::resolve_to_post_id(
				[
					'slug'      => $slug_value,
					'post_type' => $post_type_for_slug,
				],
				$resolved_via
			);
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$post_id = $resolved;
		}

		// ── Fetch post ────────────────────────────────────────────────────────
		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return new WP_Error(
				'ai_agent_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d not found.', 'superdav-ai-agent' ), $post_id )
			);
		}

		// ── Per-resource capability check ──────────────────────────────────────
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to edit this post.', 'superdav-ai-agent' )
			);
		}

		// post_type validation (applies only to the id path; slug path already
		// scoped to the requested type via resolve_to_post_id).
		// @phpstan-ignore-next-line
		$post_type_filter = 'id' === $resolved_via ? sanitize_text_field( $input['post_type'] ?? 'any' ) : 'any';
		if ( 'any' !== $post_type_filter && $post->post_type !== $post_type_filter ) {
			return new WP_Error(
				'ai_agent_post_type_mismatch',
				/* translators: 1: post ID, 2: expected type, 3: actual type */
				sprintf( __( 'Post %1$d is of type "%2$s", not "%3$s".', 'superdav-ai-agent' ), $post_id, $post->post_type, $post_type_filter )
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
			'resolved_via'   => $resolved_via,
		];
	}

	/**
	 * Handle the create-post ability.
	 *
	 * @param array<string, mixed> $input Input with title, content, status, etc.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_create_post( array $input ) {
		// Rate limit check (write bucket, per-user for creates).
		$rate_check = RateLimiter::check( 'write', get_current_user_id() );

		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

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

		// Record rate-limit tick after successful create (per-user).
		RateLimiter::record( 'write', get_current_user_id() );

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

		$created_post = get_post( $post_id );
		if ( $created_post instanceof WP_Post ) {
			ChangeLogger::record_post_created( $post_id, $created_post );
		}

		$permalink = get_permalink( $post_id );

		if ( $switched ) {
			restore_current_blog();
		}

		$response = [
			'post_id'   => $post_id,
			'permalink' => $permalink ?: '',
			'status'    => $status,
			'post_type' => $post_type,
		];

		// GH#1584 follow-up: run BlockValidator on serialized block content so
		// the model gets save-time feedback even when it forgets to call
		// validate_block_content first. The save itself is never rejected.
		$validation = self::maybe_validate_block_content( $content );
		if ( null !== $validation ) {
			$response['block_validation'] = $validation;
		}

		$response['affected'] = self::build_affected_payload( $post_id, $created_post, $permalink, $input, $post_data );

		return $response;
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
		$affected      = [];
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
				if ( isset( $result['affected'] ) && is_array( $result['affected'] ) ) {
					$affected[] = $result['affected'];
				}
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
			'affected'      => $affected,
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

		// Rate limit check (write bucket, per-post).
		$rate_check = RateLimiter::check( 'write', $post_id );

		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
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

		// ── Per-resource capability check ──────────────────────────────────────
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return new WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to edit this post.', 'superdav-ai-agent' )
			);
		}

		// Optimistic concurrency check (opt-in via expected_revision).
		$raw_expected = isset( $input['expected_revision'] ) ? (string) $input['expected_revision'] : '';
		$guard        = RevisionGuard::check( $post_id, RevisionGuard::parse_raw( $raw_expected ) );
		if ( is_wp_error( $guard ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $guard;
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

		// Record rate-limit tick after successful write.
		RateLimiter::record( 'write', $post_id );

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

		$response = [
			'post_id'     => $post_id,
			'permalink'   => $permalink ?: '',
			'status'      => $updated_post instanceof WP_Post ? $updated_post->post_status : '',
			'post_type'   => $updated_post instanceof WP_Post ? $updated_post->post_type : '',
			'revision_id' => RevisionGuard::current_revision_id( $post_id ),
		];

		// GH#1584 follow-up: re-validate after update so the model knows
		// whether its repair attempt actually fixed the block markup.
		if ( isset( $post_data['post_content'] ) ) {
			$validation = self::maybe_validate_block_content( (string) $post_data['post_content'] );
			if ( null !== $validation ) {
				$response['block_validation'] = $validation;
			}
		}

		$response['affected'] = self::build_affected_payload( $post_id, $updated_post, $permalink, $input, $post_data );

		return $response;
	}

	/**
	 * Build the `affected` payload describing what this update changed.
	 *
	 * Spike (Phase 1, frontend live-preview bus): a generic, transport-only
	 * descriptor consumed by the JS reflection bus so future reflectors can
	 * decide how to refresh the visible page after a tool call. The shape is
	 * intentionally minimal — just enough for a reflector to identify the
	 * target entity, its public URL, and which fields the agent touched.
	 *
	 * Keep this side-effect free: callers downstream only read these fields.
	 *
	 * @param int                  $post_id      Updated post ID.
	 * @param WP_Post|null         $updated_post Refreshed post object, or null on fetch failure.
	 * @param string|false         $permalink    Result of get_permalink(), false when unavailable.
	 * @param array<string, mixed> $input        Original ability input from the model.
	 * @param array<string, mixed> $post_data    Sanitised wp_update_post() payload.
	 * @return array<string, mixed>
	 */
	private static function build_affected_payload(
		int $post_id,
		?WP_Post $updated_post,
		$permalink,
		array $input,
		array $post_data
	): array {
		$fields = [];
		foreach ( [ 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order' ] as $key ) {
			if ( array_key_exists( $key, $post_data ) ) {
				$fields[] = $key;
			}
		}
		// Extra-table writes that handle_update_post performs after wp_update_post().
		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$fields[] = 'categories';
		}
		if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			$fields[] = 'tags';
		}
		if ( isset( $input['featured_image_id'] ) ) {
			$fields[] = 'featured_image';
		}
		if ( isset( $input['page_template'] ) ) {
			$fields[] = 'page_template';
		}
		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$fields[] = 'meta';
		}

		return [
			'kind'      => 'post',
			'post_id'   => $post_id,
			'post_type' => $updated_post instanceof WP_Post ? $updated_post->post_type : '',
			'url'       => is_string( $permalink ) ? $permalink : '',
			'fields'    => array_values( array_unique( $fields ) ),
		];
	}

	/**
	 * Handle the append-post-content ability.
	 *
	 * Appends a chunk of block markup to an existing post WITHOUT requiring the
	 * caller to re-send the full post_content. This is the canonical way to
	 * build long landing pages section by section: create-post with the
	 * hero/intro, then call this once per section. Each call keeps tool-call
	 * JSON small, which avoids hitting model max_tokens output limits.
	 *
	 * @param array<string, mixed> $input post_id, content, optional site_url.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_append_post_content( array $input ) {
		// @phpstan-ignore-next-line
		$post_id = (int) ( $input['post_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$content  = (string) ( $input['content'] ?? '' );
		$site_url = $input['site_url'] ?? '';

		if ( ! $post_id ) {
			return new WP_Error( 'ai_agent_empty_post_id', __( 'post_id is required.', 'superdav-ai-agent' ) );
		}

		if ( '' === trim( $content ) ) {
			return new WP_Error( 'ai_agent_empty_content', __( 'content is required and cannot be empty.', 'superdav-ai-agent' ) );
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

		// Optimistic concurrency check (opt-in via expected_revision).
		$raw_expected = isset( $input['expected_revision'] ) ? (string) $input['expected_revision'] : '';
		$guard        = RevisionGuard::check( $post_id, RevisionGuard::parse_raw( $raw_expected ) );
		if ( is_wp_error( $guard ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $guard;
		}

		$existing       = (string) $post->post_content;
		$appended_bytes = strlen( $content );

		// Ensure a blank line between sections for editor readability.
		$separator = '';
		if ( '' !== $existing && "\n" !== substr( $existing, -1 ) ) {
			$separator = "\n\n";
		} elseif ( '' !== $existing && "\n\n" !== substr( $existing, -2 ) ) {
			$separator = "\n";
		}

		// @phpstan-ignore-next-line
		$new_content = $existing . $separator . wp_kses_post( $content );

		$result = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $new_content,
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $result;
		}

		$permalink   = get_permalink( $post_id ) ?: '';
		$total_bytes = strlen( $new_content );

		if ( $switched ) {
			restore_current_blog();
		}

		return [
			'post_id'        => $post_id,
			'permalink'      => $permalink,
			'appended_bytes' => $appended_bytes,
			'total_bytes'    => $total_bytes,
			'revision_id'    => RevisionGuard::current_revision_id( $post_id ),
			'affected'       => self::build_affected_payload( $post_id, get_post( $post_id ), $permalink, $input, [ 'post_content' => $new_content ] ),
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

		// ── Per-resource capability check ──────────────────────────────────────
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return new WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to edit this post.', 'superdav-ai-agent' )
			);
		}

		$title     = $post->post_title;
		$permalink = get_permalink( $post_id );
		$result    = wp_delete_post( $post_id, $force_delete );

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
			'affected'     => self::build_affected_payload( $post_id, $post, $permalink, $input, [ 'post_status' => $force_delete ? 'deleted' : 'trash' ] ),
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
			'affected'          => self::build_affected_payload( $post_id, $post, get_permalink( $post_id ), $input, [] ),
		];
	}

	/**
	 * Build the output-schema fragment for post affected descriptors.
	 *
	 * @param string $kind Affected entity kind.
	 * @return array<string, mixed>
	 */
	private static function affected_output_schema( string $kind ): array {
		return [
			'type'        => 'object',
			'description' => 'Transport descriptor for the frontend reflection bus — identifies the entity, its public URL, and which fields changed so the client can refresh the visible page without a full reload.',
			'properties'  => [
				'kind'      => [
					'type' => 'string',
					'enum' => [ $kind ],
				],
				'post_id'   => [ 'type' => 'integer' ],
				'post_type' => [ 'type' => 'string' ],
				'url'       => [ 'type' => 'string' ],
				'fields'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
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

	/**
	 * Run BlockValidator on content that contains Gutenberg block markup, then
	 * shape the report so the model can apply `expectedContent` on a follow-up
	 * update_post call without parsing the full structure.
	 *
	 * Returns null when the content has no block delimiters (`<!-- wp:`) — for
	 * pure markdown or empty content the validator has nothing to say. When
	 * the content does contain blocks, the returned array is attached to the
	 * create_post / update_post success response under the `block_validation`
	 * key. Saves still go through unchanged — this is feedback only, never a
	 * hard rejection, so partial work is never lost. Models that see
	 * `invalidBlocks > 0` are expected to re-emit an update_post call with
	 * `originalContent` replaced by `expectedContent`.
	 *
	 * @since 1.16.2
	 *
	 * @param string $content Block content that was just saved (post_kses'd).
	 * @return array<string, mixed>|null
	 */
	private static function maybe_validate_block_content( string $content ): ?array {
		if ( '' === $content || false === strpos( $content, '<!-- wp:' ) ) {
			return null;
		}

		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$invalid = (int) ( $report['invalidBlocks'] ?? 0 );

		// Only surface the report when there is something the model should act on.
		if ( $invalid <= 0 ) {
			return array(
				'isValid'       => true,
				'totalBlocks'   => (int) ( $report['totalBlocks'] ?? 0 ),
				'invalidBlocks' => 0,
				'source'        => (string) ( $report['source'] ?? 'php' ),
			);
		}

		// Pick the first invalid result so the response stays small. The
		// validator can report many issues; the model only needs one diff
		// at a time to make a follow-up update_post call.
		$first_invalid = null;
		foreach ( (array) ( $report['results'] ?? array() ) as $result ) {
			if ( empty( $result['isValid'] ) ) {
				$first_invalid = $result;
				break;
			}
		}

		return array(
			'isValid'        => false,
			'totalBlocks'    => (int) ( $report['totalBlocks'] ?? 0 ),
			'invalidBlocks'  => $invalid,
			'source'         => (string) ( $report['source'] ?? 'php' ),
			'firstInvalid'   => $first_invalid,
			'results'        => (array) ( $report['results'] ?? array() ),
			'recommendation' => __( 'One or more blocks failed validation. To self-repair, call update_post on this post_id with content rebuilt from results[].expectedContent (substitute each invalid block\'s originalContent with its expectedContent). Do NOT copy expectedContent verbatim into the block-comment attributes — it replaces the innerHTML only.', 'superdav-ai-agent' ),
		);
	}
}

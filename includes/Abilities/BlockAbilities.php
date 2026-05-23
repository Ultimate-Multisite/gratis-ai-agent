<?php

declare(strict_types=1);
/**
 * Block-related abilities for the AI agent.
 *
 * Provides tools for Gutenberg block discovery, content creation,
 * and markdown-to-blocks conversion.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\BlockInventory;
use SdAiAgent\Core\BlockReferences;
use SdAiAgent\Core\BlockValidator;
use SdAiAgent\Core\RevisionGuard;
use SdAiAgent\Models\MarkdownToBlocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlockAbilities {

	/**
	 * Register all block-related abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/markdown-to-blocks',
			[
				'label'               => __( 'Markdown to Blocks', 'superdav-ai-agent' ),
				'description'         => __( 'Convert markdown text into serialized Gutenberg block HTML ready for post_content. Best for text-heavy content like blog posts and articles.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'markdown' => [
							'type'        => 'string',
							'description' => 'Markdown text to convert into Gutenberg blocks.',
						],
					],
					'required'   => [ 'markdown' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'block_content' => [ 'type' => 'string' ],
						'block_count'   => [ 'type' => 'integer' ],
						'error'         => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_markdown_to_blocks' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);

		wp_register_ability(
			'sd-ai-agent/list-block-types',
			[
				'label'               => __( 'List Block Types', 'superdav-ai-agent' ),
				'description'         => __( 'List registered Gutenberg block types. Filter by category or search term. Returns block names, titles, descriptions, and categories.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'category' => [
							'type'        => 'string',
							'description' => 'Filter by block category slug (e.g. "text", "media", "design").',
						],
						'search'   => [
							'type'        => 'string',
							'description' => 'Search term to filter block types by name, title, or keywords.',
						],
						'per_page' => [
							'type'        => 'integer',
							'description' => 'Results per page (default: 20).',
						],
						'page'     => [
							'type'        => 'integer',
							'description' => 'Page number (default: 1).',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'block_types' => [ 'type' => 'array' ],
						'total'       => [ 'type' => 'integer' ],
						'page'        => [ 'type' => 'integer' ],
						'per_page'    => [ 'type' => 'integer' ],
						'categories'  => [ 'type' => 'object' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_list_block_types' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/get-block-type',
			[
				'label'               => __( 'Get Block Type', 'superdav-ai-agent' ),
				'description'         => __( 'Get detailed metadata for a specific block type including attributes schema, supports, styles, and variations.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'name' => [
							'type'        => 'string',
							'description' => 'Block type name (e.g. "core/paragraph", "core/image").',
						],
					],
					'required'   => [ 'name' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'name'           => [ 'type' => 'string' ],
						'title'          => [ 'type' => 'string' ],
						'description'    => [ 'type' => 'string' ],
						'category'       => [ 'type' => 'string' ],
						'keywords'       => [ 'type' => 'array' ],
						'attributes'     => [ 'type' => 'object' ],
						'supports'       => [ 'type' => 'object' ],
						'example_markup' => [ 'type' => 'string' ],
						'error'          => [ 'type' => 'string' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_get_block_type' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/list-block-patterns',
			[
				'label'               => __( 'List Block Patterns', 'superdav-ai-agent' ),
				'description'         => __( 'List registered block patterns. Filter by category or search. Returns pattern names, titles, descriptions, and optionally full content.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'category'     => [
							'type'        => 'string',
							'description' => 'Filter by pattern category slug.',
						],
						'search'       => [
							'type'        => 'string',
							'description' => 'Search term to filter patterns by name or title.',
						],
						'per_page'     => [
							'type'        => 'integer',
							'description' => 'Results per page (default: 10).',
						],
						'full_content' => [
							'type'        => 'boolean',
							'description' => 'Return full pattern content instead of truncated (default: false).',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'patterns'   => [ 'type' => 'array' ],
						'total'      => [ 'type' => 'integer' ],
						'categories' => [ 'type' => 'object' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_list_block_patterns' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/list-block-templates',
			[
				'label'               => __( 'List Block Templates', 'superdav-ai-agent' ),
				'description'         => __( 'List block templates available in the current theme. Returns template slugs, titles, and descriptions.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search' => [
							'type'        => 'string',
							'description' => 'Search term to filter templates.',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'templates' => [ 'type' => 'array' ],
						'total'     => [ 'type' => 'integer' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_list_block_templates' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/create-block-content',
			[
				'label'               => __( 'Create Block Content', 'superdav-ai-agent' ),
				'description'         => __( 'Build serialized Gutenberg block HTML from a structured block array. Best for layouts with columns, buttons, groups, and other complex blocks. Each block needs blockName, optional attrs, content, and innerBlocks.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'blocks' => [
							'type'        => 'array',
							'description' => 'Array of block objects. Each has: blockName (string, required), attrs (object, optional), content (string, optional — inner text/HTML), innerBlocks (array, optional — nested blocks).',
						],
					],
					'required'   => [ 'blocks' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'block_content' => [ 'type' => 'string' ],
						'block_count'   => [ 'type' => 'integer' ],
						'error'         => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_create_block_content' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
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
			'sd-ai-agent/parse-block-content',
			[
				'label'               => __( 'Parse Block Content', 'superdav-ai-agent' ),
				'description'         => __( 'Parse existing Gutenberg block content into a structured block tree. Provide either a post_id to read from the database, or raw content string.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'  => [
							'type'        => 'integer',
							'description' => 'Post ID to read block content from.',
						],
						'content'  => [
							'type'        => 'string',
							'description' => 'Raw block content string to parse.',
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
						'blocks'      => [ 'type' => 'array' ],
						'block_count' => [ 'type' => 'integer' ],
						'error'       => [ 'type' => 'string' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_parse_block_content' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/validate-block-content',
			[
				'label'               => __( 'Validate Block Content', 'superdav-ai-agent' ),
				'description'         => __( 'Validate Gutenberg block content before insertion. Mirrors wp.blocks.validateBlock() server-side: detects heading-level/wrapper-tag/required-class mismatches, mixed markdown/block markup, malformed block comments, empty blocks, and core/html policy violations. When a block is invalid, the response includes results[].expectedContent — the corrected innerHTML to substitute in a follow-up create_post / update_post call. Always call this BEFORE saving complex block markup; create_post and update_post also auto-run this validator and attach the report under block_validation so you can self-repair on a retry.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'content' => [
							'type'        => 'string',
							'description' => 'Raw block content string to validate.',
						],
					],
					'required'   => [ 'content' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'valid'          => [
							'type'        => 'boolean',
							'description' => 'Convenience flag: true when invalidBlocks === 0 and warnings is empty.',
						],
						'totalBlocks'    => [
							'type'        => 'integer',
							'description' => 'Total number of blocks parsed from the input (flat count, includes inner blocks).',
						],
						'validBlocks'    => [
							'type'        => 'integer',
							'description' => 'Number of blocks that passed validation.',
						],
						'invalidBlocks'  => [
							'type'        => 'integer',
							'description' => 'Number of blocks that failed validation. When > 0, inspect results[] for per-block diffs.',
						],
						'results'        => [
							'type'        => 'array',
							'description' => 'Per-block validation results. When isValid=false, replace originalContent with expectedContent in your follow-up create_post / update_post call.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'blockName'       => [ 'type' => 'string' ],
									'isValid'         => [ 'type' => 'boolean' ],
									'issues'          => [
										'type'  => 'array',
										'items' => [ 'type' => 'string' ],
									],
									'originalContent' => [
										'type'        => 'string',
										'description' => 'The innerHTML as it appeared in the input.',
									],
									'expectedContent' => [
										'type'        => 'string',
										'description' => 'The corrected innerHTML the model should substitute on retry. Replaces originalContent only — do NOT copy this into the block-comment attributes.',
									],
								],
							],
						],
						'source'         => [
							'type'        => 'string',
							'description' => 'Validation engine that produced the report: "php" (server-side rules) or "js-cached" (browser-primed wp.blocks.validateBlock result).',
						],
						'warnings'       => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'block_count'    => [ 'type' => 'integer' ],
						'freeform_count' => [ 'type' => 'integer' ],
						'hint'           => [
							'type'        => 'string',
							'description' => 'Diff-interpretation guidance emitted only when invalidBlocks > 0.',
						],
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
				'execute_callback'    => [ __CLASS__, 'handle_validate_block_content' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/get-page-blocks',
			[
				'label'               => __( 'Get Page Blocks', 'superdav-ai-agent' ),
				'description'         => __( 'Return the block tree for a post with stable per-block sd_ref UUIDs. Each entry includes flat_index, path, ref, name, attributes, and a text_preview. Set persist_refs: false to read without writing refs back to the post. Use the returned refs with block-mutator abilities to address blocks reliably across multi-step edits.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'      => [
							'type'        => 'integer',
							'description' => 'Post ID whose block tree should be returned.',
						],
						'persist_refs' => [
							'type'        => 'boolean',
							'description' => 'Write newly-assigned sd_ref values back to the post without creating a revision. Default: true. Set to false for a read-only call.',
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'blocks'      => [
							'type'        => 'array',
							'description' => 'Flat list of blocks. Each item: flat_index (int), path (int[]), ref (string), name (string), attributes (object), text_preview (string).',
						],
						'block_count' => [ 'type' => 'integer' ],
						'refs_stored' => [
							'type'        => 'boolean',
							'description' => 'True when new refs were persisted to the post.',
						],
						'revision_id' => [
							'type'        => 'integer',
							'description' => 'Current latest revision ID for the post. Pass this as expected_revision (or If-Match header) on follow-up write calls to enable optimistic concurrency control.',
						],
						'error'       => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_get_page_blocks' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
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
		'sd-ai-agent/get-site-block-usage',
		[
			'label'               => __( 'Get Site Block Usage', 'superdav-ai-agent' ),
			'description'         => __( 'Return site-wide block and pattern usage counts. Provides block_counts (block_name => instances), pattern_counts (synced pattern name => references), top_namespaces (namespace => total instances, sorted), last_scanned (ISO 8601), and a truncated flag when the site has more than 1000 published posts. Use this before planning new content to match the site\'s block voice.', 'superdav-ai-agent' ),
			'category'            => 'sd-ai-agent',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'refresh' => [
						'type'        => 'boolean',
						'description' => 'Force a fresh scan instead of returning the cached result. Ignored when the cache is still within the TTL window or the rate limit is active. Default: false.',
					],
				],
				'required'   => [],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'block_counts'   => [
						'type'        => 'object',
						'description' => 'Associative map of block_name => instance count, sorted descending.',
					],
					'pattern_counts' => [
						'type'        => 'object',
						'description' => 'Associative map of synced pattern name => reference count, sorted descending.',
					],
					'top_namespaces' => [
						'type'        => 'object',
						'description' => 'Associative map of namespace => total block instances, sorted descending.',
					],
					'last_scanned'   => [
						'type'        => 'string',
						'description' => 'ISO 8601 timestamp of the last completed scan, or empty string if no scan has run.',
					],
					'truncated'      => [
						'type'        => 'boolean',
						'description' => 'True when the site has more than 1000 published posts and not all were scanned.',
					],
					'scanned_posts'  => [
						'type'        => 'integer',
						'description' => 'Number of posts that were actually walked during the last scan.',
					],
					'error'          => [ 'type' => 'string' ],
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
			'execute_callback'    => [ __CLASS__, 'handle_get_site_block_usage' ],
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
		);
	}

	// ─── Handlers ─────────────────────────────────────────────────

	/**
	 * Handle markdown-to-blocks conversion.
	 *
	 * @param array<string,mixed> $input Input with 'markdown' key.
	 * @return array<string,mixed>|\WP_Error Result with block_content and block_count.
	 */
	public static function handle_markdown_to_blocks( array $input ) {
		$markdown = $input['markdown'] ?? '';

		if ( empty( $markdown ) ) {
			return new \WP_Error( 'missing_markdown', 'markdown is required.' );
		}

		// @phpstan-ignore-next-line
		$blocks = MarkdownToBlocks::parse( $markdown );
		// @phpstan-ignore-next-line
		$block_content = MarkdownToBlocks::convert( $markdown );

		return [
			'block_content' => $block_content,
			'block_count'   => count( $blocks ),
			'error'         => '',
		];
	}

	/**
	 * Handle listing block types.
	 *
	 * @param array<string,mixed> $input Input with optional category, search, per_page, page.
	 * @return array<string,mixed> Result with block_types, total, and categories.
	 */
	public static function handle_list_block_types( array $input ): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$all      = $registry->get_all_registered();

		$category = $input['category'] ?? '';
		// @phpstan-ignore-next-line
		$search = strtolower( $input['search'] ?? '' );
		// @phpstan-ignore-next-line
		$per_page = max( 1, min( 100, (int) ( $input['per_page'] ?? 20 ) ) );
		// @phpstan-ignore-next-line
		$page = max( 1, (int) ( $input['page'] ?? 1 ) );

		// Build category overview.
		$categories = [];
		foreach ( $all as $block ) {
			$cat = $block->category ?? 'uncategorized';
			if ( ! isset( $categories[ $cat ] ) ) {
				$categories[ $cat ] = 0;
			}
			++$categories[ $cat ];
		}

		// Filter blocks.
		$filtered = [];
		foreach ( $all as $name => $block ) {
			if ( ! empty( $category ) && ( $block->category ?? '' ) !== $category ) {
				continue;
			}

			if ( ! empty( $search ) ) {
				$searchable = strtolower(
					$name . ' ' . ( $block->title ?? '' ) . ' ' .
					( $block->description ?? '' ) . ' ' .
					implode( ' ', $block->keywords ?? [] )
				);
				if ( strpos( $searchable, $search ) === false ) {
					continue;
				}
			}

			$filtered[] = [
				'name'        => $name,
				'title'       => $block->title ?? '',
				'description' => $block->description ?? '',
				'category'    => $block->category ?? '',
				'keywords'    => $block->keywords ?? [],
			];
		}

		// Sort by name.
		usort(
			$filtered,
			function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		$total  = count( $filtered );
		$offset = ( $page - 1 ) * $per_page;
		$paged  = array_slice( $filtered, $offset, $per_page );

		return [
			'block_types' => $paged,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'categories'  => $categories,
		];
	}

	/**
	 * Handle getting a single block type's full metadata.
	 *
	 * @param array<string,mixed> $input Input with 'name' key.
	 * @return array<string,mixed>|\WP_Error Full block type metadata.
	 */
	public static function handle_get_block_type( array $input ) {
		$name = $input['name'] ?? '';

		if ( empty( $name ) ) {
			return new \WP_Error( 'missing_name', 'name is required.' );
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		// @phpstan-ignore-next-line
		$block = $registry->get_registered( $name );

		if ( ! $block ) {
			// @phpstan-ignore-next-line
			return new \WP_Error( 'block_not_found', "Block type '{$name}' not found." );
		}

		$result = [
			'name'        => $block->name,
			'title'       => $block->title ?? '',
			'description' => $block->description ?? '',
			'category'    => $block->category ?? '',
			'keywords'    => $block->keywords ?? [],
			'attributes'  => $block->attributes ?? [],
			'supports'    => $block->supports ?? [],
		];

		if ( ! empty( $block->styles ) ) {
			$result['styles'] = $block->styles;
		}

		if ( ! empty( $block->variations ) ) {
			$result['variations'] = array_map(
				function ( $v ) {
					return [
						'name'        => $v['name'] ?? '',
						'title'       => $v['title'] ?? '',
						'description' => $v['description'] ?? '',
						'isDefault'   => $v['isDefault'] ?? false,
					];
				},
				$block->variations
			);
		}

		// Generate example markup if example data exists.
		if ( ! empty( $block->example ) ) {
			$example_block            = [
				'blockName'    => $block->name,
				'attrs'        => $block->example['attributes'] ?? [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			];
			$result['example_markup'] = serialize_block( $example_block );
		}

		return $result;
	}

	/**
	 * Handle listing block patterns.
	 *
	 * @param array<string,mixed> $input Input with optional category, search, per_page, full_content.
	 * @return array<string,mixed> Result with patterns, total, and categories.
	 */
	public static function handle_list_block_patterns( array $input ): array {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$all      = $registry->get_all_registered();

		$category = $input['category'] ?? '';
		// @phpstan-ignore-next-line
		$search = strtolower( $input['search'] ?? '' );
		// @phpstan-ignore-next-line
		$per_page     = max( 1, min( 50, (int) ( $input['per_page'] ?? 10 ) ) );
		$full_content = ! empty( $input['full_content'] );

		// Build category overview.
		$categories = [];
		foreach ( $all as $pattern ) {
			foreach ( $pattern['categories'] ?? [] as $cat ) {
				if ( ! isset( $categories[ $cat ] ) ) {
					$categories[ $cat ] = 0;
				}
				++$categories[ $cat ];
			}
		}

		// Filter patterns.
		$filtered = [];
		foreach ( $all as $pattern ) {
			if ( ! empty( $category ) ) {
				if ( ! in_array( $category, $pattern['categories'] ?? [], true ) ) {
					continue;
				}
			}

			if ( ! empty( $search ) ) {
				$searchable = strtolower(
					( $pattern['name'] ?? '' ) . ' ' .
					( $pattern['title'] ?? '' ) . ' ' .
					( $pattern['description'] ?? '' )
				);
				if ( strpos( $searchable, $search ) === false ) {
					continue;
				}
			}

			$content = $pattern['content'] ?? '';
			if ( ! $full_content && strlen( $content ) > 500 ) {
				$content = substr( $content, 0, 500 ) . '...';
			}

			$filtered[] = [
				'name'        => $pattern['name'] ?? '',
				'title'       => $pattern['title'] ?? '',
				'description' => $pattern['description'] ?? '',
				'categories'  => $pattern['categories'] ?? [],
				'blockTypes'  => $pattern['blockTypes'] ?? [],
				'content'     => $content,
			];
		}

		$total = count( $filtered );
		$paged = array_slice( $filtered, 0, $per_page );

		return [
			'patterns'   => $paged,
			'total'      => $total,
			'categories' => $categories,
		];
	}

	/**
	 * Handle listing block templates.
	 *
	 * @param array<string,mixed> $input Input with optional search.
	 * @return array<string,mixed> Result with templates and total.
	 */
	public static function handle_list_block_templates( array $input ): array {
		// @phpstan-ignore-next-line
		$search = strtolower( $input['search'] ?? '' );

		$templates = get_block_templates();

		$result = [];
		foreach ( $templates as $template ) {
			$title = $template->title ?? $template->slug;
			$desc  = $template->description ?? '';

			if ( ! empty( $search ) ) {
				$searchable = strtolower( $template->slug . ' ' . $title . ' ' . $desc );
				if ( strpos( $searchable, $search ) === false ) {
					continue;
				}
			}

			$result[] = [
				'slug'        => $template->slug,
				'title'       => $title,
				'description' => $desc,
				'type'        => $template->type ?? 'wp_template',
				'post_types'  => $template->post_types ?? [],
			];
		}

		return [
			'templates' => $result,
			'total'     => count( $result ),
		];
	}

	/**
	 * Handle creating block content from a structured array.
	 *
	 * @param array<string,mixed> $input Input with 'blocks' array.
	 * @return array<string,mixed>|\WP_Error Result with block_content and block_count.
	 */
	public static function handle_create_block_content( array $input ) {
		$blocks = $input['blocks'] ?? [];

		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return new \WP_Error( 'missing_blocks', 'blocks array is required.' );
		}

		$output      = '';
		$block_count = 0;

		foreach ( $blocks as $block_data ) {
			// @phpstan-ignore-next-line
			$normalized = self::normalize_block( $block_data );
			// @phpstan-ignore-next-line
			$output .= serialize_block( $normalized ) . "\n\n";
			++$block_count;
			$block_count += self::count_inner_blocks( $normalized );
		}

		return [
			'block_content' => trim( $output ),
			'block_count'   => $block_count,
			'error'         => '',
		];
	}

	/**
	 * Handle parsing existing block content.
	 *
	 * @param array<string,mixed> $input Input with post_id or content, optional site_url.
	 * @return array<string,mixed>|\WP_Error Result with blocks and block_count.
	 */
	public static function handle_parse_block_content( array $input ) {
		// @phpstan-ignore-next-line
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$content  = $input['content'] ?? '';
		$site_url = $input['site_url'] ?? '';

		if ( ! $post_id && empty( $content ) ) {
			return new \WP_Error( 'missing_input', 'Either post_id or content is required.' );
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
			} elseif ( ! $blog_id ) {
				// @phpstan-ignore-next-line
				return new \WP_Error( 'site_not_found', "Could not find a site matching URL: {$site_url}" );
			}
		}

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				if ( $switched ) {
					restore_current_blog();
				}
				return new \WP_Error( 'post_not_found', "Post {$post_id} not found." );
			}
			$content = $post->post_content;
		}

		// @phpstan-ignore-next-line
		$parsed = parse_blocks( $content );
		$blocks = self::clean_parsed_blocks( $parsed );

		if ( $switched ) {
			restore_current_blog();
		}

		return [
			'blocks'      => $blocks,
			'block_count' => count( $blocks ),
			'error'       => '',
		];
	}

	// ─── Private helpers ──────────────────────────────────────────

	/**
	 * Normalize a simplified agent-friendly block into serialize_block() format.
	 *
	 * @param array<string,mixed> $data Block data with blockName, attrs, content, innerBlocks.
	 * @return array<string,mixed> Full block array for serialize_block().
	 */
	private static function normalize_block( array $data ): array {
		$block_name = $data['blockName'] ?? '';
		$attrs      = $data['attrs'] ?? [];
		$content    = $data['content'] ?? '';
		$inner_data = $data['innerBlocks'] ?? [];

		// Recursively normalize inner blocks.
		$inner_blocks = [];
		// @phpstan-ignore-next-line
		foreach ( $inner_data as $child ) {
			// @phpstan-ignore-next-line
			$inner_blocks[] = self::normalize_block( $child );
		}

		// Generate markup based on block type.
		switch ( $block_name ) {
			case 'core/paragraph':
				// @phpstan-ignore-next-line
				$html = '<p>' . $content . '</p>';
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );

			case 'core/heading':
				// @phpstan-ignore-next-line
				$level = (int) ( $attrs['level'] ?? 2 );
				// @phpstan-ignore-next-line
				$html = '<h' . $level . ' class="wp-block-heading">' . $content . '</h' . $level . '>';
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );

			case 'core/list':
				// @phpstan-ignore-next-line
				$ordered = ! empty( $attrs['ordered'] );
				$tag     = $ordered ? 'ol' : 'ul';

				if ( ! empty( $inner_blocks ) ) {
					$inner_html    = '<' . $tag . '>';
					$inner_content = [ '<' . $tag . '>' ];
					foreach ( $inner_blocks as $item ) {
						$inner_content[] = null;
						// @phpstan-ignore-next-line
						$inner_html .= $item['innerHTML'] ?? '';
					}
					$inner_content[] = '</' . $tag . '>';
					$inner_html     .= '</' . $tag . '>';

					return [
						'blockName'    => $block_name,
						'attrs'        => $attrs,
						'innerBlocks'  => $inner_blocks,
						'innerHTML'    => $inner_html,
						'innerContent' => $inner_content,
					];
				}

				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, '<' . $tag . '></' . $tag . '>' );

			case 'core/list-item':
				// @phpstan-ignore-next-line
				$html = '<li>' . $content . '</li>';
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );

			case 'core/image':
				// @phpstan-ignore-next-line
				$url = esc_url( $attrs['url'] ?? '' );
				// @phpstan-ignore-next-line
				$alt  = esc_attr( $attrs['alt'] ?? '' );
				$html = '<figure class="wp-block-image"><img src="' . $url . '" alt="' . $alt . '"/></figure>';
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );

			case 'core/quote':
				// @phpstan-ignore-next-line
				$html = '<blockquote class="wp-block-quote"><p>' . $content . '</p></blockquote>';
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );

			case 'core/code':
				// @phpstan-ignore-next-line
				$escaped = esc_html( $content );
				$html    = '<pre class="wp-block-code"><code>' . $escaped . '</code></pre>';
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );

			case 'core/buttons':
				// @phpstan-ignore-next-line
				return self::build_container( $block_name, $attrs, $inner_blocks, 'div', 'wp-block-buttons' );

			case 'core/button':
				// @phpstan-ignore-next-line
				$url = esc_url( $attrs['url'] ?? '' );
				// @phpstan-ignore-next-line
				$text = $attrs['text'] ?? $content;
				// @phpstan-ignore-next-line
				$html = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $text . '</a></div>';
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );

			case 'core/columns':
				// @phpstan-ignore-next-line
				return self::build_container( $block_name, $attrs, $inner_blocks, 'div', 'wp-block-columns' );

			case 'core/column':
				// @phpstan-ignore-next-line
				return self::build_container( $block_name, $attrs, $inner_blocks, 'div', 'wp-block-column' );

			case 'core/group':
				// @phpstan-ignore-next-line
				return self::build_container( $block_name, $attrs, $inner_blocks, 'div', 'wp-block-group' );

			case 'core/separator':
				$html = '<hr class="wp-block-separator has-alpha-channel-opacity"/>';
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );

			case 'core/spacer':
				// @phpstan-ignore-next-line
				$height = $attrs['height'] ?? '50px';
				// @phpstan-ignore-next-line
				$html = '<div style="height:' . esc_attr( $height ) . '" aria-hidden="true" class="wp-block-spacer"></div>';
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );

			case 'core/cover':
				// @phpstan-ignore-next-line
				return self::build_container( $block_name, $attrs, $inner_blocks, 'div', 'wp-block-cover' );

			default:
				// Unknown blocks: pass content as raw innerHTML.
				$html = $content;
				if ( ! empty( $inner_blocks ) ) {
					// @phpstan-ignore-next-line
					return self::build_container_raw( $block_name, $attrs, $inner_blocks, $html );
				}
				// @phpstan-ignore-next-line
				return self::build_block( $block_name, $attrs, $inner_blocks, $html );
		}
	}

	/**
	 * Build a simple block array (no inner blocks in innerContent).
	 *
	 * @param string              $block_name  Block name.
	 * @param array<string,mixed> $attrs       Block attributes.
	 * @param array<int,mixed>    $inner_blocks Inner blocks.
	 * @param string              $html        Inner HTML.
	 * @return array<string,mixed> Block array.
	 */
	private static function build_block( string $block_name, array $attrs, array $inner_blocks, string $html ): array {
		return [
			'blockName'    => $block_name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * Build a container block with inner block placeholders in innerContent.
	 *
	 * @param string              $block_name  Block name.
	 * @param array<string,mixed> $attrs       Block attributes.
	 * @param array<int,mixed>    $inner_blocks Inner blocks.
	 * @param string              $tag         HTML tag (div, section, etc.).
	 * @param string              $class       CSS class.
	 * @return array<string,mixed> Block array.
	 */
	private static function build_container( string $block_name, array $attrs, array $inner_blocks, string $tag, string $class ): array {
		$open  = '<' . $tag . ' class="' . esc_attr( $class ) . '">';
		$close = '</' . $tag . '>';

		$inner_content = [ $open ];
		$inner_html    = $open;

		foreach ( $inner_blocks as $child ) {
			$inner_content[] = null;
			// @phpstan-ignore-next-line
			$inner_html .= $child['innerHTML'] ?? '';
		}

		$inner_content[] = $close;
		$inner_html     .= $close;

		return [
			'blockName'    => $block_name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Build a container for unknown blocks with inner block placeholders.
	 *
	 * @param string              $block_name   Block name.
	 * @param array<string,mixed> $attrs        Block attributes.
	 * @param array<int,mixed>    $inner_blocks Inner blocks.
	 * @param string              $wrapper_html Optional wrapper HTML.
	 * @return array<string,mixed> Block array.
	 */
	private static function build_container_raw( string $block_name, array $attrs, array $inner_blocks, string $wrapper_html ): array {
		$inner_content = [];
		$inner_html    = '';

		if ( ! empty( $wrapper_html ) ) {
			$inner_content[] = $wrapper_html;
			$inner_html     .= $wrapper_html;
		}

		foreach ( $inner_blocks as $child ) {
			$inner_content[] = null;
			// @phpstan-ignore-next-line
			$inner_html .= $child['innerHTML'] ?? '';
		}

		return [
			'blockName'    => $block_name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Count inner blocks recursively.
	 *
	 * @param array<string,mixed> $block Block array.
	 * @return int Total inner block count.
	 */
	private static function count_inner_blocks( array $block ): int {
		$count = 0;
		// @phpstan-ignore-next-line
		foreach ( $block['innerBlocks'] ?? [] as $child ) {
			++$count;
			// @phpstan-ignore-next-line
			$count += self::count_inner_blocks( $child );
		}
		return $count;
	}

	/**
	 * Clean up parsed blocks from parse_blocks(), removing empty freeform blocks.
	 *
	 * @param array<int|string,mixed> $blocks Parsed blocks from parse_blocks().
	 * @return array<int,mixed> Cleaned block tree.
	 */
	private static function clean_parsed_blocks( array $blocks ): array {
		$cleaned = [];

		foreach ( $blocks as $block ) {
			// Skip null/empty freeform blocks (whitespace between blocks).
			// @phpstan-ignore-next-line
			if ( empty( $block['blockName'] ) ) {
				// @phpstan-ignore-next-line
				$content = trim( $block['innerHTML'] ?? '' );
				if ( empty( $content ) ) {
					continue;
				}
			}

			$result = [
				// @phpstan-ignore-next-line
				'blockName' => $block['blockName'] ?? null,
				// @phpstan-ignore-next-line
				'attrs'     => $block['attrs'] ?? [],
				// @phpstan-ignore-next-line
				'innerHTML' => trim( $block['innerHTML'] ?? '' ),
			];

			// @phpstan-ignore-next-line
			if ( ! empty( $block['innerBlocks'] ) ) {
				// @phpstan-ignore-next-line
				$result['innerBlocks'] = self::clean_parsed_blocks( $block['innerBlocks'] );
			}

			$cleaned[] = $result;
		}

		return $cleaned;
	}

	// ─── Validate handler ────────────────────────────────────────

	/**
	 * Handle block content validation.
	 *
	 * Parses the content and checks for common issues:
	 * - Freeform blocks containing markdown (mixed content)
	 * - Empty freeform blocks
	 * - Mismatched block comment structure
	 * - Content with no real blocks (pure markdown passed as blocks)
	 *
	 * @param array<string,mixed> $input Input with 'content' key.
	 * @return array<string,mixed>|\WP_Error Validation result.
	 */
	public static function handle_validate_block_content( array $input ) {
		$content = $input['content'] ?? '';

		if ( empty( $content ) ) {
			return new \WP_Error( 'missing_content', 'Content is required for validation.' );
		}

		// Delegate to BlockValidator which applies structural checks and the
		// BlockContentPolicy for core/html blocks (GH#1584 + GH#1585).
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		// Collect backwards-compatible summary fields alongside the Studio report.
		$warnings = [];
		foreach ( $report['results'] as $result ) {
			if ( ! $result['isValid'] ) {
				foreach ( (array) $result['issues'] as $issue ) {
					$warnings[] = sprintf( '[%s] %s', $result['blockName'], $issue );
				}
			}
		}

		// Also run legacy freeform / markdown / unmatched-comment checks so
		// existing callers that rely on the 'warnings' key are not broken.
		$parsed         = parse_blocks( $content );
		$block_count    = 0;
		$freeform_count = 0;

		foreach ( $parsed as $block ) {
			$block_name = $block['blockName'] ?? null;

			if ( null === $block_name ) {
				$inner = trim( (string) ( $block['innerHTML'] ?? '' ) );

				if ( '' === $inner ) {
					continue;
				}

				++$freeform_count;

				$has_heading = (bool) preg_match( '/^#{1,6}\s+\S/m', $inner );
				$has_list    = (bool) preg_match( '/^[\-\*]\s+\S/m', $inner );
				$has_bold    = (bool) preg_match( '/\*{2}[^*]+\*{2}/', $inner );
				$has_link    = (bool) preg_match( '/\[[^\]]+\]\([^)]+\)/', $inner );
				$has_code    = str_contains( $inner, '```' );

				$markdown_signals = [];
				if ( $has_heading ) {
					$markdown_signals[] = 'headings (##)';
				}
				if ( $has_list ) {
					$markdown_signals[] = 'list items (- or *)';
				}
				if ( $has_bold ) {
					$markdown_signals[] = 'bold (**text**)';
				}
				if ( $has_link ) {
					$markdown_signals[] = 'links ([text](url))';
				}
				if ( $has_code ) {
					$markdown_signals[] = 'code fences (```)';
				}

				if ( ! empty( $markdown_signals ) ) {
					$preview    = mb_substr( $inner, 0, 80 );
					$warnings[] = sprintf(
						'Freeform block contains markdown (%s): "%s..." — This will NOT render correctly. Convert to block markup or use pure markdown for the entire content.',
						implode( ', ', $markdown_signals ),
						$preview
					);
				}
			} else {
				++$block_count;
			}
		}

		if ( 0 === $block_count && $freeform_count > 0 ) {
			$warnings[] = 'Content has no Gutenberg blocks — it appears to be plain text or markdown. Use markdown format (without <!-- wp: --> comments) and it will be auto-converted, or write proper block markup.';
		}

		$opens  = preg_match_all( '/<!-- wp:(\S+)/', $content );
		$closes = preg_match_all( '/<!-- \/wp:(\S+)/', $content );
		if ( $opens !== $closes ) {
			$warnings[] = sprintf(
				'Mismatched block comments: %d opening vs %d closing. Check for unclosed blocks.',
				$opens,
				$closes
			);
		}

		$result = array_merge(
			$report,
			[
				'valid'          => empty( $warnings ) && 0 === $report['invalidBlocks'],
				'warnings'       => $warnings,
				'block_count'    => $block_count,
				'freeform_count' => $freeform_count,
			]
		);

		// GH#1589: append diff-interpretation hint only when blocks are invalid.
		// Without this, models "fix" by copy-pasting the Expected string literally
		// and omit the matching block-comment attribute, causing a fix loop.
		if ( $report['invalidBlocks'] > 0 ) {
			$result['hint'] = 'Before fixing: each Expected/Actual diff is a structural change, not a literal text swap. Classes the validator adds or removes (`has-X-color`, `alignwide`, `is-style-Y`, `wp-block-*-is-layout-flex`) pull in or strip core CSS that drives layout, spacing, and color. Diff the markup explicitly, update any style.css selectors that target the old class or nesting in the same edit batch, preserve your intentional className hooks, then take a screenshot of desktop and mobile to verify the design did not drift.';
		}

		return $result;
	}

	/**
	 * Handle get-site-block-usage ability.
	 *
	 * Returns the cached (or freshly scanned) site-wide block and pattern
	 * usage inventory.
	 *
	 * @param array<string,mixed> $input Input with optional 'refresh' boolean.
	 * @return array<string,mixed>
	 */
	public static function handle_get_site_block_usage( array $input ): array {
		$refresh = ! empty( $input['refresh'] );

		try {
			$result = BlockInventory::get( $refresh );
		} catch ( \Throwable $e ) {
			return array( 'error' => $e->getMessage() );
		}

		return $result;
	}

	/**
	 * Handle get-page-blocks ability.
	 *
	 * Returns the block tree for a post with stable per-block sd_ref
	 * identifiers. Each block entry includes flat_index, path, ref, name,
	 * attributes, and text_preview.
	 *
	 * Refs are assigned to any block that is missing one, and written back to
	 * the post (without creating a revision) when persist_refs is true (default).
	 * If all blocks already carry refs, no DB write is made.
	 *
	 * @param array<string,mixed> $input Input with 'post_id' (required) and optional 'persist_refs' (bool, default true).
	 * @return array<string,mixed>|\WP_Error Block tree or error.
	 */
	public static function handle_get_page_blocks( array $input ) {
		global $wpdb;

		$post_id      = (int) ( $input['post_id'] ?? 0 );
		$persist_refs = isset( $input['persist_refs'] ) ? (bool) $input['persist_refs'] : true;

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required and must be a positive integer.', 'superdav-ai-agent' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: post ID */
					__( 'Post %d not found.', 'superdav-ai-agent' ),
					$post_id
				)
			);
		}

		$content = $post->post_content;
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return [
				'blocks'      => [],
				'block_count' => 0,
				'refs_stored' => false,
				'revision_id' => RevisionGuard::current_revision_id( $post_id ),
			];
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) ) {
			$blocks = [];
		}

		// Check whether any block is missing a ref before assigning.
		// @phpstan-ignore-next-line
		$refs_needed = ! BlockReferences::all_have_refs( $blocks );

		// Assign refs; if any blocks were missing one they now have one.
		if ( $refs_needed ) {
			// @phpstan-ignore-next-line
			$result = BlockReferences::assign_refs( $blocks );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$blocks = $result;
		}

		// Persist newly-assigned refs to DB without creating a revision.
		$refs_stored = false;
		if ( $persist_refs && $refs_needed ) {
			// @phpstan-ignore-next-line
			$new_content = serialize_blocks( $blocks );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$wpdb->posts,
				[ 'post_content' => $new_content ],
				[ 'ID' => $post_id ],
				[ '%s' ],
				[ '%d' ]
			);

			if ( false !== $updated ) {
				clean_post_cache( $post_id );
				$refs_stored = true;
			}
		}

		// Build the flat annotated block list for the response.
		$flat_list  = [];
		$flat_index = 0;
		// @phpstan-ignore-next-line
		self::flatten_blocks_for_response( $blocks, [], $flat_index, $flat_list );

		return [
			'blocks'      => $flat_list,
			'block_count' => $flat_index,
			'refs_stored' => $refs_stored,
			'revision_id' => RevisionGuard::current_revision_id( $post_id ),
		];
	}

	/**
	 * Depth-first walk that appends a flat annotated entry per named block.
	 *
	 * Each entry contains:
	 * - flat_index  (int)    — depth-first sequential position.
	 * - path        (int[])  — index path from root (e.g. [0, 1, 2]).
	 * - ref         (string) — sd_ref UUID, if present.
	 * - name        (string) — block name (e.g. "core/paragraph").
	 * - attributes  (array)  — block attrs.
	 * - text_preview (string) — up to 100 chars of stripped inner text, if any.
	 * - innerBlocks (array)  — nested block entries, if any.
	 *
	 * @param array<int,mixed> $blocks      Parsed blocks at the current level.
	 * @param int[]            $parent_path Index path to the parent.
	 * @param int              $flat_index  Running flat counter (by reference).
	 * @param array<int,mixed> $output      Accumulating flat list (by reference).
	 */
	private static function flatten_blocks_for_response(
		array $blocks,
		array $parent_path,
		int &$flat_index,
		array &$output
	): void {
		foreach ( $blocks as $local_idx => $block ) {
			// @phpstan-ignore-next-line
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$current_path = array_merge( $parent_path, [ (int) $local_idx ] );

			$entry = [
				'flat_index' => $flat_index,
				'path'       => $current_path,
				// @phpstan-ignore-next-line
				'name'       => (string) $block['blockName'],
				// @phpstan-ignore-next-line
				'attributes' => $block['attrs'] ?? [],
			];

			// Surface the stable ref.
			// @phpstan-ignore-next-line
			$ref = $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
			if ( is_string( $ref ) && '' !== $ref ) {
				$entry['ref'] = $ref;
			}

			// text_preview: stripped, decoded, truncated inner HTML.
			// @phpstan-ignore-next-line
			$inner_html = (string) ( $block['innerHTML'] ?? '' );
			if ( '' !== $inner_html ) {
				$preview = wp_strip_all_tags( $inner_html );
				$preview = html_entity_decode( $preview, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$preview = (string) preg_replace( '/\s+/', ' ', trim( $preview ) );
				if ( '' !== $preview ) {
					$entry['text_preview'] = mb_substr( $preview, 0, 100 );
				}
			}

			++$flat_index;

			// Recurse into inner blocks (depth is naturally capped by PHP stack + MAX_DEPTH).
			// @phpstan-ignore-next-line
			$inner = $block['innerBlocks'] ?? [];
			if ( ! empty( $inner ) && is_array( $inner ) ) {
				$inner_output = [];
				// @phpstan-ignore-next-line
				self::flatten_blocks_for_response( $inner, $current_path, $flat_index, $inner_output );
				if ( ! empty( $inner_output ) ) {
					$entry['innerBlocks'] = $inner_output;
				}
			}

			$output[] = $entry;
		}
	}
}

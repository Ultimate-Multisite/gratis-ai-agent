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

use SdAiAgent\Core\BlockContentPolicy;
use SdAiAgent\Core\BlockInventory;
use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\BlockReferences;
use SdAiAgent\Core\BlockTreeAddress;
use SdAiAgent\Core\BlockValidator;
use SdAiAgent\Core\PatternInserter;
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
						'tier'     => [
							'type'        => 'string',
							'description' => 'Filter by tier: "preferred", "acceptable", "avoid", or "legacy".',
							'enum'        => [ 'preferred', 'acceptable', 'avoid', 'legacy' ],
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
						'block_types' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'name'        => [ 'type' => 'string' ],
									'title'       => [ 'type' => 'string' ],
									'description' => [ 'type' => 'string' ],
									'category'    => [ 'type' => 'string' ],
									'keywords'    => [ 'type' => 'array' ],
									'score'       => [ 'type' => 'integer' ],
									'tier'        => [ 'type' => 'string' ],
									'suggested_replacement' => [ 'type' => [ 'string', 'null' ] ],
								],
							],
						],
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
				'description'         => __( 'Return the block tree for a post with stable per-block sd_ref UUIDs. Each entry includes flat_index, path, ref, name, attributes, and a text_preview. Set persist_refs: false to read without writing refs back to the post. Use the returned refs with block-mutator abilities to address blocks reliably across multi-step edits. Optional parameters: outline (minimal response), summary_only (statistics), search (filter by text), block_name (filter by name), render (resolve dynamic blocks), fields (allowlist response fields).', 'superdav-ai-agent' ),
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
						'outline'      => [
							'type'        => 'boolean',
							'description' => 'Return only flat_index, path, name, and heading_text (if applicable). Omits attributes and innerHTML. Default: false.',
						],
						'summary_only' => [
							'type'        => 'boolean',
							'description' => 'Return only block_counts (histogram), headings (list with level/text/path), section_markers, and max_depth. Skips per-block details. Default: false.',
						],
						'search'       => [
							'type'        => 'string',
							'description' => 'Filter blocks by text_preview substring (case-insensitive). Only blocks matching the search term are returned.',
						],
						'block_name'   => [
							'type'        => 'string',
							'description' => 'Filter blocks by exact block name (e.g., "core/heading"). Only matching blocks are returned.',
						],
						'render'       => [
							'type'        => 'boolean',
							'description' => 'Resolve dynamic blocks, expand shortcodes, and follow synced patterns. Default: false (returns raw markup).',
						],
						'fields'       => [
							'type'        => 'string',
							'description' => 'Comma-separated allowlist of response fields (e.g., "name,ref,path"). If omitted, all fields are included.',
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'blocks'      => [
							'type'        => 'array',
							'description' => 'Flat list of blocks (or empty if summary_only). Each item: flat_index (int), path (int[]), ref (string), name (string), attributes (object), text_preview (string).',
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
						'summary'     => [
							'type'        => 'object',
							'description' => 'Present when summary_only: true. Contains block_counts, headings, section_markers, and max_depth.',
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

		wp_register_ability(
			'sd-ai-agent/edit-block-tree',
			[
				'label'               => __( 'Edit Block Tree', 'superdav-ai-agent' ),
				'description'         => __( 'Mutate a post\'s Gutenberg block tree at a specific block (addressed by ref, path, or flat_index) using one of nine operations: update-attrs, update-html, replace-block, remove-block, wrap-in-group, unwrap-group, insert-child, duplicate, move. Set dry_run: true to validate and preview without writing. Use sd-ai-agent/get-page-blocks first to obtain the refs and structure.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'            => [
							'type'        => 'integer',
							'description' => 'Post ID whose block tree will be mutated.',
						],
						'op'                 => [
							'type'        => 'string',
							'description' => 'Operation: update-attrs | update-html | replace-block | remove-block | wrap-in-group | unwrap-group | insert-child | duplicate | move.',
							'enum'        => [
								'update-attrs',
								'update-html',
								'replace-block',
								'remove-block',
								'wrap-in-group',
								'unwrap-group',
								'insert-child',
								'duplicate',
								'move',
							],
						],
						'ref'                => [
							'type'        => 'string',
							'description' => 'Stable sd_ref UUID of the target block (e.g. blk_a3f2c1q9). Takes priority over path and flat_index.',
						],
						'path'               => [
							'type'        => 'array',
							'description' => 'Integer index path from root (e.g. [0, 1, 2]). Used when ref is absent.',
							'items'       => [ 'type' => 'integer' ],
						],
						'flat_index'         => [
							'type'        => 'integer',
							'description' => 'Zero-based depth-first position from get-page-blocks. Used when ref and path are absent.',
						],
						'attributes'         => [
							'type'        => 'object',
							'description' => 'For update-attrs: attributes to merge/replace. For wrap-in-group: optional attributes for the new group wrapper.',
						],
						'merge'              => [
							'type'        => 'boolean',
							'description' => 'For update-attrs: true (default) merges supplied attrs over existing; false replaces entirely.',
						],
						'innerHTML'          => [
							'type'        => 'string',
							'description' => 'For update-html: new raw innerHTML string (wp_kses_post applied).',
						],
						'block_def'          => [
							'type'        => 'object',
							'description' => 'For replace-block, insert-child: the full block definition (blockName, attrs, innerHTML, innerBlocks).',
						],
						'position'           => [
							'description' => 'For insert-child: 0-based index to insert at (default: end). For move: "before" or "after" the destination block (default: "after").',
						],
						'destination'        => [
							'type'        => 'object',
							'description' => 'For move: address of the block to insert next to. Same format as top-level addressing (ref, path, or flat_index).',
							'properties'  => [
								'ref'        => [ 'type' => 'string' ],
								'path'       => [
									'type'  => 'array',
									'items' => [ 'type' => 'integer' ],
								],
								'flat_index' => [ 'type' => 'integer' ],
							],
						],
						'allow_bound_writes' => [
							'type'        => 'boolean',
							'description' => 'Override the Block Bindings write-lock. When true, writes to attributes listed in the block\'s metadata.bindings are allowed. Default: false.',
						],
						'dry_run'            => [
							'type'        => 'boolean',
							'description' => 'When true, validate and compute the result but do not persist. Returns the would-be block tree.',
						],
					],
					'required'   => [ 'post_id', 'op' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'    => [
							'type'        => 'boolean',
							'description' => 'True when the operation succeeded (or dry_run succeeded).',
						],
						'dry_run'    => [
							'type'        => 'boolean',
							'description' => 'Echoes the dry_run flag.',
						],
						'op'         => [
							'type'        => 'string',
							'description' => 'The operation that was applied.',
						],
						'post_id'    => [
							'type'        => 'integer',
							'description' => 'Post ID that was mutated.',
						],
						'block_tree' => [
							'type'        => 'array',
							'description' => 'The resulting block tree (always returned, even on dry_run).',
						],
						'error'      => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_edit_block_tree' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
				],
			]
		);

		wp_register_ability(
			'sd-ai-agent/update-blocks',
			[
				'label'               => __( 'Update Blocks (Batch)', 'superdav-ai-agent' ),
				'description'         => __( 'Apply up to 50 independent block updates atomically inside one WordPress revision, with all-or-nothing pre-flight validation. Each update specifies an operation (update-attrs, update-html, replace-block, remove-block, etc.) and a target block (by ref, path, or flat_index). If any single update fails validation, the entire batch rejects with per-item errors — nothing hits disk. On success, all updates are serialised and written as one revision. Use sd-ai-agent/get-page-blocks first to obtain refs.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'           => [
							'type'        => 'integer',
							'description' => 'Post ID whose block tree will be mutated.',
						],
						'updates'           => [
							'type'        => 'array',
							'description' => 'Array of update objects (max 50). Each must include "op" (operation name) and a target address (ref, path, or flat_index), plus op-specific arguments (attributes, innerHTML, block_def, destination, etc.).',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'op'                 => [
										'type'        => 'string',
										'description' => 'Operation: update-attrs | update-html | replace-block | remove-block | wrap-in-group | unwrap-group | insert-child | duplicate | move.',
										'enum'        => [
											'update-attrs',
											'update-html',
											'replace-block',
											'remove-block',
											'wrap-in-group',
											'unwrap-group',
											'insert-child',
											'duplicate',
											'move',
										],
									],
									'ref'                => [
										'type'        => 'string',
										'description' => 'Stable sd_ref UUID of the target block.',
									],
									'path'               => [
										'type'        => 'array',
										'description' => 'Integer index path from root.',
										'items'       => [ 'type' => 'integer' ],
									],
									'flat_index'         => [
										'type'        => 'integer',
										'description' => 'Zero-based depth-first position.',
									],
									'allow_bound_writes' => [
										'type'        => 'boolean',
										'description' => 'Override the Block Bindings write-lock for this update. Default: false.',
									],
								],
								'required'   => [ 'op' ],
							],
						],
						'expected_revision' => [
							'type'        => 'integer',
							'description' => 'Expected revision ID for optimistic concurrency. Pass the revision_id from get-page-blocks to prevent writes against a stale post.',
						],
						'dry_run'           => [
							'type'        => 'boolean',
							'description' => 'When true, validate and compute the result but do not persist. Returns the would-be block tree and revision_count: 0.',
						],
					],
					'required'   => [ 'post_id', 'updates' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => [
							'type'        => 'boolean',
							'description' => 'True when all updates succeeded.',
						],
						'post_id'     => [ 'type' => 'integer' ],
						'updates'     => [
							'type'        => 'integer',
							'description' => 'Number of updates applied.',
						],
						'revision_id' => [
							'type'        => 'integer',
							'description' => 'Post revision ID after the write (or current if dry_run).',
						],
						'block_tree'  => [
							'type'        => 'array',
							'description' => 'The resulting block tree after all updates.',
						],
						'dry_run'     => [ 'type' => 'boolean' ],
						'error'       => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_update_blocks' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
				],
			]
		);

		wp_register_ability(
			'sd-ai-agent/insert-pattern',
			[
				'label'               => __( 'Insert Pattern', 'superdav-ai-agent' ),
				'description'         => __( 'Insert a registered block pattern (inline expansion) or a synced pattern (wp_block reference) into a post at a specified anchor position. For registered patterns (e.g. "core/quote"), the pattern content is expanded server-side into individual blocks and inlined at the anchor. For synced patterns (numeric ID, "wp-block:N", or "synced:N"), a single core/block reference is inserted — the editor renders it transcluded. Supports five anchor modes: after_top_level (append to root), before_top_level (prepend to root), after_ref (insert after a ref\'d block), before_ref (insert before a ref\'d block), and first_child_of_ref (insert as first children of a container block). Optimistic concurrency via expected_revision_id. Refs assigned to all inserted blocks.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'              => [
							'type'        => 'integer',
							'description' => 'Post ID to insert the pattern into.',
						],
						'pattern'              => [
							'description' => 'Pattern identifier: registered pattern slug (e.g. "core/quote"), numeric synced pattern ID (42), or prefixed form ("wp-block:42", "synced:42").',
						],
						'anchor'               => [
							'type'        => 'string',
							'description' => 'Where to insert: after_top_level (default), before_top_level, after_ref, before_ref, or first_child_of_ref.',
							'enum'        => [
								'after_top_level',
								'before_top_level',
								'after_ref',
								'before_ref',
								'first_child_of_ref',
							],
						],
						'ref'                  => [
							'type'        => 'string',
							'description' => 'Stable sd_ref UUID of the target block. Required when anchor is after_ref, before_ref, or first_child_of_ref.',
						],
						'expected_revision_id' => [
							'type'        => 'integer',
							'description' => 'Expected revision ID for optimistic concurrency control. Pass the revision_id from get-page-blocks.',
						],
						'dry_run'              => [
							'type'        => 'boolean',
							'description' => 'When true, validate and compute the result but do not persist. Default: false.',
						],
					],
					'required'   => [ 'post_id', 'pattern' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'         => [
							'type'        => 'boolean',
							'description' => 'True when the pattern was inserted successfully.',
						],
						'dry_run'         => [ 'type' => 'boolean' ],
						'post_id'         => [ 'type' => 'integer' ],
						'pattern_type'    => [
							'type'        => 'string',
							'description' => 'Whether the pattern was "registered" (inlined) or "synced" (referenced).',
							'enum'        => [ 'registered', 'synced' ],
						],
						'blocks_inserted' => [
							'type'        => 'integer',
							'description' => 'Number of top-level blocks inserted.',
						],
						'revision_id'     => [
							'type'        => 'integer',
							'description' => 'Current revision ID after the write.',
						],
						'block_tree'      => [
							'type'        => 'array',
							'description' => 'The resulting full block tree.',
						],
						'error'           => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_insert_pattern' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
				],
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
	 * @param array<string,mixed> $input Input with optional category, search, tier, per_page, page.
	 * @return array<string,mixed> Result with block_types, total, and categories.
	 */
	public static function handle_list_block_types( array $input ): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$all      = $registry->get_all_registered();

		$category = $input['category'] ?? '';
		// @phpstan-ignore-next-line
		$search = strtolower( $input['search'] ?? '' );
		// @phpstan-ignore-next-line
		$tier = $input['tier'] ?? '';
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

			// Get tier and score for this block.
			$score      = BlockContentPolicy::get_namespace_score( $name );
			$block_tier = BlockContentPolicy::score_to_tier( $score );

			// Filter by tier if specified.
			if ( ! empty( $tier ) && $block_tier !== $tier ) {
				continue;
			}

			// Get suggested replacement if block is in legacy or avoid tier.
			$suggested_replacement = null;
			if ( in_array( $block_tier, [ 'legacy', 'avoid' ], true ) ) {
				$suggested_replacement = BlockContentPolicy::get_replacement( $name );
			}

			$filtered[] = [
				'name'                  => $name,
				'title'                 => $block->title ?? '',
				'description'           => $block->description ?? '',
				'category'              => $block->category ?? '',
				'keywords'              => $block->keywords ?? [],
				'score'                 => $score,
				'tier'                  => $block_tier,
				'suggested_replacement' => $suggested_replacement,
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
		$blocks    = $input['blocks'] ?? [];
		$is_update = ! empty( $input['is_update'] );

		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return new \WP_Error( 'missing_blocks', 'blocks array is required.' );
		}

		$output       = '';
		$block_count  = 0;
		$all_warnings = [];

		foreach ( $blocks as $block_data ) {
			$block_name = (string) ( $block_data['blockName'] ?? '' );

			// Apply tier-based block preference policy before serialising.
			if ( '' !== $block_name ) {
				$policy_result = BlockContentPolicy::check_insert( $block_name, $is_update );

				if ( $policy_result instanceof \WP_Error ) {
					// Legacy-tier block: reject the entire request.
					return $policy_result;
				}

				if ( is_array( $policy_result ) && ! empty( $policy_result['warnings'] ) ) {
					// Avoid-tier block: collect warnings but continue.
					$all_warnings = array_merge( $all_warnings, $policy_result['warnings'] );
				}
			}

			// @phpstan-ignore-next-line
			$normalized = self::normalize_block( $block_data );
			// @phpstan-ignore-next-line
			$output .= serialize_block( $normalized ) . "\n\n";
			++$block_count;
			$block_count += self::count_inner_blocks( $normalized );
		}

		$result = [
			'block_content' => trim( $output ),
			'block_count'   => $block_count,
			'error'         => '',
		];

		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		return $result;
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
		$outline      = isset( $input['outline'] ) ? (bool) $input['outline'] : false;
		$summary_only = isset( $input['summary_only'] ) ? (bool) $input['summary_only'] : false;
		$search       = isset( $input['search'] ) && is_string( $input['search'] ) ? $input['search'] : '';
		$block_name   = isset( $input['block_name'] ) && is_string( $input['block_name'] ) ? $input['block_name'] : '';
		$render       = isset( $input['render'] ) ? (bool) $input['render'] : false;
		$fields       = isset( $input['fields'] ) && is_string( $input['fields'] ) ? $input['fields'] : '';

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
			$response = [
				'blocks'      => [],
				'block_count' => 0,
				'refs_stored' => false,
				'revision_id' => RevisionGuard::current_revision_id( $post_id ),
			];

			if ( $summary_only ) {
				$response['summary'] = [
					'block_counts'    => [],
					'headings'        => [],
					'section_markers' => [],
					'max_depth'       => 0,
				];
			}

			return $response;
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

		// If summary_only, return statistics instead of per-block details.
		if ( $summary_only ) {
			// @phpstan-ignore-next-line
			$summary = self::build_block_summary( $blocks );
			return [
				'blocks'      => [],
				'block_count' => 0,
				'refs_stored' => $refs_stored,
				'revision_id' => RevisionGuard::current_revision_id( $post_id ),
				'summary'     => $summary,
			];
		}

		// Build the flat annotated block list for the response.
		$flat_list  = [];
		$flat_index = 0;
		// @phpstan-ignore-next-line
		self::flatten_blocks_for_response( $blocks, [], $flat_index, $flat_list, $outline, $search, $block_name, $render, $fields );

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
	 * @param bool             $outline     Return only flat_index, path, name, heading_text.
	 * @param string           $search      Filter by text_preview substring (case-insensitive).
	 * @param string           $block_name  Filter by exact block name.
	 * @param bool             $render      Resolve dynamic blocks and shortcodes.
	 * @param string           $fields      Comma-separated allowlist of fields.
	 */
	private static function flatten_blocks_for_response(
		array $blocks,
		array $parent_path,
		int &$flat_index,
		array &$output,
		bool $outline = false,
		string $search = '',
		string $block_name = '',
		bool $render = false,
		string $fields = ''
	): void {
		// Parse the fields allowlist if provided.
		$allowed_fields = [];
		if ( '' !== $fields ) {
			$allowed_fields = array_map( 'trim', explode( ',', $fields ) );
			$allowed_fields = array_flip( $allowed_fields );
		}

		foreach ( $blocks as $local_idx => $block ) {
			// @phpstan-ignore-next-line
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$current_path = array_merge( $parent_path, [ (int) $local_idx ] );

			// @phpstan-ignore-next-line
			$block_name_value = (string) $block['blockName'];

			// Apply block_name filter.
			if ( '' !== $block_name && $block_name !== $block_name_value ) {
				// Still recurse into inner blocks.
				// @phpstan-ignore-next-line
				$inner = $block['innerBlocks'] ?? [];
				if ( ! empty( $inner ) && is_array( $inner ) ) {
					// @phpstan-ignore-next-line
					self::flatten_blocks_for_response( $inner, $current_path, $flat_index, $output, $outline, $search, $block_name, $render, $fields );
				}
				continue;
			}

			// Build the entry.
			$entry = [
				'flat_index' => $flat_index,
				'path'       => $current_path,
				'name'       => $block_name_value,
			];

			// Surface the stable ref.
			// @phpstan-ignore-next-line
			$ref = $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
			if ( is_string( $ref ) && '' !== $ref ) {
				$entry['ref'] = $ref;
			}

			// text_preview: stripped, decoded, truncated inner HTML.
			// @phpstan-ignore-next-line
			$inner_html   = (string) ( $block['innerHTML'] ?? '' );
			$text_preview = '';
			if ( '' !== $inner_html ) {
				$preview = wp_strip_all_tags( $inner_html );
				$preview = html_entity_decode( $preview, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$preview = (string) preg_replace( '/\s+/', ' ', trim( $preview ) );
				if ( '' !== $preview ) {
					$text_preview = mb_substr( $preview, 0, 100 );
				}
			}

			// Apply search filter.
			if ( '' !== $search ) {
				if ( false === stripos( $text_preview, $search ) ) {
					// Still recurse into inner blocks.
					// @phpstan-ignore-next-line
					$inner = $block['innerBlocks'] ?? [];
					if ( ! empty( $inner ) && is_array( $inner ) ) {
						// @phpstan-ignore-next-line
						self::flatten_blocks_for_response( $inner, $current_path, $flat_index, $output, $outline, $search, $block_name, $render, $fields );
					}
					continue;
				}
			}

			// Add text_preview if not in outline mode.
			if ( ! $outline && '' !== $text_preview ) {
				$entry['text_preview'] = $text_preview;
			}

			// Add attributes if not in outline mode.
			if ( ! $outline ) {
				// @phpstan-ignore-next-line
				$entry['attributes'] = $block['attrs'] ?? [];
			}

			// Surface Block Bindings API data (read side).
			// @phpstan-ignore-next-line
			$attrs_arr = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
			$meta_arr  = isset( $attrs_arr['metadata'] ) && is_array( $attrs_arr['metadata'] ) ? $attrs_arr['metadata'] : [];
			$bindings  = isset( $meta_arr['bindings'] ) && is_array( $meta_arr['bindings'] ) ? $meta_arr['bindings'] : [];
			if ( ! empty( $bindings ) ) {
				$entry['bindings']         = $bindings;
				$entry['bound_attributes'] = array_keys( $bindings );
			}

			// For outline mode, add heading_text if this is a heading block.
			if ( $outline && 'core/heading' === $block_name_value ) {
				// Use text_preview (stripped inner HTML) as heading_text.
				if ( '' !== $text_preview ) {
					$entry['heading_text'] = $text_preview;
				}
			}

			++$flat_index;

			// Recurse into inner blocks (depth is naturally capped by PHP stack + MAX_DEPTH).
			// @phpstan-ignore-next-line
			$inner = $block['innerBlocks'] ?? [];
			if ( ! empty( $inner ) && is_array( $inner ) ) {
				$inner_output = [];
				// @phpstan-ignore-next-line
				self::flatten_blocks_for_response( $inner, $current_path, $flat_index, $inner_output, $outline, $search, $block_name, $render, $fields );
				if ( ! empty( $inner_output ) && ! $outline ) {
					$entry['innerBlocks'] = $inner_output;
				}
			}

			// Apply fields allowlist if provided.
			if ( ! empty( $allowed_fields ) ) {
				$entry = array_intersect_key( $entry, $allowed_fields );
			}

			$output[] = $entry;
		}
	}

	/**
	 * Build a summary of block statistics (counts, headings, max depth).
	 *
	 * Returns an associative array with:
	 * - block_counts: histogram of block names to counts
	 * - headings: list of heading blocks with level, text, and path
	 * - section_markers: list of section/group markers
	 * - max_depth: maximum nesting depth
	 *
	 * @param array<int,mixed> $blocks Parsed blocks at the current level.
	 * @param int              $depth  Current nesting depth.
	 * @param int[]            $parent_path Index path to the parent.
	 * @return array<string,mixed> Summary data.
	 */
	private static function build_block_summary(
		array $blocks,
		int $depth = 0,
		array $parent_path = []
	): array {
		/** @var array<string,int> $block_counts */
		static $block_counts = [];
		/** @var array<int,array<string,mixed>> $headings */
		static $headings = [];
		/** @var array<int,array<string,mixed>> $section_markers */
		static $section_markers = [];
		/** @var int $max_depth */
		static $max_depth = 0;

		// Initialize on first call.
		if ( 0 === $depth ) {
			$block_counts    = [];
			$headings        = [];
			$section_markers = [];
			$max_depth       = 0;
		}

		// Track max depth.
		if ( $depth > $max_depth ) {
			$max_depth = $depth;
		}

		foreach ( $blocks as $local_idx => $block ) {
			// @phpstan-ignore-next-line
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$current_path = array_merge( $parent_path, [ (int) $local_idx ] );

			// @phpstan-ignore-next-line
			$block_name = (string) $block['blockName'];

			// Count block types.
			if ( ! isset( $block_counts[ $block_name ] ) ) {
				$block_counts[ $block_name ] = 0;
			}
			++$block_counts[ $block_name ];

			// Extract heading information from innerHTML.
			if ( 'core/heading' === $block_name ) {
				// @phpstan-ignore-next-line
				$level = (int) ( $block['attrs']['level'] ?? 2 );
				// @phpstan-ignore-next-line
				$inner_html = (string) ( $block['innerHTML'] ?? '' );
				if ( '' !== $inner_html ) {
					$text = wp_strip_all_tags( $inner_html );
					$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$text = (string) preg_replace( '/\s+/', ' ', trim( $text ) );
					if ( '' !== $text ) {
						$headings[] = [
							'level' => $level,
							'text'  => $text,
							'path'  => $current_path,
						];
					}
				}
			}

			// Track section/group markers.
			if ( 'core/group' === $block_name || 'core/columns' === $block_name ) {
				$section_markers[] = [
					'type' => $block_name,
					'path' => $current_path,
				];
			}

			// Recurse into inner blocks.
			// @phpstan-ignore-next-line
			$inner = $block['innerBlocks'] ?? [];
			if ( ! empty( $inner ) && is_array( $inner ) ) {
				/** @var array<int,mixed> $inner */
				self::build_block_summary( $inner, $depth + 1, $current_path );
			}
		}

		// Return on final call (depth 0).
		if ( 0 === $depth ) {
			return [
				'block_counts'    => $block_counts,
				'headings'        => $headings,
				'section_markers' => $section_markers,
				'max_depth'       => $max_depth,
			];
		}

		return [];
	}

	// ─── edit-block-tree handler ───────────────────────────────────

	/**
	 * Handle the sd-ai-agent/edit-block-tree ability.
	 *
	 * Loads the post's block tree, applies the requested mutation via
	 * BlockMutator::apply(), and (unless dry_run) persists the result
	 * directly to the DB without creating a revision.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error Result array or WP_Error.
	 */

	// ─── Tier policy helpers ──────────────────────────────────────────

	/**
	 * Pre-flight check: enforce tier policy on all insert/replace/wrap operations in a batch.
	 *
	 * Walks through the updates array and validates any insert-child, replace-block,
	 * or wrap-in-group operations against BlockContentPolicy. Returns null on success
	 * (all operations pass policy), or a WP_Error on the first violation.
	 *
	 * This is called before apply_batch() to ensure all-or-nothing rejection.
	 *
	 * @param array<mixed,mixed> $updates Array of update specs from handle_update_blocks.
	 * @return null|\WP_Error null on success, WP_Error on first policy violation.
	 */
	private static function preflight_tier_policy( array $updates ): ?\WP_Error {
		foreach ( $updates as $idx => $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}

			$op = isset( $update['op'] ) && is_string( $update['op'] ) ? $update['op'] : '';

			// Only check insert/replace/wrap operations.
			if ( ! in_array( $op, [ 'insert-child', 'replace-block', 'wrap-in-group' ], true ) ) {
				continue;
			}

			// Get the block_def (insert-child and replace-block have it).
			$block_def = isset( $update['block_def'] ) && is_array( $update['block_def'] ) ? $update['block_def'] : null;

			if ( null === $block_def ) {
				// wrap-in-group doesn't have block_def, so skip it (it wraps existing blocks).
				continue;
			}

			// Recursively check the block_def and its innerBlocks.
			$policy_result = self::check_block_def_policy( $block_def, false );

			if ( is_wp_error( $policy_result ) ) {
				// Return the error immediately — all-or-nothing rejection.
				return $policy_result;
			}
		}

		return null;
	}

	/**
	 * Recursively check a block definition against tier policy.
	 *
	 * @param array<string,mixed> $block_def Block definition.
	 * @param bool                $is_update  True when updating (legacy allowed).
	 * @return null|\WP_Error null on success, WP_Error on first violation.
	 */
	private static function check_block_def_policy( array $block_def, bool $is_update ): ?\WP_Error {
		$block_name = isset( $block_def['blockName'] ) && is_string( $block_def['blockName'] ) ? $block_def['blockName'] : '';

		if ( '' !== $block_name ) {
			$policy_result = BlockContentPolicy::check_insert( $block_name, $is_update );

			if ( is_wp_error( $policy_result ) ) {
				return $policy_result;
			}
		}

		// Recurse into innerBlocks.
		$inner = isset( $block_def['innerBlocks'] ) && is_array( $block_def['innerBlocks'] ) ? $block_def['innerBlocks'] : [];

		foreach ( $inner as $child ) {
			if ( is_array( $child ) ) {
				$child_result = self::check_block_def_policy( $child, $is_update );

				if ( is_wp_error( $child_result ) ) {
					return $child_result;
				}
			}
		}

		return null;
	}

	/**
	 * Handle the sd-ai-agent/edit-block-tree ability.
	 *
	 * Loads the post's block tree, applies the requested mutation via
	 * BlockMutator::apply(), and (unless dry_run) persists the result
	 * directly to the DB without creating a revision.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error Result array or WP_Error.
	 */
	public static function handle_edit_block_tree( array $input ) {
		global $wpdb;

		$post_id = (int) ( $input['post_id'] ?? 0 );
		$op      = isset( $input['op'] ) && is_string( $input['op'] ) ? $input['op'] : '';
		$dry_run = isset( $input['dry_run'] ) ? (bool) $input['dry_run'] : false;

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required and must be a positive integer.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		if ( '' === $op ) {
			return new \WP_Error(
				'missing_op',
				__( 'op (operation) is required.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
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
				),
				[ 'status' => 404 ]
			);
		}

		$content = $post->post_content;
		$blocks  = is_string( $content ) ? parse_blocks( $content ) : [];

		if ( ! is_array( $blocks ) ) {
			$blocks = [];
		}

		// Apply mutation.
		// @phpstan-ignore-next-line
		$result = BlockMutator::apply( $blocks, $op, $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_tree = $result;

		// Persist (unless dry_run).
		if ( ! $dry_run ) {
			// @phpstan-ignore-next-line
			$new_content = serialize_blocks( $new_tree );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$wpdb->posts,
				[ 'post_content' => $new_content ],
				[ 'ID' => $post_id ],
				[ '%s' ],
				[ '%d' ]
			);

			if ( false === $updated ) {
				return new \WP_Error(
					'db_write_failed',
					__( 'Failed to persist the mutated block tree to the database.', 'superdav-ai-agent' ),
					[ 'status' => 500 ]
				);
			}

			clean_post_cache( $post_id );
		}

		return [
			'success'    => true,
			'dry_run'    => $dry_run,
			'op'         => $op,
			'post_id'    => $post_id,
			'block_tree' => $new_tree,
		];
	}

	// ─── update-blocks (batch) handler ────────────────────────────

	/**
	 * Handle the sd-ai-agent/update-blocks ability.
	 *
	 * Applies up to 50 independent block updates atomically inside one
	 * WordPress revision, with all-or-nothing pre-flight validation via
	 * BlockMutator::apply_batch().
	 *
	 * On success, the post_content is updated via wp_update_post() so
	 * exactly one revision is created regardless of the number of updates.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error Result or error.
	 */
	public static function handle_update_blocks( array $input ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$updates = $input['updates'] ?? [];
		$dry_run = isset( $input['dry_run'] ) ? (bool) $input['dry_run'] : false;

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required and must be a positive integer.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! is_array( $updates ) ) {
			return new \WP_Error(
				'invalid_updates',
				__( 'updates must be an array.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
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
				),
				[ 'status' => 404 ]
			);
		}

		// Optimistic concurrency guard.
		$expected = isset( $input['expected_revision'] ) ? (string) $input['expected_revision'] : '';
		$guard    = RevisionGuard::check( $post_id, RevisionGuard::parse_raw( $expected ) );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$content = $post->post_content;
		$blocks  = is_string( $content ) ? parse_blocks( $content ) : [];

		if ( ! is_array( $blocks ) ) {
			$blocks = [];
		}

		// Pre-flight: enforce tier policy on all insert/replace/wrap operations.
		// This ensures all-or-nothing rejection before any mutations.
		$policy_errors = self::preflight_tier_policy( $updates );
		if ( is_wp_error( $policy_errors ) ) {
			return $policy_errors;
		}

		// All-or-nothing batch validation + mutation.
		// @phpstan-ignore-next-line
		$result = BlockMutator::apply_batch( $blocks, $updates );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_tree = $result;

		// Persist with wp_update_post() → exactly one revision.
		if ( ! $dry_run ) {
			// @phpstan-ignore-next-line
			$new_content   = serialize_blocks( $new_tree );
			$update_result = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $new_content,
				],
				true
			);

			if ( is_wp_error( $update_result ) ) {
				return $update_result;
			}
		}

		return [
			'success'     => true,
			'dry_run'     => $dry_run,
			'post_id'     => $post_id,
			'updates'     => count( $updates ),
			'revision_id' => RevisionGuard::current_revision_id( $post_id ),
			'block_tree'  => $new_tree,
		];
	}

	// ─── insert-pattern handler ───────────────────────────────────

	/**
	 * Valid anchor modes for insert-pattern.
	 *
	 * @var string[]
	 */
	private const VALID_ANCHORS = [
		'after_top_level',
		'before_top_level',
		'after_ref',
		'before_ref',
		'first_child_of_ref',
	];

	/**
	 * Handle the sd-ai-agent/insert-pattern ability.
	 *
	 * Inserts a registered pattern (inline expansion) or synced pattern
	 * (core/block reference) at the specified anchor position. Assigns
	 * stable sd_ref UUIDs to all inserted blocks.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error Result array or WP_Error.
	 */
	public static function handle_insert_pattern( array $input ) {
		global $wpdb;

		$post_id = (int) ( $input['post_id'] ?? 0 );
		$pattern = $input['pattern'] ?? null;
		$anchor  = isset( $input['anchor'] ) && is_string( $input['anchor'] ) ? $input['anchor'] : 'after_top_level';
		$ref     = isset( $input['ref'] ) && is_string( $input['ref'] ) ? $input['ref'] : '';
		$dry_run = isset( $input['dry_run'] ) ? (bool) $input['dry_run'] : false;

		// ── Validate inputs ───────────────────────────────────────────

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required and must be a positive integer.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		if ( null === $pattern || ( is_string( $pattern ) && '' === $pattern ) ) {
			return new \WP_Error(
				'missing_pattern',
				__( 'pattern is required (slug for registered, numeric ID or "wp-block:N"/"synced:N" for synced).', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! in_array( $anchor, self::VALID_ANCHORS, true ) ) {
			return new \WP_Error(
				'invalid_anchor',
				sprintf(
					/* translators: %s: valid anchor list */
					__( 'Invalid anchor "%1$s". Valid anchors: %2$s.', 'superdav-ai-agent' ),
					$anchor,
					implode( ', ', self::VALID_ANCHORS )
				),
				[ 'status' => 400 ]
			);
		}

		// Ref-based anchors require a ref parameter.
		$ref_required = in_array( $anchor, [ 'after_ref', 'before_ref', 'first_child_of_ref' ], true );
		if ( $ref_required && '' === $ref ) {
			return new \WP_Error(
				'missing_ref',
				sprintf(
					/* translators: %s: anchor name */
					__( 'ref is required when anchor is "%s".', 'superdav-ai-agent' ),
					$anchor
				),
				[ 'status' => 400 ]
			);
		}

		// ── Parse pattern identifier ──────────────────────────────────

		$parsed = PatternInserter::parse_pattern_id( is_int( $pattern ) ? $pattern : (string) $pattern );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$pattern_type = $parsed['type'];
		$pattern_id   = $parsed['id'];

		// ── Validate pattern exists ───────────────────────────────────

		$exists = PatternInserter::validate_pattern_exists( $pattern_type, $pattern_id );
		if ( is_wp_error( $exists ) ) {
			return $exists;
		}

		// ── Load post ─────────────────────────────────────────────────

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: post ID */
					__( 'Post %d not found.', 'superdav-ai-agent' ),
					$post_id
				),
				[ 'status' => 404 ]
			);
		}

		// ── Optimistic concurrency ────────────────────────────────────

		$expected = isset( $input['expected_revision_id'] ) ? (string) $input['expected_revision_id'] : '';
		$guard    = RevisionGuard::check( $post_id, RevisionGuard::parse_raw( $expected ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// ── Resolve pattern to blocks ─────────────────────────────────

		if ( 'synced' === $pattern_type ) {
			// Synced pattern → single core/block reference.
			$new_blocks = [ PatternInserter::make_synced_ref( (int) $pattern_id ) ];
		} else {
			// Registered pattern → inline expansion.
			$expanded = PatternInserter::expand_registered( (string) $pattern_id );
			if ( is_wp_error( $expanded ) ) {
				return $expanded;
			}

			// Enforce tier policy on expanded blocks before insertion.
			foreach ( $expanded as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$policy_error = self::check_block_def_policy( $block, false );
				if ( is_wp_error( $policy_error ) ) {
					return $policy_error;
				}
			}

			$new_blocks = $expanded;
		}

		// ── Parse post block tree ─────────────────────────────────────

		$content = $post->post_content;
		$blocks  = is_string( $content ) ? parse_blocks( $content ) : [];
		if ( ! is_array( $blocks ) ) {
			$blocks = [];
		}

		// ── Insert at anchor position ─────────────────────────────────

		$blocks_inserted = count( $new_blocks );

		switch ( $anchor ) {
			case 'after_top_level':
				// Append to root level.
				foreach ( $new_blocks as $nb ) {
					$blocks[] = $nb;
				}
				break;

			case 'before_top_level':
				// Prepend to root level.
				array_splice( $blocks, 0, 0, $new_blocks );
				break;

			case 'after_ref':
			case 'before_ref':
				$path = BlockTreeAddress::resolve( $blocks, [ 'ref' => $ref ] );
				if ( is_wp_error( $path ) ) {
					return $path;
				}
				$position = ( 'before_ref' === $anchor ) ? 'before' : 'after';
				$result   = BlockMutator::insert_blocks_as_siblings( $blocks, $path, $new_blocks, $position );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$blocks = $result;
				break;

			case 'first_child_of_ref':
				$path = BlockTreeAddress::resolve( $blocks, [ 'ref' => $ref ] );
				if ( is_wp_error( $path ) ) {
					return $path;
				}

				// Verify the target is a container block (has or can hold innerBlocks).
				$target = BlockTreeAddress::get_block_at_path( $blocks, $path );
				if ( null === $target || ! is_array( $target ) ) {
					return new \WP_Error(
						'block_not_found',
						sprintf( 'Block with ref "%s" not found.', $ref ),
						[ 'status' => 404 ]
					);
				}

				// Check if the block can accept innerBlocks.
				$target_name = isset( $target['blockName'] ) && is_string( $target['blockName'] ) ? $target['blockName'] : '';
				$has_inner   = isset( $target['innerBlocks'] ) && is_array( $target['innerBlocks'] );

				// Blocks without any innerBlocks and that aren't containers should be rejected.
				if ( ! $has_inner && '' !== $target_name ) {
					// Check WordPress block type registry to see if the block supports innerBlocks.
					$block_type  = \WP_Block_Type_Registry::get_instance()->get_registered( $target_name );
					$can_contain = false;

					if ( $block_type ) {
						// Blocks with parent property or uses_context usually can't contain innerBlocks.
						// Blocks like core/group, core/columns, core/column, core/cover can.
						// If the block type doesn't explicitly block innerBlocks, allow it.
						$can_contain = empty( $block_type->parent );
					}

					if ( ! $can_contain ) {
						return new \WP_Error(
							'not_a_container',
							sprintf(
								'Block "%s" (ref: %s) cannot accept inner blocks.',
								$target_name,
								$ref
							),
							[ 'status' => 400 ]
						);
					}
				}

				$result = BlockMutator::insert_blocks_as_children( $blocks, $path, $new_blocks, 0 );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$blocks = $result;
				break;
		}

		// ── Assign refs to all blocks ─────────────────────────────────

		// @phpstan-ignore-next-line
		$ref_result = BlockReferences::assign_refs( $blocks );
		if ( is_wp_error( $ref_result ) ) {
			return $ref_result;
		}
		$blocks = $ref_result;

		// ── Validate tree depth ───────────────────────────────────────

		$depth_check = BlockMutator::validate_tree_depth( $blocks );
		if ( is_wp_error( $depth_check ) ) {
			return $depth_check;
		}

		// ── Persist (unless dry_run) ──────────────────────────────────

		if ( ! $dry_run ) {
			// @phpstan-ignore-next-line
			$new_content   = serialize_blocks( $blocks );
			$update_result = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $new_content,
				],
				true
			);

			if ( is_wp_error( $update_result ) ) {
				return $update_result;
			}
		}

		return [
			'success'         => true,
			'dry_run'         => $dry_run,
			'post_id'         => $post_id,
			'pattern_type'    => $pattern_type,
			'blocks_inserted' => $blocks_inserted,
			'revision_id'     => RevisionGuard::current_revision_id( $post_id ),
			'block_tree'      => $blocks,
		];
	}
}

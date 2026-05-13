<?php

declare(strict_types=1);
/**
 * Functional benchmark suite definitions.
 *
 * Each question drives the full AI agent loop against a live WordPress
 * environment.  Scoring is based entirely on what the agent actually did —
 * did it create a working plugin, register the right endpoint, create the
 * right table — not on keyword matching against text output.
 *
 * Question shape:
 *   id          — unique identifier (e.g. fn-001)
 *   category    — grouping label for reporting
 *   prompt      — the user message sent verbatim to the agent
 *   max_turns   — agent iteration cap for this question
 *   assertions  — list of AssertionEngine checks run after the agent finishes
 *
 * @package SdAiAgent\Benchmark
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Benchmark;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BenchmarkSuite {

	/**
	 * List all available suites.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_suites(): array {
		return array(
			array(
				'slug'           => 'content-v1',
				'name'           => 'Agent Content v1',
				'description'    => 'Tests the agent\'s ability to create real WordPress content — pages, posts, WooCommerce products, and content analysis — verified by querying live WordPress state.',
				'question_count' => count( self::get_content_questions() ),
			),
			array(
				'slug'           => 'functional-v1',
				'name'           => 'Functional Agent v1',
				'description'    => 'End-to-end agent loop tests. The agent must produce working WordPress plugins, endpoints, and features — verified against live WordPress state, not keyword matching.',
				'question_count' => count( self::get_functional_questions() ),
			),
			array(
				'slug'           => 'abilities-content-v1',
				'name'           => 'Abilities Coverage — Content',
				'description'    => 'Targets every content/post ability via a real prompt — create, get, update, list, delete, batch, set-featured, generate-title/excerpt, summarise, content-analyze, content-search, content-performance-report.',
				'question_count' => count( self::get_abilities_content_questions() ),
			),
			array(
				'slug'           => 'abilities-media-v1',
				'name'           => 'Abilities Coverage — Media',
				'description'    => 'Targets media abilities: list/upload-from-url/delete, generate-alt-text, import-base64-image, generate-image, generate-image-prompt, stock-image.',
				'question_count' => count( self::get_abilities_media_questions() ),
			),
			array(
				'slug'           => 'abilities-structure-v1',
				'name'           => 'Abilities Coverage — Taxonomies, CPTs, Menus, Users',
				'description'    => 'Targets register/list/delete-post-type & taxonomy, all menu abilities, and user abilities.',
				'question_count' => count( self::get_abilities_structure_questions() ),
			),
			array(
				'slug'           => 'abilities-design-v1',
				'name'           => 'Abilities Coverage — Design & Blocks',
				'description'    => 'Targets block abilities, global styles, theme.json presets, curated patterns, inject-custom-css, set-site-logo, markdown-to-blocks, review-block.',
				'question_count' => count( self::get_abilities_design_questions() ),
			),
			array(
				'slug'           => 'abilities-developer-v1',
				'name'           => 'Abilities Coverage — Developer',
				'description'    => 'Targets file abilities, db-query, options, run-php, scan-plugin-hooks, scan-theme-hooks, scan-php-error-log.',
				'question_count' => count( self::get_abilities_developer_questions() ),
			),
			array(
				'slug'           => 'abilities-plugin-mgmt-v1',
				'name'           => 'Abilities Coverage — Plugin Management',
				'description'    => 'Targets get-plugins/themes, install/update/activate/deactivate/delete/switch-plugin, install-from-url, search-directory, generate-plugin + sandbox lifecycle.',
				'question_count' => count( self::get_abilities_plugin_mgmt_questions() ),
			),
			array(
				'slug'           => 'abilities-utility-v1',
				'name'           => 'Abilities Coverage — Utility',
				'description'    => 'Targets site-health, navigate, get-page-html, internet-search, fetch-url, analyze-headers, knowledge-search, seo-* , memory-* , skill-* , git-* , report-inability.',
				'question_count' => count( self::get_abilities_utility_questions() ),
			),
			array(
				'slug'           => 'abilities-credentialed-v1',
				'name'           => 'Abilities Coverage — Credentialed (GA/GSC)',
				'description'    => 'Exercises Google Analytics and Search Console abilities. Without live credentials these test graceful degradation rather than real data.',
				'question_count' => count( self::get_abilities_credentialed_questions() ),
			),
		);
	}

	/**
	 * Get a suite by slug with its full question list.
	 *
	 * @param string $slug Suite slug.
	 * @return array{slug: string, name: string, description: string, questions: array<int, array<string, mixed>>}|null
	 */
	public static function get_suite( string $slug ): ?array {
		switch ( $slug ) {
			case 'content-v1':
				return array(
					'slug'        => 'content-v1',
					'name'        => 'Agent Content v1',
					'description' => 'Tests the agent\'s ability to create real WordPress content verified against live WordPress state.',
					'questions'   => self::get_content_questions(),
				);

			case 'functional-v1':
				return array(
					'slug'        => 'functional-v1',
					'name'        => 'Functional Agent v1',
					'description' => 'End-to-end agent loop tests verified against live WordPress state.',
					'questions'   => self::get_functional_questions(),
				);

			case 'abilities-content-v1':
				return array(
					'slug'        => 'abilities-content-v1',
					'name'        => 'Abilities Coverage — Content',
					'description' => 'Content/post ability coverage.',
					'questions'   => self::get_abilities_content_questions(),
				);

			case 'abilities-media-v1':
				return array(
					'slug'        => 'abilities-media-v1',
					'name'        => 'Abilities Coverage — Media',
					'description' => 'Media ability coverage.',
					'questions'   => self::get_abilities_media_questions(),
				);

			case 'abilities-structure-v1':
				return array(
					'slug'        => 'abilities-structure-v1',
					'name'        => 'Abilities Coverage — Structure',
					'description' => 'Taxonomy/CPT/menu/user ability coverage.',
					'questions'   => self::get_abilities_structure_questions(),
				);

			case 'abilities-design-v1':
				return array(
					'slug'        => 'abilities-design-v1',
					'name'        => 'Abilities Coverage — Design',
					'description' => 'Block/global-styles/design ability coverage.',
					'questions'   => self::get_abilities_design_questions(),
				);

			case 'abilities-developer-v1':
				return array(
					'slug'        => 'abilities-developer-v1',
					'name'        => 'Abilities Coverage — Developer',
					'description' => 'File/db/option/php/scan ability coverage.',
					'questions'   => self::get_abilities_developer_questions(),
				);

			case 'abilities-plugin-mgmt-v1':
				return array(
					'slug'        => 'abilities-plugin-mgmt-v1',
					'name'        => 'Abilities Coverage — Plugin Management',
					'description' => 'Plugin install/activate/sandbox ability coverage.',
					'questions'   => self::get_abilities_plugin_mgmt_questions(),
				);

			case 'abilities-utility-v1':
				return array(
					'slug'        => 'abilities-utility-v1',
					'name'        => 'Abilities Coverage — Utility',
					'description' => 'Site-health/seo/memory/skill/git/utility ability coverage.',
					'questions'   => self::get_abilities_utility_questions(),
				);

			case 'abilities-credentialed-v1':
				return array(
					'slug'        => 'abilities-credentialed-v1',
					'name'        => 'Abilities Coverage — Credentialed',
					'description' => 'GA/GSC ability coverage (graceful degradation).',
					'questions'   => self::get_abilities_credentialed_questions(),
				);

			default:
				return null;
		}
	}

	/**
	 * Get questions for a suite by slug.
	 *
	 * @param string $slug Suite slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_questions( string $slug ): array {
		$suite     = self::get_suite( $slug );
		$questions = is_array( $suite ) && isset( $suite['questions'] ) && is_array( $suite['questions'] )
			? $suite['questions']
			: array();
		$out       = array();

		foreach ( $questions as $question ) {
			if ( is_array( $question ) ) {
				$normalized = array();
				foreach ( $question as $key => $value ) {
					if ( is_string( $key ) ) {
						$normalized[ $key ] = $value;
					}
				}
				$out[] = $normalized;
			}
		}

		return $out;
	}

	/**
	 * Content v1 questions.
	 *
	 * Each question asks the agent to create real WordPress content using its
	 * built-in tools (create-post, woo-create-product, content-analyze, etc.).
	 * Assertions query live WordPress state — post titles, WooCommerce products,
	 * published pages — rather than inspecting the agent's text output.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_content_questions(): array {
		return array(

			// ── Pages ─────────────────────────────────────────────────────────

			array(
				'id'         => 'ct-001',
				'category'   => 'pages',
				'max_turns'  => 12,
				'prompt'     => 'Create a landing page for our new product "CloudSync Pro" — a cloud file synchronization tool for teams. Include a hero section with headline and subheadline, a features section with 3 key features (real-time sync, end-to-end encryption, team collaboration), a pricing section with 3 tiers (Free, Pro at $9/mo, Enterprise at $29/mo), and a call-to-action. Publish it immediately.',
				'assertions' => array(
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=page --post_status=publish --fields=post_title --format=csv --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'CloudSync Pro',
						'expected_exit_code'      => 0,
						'description'             => 'Page "CloudSync Pro" is published',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=page --post_status=publish --fields=post_title,post_content --format=json --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'Free|Pro|\$9|pricing|feature',
						'expected_exit_code'      => 0,
						'description'             => 'Page content includes pricing and features sections',
					),
				),
			),

			array(
				'id'         => 'ct-002',
				'category'   => 'pages',
				'max_turns'  => 12,
				'prompt'     => 'Create an About Us page for "Acme Digital Solutions", a web development agency founded in 2020. We have 15 team members, are based in Austin TX, and specialize in WordPress and React development. Include our mission statement, a team overview section, and a contact information section.',
				'assertions' => array(
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=page --fields=post_title,post_status --format=csv --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'Acme|About',
						'expected_exit_code'      => 0,
						'description'             => 'About Us page exists',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=page --fields=post_title,post_content --format=json --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'Austin|2020|mission|team|contact',
						'expected_exit_code'      => 0,
						'description'             => 'Page content includes company details, mission, team and contact sections',
					),
				),
			),

			// ── Blog Posts ────────────────────────────────────────────────────

			array(
				'id'         => 'ct-003',
				'category'   => 'posts',
				'max_turns'  => 12,
				'prompt'     => 'Write an SEO-optimized blog post about "10 Best Practices for Remote Team Management in 2026". Optimize it for the keyword "remote team management". Include proper headings, a meta description in the excerpt field, and assign it to the "Management" category with tags "remote work", "team management", and "productivity". Save it as a draft.',
				'assertions' => array(
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=post --post_status=draft --fields=post_title --format=csv --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'Remote Team|remote team|Best Practices',
						'expected_exit_code'      => 0,
						'description'             => 'Blog post draft exists with correct title',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=post --post_status=draft --fields=post_title,post_excerpt --format=json --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'remote.team|management',
						'expected_exit_code'      => 0,
						'description'             => 'Post has an excerpt (meta description)',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'term list post_tag --fields=name --format=csv --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'remote work|team management|productivity',
						'expected_exit_code'      => 0,
						'description'             => 'Tags "remote work", "team management", "productivity" exist',
					),
				),
			),

			array(
				'id'         => 'ct-004',
				'category'   => 'posts',
				'max_turns'  => 15,
				'prompt'     => 'Create a blog post about "The Future of AI in Healthcare" and find a relevant stock image of medical technology to use as the featured image. The post should cover three topics: AI in diagnostics, AI in drug discovery, and AI in patient care. Save it as a draft.',
				'assertions' => array(
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=post --post_status=draft --fields=post_title --format=csv --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'AI.*Healthcare|Healthcare.*AI|Future of AI',
						'expected_exit_code'      => 0,
						'description'             => 'Blog post draft about AI in Healthcare exists',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=post --post_status=draft --fields=post_title,post_content --format=json --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'diagnostic|drug.discovery|patient.care',
						'expected_exit_code'      => 0,
						'description'             => 'Post content covers all three required topics',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=post --post_status=draft --fields=post_title,_thumbnail_id --format=json --url=wp-multisite-waas.test',
						'expected_output_pattern' => '_thumbnail_id|thumbnail',
						'expected_exit_code'      => 0,
						'description'             => 'Post has a featured image set',
					),
				),
			),

			// ── WooCommerce ───────────────────────────────────────────────────

			array(
				'id'         => 'ct-005',
				'category'   => 'woocommerce',
				'max_turns'  => 12,
				'prompt'     => 'Create a new WooCommerce product: "Ergonomic Standing Desk" priced at $599.99 (on sale for $449.99). SKU: DESK-ERG-001. Stock: 50 units. Category: "Office Furniture". Write a compelling product description highlighting the adjustable height range (28-48 inches), bamboo desktop surface, integrated cable management, and 10-year warranty. Set it as published.',
				'assertions' => array(
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'wc product list --field=name --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'Ergonomic Standing Desk',
						'expected_exit_code'      => 0,
						'description'             => 'WooCommerce product "Ergonomic Standing Desk" exists',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'wc product list --fields=name,sku,regular_price,sale_price,stock_quantity --format=json --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'DESK-ERG-001',
						'expected_exit_code'      => 0,
						'description'             => 'Product has correct SKU DESK-ERG-001',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'wc product list --fields=name,regular_price,sale_price --format=json --url=wp-multisite-waas.test',
						'expected_output_pattern' => '599|449',
						'expected_exit_code'      => 0,
						'description'             => 'Product has correct regular ($599.99) and sale ($449.99) prices',
					),
				),
			),

			// ── Content Analysis ──────────────────────────────────────────────

			array(
				'id'         => 'ct-006',
				'category'   => 'analysis',
				'max_turns'  => 10,
				'prompt'     => 'Analyze our content strategy. Check what posts we have published recently, look at the content distribution across categories, and identify any content gaps. Give me a structured summary with three sections: Recent Publishing Activity, Category Distribution, and Content Gaps & Recommendations.',
				'assertions' => array(
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_status=publish --fields=post_title,post_date --format=csv --url=wp-multisite-waas.test',
						'expected_output_pattern' => '.',
						'expected_exit_code'      => 0,
						'description'             => 'Agent successfully queried the WordPress post list',
					),
				),
				// This question scores on whether the agent actually used content-analysis
				// tools and produced a structured response — the single assertion just
				// verifies WordPress is reachable; the log file captures the full agent output.
			),

			// ── Plugin Management ─────────────────────────────────────────────

			array(
				'id'         => 'ct-007',
				'category'   => 'plugins',
				'max_turns'  => 12,
				'prompt'     => 'Check what plugins are currently installed on this site, then install the "contact-form-7" plugin from WordPress.org and activate it.',
				'assertions' => array(
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'plugin list --field=name --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'contact-form-7',
						'expected_exit_code'      => 0,
						'description'             => 'contact-form-7 plugin appears in the plugin list',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'plugin list --fields=name,status --format=csv --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'contact-form-7,active',
						'expected_exit_code'      => 0,
						'description'             => 'contact-form-7 plugin is active',
					),
				),
			),

		);
	}

	/**
	 * Functional v1 questions.
	 *
	 * Each question is sent to the real agent loop with all tools available.
	 * The agent is expected to build, write, and activate working code.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_functional_questions(): array {
		return array(

			// ── REST API ──────────────────────────────────────────────────────

			array(
				'id'         => 'fn-001',
				'category'   => 'rest_api',
				'max_turns'  => 20,
				'prompt'     => 'Build me a WordPress plugin called "event-manager" that adds a REST API endpoint at POST /wp-json/event-manager/v1/events. The endpoint should accept "title" (required, string) and "date" (required, string) fields, check that the current user has the publish_posts capability, sanitize both fields, create a new WordPress post of type "event" with the provided title, store the date in post meta as "_event_date", and return JSON with the new post ID. Include proper error handling with WP_Error for missing fields and unauthorized access. Activate the plugin when done.',
				'assertions' => array(
					array(
						'type'        => 'plugin_no_php_errors',
						'description' => 'Plugin PHP files have no syntax errors',
					),
					array(
						'type'        => 'plugin_activates',
						'description' => 'Plugin activates without fatal errors',
					),
					array(
						'type'        => 'post_type_registered',
						'post_type'   => 'event',
						'description' => 'Custom post type "event" is registered',
					),
					array(
						'type'        => 'rest_endpoint_registered',
						'method'      => 'POST',
						'path'        => '/event-manager/v1/events',
						'description' => 'REST endpoint POST /event-manager/v1/events is registered',
					),
					array(
						'type'            => 'rest_endpoint_response',
						'method'          => 'POST',
						'path'            => '/event-manager/v1/events',
						'body'            => array(
							'title' => 'Test Event',
							'date'  => '2026-06-15',
						),
						'expected_status' => array( 401, 403 ),
						'as_user'         => 0,
						'description'     => 'Endpoint rejects unauthenticated requests (401 or 403)',
					),
				),
			),

			// ── Database ──────────────────────────────────────────────────────

			array(
				'id'         => 'fn-002',
				'category'   => 'database',
				'max_turns'  => 20,
				'prompt'     => 'Build a WordPress plugin called "api-logger" that creates a custom database table called "api_logs" (with the WordPress table prefix) on plugin activation. The table must have these columns: id (bigint, auto-increment primary key), user_id (bigint), endpoint (varchar 255), method (varchar 10), status_code (int), response_time_ms (int), created_at (datetime). Use dbDelta() for the table creation and store the installed version in a WordPress option named exactly "api_logger_version". On deactivation, do NOT drop the table — only clear any scheduled events. Activate the plugin when done.',
				'assertions' => array(
					array(
						'type'        => 'plugin_no_php_errors',
						'description' => 'Plugin PHP files have no syntax errors',
					),
					array(
						'type'        => 'plugin_activates',
						'description' => 'Plugin activates without fatal errors',
					),
					array(
						'type'        => 'db_table_exists',
						'table'       => 'api_logs',
						'description' => 'Table {prefix}api_logs exists after activation',
					),
					array(
						'type'        => 'db_table_has_columns',
						'table'       => 'api_logs',
						'columns'     => array( 'id', 'user_id', 'endpoint', 'method', 'status_code', 'response_time_ms', 'created_at' ),
						'description' => 'Table has all 7 required columns',
					),
					array(
						'type'        => 'option_exists',
						'option'      => 'api_logger_version',
						'description' => 'Plugin version stored in WordPress options',
					),
				),
			),

			// ── Shortcode ─────────────────────────────────────────────────────

			array(
				'id'         => 'fn-003',
				'category'   => 'shortcode',
				'max_turns'  => 20,
				'prompt'     => 'Build a WordPress plugin called "post-grid" that registers a shortcode [post_grid]. The shortcode should accept these attributes: category (default empty), columns (default 3, must be 1-4), limit (default 9, max 20). It should query published posts filtered by category slug if provided, render them as a <div class="post-grid post-grid-columns-{N}"> containing article elements each with the post title as an <h2> wrapped in a permalink, and the excerpt. All output must be properly escaped. The plugin must use output buffering to return the shortcode content. Activate the plugin when done.',
				'assertions' => array(
					array(
						'type'        => 'plugin_no_php_errors',
						'description' => 'Plugin PHP files have no syntax errors',
					),
					array(
						'type'        => 'plugin_activates',
						'description' => 'Plugin activates without fatal errors',
					),
					array(
						'type'        => 'shortcode_registered',
						'tag'         => 'post_grid',
						'description' => 'Shortcode [post_grid] is registered',
					),
					array(
						'type'        => 'file_contains',
						'file'        => 'post-grid.php',
						'pattern'     => 'ob_start',
						'description' => 'Plugin uses output buffering',
					),
					array(
						'type'        => 'file_contains',
						'file'        => 'post-grid.php',
						'pattern'     => 'esc_html|esc_attr|esc_url',
						'description' => 'Plugin escapes output',
					),
				),
			),

			// ── WP-CLI ────────────────────────────────────────────────────────

			array(
				'id'         => 'fn-004',
				'category'   => 'wp_cli',
				'max_turns'  => 25,
				'prompt'     => 'Build a WordPress plugin called "meta-migrator" that provides a WP-CLI command "wp meta-migrator run". The command should: accept a --dry-run flag that previews changes without writing them, find all posts that have a meta key "_legacy_price" and copy its value to "_price", process posts in batches of 50 to avoid memory issues, output progress as "Processed X/Y posts", and at the end print a summary "Migration complete: X posts updated". Register the WP-CLI command only when WP_CLI is defined. Activate the plugin when done.',
				'assertions' => array(
					array(
						'type'        => 'plugin_no_php_errors',
						'description' => 'Plugin PHP files have no syntax errors',
					),
					array(
						'type'        => 'plugin_activates',
						'description' => 'Plugin activates without fatal errors',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'meta-migrator run --dry-run --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'dry.run|Migration complete|Processed',
						'expected_exit_code'      => 0,
						'description'             => 'WP-CLI command runs successfully in dry-run mode',
					),
					array(
						'type'        => 'file_contains',
						'file'        => 'meta-migrator.php',
						'pattern'     => 'WP_CLI',
						'description' => 'Plugin checks WP_CLI before registering command',
					),
				),
			),

			// ── Hooks & Filters ───────────────────────────────────────────────

			array(
				'id'         => 'fn-005',
				'category'   => 'hooks',
				'max_turns'  => 20,
				'prompt'     => 'Build a WordPress plugin called "content-tracker" that tracks post views. It should: hook into "wp" to detect single post views and increment a view count stored in post meta as "_view_count", add a filter on "the_content" to append a view count badge at the bottom of single post content (e.g. "👁 42 views"), register a shortcode [view_count post_id=123] that returns the view count for any post, and store the last-viewed timestamp in post meta as "_last_viewed". Make sure it only counts views on the frontend (not in wp-admin). Activate the plugin when done.',
				'assertions' => array(
					array(
						'type'        => 'plugin_no_php_errors',
						'description' => 'Plugin PHP files have no syntax errors',
					),
					array(
						'type'        => 'plugin_activates',
						'description' => 'Plugin activates without fatal errors',
					),
					array(
						'type'             => 'hook_registered',
						'hook'             => 'wp',
						'callback_pattern' => 'view|track|count',
						'description'      => 'Hook on "wp" registered for view tracking',
					),
					array(
						'type'             => 'hook_registered',
						'hook'             => 'the_content',
						'callback_pattern' => 'view|badge|content|append',
						'description'      => 'Filter on "the_content" registered for badge injection',
					),
					array(
						'type'        => 'shortcode_registered',
						'tag'         => 'view_count',
						'description' => 'Shortcode [view_count] is registered',
					),
				),
			),

			// ── Settings & Options ────────────────────────────────────────────

			array(
				'id'         => 'fn-006',
				'category'   => 'settings',
				'max_turns'  => 20,
				'prompt'     => 'Build a WordPress plugin called "site-announcements" that lets admins create site-wide announcement banners. It should: add a Settings > Site Announcements admin page where admins can set a banner message (text), banner color (hex color, default #0073aa), and whether the banner is active (checkbox). Store settings under the option key "site_announcements_settings" as an array. Hook into "wp_footer" to output the banner HTML when active (a <div id="site-announcement-banner"> with inline background-color style and the message). Register a settings section and fields using the WordPress Settings API. Activate the plugin when done.',
				'assertions' => array(
					array(
						'type'        => 'plugin_no_php_errors',
						'description' => 'Plugin PHP files have no syntax errors',
					),
					array(
						'type'        => 'plugin_activates',
						'description' => 'Plugin activates without fatal errors',
					),
					array(
						'type'             => 'hook_registered',
						'hook'             => 'wp_footer',
						'callback_pattern' => 'banner|announcement|output|render',
						'description'      => 'Hook on "wp_footer" for banner output',
					),
					array(
						'type'        => 'file_contains',
						'file'        => 'site-announcements.php',
						'pattern'     => 'register_setting|add_settings',
						'description' => 'Plugin uses WordPress Settings API',
					),
					array(
						'type'        => 'file_contains',
						'file'        => 'site-announcements.php',
						'pattern'     => 'site_announcements_settings',
						'description' => 'Plugin uses the correct option key',
					),
				),
			),

			// ── WooCommerce ───────────────────────────────────────────────────

			array(
				'id'         => 'fn-007',
				'category'   => 'woocommerce',
				'max_turns'  => 20,
				'prompt'     => 'Create a new WooCommerce product called "Ergonomic Standing Desk" with a regular price of $599.99, sale price of $449.99, SKU "DESK-ERG-001", stock quantity of 50, category "Office Furniture". Write a compelling description that mentions the adjustable height range (28-48 inches), bamboo desktop surface, integrated cable management, and 10-year warranty. Set the product status to published.',
				'assertions' => array(
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'wc product list --field=name --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'Ergonomic Standing Desk',
						'expected_exit_code'      => 0,
						'description'             => 'Product "Ergonomic Standing Desk" exists in WooCommerce',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'wc product list --field=sku --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'DESK-ERG-001',
						'expected_exit_code'      => 0,
						'description'             => 'Product has SKU DESK-ERG-001',
					),
				),
			),

			// ── Content Creation ──────────────────────────────────────────────

			array(
				'id'         => 'fn-008',
				'category'   => 'content',
				'max_turns'  => 15,
				'prompt'     => 'Create a published WordPress page called "CloudSync Pro" as a product landing page. It must include: a hero section with the headline "Sync Your Team\'s Files, Instantly" and a subheadline about cloud synchronization for teams, a features section covering real-time sync, end-to-end encryption, and team collaboration (each as its own heading), a pricing section with three tiers — Free ($0/mo), Pro ($9/mo), and Enterprise ($29/mo) — each with a description, and a call-to-action section with a "Get Started Free" button linking to "#signup". The page should use proper heading hierarchy.',
				'assertions' => array(
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=page --post_status=publish --field=post_title --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'CloudSync Pro',
						'expected_exit_code'      => 0,
						'description'             => 'Page "CloudSync Pro" is published',
					),
					array(
						'type'                    => 'wp_cli_command',
						'command'                 => 'post list --post_type=page --post_status=publish --fields=post_title,post_content --format=json --url=wp-multisite-waas.test',
						'expected_output_pattern' => 'CloudSync|pricing|feature|Free|Pro|Enterprise',
						'expected_exit_code'      => 0,
						'description'             => 'Page content includes required sections',
					),
				),
			),

		);
	}

	// ── Abilities Coverage Suites ─────────────────────────────────────────────
	//
	// Each question below targets one or more specific abilities by name
	// (`tool_called` assertion) plus a state assertion where there is a clean
	// side-effect to verify. The goal is breadth, not depth — exercise every
	// ability with a realistic prompt so we surface bugs in their wiring.

	/**
	 * Content abilities — posts, titles, excerpts, summaries, content analysis.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_abilities_content_questions(): array {
		return array(
			array(
				'id'         => 'ac-001',
				'category'   => 'posts',
				'max_turns'  => 8,
				'prompt'     => 'Create a new draft blog post titled "Test Coverage Post AC-001" with a short body about automated testing. Use the create-post ability.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/create-post' ),
						'description' => 'create-post ability called',
					),
					array(
						'type'          => 'post_exists',
						'post_type'     => 'post',
						'title_pattern' => 'AC-001',
						'description'   => 'Post titled with AC-001 exists',
					),
				),
			),
			array(
				'id'         => 'ac-002',
				'category'   => 'posts',
				'max_turns'  => 10,
				'prompt'     => 'List the 5 most recent posts using the list-posts ability, then fetch the full content of the newest one with get-post and report its title and excerpt back to me.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-posts' ),
						'description' => 'list-posts called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-post' ),
						'description' => 'get-post called',
					),
				),
			),
			array(
				'id'         => 'ac-003',
				'category'   => 'posts',
				'max_turns'  => 10,
				'prompt'     => 'Find the post titled "Test Coverage Post AC-001" using list-posts, then update its content using the update-post ability to add the line "UPDATED-AC-003" at the end of the body.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/update-post' ),
						'description' => 'update-post called',
					),
				),
			),
			array(
				'id'         => 'ac-004',
				'category'   => 'posts',
				'max_turns'  => 10,
				'prompt'     => 'Use the batch-create-posts ability to create three draft posts in one call: "Batch AC-004 Alpha", "Batch AC-004 Beta", "Batch AC-004 Gamma". Each should have one paragraph of placeholder body text.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/batch-create-posts' ),
						'description' => 'batch-create-posts called',
					),
					array(
						'type'          => 'post_exists',
						'post_type'     => 'post',
						'title_pattern' => 'Batch AC-004 Alpha',
						'description'   => 'Alpha exists',
					),
					array(
						'type'          => 'post_exists',
						'post_type'     => 'post',
						'title_pattern' => 'Batch AC-004 Beta',
						'description'   => 'Beta exists',
					),
					array(
						'type'          => 'post_exists',
						'post_type'     => 'post',
						'title_pattern' => 'Batch AC-004 Gamma',
						'description'   => 'Gamma exists',
					),
				),
			),
			array(
				'id'         => 'ac-005',
				'category'   => 'posts',
				'max_turns'  => 8,
				'prompt'     => 'Find the draft post "Batch AC-004 Gamma" using list-posts, then permanently delete it (force=true) with delete-post.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/delete-post' ),
						'description' => 'delete-post called',
					),
				),
			),
			array(
				'id'         => 'ac-006',
				'category'   => 'media',
				'max_turns'  => 12,
				'prompt'     => 'Find or create a draft post titled "Featured AC-006", upload a stock image of a coffee cup as a media attachment, and use the set-featured-image ability to attach it as the featured image of that post.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/set-featured-image' ),
						'description' => 'set-featured-image called',
					),
				),
			),
			array(
				'id'         => 'ac-007',
				'category'   => 'editorial',
				'max_turns'  => 6,
				'prompt'     => 'Use the generate-title ability to suggest 3 SEO-friendly title options for a blog post about migrating a legacy WordPress site to a multisite network. Pick the best one and report it back.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/generate-title' ),
						'description' => 'generate-title called',
					),
				),
			),
			array(
				'id'         => 'ac-008',
				'category'   => 'editorial',
				'max_turns'  => 6,
				'prompt'     => 'Use the generate-excerpt ability to produce a 50-word excerpt for the following content: "WordPress multisite lets you run many sites from a single installation. It is ideal for agencies managing client sites, universities running departmental blogs, and SaaS products selling subsites. The shared codebase makes upgrades trivial but the architecture introduces complexity around domain mapping and per-site limitations."',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/generate-excerpt' ),
						'description' => 'generate-excerpt called',
					),
				),
			),
			array(
				'id'         => 'ac-009',
				'category'   => 'editorial',
				'max_turns'  => 6,
				'prompt'     => 'Use the summarize-content ability to summarise the following text in 2 sentences: "Customer support tickets at SaaS companies follow a predictable pattern. About 60% of incoming volume can be deflected with good documentation. The remaining 40% splits into account/billing issues, technical bugs, and feature requests. Tier-1 agents handle the first two; bugs and feature requests are escalated to engineering."',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/summarize-content' ),
						'description' => 'summarize-content called',
					),
				),
			),
			array(
				'id'         => 'ac-010',
				'category'   => 'analysis',
				'max_turns'  => 8,
				'prompt'     => 'Run the content-analyze ability against the main site and report the published-vs-draft post counts and most-used categories.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/content-analyze' ),
						'description' => 'content-analyze called',
					),
				),
			),
			array(
				'id'         => 'ac-011',
				'category'   => 'analysis',
				'max_turns'  => 8,
				'prompt'     => 'Use the content-search ability to find any pages or posts on this site that mention "WordPress" and report the top 3 matches with their IDs and titles.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/content-search' ),
						'description' => 'content-search called',
					),
				),
			),
			array(
				'id'         => 'ac-012',
				'category'   => 'analysis',
				'max_turns'  => 8,
				'prompt'     => 'Run the content-performance-report ability for this site and tell me the top 3 metrics it returns (recent activity, top categories, anything else).',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/content-performance-report' ),
						'description' => 'content-performance-report called',
					),
				),
			),
		);
	}

	/**
	 * Media abilities.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_abilities_media_questions(): array {
		return array(
			array(
				'id'         => 'am-001',
				'category'   => 'media',
				'max_turns'  => 6,
				'prompt'     => 'Use the list-media ability to show me the 5 most recent media attachments on this site with their IDs and filenames.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-media' ),
						'description' => 'list-media called',
					),
				),
			),
			array(
				'id'         => 'am-002',
				'category'   => 'media',
				'max_turns'  => 8,
				'prompt'     => 'Use upload-media-from-url to upload this remote image into the media library: https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/PNG_transparency_demonstration_1.png/240px-PNG_transparency_demonstration_1.png . Set the title to "AM-002 Test Upload". Report back the new attachment ID.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/upload-media-from-url' ),
						'description' => 'upload-media-from-url called',
					),
				),
			),
			array(
				'id'         => 'am-003',
				'category'   => 'media',
				'max_turns'  => 6,
				'prompt'     => 'Use generate-alt-text to generate descriptive alt text for an image whose subject is "a black cat sitting on a windowsill at sunset". Report the suggested alt text.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/generate-alt-text' ),
						'description' => 'generate-alt-text called',
					),
				),
			),
			array(
				'id'         => 'am-004',
				'category'   => 'media',
				'max_turns'  => 6,
				'prompt'     => 'Use the generate-image-prompt ability to expand the rough idea "minimalist hero illustration of a cloud-sync app for teams" into a full image-generation prompt suitable for an AI image model.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/generate-image-prompt' ),
						'description' => 'generate-image-prompt called',
					),
				),
			),
			array(
				'id'         => 'am-005',
				'category'   => 'media',
				'max_turns'  => 8,
				'prompt'     => 'Use the stock-image ability to find a free-licence stock photo matching the query "open laptop on a wooden desk". Show me the top result\'s URL and source.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/stock-image' ),
						'description' => 'stock-image called',
					),
				),
			),
			array(
				'id'         => 'am-006',
				'category'   => 'media',
				'max_turns'  => 12,
				'prompt'     => 'Use generate-image to create a 512x512 image of "a flat-design icon of a lightning bolt on a purple gradient". Report the resulting attachment ID.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/generate-image' ),
						'description' => 'generate-image called',
					),
				),
			),
			array(
				'id'         => 'am-007',
				'category'   => 'media',
				'max_turns'  => 6,
				'prompt'     => 'Use the import-base64-image ability to upload this 1x1 transparent PNG and report the new attachment ID. Image data: iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/import-base64-image' ),
						'description' => 'import-base64-image called',
					),
				),
			),
		);
	}

	/**
	 * Taxonomy / CPT / Menu / User abilities.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_abilities_structure_questions(): array {
		return array(
			array(
				'id'         => 'as-001',
				'category'   => 'cpt',
				'max_turns'  => 6,
				'prompt'     => 'Register a new custom post type with slug "as_book", singular label "Book", plural label "Books", supports title and editor, public and shows in REST. Use the register-post-type ability.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/register-post-type' ),
						'description' => 'register-post-type called',
					),
					array(
						'type'        => 'post_type_registered',
						'post_type'   => 'as_book',
						'description' => 'CPT as_book registered',
					),
				),
			),
			array(
				'id'         => 'as-002',
				'category'   => 'cpt',
				'max_turns'  => 6,
				'prompt'     => 'Use the list-post-types ability to show all custom post types currently registered on this site (excluding built-ins).',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-post-types' ),
						'description' => 'list-post-types called',
					),
				),
			),
			array(
				'id'         => 'as-003',
				'category'   => 'cpt',
				'max_turns'  => 6,
				'prompt'     => 'Use the delete-post-type ability to remove the custom post type "as_book" that was registered earlier.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/delete-post-type' ),
						'description' => 'delete-post-type called',
					),
				),
			),
			array(
				'id'         => 'as-004',
				'category'   => 'taxonomy',
				'max_turns'  => 6,
				'prompt'     => 'Register a hierarchical taxonomy with slug "as_genre", labels "Genre/Genres", attached to the "post" post type, public and shown in REST. Use register-taxonomy.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/register-taxonomy' ),
						'description' => 'register-taxonomy called',
					),
					array(
						'type'        => 'taxonomy_registered',
						'taxonomy'    => 'as_genre',
						'description' => 'taxonomy as_genre registered',
					),
				),
			),
			array(
				'id'         => 'as-005',
				'category'   => 'taxonomy',
				'max_turns'  => 6,
				'prompt'     => 'Use the list-taxonomies ability to enumerate all custom taxonomies on this site (exclude built-ins).',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-taxonomies' ),
						'description' => 'list-taxonomies called',
					),
				),
			),
			array(
				'id'         => 'as-006',
				'category'   => 'taxonomy',
				'max_turns'  => 6,
				'prompt'     => 'Use the delete-taxonomy ability to remove the "as_genre" taxonomy that was registered earlier.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/delete-taxonomy' ),
						'description' => 'delete-taxonomy called',
					),
				),
			),
			array(
				'id'         => 'as-007',
				'category'   => 'menus',
				'max_turns'  => 12,
				'prompt'     => 'Create a new nav menu called "Coverage Menu AS-007" using create-menu. Then add two custom-link items to it via add-menu-item: "Home" → /, and "About" → /about. Finally use list-menus to confirm the menu and item count.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/create-menu' ),
						'description' => 'create-menu called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/add-menu-item' ),
						'min_calls'   => 2,
						'description' => 'add-menu-item called twice',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-menus' ),
						'description' => 'list-menus called',
					),
					array(
						'type'        => 'menu_exists',
						'name'        => 'Coverage Menu AS-007',
						'description' => 'menu Coverage Menu AS-007 exists',
					),
				),
			),
			array(
				'id'         => 'as-008',
				'category'   => 'menus',
				'max_turns'  => 8,
				'prompt'     => 'Use get-menu to fetch the contents of the menu named "Coverage Menu AS-007", then use remove-menu-item to remove the "About" item from it.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-menu' ),
						'description' => 'get-menu called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/remove-menu-item' ),
						'description' => 'remove-menu-item called',
					),
				),
			),
			array(
				'id'         => 'as-009',
				'category'   => 'menus',
				'max_turns'  => 6,
				'prompt'     => 'Use assign-menu-location to assign the "Coverage Menu AS-007" menu to the "primary" theme location, then delete-menu to remove the menu entirely.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/assign-menu-location' ),
						'description' => 'assign-menu-location called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/delete-menu' ),
						'description' => 'delete-menu called',
					),
				),
			),
			array(
				'id'         => 'as-010',
				'category'   => 'users',
				'max_turns'  => 10,
				'prompt'     => 'Use list-users to show the first 5 users on this site, then create-user to add a new user with login "as010_user", email "as010_user@example.test", role "subscriber". Then use update-user-role to change them to "editor".',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-users' ),
						'description' => 'list-users called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/create-user' ),
						'description' => 'create-user called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/update-user-role' ),
						'description' => 'update-user-role called',
					),
					array(
						'type'        => 'user_exists',
						'login'       => 'as010_user',
						'role'        => 'editor',
						'description' => 'user as010_user has role editor',
					),
				),
			),
		);
	}

	/**
	 * Design / blocks / global styles abilities.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_abilities_design_questions(): array {
		return array(
			array(
				'id'         => 'ad-001',
				'category'   => 'blocks',
				'max_turns'  => 6,
				'prompt'     => 'Use list-block-types to show me the first 10 registered Gutenberg block types on this site.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-block-types' ),
						'description' => 'list-block-types called',
					),
				),
			),
			array(
				'id'         => 'ad-002',
				'category'   => 'blocks',
				'max_turns'  => 6,
				'prompt'     => 'Use get-block-type to show the schema and supports of the core/paragraph block.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-block-type' ),
						'description' => 'get-block-type called',
					),
				),
			),
			array(
				'id'         => 'ad-003',
				'category'   => 'blocks',
				'max_turns'  => 6,
				'prompt'     => 'Use list-block-patterns to show me the first 5 block patterns registered on this site (full content, not truncated).',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-block-patterns' ),
						'description' => 'list-block-patterns called',
					),
				),
			),
			array(
				'id'         => 'ad-004',
				'category'   => 'blocks',
				'max_turns'  => 6,
				'prompt'     => 'Use list-block-templates to enumerate the FSE templates available on this site.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-block-templates' ),
						'description' => 'list-block-templates called',
					),
				),
			),
			array(
				'id'         => 'ad-005',
				'category'   => 'blocks',
				'max_turns'  => 6,
				'prompt'     => 'Use markdown-to-blocks to convert this markdown into Gutenberg blocks: "## Hello\n\nThis is **bold** text and a [link](https://example.com).\n\n- one\n- two".',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/markdown-to-blocks' ),
						'description' => 'markdown-to-blocks called',
					),
				),
			),
			array(
				'id'         => 'ad-006',
				'category'   => 'blocks',
				'max_turns'  => 8,
				'prompt'     => 'Use create-block-content to assemble a block array containing a heading ("Coverage AD-006") and a paragraph ("This was created via the create-block-content ability."). Then use validate-block-content to confirm the resulting block markup is valid, and parse-block-content to round-trip it back.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/create-block-content' ),
						'description' => 'create-block-content called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/validate-block-content' ),
						'description' => 'validate-block-content called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/parse-block-content' ),
						'description' => 'parse-block-content called',
					),
				),
			),
			array(
				'id'         => 'ad-007',
				'category'   => 'blocks',
				'max_turns'  => 6,
				'prompt'     => 'Use review-block to suggest improvements for this paragraph block markup: <!-- wp:paragraph --><p>this is a sentence with no capital letters and a typo recieve.</p><!-- /wp:paragraph -->',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/review-block' ),
						'description' => 'review-block called',
					),
				),
			),
			array(
				'id'         => 'ad-008',
				'category'   => 'design',
				'max_turns'  => 6,
				'prompt'     => 'Use get-global-styles to show the current global styles JSON for the active theme on this site.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-global-styles' ),
						'description' => 'get-global-styles called',
					),
				),
			),
			array(
				'id'         => 'ad-009',
				'category'   => 'design',
				'max_turns'  => 8,
				'prompt'     => 'Use update-global-styles to set the body background colour to #fafafa, then use get-theme-json to show the current theme.json. Then use reset-global-styles to revert to defaults.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/update-global-styles' ),
						'description' => 'update-global-styles called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-theme-json' ),
						'description' => 'get-theme-json called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/reset-global-styles' ),
						'description' => 'reset-global-styles called',
					),
				),
			),
			array(
				'id'         => 'ad-010',
				'category'   => 'design',
				'max_turns'  => 6,
				'prompt'     => 'Use theme-json-presets to show what colour palette presets are currently defined in the active theme\'s theme.json.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/theme-json-presets' ),
						'description' => 'theme-json-presets called',
					),
				),
			),
			array(
				'id'         => 'ad-011',
				'category'   => 'design',
				'max_turns'  => 6,
				'prompt'     => 'Use curated-block-patterns to list the curated patterns available, then return their slugs.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/curated-block-patterns' ),
						'description' => 'curated-block-patterns called',
					),
				),
			),
			array(
				'id'         => 'ad-012',
				'category'   => 'design',
				'max_turns'  => 6,
				'prompt'     => 'Use the inject-custom-css ability with dry-run=true to preview the CSS that would be added if I requested "make h1 headings purple and 3rem". Show me the CSS without actually saving it.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/inject-custom-css' ),
						'description' => 'inject-custom-css called',
					),
				),
			),
			array(
				'id'         => 'ad-013',
				'category'   => 'design',
				'max_turns'  => 6,
				'prompt'     => 'Use set-site-logo with remove=true to clear the current site logo (it may already be empty — that is fine, just exercise the call).',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/set-site-logo' ),
						'description' => 'set-site-logo called',
					),
				),
			),
		);
	}

	/**
	 * Developer abilities — files, db, options, php, scan.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_abilities_developer_questions(): array {
		return array(
			array(
				'id'         => 'adev-001',
				'category'   => 'options',
				'max_turns'  => 8,
				'prompt'     => 'Use update-option to set an option named "ac_dev_test_option" to the JSON value {"a":1,"b":[1,2,3]}. Then use get-option to read it back. Then list-options to confirm it appears in the options list.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/update-option' ),
						'description' => 'update-option called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-option' ),
						'description' => 'get-option called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-options' ),
						'description' => 'list-options called',
					),
					array(
						'type'        => 'option_exists',
						'option'      => 'ac_dev_test_option',
						'description' => 'ac_dev_test_option exists',
					),
					array(
						'type'        => 'option_value_matches',
						'option'      => 'ac_dev_test_option',
						'pattern'     => '"a".*1',
						'description' => 'option holds the expected JSON',
					),
				),
			),
			array(
				'id'         => 'adev-002',
				'category'   => 'options',
				'max_turns'  => 6,
				'prompt'     => 'Use delete-option to remove the option "ac_dev_test_option" that was created earlier.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/delete-option' ),
						'description' => 'delete-option called',
					),
				),
			),
			array(
				'id'         => 'adev-003',
				'category'   => 'database',
				'max_turns'  => 6,
				'prompt'     => 'Use the db-query ability to run a SELECT showing the 5 most recent rows from the wp_posts table — only id, post_title and post_status. Read-only query.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/db-query' ),
						'description' => 'db-query called',
					),
				),
			),
			array(
				'id'         => 'adev-004',
				'category'   => 'php',
				'max_turns'  => 6,
				'prompt'     => 'Use the run-php ability to evaluate a snippet that returns the WordPress version (return get_bloginfo(\'version\');). Report the value back to me.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/run-php' ),
						'description' => 'run-php called',
					),
				),
			),
			array(
				'id'         => 'adev-005',
				'category'   => 'files',
				'max_turns'  => 12,
				'prompt'     => 'Use file-list to list the contents of the current plugin directory (path "."). Then use file-search (glob filename matcher) to find any PHP files matching the pattern "*Abilities.php". Then use file-read on the first match and report its first 20 lines back.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/file-list' ),
						'description' => 'file-list called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/file-search' ),
						'description' => 'file-search called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/file-read' ),
						'description' => 'file-read called',
					),
				),
			),
			array(
				'id'         => 'adev-006',
				'category'   => 'files',
				'max_turns'  => 14,
				'prompt'     => 'Build a one-file plugin called "file-coverage" by: (a) using file-write to create file-coverage/file-coverage.php with the standard plugin header (Plugin Name: File Coverage), (b) using file-edit to append a comment "// EDITED" at the bottom, (c) using file-read to verify the change, (d) using file-delete to remove the file. The plugin slug must be exactly "file-coverage".',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/file-write' ),
						'description' => 'file-write called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/file-edit' ),
						'description' => 'file-edit called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/file-read' ),
						'description' => 'file-read called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/file-delete' ),
						'description' => 'file-delete called',
					),
				),
			),
			array(
				'id'         => 'adev-007',
				'category'   => 'scan',
				'max_turns'  => 6,
				'prompt'     => 'Use scan-plugin-hooks to list all action and filter hook names invoked from the "akismet" plugin (or any installed plugin if akismet is not present — pick one from the get-plugins list).',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/scan-plugin-hooks' ),
						'description' => 'scan-plugin-hooks called',
					),
				),
			),
			array(
				'id'         => 'adev-008',
				'category'   => 'scan',
				'max_turns'  => 6,
				'prompt'     => 'Use scan-theme-hooks against the active theme to list its registered hooks.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/scan-theme-hooks' ),
						'description' => 'scan-theme-hooks called',
					),
				),
			),
			array(
				'id'         => 'adev-009',
				'category'   => 'scan',
				'max_turns'  => 6,
				'prompt'     => 'Use scan-php-error-log to show me the last 10 entries from the WordPress PHP error log. If the log is empty say so.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/scan-php-error-log' ),
						'description' => 'scan-php-error-log called',
					),
				),
			),
		);
	}

	/**
	 * Plugin management abilities.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_abilities_plugin_mgmt_questions(): array {
		return array(
			array(
				'id'         => 'apm-001',
				'category'   => 'plugins',
				'max_turns'  => 6,
				'prompt'     => 'Use the get-plugins ability to list all plugins currently installed on this site, and the get-themes ability to list installed themes.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-plugins' ),
						'description' => 'get-plugins called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-themes' ),
						'description' => 'get-themes called',
					),
				),
			),
			array(
				'id'         => 'apm-002',
				'category'   => 'plugins',
				'max_turns'  => 6,
				'prompt'     => 'Use search-plugin-directory to find a plugin in the WordPress.org directory matching the term "hello dolly". Show me the top result.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/search-plugin-directory' ),
						'description' => 'search-plugin-directory called',
					),
				),
			),
			array(
				'id'         => 'apm-003',
				'category'   => 'plugins',
				'max_turns'  => 12,
				'prompt'     => 'Install the "hello-dolly" plugin from the WordPress.org directory using install-plugin (do not activate it yet). Then use activate-plugin to activate it. Confirm with get-plugins that it is active.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/install-plugin' ),
						'description' => 'install-plugin called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/activate-plugin' ),
						'description' => 'activate-plugin called',
					),
				),
			),
			array(
				'id'         => 'apm-004',
				'category'   => 'plugins',
				'max_turns'  => 8,
				'prompt'     => 'Use deactivate-plugin to deactivate the "hello-dolly" plugin, then use delete-plugin to remove it from the site entirely.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/deactivate-plugin' ),
						'description' => 'deactivate-plugin called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/delete-plugin' ),
						'description' => 'delete-plugin called',
					),
				),
			),
			array(
				'id'         => 'apm-005',
				'category'   => 'plugins',
				'max_turns'  => 6,
				'prompt'     => 'Use list-plugin-updates and check-plugin-updates to report any plugin updates currently available on this site.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-plugin-updates' ),
						'description' => 'list-plugin-updates called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/check-plugin-updates' ),
						'description' => 'check-plugin-updates called',
					),
				),
			),
			array(
				'id'         => 'apm-006',
				'category'   => 'plugins',
				'max_turns'  => 6,
				'prompt'     => 'Use list-modified-plugins to show me any plugins on this site that have been modified from their original distribution.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/list-modified-plugins' ),
						'description' => 'list-modified-plugins called',
					),
				),
			),
			array(
				'id'         => 'apm-007',
				'category'   => 'plugins',
				'max_turns'  => 6,
				'prompt'     => 'Use the recommend-plugin ability to recommend a plugin for "newsletter signup forms". Show me 3 candidates with their slugs.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/recommend-plugin' ),
						'description' => 'recommend-plugin called',
					),
				),
			),
			array(
				'id'         => 'apm-008',
				'category'   => 'plugins',
				'max_turns'  => 6,
				'prompt'     => 'Use get-plugin-download-url to fetch the download URL for the "akismet" plugin from WordPress.org.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-plugin-download-url' ),
						'description' => 'get-plugin-download-url called',
					),
				),
			),
			array(
				'id'         => 'apm-009',
				'category'   => 'sandbox',
				'max_turns'  => 25,
				'prompt'     => 'Use generate-plugin to create a new sandboxed plugin called "apm-009-sandbox" that registers a single shortcode [apm009] returning the string "hello from apm-009". Then use sandbox-test-plugin to verify it loads cleanly. Then use sandbox-activate-plugin to copy it from sandbox to the live plugins directory and activate it.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/generate-plugin' ),
						'description' => 'generate-plugin called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/sandbox-test-plugin' ),
						'description' => 'sandbox-test-plugin called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/sandbox-activate-plugin' ),
						'description' => 'sandbox-activate-plugin called',
					),
				),
			),
			array(
				'id'         => 'apm-010',
				'category'   => 'sandbox',
				'max_turns'  => 15,
				'prompt'     => 'Use update-plugin-sandboxed to apply a small change to the previously generated "apm-009-sandbox" plugin: add a second shortcode [apm009v2] that returns "v2". Test it in the sandbox before deploying.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/update-plugin-sandboxed' ),
						'description' => 'update-plugin-sandboxed called',
					),
				),
			),
			array(
				'id'         => 'apm-011',
				'category'   => 'plugins',
				'max_turns'  => 6,
				'prompt'     => 'Use the install-plugin-from-url ability to attempt to fetch the URL https://downloads.wordpress.org/plugin/hello-dolly.zip into a draft slot — do NOT activate it. Report whether the install succeeded.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/install-plugin-from-url' ),
						'description' => 'install-plugin-from-url called',
					),
				),
			),
			array(
				'id'         => 'apm-012',
				'category'   => 'plugins',
				'max_turns'  => 8,
				'prompt'     => 'Use update-plugin to attempt to update the "akismet" plugin to the latest version (it may already be up to date — that is fine, just exercise the path).',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/update-plugin' ),
						'description' => 'update-plugin called',
					),
				),
			),
			array(
				'id'         => 'apm-013',
				'category'   => 'plugins',
				'max_turns'  => 6,
				'prompt'     => 'Use the switch-plugin ability to find a recommended replacement for "akismet" (any anti-spam alternative). Just exercise the call — do not actually switch.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/switch-plugin' ),
						'description' => 'switch-plugin called',
					),
				),
			),
		);
	}

	/**
	 * Utility abilities — site-health, navigation, search, seo, memory, skills, git.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_abilities_utility_questions(): array {
		return array(
			array(
				'id'         => 'au-001',
				'category'   => 'site-health',
				'max_turns'  => 6,
				'prompt'     => 'Use site-health-summary, check-disk-space, check-performance, check-security, and detect-fresh-install to give me a one-paragraph site-health overview.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/site-health-summary' ),
						'description' => 'site-health-summary called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/check-disk-space' ),
						'description' => 'check-disk-space called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/check-performance' ),
						'description' => 'check-performance called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/check-security' ),
						'description' => 'check-security called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/detect-fresh-install' ),
						'description' => 'detect-fresh-install called',
					),
				),
			),
			array(
				'id'         => 'au-002',
				'category'   => 'navigation',
				'max_turns'  => 6,
				'prompt'     => 'Use the navigate ability to send the user to the WordPress dashboard, then use get-page-html to fetch the rendered HTML of the home page (/) and report the <title> tag content.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/navigate' ),
						'description' => 'navigate called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/get-page-html' ),
						'description' => 'get-page-html called',
					),
				),
			),
			array(
				'id'         => 'au-003',
				'category'   => 'web',
				'max_turns'  => 6,
				'prompt'     => 'Use fetch-url to fetch https://example.com and report the first 200 characters of the response body. Then use analyze-headers on the same URL and tell me what cache headers were returned.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/fetch-url' ),
						'description' => 'fetch-url called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/analyze-headers' ),
						'description' => 'analyze-headers called',
					),
				),
			),
			array(
				'id'         => 'au-004',
				'category'   => 'web',
				'max_turns'  => 6,
				'prompt'     => 'Use internet-search to search for "WordPress 6.7 release notes" and show me the top 3 results.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/internet-search' ),
						'description' => 'internet-search called',
					),
				),
			),
			array(
				'id'         => 'au-005',
				'category'   => 'web',
				'max_turns'  => 6,
				'prompt'     => 'Use configure-search-provider to query the current internet-search provider configuration on this site (just read it back, do not change anything).',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/configure-search-provider' ),
						'description' => 'configure-search-provider called',
					),
				),
			),
			array(
				'id'         => 'au-006',
				'category'   => 'knowledge',
				'max_turns'  => 6,
				'prompt'     => 'Use knowledge-search to search the local knowledge base for "WordPress hooks". If no collection is configured, report that gracefully.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/knowledge-search' ),
						'description' => 'knowledge-search called',
					),
				),
			),
			array(
				'id'         => 'au-007',
				'category'   => 'seo',
				'max_turns'  => 8,
				'prompt'     => 'Use seo-analyze-content on this body text targeting the keyword "remote work tools": "Working remotely changes the kinds of tools teams need. Project management software, video calls, async docs, and good ergonomics matter more than they do in an office. Choose tools that integrate well together." Report the score and top 3 suggestions.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/seo-analyze-content' ),
						'description' => 'seo-analyze-content called',
					),
				),
			),
			array(
				'id'         => 'au-008',
				'category'   => 'seo',
				'max_turns'  => 8,
				'prompt'     => 'Use seo-audit-url to audit https://example.com and report the top 3 SEO issues it finds.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/seo-audit-url' ),
						'description' => 'seo-audit-url called',
					),
				),
			),
			array(
				'id'         => 'au-009',
				'category'   => 'memory',
				'max_turns'  => 8,
				'prompt'     => 'Use memory-save to save a fact under key "au_009_fact" with body "Coverage benchmark AU-009 ran on this site". Then memory-list to confirm. Then memory-delete to remove it.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/memory-save' ),
						'description' => 'memory-save called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/memory-list' ),
						'description' => 'memory-list called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/memory-delete' ),
						'description' => 'memory-delete called',
					),
				),
			),
			array(
				'id'         => 'au-010',
				'category'   => 'skills',
				'max_turns'  => 6,
				'prompt'     => 'Use skill-list to enumerate the skills the agent currently has loaded, then use skill-load to load whichever skill is most relevant for "creating WordPress block themes".',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/skill-list' ),
						'description' => 'skill-list called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/skill-load' ),
						'description' => 'skill-load called',
					),
				),
			),
			array(
				'id'         => 'au-011',
				'category'   => 'git',
				'max_turns'  => 10,
				'prompt'     => 'Run these four git abilities in order so we can verify each one is wired up. (1) git-list (no args) — list all currently tracked files. (2) git-package-summary with package="akismet/akismet.php", package_type="plugin". (3) git-snapshot with path="' . WP_PLUGIN_DIR . '/akismet/akismet.php". (4) git-diff with package="akismet/akismet.php", package_type="plugin". Empty results are fine — the goal is to exercise each ability.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/git-snapshot' ),
						'description' => 'git-snapshot called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/git-list' ),
						'description' => 'git-list called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/git-package-summary' ),
						'description' => 'git-package-summary called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/git-diff' ),
						'description' => 'git-diff called',
					),
				),
			),
			array(
				'id'         => 'au-012',
				'category'   => 'git',
				'max_turns'  => 6,
				'prompt'     => 'Call git-restore with package="akismet/akismet.php", package_type="plugin". Then call git-revert-package with the same package and package_type. Both calls may report nothing-to-restore — that is fine, the goal is to exercise each ability.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/git-restore' ),
						'description' => 'git-restore called',
					),
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/git-revert-package' ),
						'description' => 'git-revert-package called',
					),
				),
			),
			array(
				'id'         => 'au-014',
				'category'   => 'meta',
				'max_turns'  => 6,
				'prompt'     => 'Pretend you have been asked to perform a task that you genuinely cannot do — "open my refrigerator and make me a sandwich". Use the report-inability ability to formally report what was attempted and why it cannot be completed.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/report-inability' ),
						'description' => 'report-inability called',
					),
				),
			),
		);
	}

	/**
	 * Credentialed abilities — Google Analytics & Search Console.
	 * Without live credentials these test graceful degradation paths.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_abilities_credentialed_questions(): array {
		return array(
			array(
				'id'         => 'acr-001',
				'category'   => 'ga',
				'max_turns'  => 6,
				'prompt'     => 'Use ga-traffic-summary to fetch the last 7 days of traffic for this site. If GA credentials are not configured, report the error message gracefully.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/ga-traffic-summary' ),
						'description' => 'ga-traffic-summary called',
					),
				),
			),
			array(
				'id'         => 'acr-002',
				'category'   => 'ga',
				'max_turns'  => 6,
				'prompt'     => 'Use ga-top-pages to fetch the top 10 pages by pageviews for the last 30 days.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/ga-top-pages' ),
						'description' => 'ga-top-pages called',
					),
				),
			),
			array(
				'id'         => 'acr-003',
				'category'   => 'ga',
				'max_turns'  => 6,
				'prompt'     => 'Use ga-realtime to fetch the current live visitor count.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/ga-realtime' ),
						'description' => 'ga-realtime called',
					),
				),
			),
			array(
				'id'         => 'acr-004',
				'category'   => 'gsc',
				'max_turns'  => 6,
				'prompt'     => 'Use gsc-site-summary for the last 28 days, including the previous-period comparison.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/gsc-site-summary' ),
						'description' => 'gsc-site-summary called',
					),
				),
			),
			array(
				'id'         => 'acr-005',
				'category'   => 'gsc',
				'max_turns'  => 6,
				'prompt'     => 'Use gsc-top-queries to show the top 10 search queries driving traffic in the last 28 days.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/gsc-top-queries' ),
						'description' => 'gsc-top-queries called',
					),
				),
			),
			array(
				'id'         => 'acr-006',
				'category'   => 'gsc',
				'max_turns'  => 6,
				'prompt'     => 'Use gsc-page-performance for the homepage URL of this site over the last 28 days.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/gsc-page-performance' ),
						'description' => 'gsc-page-performance called',
					),
				),
			),
			array(
				'id'         => 'acr-007',
				'category'   => 'gsc',
				'max_turns'  => 6,
				'prompt'     => 'Use gsc-query-details for the query "wordpress" over the last 28 days.',
				'assertions' => array(
					array(
						'type'        => 'tool_called',
						'tools'       => array( 'sd-ai-agent/gsc-query-details' ),
						'description' => 'gsc-query-details called',
					),
				),
			),
		);
	}
}

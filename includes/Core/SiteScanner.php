<?php

declare(strict_types=1);
/**
 * Site Scanner — collects site metadata on first activation for smart onboarding.
 *
 * Runs as a background WP-Cron job. Gathers plugins, theme, post types,
 * post counts, categories, WooCommerce status, and site identity, then
 * stores the results as memories and optionally seeds the knowledge base
 * with the first 50 published posts.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Knowledge\Knowledge;
use SdAiAgent\Knowledge\KnowledgeDatabase;
use SdAiAgent\Models\Memory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteScanner {

	/**
	 * WP-Cron hook name for the background scan.
	 */
	const CRON_HOOK = 'sd_ai_agent_site_scan';

	/**
	 * Option key that stores the scan status / results.
	 */
	const STATUS_OPTION = 'sd_ai_agent_onboarding_scan';

	/**
	 * Maximum number of posts to seed into the knowledge base.
	 */
	const KNOWLEDGE_SEED_LIMIT = 50;

	// ── Registration ─────────────────────────────────────────────────────

	/**
	 * Register the cron hook handler.
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * Schedule a one-time background scan (idempotent — safe to call multiple times).
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}

	/**
	 * Cancel a pending scan (e.g. on plugin deactivation).
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	// ── Status helpers ────────────────────────────────────────────────────

	/**
	 * Return the current scan status record.
	 *
	 * @return array
	 */
	public static function get_status(): array {
		$raw = get_option( self::STATUS_OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Whether the scan has already completed successfully.
	 */
	public static function is_complete(): bool {
		$status = self::get_status();
		return ( $status['status'] ?? '' ) === 'complete';
	}

	/**
	 * Whether the scan is currently pending or running.
	 */
	public static function is_pending(): bool {
		$status = self::get_status();
		return in_array( $status['status'] ?? '', [ 'pending', 'running' ], true );
	}

	// ── Core scan ─────────────────────────────────────────────────────────

	/**
	 * Execute the site scan (called by WP-Cron).
	 *
	 * Collects site metadata, stores memories, and seeds the knowledge base.
	 */
	public static function run(): void {
		// Mark as running.
		update_option(
			self::STATUS_OPTION,
			[
				'status'     => 'running',
				'started_at' => current_time( 'mysql', true ),
			],
			false
		);

		try {
			$data = self::collect();
			self::store_memories( $data );
			self::seed_knowledge_base( $data );

			update_option(
				self::STATUS_OPTION,
				[
					'status'       => 'complete',
					'started_at'   => self::get_status()['started_at'] ?? current_time( 'mysql', true ),
					'completed_at' => current_time( 'mysql', true ),
					'site_type'    => $data['site_type'],
					'post_count'   => $data['post_count'],
				],
				false
			);
		} catch ( \Throwable $e ) {
			update_option(
				self::STATUS_OPTION,
				[
					'status' => 'error',
					'error'  => $e->getMessage(),
				],
				false
			);
		}
	}

	// ── Data collection ───────────────────────────────────────────────────

	/**
	 * Collect all site metadata.
	 *
	 * @return array<string, mixed>
	 */
	public static function collect(): array {
		$data = [];

		$data['site_name']    = get_bloginfo( 'name' );
		$data['site_url']     = get_site_url();
		$data['site_tagline'] = get_bloginfo( 'description' );
		$data['wp_version']   = get_bloginfo( 'version' );
		$data['language']     = get_bloginfo( 'language' );

		$data['active_theme']   = self::collect_theme();
		$data['active_plugins'] = self::collect_plugins();
		$data['post_types']     = self::collect_post_types();
		$data['post_count']     = self::collect_post_count();
		$data['categories']     = self::collect_categories();
		$data['woocommerce']    = self::collect_woocommerce();
		$data['site_type']      = self::detect_site_type( $data );

		return $data;
	}

	/**
	 * Collect active theme info.
	 *
	 * @return array<string, string>
	 */
	private static function collect_theme(): array {
		$theme = wp_get_theme();
		return [
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'author'  => $theme->get( 'Author' ),
		];
	}

	/**
	 * Collect active plugin names.
	 *
	 * @return string[]
	 */
	private static function collect_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active  = get_option( 'active_plugins', [] );
		$plugins = [];

		foreach ( $active as $plugin_file ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			if ( ! empty( $plugin_data['Name'] ) ) {
				$plugins[] = $plugin_data['Name'];
			}
		}

		return $plugins;
	}

	/**
	 * Collect public, non-built-in post types.
	 *
	 * @return string[]
	 */
	private static function collect_post_types(): array {
		$args  = [
			'public'   => true,
			'_builtin' => false,
		];
		$types = get_post_types( $args, 'names' );

		// Always include the built-in public types.
		$builtin = [ 'post', 'page' ];

		return array_values( array_unique( array_merge( $builtin, array_values( $types ) ) ) );
	}

	/**
	 * Count published posts across all public post types.
	 *
	 * @return int
	 */
	private static function collect_post_count(): int {
		$counts = wp_count_posts( 'post' );
		return (int) ( $counts->publish ?? 0 );
	}

	/**
	 * Collect top-level category names (up to 20).
	 *
	 * @return string[]
	 */
	private static function collect_categories(): array {
		$terms = get_terms(
			[
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'parent'     => 0,
				'number'     => 20,
				'fields'     => 'names',
			]
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		return array_values( $terms );
	}

	/**
	 * Detect WooCommerce status and basic store info.
	 *
	 * @return array<string, mixed>
	 */
	private static function collect_woocommerce(): array {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'get_woocommerce_currency' ) ) {
			return [ 'active' => false ];
		}

		$product_count = wp_count_posts( 'product' );

		return [
			'active'        => true,
			'version'       => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
			'product_count' => (int) ( $product_count->publish ?? 0 ),
			'currency'      => get_woocommerce_currency(),
		];
	}

	/**
	 * Detect the site type based on collected data.
	 *
	 * @param array<string, mixed> $data Collected site data.
	 * @return string Site type slug.
	 */
	private static function detect_site_type( array $data ): string {
		if ( ! empty( $data['woocommerce']['active'] ) ) {
			return 'ecommerce';
		}

		$plugin_names = array_map( 'strtolower', $data['active_plugins'] ?? [] );
		$plugin_str   = implode( ' ', $plugin_names );

		if ( str_contains( $plugin_str, 'lms' ) || str_contains( $plugin_str, 'learnpress' ) || str_contains( $plugin_str, 'learndash' ) || str_contains( $plugin_str, 'tutor' ) ) {
			return 'lms';
		}

		if ( str_contains( $plugin_str, 'membership' ) || str_contains( $plugin_str, 'restrict content' ) || str_contains( $plugin_str, 'memberpress' ) ) {
			return 'membership';
		}

		if ( str_contains( $plugin_str, 'portfolio' ) || str_contains( $plugin_str, 'elementor' ) ) {
			return 'portfolio';
		}

		// Heuristic: lots of posts → blog/news.
		if ( ( $data['post_count'] ?? 0 ) > 20 ) {
			return 'blog';
		}

		return 'brochure';
	}

	// ── Memory storage ────────────────────────────────────────────────────

	/**
	 * Store scan results as agent memories.
	 *
	 * @param array<string, mixed> $data Collected site data.
	 */
	private static function store_memories( array $data ): void {
		// Clear any previous onboarding memories to avoid duplicates on re-scan.
		Memory::forget_by_topic( 'site scan onboarding' );

		// Site identity.
		Memory::create(
			'site_info',
			sprintf(
				/* translators: 1: site name, 2: site URL, 3: tagline */
				'Site name: %1$s. URL: %2$s. Tagline: %3$s.',
				$data['site_name'],
				$data['site_url'],
				$data['site_tagline'] ?: 'none'
			)
		);

		// WordPress version + language.
		Memory::create(
			'technical_notes',
			sprintf(
				/* translators: 1: WP version, 2: language */
				'WordPress version: %1$s. Site language: %2$s.',
				$data['wp_version'],
				$data['language']
			)
		);

		// Active theme.
		$theme = $data['active_theme'];
		Memory::create(
			'technical_notes',
			sprintf(
				/* translators: 1: theme name, 2: theme version */
				'Active theme: %1$s (version %2$s).',
				$theme['name'],
				$theme['version']
			)
		);

		// Active plugins (chunked to avoid overly long memories).
		if ( ! empty( $data['active_plugins'] ) ) {
			$chunks = array_chunk( $data['active_plugins'], 10 );
			foreach ( $chunks as $chunk ) {
				Memory::create(
					'technical_notes',
					'Active plugins: ' . implode( ', ', $chunk ) . '.'
				);
			}
		}

		// Post types.
		if ( ! empty( $data['post_types'] ) ) {
			Memory::create(
				'site_info',
				'Registered public post types: ' . implode( ', ', $data['post_types'] ) . '.'
			);
		}

		// Post count.
		Memory::create(
			'site_info',
			sprintf(
				/* translators: %d: number of published posts */
				'Published post count: %d.',
				$data['post_count']
			)
		);

		// Categories.
		if ( ! empty( $data['categories'] ) ) {
			Memory::create(
				'site_info',
				'Top-level categories: ' . implode( ', ', $data['categories'] ) . '.'
			);
		}

		// WooCommerce.
		if ( ! empty( $data['woocommerce']['active'] ) ) {
			Memory::create(
				'site_info',
				sprintf(
					/* translators: 1: WC version, 2: product count, 3: currency */
					'WooCommerce %1$s is active. Products: %2$d. Currency: %3$s.',
					$data['woocommerce']['version'],
					$data['woocommerce']['product_count'],
					$data['woocommerce']['currency']
				)
			);
		}

		// Detected site type.
		Memory::create(
			'site_info',
			sprintf(
				/* translators: %s: site type */
				'Detected site type: %s. (Set during onboarding scan — update if incorrect.)',
				$data['site_type']
			)
		);
	}

	// ── Knowledge base seeding ────────────────────────────────────────────

	/**
	 * Seed the knowledge base with the first N published posts.
	 *
	 * Creates a dedicated "Site Content" collection if one does not already exist,
	 * then indexes up to KNOWLEDGE_SEED_LIMIT posts into it.
	 *
	 * @param array<string, mixed> $data Collected site data.
	 */
	private static function seed_knowledge_base( array $data ): void {
		$settings = Settings::instance()->get();

		// Only seed if the knowledge base feature is enabled.
		if ( empty( $settings['knowledge_enabled'] ) ) {
			return;
		}

		$collection_id = self::get_or_create_seed_collection( $data );

		if ( ! $collection_id ) {
			return;
		}

		$post_types = $data['post_types'] ?? [ 'post', 'page' ];

		$posts = get_posts(
			[
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => self::KNOWLEDGE_SEED_LIMIT,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			]
		);

		foreach ( $posts as $post_id ) {
			Knowledge::index_post( (int) $post_id, $collection_id );
		}
	}

	/**
	 * Get or create the onboarding seed knowledge collection.
	 *
	 * @param array<string, mixed> $data Collected site data.
	 * @return int|false Collection ID or false on failure.
	 */
	private static function get_or_create_seed_collection( array $data ) {
		// Check if the seed collection already exists.
		$collections = KnowledgeDatabase::list_collections( 'active' );
		foreach ( $collections as $collection ) {
			if ( ( $collection->slug ?? '' ) === 'onboarding-site-content' ) {
				return (int) $collection->id;
			}
		}

		$post_types = $data['post_types'] ?? [ 'post', 'page' ];

		return KnowledgeDatabase::create_collection(
			[
				'name'          => __( 'Site Content', 'superdav-ai-agent' ),
				'slug'          => 'onboarding-site-content',
				'description'   => __( 'Auto-indexed during onboarding scan.', 'superdav-ai-agent' ),
				'auto_index'    => true,
				'source_config' => [
					'post_types' => $post_types,
				],
			]
		);
	}
}

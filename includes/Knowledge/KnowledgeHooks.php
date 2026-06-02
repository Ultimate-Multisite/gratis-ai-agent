<?php

declare(strict_types=1);
/**
 * Knowledge base WordPress hooks.
 *
 * Auto-indexes posts on save/delete and schedules batch re-indexing.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Knowledge;

use SdAiAgent\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KnowledgeHooks {

	/**
	 * Plugin-owned cron hook for scheduled knowledge re-indexing.
	 */
	public const CRON_HOOK = 'sd_ai_agent_reindex';

	/**
	 * Register all hooks.
	 */
	public static function register(): void {
		add_action( 'save_post', [ __CLASS__, 'handle_save_post' ], 20, 2 );
		add_action( 'delete_post', [ __CLASS__, 'handle_delete_post' ], 10, 1 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'handle_cron_reindex' ] );

		// Schedule hourly reindex if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Handle post save — index if the post type matches any collection.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function handle_save_post( int $post_id, $post ): void {
		// Skip autosaves, revisions, and non-published posts.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$settings = Settings::instance()->get();
		if ( empty( $settings['knowledge_enabled'] ) || empty( $settings['knowledge_auto_index'] ) ) {
			return;
		}

		// Find collections that auto-index this post type.
		$collections = KnowledgeDatabase::list_collections( 'active' );

		foreach ( $collections as $collection ) {
			if ( empty( $collection->auto_index ) ) {
				continue;
			}

			$config     = $collection->source_config;
			$post_types = $config['post_types'] ?? [];

			if ( ! empty( $post_types ) && in_array( $post->post_type, $post_types, true ) ) {
				Knowledge::index_post( $post_id, (int) $collection->id );
			}
		}
	}

	/**
	 * Handle post deletion — remove source and chunks.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function handle_delete_post( int $post_id ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$sources_table = KnowledgeDatabase::sources_table();

		// Find all sources referencing this post.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$sources = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, collection_id FROM %i WHERE source_type IN ('post', 'attachment') AND source_id = %d",
				$sources_table,
				$post_id
			)
		);

		if ( empty( $sources ) ) {
			return;
		}

		foreach ( $sources as $source ) {
			KnowledgeDatabase::delete_source( (int) $source->id );
		}
	}

	/**
	 * Handle the hourly cron re-index of auto-index collections.
	 */
	public static function handle_cron_reindex(): void {
		$settings = Settings::instance()->get();

		if ( empty( $settings['knowledge_enabled'] ) ) {
			return;
		}

		$collections = KnowledgeDatabase::list_collections( 'active' );

		foreach ( $collections as $collection ) {
			if ( empty( $collection->auto_index ) ) {
				continue;
			}

			Knowledge::reindex_collection( (int) $collection->id );
		}
	}

	/**
	 * Clean up scheduled events on plugin deactivation.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}

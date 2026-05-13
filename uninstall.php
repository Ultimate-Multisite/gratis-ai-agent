<?php
/**
 * Uninstall handler for Superdav AI Agent.
 *
 * Runs when the user deletes the plugin from the WordPress admin.
 * Removes all plugin data: database tables, options, user meta, and cron events.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Guard: only run when WordPress triggers uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── 1. Drop all plugin database tables ──────────────────────────────────────
$sd_ai_agent_tables = [
	$wpdb->prefix . 'sd_ai_agent_sessions',
	$wpdb->prefix . 'sd_ai_agent_usage',
	$wpdb->prefix . 'sd_ai_agent_memories',
	$wpdb->prefix . 'sd_ai_agent_skills',
	$wpdb->prefix . 'sd_ai_agent_custom_tools',
	$wpdb->prefix . 'sd_ai_agent_automations',
	$wpdb->prefix . 'sd_ai_agent_automation_logs',
	$wpdb->prefix . 'sd_ai_agent_event_automations',
	$wpdb->prefix . 'sd_ai_agent_conversation_templates',
	$wpdb->prefix . 'sd_ai_agent_git_tracked_files',
	$wpdb->prefix . 'sd_ai_agent_changes_log',
	$wpdb->prefix . 'sd_ai_agent_modified_files',
	$wpdb->prefix . 'sd_ai_agent_agents',
	$wpdb->prefix . 'sd_ai_agent_shared_sessions',
	$wpdb->prefix . 'sd_ai_agent_benchmark_runs',
	$wpdb->prefix . 'sd_ai_agent_benchmark_results',
];

foreach ( $sd_ai_agent_tables as $sd_ai_agent_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall handler must drop tables; names are from $wpdb->prefix only, caching is irrelevant on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS `{$sd_ai_agent_table}`" );
}

// ── 2. Delete all plugin options ─────────────────────────────────────────────
$sd_ai_agent_options = [
	'sd_ai_agent_settings',
	'sd_ai_agent_db_version',
	'sd_ai_agent_claude_max_token',
	// Legacy option removed in v1.x — kept here so uninstall cleans up rows on
	// sites that upgraded from before the WP 7.0 Connectors API migration.
	'sd_ai_agent_provider_keys',
	'sd_ai_agent_gsc_credentials',
	'sd_ai_agent_tool_profiles',
	'sd_ai_agent_custom_tools_seeded',
	'sd_ai_agent_migrated_from_ai_agent',
];

foreach ( $sd_ai_agent_options as $sd_ai_agent_option ) {
	delete_option( $sd_ai_agent_option );
}

// ── 3. Delete user meta with plugin prefix ───────────────────────────────────
// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-only, caching is irrelevant on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( 'sd_ai_agent_' ) . '%'
	)
);

// ── 4. Clear scheduled cron events ───────────────────────────────────────────
$sd_ai_agent_cron_hooks = [
	'sd_ai_agent_run_automation',
	'sd_ai_agent_run_event_automation',
	'sd_ai_agent_site_scan',
	'wp_sd_ai_agent_reindex',
];

foreach ( $sd_ai_agent_cron_hooks as $sd_ai_agent_hook ) {
	wp_clear_scheduled_hook( $sd_ai_agent_hook );
}

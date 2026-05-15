<?php

declare(strict_types=1);
/**
 * Database table management for AI Agent sessions.
 *
 * This class owns:
 * - DB version constants and schema installation.
 * - Table-name registry (referenced by repository classes and external code).
 * - Thin static delegates to domain repositories for backward compatibility.
 *
 * Business logic has been extracted into:
 * - SdAiAgent\Repositories\SessionRepository  — session + shared-session CRUD
 * - SdAiAgent\Repositories\UsageRepository    — usage logging
 * - SdAiAgent\Repositories\ModifiedFilesRepository — file-modification audit
 * - SdAiAgent\Repositories\GeneratedPluginsRepository — AI plugin builder records
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Knowledge\KnowledgeDatabase;
use SdAiAgent\Models\Agent;
use SdAiAgent\Models\ConversationTemplate;
use SdAiAgent\Models\ProviderTrace;
use SdAiAgent\Models\Skill;
use SdAiAgent\Repositories\GeneratedPluginsRepository;
use SdAiAgent\Repositories\ModifiedFilesRepository;
use SdAiAgent\Repositories\SessionRepository;
use SdAiAgent\Repositories\UsageRepository;
use SdAiAgent\REST\WebhookDatabase;
use SdAiAgent\Tools\CustomTools;

class Database {

	const DB_VERSION_OPTION = 'sd_ai_agent_db_version';
	const DB_VERSION        = '19.3.0';

	// ─── Table Name Registry ──────────────────────────────────────────────────

	/**
	 * Get the sessions table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_sessions';
	}

	/**
	 * Get the usage table name.
	 */
	public static function usage_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_usage';
	}

	/**
	 * Get the memories table name.
	 */
	public static function memories_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_memories';
	}

	/**
	 * Get the skills table name.
	 */
	public static function skills_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_skills';
	}

	/**
	 * Get the custom tools table name.
	 */
	public static function custom_tools_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_custom_tools';
	}

	/**
	 * Get the automations table name.
	 */
	public static function automations_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_automations';
	}

	/**
	 * Get the automation logs table name.
	 */
	public static function automation_logs_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_automation_logs';
	}

	/**
	 * Get the event automations table name.
	 */
	public static function event_automations_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_event_automations';
	}

	/**
	 * Get the conversation templates table name.
	 */
	public static function conversation_templates_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_conversation_templates';
	}

	/**
	 * Get the git tracked files table name.
	 */
	public static function git_tracked_files_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_git_tracked_files';
	}

	/**
	 * Get the changes log table name.
	 */
	public static function changes_log_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_changes_log';
	}

	/**
	 * Get the modified files table name.
	 *
	 * Tracks files written or edited by the AI agent so modified plugins
	 * can be identified and offered as downloads.
	 */
	public static function modified_files_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_modified_files';
	}

	/**
	 * Get the agents table name.
	 */
	public static function agents_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_agents';
	}

	/**
	 * Get the shared sessions table name.
	 */
	public static function shared_sessions_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_shared_sessions';
	}

	/**
	 * Get the benchmark runs table name.
	 */
	public static function benchmark_runs_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_benchmark_runs';
	}

	/**
	 * Get the benchmark results table name.
	 */
	public static function benchmark_results_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_benchmark_results';
	}


	/**
	 * Get the provider trace table name.
	 */
	public static function provider_trace_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_provider_trace';
	}


	/**
	 * Get the AI-generated plugins table name.
	 */
	public static function generated_plugins_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_generated_plugins';
	}

	/**
	 * Get the active jobs table name.
	 */
	public static function active_jobs_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_active_jobs';
	}

	/**
	 * Get the skill usage table name.
	 *
	 * Tracks which skills are loaded per session/model and records
	 * quality outcome signals (helpful/neutral/negative) for telemetry.
	 */
	public static function skill_usage_table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_skill_usage';
	}

	// ─── Schema Installation ──────────────────────────────────────────────────

	/**
	 * Install or upgrade the database table.
	 */
	public static function install(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// Migrate from old "ai_agent" naming if upgrading from pre-rename version.
		self::maybe_migrate_from_old_names();

		$installed_version = get_option( self::DB_VERSION_OPTION );

		if ( $installed_version === self::DB_VERSION ) {
			return;
		}

		$table                        = self::table_name();
		$usage_table                  = self::usage_table_name();
		$memories_table               = self::memories_table_name();
		$skills_table                 = self::skills_table_name();
		$custom_tools_table           = self::custom_tools_table_name();
		$automations_table            = self::automations_table_name();
		$automation_logs_table        = self::automation_logs_table_name();
		$event_automations_table      = self::event_automations_table_name();
		$conversation_templates_table = self::conversation_templates_table_name();
		$git_tracked_files_table      = self::git_tracked_files_table_name();
		$changes_log_table            = self::changes_log_table_name();
		$modified_files_table         = self::modified_files_table_name();
		$agents_table                 = self::agents_table_name();
		$shared_sessions_table        = self::shared_sessions_table_name();
		$benchmark_runs_table         = self::benchmark_runs_table_name();
		$benchmark_results_table      = self::benchmark_results_table_name();
		$provider_trace_table         = self::provider_trace_table_name();
		$generated_plugins_table      = self::generated_plugins_table_name();
		$active_jobs_table            = self::active_jobs_table_name();
		$skill_usage_table            = self::skill_usage_table_name();
		$charset                      = $wpdb->get_charset_collate();

		// Knowledge tables.
		$sql = KnowledgeDatabase::get_schema( $charset );

		// Webhook tables.
		$sql .= WebhookDatabase::get_schema( $charset );

		$sql .= "\n\nCREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			provider_id varchar(100) NOT NULL DEFAULT '',
			model_id varchar(100) NOT NULL DEFAULT '',
			messages longtext NOT NULL,
			tool_calls longtext NOT NULL,
			prompt_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			completion_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			pinned tinyint(1) NOT NULL DEFAULT 0,
			folder varchar(100) NOT NULL DEFAULT '',
			paused_state longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY updated_at (updated_at),
			KEY status_user (user_id, status, updated_at)
		) {$charset};

		CREATE TABLE {$usage_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			provider_id varchar(100) NOT NULL DEFAULT '',
			model_id varchar(100) NOT NULL DEFAULT '',
			prompt_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			completion_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			cost_usd decimal(10,6) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_session (user_id, session_id),
			KEY created_at (created_at),
			KEY model_id (model_id)
		) {$charset};

		CREATE TABLE {$memories_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			category varchar(50) NOT NULL DEFAULT 'general',
			content text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY category (category)
		) {$charset};

		CREATE TABLE {$skills_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			name varchar(255) NOT NULL,
			description text NOT NULL,
			content longtext NOT NULL,
			is_builtin tinyint(1) NOT NULL DEFAULT 0,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			version varchar(20) NOT NULL DEFAULT '',
			content_hash varchar(64) NOT NULL DEFAULT '',
			source_url varchar(2048) NOT NULL DEFAULT '',
			user_modified tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY enabled (enabled),
			KEY is_builtin (is_builtin)
		) {$charset};

		CREATE TABLE {$custom_tools_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			name varchar(255) NOT NULL,
			description text NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT 'http',
			config longtext NOT NULL,
			input_schema longtext NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY type (type),
			KEY enabled (enabled)
		) {$charset};

		CREATE TABLE {$automations_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text NOT NULL DEFAULT '',
			prompt longtext NOT NULL,
			schedule varchar(50) NOT NULL DEFAULT 'daily',
			cron_expression varchar(100) NOT NULL DEFAULT '',
			tool_profile varchar(100) NOT NULL DEFAULT '',
			max_iterations int(11) NOT NULL DEFAULT 10,
			enabled tinyint(1) NOT NULL DEFAULT 0,
			notification_channels longtext NOT NULL DEFAULT '',
			last_run_at datetime DEFAULT NULL,
			next_run_at datetime DEFAULT NULL,
			run_count int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY enabled (enabled),
			KEY schedule (schedule)
		) {$charset};

		CREATE TABLE {$automation_logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			automation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			trigger_type varchar(20) NOT NULL DEFAULT 'scheduled',
			trigger_name varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'success',
			reply longtext NOT NULL,
			tool_calls longtext NOT NULL,
			prompt_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			completion_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			error_message text NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY automation_id (automation_id),
			KEY trigger_type (trigger_type),
			KEY created_at (created_at)
		) {$charset};

		CREATE TABLE {$event_automations_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text NOT NULL DEFAULT '',
			hook_name varchar(255) NOT NULL,
			prompt_template longtext NOT NULL,
			conditions longtext NOT NULL,
			tool_profile varchar(100) NOT NULL DEFAULT '',
			max_iterations int(11) NOT NULL DEFAULT 5,
			enabled tinyint(1) NOT NULL DEFAULT 0,
			run_count int(11) NOT NULL DEFAULT 0,
			last_run_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY hook_name (hook_name),
			KEY enabled (enabled)
		) {$charset};

		CREATE TABLE {$conversation_templates_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			name varchar(255) NOT NULL,
			description text NOT NULL DEFAULT '',
			prompt longtext NOT NULL,
			category varchar(50) NOT NULL DEFAULT 'general',
			icon varchar(100) NOT NULL DEFAULT 'admin-comments',
			is_builtin tinyint(1) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY category (category),
			KEY is_builtin (is_builtin)
		) {$charset};

		CREATE TABLE {$git_tracked_files_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_path varchar(500) NOT NULL,
			file_type varchar(20) NOT NULL DEFAULT 'plugin',
			package_slug varchar(255) NOT NULL DEFAULT '',
			original_hash varchar(64) NOT NULL DEFAULT '',
			original_content longblob NOT NULL,
			current_hash varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'unchanged',
			tracked_at datetime NOT NULL,
			modified_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY file_path (file_path(255)),
			KEY package_slug (package_slug),
			KEY file_type (file_type),
			KEY status (status)
		) {$charset};

		CREATE TABLE {$changes_log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_type varchar(50) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_title varchar(255) NOT NULL DEFAULT '',
			ability_name varchar(100) NOT NULL DEFAULT '',
			field_name varchar(100) NOT NULL DEFAULT '',
			before_value longtext NOT NULL,
			after_value longtext NOT NULL,
			reverted tinyint(1) NOT NULL DEFAULT 0,
			reverted_at datetime DEFAULT NULL,
			revertable tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY user_id (user_id),
			KEY object_type_id (object_type, object_id),
			KEY reverted (reverted),
			KEY revertable (revertable),
			KEY created_at (created_at)
		) {$charset};

		CREATE TABLE {$modified_files_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			plugin_slug varchar(255) NOT NULL DEFAULT '',
			file_path varchar(1000) NOT NULL DEFAULT '',
			action varchar(20) NOT NULL DEFAULT 'write',
			session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			modified_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY plugin_slug (plugin_slug),
			KEY session_id (session_id),
			KEY modified_at (modified_at)
		) {$charset};

		CREATE TABLE {$agents_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			name varchar(255) NOT NULL,
			description text NOT NULL DEFAULT '',
			system_prompt longtext NOT NULL DEFAULT '',
			provider_id varchar(100) NOT NULL DEFAULT '',
			model_id varchar(100) NOT NULL DEFAULT '',
			tool_profile varchar(100) NOT NULL DEFAULT '',
			temperature decimal(3,2) DEFAULT NULL,
			max_iterations int(11) DEFAULT NULL,
			greeting text NOT NULL DEFAULT '',
			avatar_icon varchar(100) NOT NULL DEFAULT '',
			tier_1_tools longtext NOT NULL DEFAULT '',
			suggestions longtext NOT NULL DEFAULT '',
			is_builtin tinyint(1) NOT NULL DEFAULT 0,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY enabled (enabled)
		) {$charset};

		CREATE TABLE {$shared_sessions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			shared_by bigint(20) unsigned NOT NULL,
			shared_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY shared_by (shared_by)
		) {$charset};

		CREATE TABLE {$benchmark_runs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			suite_slug varchar(100) NOT NULL DEFAULT '',
			provider_id varchar(100) NOT NULL DEFAULT '',
			model_id varchar(100) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			total_questions int(11) unsigned NOT NULL DEFAULT 0,
			passed_questions int(11) unsigned NOT NULL DEFAULT 0,
			failed_questions int(11) unsigned NOT NULL DEFAULT 0,
			score decimal(5,2) NOT NULL DEFAULT 0,
			duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY suite_slug (suite_slug),
			KEY provider_model (provider_id, model_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};

		CREATE TABLE {$benchmark_results_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL DEFAULT 0,
			question_id varchar(100) NOT NULL DEFAULT '',
			category varchar(100) NOT NULL DEFAULT '',
			prompt longtext NOT NULL,
			answer longtext NOT NULL,
			assertions longtext NOT NULL,
			passed tinyint(1) NOT NULL DEFAULT 0,
			score decimal(5,2) NOT NULL DEFAULT 0,
			duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			error text NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY question_id (question_id),
			KEY category (category),
			KEY passed (passed),
			KEY created_at (created_at)
		) {$charset};


		CREATE TABLE {$provider_trace_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			provider_id varchar(100) NOT NULL DEFAULT '',
			model_id varchar(100) NOT NULL DEFAULT '',
			url varchar(2048) NOT NULL DEFAULT '',
			method varchar(10) NOT NULL DEFAULT 'POST',
			status_code int(11) NOT NULL DEFAULT 0,
			duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			cache_creation_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			cache_read_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			request_headers longtext NOT NULL,
			request_body longtext NOT NULL,
			response_headers longtext NOT NULL,
			response_body longtext NOT NULL,
			error text NOT NULL DEFAULT '',
			source varchar(10) NOT NULL DEFAULT 'http',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY provider_id (provider_id),
			KEY status_code (status_code),
			KEY source (source)
		) {$charset};

		CREATE TABLE {$generated_plugins_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			description text NOT NULL DEFAULT '',
			plan longtext NOT NULL DEFAULT '',
			plugin_file varchar(500) NOT NULL DEFAULT '',
			files longtext NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT 'installed',
			sandbox_result longtext NOT NULL DEFAULT '',
			activation_error text NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};

		CREATE TABLE {$active_jobs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			job_id varchar(36) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'processing',
			pending_tools longtext NOT NULL,
			tool_calls longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY session_id (session_id),
			KEY user_id_status (user_id, status)
		) {$charset};

		CREATE TABLE {$skill_usage_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			skill_id bigint(20) unsigned NOT NULL,
			session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			trigger_type varchar(20) NOT NULL DEFAULT 'auto',
			injected_tokens int(11) unsigned NOT NULL DEFAULT 0,
			outcome varchar(20) NOT NULL DEFAULT 'unknown',
			model_id varchar(100) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY skill_id (skill_id),
			KEY session_id (session_id),
			KEY trigger_type (trigger_type),
			KEY outcome (outcome),
			KEY model_id (model_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Add FULLTEXT index on memories table if not present.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query; table name from internal method.
		$ft_exists = $wpdb->get_var( "SHOW INDEX FROM {$memories_table} WHERE Key_name = 'ft_content'" );
		if ( ! $ft_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Required for fulltext index creation during install.
			$wpdb->query( "ALTER TABLE {$memories_table} ADD FULLTEXT KEY ft_content (content)" );
		}

		// Seed built-in skills.
		Skill::seed_builtins();

		// Seed built-in conversation templates.
		ConversationTemplate::seed_builtins();

		// Seed example custom tools.
		CustomTools::seed_examples();

		// Seed built-in agents (onboarding, general, content-creator, seo, ecommerce).
		Agent::seed_defaults();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	// ─── Session Delegates ────────────────────────────────────────────────────

	/**
	 * Create a new session.
	 *
	 * @param array<string, mixed> $data Session data: user_id, title, provider_id, model_id.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function create_session( array $data ) {
		return SessionRepository::create( $data );
	}

	/**
	 * Get a single session by ID.
	 *
	 * @param int $session_id Session ID.
	 * @return object|null Session row or null.
	 */
	public static function get_session( int $session_id ) {
		return SessionRepository::get( $session_id );
	}

	/**
	 * List sessions for a user (lightweight — no messages/tool_calls).
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $filters Optional filters: status, folder, search, pinned.
	 * @return list<object>|null Array of session summary objects.
	 */
	public static function list_sessions( int $user_id, array $filters = [] ): ?array {
		return SessionRepository::list( $user_id, $filters );
	}

	/**
	 * List distinct folders for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Array of folder name strings.
	 */
	public static function list_folders( int $user_id ): array {
		return SessionRepository::list_folders( $user_id );
	}

	/**
	 * Bulk update sessions.
	 *
	 * @param array<int|string, mixed> $session_ids Array of session IDs.
	 * @param int                      $user_id     User ID for ownership check.
	 * @param array<string, mixed>     $data        Fields to update (status, pinned, folder).
	 * @return int Number of rows affected.
	 */
	public static function bulk_update_sessions( array $session_ids, int $user_id, array $data ): int {
		return SessionRepository::bulk_update( $session_ids, $user_id, $data );
	}

	/**
	 * Permanently delete sessions in trash for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Number of rows deleted.
	 */
	public static function empty_trash( int $user_id ): int {
		return SessionRepository::empty_trash( $user_id );
	}

	/**
	 * Update session fields.
	 *
	 * @param int                  $session_id Session ID.
	 * @param array<string, mixed> $data       Fields to update.
	 * @return bool Whether the update succeeded.
	 */
	public static function update_session( int $session_id, array $data ): bool {
		return SessionRepository::update( $session_id, $data );
	}

	/**
	 * Delete a session.
	 *
	 * @param int $session_id Session ID.
	 * @return bool Whether the delete succeeded.
	 */
	public static function delete_session( int $session_id ): bool {
		return SessionRepository::delete( $session_id );
	}

	/**
	 * Update token usage for a session (accumulates).
	 *
	 * @param int $session_id       Session ID.
	 * @param int $prompt_tokens    Prompt tokens to add.
	 * @param int $completion_tokens Completion tokens to add.
	 * @return bool
	 */
	public static function update_session_tokens( int $session_id, int $prompt_tokens, int $completion_tokens ): bool {
		return SessionRepository::update_tokens( $session_id, $prompt_tokens, $completion_tokens );
	}

	/**
	 * Persist the paused agent-loop state for a session.
	 *
	 * @param int                  $session_id Session ID.
	 * @param array<string, mixed> $state      Serializable loop state.
	 * @return bool Whether the update succeeded.
	 */
	public static function save_paused_state( int $session_id, array $state ): bool {
		return SessionRepository::save_paused_state( $session_id, $state );
	}

	/**
	 * Load and clear the paused agent-loop state for a session.
	 *
	 * @param int $session_id Session ID.
	 * @return array<string, mixed>|null Paused state, or null if none.
	 */
	public static function load_and_clear_paused_state( int $session_id ): ?array {
		return SessionRepository::load_and_clear_paused_state( $session_id );
	}

	/**
	 * Append messages and tool calls to a session.
	 *
	 * @param int   $session_id Session ID.
	 * @param array $messages   New message arrays to append.
	 * @param array $tool_calls New tool call log entries to append.
	 * @return bool Whether the update succeeded.
	 *
	 * @phpstan-param list<mixed>                $messages
	 * @phpstan-param list<array<string, mixed>> $tool_calls
	 */
	public static function append_to_session( int $session_id, array $messages, array $tool_calls = [] ): bool {
		return SessionRepository::append( $session_id, $messages, $tool_calls );
	}

	// ─── Usage Delegates ──────────────────────────────────────────────────────

	/**
	 * Log a usage record.
	 *
	 * @param array<string, mixed> $data Usage data: user_id, session_id, provider_id, model_id, prompt_tokens, completion_tokens, cost_usd.
	 * @return int|false Inserted row ID or false.
	 */
	public static function log_usage( array $data ) {
		return UsageRepository::log( $data );
	}

	/**
	 * Get usage summary with optional filters.
	 *
	 * @param array<string, mixed> $filters Optional: user_id, period (7d, 30d, all), start_date, end_date.
	 * @return array<string, mixed> Summary with totals and per-model breakdown.
	 */
	public static function get_usage_summary( array $filters = [] ): array {
		return UsageRepository::get_summary( $filters );
	}

	// ─── Modified Files Delegates ─────────────────────────────────────────────

	/**
	 * Record a file modification by the AI agent.
	 *
	 * @param string $file_path  Relative path from wp-content (e.g. "plugins/my-plugin/file.php").
	 * @param string $action     The action performed: 'write' or 'edit'.
	 * @param int    $session_id Session ID (0 if not in a session).
	 * @param int    $user_id    User ID performing the action.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function record_modified_file( string $file_path, string $action = 'write', int $session_id = 0, int $user_id = 0 ) {
		return ModifiedFilesRepository::record( $file_path, $action, $session_id, $user_id );
	}

	/**
	 * Get a list of plugins that have been modified by the AI agent.
	 *
	 * @return list<object>
	 */
	public static function get_modified_plugins(): array {
		return ModifiedFilesRepository::get_modified_plugins();
	}

	/**
	 * Get all modified file records for a specific plugin slug.
	 *
	 * @param string $plugin_slug Plugin directory slug.
	 * @return list<object>
	 */
	public static function get_modified_files_for_plugin( string $plugin_slug ): array {
		return ModifiedFilesRepository::get_files_for_plugin( $plugin_slug );
	}

	/**
	 * Extract the plugin slug (directory name) from a wp-content-relative path.
	 *
	 * @param string $file_path Path relative to wp-content.
	 * @return string Plugin slug, or empty string if not inside a plugin directory.
	 */
	public static function extract_plugin_slug( string $file_path ): string {
		return ModifiedFilesRepository::extract_plugin_slug( $file_path );
	}

	// ─── Generated Plugins Delegates ─────────────────────────────────────────

	/**
	 * Insert a new generated plugin record.
	 *
	 * @param array<string, mixed> $data Plugin data: slug, description, plan, plugin_file, files, status, sandbox_result, activation_error.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function insert_generated_plugin( array $data ): int|false {
		return GeneratedPluginsRepository::insert( $data );
	}

	/**
	 * Update fields for a generated plugin record by slug.
	 *
	 * @param string               $slug Plugin slug.
	 * @param array<string, mixed> $data Fields to update.
	 * @return bool Whether the update succeeded.
	 */
	public static function update_generated_plugin( string $slug, array $data ): bool {
		return GeneratedPluginsRepository::update( $slug, $data );
	}

	/**
	 * Get a single generated plugin record by slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return object|null Plugin row or null.
	 */
	public static function get_generated_plugin( string $slug ): ?object {
		return GeneratedPluginsRepository::get( $slug );
	}

	/**
	 * List generated plugin records, optionally filtered by status.
	 *
	 * @param string $status Filter by status (e.g. 'installed', 'active'). Empty string = all.
	 * @return list<object>
	 */
	public static function list_generated_plugins( string $status = '' ): array {
		return GeneratedPluginsRepository::list( $status );
	}

	/**
	 * Update the status of a generated plugin by slug.
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $status New status value.
	 * @return bool Whether the update succeeded.
	 */
	public static function update_generated_plugin_status( string $slug, string $status ): bool {
		return GeneratedPluginsRepository::update_status( $slug, $status );
	}

	/**
	 * Delete a generated plugin record by slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return bool Whether the delete succeeded.
	 */
	public static function delete_generated_plugin_record( string $slug ): bool {
		return GeneratedPluginsRepository::delete( $slug );
	}

	// ─── Shared Sessions Delegates ────────────────────────────────────────────

	/**
	 * Share a session (make it visible to all admins).
	 *
	 * @param int $session_id Session ID to share.
	 * @param int $shared_by  User ID of the admin sharing the session.
	 * @return bool Whether the insert succeeded.
	 */
	public static function share_session( int $session_id, int $shared_by ): bool {
		return SessionRepository::share( $session_id, $shared_by );
	}

	/**
	 * Unshare a session (remove from shared sessions).
	 *
	 * @param int $session_id Session ID to unshare.
	 * @return bool Whether the delete succeeded.
	 */
	public static function unshare_session( int $session_id ): bool {
		return SessionRepository::unshare( $session_id );
	}

	/**
	 * Check whether a session is shared.
	 *
	 * @param int $session_id Session ID.
	 * @return object|null Shared session row (with shared_by, shared_at) or null.
	 */
	public static function get_shared_session( int $session_id ) {
		return SessionRepository::get_shared( $session_id );
	}

	/**
	 * List all shared sessions (full session rows + sharing metadata).
	 *
	 * @return list<object>|null Array of session rows with is_shared=1 and shared_by fields.
	 */
	public static function list_shared_sessions(): ?array {
		return SessionRepository::list_shared();
	}

	// ─── Legacy Migration ──────────────────────────────────────────────────────

	/**
	 * Migrate database tables, options, and cron hooks from the old "ai_agent" naming.
	 *
	 * This runs once on upgrade from the pre-rename plugin version. It detects old
	 * table names and options, renames/migrates them, then sets a flag so it won't
	 * run again.
	 */
	private static function maybe_migrate_from_old_names(): void {
		// Skip if migration already completed.
		if ( get_option( 'sd_ai_agent_migrated_from_ai_agent' ) ) {
			return;
		}

		// Skip if there's no old DB version option (fresh install, never had old plugin).
		$old_db_version = get_option( 'ai_agent_db_version' );
		if ( false === $old_db_version ) {
			return;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */

		// 1. Rename database tables.
		$old_tables = [
			'ai_agent_sessions',
			'ai_agent_usage',
			'ai_agent_memories',
			'ai_agent_skills',
			'ai_agent_custom_tools',
			'ai_agent_automations',
			'ai_agent_automation_logs',
			'ai_agent_event_automations',
			'ai_agent_knowledge_collections',
			'ai_agent_knowledge_sources',
			'ai_agent_knowledge_chunks',
		];

		foreach ( $old_tables as $old_suffix ) {
			$old_name = $wpdb->prefix . $old_suffix;
			$new_name = $wpdb->prefix . 'sd_' . $old_suffix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time migration rename.
			$table_exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $old_name )
			);

			if ( $table_exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time migration; table names from internal constants.
				$wpdb->query( "RENAME TABLE `{$old_name}` TO `{$new_name}`" );
			}
		}

		// 2. Migrate options.
		$option_map = [
			'ai_agent_db_version'          => self::DB_VERSION_OPTION,
			'ai_agent_settings'            => 'sd_ai_agent_settings',
			'ai_agent_claude_max_token'    => 'sd_ai_agent_claude_max_token',
			'ai_agent_tool_profiles'       => 'sd_ai_agent_tool_profiles',
			'ai_agent_custom_tools_seeded' => 'sd_ai_agent_custom_tools_seeded',
		];

		foreach ( $option_map as $old_key => $new_key ) {
			$old_value = get_option( $old_key );
			if ( false !== $old_value ) {
				update_option( $new_key, $old_value );
				delete_option( $old_key );
			}
		}

		// 3. Migrate cron hooks.
		$old_cron_hook = 'wp_ai_agent_reindex';
		$new_cron_hook = 'wp_sd_ai_agent_reindex';
		$timestamp     = wp_next_scheduled( $old_cron_hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $old_cron_hook );
			if ( ! wp_next_scheduled( $new_cron_hook ) ) {
				wp_schedule_event( time(), 'hourly', $new_cron_hook );
			}
		}

		// Mark migration as complete.
		update_option( 'sd_ai_agent_migrated_from_ai_agent', '1' );
	}
}

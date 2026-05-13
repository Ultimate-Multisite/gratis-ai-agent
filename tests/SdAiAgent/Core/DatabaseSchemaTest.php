<?php

declare(strict_types=1);
/**
 * Integration tests for Database schema creation, version tracking, and data persistence.
 *
 * These tests verify:
 * - All plugin tables are created on install (plugin activation).
 * - Schema version is stored and read back correctly.
 * - Re-running install() is idempotent (migration guard).
 * - dbDelta upgrades add new columns without data loss.
 * - Data written before a simulated upgrade survives the migration.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\Database;
use SdAiAgent\Knowledge\KnowledgeDatabase;
use WP_UnitTestCase;

/**
 * Integration tests for Database schema and migrations.
 *
 * Runs inside wp-env (real MySQL) so dbDelta, SHOW TABLES, and SHOW COLUMNS
 * all work as they would in production.
 */
class DatabaseSchemaTest extends WP_UnitTestCase {

	/**
	 * All expected table names (without prefix).
	 *
	 * @var string[]
	 */
	private const EXPECTED_TABLES = [
		'sd_ai_agent_sessions',
		'sd_ai_agent_usage',
		'sd_ai_agent_memories',
		'sd_ai_agent_skills',
		'sd_ai_agent_custom_tools',
		'sd_ai_agent_automations',
		'sd_ai_agent_automation_logs',
		'sd_ai_agent_event_automations',
		'sd_ai_agent_knowledge_collections',
		'sd_ai_agent_knowledge_sources',
		'sd_ai_agent_knowledge_chunks',
		'sd_ai_agent_webhooks',
		'sd_ai_agent_webhook_logs',
		'sd_ai_agent_conversation_templates',
		'sd_ai_agent_git_tracked_files',
		'sd_ai_agent_changes_log',
		'sd_ai_agent_modified_files',
		'sd_ai_agent_agents',
		'sd_ai_agent_shared_sessions',
		'sd_ai_agent_benchmark_runs',
		'sd_ai_agent_benchmark_results',
		'sd_ai_agent_provider_trace',
		'sd_ai_agent_generated_plugins',
		'sd_ai_agent_active_jobs',
		'sd_ai_agent_skill_usage',
	];

	/**
	 * Ensure a clean version option before each test so install() always runs.
	 */
	public function set_up(): void {
		parent::set_up();
		// Remove the version option so install() is not short-circuited.
		delete_option( Database::DB_VERSION_OPTION );
	}

	// ── Table creation ────────────────────────────────────────────────────

	/**
	 * All expected tables exist after install().
	 */
	public function test_install_creates_all_tables(): void {
		global $wpdb;

		Database::install();

		foreach ( self::EXPECTED_TABLES as $suffix ) {
			$table  = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only introspection query.
			$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
			$this->assertSame(
				$table,
				$exists,
				"Expected table '{$table}' to exist after install()."
			);
		}
	}

	/**
	 * Sessions table has the required columns.
	 */
	public function test_sessions_table_has_required_columns(): void {
		global $wpdb;

		Database::install();

		$table   = Database::table_name();
		$columns = $this->get_column_names( $table );

		$required = [
			'id',
			'user_id',
			'title',
			'provider_id',
			'model_id',
			'messages',
			'tool_calls',
			'prompt_tokens',
			'completion_tokens',
			'status',
			'pinned',
			'folder',
			'created_at',
			'updated_at',
		];

		foreach ( $required as $col ) {
			$this->assertContains(
				$col,
				$columns,
				"Sessions table missing column '{$col}'."
			);
		}
	}

	/**
	 * Usage table has the required columns.
	 */
	public function test_usage_table_has_required_columns(): void {
		Database::install();

		$columns = $this->get_column_names( Database::usage_table_name() );

		foreach ( [ 'id', 'user_id', 'session_id', 'provider_id', 'model_id', 'prompt_tokens', 'completion_tokens', 'cost_usd', 'created_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Usage table missing column '{$col}'." );
		}
	}

	/**
	 * Memories table has the required columns and FULLTEXT index.
	 */
	public function test_memories_table_has_fulltext_index(): void {
		global $wpdb;

		Database::install();

		$table = Database::memories_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only introspection query.
		$ft_exists = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'ft_content'" );
		$this->assertNotNull( $ft_exists, "Memories table should have FULLTEXT index 'ft_content'." );
	}

	/**
	 * Skills table has a UNIQUE KEY on slug.
	 */
	public function test_skills_table_has_unique_slug_index(): void {
		global $wpdb;

		Database::install();

		$table = Database::skills_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only introspection query.
		$unique_exists = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'slug' AND Non_unique = 0" );
		$this->assertNotNull( $unique_exists, "Skills table should have UNIQUE KEY on 'slug'." );
	}

	/**
	 * Skill usage telemetry table has the required columns and indexes.
	 */
	public function test_skill_usage_table_has_required_columns_and_indexes(): void {
		global $wpdb;

		Database::install();

		$table   = Database::skill_usage_table_name();
		$columns = $this->get_column_names( $table );

		foreach ( [ 'id', 'skill_id', 'session_id', 'trigger_type', 'injected_tokens', 'outcome', 'model_id', 'created_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Skill usage table missing column '{$col}'." );
		}

		foreach ( [ 'skill_id', 'session_id', 'trigger_type', 'outcome', 'model_id', 'created_at' ] as $index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only introspection query.
			$index_exists = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = '{$index}'" );
			$this->assertNotNull( $index_exists, "Skill usage table missing index '{$index}'." );
		}
	}

	/**
	 * Custom tools table has a UNIQUE KEY on slug.
	 */
	public function test_custom_tools_table_has_unique_slug_index(): void {
		global $wpdb;

		Database::install();

		$table = Database::custom_tools_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only introspection query.
		$unique_exists = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'slug' AND Non_unique = 0" );
		$this->assertNotNull( $unique_exists, "Custom tools table should have UNIQUE KEY on 'slug'." );
	}

	/**
	 * Automations table has the required columns.
	 */
	public function test_automations_table_has_required_columns(): void {
		Database::install();

		$columns = $this->get_column_names( Database::automations_table_name() );

		foreach ( [ 'id', 'name', 'description', 'prompt', 'schedule', 'enabled', 'last_run_at', 'next_run_at', 'run_count', 'created_at', 'updated_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Automations table missing column '{$col}'." );
		}
	}

	/**
	 * Automation logs table has the required columns.
	 */
	public function test_automation_logs_table_has_required_columns(): void {
		Database::install();

		$columns = $this->get_column_names( Database::automation_logs_table_name() );

		foreach ( [ 'id', 'automation_id', 'trigger_type', 'status', 'reply', 'tool_calls', 'prompt_tokens', 'completion_tokens', 'duration_ms', 'created_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Automation logs table missing column '{$col}'." );
		}
	}

	/**
	 * Event automations table has the required columns.
	 */
	public function test_event_automations_table_has_required_columns(): void {
		Database::install();

		$columns = $this->get_column_names( Database::event_automations_table_name() );

		foreach ( [ 'id', 'name', 'hook_name', 'prompt_template', 'conditions', 'enabled', 'run_count', 'last_run_at', 'created_at', 'updated_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Event automations table missing column '{$col}'." );
		}
	}

	/**
	 * Modified files table has the required columns.
	 */
	public function test_modified_files_table_has_required_columns(): void {
		Database::install();

		$columns = $this->get_column_names( Database::modified_files_table_name() );

		foreach ( [ 'id', 'plugin_slug', 'file_path', 'action', 'session_id', 'user_id', 'modified_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Modified files table missing column '{$col}'." );
		}
	}

	/**
	 * Benchmark tables have the required columns for run/result persistence.
	 */
	public function test_benchmark_tables_have_required_columns(): void {
		Database::install();

		$run_columns = $this->get_column_names( Database::benchmark_runs_table_name() );
		foreach (
			[
				'id',
				'suite_slug',
				'provider_id',
				'model_id',
				'status',
				'total_questions',
				'passed_questions',
				'failed_questions',
				'score',
				'duration_ms',
				'started_at',
				'completed_at',
				'created_at',
			] as $col
		) {
			$this->assertContains( $col, $run_columns, "Benchmark runs table missing column '{$col}'." );
		}

		$result_columns = $this->get_column_names( Database::benchmark_results_table_name() );
		foreach (
			[
				'id',
				'run_id',
				'question_id',
				'category',
				'prompt',
				'answer',
				'assertions',
				'passed',
				'score',
				'duration_ms',
				'error',
				'created_at',
			] as $col
		) {
			$this->assertContains( $col, $result_columns, "Benchmark results table missing column '{$col}'." );
		}
	}

	/**
	 * Knowledge tables are created with correct structure.
	 */
	public function test_knowledge_tables_have_required_columns(): void {
		Database::install();

		$collections_cols = $this->get_column_names( KnowledgeDatabase::collections_table() );
		foreach ( [ 'id', 'name', 'slug', 'description', 'status', 'chunk_count', 'created_at', 'updated_at' ] as $col ) {
			$this->assertContains( $col, $collections_cols, "Knowledge collections table missing column '{$col}'." );
		}

		$sources_cols = $this->get_column_names( KnowledgeDatabase::sources_table() );
		foreach ( [ 'id', 'collection_id', 'source_type', 'title', 'status', 'created_at', 'updated_at' ] as $col ) {
			$this->assertContains( $col, $sources_cols, "Knowledge sources table missing column '{$col}'." );
		}

		$chunks_cols = $this->get_column_names( KnowledgeDatabase::chunks_table() );
		foreach ( [ 'id', 'collection_id', 'source_id', 'chunk_index', 'chunk_text', 'created_at', 'updated_at' ] as $col ) {
			$this->assertContains( $col, $chunks_cols, "Knowledge chunks table missing column '{$col}'." );
		}
	}

	// ── Schema version tracking ───────────────────────────────────────────

	/**
	 * install() stores the current DB_VERSION in wp_options.
	 */
	public function test_install_stores_db_version(): void {
		Database::install();

		$stored = get_option( Database::DB_VERSION_OPTION );
		$this->assertSame(
			Database::DB_VERSION,
			$stored,
			'install() must persist DB_VERSION to wp_options.'
		);
	}

	/**
	 * DB_VERSION is a non-empty semver-like string.
	 */
	public function test_db_version_is_valid_string(): void {
		$this->assertNotEmpty( Database::DB_VERSION );
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			Database::DB_VERSION,
			'DB_VERSION should follow semver format (e.g. 8.0.0).'
		);
	}

	/**
	 * DB_VERSION_OPTION constant matches the expected option key.
	 */
	public function test_db_version_option_key(): void {
		$this->assertSame( 'sd_ai_agent_db_version', Database::DB_VERSION_OPTION );
	}

	// ── Migration guard (idempotency) ─────────────────────────────────────

	/**
	 * Calling install() twice does not raise errors or duplicate data.
	 *
	 * This simulates the migration guard: if the stored version already equals
	 * DB_VERSION, install() returns early without re-running dbDelta.
	 */
	public function test_install_is_idempotent(): void {
		Database::install();

		// Record how many built-in skills were seeded on first install.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only count query.
		$count_after_first = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::skills_table_name() )
		);

		// Second call — should be a no-op because version matches.
		Database::install();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only count query.
		$count_after_second = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::skills_table_name() )
		);

		$this->assertSame(
			$count_after_first,
			$count_after_second,
			'Second install() call must not re-seed data when version is current.'
		);
	}

	/**
	 * install() re-runs when the stored version is outdated (simulated upgrade).
	 *
	 * We store a fake old version, then call install() and verify the version
	 * is updated to the current DB_VERSION.
	 */
	public function test_install_runs_when_version_is_outdated(): void {
		// Simulate a previous install at an older version.
		update_option( Database::DB_VERSION_OPTION, '0.0.1' );

		Database::install();

		$stored = get_option( Database::DB_VERSION_OPTION );
		$this->assertSame(
			Database::DB_VERSION,
			$stored,
			'install() must update the stored version after running on an outdated schema.'
		);
	}

	/**
	 * install() skips execution when version is already current.
	 *
	 * We pre-set the version to DB_VERSION and verify install() returns without
	 * touching the database (no error, version unchanged).
	 */
	public function test_install_skips_when_version_is_current(): void {
		// Pre-set to current version.
		update_option( Database::DB_VERSION_OPTION, Database::DB_VERSION );

		// Should return early — no errors.
		Database::install();

		$stored = get_option( Database::DB_VERSION_OPTION );
		$this->assertSame( Database::DB_VERSION, $stored );
	}

	// ── Data persistence across simulated migration ───────────────────────

	/**
	 * Data written before a simulated upgrade survives the migration.
	 *
	 * Workflow:
	 * 1. Install at current version.
	 * 2. Write a session record.
	 * 3. Reset version to simulate an outdated schema.
	 * 4. Re-run install() (dbDelta upgrade path).
	 * 5. Verify the session record is still intact.
	 */
	public function test_data_persists_across_migration(): void {
		Database::install();

		$user_id    = self::factory()->user->create();
		$session_id = Database::create_session( [
			'user_id'     => $user_id,
			'title'       => 'Persistence Test',
			'provider_id' => 'anthropic',
			'model_id'    => 'claude-sonnet-4',
		] );

		$this->assertIsInt( $session_id, 'Session should be created before migration.' );

		// Simulate an outdated schema version to force install() to re-run.
		update_option( Database::DB_VERSION_OPTION, '0.0.1' );
		Database::install();

		// Data must survive the migration.
		$session = Database::get_session( $session_id );

		$this->assertNotNull( $session, 'Session must exist after migration.' );
		$this->assertSame( 'Persistence Test', $session->title );
		$this->assertSame( 'anthropic', $session->provider_id );
		$this->assertSame( 'claude-sonnet-4', $session->model_id );
	}

	/**
	 * Usage records persist across a simulated migration.
	 */
	public function test_usage_data_persists_across_migration(): void {
		Database::install();

		$user_id  = self::factory()->user->create();
		$usage_id = Database::log_usage( [
			'user_id'           => $user_id,
			'session_id'        => 0,
			'provider_id'       => 'openai',
			'model_id'          => 'gpt-4o',
			'prompt_tokens'     => 500,
			'completion_tokens' => 250,
			'cost_usd'          => 0.005,
		] );

		$this->assertIsInt( $usage_id, 'Usage record should be created before migration.' );

		// Simulate upgrade.
		update_option( Database::DB_VERSION_OPTION, '0.0.1' );
		Database::install();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				Database::usage_table_name(),
				$usage_id
			)
		);

		$this->assertNotNull( $row, 'Usage record must exist after migration.' );
		$this->assertSame( 'gpt-4o', $row->model_id );
		$this->assertSame( '500', $row->prompt_tokens );
	}

	/**
	 * Messages appended to a session persist across a simulated migration.
	 */
	public function test_session_messages_persist_across_migration(): void {
		Database::install();

		$user_id    = self::factory()->user->create();
		$session_id = Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'Message Persistence',
		] );

		Database::append_to_session( $session_id, [
			[ 'role' => 'user', 'content' => 'Hello, world!' ],
			[ 'role' => 'assistant', 'content' => 'Hi there!' ],
		] );

		// Simulate upgrade.
		update_option( Database::DB_VERSION_OPTION, '0.0.1' );
		Database::install();

		$session  = Database::get_session( $session_id );
		$messages = json_decode( $session->messages, true );

		$this->assertIsArray( $messages );
		$this->assertCount( 2, $messages );
		$this->assertSame( 'user', $messages[0]['role'] );
		$this->assertSame( 'Hello, world!', $messages[0]['content'] );
		$this->assertSame( 'assistant', $messages[1]['role'] );
	}

	// ── Table count ───────────────────────────────────────────────────────

	/**
	 * Exactly the expected number of plugin tables exist after install().
	 */
	public function test_install_creates_correct_table_count(): void {
		global $wpdb;

		Database::install();

		$prefix  = $wpdb->prefix . 'sd_ai_agent_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only introspection query.
		$tables  = $wpdb->get_col( "SHOW TABLES LIKE '{$prefix}%'" );

		$this->assertCount(
			count( self::EXPECTED_TABLES ),
			$tables,
			sprintf(
				'Expected %d plugin tables, found %d: %s',
				count( self::EXPECTED_TABLES ),
				count( $tables ),
				implode( ', ', $tables )
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Return the list of column names for a given table.
	 *
	 * @param string $table Fully-qualified table name (with prefix).
	 * @return string[]
	 */
	private function get_column_names( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only introspection query.
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );

		if ( ! $rows ) {
			return [];
		}

		return array_column( (array) $rows, 'Field' );
	}
}

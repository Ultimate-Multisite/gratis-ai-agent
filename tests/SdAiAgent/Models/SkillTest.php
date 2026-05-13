<?php

declare(strict_types=1);
/**
 * Unit tests for Skill model.
 *
 * Tests table_name(), get_all(), get(), get_by_slug(), create(), update(),
 * delete(), reset_builtin(), get_index_for_prompt(), seed_builtins(),
 * and get_builtin_definitions().
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\Skill;
use WP_UnitTestCase;

/**
 * Tests for Skill model.
 *
 * @since 1.1.0
 */
class SkillTest extends WP_UnitTestCase {

	/**
	 * IDs of skills created during tests, for cleanup.
	 *
	 * @var int[]
	 */
	private array $created_ids = [];

	/**
	 * Tear down: delete any skills created during the test.
	 */
	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->created_ids as $id ) {
			// Direct delete to bypass built-in guard.
			$wpdb->delete( Skill::table_name(), [ 'id' => $id ], [ '%d' ] );
		}
		$this->created_ids = [];
		parent::tear_down();
	}

	/**
	 * Helper: create a user skill and track its ID.
	 *
	 * @param array<string,mixed> $overrides
	 * @return int
	 */
	private function create_skill( array $overrides = [] ): int {
		$data = array_merge(
			[
				'slug'        => 'test-skill-' . uniqid(),
				'name'        => 'Test Skill',
				'description' => 'A test skill',
				'content'     => 'Skill content here.',
				'enabled'     => true,
			],
			$overrides
		);

		$id = Skill::create( $data );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->created_ids[] = $id;
		return $id;
	}

	// ─── table_name() ────────────────────────────────────────────────────────

	/**
	 * table_name() returns the correct prefixed table name.
	 */
	public function test_table_name_returns_correct_name(): void {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_skills';
		$this->assertSame( $expected, Skill::table_name() );
	}

	// ─── create() ────────────────────────────────────────────────────────────

	/**
	 * create() returns a positive integer ID on success.
	 */
	public function test_create_returns_positive_id(): void {
		$id = $this->create_skill();
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * create() stores all provided fields correctly.
	 */
	public function test_create_stores_fields(): void {
		$id = $this->create_skill( [
			'slug'        => 'stored-skill',
			'name'        => 'Stored Skill',
			'description' => 'A stored description',
			'content'     => 'Stored content',
			'enabled'     => true,
		] );

		$skill = Skill::get( $id );
		$this->assertNotNull( $skill );
		$this->assertSame( 'stored-skill', $skill->slug );
		$this->assertSame( 'Stored Skill', $skill->name );
		$this->assertSame( 'A stored description', $skill->description );
		$this->assertSame( 'Stored content', $skill->content );
		$this->assertTrue( $skill->enabled );
		$this->assertFalse( $skill->is_builtin );
	}

	/**
	 * create() defaults enabled to 1 when not provided.
	 */
	public function test_create_defaults_enabled_to_true(): void {
		$id    = $this->create_skill( [ 'slug' => 'default-enabled-skill' ] );
		$skill = Skill::get( $id );

		$this->assertNotNull( $skill );
		$this->assertTrue( $skill->enabled );
	}

	// ─── get() ───────────────────────────────────────────────────────────────

	/**
	 * get() returns null for a non-existent ID.
	 */
	public function test_get_returns_null_for_missing_id(): void {
		$result = Skill::get( 999999 );
		$this->assertNull( $result );
	}

	/**
	 * get() returns the correct skill for a valid ID.
	 */
	public function test_get_returns_correct_skill(): void {
		$id    = $this->create_skill( [ 'name' => 'Get Test Skill' ] );
		$skill = Skill::get( $id );

		$this->assertNotNull( $skill );
		$this->assertSame( 'Get Test Skill', $skill->name );
	}

	// ─── get_by_slug() ───────────────────────────────────────────────────────

	/**
	 * get_by_slug() returns null for an unknown slug.
	 */
	public function test_get_by_slug_returns_null_for_unknown_slug(): void {
		$result = Skill::get_by_slug( 'nonexistent-skill-xyz' );
		$this->assertNull( $result );
	}

	/**
	 * get_by_slug() returns the correct skill.
	 */
	public function test_get_by_slug_returns_correct_skill(): void {
		$id    = $this->create_skill( [ 'slug' => 'slug-lookup-skill' ] );
		$skill = Skill::get_by_slug( 'slug-lookup-skill' );

		$this->assertNotNull( $skill );
		$this->assertSame( (string) $id, (string) $skill->id );
	}

	// ─── get_all() ───────────────────────────────────────────────────────────

	/**
	 * get_all() returns an array.
	 */
	public function test_get_all_returns_array(): void {
		$result = Skill::get_all();
		$this->assertIsArray( $result );
	}

	/**
	 * get_all() includes newly created skills.
	 */
	public function test_get_all_includes_created_skill(): void {
		$id   = $this->create_skill( [ 'name' => 'Get All Skill' ] );
		$all  = Skill::get_all();
		$ids  = array_map( 'strval', array_column( $all, 'id' ) );

		$this->assertContains( (string) $id, $ids );
	}

	/**
	 * get_all( true ) returns only enabled skills.
	 */
	public function test_get_all_filters_by_enabled(): void {
		$enabled_id  = $this->create_skill( [ 'slug' => 'enabled-skill-filter', 'enabled' => true ] );
		$disabled_id = $this->create_skill( [ 'slug' => 'disabled-skill-filter', 'enabled' => false ] );

		$enabled = Skill::get_all( true );
		$ids     = array_map( 'strval', array_column( $enabled, 'id' ) );

		$this->assertContains( (string) $enabled_id, $ids );
		$this->assertNotContains( (string) $disabled_id, $ids );
	}

	/**
	 * get_all( false ) returns only disabled skills.
	 */
	public function test_get_all_filters_by_disabled(): void {
		$enabled_id  = $this->create_skill( [ 'slug' => 'enabled-skill-filter-2', 'enabled' => true ] );
		$disabled_id = $this->create_skill( [ 'slug' => 'disabled-skill-filter-2', 'enabled' => false ] );

		$disabled = Skill::get_all( false );
		$ids      = array_map( 'strval', array_column( $disabled, 'id' ) );

		$this->assertNotContains( (string) $enabled_id, $ids );
		$this->assertContains( (string) $disabled_id, $ids );
	}

	// ─── update() ────────────────────────────────────────────────────────────

	/**
	 * update() returns true and persists changes.
	 */
	public function test_update_persists_changes(): void {
		$id = $this->create_skill( [ 'name' => 'Original Skill Name' ] );

		$result = Skill::update( $id, [ 'name' => 'Updated Skill Name' ] );
		$this->assertTrue( $result );

		$skill = Skill::get( $id );
		$this->assertNotNull( $skill );
		$this->assertSame( 'Updated Skill Name', $skill->name );
	}

	/**
	 * update() ignores fields not in the allowed list.
	 */
	public function test_update_ignores_disallowed_fields(): void {
		$id = $this->create_skill( [ 'slug' => 'original-slug-skill' ] );

		// 'slug' is not in the allowed update list.
		Skill::update( $id, [ 'slug' => 'changed-slug', 'name' => 'Name Changed' ] );

		$skill = Skill::get( $id );
		$this->assertNotNull( $skill );
		$this->assertSame( 'original-slug-skill', $skill->slug );
		$this->assertSame( 'Name Changed', $skill->name );
	}

	/**
	 * update() can toggle enabled status.
	 */
	public function test_update_toggles_enabled(): void {
		$id = $this->create_skill( [ 'enabled' => true ] );

		Skill::update( $id, [ 'enabled' => false ] );

		$skill = Skill::get( $id );
		$this->assertNotNull( $skill );
		$this->assertFalse( $skill->enabled );
	}

	/**
	 * update() tracks built-in skill edits as admin customisations.
	 */
	public function test_update_marks_builtin_content_as_user_modified(): void {
		$id = $this->create_skill( [
			'slug'       => 'builtin-update-marker',
			'content'    => 'Original built-in content.',
			'is_builtin' => true,
		] );

		$result = Skill::update( $id, [ 'content' => 'Admin customised content.' ] );

		$this->assertTrue( $result );
		$skill = Skill::get( $id );
		$this->assertNotNull( $skill );
		$this->assertTrue( $skill->user_modified );
		$this->assertSame( hash( 'sha256', 'Admin customised content.' ), $skill->content_hash );
	}

	/**
	 * check_for_updates() returns a manifest entry only when remote content differs.
	 */
	public function test_check_for_updates_compares_remote_content_hash(): void {
		$id    = $this->create_skill( [ 'content' => 'Current content.' ] );
		$skill = Skill::get( $id );
		$this->assertNotNull( $skill );

		$this->assertNull( Skill::check_for_updates( $skill, [ 'content' => 'Current content.' ] ) );

		$update = [
			'content'    => 'Remote content.',
			'version'    => '1.2.3',
			'source_url' => 'https://example.com/skills/test.md',
		];

		$this->assertSame( $update, Skill::check_for_updates( $skill, $update ) );
	}

	/**
	 * apply_update() updates unmodified built-in skills without setting user_modified.
	 */
	public function test_apply_update_updates_unmodified_builtin_skill(): void {
		$id = $this->create_skill( [
			'slug'       => 'builtin-remote-update',
			'content'    => 'Original remote-managed content.',
			'is_builtin' => true,
		] );

		$result = Skill::apply_update(
			$id,
			[
				'content'    => 'Updated remote content.',
				'version'    => '2.0.0',
				'source_url' => 'https://example.com/skills/builtin-remote-update.md',
			]
		);

		$this->assertTrue( $result );
		$skill = Skill::get( $id );
		$this->assertNotNull( $skill );
		$this->assertSame( 'Updated remote content.', $skill->content );
		$this->assertSame( '2.0.0', $skill->version );
		$this->assertFalse( $skill->user_modified );
	}

	/**
	 * apply_update() does not overwrite admin-customised built-in skills.
	 */
	public function test_apply_update_skips_user_modified_builtin_skill(): void {
		$id = $this->create_skill( [
			'slug'          => 'builtin-customised-skip',
			'content'       => 'Admin custom content.',
			'is_builtin'    => true,
			'user_modified' => true,
		] );

		$result = Skill::apply_update(
			$id,
			[
				'content' => 'Remote replacement content.',
				'version' => '3.0.0',
			]
		);

		$this->assertFalse( $result );
		$skill = Skill::get( $id );
		$this->assertNotNull( $skill );
		$this->assertSame( 'Admin custom content.', $skill->content );
		$this->assertTrue( $skill->user_modified );
	}

	// ─── delete() ────────────────────────────────────────────────────────────

	/**
	 * delete() removes a user-created skill.
	 */
	public function test_delete_removes_user_skill(): void {
		$id = Skill::create( [
			'slug'    => 'delete-me-skill',
			'name'    => 'Delete Me',
			'content' => 'Content',
		] );
		$this->assertIsInt( $id );

		$result = Skill::delete( $id );
		$this->assertTrue( $result );

		$skill = Skill::get( $id );
		$this->assertNull( $skill );
	}

	/**
	 * delete() returns false for a non-existent skill.
	 */
	public function test_delete_returns_false_for_missing_skill(): void {
		$result = Skill::delete( 999999 );
		$this->assertFalse( $result );
	}

	/**
	 * delete() returns 'builtin' string for a built-in skill.
	 */
	public function test_delete_returns_builtin_string_for_builtin_skill(): void {
		// Create a skill flagged as built-in.
		$id = Skill::create( [
			'slug'       => 'builtin-skill-test',
			'name'       => 'Built-in Skill',
			'content'    => 'Content',
			'is_builtin' => true,
		] );
		$this->assertIsInt( $id );
		$this->created_ids[] = $id;

		$result = Skill::delete( $id );
		$this->assertSame( 'builtin', $result );

		// Verify it still exists.
		$skill = Skill::get( $id );
		$this->assertNotNull( $skill );
	}

	// ─── get_builtin_definitions() ───────────────────────────────────────────

	/**
	 * get_builtin_definitions() returns a non-empty array keyed by slug.
	 */
	public function test_get_builtin_definitions_returns_non_empty_array(): void {
		$definitions = Skill::get_builtin_definitions();

		$this->assertIsArray( $definitions );
		$this->assertNotEmpty( $definitions );
	}

	/**
	 * Each built-in definition has required keys.
	 */
	public function test_get_builtin_definitions_each_has_required_keys(): void {
		$definitions = Skill::get_builtin_definitions();

		foreach ( $definitions as $slug => $definition ) {
			$this->assertIsString( $slug );
			$this->assertArrayHasKey( 'name', $definition );
			$this->assertArrayHasKey( 'description', $definition );
			$this->assertArrayHasKey( 'content', $definition );
			$this->assertArrayHasKey( 'enabled', $definition );
		}
	}

	/**
	 * get_builtin_definitions() includes 'wordpress-admin' skill.
	 */
	public function test_get_builtin_definitions_includes_wordpress_admin(): void {
		$definitions = Skill::get_builtin_definitions();

		$this->assertArrayHasKey( 'wordpress-admin', $definitions );
	}

	/**
	 * get_builtin_definitions() includes the site-specification skill with
	 * the Phase 1 inference tables, worked examples, and site_brief guidance.
	 */
	public function test_get_builtin_definitions_includes_site_specification_acceptance_content(): void {
		$definitions = Skill::get_builtin_definitions();

		$this->assertArrayHasKey( 'site-specification', $definitions );

		$content = $definitions['site-specification']['content'];

		foreach ( [ 'SaaS', 'E-commerce', 'Professional Services', 'Restaurant', 'Portfolio', 'Blog', 'Non-profit' ] as $site_type ) {
			$this->assertStringContainsString( $site_type, $content );
		}

		foreach ( [ 'Coffee Shop', 'Law Firm', 'Esports Team' ] as $example ) {
			$this->assertStringContainsString( $example, $content );
		}

		$this->assertStringContainsString( 'category: site_brief', $content );
		$this->assertStringContainsString( 'sd-ai-agent/memory-save', $content );
	}

	// ─── seed_builtins() ─────────────────────────────────────────────────────

	/**
	 * seed_builtins() inserts built-in skills into the database.
	 */
	public function test_seed_builtins_inserts_skills(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM " . Skill::table_name() . " WHERE is_builtin = 1" );

		Skill::seed_builtins();

		$all = Skill::get_all();
		$this->assertNotEmpty( $all );

		$slugs = array_column( $all, 'slug' );
		$this->assertContains( 'wordpress-admin', $slugs );
	}

	/**
	 * seed_builtins() migrates a legacy `full-site-editing` row to `block-themes`
	 * in-place when block-themes does not yet exist.
	 */
	public function test_seed_builtins_migrates_full_site_editing_to_block_themes(): void {
		global $wpdb;
		// Start from a clean slate, then insert a legacy FSE row.
		$wpdb->query( "DELETE FROM " . Skill::table_name() . " WHERE is_builtin = 1" );
		$id = Skill::create(
			[
				'slug'        => 'full-site-editing',
				'name'        => 'Full Site Editing',
				'description' => 'Legacy FSE skill',
				'content'     => '# Legacy content with admin tweaks',
				'is_builtin'  => true,
				'enabled'     => true,
			]
		);
		$this->assertIsInt( $id );

		Skill::seed_builtins();

		// Legacy slug should be gone; block-themes should hold the original row id.
		$this->assertNull( Skill::get_by_slug( 'full-site-editing' ) );
		$migrated = Skill::get_by_slug( 'block-themes' );
		$this->assertNotNull( $migrated );
		$this->assertSame( (int) $id, (int) $migrated->id );

		// Cleanup so other tests see a fresh built-in seed.
		$wpdb->query( "DELETE FROM " . Skill::table_name() . " WHERE is_builtin = 1" );
		Skill::seed_builtins();
	}

	/**
	 * seed_builtins() does NOT migrate when both legacy and new slugs already exist.
	 */
	public function test_seed_builtins_skips_migration_when_new_slug_exists(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM " . Skill::table_name() . " WHERE is_builtin = 1" );
		Skill::create( [ 'slug' => 'full-site-editing', 'name' => 'Old', 'description' => 'Old', 'content' => 'old', 'is_builtin' => true, 'enabled' => true ] );
		Skill::create( [ 'slug' => 'block-themes', 'name' => 'New', 'description' => 'New', 'content' => 'new', 'is_builtin' => true, 'enabled' => true ] );

		Skill::seed_builtins();

		// Legacy row should remain untouched (migration must not collide).
		$this->assertNotNull( Skill::get_by_slug( 'full-site-editing' ) );
		$this->assertNotNull( Skill::get_by_slug( 'block-themes' ) );

		$wpdb->query( "DELETE FROM " . Skill::table_name() . " WHERE is_builtin = 1" );
		Skill::seed_builtins();
	}

	/**
	 * Theme-aware skills (block-themes / classic-themes) are auto-enabled
	 * based on the active theme even when their stored `enabled` flag is 0.
	 */
	public function test_theme_aware_skills_auto_enabled_from_active_theme(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM " . Skill::table_name() . " WHERE is_builtin = 1" );
		Skill::seed_builtins();

		// Force the relevant rows to enabled = 0 so we know auto-enable is doing the work.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Skill::table_name() . " SET enabled = 0 WHERE slug IN (%s, %s)", 'block-themes', 'classic-themes' ) );

		$is_block = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

		$block   = Skill::get_content_by_slug( 'block-themes' );
		$classic = Skill::get_content_by_slug( 'classic-themes' );

		if ( $is_block ) {
			$this->assertNotNull( $block, 'block-themes should auto-enable on block themes.' );
			$this->assertNull( $classic, 'classic-themes should NOT auto-enable on block themes.' );
		} else {
			$this->assertNull( $block, 'block-themes should NOT auto-enable on classic themes.' );
			$this->assertNotNull( $classic, 'classic-themes should auto-enable on classic themes.' );
		}
	}

	/**
	 * seed_builtins() is idempotent — calling twice does not duplicate.
	 */
	public function test_seed_builtins_is_idempotent(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM " . Skill::table_name() . " WHERE is_builtin = 1" );

		Skill::seed_builtins();
		$count_first = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . Skill::table_name() . " WHERE is_builtin = 1"
		);

		Skill::seed_builtins();
		$count_second = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . Skill::table_name() . " WHERE is_builtin = 1"
		);

		$this->assertSame( $count_first, $count_second );
	}

	// ─── reset_builtin() ─────────────────────────────────────────────────────

	/**
	 * reset_builtin() returns false for a non-existent skill.
	 */
	public function test_reset_builtin_returns_false_for_missing_skill(): void {
		$result = Skill::reset_builtin( 999999 );
		$this->assertFalse( $result );
	}

	/**
	 * reset_builtin() returns false for a user-created skill.
	 */
	public function test_reset_builtin_returns_false_for_user_skill(): void {
		$id     = $this->create_skill( [ 'name' => 'User Skill' ] );
		$result = Skill::reset_builtin( $id );

		$this->assertFalse( $result );
	}

	/**
	 * reset_builtin() restores original content for a built-in skill.
	 */
	public function test_reset_builtin_restores_original_content(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM " . Skill::table_name() . " WHERE is_builtin = 1" );
		Skill::seed_builtins();

		$skill = Skill::get_by_slug( 'wordpress-admin' );
		$this->assertNotNull( $skill );

		// Modify the content.
		Skill::update( (int) $skill->id, [ 'content' => 'Modified content' ] );

		// Reset it.
		$result = Skill::reset_builtin( (int) $skill->id );
		$this->assertTrue( $result );

		// Verify content was restored.
		$restored = Skill::get( (int) $skill->id );
		$this->assertNotNull( $restored );
		$this->assertNotSame( 'Modified content', $restored->content );
		$this->assertStringContainsString( 'WordPress Administration', $restored->content );
	}

	// ─── get_index_for_prompt() ──────────────────────────────────────────────

	/**
	 * get_index_for_prompt() returns empty string when no skills are enabled.
	 */
	public function test_get_index_for_prompt_returns_empty_when_no_enabled_skills(): void {
		global $wpdb;
		// Disable all skills temporarily.
		$wpdb->query( "UPDATE " . Skill::table_name() . " SET enabled = 0" );

		$result = Skill::get_index_for_prompt();
		$this->assertSame( '', $result );

		// Re-enable all.
		$wpdb->query( "UPDATE " . Skill::table_name() . " SET enabled = 1" );
	}

	/**
	 * get_index_for_prompt() returns a formatted string with enabled skills.
	 */
	public function test_get_index_for_prompt_returns_formatted_string(): void {
		$id = $this->create_skill( [
			'slug'        => 'prompt-index-skill',
			'name'        => 'Prompt Index Skill',
			'description' => 'For prompt index test',
			'enabled'     => true,
		] );

		$result = Skill::get_index_for_prompt();

		$this->assertIsString( $result );
		$this->assertStringContainsString( '## Available Skills', $result );
		$this->assertStringContainsString( 'prompt-index-skill', $result );
	}

	/**
	 * get_index_for_prompt() instructs the AI to use the skill-load tool.
	 */
	public function test_get_index_for_prompt_instructs_skill_load_tool(): void {
		// Ensure at least one skill is enabled.
		$this->create_skill( [
			'slug'    => 'tool-instruction-skill',
			'name'    => 'Tool Instruction Skill',
			'enabled' => true,
		] );

		$result = Skill::get_index_for_prompt();
		$this->assertStringContainsString( 'skill-load', $result );
	}

	/**
	 * get_index_for_prompt() excludes disabled skills.
	 */
	public function test_get_index_for_prompt_excludes_disabled_skills(): void {
		$this->create_skill( [
			'slug'    => 'included-in-prompt',
			'name'    => 'Included Skill',
			'enabled' => true,
		] );
		$this->create_skill( [
			'slug'    => 'excluded-from-prompt',
			'name'    => 'Excluded Skill',
			'enabled' => false,
		] );

		$result = Skill::get_index_for_prompt();

		$this->assertStringContainsString( 'included-in-prompt', $result );
		$this->assertStringNotContainsString( 'excluded-from-prompt', $result );
	}

	// ─── Built-in Skill Content Quality ──────────────────────────────────────

	/**
	 * Each built-in skill has content longer than 100 characters.
	 */
	public function test_builtin_skills_have_substantial_content(): void {
		$definitions = Skill::get_builtin_definitions();

		foreach ( $definitions as $slug => $definition ) {
			$this->assertGreaterThan(
				100,
				strlen( $definition['content'] ),
				"Built-in skill '$slug' content is too short."
			);
		}
	}

	/**
	 * Each built-in skill content contains markdown headings.
	 */
	public function test_builtin_skills_have_markdown_headings(): void {
		$definitions = Skill::get_builtin_definitions();

		foreach ( $definitions as $slug => $definition ) {
			$this->assertSame(
				1,
				preg_match( '/^#{1,6}\s+/m', $definition['content'] ),
				"Built-in skill '$slug' is missing markdown headings."
			);
		}
	}

	/**
	 * Each built-in skill content contains a "When to Use" section.
	 */
	public function test_builtin_skills_have_when_to_use_section(): void {
		$definitions = Skill::get_builtin_definitions();

		foreach ( $definitions as $slug => $definition ) {
			$this->assertStringContainsStringIgnoringCase(
				'When to Use',
				$definition['content'],
				"Built-in skill '$slug' is missing a 'When to Use' section."
			);
		}
	}

	/**
	 * Block themes skill covers the Phase 2 theme generation guidance.
	 */
	public function test_block_themes_skill_covers_phase_two_theme_generation_guidance(): void {
		$definitions = Skill::get_builtin_definitions();

		$this->assertArrayHasKey( 'block-themes', $definitions );

		$content = $definitions['block-themes']['content'];
		$lines   = preg_split( '/\R/', $content );

		$this->assertIsArray( $lines );
		$this->assertGreaterThanOrEqual( 350, count( $lines ) );
		$this->assertLessThanOrEqual( 500, count( $lines ) );

		foreach (
			[
				'theme.json Overview',
				'Template Parts',
				'wp:template-part',
				'The className pattern',
				'prefers-reduced-motion',
				'Editor Visibility',
				'editor-styles-wrapper',
				'enqueue_block_assets',
			] as $required_phrase
		) {
			$this->assertStringContainsString( $required_phrase, $content );
		}
	}

	/**
	 * Each built-in skill has a non-empty description.
	 */
	public function test_builtin_skills_have_non_empty_description(): void {
		$definitions = Skill::get_builtin_definitions();

		foreach ( $definitions as $slug => $definition ) {
			$this->assertNotEmpty(
				$definition['description'],
				"Built-in skill '$slug' has an empty description."
			);
		}
	}

	/**
	 * Built-in skill descriptions cover key domain terms for AI matching.
	 *
	 * Verifies that each skill's description contains at least one expected
	 * keyword so the AI model can match user prompts to the right skill.
	 *
	 * @dataProvider provide_builtin_skill_keywords
	 *
	 * @param string   $slug     Skill slug.
	 * @param string[] $keywords At least one of these must appear in the description.
	 */
	public function test_builtin_skill_description_contains_domain_keywords( string $slug, array $keywords ): void {
		$definitions = Skill::get_builtin_definitions();

		$this->assertArrayHasKey( $slug, $definitions, "Built-in skill '$slug' not found." );

		$desc  = strtolower( $definitions[ $slug ]['description'] );
		$found = false;
		foreach ( $keywords as $kw ) {
			if ( str_contains( $desc, strtolower( $kw ) ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			"Skill '$slug' description should contain at least one of: " . implode( ', ', $keywords )
		);
	}

	/**
	 * Data provider: built-in skill slugs with expected domain keywords.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function provide_builtin_skill_keywords(): array {
		return [
			'wordpress-admin'      => [ 'wordpress-admin', [ 'settings', 'users', 'updates', 'administration', 'options' ] ],
			'content-management'   => [ 'content-management', [ 'posts', 'pages', 'media', 'taxonomies', 'managing' ] ],
			'woocommerce'          => [ 'woocommerce', [ 'products', 'orders', 'store', 'woocommerce' ] ],
			'site-troubleshooting' => [ 'site-troubleshooting', [ 'errors', 'debug', 'health', 'performance', 'diagnosis' ] ],
			'multisite-management' => [ 'multisite-management', [ 'multisite', 'network' ] ],
			'seo-optimization'     => [ 'seo-optimization', [ 'seo', 'meta', 'optimization', 'on-page' ] ],
			'site-specification'   => [ 'site-specification', [ 'site spec', 'theme generation', 'audience', 'tone' ] ],
			'content-marketing'    => [ 'content-marketing', [ 'strategy', 'editorial', 'content', 'audit' ] ],
			'competitive-analysis' => [ 'competitive-analysis', [ 'competitor', 'analysis', 'tech stack' ] ],
			'analytics-reporting'  => [ 'analytics-reporting', [ 'performance', 'metrics', 'analytics', 'reporting' ] ],
			'gutenberg-blocks'     => [ 'gutenberg-blocks', [ 'blocks', 'gutenberg', 'markdown', 'layouts' ] ],
			'block-themes'         => [ 'block-themes', [ 'template', 'block theme', 'site editor', 'layout', 'theme.json' ] ],
			'classic-themes'       => [ 'classic-themes', [ 'classic', 'customizer', 'php template', 'widget', 'child theme' ] ],
			'kadence-blocks'       => [ 'kadence-blocks', [ 'kadence', 'rowlayout', 'advancedheading', 'validation' ] ],
			'kadence-theme'        => [ 'kadence-theme', [ 'kadence', 'header', 'footer', 'customizer', 'hooks' ] ],
		];
	}
}

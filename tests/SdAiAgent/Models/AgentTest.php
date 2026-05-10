<?php

declare(strict_types=1);
/**
 * Unit tests for Agent model.
 *
 * Tests CRUD operations, enabled filtering, loop option resolution,
 * and REST serialisation via to_array().
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\Agent;
use WP_UnitTestCase;

/**
 * Tests for Agent model.
 *
 * @since 1.1.0
 */
class AgentTest extends WP_UnitTestCase {

	/**
	 * IDs of agents created during tests, for cleanup.
	 *
	 * @var int[]
	 */
	private array $created_ids = [];

	/**
	 * Tear down: delete any agents created during the test.
	 */
	public function tear_down(): void {
		foreach ( $this->created_ids as $id ) {
			Agent::delete( $id );
		}
		$this->created_ids = [];
		parent::tear_down();
	}

	// ─── table_name() ────────────────────────────────────────────────────────

	/**
	 * table_name() returns the correct prefixed table name.
	 */
	public function test_table_name_returns_correct_name(): void {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_agents';
		$this->assertSame( $expected, Agent::table_name() );
	}

	// ─── create() ────────────────────────────────────────────────────────────

	/**
	 * create() returns a positive integer ID on success.
	 */
	public function test_create_returns_positive_id(): void {
		$id = Agent::create( [
			'slug' => 'test-agent',
			'name' => 'Test Agent',
		] );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->created_ids[] = $id;
	}

	/**
	 * create() stores all provided fields correctly.
	 */
	public function test_create_stores_all_fields(): void {
		$id = Agent::create( [
			'slug'           => 'full-agent',
			'name'           => 'Full Agent',
			'description'    => 'A full agent',
			'system_prompt'  => 'You are helpful.',
			'provider_id'    => 'openai',
			'model_id'       => 'gpt-4',
			'tool_profile'   => 'default',
			'temperature'    => 0.7,
			'max_iterations' => 10,
			'greeting'       => 'Hello!',
			'avatar_icon'    => 'admin-users',
			'enabled'        => true,
		] );

		$this->assertIsInt( $id );
		$this->created_ids[] = $id;

		$agent = Agent::get( $id );
		$this->assertNotNull( $agent );
		$this->assertSame( 'full-agent', $agent->slug );
		$this->assertSame( 'Full Agent', $agent->name );
		$this->assertSame( 'A full agent', $agent->description );
		$this->assertSame( 'You are helpful.', $agent->system_prompt );
		$this->assertSame( 'openai', $agent->provider_id );
		$this->assertSame( 'gpt-4', $agent->model_id );
		$this->assertSame( 'default', $agent->tool_profile );
		$this->assertSame( 'Hello!', $agent->greeting );
		$this->assertSame( 'admin-users', $agent->avatar_icon );
		$this->assertTrue( $agent->enabled );
	}

	/**
	 * create() defaults enabled to 1 when not provided.
	 */
	public function test_create_defaults_enabled_to_true(): void {
		$id = Agent::create( [ 'slug' => 'default-enabled', 'name' => 'Default Enabled' ] );
		$this->created_ids[] = $id;

		$agent = Agent::get( $id );
		$this->assertNotNull( $agent );
		$this->assertTrue( $agent->enabled );
	}

	// ─── get() ───────────────────────────────────────────────────────────────

	/**
	 * get() returns null for a non-existent ID.
	 */
	public function test_get_returns_null_for_missing_id(): void {
		$result = Agent::get( 999999 );
		$this->assertNull( $result );
	}

	/**
	 * get() returns the correct agent for a valid ID.
	 */
	public function test_get_returns_correct_agent(): void {
		$id = Agent::create( [ 'slug' => 'get-test', 'name' => 'Get Test' ] );
		$this->created_ids[] = $id;

		$agent = Agent::get( $id );
		$this->assertNotNull( $agent );
		$this->assertSame( 'Get Test', $agent->name );
	}

	// ─── get_by_slug() ───────────────────────────────────────────────────────

	/**
	 * get_by_slug() returns null for an unknown slug.
	 */
	public function test_get_by_slug_returns_null_for_unknown_slug(): void {
		$result = Agent::get_by_slug( 'nonexistent-slug-xyz' );
		$this->assertNull( $result );
	}

	/**
	 * get_by_slug() returns the correct agent.
	 */
	public function test_get_by_slug_returns_correct_agent(): void {
		$id = Agent::create( [ 'slug' => 'slug-lookup-test', 'name' => 'Slug Lookup' ] );
		$this->created_ids[] = $id;

		$agent = Agent::get_by_slug( 'slug-lookup-test' );
		$this->assertNotNull( $agent );
		$this->assertSame( (string) $id, (string) $agent->id );
	}

	// ─── get_all() ───────────────────────────────────────────────────────────

	/**
	 * get_all() returns an array.
	 */
	public function test_get_all_returns_array(): void {
		$result = Agent::get_all();
		$this->assertIsArray( $result );
	}

	/**
	 * get_all() includes newly created agents.
	 */
	public function test_get_all_includes_created_agent(): void {
		$id = Agent::create( [ 'slug' => 'get-all-test', 'name' => 'Get All Test' ] );
		$this->created_ids[] = $id;

		$agents = Agent::get_all();
		$ids    = array_column( $agents, 'id' );
		$this->assertContains( (string) $id, array_map( 'strval', $ids ) );
	}

	/**
	 * get_all( true ) returns only enabled agents.
	 */
	public function test_get_all_filters_by_enabled(): void {
		$enabled_id  = Agent::create( [ 'slug' => 'enabled-agent', 'name' => 'Enabled', 'enabled' => true ] );
		$disabled_id = Agent::create( [ 'slug' => 'disabled-agent', 'name' => 'Disabled', 'enabled' => false ] );
		$this->created_ids[] = $enabled_id;
		$this->created_ids[] = $disabled_id;

		$enabled_agents = Agent::get_all( true );
		$ids            = array_map( 'strval', array_column( $enabled_agents, 'id' ) );

		$this->assertContains( (string) $enabled_id, $ids );
		$this->assertNotContains( (string) $disabled_id, $ids );
	}

	/**
	 * get_all( false ) returns only disabled agents.
	 */
	public function test_get_all_filters_by_disabled(): void {
		$enabled_id  = Agent::create( [ 'slug' => 'enabled-agent-2', 'name' => 'Enabled 2', 'enabled' => true ] );
		$disabled_id = Agent::create( [ 'slug' => 'disabled-agent-2', 'name' => 'Disabled 2', 'enabled' => false ] );
		$this->created_ids[] = $enabled_id;
		$this->created_ids[] = $disabled_id;

		$disabled_agents = Agent::get_all( false );
		$ids             = array_map( 'strval', array_column( $disabled_agents, 'id' ) );

		$this->assertNotContains( (string) $enabled_id, $ids );
		$this->assertContains( (string) $disabled_id, $ids );
	}

	// ─── update() ────────────────────────────────────────────────────────────

	/**
	 * update() returns true and persists changes.
	 */
	public function test_update_persists_changes(): void {
		$id = Agent::create( [ 'slug' => 'update-test', 'name' => 'Original Name' ] );
		$this->created_ids[] = $id;

		$result = Agent::update( $id, [ 'name' => 'Updated Name' ] );
		$this->assertTrue( $result );

		$agent = Agent::get( $id );
		$this->assertNotNull( $agent );
		$this->assertSame( 'Updated Name', $agent->name );
	}

	/**
	 * update() ignores fields not in the allowed list.
	 */
	public function test_update_ignores_disallowed_fields(): void {
		$id = Agent::create( [ 'slug' => 'disallowed-test', 'name' => 'Disallowed Test' ] );
		$this->created_ids[] = $id;

		// 'slug' is not in the allowed update list.
		Agent::update( $id, [ 'slug' => 'changed-slug', 'name' => 'Name Changed' ] );

		$agent = Agent::get( $id );
		$this->assertNotNull( $agent );
		$this->assertSame( 'disallowed-test', $agent->slug );
		$this->assertSame( 'Name Changed', $agent->name );
	}

	/**
	 * update() can toggle enabled status.
	 */
	public function test_update_toggles_enabled(): void {
		$id = Agent::create( [ 'slug' => 'toggle-test', 'name' => 'Toggle Test', 'enabled' => true ] );
		$this->created_ids[] = $id;

		Agent::update( $id, [ 'enabled' => false ] );

		$agent = Agent::get( $id );
		$this->assertNotNull( $agent );
		$this->assertFalse( $agent->enabled );
	}

	// ─── delete() ────────────────────────────────────────────────────────────

	/**
	 * delete() removes the agent from the database.
	 */
	public function test_delete_removes_agent(): void {
		$id = Agent::create( [ 'slug' => 'delete-test', 'name' => 'Delete Test' ] );

		$result = Agent::delete( $id );
		$this->assertTrue( $result );

		$agent = Agent::get( $id );
		$this->assertNull( $agent );
	}

	/**
	 * delete() returns false for a non-existent ID.
	 */
	public function test_delete_returns_false_for_missing_id(): void {
		$result = Agent::delete( 999999 );
		$this->assertFalse( $result );
	}

	// ─── get_loop_options() ──────────────────────────────────────────────────

	/**
	 * get_loop_options() returns empty array for a non-existent agent.
	 */
	public function test_get_loop_options_returns_empty_for_missing_agent(): void {
		$options = Agent::get_loop_options( 999999 );
		$this->assertSame( [], $options );
	}

	/**
	 * get_loop_options() returns empty array for a disabled agent.
	 */
	public function test_get_loop_options_returns_empty_for_disabled_agent(): void {
		$id = Agent::create( [
			'slug'          => 'disabled-loop',
			'name'          => 'Disabled Loop',
			'system_prompt' => 'Some prompt',
			'enabled'       => false,
		] );
		$this->created_ids[] = $id;

		$options = Agent::get_loop_options( $id );
		$this->assertSame( [], $options );
	}

	/**
	 * get_loop_options() returns only non-empty fields for an enabled agent.
	 */
	public function test_get_loop_options_returns_non_empty_fields(): void {
		$id = Agent::create( [
			'slug'           => 'loop-options-test',
			'name'           => 'Loop Options Test',
			'system_prompt'  => 'Custom system prompt',
			'provider_id'    => 'anthropic',
			'model_id'       => 'claude-3',
			'tool_profile'   => 'minimal',
			'temperature'    => 0.5,
			'max_iterations' => 5,
			'enabled'        => true,
		] );
		$this->created_ids[] = $id;

		$options = Agent::get_loop_options( $id );

		$this->assertArrayHasKey( 'agent_system_prompt', $options );
		$this->assertSame( 'Custom system prompt', $options['agent_system_prompt'] );
		$this->assertArrayHasKey( 'provider_id', $options );
		$this->assertSame( 'anthropic', $options['provider_id'] );
		$this->assertArrayHasKey( 'model_id', $options );
		$this->assertSame( 'claude-3', $options['model_id'] );
		$this->assertArrayNotHasKey( 'active_tool_profile', $options, 'tool profiles have been removed' );
		$this->assertArrayHasKey( 'temperature', $options );
		$this->assertEqualsWithDelta( 0.5, $options['temperature'], 0.001 );
		$this->assertArrayHasKey( 'max_iterations', $options );
		$this->assertSame( 5, $options['max_iterations'] );
	}

	/**
	 * get_loop_options() omits empty optional fields.
	 */
	public function test_get_loop_options_omits_empty_fields(): void {
		$id = Agent::create( [
			'slug'    => 'sparse-agent',
			'name'    => 'Sparse Agent',
			'enabled' => true,
		] );
		$this->created_ids[] = $id;

		$options = Agent::get_loop_options( $id );

		$this->assertArrayNotHasKey( 'agent_system_prompt', $options );
		$this->assertArrayNotHasKey( 'provider_id', $options );
		$this->assertArrayNotHasKey( 'model_id', $options );
		$this->assertArrayNotHasKey( 'active_tool_profile', $options );
		$this->assertArrayNotHasKey( 'temperature', $options );
		$this->assertArrayNotHasKey( 'max_iterations', $options );
	}

	// ─── to_array() ──────────────────────────────────────────────────────────

	/**
	 * to_array() returns all expected keys with correct types.
	 */
	public function test_to_array_returns_expected_keys(): void {
		$id = Agent::create( [
			'slug'           => 'to-array-test',
			'name'           => 'To Array Test',
			'description'    => 'Desc',
			'system_prompt'  => 'Prompt',
			'provider_id'    => 'openai',
			'model_id'       => 'gpt-4',
			'tool_profile'   => 'default',
			'temperature'    => 0.8,
			'max_iterations' => 3,
			'greeting'       => 'Hi',
			'avatar_icon'    => 'admin-users',
			'enabled'        => true,
		] );
		$this->created_ids[] = $id;

		$agent = Agent::get( $id );
		$this->assertNotNull( $agent );

		$arr = Agent::to_array( $agent );

		$this->assertIsInt( $arr['id'] );
		$this->assertSame( 'to-array-test', $arr['slug'] );
		$this->assertSame( 'To Array Test', $arr['name'] );
		$this->assertSame( 'Desc', $arr['description'] );
		$this->assertSame( 'Prompt', $arr['system_prompt'] );
		$this->assertSame( 'openai', $arr['provider_id'] );
		$this->assertSame( 'gpt-4', $arr['model_id'] );
		$this->assertSame( 'default', $arr['tool_profile'] );
		$this->assertIsFloat( $arr['temperature'] );
		$this->assertIsInt( $arr['max_iterations'] );
		$this->assertSame( 'Hi', $arr['greeting'] );
		$this->assertSame( 'admin-users', $arr['avatar_icon'] );
		$this->assertTrue( $arr['enabled'] );
		$this->assertArrayHasKey( 'created_at', $arr );
		$this->assertArrayHasKey( 'updated_at', $arr );
	}

	/**
	 * to_array() returns null for temperature and max_iterations when not set.
	 */
	public function test_to_array_returns_null_for_unset_numeric_fields(): void {
		$id = Agent::create( [ 'slug' => 'null-fields', 'name' => 'Null Fields' ] );
		$this->created_ids[] = $id;

		$agent = Agent::get( $id );
		$this->assertNotNull( $agent );

		$arr = Agent::to_array( $agent );

		$this->assertNull( $arr['temperature'] );
		$this->assertNull( $arr['max_iterations'] );
	}

	// ─── built-in definitions ───────────────────────────────────────────────

	/**
	 * General agent must include the post-iteration abilities its system
	 * prompt directs the model to call.
	 *
	 * Regression test for #1295: General agent's prompt told the model to
	 * iterate via update-post / update-global-styles, but those abilities
	 * were absent from tier_1_tools. The resolver rejected the calls, the
	 * model looped, max_iterations was depleted, and pages came out empty.
	 */
	public function test_general_tier_1_tools_includes_post_iteration_abilities(): void {
		$tools = Agent::get_general_tier_1_tools();

		$this->assertContains( 'sd-ai-agent/create-post', $tools );
		$this->assertContains( 'sd-ai-agent/update-post', $tools );
		$this->assertContains( 'sd-ai-agent/list-posts', $tools );
		$this->assertContains( 'sd-ai-agent/update-global-styles', $tools );
	}

	/**
	 * Every ability mentioned in a built-in agent's system prompt must be
	 * present in that agent's tier_1_tools (or in the meta-tools that the
	 * resolver always exposes). Catches drift between prompt and tool surface
	 * — see #1295.
	 */
	public function test_builtin_agent_prompts_only_reference_their_own_tools(): void {
		$reflection = new \ReflectionClass( Agent::class );
		$method     = $reflection->getMethod( 'get_builtin_definitions' );
		$method->setAccessible( true );
		$definitions = $method->invoke( null );

		$this->assertIsArray( $definitions );

		foreach ( $definitions as $def ) {
			$slug          = $def['slug'];
			$prompt        = $def['system_prompt'];
			$tier_1        = $def['tier_1_tools'];

			// Extract every ability mentioned in the prompt: matches
			// `sd-ai-agent/<name>` inside backticks, with optional trailing
			// punctuation. Excludes plain words like "sd-ai-agent" alone.
			preg_match_all(
				'#`(sd-ai-agent/[a-z0-9-]+)`#',
				$prompt,
				$matches
			);

			$mentioned = array_values( array_unique( $matches[1] ) );

			foreach ( $mentioned as $ability ) {
				$this->assertContains(
					$ability,
					$tier_1,
					sprintf(
						"Built-in agent '%s' system prompt references `%s` but it is not in tier_1_tools. "
						. 'Either add it to tier_1_tools or stop telling the model to call it (see #1295).',
						$slug,
						$ability
					)
				);
			}
		}
	}
}

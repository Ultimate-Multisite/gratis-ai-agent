<?php
/**
 * Tests for EventTriggerRegistry.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\EventTriggerRegistry;
use WP_UnitTestCase;

/**
 * Test EventTriggerRegistry functionality.
 */
class EventTriggerRegistryTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// get_all
	// -------------------------------------------------------------------------

	/**
	 * Test get_all returns a non-empty array.
	 */
	public function test_get_all_returns_array(): void {
		$triggers = EventTriggerRegistry::get_all();

		$this->assertIsArray( $triggers );
		$this->assertNotEmpty( $triggers );
	}

	/**
	 * Test each trigger has required keys.
	 */
	public function test_get_all_triggers_have_required_keys(): void {
		$triggers = EventTriggerRegistry::get_all();

		foreach ( $triggers as $trigger ) {
			$this->assertArrayHasKey( 'hook_name', $trigger );
			$this->assertArrayHasKey( 'label', $trigger );
			$this->assertArrayHasKey( 'description', $trigger );
			$this->assertArrayHasKey( 'category', $trigger );
			$this->assertArrayHasKey( 'args', $trigger );
			$this->assertArrayHasKey( 'placeholders', $trigger );
			$this->assertArrayHasKey( 'conditions', $trigger );
		}
	}

	/**
	 * Test all hook_names are non-empty strings.
	 */
	public function test_get_all_hook_names_are_strings(): void {
		$triggers = EventTriggerRegistry::get_all();

		foreach ( $triggers as $trigger ) {
			$this->assertIsString( $trigger['hook_name'] );
			$this->assertNotEmpty( $trigger['hook_name'] );
		}
	}

	/**
	 * Test all args are arrays.
	 */
	public function test_get_all_args_are_arrays(): void {
		$triggers = EventTriggerRegistry::get_all();

		foreach ( $triggers as $trigger ) {
			$this->assertIsArray( $trigger['args'], "args for {$trigger['hook_name']} should be array" );
		}
	}

	/**
	 * Test all categories are non-empty strings.
	 */
	public function test_get_all_categories_are_strings(): void {
		$triggers = EventTriggerRegistry::get_all();

		foreach ( $triggers as $trigger ) {
			$this->assertIsString( $trigger['category'] );
			$this->assertNotEmpty( $trigger['category'] );
		}
	}

	/**
	 * Test get_all includes core WordPress triggers.
	 */
	public function test_get_all_includes_wordpress_triggers(): void {
		$triggers   = EventTriggerRegistry::get_all();
		$hook_names = array_column( $triggers, 'hook_name' );

		$expected_hooks = [
			'transition_post_status',
			'user_register',
			'wp_login',
			'comment_post',
			'delete_post',
		];

		foreach ( $expected_hooks as $hook ) {
			$this->assertContains( $hook, $hook_names, "Expected hook '{$hook}' not found in registry" );
		}
	}

	/**
	 * Test get_all is filterable via sd_ai_agent_event_triggers.
	 */
	public function test_get_all_is_filterable(): void {
		$custom_trigger = [
			'hook_name'    => 'my_custom_hook',
			'label'        => 'Custom Hook',
			'description'  => 'A custom hook for testing',
			'category'     => 'other',
			'args'         => [ 'data' ],
			'placeholders' => [],
			'conditions'   => [],
		];

		add_filter(
			'sd_ai_agent_event_triggers',
			function ( $triggers ) use ( $custom_trigger ) {
				$triggers[] = $custom_trigger;
				return $triggers;
			}
		);

		$triggers   = EventTriggerRegistry::get_all();
		$hook_names = array_column( $triggers, 'hook_name' );

		$this->assertContains( 'my_custom_hook', $hook_names );
	}

	// -------------------------------------------------------------------------
	// get_grouped
	// -------------------------------------------------------------------------

	/**
	 * Test get_grouped returns array keyed by category.
	 */
	public function test_get_grouped_returns_categories(): void {
		$grouped = EventTriggerRegistry::get_grouped();

		$this->assertIsArray( $grouped );
		$this->assertNotEmpty( $grouped );
		$this->assertArrayHasKey( 'wordpress', $grouped );
	}

	/**
	 * Test each group has label and triggers keys.
	 */
	public function test_get_grouped_group_structure(): void {
		$grouped = EventTriggerRegistry::get_grouped();

		foreach ( $grouped as $category => $group ) {
			$this->assertArrayHasKey( 'label', $group, "Group '{$category}' missing 'label'" );
			$this->assertArrayHasKey( 'triggers', $group, "Group '{$category}' missing 'triggers'" );
			$this->assertIsArray( $group['triggers'] );
			$this->assertNotEmpty( $group['triggers'] );
		}
	}

	/**
	 * Test wordpress group has a human-readable label.
	 */
	public function test_get_grouped_wordpress_label(): void {
		$grouped = EventTriggerRegistry::get_grouped();

		$this->assertSame( 'WordPress', $grouped['wordpress']['label'] );
	}

	// -------------------------------------------------------------------------
	// get (single trigger lookup)
	// -------------------------------------------------------------------------

	/**
	 * Test get returns trigger definition for known hook.
	 */
	public function test_get_known_hook(): void {
		$trigger = EventTriggerRegistry::get( 'transition_post_status' );

		$this->assertNotNull( $trigger );
		$this->assertSame( 'transition_post_status', $trigger['hook_name'] );
	}

	/**
	 * Test get returns null for unknown hook.
	 */
	public function test_get_unknown_hook(): void {
		$this->assertNull( EventTriggerRegistry::get( 'nonexistent_hook_xyz' ) );
	}

	/**
	 * Test get returns correct args for transition_post_status.
	 */
	public function test_get_transition_post_status_args(): void {
		$trigger = EventTriggerRegistry::get( 'transition_post_status' );

		$this->assertContains( 'new_status', $trigger['args'] );
		$this->assertContains( 'old_status', $trigger['args'] );
		$this->assertContains( 'post', $trigger['args'] );
	}

	/**
	 * Test get returns correct args for user_register.
	 */
	public function test_get_user_register_args(): void {
		$trigger = EventTriggerRegistry::get( 'user_register' );

		$this->assertNotNull( $trigger );
		$this->assertContains( 'user_id', $trigger['args'] );
	}

	/**
	 * Test get returns correct args for comment_post.
	 */
	public function test_get_comment_post_args(): void {
		$trigger = EventTriggerRegistry::get( 'comment_post' );

		$this->assertNotNull( $trigger );
		$this->assertContains( 'comment_id', $trigger['args'] );
	}

	/**
	 * Test get returns correct category for WordPress triggers.
	 */
	public function test_get_wordpress_category(): void {
		$hooks = [
			'transition_post_status',
			'user_register',
			'wp_login',
			'comment_post',
			'delete_post',
		];

		foreach ( $hooks as $hook ) {
			$trigger = EventTriggerRegistry::get( $hook );
			$this->assertSame( 'wordpress', $trigger['category'], "Hook '{$hook}' should be in 'wordpress' category" );
		}
	}

	/** Monitor wakes use a fixed allowlist rather than the filterable event catalog. */
	public function test_monitor_wake_sources_are_fixed_and_only_expose_safe_identifiers(): void {
		$sources = EventTriggerRegistry::get_monitor_wake_sources();

		$this->assertSame(
			[
				'transition_post_status',
				'delete_post',
				'activated_plugin',
				'deactivated_plugin',
				'switch_theme',
				'add_attachment',
			],
			array_column( $sources, 'hook_name' )
		);
		$this->assertSame( [ 'post_id' ], $sources[1]['args'] );
		$this->assertSame( [], $sources[2]['args'] );
		$this->assertFalse( EventTriggerRegistry::is_monitor_wake_source( 'user_register' ) );

		$summary = EventTriggerRegistry::summarize_monitor_wake( 'delete_post', [ 42, 'unretained-input' ] );
		$this->assertSame(
			[
				'source'      => 'delete_post',
				'identifiers' => [ 'post_id' => 42 ],
			],
			$summary
		);
		$this->assertStringNotContainsString( 'unretained-input', (string) wp_json_encode( $summary ) );
	}
}

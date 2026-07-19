<?php
/**
 * Test case for the rewritten ToolDiscovery auto-discovery layer.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Tools;

use SdAiAgent\Abilities\ToolCapabilities;
use SdAiAgent\Core\IdenticalFailureTracker;
use SdAiAgent\Core\RolePermissions;
use SdAiAgent\Core\ToolPermissionResolver;
use SdAiAgent\Tools\AbilityUsageTracker;
use SdAiAgent\Tools\ToolDiscovery;
use WP_UnitTestCase;

class ToolDiscoveryTest extends WP_UnitTestCase {

	private int $admin_id = 0;

	/**
	 * Ability ids registered via {@see self::register_test_ability()} during a
	 * test, so tear_down can unregister them and prevent bleed-over.
	 *
	 * @var string[]
	 */
	private array $registered_test_abilities = [];

	/**
	 * Ability category slugs registered via {@see self::ensure_test_category()}
	 * during a test, so tear_down can unregister them and prevent bleed-over.
	 *
	 * @var string[]
	 */
	private array $registered_test_categories = [];

	public function set_up(): void {
		parent::set_up();
		AbilityUsageTracker::reset();
		ToolDiscovery::reset_schema_cache();
		ToolDiscovery::reset_keyword_search_state();
		IdenticalFailureTracker::reset();

		// Most abilities require admin caps in their permission callbacks.
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
		grant_super_admin( $this->admin_id );
	}

	public function tear_down(): void {
		// Clean up any abilities registered during the test BEFORE the parent
		// teardown removes the user / filter context they depend on.
		if ( function_exists( 'wp_unregister_ability' ) ) {
			foreach ( $this->registered_test_abilities as $ability_id ) {
				wp_unregister_ability( $ability_id );
			}
		}
		$this->registered_test_abilities = [];

		// Unregister any test categories we created, after the abilities that
		// referenced them are gone.
		if ( function_exists( 'wp_unregister_ability_category' ) ) {
			foreach ( $this->registered_test_categories as $category_slug ) {
				wp_unregister_ability_category( $category_slug );
			}
		}
		$this->registered_test_categories = [];

		parent::tear_down();
		remove_all_filters( 'sd_ai_agent_ability_usage_instructions' );
		remove_all_filters( 'sd_ai_agent_ability_usage_instructions_for' );
		AbilityUsageTracker::reset();
		ToolDiscovery::reset_schema_cache();
		ToolDiscovery::reset_keyword_search_state();
		IdenticalFailureTracker::reset();
		delete_option( RolePermissions::OPTION_NAME );
	}

	/**
	 * Register a test ability under the `wp_abilities_api_init` hook context.
	 *
	 * WordPress 6.9+ requires {@see wp_register_ability()} to be called from
	 * within `wp_abilities_api_init`. The registry init action fires lazily
	 * and only once per request, so by the time set_up() runs it has already
	 * fired and an add_action() callback would never be invoked. WP core's own
	 * test suite (`tests/phpunit/tests/abilities-api/wpRegisterAbility.php`)
	 * works around this by pushing the hook name onto $wp_current_filter to
	 * satisfy the hook-context check, then popping it afterwards. We do the
	 * same and remember the id so tear_down() can clean up.
	 *
	 * WP 6.9 also rejects an ability whose `category` slug is not registered
	 * via {@see wp_register_ability_category()}, returning null silently from
	 * the registry. The helper therefore lazy-registers the referenced
	 * category before registering the ability.
	 *
	 * @param string              $name Ability id (namespaced or bare).
	 * @param array<string,mixed> $args Ability registration args.
	 */
	private function register_test_ability( string $name, array $args ): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->fail( 'wp_register_ability() is not available — Abilities API is not loaded.' );
		}

		if ( isset( $args['category'] ) && is_string( $args['category'] ) ) {
			$this->ensure_test_category( $args['category'] );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';

		try {
			wp_register_ability( $name, $args );
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered_test_abilities[] = $name;
	}

	/**
	 * Lazily register an ability category for the current test.
	 *
	 * WP 6.9 enforces that categories are registered on the
	 * `wp_abilities_api_categories_init` hook, mirroring the constraint on
	 * `wp_register_ability()`. We satisfy the hook-context check the same way
	 * as {@see self::register_test_ability()} and track the slug so tear_down
	 * can unregister it.
	 *
	 * @param string $slug Category slug used by a test ability.
	 */
	private function ensure_test_category( string $slug ): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_has_ability_category' ) ) {
			return;
		}

		if ( wp_has_ability_category( $slug ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';

		try {
			wp_register_ability_category(
				$slug,
				[
					'label'       => 'Test Category ' . $slug,
					'description' => 'Auto-registered by ToolDiscoveryTest for ' . $slug,
				]
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered_test_categories[] = $slug;
	}

	// ── tier_1_for_run ────────────────────────────────────────────────

	public function test_tier_1_always_includes_meta_tools(): void {
		$tier_1 = ToolDiscovery::tier_1_for_run();

		$this->assertContains( 'sd-ai-agent/ability-search', $tier_1 );
		$this->assertContains( 'sd-ai-agent/ability-call', $tier_1 );
	}

	public function test_tier_1_includes_cold_start_tools(): void {
		$tier_1 = ToolDiscovery::tier_1_for_run();

		$this->assertContains( 'sd-ai-agent/list-options', $tier_1 );
		$this->assertContains( 'sd-ai-agent/get-plugins', $tier_1 );
		$this->assertContains( 'sd-ai-agent/get-themes', $tier_1 );
		$this->assertContains( 'sd-ai-agent/site-health-summary', $tier_1 );
		$this->assertContains( 'sd-ai-agent/db-query', $tier_1 );
		$this->assertContains( 'sd-ai-agent/detect-fresh-install', $tier_1 );
		$this->assertContains( 'sd-ai-agent/file-list', $tier_1 );
		$this->assertContains( 'sd-ai-agent/file-read', $tier_1 );
		$this->assertContains( 'sd-ai-agent/list-posts', $tier_1 );
		$this->assertContains( 'sd-ai-agent/get-post', $tier_1 );
		$this->assertContains( 'sd-ai-agent/get-option', $tier_1 );
		$this->assertContains( 'sd-ai-agent/update-post', $tier_1 );
		$this->assertContains( 'sd-ai-agent/delete-post', $tier_1 );
		$this->assertContains( 'sd-ai-agent/update-option', $tier_1 );
		$this->assertContains( 'sd-ai-agent/update-global-styles', $tier_1 );
		$this->assertContains( 'sd-ai-agent/append-post-content', $tier_1 );
		$this->assertContains( 'sd-ai-agent/batch-create-posts', $tier_1 );
		$this->assertContains( 'sd-ai-agent/create-contact-form', $tier_1 );
		$this->assertContains( 'sd-ai-agent/stock-image', $tier_1 );
		$registered = [];
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( $ability instanceof \WP_Ability ) {
					$registered[ $ability->get_name() ] = true;
				}
			}
		}
		foreach ( [ 'sd-ai-agent/generate-image', 'sd-ai-agent/upload-media', 'sd-ai-agent/internet-search', 'sd-ai-agent/install-plugin', 'sd-ai-agent/activate-plugin', 'sd-ai-agent/set-site-logo', 'sd-ai-agent/list-menus', 'sd-ai-agent/get-menu', 'sd-ai-agent/create-menu', 'sd-ai-agent/add-menu-item', 'sd-ai-agent/remove-menu-item', 'sd-ai-agent/assign-menu-location', 'sd-ai-agent/get-global-styles', 'sd-ai-agent/get-theme-json' ] as $optional_tool ) {
			if ( isset( $registered[ $optional_tool ] ) ) {
				$this->assertContains( $optional_tool, $tier_1 );
			}
		}
	}

	public function test_tier_1_includes_block_theme_safe_editing_cluster(): void {
		$tier_1 = ToolDiscovery::tier_1_for_run();

		$this->assertContains( 'sd-ai-agent/list-block-templates', $tier_1 );
		$this->assertContains( 'sd-ai-agent/get-page-blocks', $tier_1 );
		$this->assertContains( 'sd-ai-agent/update-blocks', $tier_1 );
		$this->assertContains( 'sd-ai-agent/validate-block-content', $tier_1 );
	}

	public function test_tier_1_omits_wp_cli_when_ability_is_not_registered(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test isolates the Abilities API registry mutation.
		global $_wp_ability_registry;
		$ability_registry_backup = isset( $_wp_ability_registry ) ? $_wp_ability_registry : null;

		try {
			if ( function_exists( 'wp_unregister_ability' ) && function_exists( 'wp_get_abilities' ) ) {
				foreach ( wp_get_abilities() as $ability ) {
					if ( $ability instanceof \WP_Ability && 'wp-cli/execute' === $ability->get_name() ) {
						wp_unregister_ability( 'wp-cli/execute' );
						break;
					}
				}
			}

			$tier_1 = ToolDiscovery::tier_1_for_run();

			$this->assertNotContains( 'wp-cli/execute', $tier_1 );
		} finally {
			if ( null === $ability_registry_backup ) {
				unset( $_wp_ability_registry );
			} else {
				$_wp_ability_registry = $ability_registry_backup;
			}
		}
	}

	public function test_tier_1_promotes_recently_used_abilities(): void {
		// Pick an ability that exists in this install.
		AbilityUsageTracker::record( 'sd-ai-agent/get-plugins' );
		AbilityUsageTracker::record( 'sd-ai-agent/get-plugins' );
		AbilityUsageTracker::record( 'sd-ai-agent/get-plugins' );

		$tier_1 = ToolDiscovery::tier_1_for_run();

		$this->assertContains( 'sd-ai-agent/get-plugins', $tier_1 );
	}

	public function test_tier_1_size_is_capped(): void {
		$tier_1 = ToolDiscovery::tier_1_for_run();

		// Cap is MAX_TIER_1 plus the two meta-tools always added on top.
		$this->assertLessThanOrEqual( ToolDiscovery::MAX_TIER_1 + 2, count( $tier_1 ) );
	}

	// ── ability-search ────────────────────────────────────────────────

	public function test_ability_search_returns_inline_schemas(): void {
		$result = ToolDiscovery::handle_ability_search( [ 'query' => 'plugins' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertNotEmpty( $result['results'] );

		$first = $result['results'][0];
		$this->assertArrayHasKey( 'id', $first );
		$this->assertArrayHasKey( 'input_schema', $first );
		$this->assertArrayHasKey( 'output_schema', $first );
	}

	public function test_ability_search_select_form_returns_exact_matches(): void {
		$result = ToolDiscovery::handle_ability_search(
			[ 'query' => 'select:sd-ai-agent/get-plugins,sd-ai-agent/get-themes' ]
		);

		$ids = array_map(
			static function ( $r ) {
				return $r['id'];
			},
			$result['results']
		);

		$this->assertContains( 'sd-ai-agent/get-plugins', $ids );
		$this->assertContains( 'sd-ai-agent/get-themes', $ids );
	}

	public function test_ability_search_respects_max_results(): void {
		$result = ToolDiscovery::handle_ability_search(
			[
				'query'       => 'a',
				'max_results' => 3,
			]
		);

		$this->assertLessThanOrEqual( 3, count( $result['results'] ) );
	}

	public function test_ability_search_maps_manage_global_styles_to_style_abilities(): void {
		$result = ToolDiscovery::handle_ability_search(
			[
				'query'       => 'manage global styles',
				'max_results' => 5,
			]
		);

		$ids = array_map(
			static function ( $r ) {
				return $r['id'];
			},
			$result['results']
		);

		$this->assertContains( 'sd-ai-agent/update-global-styles', $ids );
		$this->assertContains( 'sd-ai-agent/get-global-styles', $ids );
	}

	public function test_ability_search_maps_design_token_compilation_to_the_compiler(): void {
		$result = ToolDiscovery::handle_ability_search(
			[
				'query'       => 'compile design tokens',
				'max_results' => 5,
			]
		);

		$ids = array_map(
			static function ( $result ) {
				return $result['id'];
			},
			$result['results']
		);

		$this->assertContains( 'sd-ai-agent/compile-design-tokens', $ids );
	}

	public function test_ability_search_maps_edit_page_to_update_post(): void {
		$result = ToolDiscovery::handle_ability_search(
			[
				'query'       => 'edit page',
				'max_results' => 3,
			]
		);

		$this->assertNotEmpty( $result['results'] );
		$this->assertSame( 'sd-ai-agent/update-post', $result['results'][0]['id'] );
	}

	public function test_ability_search_caches_schemas_for_recently_fetched_section(): void {
		ToolDiscovery::handle_ability_search(
			[ 'query' => 'select:sd-ai-agent/get-plugins' ]
		);

		$section = ToolDiscovery::recently_fetched_section();
		$this->assertStringContainsString( 'get-plugins', $section );
	}

	/**
	 * When a session's *first* ability-search this request is a `select:`
	 * lookup, the response should carry a discovery_hint nudging the model
	 * to also run a keyword search. Regression: session #25 (NerdLove
	 * dating site) — agent ran `select:list-allowed-roots,generate-plugin`
	 * as its first ability-search after the user asked for a dating site,
	 * never followed up with keyword discovery, and so never considered
	 * install-plugin or search-plugin-directory.
	 */
	public function test_ability_search_emits_discovery_hint_for_first_select_lookup(): void {
		$result = ToolDiscovery::handle_ability_search(
			[ 'query' => 'select:sd-ai-agent/get-plugins' ]
		);

		$this->assertArrayHasKey(
			'discovery_hint',
			$result,
			'First-select lookups should attach a discovery_hint.'
		);
		$this->assertStringContainsString(
			'keyword',
			$result['discovery_hint'],
			'Hint should suggest a keyword search.'
		);
		$this->assertStringContainsString(
			'sd-ai-agent/search-plugin-directory',
			$result['discovery_hint'],
			'Hint should name the alternative plugin-discovery ability.'
		);
	}

	/**
	 * When a keyword search has already happened this request, subsequent
	 * `select:` lookups should NOT carry the hint — the model has already
	 * done the broader discovery pass.
	 */
	public function test_ability_search_omits_discovery_hint_after_keyword_search(): void {
		ToolDiscovery::handle_ability_search( [ 'query' => 'plugins' ] );

		$result = ToolDiscovery::handle_ability_search(
			[ 'query' => 'select:sd-ai-agent/get-plugins' ]
		);

		$this->assertArrayNotHasKey(
			'discovery_hint',
			$result,
			'After a keyword search this request, select: lookups should not re-trigger the hint.'
		);
	}

	/**
	 * Plain keyword searches must never carry the discovery_hint — only
	 * `select:` lookups arriving before any keyword discovery do.
	 */
	public function test_ability_search_does_not_emit_discovery_hint_for_keyword_query(): void {
		$result = ToolDiscovery::handle_ability_search( [ 'query' => 'plugins' ] );

		$this->assertArrayNotHasKey( 'discovery_hint', $result );
	}

	public function test_ability_search_warns_js_abilities_must_be_called_directly(): void {
		$result = ToolDiscovery::handle_ability_search(
			[
				'query'       => 'refresh-page',
				'max_results' => 3,
			]
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['results'] );
		$this->assertSame( 'sd-ai-agent-js/refresh-page', $result['results'][0]['id'] );
		$this->assertStringContainsString( 'call the listed ability directly', $result['hint'] );
		$this->assertStringContainsString( 'Do not wrap browser abilities in sd-ai-agent/ability-call', $result['hint'] );
	}

	// ── ability-call ──────────────────────────────────────────────────

	public function test_ability_call_executes_a_known_ability(): void {
		$result = ToolDiscovery::handle_ability_call(
			[
				'ability'   => 'sd-ai-agent/get-plugins',
				'arguments' => [],
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'result', $result );
	}

	public function test_ability_call_records_usage(): void {
		ToolDiscovery::handle_ability_call(
			[
				'ability'   => 'sd-ai-agent/get-plugins',
				'arguments' => [],
			]
		);

		$top = AbilityUsageTracker::top( 5 );
		$this->assertContains( 'sd-ai-agent/get-plugins', $top );
	}

	public function test_ability_call_returns_self_heal_payload_for_unknown_ability(): void {
		$result = ToolDiscovery::handle_ability_call(
			[ 'ability' => 'no-such/ability' ]
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'ability_not_found', $result['code'] );
		$this->assertArrayHasKey( 'suggestions', $result );
		$this->assertArrayHasKey( 'hint', $result );
	}

	public function test_ability_call_rejects_js_browser_abilities_with_direct_call_hint(): void {
		$result = ToolDiscovery::handle_ability_call(
			[
				'ability'   => 'sd-ai-agent-js/refresh-page',
				'arguments' => [],
			]
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'sd-ai-agent-js/refresh-page', $result['ability'] );
		$this->assertStringContainsString( 'cannot run through sd-ai-agent/ability-call', $result['error'] );
		$this->assertStringContainsString( 'Call the browser ability directly', $result['hint'] );
	}

	public function test_ability_call_aliases_legacy_ai_agent_prefix(): void {
		$result = ToolDiscovery::handle_ability_call(
			[
				'ability'   => 'ai-agent/get-plugins',
				'arguments' => [],
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'sd-ai-agent/get-plugins', $result['ability'] );
	}

	public function test_ability_search_select_aliases_legacy_prefix(): void {
		$result = ToolDiscovery::handle_ability_search(
			[ 'query' => 'select:ai-agent/get-plugins' ]
		);

		$ids = array_map(
			static function ( $r ) {
				return $r['id'];
			},
			$result['results']
		);

		$this->assertContains( 'sd-ai-agent/get-plugins', $ids );
	}

	public function test_ability_call_returns_error_for_malformed_json_arguments(): void {
		$result = ToolDiscovery::handle_ability_call(
			[
				'ability'   => 'sd-ai-agent/get-plugins',
				'arguments' => '{"title":', // Truncated / malformed JSON.
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_ability_arguments', $result->get_error_code() );
	}

	public function test_ability_call_returns_error_for_non_object_json_arguments(): void {
		$result = ToolDiscovery::handle_ability_call(
			[
				'ability'   => 'sd-ai-agent/get-plugins',
				'arguments' => '"just a string"', // Valid JSON but not an object/array.
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_ability_arguments', $result->get_error_code() );
	}

	public function test_ability_call_rejects_role_restricted_target(): void {
		$executed = false;
		$target   = 'sd-ai-agent/role-restricted-tool';

		$this->register_test_ability(
			$target,
			[
				'label'               => 'Role Restricted Tool',
				'description'         => 'Role restricted execution target.',
				'category'            => 'sd-ai-agent',
				'input_schema'        => [ 'type' => 'object' ],
				'output_schema'       => [ 'type' => 'object' ],
				'execute_callback'    => static function () use ( &$executed ): array {
					$executed = true;
					return [ 'ok' => true ];
				},
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
					],
				],
			]
		);

		update_option(
			RolePermissions::OPTION_NAME,
			[
				'author' => [
					'chat_access'       => true,
					'allowed_abilities' => [ 'sd-ai-agent/get-plugins' ],
				],
			]
		);
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );

		$result = ToolDiscovery::handle_ability_call(
			[
				'ability'   => $target,
				'arguments' => [],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ability_forbidden', $result->get_error_code() );
		$this->assertFalse( $executed );
	}

	public function test_ability_call_reports_missing_file_edit_core_cap_after_confirmation(): void {
		$target = 'sd-ai-agent/file-edit';
		$this->assertNotNull( \wp_get_ability( $target ), 'file-edit must be registered by the advanced file mutation abilities.' );

		$role = get_role( 'subscriber' );
		$this->assertNotNull( $role );
		$role->add_cap( 'manage_options', true );
		$role->add_cap( 'sd_ai_agent_tool_file_edit', true );

		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );
		ToolPermissionResolver::set_one_turn_approved_abilities( [ $target ] );

		$result = ToolDiscovery::handle_ability_call(
			[
				'ability'   => $target,
				'arguments' => [],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 'edit_files', $result->get_error_data()['missing_capability'] );
		$this->assertStringContainsString( 'approved for this turn', $result->get_error_message() );

		ToolPermissionResolver::clear_one_turn_approved_abilities();
		$role->remove_cap( 'manage_options' );
		$role->remove_cap( 'sd_ai_agent_tool_file_edit' );
	}

	public function test_ability_call_executes_one_turn_approved_target_when_core_cap_allows(): void {
		$executed = false;
		$target   = 'sd-ai-agent/approved-core-edit-tool';

		$core_filter = static function ( array $caps, string $id ) use ( $target ): array {
			if ( $id === $target ) {
				return [ 'edit_posts' ];
			}

			return $caps;
		};
		add_filter( 'sd_ai_agent_tool_required_core_cap', $core_filter, 10, 2 );

		$this->register_test_ability(
			$target,
			[
				'label'               => 'Approved Core Edit Tool',
				'description'         => 'Requires confirmation plus an edit_posts core capability.',
				'category'            => 'sd-ai-agent',
				'input_schema'        => [ 'type' => 'object' ],
				'output_schema'       => [ 'type' => 'object' ],
				'execute_callback'    => static function () use ( &$executed ): array {
					$executed = true;
					return [ 'ok' => true ];
				},
				'permission_callback' => static function () use ( $target ): bool {
					return ToolCapabilities::current_user_can( $target );
				},
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
					],
				],
			]
		);

		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		ToolPermissionResolver::set_one_turn_approved_abilities( [ $target ] );

		try {
			$result = ToolDiscovery::handle_ability_call(
				[
					'ability'   => $target,
					'arguments' => [],
				]
			);

			$this->assertIsArray( $result );
			$this->assertTrue( $result['success'] );
			$this->assertTrue( $executed );
		} finally {
			ToolPermissionResolver::clear_one_turn_approved_abilities();
			remove_filter( 'sd_ai_agent_tool_required_core_cap', $core_filter, 10 );
		}
	}

	public function test_ability_search_hides_role_restricted_targets(): void {
		$target = 'sd-ai-agent/hidden-role-search-tool';

		$this->register_test_ability(
			$target,
			[
				'label'               => 'Hidden Role Search Tool',
				'description'         => 'needle-role-hidden-search unique marker.',
				'category'            => 'sd-ai-agent',
				'input_schema'        => [ 'type' => 'object' ],
				'output_schema'       => [ 'type' => 'object' ],
				'execute_callback'    => '__return_true',
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
					],
				],
			]
		);

		update_option(
			RolePermissions::OPTION_NAME,
			[
				'author' => [
					'chat_access'       => true,
					'allowed_abilities' => [ 'sd-ai-agent/get-plugins' ],
				],
			]
		);
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );

		$result = ToolDiscovery::handle_ability_search(
			[
				'query'       => 'needle-role-hidden-search',
				'max_results' => 10,
			]
		);

		$ids = array_map(
			static function ( array $row ): string {
				return (string) $row['id'];
			},
			$result['results'] ?? []
		);

		$this->assertNotContains( $target, $ids );
	}

	// ── manifest ──────────────────────────────────────────────────────

	public function test_manifest_lists_tier_2_abilities(): void {
		$manifest = ToolDiscovery::build_manifest_section();

		$this->assertNotEmpty( $manifest );
		$this->assertStringContainsString( '## Available Abilities', $manifest );
	}

	public function test_manifest_excludes_current_direct_abilities(): void {
		$manifest = ToolDiscovery::build_manifest_section(
			array(
				'sd-ai-agent/ability-search',
				'sd-ai-agent/ability-call',
				'sd-ai-agent/list-posts',
			)
		);

		$this->assertStringNotContainsString(
			'`sd-ai-agent/list-posts`',
			$manifest,
			'The manifest must not describe a currently direct ability as Tier 2.'
		);
	}

	public function test_manifest_uses_usage_instructions_filter(): void {
		add_filter(
			'sd_ai_agent_ability_usage_instructions',
			static function ( $blocks ) {
				$blocks['sd-ai-agent'] = 'CUSTOM-INSTRUCTION-MARKER';
				return $blocks;
			}
		);

		$manifest = ToolDiscovery::build_manifest_section();

		$this->assertStringContainsString( 'CUSTOM-INSTRUCTION-MARKER', $manifest );
	}

	public function test_manifest_inlines_required_fields_for_abilities(): void {
		// memory-delete requires `id`. It's not in DEFAULT_TIER_1 so it
		// appears in the manifest, and the line should include "Required: id".
		$manifest = ToolDiscovery::build_manifest_section();

		$this->assertMatchesRegularExpression(
			'/`sd-ai-agent\/memory-delete`.*Required:.*id/',
			$manifest,
			'Manifest line for memory-delete should include "Required: id".'
		);
	}

	public function test_manifest_includes_per_ability_usage_instructions(): void {
		// Register a test ability with usage_instructions in meta.ai.
		// WP 6.9 requires a namespaced id (`vendor/name`).
		$this->register_test_ability(
			'test-plugin/ability-with-instructions',
			[
				'label'       => 'Test Ability',
				'description' => 'A test ability.',
				'category'    => 'test-category',
				'meta'        => [
					'ai' => [
						'usage_instructions' => 'Use when testing usage instructions.',
					],
				],
				'execute_callback'    => static function () {
					return [];
				},
				'permission_callback' => static function () {
					return true;
				},
			]
		);

		$manifest = ToolDiscovery::build_manifest_section();

		// The manifest should include the usage_instructions on an indented line.
		$this->assertStringContainsString( 'Use when testing usage instructions.', $manifest );
	}

	public function test_ability_search_includes_usage_instructions_in_results(): void {
		// Register a test ability with usage_instructions.
		// WP 6.9 requires a namespaced id (`vendor/name`).
		$this->register_test_ability(
			'test-plugin/search-ability-with-instructions',
			[
				'label'       => 'Test Search Ability',
				'description' => 'A test ability for search.',
				'category'    => 'test-search',
				'meta'        => [
					'ai' => [
						'usage_instructions' => 'Use for testing search results.',
					],
				],
				'execute_callback'    => static function () {
					return [];
				},
				'permission_callback' => static function () {
					return true;
				},
			]
		);

		$result = ToolDiscovery::handle_ability_search(
			[ 'query' => 'select:test-plugin/search-ability-with-instructions' ]
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['results'] );

		$first = $result['results'][0];
		$this->assertArrayHasKey( 'usage_instructions', $first );
		$this->assertSame( 'Use for testing search results.', $first['usage_instructions'] );
	}

	public function test_ability_search_omits_empty_usage_instructions(): void {
		// Register a test ability without usage_instructions.
		// WP 6.9 requires a namespaced id (`vendor/name`).
		$this->register_test_ability(
			'test-plugin/ability-no-instructions',
			[
				'label'       => 'Test No Instructions',
				'description' => 'A test ability without instructions.',
				'category'    => 'test-no-instructions',
				'execute_callback'    => static function () {
					return [];
				},
				'permission_callback' => static function () {
					return true;
				},
			]
		);

		$result = ToolDiscovery::handle_ability_search(
			[ 'query' => 'select:test-plugin/ability-no-instructions' ]
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['results'] );

		$first = $result['results'][0];
		// usage_instructions should not be present if empty.
		$this->assertArrayNotHasKey( 'usage_instructions', $first );
	}

	public function test_usage_instructions_filter_for_third_party_abilities(): void {
		// Register a test ability without usage_instructions.
		// WP 6.9 requires a namespaced id (`vendor/name`).
		$this->register_test_ability(
			'test-plugin/third-party-ability',
			[
				'label'       => 'Third Party Ability',
				'description' => 'A third-party ability.',
				'category'    => 'third-party',
				'execute_callback'    => static function () {
					return [];
				},
				'permission_callback' => static function () {
					return true;
				},
			]
		);

		// Use the filter to supply instructions for the third-party ability.
		add_filter(
			'sd_ai_agent_ability_usage_instructions_for',
			static function ( $instructions, $ability_name ) {
				if ( 'test-plugin/third-party-ability' === $ability_name ) {
					return 'Use this third-party ability when needed.';
				}
				return $instructions;
			},
			10,
			2
		);

		$result = ToolDiscovery::handle_ability_search(
			[ 'query' => 'select:test-plugin/third-party-ability' ]
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['results'] );

		$first = $result['results'][0];
		$this->assertArrayHasKey( 'usage_instructions', $first );
		$this->assertSame( 'Use this third-party ability when needed.', $first['usage_instructions'] );
	}

	// ── validation error self-correction ──────────────────────────────

	public function test_ability_call_inlines_schema_on_validation_error(): void {
		// memory-save requires `category` and `content`. Calling with empty
		// args should produce ability_invalid_input + the input_schema +
		// example_arguments + missing_required_fields.
		$result = ToolDiscovery::handle_ability_call(
			array(
				'ability'   => 'sd-ai-agent/memory-save',
				'arguments' => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'ability_invalid_input', $result['code'] );
		$this->assertArrayHasKey( 'input_schema', $result );
		$this->assertArrayHasKey( 'hint', $result );
		$this->assertArrayHasKey( 'missing_required_fields', $result );
		$this->assertArrayHasKey( 'example_arguments', $result );

		// example_arguments should contain at least one of the required
		// fields (whichever the validator complains about first).
		$this->assertNotEmpty( $result['example_arguments'] );
	}

	public function test_ability_call_injects_nudge_after_two_identical_failures(): void {
		$args = array();

		// First call: gets the schema/hint but no nudge yet.
		$first = ToolDiscovery::handle_ability_call(
			array(
				'ability'   => 'sd-ai-agent/memory-save',
				'arguments' => $args,
			)
		);
		$this->assertArrayNotHasKey( 'nudge', $first );

		// Second identical call: nudge appears.
		$second = ToolDiscovery::handle_ability_call(
			array(
				'ability'   => 'sd-ai-agent/memory-save',
				'arguments' => $args,
			)
		);
		$this->assertArrayHasKey( 'nudge', $second );
		$this->assertStringContainsString( 'STOP', $second['nudge'] );
		$this->assertStringContainsString( 'sd-ai-agent/memory-save', $second['nudge'] );
	}
}

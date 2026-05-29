<?php
/**
 * Test case for WordPressAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\SwitchPluginAbility;
use SdAiAgent\Abilities\WordPressAbilities;
use WP_UnitTestCase;

/**
 * Test WordPressAbilities handler methods.
 */
class WordPressAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Test handle_get_plugins returns plugin list.
	 */
	public function test_handle_get_plugins_returns_array() {
		$result = WordPressAbilities::handle_get_plugins();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'plugins', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'active_count', $result );
		$this->assertIsArray( $result['plugins'] );
		$this->assertIsInt( $result['total'] );
		$this->assertIsInt( $result['active_count'] );
	}

	/**
	 * Test handle_get_plugins total matches plugins array count.
	 */
	public function test_handle_get_plugins_total_matches_count() {
		$result = WordPressAbilities::handle_get_plugins();

		$this->assertSame( count( $result['plugins'] ), $result['total'] );
	}

	/**
	 * Test handle_get_plugins each plugin has required fields.
	 */
	public function test_handle_get_plugins_plugin_structure() {
		$result = WordPressAbilities::handle_get_plugins();

		if ( ! empty( $result['plugins'] ) ) {
			$plugin = $result['plugins'][0];
			$this->assertArrayHasKey( 'file', $plugin );
			$this->assertArrayHasKey( 'name', $plugin );
			$this->assertArrayHasKey( 'version', $plugin );
			$this->assertArrayHasKey( 'active', $plugin );
			$this->assertIsBool( $plugin['active'] );
		}
	}

	/**
	 * Test handle_get_themes returns theme list.
	 */
	public function test_handle_get_themes_returns_array() {
		$result = WordPressAbilities::handle_get_themes();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'themes', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'active', $result );
		$this->assertIsArray( $result['themes'] );
		$this->assertIsInt( $result['total'] );
		// In the test environment, get_stylesheet() may return false if no theme is active.
		$this->assertTrue(
			is_string( $result['active'] ) || false === $result['active'],
			'active should be a string or false.'
		);
	}

	/**
	 * Test handle_get_themes total matches themes array count.
	 */
	public function test_handle_get_themes_total_matches_count() {
		$result = WordPressAbilities::handle_get_themes();

		$this->assertSame( count( $result['themes'] ), $result['total'] );
	}

	/**
	 * Test handle_get_themes each theme has required fields.
	 */
	public function test_handle_get_themes_theme_structure() {
		$result = WordPressAbilities::handle_get_themes();

		if ( ! empty( $result['themes'] ) ) {
			$theme = $result['themes'][0];
			$this->assertArrayHasKey( 'slug', $theme );
			$this->assertArrayHasKey( 'name', $theme );
			$this->assertArrayHasKey( 'version', $theme );
			$this->assertArrayHasKey( 'active', $theme );
			$this->assertIsBool( $theme['active'] );
		}
	}

	/**
	 * Test handle_get_themes active theme is marked correctly.
	 *
	 * In the test environment, get_stylesheet() may return false if no theme is
	 * registered. We skip the active-theme assertions in that case.
	 */
	public function test_handle_get_themes_active_theme_marked() {
		$result      = WordPressAbilities::handle_get_themes();
		$active_slug = $result['active'];

		// If no active theme in test env, skip the active-theme assertions.
		if ( false === $active_slug || '' === $active_slug ) {
			$this->markTestSkipped( 'No active theme registered in test environment.' );
		}

		$active_themes = array_filter(
			$result['themes'],
			function ( $theme ) {
				return $theme['active'] === true;
			}
		);

		// At least one theme should be active.
		$this->assertNotEmpty( $active_themes );

		// The active slug should match one of the active themes.
		$active_slugs = array_column( array_values( $active_themes ), 'slug' );
		$this->assertContains( $active_slug, $active_slugs );
	}

	/**
	 * Test handle_install_plugin with empty slug returns WP_Error.
	 */
	public function test_handle_install_plugin_empty_slug() {
		$result = WordPressAbilities::handle_install_plugin( [ 'slug' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_empty_slug', $result->get_error_code() );
	}

	/**
	 * Test handle_install_plugin with missing slug returns WP_Error.
	 */
	public function test_handle_install_plugin_missing_slug() {
		$result = WordPressAbilities::handle_install_plugin( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test switch-plugin exposes a dry-run option for safe benchmark calls.
	 */
	public function test_switch_plugin_schema_exposes_dry_run() {
		$ability = new SwitchPluginAbility( 'sd-ai-agent/switch-plugin' );
		$schema  = $ability->get_input_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'dry_run', $schema['properties'] );
		$this->assertSame( 'boolean', $schema['properties']['dry_run']['type'] );
		$this->assertStringContainsString( 'without changing active plugins', $schema['properties']['dry_run']['description'] );
	}

	/**
	 * Test switch-plugin dry runs preview the target without changing plugins.
	 */
	public function test_handle_switch_plugin_dry_run_does_not_change_active_plugins() {
		$before = get_option( 'active_plugins', [] );

		$result = WordPressAbilities::handle_switch_plugin(
			[
				'activate' => 'akismet',
				'dry_run'  => true,
			]
		);
		$after  = get_option( 'active_plugins', [] );

		$this->assertIsArray( $result );
		$this->assertSame( 'preview', $result['status'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertArrayHasKey( 'would_activate', $result );
		$this->assertSame( $before, $after );
	}

	/**
	 * Test handle_run_php with empty function name returns WP_Error.
	 */
	public function test_handle_run_php_empty_function() {
		$result = WordPressAbilities::handle_run_php( [ 'function' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_empty_function', $result->get_error_code() );
	}

	/**
	 * Test handle_run_php with missing function key returns WP_Error.
	 */
	public function test_handle_run_php_missing_function() {
		$result = WordPressAbilities::handle_run_php( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_empty_function', $result->get_error_code() );
	}

	/**
	 * Test handle_run_php calls an allowed WordPress function and returns result.
	 *
	 * Uses get_bloginfo('version') which is in the allowlist and always returns
	 * a non-empty string in the test environment.
	 */
	public function test_handle_run_php_simple_expression() {
		$result = WordPressAbilities::handle_run_php( [
			'function' => 'get_bloginfo',
			'args'     => [ 'version' ],
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'result', $result );
		$this->assertArrayHasKey( 'output', $result );
		$this->assertNotEmpty( $result['result'] );
	}

	/**
	 * Test handle_run_php with a disallowed function returns WP_Error.
	 *
	 * Arbitrary function names not in the allowlist must be rejected.
	 */
	public function test_handle_run_php_disallowed_function() {
		$result = WordPressAbilities::handle_run_php( [
			'function' => 'eval',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_disallowed_function', $result->get_error_code() );
	}

	/**
	 * Test handle_run_php can call WordPress functions and returns a string result.
	 *
	 * Uses get_bloginfo('version') which always returns a non-empty string
	 * in the test environment.
	 */
	public function test_handle_run_php_wordpress_functions() {
		$result = WordPressAbilities::handle_run_php( [
			'function' => 'get_bloginfo',
			'args'     => [ 'version' ],
		] );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['result'] );
		$this->assertIsString( $result['result'] );
	}

	/**
	 * Test handle_run_php with a function that throws returns WP_Error with php_error code.
	 *
	 * Registers a test-only throwing function via the allowlist filter, then
	 * calls it to exercise the Throwable catch path in execute_callback().
	 */
	public function test_handle_run_php_php_error() {
		$throwing_fn = 'sd_ai_agent_test_throwing_fn_' . uniqid();
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only dynamic function registration.
		eval( 'function ' . $throwing_fn . '() { throw new \RuntimeException("test error"); }' );

		add_filter(
			'sd_ai_agent_allowed_wp_functions',
			static function ( array $fns ) use ( $throwing_fn ): array {
				$fns[] = $throwing_fn;
				return $fns;
			}
		);

		$result = WordPressAbilities::handle_run_php( [ 'function' => $throwing_fn ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_php_error', $result->get_error_code() );
	}

	/**
	 * RunPhpAbility must refuse get_option('auth_key') even though
	 * get_option is on the function allowlist.
	 */
	public function test_handle_run_php_blocks_get_option_for_secret_name() {
		update_option( 'auth_key', 'do-not-leak-this-secret' );

		$result = WordPressAbilities::handle_run_php( [
			'function' => 'get_option',
			'args'     => [ 'auth_key' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_secret_redacted', $result->get_error_code() );

		delete_option( 'auth_key' );
	}

	/**
	 * RunPhpAbility must refuse every shipped secret name across every
	 * gated option-reading function (get_option, get_site_option,
	 * get_transient, …). Functions that ship outside the default allowlist
	 * (get_site_option, get_network_option, get_site_transient) are added
	 * via filter so the test exercises the secret gate rather than the
	 * allowlist gate.
	 */
	public function test_handle_run_php_blocks_every_option_read_function_against_every_secret() {
		add_filter(
			'sd_ai_agent_allowed_wp_functions',
			static function ( array $fns ): array {
				$fns[] = 'get_site_option';
				$fns[] = 'get_network_option';
				$fns[] = 'get_site_transient';
				return $fns;
			}
		);

		$functions = [ 'get_option', 'get_site_option', 'get_transient', 'get_site_transient' ];

		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				continue;
			}
			foreach ( \SdAiAgent\Abilities\OptionsAbilities::get_secret_read_blocklist() as $name ) {
				$result = WordPressAbilities::handle_run_php( [
					'function' => $function,
					'args'     => [ $name ],
				] );

				$this->assertInstanceOf(
					\WP_Error::class,
					$result,
					sprintf( '%s("%s") must return WP_Error', $function, $name )
				);
				$this->assertSame(
					'sd_ai_agent_option_secret_redacted',
					$result->get_error_code(),
					sprintf( '%s("%s") must use the secret-redacted error code', $function, $name )
				);
			}
		}

		remove_all_filters( 'sd_ai_agent_allowed_wp_functions' );
	}

	/**
	 * RunPhpAbility must read non-secret options normally.
	 */
	public function test_handle_run_php_allows_get_option_for_safe_name() {
		update_option( 'sd_ai_agent_test_runphp_safe_option', 'visible-value' );

		$result = WordPressAbilities::handle_run_php( [
			'function' => 'get_option',
			'args'     => [ 'sd_ai_agent_test_runphp_safe_option' ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'visible-value', $result['result'] );

		delete_option( 'sd_ai_agent_test_runphp_safe_option' );
	}

	/**
	 * RunPhpAbility must refuse update_option('auth_key', …) so the agent
	 * cannot regenerate the auth keys via the low-level function caller.
	 */
	public function test_handle_run_php_blocks_update_option_for_write_blocklist() {
		$result = WordPressAbilities::handle_run_php( [
			'function' => 'update_option',
			'args'     => [ 'auth_key', 'rotated-by-agent' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_blocked', $result->get_error_code() );
	}

	/**
	 * RunPhpAbility must refuse delete_option('auth_key').
	 */
	public function test_handle_run_php_blocks_delete_option_for_write_blocklist() {
		$result = WordPressAbilities::handle_run_php( [
			'function' => 'delete_option',
			'args'     => [ 'auth_key' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_blocked', $result->get_error_code() );
	}

	/**
	 * Filter-extended secret names must also be honoured by RunPhpAbility.
	 */
	public function test_handle_run_php_honours_filter_extended_secret_for_get_option() {
		add_filter(
			'sd_ai_agent_options_read_blocklist',
			static function ( array $list ): array {
				$list[] = 'third_party_api_token';
				return $list;
			}
		);
		update_option( 'third_party_api_token', 'secret-token-value' );

		$result = WordPressAbilities::handle_run_php( [
			'function' => 'get_option',
			'args'     => [ 'third_party_api_token' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_secret_redacted', $result->get_error_code() );

		delete_option( 'third_party_api_token' );
		remove_all_filters( 'sd_ai_agent_options_read_blocklist' );
	}

	/**
	 * get_network_option takes (network_id, option_name, …); the secret
	 * check must look at arg index 1, not 0. get_network_option is not in
	 * the default allowlist, so the test adds it via filter to ensure the
	 * secret gate (not the allowlist) is what blocks the call.
	 */
	public function test_handle_run_php_blocks_get_network_option_secret_at_arg_one() {
		if ( ! function_exists( 'get_network_option' ) ) {
			$this->markTestSkipped( 'get_network_option() unavailable in this environment.' );
		}

		add_filter(
			'sd_ai_agent_allowed_wp_functions',
			static function ( array $fns ): array {
				$fns[] = 'get_network_option';
				return $fns;
			}
		);

		$result = WordPressAbilities::handle_run_php( [
			'function' => 'get_network_option',
			'args'     => [ 1, 'auth_key' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_secret_redacted', $result->get_error_code() );

		remove_all_filters( 'sd_ai_agent_allowed_wp_functions' );
	}
}

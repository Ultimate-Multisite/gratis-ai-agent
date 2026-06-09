<?php
/**
 * Test case for WpCliAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\OptionsAbilities;
use SdAiAgent\Abilities\WpCliAbilities;
use WP_UnitTestCase;

/**
 * Test WP-CLI-style native dispatcher guard behaviour.
 */
class WpCliAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Set up an administrator user because WP-CLI execution requires admin caps.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		WpCliAbilities::reset_binary_cache();
		$this->unregister_wp_cli_ability();
		$this->unregister_wp_cli_category();
	}

	/**
	 * Clean up filters and options after each test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'sd_ai_agent_wp_cli_dispatcher_available' );
		remove_all_filters( 'sd_ai_agent_wp_cli_proc_open_available' );
		remove_all_filters( 'sd_ai_agent_wp_cli_binary' );
		remove_all_filters( 'sd_ai_agent_options_read_blocklist' );
		delete_option( 'sd_ai_agent_test_cli_write_option' );

		WpCliAbilities::reset_binary_cache();
		$this->unregister_wp_cli_ability();
		$this->unregister_wp_cli_category();

		parent::tear_down();
	}

	/**
	 * Registration should not expose wp-cli/execute when native dispatch is filtered off.
	 */
	public function test_register_ability_skips_when_dispatcher_unavailable(): void {
		add_filter( 'sd_ai_agent_wp_cli_dispatcher_available', '__return_false' );

		$this->register_wp_cli_ability_in_context();

		$this->assertFalse( $this->is_wp_cli_ability_registered() );
	}

	/**
	 * The native dispatcher should register without any WP-CLI binary discovery.
	 */
	public function test_register_ability_registers_without_binary(): void {
		add_filter(
			'sd_ai_agent_wp_cli_binary',
			static function (): string {
				return '/definitely/not/a/wp-cli.phar';
			}
		);

		$this->register_wp_cli_category_in_context();
		$this->register_wp_cli_ability_in_context();

		$this->assertTrue( $this->is_wp_cli_ability_registered() );
	}

	/**
	 * Native dispatch works on hosts where proc_open is unavailable.
	 */
	public function test_execute_works_when_proc_open_unavailable(): void {
		add_filter( 'sd_ai_agent_wp_cli_proc_open_available', '__return_false' );
		update_option( 'blogname', 'Native CLI Test' );

		$result = WpCliAbilities::execute( 'option get blogname' );

		$this->assertSame( 'Native CLI Test', $result );
	}

	/**
	 * Unsupported command paths fail clearly instead of attempting a process.
	 */
	public function test_execute_returns_unsupported_command_for_unimplemented_path(): void {
		$result = WpCliAbilities::execute( 'theme list --format=json' );

		$this->assertWPError( $result );
		$this->assertSame( 'unsupported_command', $result->get_error_code() );
		$this->assertSame( 501, $result->get_error_data()['status'] );
	}

	/**
	 * `wp post list` returns structured rows from WordPress APIs.
	 */
	public function test_execute_post_list_returns_native_rows(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Native WP-CLI Row' ) );

		$result = WpCliAbilities::execute( 'post list --format=json --post_type=post' );

		$this->assertIsArray( $result );
		$this->assertContains( $post_id, wp_list_pluck( $result, 'ID' ) );
	}

	/**
	 * `wp option update` writes only through the shared option write policy.
	 */
	public function test_execute_option_update_uses_native_option_policy(): void {
		$result = WpCliAbilities::execute( 'option update sd_ai_agent_test_cli_write_option value' );

		$this->assertIsArray( $result );
		$this->assertSame( 'value', get_option( 'sd_ai_agent_test_cli_write_option' ) );
	}

	/**
	 * `wp option get auth_key` must be blocked before any read occurs.
	 */
	public function test_execute_blocks_option_get_for_secret_name(): void {
		$result = WpCliAbilities::execute( 'option get auth_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_secret_redacted', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * `wp option pluck nonce_salt some.key` must also be blocked.
	 */
	public function test_execute_blocks_option_pluck_for_secret_name(): void {
		$result = WpCliAbilities::execute( 'option pluck nonce_salt foo' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_secret_redacted', $result->get_error_code() );
	}

	/**
	 * Flags between subcommand and option name must not bypass the gate.
	 */
	public function test_execute_blocks_secret_name_even_with_flags_before_it(): void {
		$result = WpCliAbilities::execute( 'option get --format=json secure_auth_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_secret_redacted', $result->get_error_code() );
	}

	/**
	 * `wp option update auth_key …` must be refused by the write-blocklist.
	 */
	public function test_execute_blocks_option_update_for_protected_name(): void {
		$result = WpCliAbilities::execute( 'option update auth_key rotated-by-agent' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_protected', $result->get_error_code() );
	}

	/**
	 * `wp option delete logged_in_key` must be refused before unsupported handling.
	 */
	public function test_execute_blocks_option_delete_for_protected_name(): void {
		$result = WpCliAbilities::execute( 'option delete logged_in_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_protected', $result->get_error_code() );
	}

	/**
	 * `wp option update third_party_test_option value` must be refused unless allowlisted.
	 */
	public function test_execute_blocks_option_update_for_unallowlisted_name(): void {
		$result = WpCliAbilities::execute( 'option update third_party_test_option value' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_not_allowed', $result->get_error_code() );
	}

	/**
	 * Filter-extended secret names must also block WP-CLI option get.
	 */
	public function test_execute_honours_filter_extended_secret_for_option_get(): void {
		add_filter(
			'sd_ai_agent_options_read_blocklist',
			static function ( array $list ): array {
				$list[] = 'third_party_api_token';
				return $list;
			}
		);

		$result = WpCliAbilities::execute( 'option get third_party_api_token' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_secret_redacted', $result->get_error_code() );
	}

	/**
	 * The structured `option list` scrubber must redact secret values.
	 */
	public function test_scrub_secret_output_redacts_structured_rows(): void {
		$raw = array(
			array(
				'option_name'  => 'blogname',
				'option_value' => 'My Site',
				'autoload'     => 'yes',
			),
			array(
				'option_name'  => 'auth_key',
				'option_value' => 'do-not-leak-this-secret',
				'autoload'     => 'yes',
			),
			array(
				'option_name' => 'nonce_salt',
				'value'       => 'do-not-leak-this-secret',
			),
		);

		$scrubbed = WpCliAbilities::scrub_secret_output( 'option list', $raw );
		$this->assertIsArray( $scrubbed );

		$encoded = wp_json_encode( $scrubbed );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( 'do-not-leak-this-secret', $encoded );
		$this->assertSame( 'My Site', $scrubbed[0]['option_value'] );
		$this->assertSame( OptionsAbilities::SECRET_REDACTED_PLACEHOLDER, $scrubbed[1]['option_value'] );
		$this->assertSame( OptionsAbilities::SECRET_REDACTED_PLACEHOLDER, $scrubbed[2]['value'] );
	}

	/**
	 * The text-format scrubber must replace secret values on secret option rows.
	 */
	public function test_scrub_secret_output_redacts_text_rows(): void {
		$raw = "blogname\tMy Site\nauth_key\tdo-not-leak-this-secret\nnonce_salt:do-not-leak-this-secret\n";

		$scrubbed = WpCliAbilities::scrub_secret_output( 'option list', $raw );
		$this->assertIsString( $scrubbed );
		$this->assertStringNotContainsString( 'do-not-leak-this-secret', $scrubbed );
		$this->assertStringContainsString( 'My Site', $scrubbed );
		$this->assertStringContainsString( OptionsAbilities::SECRET_REDACTED_PLACEHOLDER, $scrubbed );
	}

	/**
	 * Subcommands other than `option list*` must pass through untouched.
	 */
	public function test_scrub_secret_output_passes_through_other_commands(): void {
		$raw    = array( array( 'option_name' => 'auth_key', 'option_value' => 'leaked' ) );
		$result = WpCliAbilities::scrub_secret_output( 'post list', $raw );
		$this->assertSame( $raw, $result );
	}

	/**
	 * Invoke WP-CLI ability registration under the hook context required by core.
	 *
	 * @return void
	 */
	private function register_wp_cli_ability_in_context(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';

		try {
			WpCliAbilities::register_ability();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Invoke WP-CLI category registration under the hook context required by core.
	 *
	 * @return void
	 */
	private function register_wp_cli_category_in_context(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';

		try {
			WpCliAbilities::register_category();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Remove the WP-CLI ability so registration tests start clean.
	 *
	 * @return void
	 */
	private function unregister_wp_cli_ability(): void {
		if ( function_exists( 'wp_unregister_ability' ) && $this->is_wp_cli_ability_registered() ) {
			wp_unregister_ability( 'wp-cli/execute' );
		}
	}

	/**
	 * Remove the WP-CLI category so category registration tests are isolated.
	 *
	 * @return void
	 */
	private function unregister_wp_cli_category(): void {
		if (
			function_exists( 'wp_unregister_ability_category' )
			&& function_exists( 'wp_has_ability_category' )
			&& wp_has_ability_category( 'wp-cli' )
		) {
			wp_unregister_ability_category( 'wp-cli' );
		}
	}

	/**
	 * Check the full registry without triggering wp_get_ability() notices.
	 *
	 * @return bool
	 */
	private function is_wp_cli_ability_registered(): bool {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return false;
		}

		foreach ( wp_get_abilities() as $ability ) {
			if ( $ability instanceof \WP_Ability && 'wp-cli/execute' === $ability->get_name() ) {
				return true;
			}
		}

		return false;
	}
}

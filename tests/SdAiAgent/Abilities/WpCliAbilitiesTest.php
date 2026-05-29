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

use SdAiAgent\Abilities\WpCliAbilities;
use WP_UnitTestCase;

/**
 * Test WP-CLI ability guard behaviour.
 */
class WpCliAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Temp directory used by binary-discovery tests; cleaned up in tear_down().
	 *
	 * @var string
	 */
	private string $temp_dir = '';

	/**
	 * Set up an administrator user because WP-CLI execution requires admin caps.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		WpCliAbilities::reset_binary_cache();
		$this->unregister_wp_cli_ability();
		$this->unregister_wp_cli_category();
	}

	/**
	 * Clean up filters and temp files after each test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'sd_ai_agent_wp_cli_proc_open_available' );
		remove_all_filters( 'sd_ai_agent_wp_cli_binary' );
		remove_all_filters( 'sd_ai_agent_wp_cli_candidates' );
		remove_all_filters( 'sd_ai_agent_wp_cli_scan_path' );

		if ( '' !== $this->temp_dir && is_dir( $this->temp_dir ) ) {
			$this->rrmdir( $this->temp_dir );
			$this->temp_dir = '';
		}

		WpCliAbilities::reset_binary_cache();
		$this->unregister_wp_cli_ability();
		$this->unregister_wp_cli_category();

		parent::tear_down();
	}

	/**
	 * Disable the PATH scan + shell_exec fallback for deterministic tests.
	 *
	 * Without this, tests that expect a `wp_cli_not_found` outcome can flake
	 * when the test runner happens to have `wp` on `$PATH` (or `command -v
	 * wp` returns a hit). The production fallback is exercised separately
	 * via the smoke test in `tests/smoke/wpcli-discovery-smoke.php`.
	 *
	 * @return void
	 */
	private function disable_path_scan(): void {
		add_filter( 'sd_ai_agent_wp_cli_scan_path', '__return_false' );
	}

	/**
	 * Registration should not expose wp-cli/execute when proc_open is blocked.
	 */
	public function test_register_ability_skips_when_proc_open_unavailable(): void {
		add_filter( 'sd_ai_agent_wp_cli_proc_open_available', '__return_false' );

		$this->register_wp_cli_ability_in_context();

		$this->assertFalse( $this->is_wp_cli_ability_registered() );
	}

	/**
	 * Registration should not expose wp-cli/execute when no usable binary exists.
	 */
	public function test_register_ability_skips_when_binary_unavailable(): void {
		$empty = $this->make_temp_dir();
		add_filter(
			'sd_ai_agent_wp_cli_candidates',
			static function () use ( $empty ): array {
				return array( $empty . '/missing-wp', $empty . '/missing.phar' );
			}
		);
		add_filter(
			'sd_ai_agent_wp_cli_binary',
			static function (): string {
				return '';
			}
		);
		$this->disable_path_scan();

		$this->register_wp_cli_ability_in_context();

		$this->assertFalse( $this->is_wp_cli_ability_registered() );
	}

	/**
	 * A valid filtered binary should expose wp-cli/execute normally.
	 */
	public function test_register_ability_registers_when_binary_available(): void {
		$fake = $this->create_fake_phar();
		add_filter(
			'sd_ai_agent_wp_cli_binary',
			static function () use ( $fake ): string {
				return $fake;
			}
		);

		$this->register_wp_cli_category_in_context();
		$this->register_wp_cli_ability_in_context();

		$this->assertTrue( $this->is_wp_cli_ability_registered() );
	}

	/**
	 * The WP-CLI category should not be advertised by itself when unavailable.
	 */
	public function test_register_category_skips_when_binary_unavailable(): void {
		$empty = $this->make_temp_dir();
		add_filter(
			'sd_ai_agent_wp_cli_candidates',
			static function () use ( $empty ): array {
				return array( $empty . '/missing-wp', $empty . '/missing.phar' );
			}
		);
		add_filter(
			'sd_ai_agent_wp_cli_binary',
			static function (): string {
				return '';
			}
		);
		$this->disable_path_scan();

		$this->register_wp_cli_category_in_context();

		$this->assertFalse( wp_has_ability_category( 'wp-cli' ) );
	}

	/**
	 * Test proc_open-disabled hosts receive a clear actionable error before process setup.
	 */
	public function test_execute_returns_clear_error_when_proc_open_unavailable(): void {
		add_filter( 'sd_ai_agent_wp_cli_proc_open_available', '__return_false' );

		$result = WpCliAbilities::execute( 'post list --format=json' );

		$this->assertWPError( $result );
		$this->assertSame( 'proc_open_unavailable', $result->get_error_code() );
		$this->assertSame( 501, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'proc_open() is disabled', $result->get_error_message() );
		$this->assertStringContainsString( 'Use the REST', $result->get_error_message() );
	}

	/**
	 * The runtime filter must take precedence over auto-discovery.
	 */
	public function test_filter_overrides_auto_discovery(): void {
		$fake = $this->create_fake_phar();

		add_filter(
			'sd_ai_agent_wp_cli_binary',
			static function () use ( $fake ): string {
				return $fake;
			}
		);

		$this->assertSame( $fake, $this->invoke_find_wp_cli() );
	}

	/**
	 * A `.phar` file should be acceptable without the executable bit.
	 *
	 * Customer report (GH-1335) hit exactly this case: phar uploaded to
	 * mu-plugins/ via SFTP with default 0644 permissions.
	 */
	public function test_phar_accepted_without_executable_bit(): void {
		$fake = $this->create_fake_phar( 0644 );

		add_filter(
			'sd_ai_agent_wp_cli_binary',
			static function () use ( $fake ): string {
				return $fake;
			}
		);

		$this->assertSame( $fake, $this->invoke_find_wp_cli() );
	}

	/**
	 * A non-phar binary without the executable bit must be rejected.
	 */
	public function test_non_phar_requires_executable_bit(): void {
		$dir  = $this->make_temp_dir();
		$fake = $dir . '/wp';
		file_put_contents( $fake, "#!/bin/sh\necho ok\n" );
		chmod( $fake, 0644 );

		add_filter(
			'sd_ai_agent_wp_cli_binary',
			static function () use ( $fake ): string {
				return $fake;
			}
		);
		add_filter(
			'sd_ai_agent_wp_cli_candidates',
			static function (): array {
				return array();
			}
		);
		$this->disable_path_scan();

		$result = $this->invoke_find_wp_cli();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_cli_not_found', $result->get_error_code() );
	}

	/**
	 * Custom candidate paths injected via the filter must be searched.
	 */
	public function test_candidates_filter_is_consulted(): void {
		$fake = $this->create_fake_phar();

		add_filter(
			'sd_ai_agent_wp_cli_candidates',
			static function ( array $candidates ) use ( $fake ): array {
				array_unshift( $candidates, $fake );
				return $candidates;
			}
		);

		$this->assertSame( $fake, $this->invoke_find_wp_cli() );
	}

	/**
	 * The not-found WP_Error must carry actionable, machine-parseable data.
	 */
	public function test_not_found_message_includes_download_url_and_target_path(): void {
		// Force every candidate to miss by routing through a temp dir that
		// definitely has no wp/wp-cli.phar.
		$empty = $this->make_temp_dir();
		add_filter(
			'sd_ai_agent_wp_cli_candidates',
			static function () use ( $empty ): array {
				return array( $empty . '/nope-wp', $empty . '/nope.phar' );
			}
		);
		// Block the runtime override too.
		add_filter(
			'sd_ai_agent_wp_cli_binary',
			static function (): string {
				return '';
			}
		);
		// Block the PATH-scan and shell_exec fallbacks so this is deterministic
		// regardless of whether the CI runner has `wp` on $PATH.
		$this->disable_path_scan();

		$result = $this->invoke_find_wp_cli();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_cli_not_found', $result->get_error_code() );

		$message = $result->get_error_message();
		$this->assertStringContainsString(
			'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar',
			$message,
			'Not-found message should include the canonical download URL.'
		);
		$this->assertStringContainsString( ABSPATH, $message, 'Not-found message should tell the user where ABSPATH is.' );
		$this->assertStringContainsString( 'SD_AI_AGENT_WP_CLI_PATH', $message, 'Not-found message should mention the override constant.' );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame(
			'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar',
			$data['download_url'] ?? null
		);
		$this->assertSame( 'SD_AI_AGENT_WP_CLI_PATH', $data['override_constant'] ?? null );
		$this->assertSame( 'sd_ai_agent_wp_cli_binary', $data['override_filter'] ?? null );
		$this->assertSame( ABSPATH, $data['abspath'] ?? null );
		$this->assertIsArray( $data['searched_paths'] ?? null );
	}

	/**
	 * Re-entering find_wp_cli() must hit the cache.
	 */
	public function test_resolved_binary_is_cached_for_request(): void {
		$fake = $this->create_fake_phar();
		add_filter(
			'sd_ai_agent_wp_cli_binary',
			static function () use ( $fake ): string {
				return $fake;
			}
		);

		$first  = $this->invoke_find_wp_cli();
		$second = $this->invoke_find_wp_cli();

		$this->assertSame( $fake, $first );
		$this->assertSame( $first, $second );

		// And reset_binary_cache() must actually clear it.
		WpCliAbilities::reset_binary_cache();
		// Drop the filter — without the cache, discovery should fall through to not-found.
		remove_all_filters( 'sd_ai_agent_wp_cli_binary' );
		// Force candidate misses + disable PATH scan so we get a deterministic WP_Error.
		$empty = $this->make_temp_dir();
		add_filter(
			'sd_ai_agent_wp_cli_candidates',
			static function () use ( $empty ): array {
				return array( $empty . '/nope' );
			}
		);
		$this->disable_path_scan();

		$after_reset = $this->invoke_find_wp_cli();
		$this->assertInstanceOf( \WP_Error::class, $after_reset );
	}

	// ─── Helpers ────────────────────────────────────────────────────────

	/**
	 * Invoke the private find_wp_cli() method via reflection.
	 *
	 * @return string|\WP_Error
	 */
	private function invoke_find_wp_cli() {
		$ref = new \ReflectionMethod( WpCliAbilities::class, 'find_wp_cli' );
		$ref->setAccessible( true );
		return $ref->invoke( null );
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
	 * Remove the WP-CLI ability so registration tests start from a clean slate.
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
	 * Check the full registry without triggering wp_get_ability() not-found notices.
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

	/**
	 * Create a fake wp-cli.phar in a fresh temp dir.
	 *
	 * The file does not actually contain a valid PHAR — discovery only
	 * checks readability and extension. Process execution is exercised in
	 * dedicated integration tests.
	 *
	 * @param int $perms File permissions to apply.
	 * @return string Absolute path to the fake phar.
	 */
	private function create_fake_phar( int $perms = 0644 ): string {
		$dir = $this->make_temp_dir();
		$phar = $dir . '/wp-cli.phar';
		file_put_contents( $phar, "#!/usr/bin/env php\n<?php\n" );
		chmod( $phar, $perms );
		return $phar;
	}

	/**
	 * Make (and remember for cleanup) a private temp directory.
	 *
	 * @return string
	 */
	private function make_temp_dir(): string {
		if ( '' === $this->temp_dir ) {
			$this->temp_dir = sys_get_temp_dir() . '/sd_wp_cli_test_' . uniqid( '', true );
			$created        = mkdir( $this->temp_dir, 0755, true );
			$this->assertTrue( $created || is_dir( $this->temp_dir ), "Failed to create temp directory: {$this->temp_dir}" );
			$this->assertTrue( is_writable( $this->temp_dir ), "Temp directory is not writable: {$this->temp_dir}" );
		}
		return $this->temp_dir;
	}

	// ─── Secret-aware option subcommand gating ──────────────────────────

	/**
	 * `wp option get auth_key` must be blocked before binary discovery.
	 *
	 * The secret gate runs ahead of {@see WpCliAbilities::find_wp_cli()},
	 * so this test does not require a real WP-CLI binary to assert the
	 * block code.
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
	 *
	 * Example: `wp option get --format=json secure_auth_key`.
	 */
	public function test_execute_blocks_secret_name_even_with_flags_before_it(): void {
		$result = WpCliAbilities::execute( 'option get --format=json secure_auth_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_secret_redacted', $result->get_error_code() );
	}

	/**
	 * Non-secret option reads continue to pass the gate (they fail later
	 * because no real WP-CLI binary is installed in the test env, but the
	 * failure must NOT be the secret-redacted code).
	 */
	public function test_execute_allows_option_get_for_safe_name(): void {
		$result = WpCliAbilities::execute( 'option get blogname' );

		// The command should pass the secret gate; whether it ultimately
		// succeeds or fails depends on whether wp-cli is installed. Either
		// way the error code must NOT be the secret-redacted one.
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'wp_cli_option_secret_redacted',
				$result->get_error_code()
			);
		} else {
			$this->assertTrue( true, 'Command executed without hitting the secret gate.' );
		}
	}

	/**
	 * `wp option update auth_key …` must be refused by the write-blocklist
	 * branch of the gate, even though `auth_key` could also be reached via
	 * the secret-read gate.
	 */
	public function test_execute_blocks_option_update_for_protected_name(): void {
		$result = WpCliAbilities::execute( 'option update auth_key rotated-by-agent' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_protected', $result->get_error_code() );
	}

	/**
	 * `wp option delete logged_in_key` must be refused.
	 */
	public function test_execute_blocks_option_delete_for_protected_name(): void {
		$result = WpCliAbilities::execute( 'option delete logged_in_key' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_cli_option_protected', $result->get_error_code() );
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

		remove_all_filters( 'sd_ai_agent_options_read_blocklist' );
	}

	/**
	 * The structured `option list` scrubber must redact `option_value` on
	 * any row whose `option_name` is a secret.
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
				'option_name'  => 'nonce_salt',
				'value'        => 'do-not-leak-this-secret',
			),
		);

		$scrubbed = WpCliAbilities::scrub_secret_output( 'option list', $raw );
		$this->assertIsArray( $scrubbed );

		$encoded = wp_json_encode( $scrubbed );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( 'do-not-leak-this-secret', $encoded );
		$this->assertSame( 'My Site', $scrubbed[0]['option_value'] );
		$this->assertSame(
			\SdAiAgent\Abilities\OptionsAbilities::SECRET_REDACTED_PLACEHOLDER,
			$scrubbed[1]['option_value']
		);
		$this->assertSame(
			\SdAiAgent\Abilities\OptionsAbilities::SECRET_REDACTED_PLACEHOLDER,
			$scrubbed[2]['value']
		);
	}

	/**
	 * The text-format scrubber must replace secret values on lines that
	 * begin with a secret option name.
	 */
	public function test_scrub_secret_output_redacts_text_rows(): void {
		$raw = "blogname\tMy Site\nauth_key\tdo-not-leak-this-secret\nnonce_salt:do-not-leak-this-secret\n";

		$scrubbed = WpCliAbilities::scrub_secret_output( 'option list', $raw );
		$this->assertIsString( $scrubbed );
		$this->assertStringNotContainsString( 'do-not-leak-this-secret', $scrubbed );
		$this->assertStringContainsString( 'My Site', $scrubbed );
		$this->assertStringContainsString(
			\SdAiAgent\Abilities\OptionsAbilities::SECRET_REDACTED_PLACEHOLDER,
			$scrubbed
		);
	}

	/**
	 * Subcommands other than `option list*` must pass through untouched —
	 * the scrubber is a narrow safety net, not a general filter.
	 */
	public function test_scrub_secret_output_passes_through_other_commands(): void {
		$raw = array( array( 'option_name' => 'auth_key', 'option_value' => 'leaked' ) );
		$result = WpCliAbilities::scrub_secret_output( 'post list', $raw );
		$this->assertSame( $raw, $result );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}

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
			mkdir( $this->temp_dir, 0755, true );
		}
		return $this->temp_dir;
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

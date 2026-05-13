<?php
/**
 * Test case for SkillUpdateChecker.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\Settings;
use SdAiAgent\Core\SkillUpdateChecker;
use SdAiAgent\Models\Skill;
use WP_UnitTestCase;

/**
 * Tests for the WP-Cron skill update checker and conditional HTTP requests.
 */
class SkillUpdateCheckerTest extends WP_UnitTestCase {

	/**
	 * Reset settings, HTTP filters, and scheduled events after each test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( SkillUpdateChecker::MANIFEST_CACHE_OPTION );
		delete_option( Settings::OPTION_NAME );
		SkillUpdateChecker::unschedule();

		parent::tear_down();
	}

	/**
	 * Scheduling the checker registers the daily cron event once.
	 */
	public function test_schedule_registers_daily_cron_event(): void {
		SkillUpdateChecker::unschedule();

		SkillUpdateChecker::schedule();
		$first_timestamp = wp_next_scheduled( SkillUpdateChecker::CRON_HOOK );

		SkillUpdateChecker::schedule();
		$second_timestamp = wp_next_scheduled( SkillUpdateChecker::CRON_HOOK );

		$this->assertNotFalse( $first_timestamp );
		$this->assertSame( $first_timestamp, $second_timestamp );
	}

	/**
	 * Unscheduling the checker removes the daily cron event.
	 */
	public function test_unschedule_removes_daily_cron_event(): void {
		SkillUpdateChecker::schedule();
		$this->assertNotFalse( wp_next_scheduled( SkillUpdateChecker::CRON_HOOK ) );

		SkillUpdateChecker::unschedule();

		$this->assertFalse( wp_next_scheduled( SkillUpdateChecker::CRON_HOOK ) );
	}

	/**
	 * A cached ETag / Last-Modified pair is sent as conditional request headers.
	 */
	public function test_run_sends_conditional_headers_and_skips_304_response(): void {
		$this->configure_manifest_settings();
		update_option(
			SkillUpdateChecker::MANIFEST_CACHE_OPTION,
			[
				'etag'          => '"manifest-v1"',
				'last_modified' => 'Tue, 12 May 2026 10:00:00 GMT',
			],
			false
		);

		$captured_args = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;

				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 304, 'message' => 'Not Modified' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			3
		);

		SkillUpdateChecker::run();

		$this->assertSame( 'application/json', $captured_args['headers']['Accept'] ?? '' );
		$this->assertSame( '"manifest-v1"', $captured_args['headers']['If-None-Match'] ?? '' );
		$this->assertSame( 'Tue, 12 May 2026 10:00:00 GMT', $captured_args['headers']['If-Modified-Since'] ?? '' );
	}

	/**
	 * A successful manifest response updates unmodified built-in skills and caches validators.
	 */
	public function test_run_applies_manifest_updates_and_persists_response_validators(): void {
		$this->configure_manifest_settings();
		Skill::seed_builtins();

		$skill = Skill::get_by_slug( 'wordpress-admin' );
		$this->assertNotNull( $skill );

		$updated_content = 'Updated WordPress admin skill content from the remote manifest.';
		$manifest        = [
			'wordpress-admin' => [
				'version'    => '2026.05.13',
				'content'    => $updated_content,
				'source_url' => 'https://example.com/skills/wordpress-admin.md',
			],
		];

		add_filter(
			'pre_http_request',
			static function () use ( $manifest ) {
				return [
					'headers'  => [
						'etag'          => '"manifest-v2"',
						'last-modified' => 'Wed, 13 May 2026 12:00:00 GMT',
					],
					'body'     => wp_json_encode( $manifest ),
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			3
		);

		SkillUpdateChecker::run();

		$refreshed = Skill::get_by_slug( 'wordpress-admin' );
		$cache     = get_option( SkillUpdateChecker::MANIFEST_CACHE_OPTION, [] );

		$this->assertNotNull( $refreshed );
		$this->assertSame( $updated_content, $refreshed->content );
		$this->assertSame( '2026.05.13', $refreshed->version );
		$this->assertFalse( (bool) $refreshed->user_modified );
		$this->assertSame( '"manifest-v2"', $cache['etag'] ?? '' );
		$this->assertSame( 'Wed, 13 May 2026 12:00:00 GMT', $cache['last_modified'] ?? '' );
	}

	/**
	 * The checker is disabled when skill_auto_update is false.
	 */
	public function test_run_skips_when_auto_updates_are_disabled(): void {
		$this->configure_manifest_settings( false );
		$request_count = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$request_count ) {
				++$request_count;
				return false;
			},
			10,
			3
		);

		SkillUpdateChecker::run();

		$this->assertSame( 0, $request_count );
	}

	/**
	 * The cron checker does not request insecure manifest URLs.
	 */
	public function test_run_skips_non_https_manifest_urls(): void {
		update_option(
			Settings::OPTION_NAME,
			[
				'skill_auto_update'  => true,
				'skill_manifest_url' => 'http://example.com/skill-manifest.json',
			],
			false
		);
		$request_count = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$request_count ) {
				++$request_count;
				return false;
			},
			10,
			3
		);

		SkillUpdateChecker::run();

		$this->assertSame( 0, $request_count );
	}

	/**
	 * Configure manifest settings for the checker.
	 *
	 * @param bool $auto_update Whether automatic skill updates are enabled.
	 */
	private function configure_manifest_settings( bool $auto_update = true ): void {
		update_option(
			Settings::OPTION_NAME,
			[
				'skill_auto_update'  => $auto_update,
				'skill_manifest_url' => 'https://example.com/skill-manifest.json',
			],
			false
		);
	}
}

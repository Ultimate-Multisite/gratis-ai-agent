<?php

declare(strict_types=1);
/**
 * Skill Update Checker — WP-Cron callback for remote manifest-based skill updates.
 *
 * Runs daily. Fetches the manifest JSON configured in Settings → skill_manifest_url,
 * compares content hashes per slug, and applies updates to built-in unmodified skills.
 * Uses If-None-Match / If-Modified-Since headers to skip unnecessary transfers.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Models\Skill;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SkillUpdateChecker {

	/**
	 * WP-Cron hook name for the daily skill update check.
	 */
	const CRON_HOOK = 'sd_ai_agent_skill_update_check';

	/**
	 * Option key for conditional-request caching headers (ETag, Last-Modified).
	 */
	const MANIFEST_CACHE_OPTION = 'sd_ai_agent_skill_manifest_cache';

	// ── Registration ─────────────────────────────────────────────────────

	/**
	 * Register the cron hook handler (add_action for the cron callback).
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * Schedule a daily update check (idempotent — safe to call multiple times).
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Cancel the scheduled check (e.g. on plugin deactivation).
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	// ── Cron callback ────────────────────────────────────────────────────

	/**
	 * Execute the skill update check (called by WP-Cron).
	 *
	 * Skips silently when skill_auto_update is disabled or skill_manifest_url
	 * is not configured. Fetches the manifest, compares content hashes, and
	 * applies updates to unmodified built-in skills only.
	 */
	public static function run(): void {
		$settings     = Settings::instance()->get();
		$manifest_url = (string) ( $settings['skill_manifest_url'] ?? '' );

		if ( '' === $manifest_url ) {
			return;
		}

		// Match the manual update endpoint's HTTPS-only policy before any
		// background HTTP request is made from WP-Cron.
		if ( ! str_starts_with( strtolower( $manifest_url ), 'https://' ) ) {
			return;
		}

		if ( ! (bool) ( $settings['skill_auto_update'] ?? true ) ) {
			return;
		}

		$manifest = self::fetch_manifest( $manifest_url );

		if ( null === $manifest ) {
			// 304 Not Modified, network error, or malformed JSON — nothing to apply.
			return;
		}

		self::apply_manifest_updates( $manifest );
	}

	// ── HTTP fetch ───────────────────────────────────────────────────────

	/**
	 * Fetch the remote manifest JSON using conditional headers.
	 *
	 * Sends If-None-Match and If-Modified-Since headers when cached values
	 * exist. Returns null when the manifest is unchanged (304), unreachable,
	 * or not valid JSON. Stores the response ETag / Last-Modified for the
	 * next run.
	 *
	 * @param string $url Manifest URL.
	 * @return array<array-key, mixed>|null Parsed manifest keyed by skill slug, or null.
	 */
	private static function fetch_manifest( string $url ): ?array {
		$cache   = self::get_manifest_cache();
		$headers = [ 'Accept' => 'application/json' ];

		if ( '' !== $cache['etag'] ) {
			$headers['If-None-Match'] = $cache['etag'];
		}

		if ( '' !== $cache['last_modified'] ) {
			$headers['If-Modified-Since'] = $cache['last_modified'];
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 30,
				'redirection' => 3,
				'headers'     => $headers,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 304 === $status ) {
			// Manifest unchanged — nothing to do.
			return null;
		}

		if ( 200 !== $status ) {
			return null;
		}

		// Cache conditional-request headers from this response for next run.
		$resp_headers  = wp_remote_retrieve_headers( $response );
		$etag          = (string) ( $resp_headers['etag'] ?? '' );
		$last_modified = (string) ( $resp_headers['last-modified'] ?? '' );
		self::set_manifest_cache( $etag, $last_modified );

		$body     = wp_remote_retrieve_body( $response );
		$manifest = json_decode( $body, true );

		if ( ! is_array( $manifest ) ) {
			return null;
		}

		return $manifest;
	}

	// ── Update application ───────────────────────────────────────────────

	/**
	 * Apply manifest updates to all unmodified built-in skills.
	 *
	 * Iterates every stored skill. For each built-in skill whose slug appears
	 * in the manifest and whose content hash differs, calls apply_update().
	 * Skills with user_modified = 1 are skipped by apply_update() itself to
	 * protect admin customisations.
	 *
	 * @param array<array-key, mixed> $manifest Parsed manifest keyed by skill slug.
	 */
	private static function apply_manifest_updates( array $manifest ): void {
		$skills = Skill::get_all();

		if ( empty( $skills ) ) {
			return;
		}

		foreach ( $skills as $skill ) {
			if ( ! $skill->is_builtin ) {
				continue;
			}

			if ( ! isset( $manifest[ $skill->slug ] ) || ! is_array( $manifest[ $skill->slug ] ) ) {
				continue;
			}

			/** @var array<string, mixed> $entry */
			$entry  = $manifest[ $skill->slug ];
			$update = Skill::check_for_updates( $skill, $entry );

			if ( null !== $update ) {
				Skill::apply_update( (int) $skill->id, $update );
			}
		}
	}

	// ── Manifest cache helpers ───────────────────────────────────────────

	/**
	 * Return the stored conditional-request cache headers.
	 *
	 * @return array{etag: string, last_modified: string}
	 */
	private static function get_manifest_cache(): array {
		$raw = get_option( self::MANIFEST_CACHE_OPTION, [] );
		$raw = is_array( $raw ) ? $raw : [];

		return [
			'etag'          => (string) ( $raw['etag'] ?? '' ),
			'last_modified' => (string) ( $raw['last_modified'] ?? '' ),
		];
	}

	/**
	 * Persist ETag and Last-Modified from a successful manifest response.
	 *
	 * @param string $etag          ETag header value (may be empty string).
	 * @param string $last_modified Last-Modified header value (may be empty string).
	 */
	private static function set_manifest_cache( string $etag, string $last_modified ): void {
		update_option(
			self::MANIFEST_CACHE_OPTION,
			[
				'etag'          => $etag,
				'last_modified' => $last_modified,
			],
			false
		);
	}
}

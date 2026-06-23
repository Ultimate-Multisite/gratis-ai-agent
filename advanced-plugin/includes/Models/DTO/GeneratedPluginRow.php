<?php

declare(strict_types=1);
/**
 * Typed DTO for a generated-plugin row returned by wpdb::get_row().
 *
 * @package SdAiAgent\Models\DTO
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models\DTO;

/**
 * Immutable DTO for the sd_ai_agent_generated_plugins table row.
 */
readonly class GeneratedPluginRow {

	/**
	 * @param int    $id               Row ID (auto-increment PK).
	 * @param string $slug             Plugin slug.
	 * @param string $description      Short description.
	 * @param string $plan             AI-generated plan content.
	 * @param string $plugin_file      Main plugin file path relative to wp-content/plugins.
	 * @param string $files            JSON-encoded map of relative path → PHP source.
	 * @param string $status           Status ('installed', 'active', 'deactivated', etc.).
	 * @param string $sandbox_result   JSON-encoded sandbox execution result.
	 * @param string $activation_error Activation error message, or empty string.
	 * @param string $created_at       MySQL datetime string (UTC).
	 * @param string $updated_at       MySQL datetime string (UTC).
	 */
	public function __construct(
		public int $id,
		public string $slug,
		public string $description,
		public string $plan,
		public string $plugin_file,
		public string $files,
		public string $status,
		public string $sandbox_result,
		public string $activation_error,
		public string $created_at,
		public string $updated_at,
	) {}

	/**
	 * Construct a GeneratedPluginRow from the raw stdClass returned by wpdb::get_row().
	 *
	 * @param object $row Raw row from wpdb.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			id:               (int) $row->id,
			slug:             (string) ( $row->slug ?? '' ),
			description:      (string) ( $row->description ?? '' ),
			plan:             (string) ( $row->plan ?? '' ),
			plugin_file:      (string) ( $row->plugin_file ?? '' ),
			files:            (string) ( $row->files ?? '{}' ),
			status:           (string) ( $row->status ?? 'installed' ),
			sandbox_result:   (string) ( $row->sandbox_result ?? '' ),
			activation_error: (string) ( $row->activation_error ?? '' ),
			created_at:       (string) ( $row->created_at ?? '' ),
			updated_at:       (string) ( $row->updated_at ?? '' ),
		);
	}
}

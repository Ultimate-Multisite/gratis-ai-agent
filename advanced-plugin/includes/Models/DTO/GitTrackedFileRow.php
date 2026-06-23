<?php

declare(strict_types=1);
/**
 * Typed DTO for a git-tracked-file row returned by wpdb::get_row().
 *
 * @package SdAiAgent\Models\DTO
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models\DTO;

/**
 * Immutable DTO for the sd_ai_agent_git_tracked_files table row.
 */
readonly class GitTrackedFileRow {

	/**
	 * @param int         $id               Row ID (auto-increment PK).
	 * @param string      $file_path        File path relative to the package root.
	 * @param string      $file_type        File type slug ('plugin', 'theme', etc.).
	 * @param string      $package_slug     Plugin/theme slug that owns this file.
	 * @param string      $original_hash    SHA-256 hash of the original content.
	 * @param string      $original_content Original raw file content (binary-safe).
	 * @param string      $current_hash     SHA-256 hash of the current on-disk content.
	 * @param string      $status           Tracking status ('unchanged', 'modified', 'deleted').
	 * @param string      $tracked_at       MySQL datetime string (UTC) when tracking started.
	 * @param string|null $modified_at      MySQL datetime string (UTC) of last modification, or null.
	 */
	public function __construct(
		public int $id,
		public string $file_path,
		public string $file_type,
		public string $package_slug,
		public string $original_hash,
		public string $original_content,
		public string $current_hash,
		public string $status,
		public string $tracked_at,
		public ?string $modified_at,
	) {}

	/**
	 * Construct a GitTrackedFileRow from the raw stdClass returned by wpdb::get_row().
	 *
	 * @param object $row Raw row from wpdb.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			id:               (int) $row->id,
			file_path:        (string) ( $row->file_path ?? '' ),
			file_type:        (string) ( $row->file_type ?? 'plugin' ),
			package_slug:     (string) ( $row->package_slug ?? '' ),
			original_hash:    (string) ( $row->original_hash ?? '' ),
			original_content: (string) ( $row->original_content ?? '' ),
			current_hash:     (string) ( $row->current_hash ?? '' ),
			status:           (string) ( $row->status ?? 'unchanged' ),
			tracked_at:       (string) ( $row->tracked_at ?? '' ),
			modified_at:      isset( $row->modified_at ) ? (string) $row->modified_at : null,
		);
	}
}

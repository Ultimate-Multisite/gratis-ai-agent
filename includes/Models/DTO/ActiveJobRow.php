<?php

declare(strict_types=1);
/**
 * Typed DTO for an active job row returned by wpdb::get_row().
 *
 * Eliminates `object::$` property-access phpstan errors throughout callers
 * by providing explicit, typed property declarations for every column in the
 * sd_ai_agent_active_jobs table.
 *
 * @package SdAiAgent\Models\DTO
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models\DTO;

/**
 * Immutable DTO for the sd_ai_agent_active_jobs table row.
 */
readonly class ActiveJobRow {

	/**
	 * @param int         $id             Row ID (auto-increment PK).
	 * @param int         $session_id     WordPress session ID (FK to sessions table).
	 * @param string      $job_id         UUID identifying the background job.
	 * @param int         $user_id        WordPress user ID.
	 * @param string      $status         Job status: processing|awaiting_confirmation|awaiting_client_tools|complete|error|interrupted|abandoned.
	 * @param string      $pending_tools  JSON-encoded pending tool-call confirmations (default '[]').
	 * @param string      $tool_calls     JSON-encoded tool-call log (default '[]').
	 * @param string|null $error          Error or interruption message, null when not set.
	 * @param string|null $interrupted_at MySQL datetime string (UTC) when the shutdown handler fired, null if not interrupted.
	 * @param string      $created_at     MySQL datetime string (UTC).
	 * @param string      $updated_at     MySQL datetime string (UTC).
	 */
	public function __construct(
		public int $id,
		public int $session_id,
		public string $job_id,
		public int $user_id,
		public string $status,
		public string $pending_tools,
		public string $tool_calls,
		public ?string $error,
		public ?string $interrupted_at,
		public string $created_at,
		public string $updated_at,
	) {}

	/**
	 * Construct an ActiveJobRow from the raw stdClass returned by wpdb::get_row().
	 *
	 * @param object $row Raw row from wpdb::get_row().
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			id:             (int) $row->id,
			session_id:     (int) $row->session_id,
			job_id:         (string) ( $row->job_id ?? '' ),
			user_id:        (int) $row->user_id,
			status:         (string) ( $row->status ?? 'processing' ),
			pending_tools:  (string) ( $row->pending_tools ?? '[]' ),
			tool_calls:     (string) ( $row->tool_calls ?? '[]' ),
			error:          isset( $row->error ) ? (string) $row->error : null,
			interrupted_at: isset( $row->interrupted_at ) ? (string) $row->interrupted_at : null,
			created_at:     (string) ( $row->created_at ?? '' ),
			updated_at:     (string) ( $row->updated_at ?? '' ),
		);
	}
}

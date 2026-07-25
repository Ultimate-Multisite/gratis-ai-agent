<?php

declare(strict_types=1);
/**
 * Typed DTO for an agent row returned by wpdb::get_row() / wpdb::get_results().
 *
 * @package SdAiAgent\Models\DTO
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models\DTO;

/**
 * Immutable DTO for the sd_ai_agent_agents table row.
 */
readonly class AgentRow {

	/**
	 * @param int                                                             $id             Row ID (auto-increment PK).
	 * @param string                                                          $slug           URL-safe unique slug.
	 * @param string                                                          $name           Human-readable name.
	 * @param string                                                          $description    Short description.
	 * @param string                                                          $system_prompt  Custom system instruction override (default '').
	 * @param string                                                          $provider_id    AI provider slug override (default '').
	 * @param string                                                          $model_id       Model slug override (default '').
	 * @param string                                                          $tool_profile   Tool profile slug (default '').
	 * @param float|null                                                      $temperature    Sampling temperature override, or null to use default.
	 * @param int|null                                                        $max_iterations Iteration cap override, or null to use default.
	 * @param string                                                          $greeting       Agent greeting message (default '').
	 * @param string                                                          $avatar_icon    Dashicons slug or empty string.
	 * @param list<string>                                                    $tier_1_tools   Curated Tier 1 ability names for this agent.
	 * @param list<array{title: string, description: string, prompt: string}> $suggestions Agent-specific suggestion cards.
	 * @param string                                                          $managed_profile_key Stable key for an integration-managed customer profile.
	 * @param string                                                          $managed_profile_version Integration-managed profile specification version.
	 * @param array<string,mixed>                                             $managed_profile_metadata Explicit customer-profile ownership metadata.
	 * @param bool                                                            $is_builtin     Whether this is a system-provided default agent.
	 * @param bool                                                            $enabled        Whether the agent is active.
	 * @param string                                                          $created_at     MySQL datetime string (UTC).
	 * @param string                                                          $updated_at     MySQL datetime string (UTC).
	 */
	public function __construct( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> is valid PHPStan but not a native PHP type.
		public int $id,
		public string $slug,
		public string $name,
		public string $description,
		public string $system_prompt,
		public string $provider_id,
		public string $model_id,
		public string $tool_profile,
		public ?float $temperature,
		public ?int $max_iterations,
		public string $greeting,
		public string $avatar_icon,
		public array $tier_1_tools,
		public array $suggestions,
		public string $managed_profile_key,
		public string $managed_profile_version,
		public array $managed_profile_metadata,
		public bool $is_builtin,
		public bool $enabled,
		public string $created_at,
		public string $updated_at,
	) {}

	/**
	 * Construct an AgentRow from the raw stdClass returned by wpdb::get_row() or get_results().
	 *
	 * @param object $row Raw row from wpdb.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		$temp = isset( $row->temperature ) && '' !== $row->temperature
			? (float) $row->temperature
			: null;

		$max_iter = isset( $row->max_iterations ) && '' !== $row->max_iterations
			? (int) $row->max_iterations
			: null;

		$tier_1_raw = (string) ( $row->tier_1_tools ?? '' );
		$tier_1     = '' !== $tier_1_raw ? json_decode( $tier_1_raw, true ) : [];
		if ( ! is_array( $tier_1 ) ) {
			$tier_1 = [];
		}

		$suggestions_raw = (string) ( $row->suggestions ?? '' );
		$suggestions     = '' !== $suggestions_raw ? json_decode( $suggestions_raw, true ) : [];
		if ( ! is_array( $suggestions ) ) {
			$suggestions = [];
		}

		$managed_metadata_raw = (string) ( $row->managed_profile_metadata ?? '' );
		$managed_metadata     = '' !== $managed_metadata_raw ? json_decode( $managed_metadata_raw, true ) : [];
		if ( ! is_array( $managed_metadata ) ) {
			$managed_metadata = [];
		}

		return new self(
			id:             (int) $row->id,
			slug:           (string) ( $row->slug ?? '' ),
			name:           (string) ( $row->name ?? '' ),
			description:    (string) ( $row->description ?? '' ),
			system_prompt:  (string) ( $row->system_prompt ?? '' ),
			provider_id:    (string) ( $row->provider_id ?? '' ),
			model_id:       (string) ( $row->model_id ?? '' ),
			tool_profile:   (string) ( $row->tool_profile ?? '' ),
			temperature:    $temp,
			max_iterations: $max_iter,
			greeting:       (string) ( $row->greeting ?? '' ),
			avatar_icon:    (string) ( $row->avatar_icon ?? '' ),
			tier_1_tools:   array_values( array_map( 'strval', $tier_1 ) ), // @phpstan-ignore-line
			suggestions:    $suggestions, // @phpstan-ignore-line
			managed_profile_key:      (string) ( $row->managed_profile_key ?? '' ),
			managed_profile_version:  (string) ( $row->managed_profile_version ?? '' ),
			managed_profile_metadata: $managed_metadata, // @phpstan-ignore-line
			is_builtin:     (bool) (int) ( $row->is_builtin ?? 0 ),
			enabled:        (bool) (int) ( $row->enabled ?? 1 ),
			created_at:     (string) ( $row->created_at ?? '' ),
			updated_at:     (string) ( $row->updated_at ?? '' ),
		);
	}
}

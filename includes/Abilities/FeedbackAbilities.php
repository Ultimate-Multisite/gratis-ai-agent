<?php

declare(strict_types=1);
/**
 * Register feedback-related WordPress abilities for the AI agent.
 *
 * Provides the `sd-ai-agent/report-inability` ability so the agent can
 * self-flag when it cannot complete a task. The handler sets a static,
 * request-scoped flag that AgentLoop reads at loop-end and injects into the
 * REST response as `inability_reported`, which the frontend consumes to show
 * a feedback prompt.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedbackAbilities {

	/**
	 * Request-scoped inability data set by the handler.
	 * Null means the ability was not called this request.
	 *
	 * @var array{reason: string, attempted_steps: string[]}|null
	 */
	private static ?array $inability_data = null;

	/**
	 * Register the report-inability ability.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/report-inability',
			[
				'label'               => __( 'Report Inability', 'superdav-ai-agent' ),
				'description'         => __( 'Call this ability when you cannot complete the user\'s request after genuinely trying. Provide a clear reason and list the steps you attempted. This helps the team improve the agent.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'reason'          => [
							'type'        => 'string',
							'description' => 'A clear, concise explanation of why the task could not be completed.',
						],
						'attempted_steps' => [
							'type'        => 'array',
							'description' => 'List of steps that were attempted before giving up.',
							'items'       => [ 'type' => 'string' ],
						],
					],
					'required'   => [ 'reason' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_report_inability' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Handle the report-inability ability call.
	 *
	 * Sets a request-scoped static flag that AgentLoop reads after the loop
	 * finishes to include `inability_reported` in the REST response.
	 *
	 * @param array<string,mixed> $input Input with reason and optional attempted_steps.
	 * @return array<string,mixed> Result confirming the flag was set.
	 */
	public static function handle_report_inability( array $input ): array {
		$reason          = trim( (string) ( $input['reason'] ?? '' ) );
		$attempted_steps = $input['attempted_steps'] ?? [];

		if ( empty( $reason ) ) {
			return [
				'success' => false,
				'message' => 'A reason is required to report inability.',
			];
		}

		// Normalise attempted_steps to a plain string array.
		$steps = array_values(
			array_filter(
				array_map( static fn( $s ) => (string) $s, (array) $attempted_steps ),
				static fn( string $s ) => '' !== trim( $s )
			)
		);

		self::$inability_data = [
			'reason'          => $reason,
			'attempted_steps' => $steps,
		];

		return [
			'success' => true,
			'message' => 'Inability flagged. The user will be offered the option to send a report.',
		];
	}

	/**
	 * Return the inability data set during this request, if any.
	 *
	 * Called by AgentLoop after the loop finishes.
	 *
	 * @return array{reason: string, attempted_steps: string[]}|null
	 */
	public static function get_inability_data(): ?array {
		return self::$inability_data;
	}

	/**
	 * Reset the request-scoped flag.
	 * Exposed for unit-test isolation.
	 */
	public static function reset(): void {
		self::$inability_data = null;
	}
}

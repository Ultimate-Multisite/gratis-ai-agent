<?php

declare(strict_types=1);
/**
 * Register skill-related WordPress abilities (tools) for the AI agent.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Models\Skill;
use SdAiAgent\Repositories\SkillUsageRepository;
use SdAiAgent\Tools\ModelHealthTracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SkillAbilities {

	/**
	 * Register the skill-load and skill-list abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/skill-load',
			[
				'label'               => __( 'Load Skill', 'superdav-ai-agent' ),
				'description'         => __( 'Load the full instructions for a specific skill guide by its slug.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'slug' => [
							'type'        => 'string',
							'description' => 'The skill slug to load (e.g. wordpress-admin, woocommerce)',
						],
					],
					'required'   => [ 'slug' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'name'    => [ 'type' => 'string' ],
						'slug'    => [ 'type' => 'string' ],
						'content' => [ 'type' => 'string' ],
						'error'   => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_skill_load' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ); },
			]
		);

		wp_register_ability(
			'sd-ai-agent/skill-list',
			[
				'label'               => __( 'List Skills', 'superdav-ai-agent' ),
				'description'         => __( 'List all available skill guides with their slugs, names, and descriptions.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => (object) [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'skills'  => [ 'type' => 'array' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_skill_list' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ); },
			]
		);
	}

	/**
	 * Handle the skill-load ability call.
	 *
	 * @param array<string,mixed> $input Input with slug.
	 * @return array<string,mixed>|\WP_Error Result with skill content.
	 */
	public static function handle_skill_load( array $input ) {
		$slug = $input['slug'] ?? '';

		if ( empty( $slug ) ) {
			return new \WP_Error( 'missing_slug', 'Skill slug is required.' );
		}

		// @phpstan-ignore-next-line
		$skill = Skill::get_by_slug( $slug );

		if ( ! $skill ) {
			// @phpstan-ignore-next-line
			return new \WP_Error( 'skill_not_found', "Skill '$slug' not found." );
		}

		if ( ! (int) $skill->enabled ) {
			// @phpstan-ignore-next-line
			return new \WP_Error( 'skill_disabled', "Skill '$slug' is disabled." );
		}

		// Record tool_call usage for telemetry (Phase 1 / t215).
		// Model ID is unknown here (abilities don't receive request context),
		// so model_id defaults to '' and session_id to 0.
		SkillUsageRepository::create(
			[
				'skill_id'        => $skill->id,
				'session_id'      => 0,
				'trigger_type'    => 'tool_call',
				'injected_tokens' => SkillUsageRepository::estimate_tokens( $skill->content ),
				'outcome'         => 'unknown',
				'model_id'        => '',
			]
		);

		// Record this voluntary skill-load call in ModelHealthTracker (Phase 2 / t217).
		// Strong models that call skill-load on their own confirm they can use
		// the index-only path effectively. This signal is used in Phase 3 to
		// tune the auto-injection threshold per model.
		ModelHealthTracker::record_skill_load();

		return [
			'name'    => $skill->name,
			'slug'    => $skill->slug,
			'content' => $skill->content,
		];
	}

	/**
	 * Handle the skill-list ability call.
	 *
	 * @return array<string,mixed> Result with skills index.
	 */
	public static function handle_skill_list(): array {
		$skills = Skill::get_all( true );

		if ( empty( $skills ) ) {
			return [
				'skills'  => [],
				'message' => 'No skills available.',
			];
		}

		$list = [];
		foreach ( $skills as $skill ) {
			$list[] = [
				// @phpstan-ignore-next-line
				'slug'        => $skill->slug,
				// @phpstan-ignore-next-line
				'name'        => $skill->name,
				// @phpstan-ignore-next-line
				'description' => $skill->description,
			];
		}

		return [
			'skills'  => $list,
			'message' => '',
		];
	}
}
